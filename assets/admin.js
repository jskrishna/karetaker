(function () {
	'use strict';

	function qs(sel, root) {
		return (root || document).querySelector(sel);
	}

	function qsa(sel, root) {
		return Array.prototype.slice.call((root || document).querySelectorAll(sel));
	}

	function parseJsonAttr(raw, fallback) {
		try {
			return JSON.parse(raw || '');
		} catch (e) {
			return fallback;
		}
	}

	function severityLabel(sev) {
		if (sev === 2) {
			return 'Act-now';
		}
		if (sev === 1) {
			return 'Watch';
		}
		return 'Log';
	}

	function openDrawer(row) {
		var drawer = qs('.kt-drawer');
		if (!drawer || !row) {
			return;
		}

		var code = row.getAttribute('data-karetaker-code') || '';
		var time = row.getAttribute('data-karetaker-time') || '';
		var id = row.getAttribute('data-karetaker-id') || '';
		var severity = parseInt(row.getAttribute('data-karetaker-severity') || '0', 10);
		var json = row.getAttribute('data-karetaker-json') || '{}';
		var guidance = parseJsonAttr(row.getAttribute('data-karetaker-guidance'), {});

		var eyebrow = qs('.kt-drawer__eyebrow', drawer);
		var title = qs('.kt-drawer__title', drawer);
		var meta = qs('.kt-drawer__meta', drawer);
		var summary = qs('.kt-drawer__summary', drawer);
		var steps = qs('.kt-drawer__steps', drawer);
		var link = qs('.kt-drawer__link', drawer);
		var actionBox = qs('.kt-drawer__action', drawer);
		var pre = qs('.kt-drawer pre', drawer);

		if (eyebrow) {
			eyebrow.textContent = severityLabel(severity) + ' · ' + code;
			eyebrow.className = 'kt-drawer__eyebrow' + (severity === 2 ? ' is-act' : '');
		}

		if (title) {
			title.textContent = guidance.title || code;
		}

		if (meta) {
			meta.textContent = '#' + id + ' · ' + time + ' UTC';
		}

		if (summary) {
			summary.textContent = guidance.summary || '';
		}

		if (steps) {
			steps.innerHTML = '';
			var list = Array.isArray(guidance.steps) ? guidance.steps : [];
			list.forEach(function (step) {
				var li = document.createElement('li');
				li.textContent = step;
				steps.appendChild(li);
			});
		}

		if (link) {
			if (guidance.link) {
				link.href = guidance.link;
				link.textContent = guidance.link_label || 'Open';
				link.style.display = 'inline-flex';
			} else {
				link.removeAttribute('href');
				link.style.display = 'none';
			}
		}

		if (actionBox) {
			actionBox.style.display = guidance.summary || (guidance.steps && guidance.steps.length) ? '' : 'none';
		}

		if (pre) {
			try {
				pre.textContent = JSON.stringify(JSON.parse(json), null, 2);
			} catch (e) {
				pre.textContent = json;
			}
		}

		drawer.classList.add('is-open');
		drawer.setAttribute('aria-hidden', 'false');
	}

	function closeDrawer() {
		var drawer = qs('.kt-drawer');
		if (drawer) {
			drawer.classList.remove('is-open');
			drawer.setAttribute('aria-hidden', 'true');
		}
	}

	function initDrawer() {
		qsa('.karetaker-event-row').forEach(function (row) {
			row.addEventListener('click', function () {
				openDrawer(row);
			});
			row.addEventListener('keydown', function (e) {
				if (e.key === 'Enter' || e.key === ' ') {
					e.preventDefault();
					openDrawer(row);
				}
			});
		});

		var drawer = qs('.kt-drawer');
		if (!drawer) {
			return;
		}

		var closeBtn = qs('.kt-drawer__close', drawer);
		var backdrop = qs('.kt-drawer__backdrop', drawer);
		if (closeBtn) {
			closeBtn.addEventListener('click', closeDrawer);
		}
		if (backdrop) {
			backdrop.addEventListener('click', closeDrawer);
		}

		var copyBtn = qs('.kt-drawer__copy', drawer);
		if (copyBtn) {
			copyBtn.addEventListener('click', function () {
				var pre = qs('.kt-drawer pre', drawer);
				if (!pre) {
					return;
				}
				var text = pre.textContent || '';
				if (navigator.clipboard && navigator.clipboard.writeText) {
					navigator.clipboard.writeText(text);
				}
			});
		}

		document.addEventListener('keydown', function (e) {
			if (e.key === 'Escape') {
				closeDrawer();
			}
		});
	}

	function initScanForm() {
		var form = qs('.kt-scan-form');
		if (!form) {
			return;
		}
		form.addEventListener('submit', function () {
			var btn = qs('.kt-btn--primary', form);
			if (btn) {
				btn.classList.add('is-busy');
				btn.setAttribute('disabled', 'disabled');
				var label = btn.getAttribute('data-busy-label') || 'Running…';
				btn.innerHTML = '<span class="dashicons dashicons-update" aria-hidden="true"></span> ' + label;
			}
		});
	}

	document.addEventListener('DOMContentLoaded', function () {
		initDrawer();
		initScanForm();
	});
})();
