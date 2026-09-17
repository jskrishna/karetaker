<?php
/**
 * Update receipts: what changed inside a plugin when it was updated.
 *
 * @package Karetaker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Snapshots a plugin folder before an update and compares it afterwards: files changed,
 * new outbound domains, and risky PHP functions that appeared. Counts patterns only; not a
 * malware verdict.
 *
 * @since 1.0.0
 * @package Karetaker
 */
class Karetaker_Receipts {

	const OPTION    = 'karetaker_update_receipts';
	const MAX_FILES = 3000;
	const BUDGET    = 5;
	const RISKY     = array( 'base64_decode', 'eval', 'gzinflate', 'str_rot13', 'assert', 'create_function', 'shell_exec', 'passthru', 'proc_open', 'popen' );

	/**
	 * Snapshots taken before updates in this request, keyed by plugin file.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private static $before = array();

	/**
	 * Stored receipts, newest first.
	 *
	 * @since 1.0.0
	 * @return array<int, array<string, mixed>>
	 */
	public static function all() {
		$rows = get_option( self::OPTION, array() );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Takes the "before" snapshot for a plugin.
	 *
	 * @since 1.0.0
	 * @param string $plugin_file Plugin basename.
	 * @return void
	 */
	public static function before( $plugin_file ) {
		self::$before[ $plugin_file ] = self::snapshot( $plugin_file );
	}

	/**
	 * Compares with the "before" snapshot and stores a receipt.
	 *
	 * @since 1.0.0
	 * @param string $plugin_file Plugin basename.
	 * @param string $name        Plugin name.
	 * @param string $from        Old version.
	 * @param string $to          New version.
	 * @return array<string, mixed>|null
	 */
	public static function after( $plugin_file, $name, $from, $to ) {
		if ( empty( self::$before[ $plugin_file ] ) ) {
			return null;
		}
		$old = self::$before[ $plugin_file ];
		$new = self::snapshot( $plugin_file );

		$changed = 0;
		foreach ( $new['files'] as $rel => $hash ) {
			if ( ! isset( $old['files'][ $rel ] ) || $old['files'][ $rel ] !== $hash ) {
				++$changed;
			}
		}
		$changed += count( array_diff_key( $old['files'], $new['files'] ) );

		$new_hosts = array_values( array_diff( $new['hosts'], $old['hosts'] ) );
		$risky     = array();
		foreach ( $new['risky'] as $fn => $count ) {
			$was = isset( $old['risky'][ $fn ] ) ? (int) $old['risky'][ $fn ] : 0;
			if ( $count > $was ) {
				$risky[ $fn ] = $count - $was;
			}
		}
		$lines = array_values( array_slice( array_diff( $new['lines'], $old['lines'] ), 0, 20 ) );

		$receipt = array(
			'slug'      => Karetaker_Checksums::plugin_slug( $plugin_file ),
			'name'      => $name,
			'from'      => $from,
			'to'        => $to,
			'at'        => time(),
			'changed'   => $changed,
			'hosts'     => array_slice( $new_hosts, 0, 10 ),
			'risky'     => $risky,
			'lines'     => $lines,
			'truncated' => $old['truncated'] || $new['truncated'],
		);

		$rows = self::all();
		array_unshift( $rows, $receipt );
		update_option( self::OPTION, array_slice( $rows, 0, 30 ), false );

		if ( $new_hosts || $risky ) {
			Karetaker_Events::record(
				'plugin_update_flagged',
				array(
					'slug'    => $receipt['slug'],
					'name'    => $name,
					'from'    => $from,
					'to'      => $to,
					'domains' => $receipt['hosts'],
					'risky'   => implode( ', ', array_keys( $risky ) ),
				),
				0
			);
		}

		return $receipt;
	}

	/**
	 * File hashes, outbound hosts, risky function counts and matching lines for a plugin.
	 *
	 * @since 1.0.0
	 * @param string $plugin_file Plugin basename.
	 * @return array{files: array<string, string>, hosts: string[], risky: array<string, int>, lines: string[], truncated: bool}
	 */
	private static function snapshot( $plugin_file ) {
		$dir   = WP_PLUGIN_DIR . '/' . dirname( $plugin_file );
		$out   = array(
			'files'     => array(),
			'hosts'     => array(),
			'risky'     => array(),
			'lines'     => array(),
			'truncated' => false,
		);
		$start = microtime( true );
		if ( '.' === dirname( $plugin_file ) || ! is_dir( $dir ) ) {
			return $out;
		}
		$own   = strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
		$items = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ) );
		$hosts = array();
		$regex = '/\b(' . implode( '|', self::RISKY ) . ')\s*\(/i';
		foreach ( $items as $item ) {
			if ( count( $out['files'] ) >= self::MAX_FILES || microtime( true ) - $start > self::BUDGET ) {
				$out['truncated'] = true;
				break;
			}
			if ( ! $item->isFile() || ! preg_match( '/\.(php|js)$/i', $item->getFilename() ) || $item->getSize() > 2000000 ) {
				continue;
			}
			$rel                  = ltrim( str_replace( $dir, '', $item->getPathname() ), '/\\' );
			$body                 = (string) file_get_contents( $item->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local plugin file read.
			$out['files'][ $rel ] = md5( $body );
			if ( preg_match_all( '#https?://([a-z0-9.-]+\.[a-z]{2,})#i', $body, $m ) ) {
				foreach ( $m[1] as $host ) {
					$host = strtolower( $host );
					if ( $host !== $own && ! preg_match( '/(^|\.)(WordPress\.org|w\.org|gnu\.org|php\.net|schema\.org|w3\.org|github\.com)$/i', $host ) ) {
						$hosts[ $host ] = true;
					}
				}
			}
			if ( preg_match_all( $regex, $body, $m ) ) {
				foreach ( $m[1] as $fn ) {
					$fn                  = strtolower( $fn );
					$out['risky'][ $fn ] = isset( $out['risky'][ $fn ] ) ? $out['risky'][ $fn ] + 1 : 1;
				}
			}
			foreach ( preg_split( '/\R/', $body ) as $line ) {
				if ( strlen( $line ) < 400 && ( preg_match( $regex, $line ) || preg_match( '#https?://#i', $line ) ) ) {
					$out['lines'][] = $rel . ': ' . trim( $line );
				}
			}
		}
		$out['hosts'] = array_keys( $hosts );
		$out['lines'] = array_values( array_unique( $out['lines'] ) );
		return $out;
	}
}
