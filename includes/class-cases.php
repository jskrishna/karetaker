<?php
/**
 * Incident cases: group issues, work a checklist, keep a record.
 *
 * @package Karetaker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stores incident cases (INC-0001...) with linked issues, checklist progress, owner and timeline.
 *
 * @since 1.0.0
 * @package Karetaker
 */
class Karetaker_Cases {

	const OPTION = 'karetaker_cases';

	/**
	 * Checklist steps: key => [title, help, action label, action key].
	 *
	 * @since 1.0.0
	 * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
	 */
	public static function steps() {
		return array(
			'evidence' => array( __( 'Preserve evidence', 'karetaker' ), __( 'Don’t delete files yet. Take a full backup of files and database as they are now.', 'karetaker' ), __( 'Backup guide', 'karetaker' ), 'backup' ),
			'sessions' => array( __( 'Sign out every session', 'karetaker' ), __( 'Ends all sessions, including any the attacker holds.', 'karetaker' ), __( 'Sign out all', 'karetaker' ), 'sessions' ),
			'admins'   => array( __( 'Remove unknown administrators', 'karetaker' ), __( 'Check every administrator, including hidden accounts.', 'karetaker' ), __( 'Open users', 'karetaker' ), 'users' ),
			'files'    => array( __( 'Review changed files', 'karetaker' ), __( 'Compare changed files with your backup and remove code from uploads.', 'karetaker' ), __( 'Open file integrity', 'karetaker' ), 'files' ),
			'rotate'   => array( __( 'Rotate credentials', 'karetaker' ), __( 'Admin passwords, database password, security keys, API tokens.', 'karetaker' ), __( 'Rotate Karetaker tokens', 'karetaker' ), 'tokens' ),
			'plugins'  => array( __( 'Deal with risky plugins', 'karetaker' ), __( 'Replace closed plugins and review suspicious updates.', 'karetaker' ), __( 'Open plugin risk', 'karetaker' ), 'plugins' ),
			'confirm'  => array( __( 'Confirm clean and monitor', 'karetaker' ), __( 'Run a full check. Keep the case open for 7 days of clean checks.', 'karetaker' ), __( 'Run check', 'karetaker' ), 'check' ),
		);
	}

	/**
	 * All cases, newest first.
	 *
	 * @since 1.0.0
	 * @return array<int, array<string, mixed>>
	 */
	public static function all() {
		$cases = get_option( self::OPTION, array() );
		$cases = is_array( $cases ) ? array_values( $cases ) : array();
		usort(
			$cases,
			static function ( $a, $b ) {
				return (int) $b['number'] - (int) $a['number'];
			}
		);
		return $cases;
	}

	/**
	 * One case by id (INC-0001).
	 *
	 * @since 1.0.0
	 * @param string $id Case id.
	 * @return array<string, mixed>|null
	 */
	public static function get( $id ) {
		foreach ( self::all() as $incident ) {
			if ( $incident['id'] === $id ) {
				return $incident;
			}
		}
		return null;
	}

	/**
	 * Saves one case.
	 *
	 * @since 1.0.0
	 * @param array<string, mixed> $incident Case.
	 * @return void
	 */
	private static function put( array $incident ) {
		$cases = array();
		foreach ( self::all() as $existing ) {
			$cases[ $existing['id'] ] = $existing;
		}
		$cases[ $incident['id'] ] = $incident;
		update_option( self::OPTION, array_values( array_slice( $cases, -200, null, true ) ), false );
	}

	/**
	 * Opens a new case linked to issues.
	 *
	 * @since 1.0.0
	 * @param string $title     Title.
	 * @param int[]  $event_ids Issue event ids to link.
	 * @return array<string, mixed>
	 */
	public static function open( $title, array $event_ids ) {
		$number = 1;
		foreach ( self::all() as $incident ) {
			$number = max( $number, (int) $incident['number'] + 1 );
		}
		$titles = array();
		foreach ( $event_ids as $event_id ) {
			$issue = Karetaker_Issues::find( (int) $event_id );
			if ( $issue ) {
				$titles[] = $issue['title'];
			}
		}
		if ( '' === trim( (string) $title ) ) {
			$title = $titles ? implode( ' + ', array_slice( $titles, 0, 2 ) ) : __( 'Suspected compromise', 'karetaker' );
		}
		$incident = array(
			'id'       => sprintf( 'INC-%04d', $number ),
			'number'   => $number,
			'title'    => sanitize_text_field( $title ),
			'status'   => 'open',
			'issues'   => array_values( array_unique( array_map( 'intval', $event_ids ) ) ),
			'done'     => array(),
			'owner'    => get_current_user_id(),
			'opened'   => time(),
			'closed'   => 0,
			'timeline' => array(
				array(
					'at'   => time(),
					'user' => get_current_user_id(),
					'text' => __( 'Incident opened', 'karetaker' ),
				),
			),
		);
		self::put( $incident );
		self::log( $incident['id'], 'opened' );
		return $incident;
	}

	/**
	 * Updates a case: tick a step, assign, close or reopen.
	 *
	 * @since 1.0.0
	 * @param string $id     Case id.
	 * @param string $action step, owner, close, reopen, link.
	 * @param mixed  $value  Step key + bool, owner id, or event id.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function update( $id, $action, $value = null ) {
		$incident = self::get( $id );
		if ( ! $incident ) {
			return new WP_Error( 'karetaker_case_missing', __( 'Incident not found.', 'karetaker' ) );
		}
		$steps = self::steps();
		switch ( $action ) {
			case 'step':
				$key = is_array( $value ) && isset( $value['key'] ) ? sanitize_key( $value['key'] ) : '';
				if ( ! isset( $steps[ $key ] ) ) {
					return new WP_Error( 'karetaker_case_step', __( 'Unknown step.', 'karetaker' ) );
				}
				$done = ! empty( $value['done'] );
				if ( $done ) {
					$incident['done'][ $key ] = time();
				} else {
					unset( $incident['done'][ $key ] );
				}
				/* translators: %s: step title */
				$text = sprintf( $done ? __( 'Done: %s', 'karetaker' ) : __( 'Not done: %s', 'karetaker' ), $steps[ $key ][0] );
				break;
			case 'owner':
				$incident['owner'] = (int) $value;
				$user              = get_userdata( (int) $value );
				/* translators: %s: user login */
				$text = sprintf( __( 'Owner set to %s', 'karetaker' ), $user ? $user->user_login : '—' );
				break;
			case 'close':
				if ( count( $incident['done'] ) < count( $steps ) ) {
					return new WP_Error( 'karetaker_case_open_steps', __( 'Finish every step before closing the incident.', 'karetaker' ) );
				}
				$incident['status'] = 'closed';
				$incident['closed'] = time();
				$text               = __( 'Incident closed', 'karetaker' );
				break;
			case 'reopen':
				$incident['status'] = 'open';
				$incident['closed'] = 0;
				$text               = __( 'Incident reopened', 'karetaker' );
				break;
			case 'link':
				$incident['issues'][] = (int) $value;
				$incident['issues']   = array_values( array_unique( $incident['issues'] ) );
				/* translators: %d: event id */
				$text = sprintf( __( 'Linked issue #%d', 'karetaker' ), (int) $value );
				break;
			default:
				return new WP_Error( 'karetaker_case_action', __( 'Unknown action.', 'karetaker' ) );
		}
		$incident['timeline'][] = array(
			'at'   => time(),
			'user' => get_current_user_id(),
			'text' => $text,
		);
		self::put( $incident );
		self::log( $id, $action );
		return $incident;
	}

	/**
	 * Records the change in the audit trail.
	 *
	 * @since 1.0.0
	 * @param string $id     Case id.
	 * @param string $action Action.
	 * @return void
	 */
	private static function log( $id, $action ) {
		Karetaker_Events::record(
			'case_updated',
			array(
				'case'   => $id,
				'action' => $action,
			)
		);
	}

	/**
	 * Export payload for a case.
	 *
	 * @since 1.0.0
	 * @param array<string, mixed> $incident Case.
	 * @return array<string, mixed>
	 */
	public static function export( array $incident ) {
		$issues = array();
		foreach ( $incident['issues'] as $event_id ) {
			$issue = Karetaker_Issues::find( (int) $event_id );
			if ( $issue ) {
				$issues[] = array(
					'id'      => $issue['id'],
					'title'   => $issue['title'],
					'status'  => $issue['status'],
					'opened'  => $issue['opened'],
					'details' => $issue['details'],
				);
			}
		}
		$steps = array();
		foreach ( self::steps() as $key => $step ) {
			$steps[] = array(
				'step' => $step[0],
				'done' => isset( $incident['done'][ $key ] ) ? gmdate( 'c', (int) $incident['done'][ $key ] ) : null,
			);
		}
		$timeline = array();
		foreach ( $incident['timeline'] as $row ) {
			$user       = get_userdata( (int) $row['user'] );
			$timeline[] = array(
				'at'   => gmdate( 'c', (int) $row['at'] ),
				'user' => $user ? $user->user_login : '',
				'text' => $row['text'],
			);
		}
		return array(
			'site'      => home_url( '/' ),
			'case'      => $incident['id'],
			'title'     => $incident['title'],
			'status'    => $incident['status'],
			'opened'    => gmdate( 'c', (int) $incident['opened'] ),
			'closed'    => $incident['closed'] ? gmdate( 'c', (int) $incident['closed'] ) : null,
			'issues'    => $issues,
			'checklist' => $steps,
			'timeline'  => $timeline,
		);
	}

	/**
	 * Printable HTML record for a case.
	 *
	 * @since 1.0.0
	 * @param array<string, mixed> $incident Case.
	 * @return string
	 */
	public static function export_html( array $incident ) {
		$data = self::export( $incident );
		ob_start();
		?>
<!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8"><title><?php echo esc_html( $data['case'] . ' — ' . $data['title'] ); ?></title>
<style>body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;color:#1d2327;max-width:760px;margin:24px auto;padding:0 20px;line-height:1.5}h1{font-size:20px}h2{font-size:15px;border-bottom:1px solid #dcdcde;padding-bottom:6px;margin-top:24px}table{width:100%;border-collapse:collapse;font-size:13px}td,th{text-align:left;border-bottom:1px solid #f0f0f1;padding:6px}.m{color:#646970}</style></head>
<body>
<h1><?php echo esc_html( $data['case'] . ' · ' . $data['title'] ); ?></h1>
<p class="m"><?php echo esc_html( $data['site'] . ' · ' . $data['status'] . ' · ' . $data['opened'] ); ?></p>
<h2><?php echo esc_html__( 'Linked issues', 'karetaker' ); ?></h2>
<table><tr><th>#</th><th><?php echo esc_html__( 'Issue', 'karetaker' ); ?></th><th><?php echo esc_html__( 'Status', 'karetaker' ); ?></th><th><?php echo esc_html__( 'Opened (UTC)', 'karetaker' ); ?></th></tr>
		<?php foreach ( $data['issues'] as $issue ) : ?>
<tr><td><?php echo esc_html( (string) $issue['id'] ); ?></td><td><?php echo esc_html( $issue['title'] ); ?></td><td><?php echo esc_html( $issue['status'] ); ?></td><td><?php echo esc_html( $issue['opened'] ); ?></td></tr>
		<?php endforeach; ?>
</table>
<h2><?php echo esc_html__( 'Checklist', 'karetaker' ); ?></h2>
<table>
		<?php foreach ( $data['checklist'] as $step ) : ?>
<tr><td><?php echo esc_html( $step['done'] ? '✓' : '○' ); ?></td><td><?php echo esc_html( $step['step'] ); ?></td><td class="m"><?php echo esc_html( (string) $step['done'] ); ?></td></tr>
		<?php endforeach; ?>
</table>
<h2><?php echo esc_html__( 'Timeline', 'karetaker' ); ?></h2>
<table>
		<?php foreach ( $data['timeline'] as $row ) : ?>
<tr><td class="m"><?php echo esc_html( $row['at'] ); ?></td><td><?php echo esc_html( $row['user'] ); ?></td><td><?php echo esc_html( $row['text'] ); ?></td></tr>
		<?php endforeach; ?>
</table>
</body></html>
		<?php
		return (string) ob_get_clean();
	}
}
