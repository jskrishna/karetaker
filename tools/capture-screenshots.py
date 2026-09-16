#!/usr/bin/env python3
"""Capture Karetaker admin screenshots via Playwright (full element, no clip)."""

from __future__ import annotations

import sys
from pathlib import Path

from playwright.sync_api import sync_playwright

ROOT = Path(__file__).resolve().parents[1]
OUT = ROOT / "assets"
BASE = "http://127.0.0.1:9410"
CHROME = (
	Path.home()
	/ "Library/Caches/ms-playwright/chromium-1243/chrome-mac-arm64"
	/ "Google Chrome for Testing.app/Contents/MacOS/Google Chrome for Testing"
)

TABS = [
	("screenshot-1.png", f"{BASE}/wp-admin/admin.php?page=karetaker", ".karetaker-wrap"),
	("screenshot-2.png", f"{BASE}/wp-admin/admin.php?page=karetaker&tab=activity", ".karetaker-wrap"),
	("screenshot-3.png", f"{BASE}/wp-admin/admin.php?page=karetaker&tab=harden", ".karetaker-wrap"),
	("screenshot-4.png", f"{BASE}/wp-admin/admin.php?page=karetaker&tab=settings", ".karetaker-wrap"),
	("screenshot-5.png", f"{BASE}/wp-admin/admin.php?page=karetaker&tab=settings", ".kt-email-preview"),
	("screenshot-6.png", f"{BASE}/wp-admin/admin.php?page=karetaker", ".karetaker-wrap"),
]


def prep(page) -> None:
	page.evaluate(
		"""() => {
			document.getElementById('wp-auth-check-wrap')?.remove();
			document.querySelectorAll('.wp-auth-check-close').forEach((e) => e.click());
			['adminmenumain', 'wpadminbar', 'wpfooter'].forEach((id) => {
				const el = document.getElementById(id);
				if (el) el.style.display = 'none';
			});
			const content = document.getElementById('wpcontent');
			if (content) {
				content.style.marginLeft = '0';
				content.style.paddingLeft = '24px';
				content.style.paddingRight = '24px';
			}
			document.body.classList.remove('folded', 'auto-fold');
		}"""
	)


def wait_ready(page, selector: str) -> None:
	page.wait_for_selector(selector, timeout=30000)
	page.wait_for_load_state("networkidle")
	# Brand mark / logo must finish painting.
	page.wait_for_function(
		"""() => {
			const img = document.querySelector('.kt-brand__mark-img, .kt-brand img');
			if (!img) return true;
			return img.complete && img.naturalWidth > 0;
		}""",
		timeout=15000,
	)
	page.wait_for_timeout(400)


def fetch_login_cookies() -> list[dict]:
	"""Use curl auto-login cookies so Playwright skips the login form."""
	import subprocess
	import tempfile

	jar_path = tempfile.mktemp(prefix="kt-cookies-")
	subprocess.run(
		[
			"curl",
			"-s",
			"-c",
			jar_path,
			"-b",
			jar_path,
			"-L",
			f"{BASE}/?playground-auto-login=1",
			"-o",
			"/dev/null",
		],
		check=True,
	)
	cookies: list[dict] = []
	for line in Path(jar_path).read_text().splitlines():
		if not line or (line.startswith("#") and not line.startswith("#HttpOnly_")):
			continue
		line = line.replace("#HttpOnly_", "", 1)
		parts = line.split("\t")
		if len(parts) < 7:
			continue
		domain, _flag, path, _secure, _exp, name, value = parts[:7]
		cookies.append(
			{
				"name": name,
				"value": value,
				"domain": domain,
				"path": path or "/",
			}
		)
	Path(jar_path).unlink(missing_ok=True)
	return cookies


def main() -> int:
	if not CHROME.exists():
		print("Chromium binary missing:", CHROME, file=sys.stderr)
		return 1

	cookies = fetch_login_cookies()
	if not any(c["name"].startswith("wordpress_logged_in") for c in cookies):
		print("Auto-login cookies missing", file=sys.stderr)
		return 1

	with sync_playwright() as p:
		browser = p.chromium.launch(
			executable_path=str(CHROME),
			headless=True,
		)
		context = browser.new_context(
			viewport={"width": 1440, "height": 2400},
			device_scale_factor=1,
		)
		context.add_cookies(cookies)
		page = context.new_page()

		page.goto(f"{BASE}/wp-admin/admin.php?page=karetaker", wait_until="domcontentloaded")
		if "wp-login.php" in page.url:
			print("Login failed:", page.url, file=sys.stderr)
			return 1
		print("logged in:", page.url)

		# Warm overview + run scan once so Overview/Activity look alive.
		page.goto(f"{BASE}/wp-admin/admin.php?page=karetaker", wait_until="domcontentloaded")
		prep(page)
		wait_ready(page, ".karetaker-wrap")
		btn = page.locator("button.kt-btn--primary", has_text="Run scan now")
		if btn.count():
			btn.first.click()
			page.wait_for_timeout(6000)
			page.goto(f"{BASE}/wp-admin/admin.php?page=karetaker", wait_until="domcontentloaded")

		for name, url, selector in TABS:
			page.goto(url, wait_until="domcontentloaded")
			prep(page)
			wait_ready(page, ".karetaker-wrap")

			out = OUT / name

			if name == "screenshot-5.png":
				# Expand full HTML email (no clipped iframe) and capture as a clean frame.
				html = page.evaluate(
					"""() => {
						const frame = document.querySelector('.kt-email-preview__frame');
						return frame ? frame.getAttribute('srcdoc') : '';
					}"""
				)
				if not html:
					print("email srcdoc missing", file=sys.stderr)
					return 1
				email_page = context.new_page()
				email_page.set_viewport_size({"width": 720, "height": 1600})
				email_page.set_content(html, wait_until="load")
				email_page.wait_for_function(
					"""() => {
						const img = document.querySelector('img');
						return !img || (img.complete && img.naturalWidth > 0);
					}""",
					timeout=15000,
				)
				email_page.wait_for_timeout(300)
				# Prefer the email root table (avoids giant empty canvas).
				root = email_page.locator("body > table").first
				if root.count():
					root.screenshot(path=str(out), type="png", animations="disabled")
				else:
					email_page.screenshot(path=str(out), type="png", full_page=True, animations="disabled")
				# Trim leftover whitespace if any.
				try:
					from PIL import Image, ImageChops

					im = Image.open(out).convert("RGB")
					bg = Image.new("RGB", im.size, (238, 240, 241))
					diff = ImageChops.difference(im, bg)
					bbox = diff.getbbox()
					if bbox:
						pad = 24
						l, t, r, b = bbox
						l = max(0, l - pad)
						t = max(0, t - pad)
						r = min(im.width, r + pad)
						b = min(im.height, b + pad)
						im.crop((l, t, r, b)).save(out)
				except Exception:
					pass
				email_page.close()
				print(f"wrote {out} ({out.stat().st_size} bytes)")
				continue

			if name == "screenshot-6.png":
				# Quiet-week framing: remove noise notices, keep calm overview.
				page.evaluate(
					"""() => {
						document.querySelectorAll('.kt-alert, .notice').forEach((n) => n.remove());
						const body = document.querySelector('.kt-body');
						if (!body || document.querySelector('.kt-quiet-banner')) return;
						const ban = document.createElement('div');
						ban.className = 'kt-quiet-banner';
						ban.style.cssText = 'margin:0 0 14px;padding:12px 14px;border:1px solid #c3e6cb;border-radius:8px;background:#edfaef;color:#1e4620;font-size:13px;line-height:1.5;';
						ban.textContent = 'A quiet week. Guard is clear, no act-now events, and the watchtower stays silent until something needs you.';
						body.prepend(ban);
					}"""
				)
				page.wait_for_timeout(200)

			target = page.locator(selector).first
			target.scroll_into_view_if_needed()
			target.screenshot(path=str(out), type="png", animations="disabled")
			print(f"wrote {out} ({out.stat().st_size} bytes)")

		browser.close()
	return 0


if __name__ == "__main__":
	raise SystemExit(main())
