/* Karetaker admin screens. Plain JS; talks to karetaker/v1/admin/* through wp.apiFetch. */
(function () {
	'use strict';

	var root = document.getElementById('kt');
	if (!root) {
		return;
	}
	var L = window.karetakerL10n || {};
	var data = window.karetakerData || { items: {} };
	var $ = function (s, r) { return (r || root).querySelector(s); };
	var $$ = function (s, r) { return Array.prototype.slice.call((r || root).querySelectorAll(s)); };
	var fmt = function (s) {
		var args = Array.prototype.slice.call(arguments, 1), i = 0;
		return String(s || '').replace(/%(\d\$)?[sd]/g, function (m, pos) {
			return pos ? args[parseInt(pos, 10) - 1] : args[i++];
		});
	};

	/* ---------- toast, API, reload ---------- */
	function toast(msg, error) {
		var t = $('#toast');
		if (!t || !msg) {
			return;
		}
		t.textContent = msg;
		t.classList.toggle('error', !!error);
		t.classList.add('show');
		clearTimeout(t._h);
		t._h = setTimeout(function () { t.classList.remove('show'); }, 2600);
	}
	(function () {
		var t = $('#toast');
		if (t && t.classList.contains('show')) {
			t._h = setTimeout(function () { t.classList.remove('show'); }, 2600);
		}
		try {
			var m = window.sessionStorage.getItem('ktToast');
			if (m) {
				window.sessionStorage.removeItem('ktToast');
				toast(m);
			}
		} catch (e) {}
	})();

	function api(path, body, method) {
		return window.wp.apiFetch({ path: '/karetaker/v1/admin/' + path, method: method || 'POST', data: body || {} })
			.catch(function (err) {
				toast((err && err.message) || L.error, true);
				throw err;
			});
	}
	function reload(msg, url) {
		try {
			if (msg) {
				window.sessionStorage.setItem('ktToast', msg);
			}
		} catch (e) {}
		if (url) {
			window.location.href = url;
		} else {
			window.location.reload();
		}
	}
	function busy(btn, on) {
		if (!btn) {
			return;
		}
		btn.disabled = !!on;
		btn.classList.toggle('is-busy', !!on);
	}
	function on(sel, type, fn) {
		root.addEventListener(type, function (e) {
			var el = e.target.closest(sel);
			if (el && root.contains(el)) {
				fn(el, e);
			}
		});
	}
	var esc = function (s) {
		return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
		});
	};

	/* ---------- bridge for add-ons ---------- */
	window.karetakerAdmin = { root: root, L: L, data: data, $: $, $$: $$, fmt: fmt, toast: toast, api: api, reload: reload, busy: busy, on: on, esc: esc };

	/* ---------- onboarding ---------- */
	var onb = $('#onb');
	if (onb) {
		var step = 1, checked = false;
		var showStep = function (n) {
			step = n;
			$$('[data-step]', onb).forEach(function (s) { if (s.tagName === 'SECTION') { s.hidden = +s.dataset.step !== n; } });
			$$('.steps i', onb).forEach(function (b, i) { b.classList.toggle('on', i < n); });
			$('#onbBack').hidden = n === 1 || n === 3;
			$('#onbSkip').hidden = n !== 4;
			var next = $('#onbNext');
			next.textContent = n === 4 ? L.finish : L['continue'];
			next.disabled = n === 3 && !checked;
			if (n === 3 && !checked) {
				runFirstCheck();
			}
			var h = $('section[data-step="' + n + '"] h2', onb);
			if (h) {
				h.setAttribute('tabindex', '-1');
				h.focus();
			}
		};
		var runFirstCheck = function () {
			var lis = $$('#run li', onb), i = 0, result = null;
			lis.forEach(function (li) { li.className = ''; li.querySelector('.st').textContent = ''; li.querySelector('.r').textContent = ''; });
			var spin = setInterval(function () {
				if (result) {
					return;
				}
				lis.forEach(function (li, j) { li.classList.toggle('now', j === i % lis.length); });
				i++;
			}, 600);
			lis[0].classList.add('now');
			api('first-check').then(function (res) {
				result = res;
				clearInterval(spin);
				var k = 0;
				var tick = function () {
					if (k > 0) {
						var p = lis[k - 1], row = res.checks[k - 1] || { level: '', value: '' };
						p.classList.remove('now');
						p.classList.add('done');
						if (row.level) {
							p.classList.add(row.level);
							p.querySelector('.st').textContent = '!';
							p.querySelector('.r').textContent = row.value;
						} else {
							p.querySelector('.st').textContent = '✓';
							p.querySelector('.r').textContent = row.value || L.ok;
						}
					}
					if (k < lis.length) {
						lis.forEach(function (li) { li.classList.remove('now'); });
						lis[k].classList.add('now');
						k++;
						setTimeout(tick, 350);
					} else {
						var r = $('#runResult', onb), n = res.found;
						r.hidden = false;
						r.classList.toggle('ok', !n);
						r.innerHTML = '<span style="font-size:22px" aria-hidden="true">' + (n ? '⚠️' : '✅') + '</span><div><b>' +
							esc(n ? (n === 1 ? L.foundOne : fmt(L.foundSome, n)) : L.foundNone) + '</b><div class="muted">' + esc(n ? L.foundSub : L.noneSub) + '</div></div>';
						checked = true;
						$('#onbNext').disabled = false;
					}
				};
				tick();
			}).catch(function () {
				clearInterval(spin);
				checked = true;
				$('#onbNext').disabled = false;
			});
		};
		var finish = function (later) {
			var harden = {};
			$$('[data-harden]', onb).forEach(function (c) { harden[c.dataset.harden] = c.checked; });
			var type = $('#types [aria-checked="true"]', onb);
			busy($('#onbNext'), true);
			api('onboarding', {
				later: !!later,
				email: $('#onbEmail').value,
				site_type: type ? type.dataset.type : '',
				harden: harden
			}).then(function () {
				reload(L.setupDone, onb.dataset.home);
			}).catch(function () { busy($('#onbNext'), false); });
		};
		$$('#types .type', onb).forEach(function (b) {
			b.addEventListener('click', function () {
				$$('#types .type', onb).forEach(function (x) { x.setAttribute('aria-checked', x === b ? 'true' : 'false'); });
			});
		});
		$('#onbNext').addEventListener('click', function () { if (step < 4) { showStep(step + 1); } else { finish(false); } });
		$('#onbBack').addEventListener('click', function () { showStep(step - 1); });
		$('#onbSkip').addEventListener('click', function () {
			$$('[data-harden]', onb).forEach(function (c) { c.checked = false; });
			finish(false);
		});
		$('#onbLater').addEventListener('click', function () { finish(true); });
	}

	/* ---------- simple controls ---------- */
	on('[data-kt-scan]', 'click', function (b) {
		var html = b.innerHTML;
		busy(b, true);
		b.textContent = L.checking;
		api('scan').then(function () { reload(L.checkDone); }).catch(function () { busy(b, false); b.innerHTML = html; });
	});

	on('[data-kt-setting]', 'change', function (el) {
		var key = el.dataset.ktSetting;
		var value = el.type === 'checkbox' ? el.checked : el.value;
		api('setting', { key: key, value: value }).then(function () {
			if (el.dataset.reload) {
				reload(L.saved);
			} else {
				toast(L.saved);
			}
		}).catch(function () {
			if (el.type === 'checkbox') {
				el.checked = !el.checked;
			}
		});
	});

	on('[data-kt-harden]', 'change', function (el) {
		api('harden', { key: el.dataset.ktHarden, on: el.checked }).then(function () {
			var tag = el.closest('.rows > div').querySelector('[data-live]');
			if (tag) {
				tag.className = 'tag ' + (el.checked ? 'ok' : 'off');
				tag.textContent = el.checked ? tag.dataset.on : tag.dataset.off;
			}
			toast(L.saved);
		}).catch(function () { el.checked = !el.checked; });
	});

	on('[data-kt-pause]', 'click', function (b) {
		var hours = +b.dataset.ktPause;
		busy(b, true);
		api('pause', { hours: hours }).then(function () { reload(hours ? L.paused : L.resumed); }).catch(function () { busy(b, false); });
	});

	on('[data-kt-toggle]', 'click', function (b) {
		var el = document.getElementById(b.dataset.ktToggle);
		if (el) {
			el.hidden = !el.hidden;
			b.setAttribute('aria-expanded', el.hidden ? 'false' : 'true');
		}
	});

	on('[data-kt-submit]', 'change', function (el) { el.form.submit(); });

	/* ---------- side sheet: issue guidance and connect ---------- */
	var panel = $('#panel'), scrim = $('#scrim'), lastFocus = null, cur = null;
	function mode(m) {
		$$('[data-mode]', panel).forEach(function (x) {
			x.hidden = x.dataset.mode !== m || (m === 'connect' && x.tagName === 'FORM');
		});
	}
	function openSheet() {
		lastFocus = document.activeElement;
		panel.classList.add('open');
		scrim.classList.add('open');
		$('#pClose').focus();
	}
	function closeSheet() {
		if (!panel.classList.contains('open')) {
			return;
		}
		panel.classList.remove('open');
		var drawer = $('#drawer');
		if (!drawer || !drawer.classList.contains('open')) {
			scrim.classList.remove('open');
		}
		if (lastFocus && document.contains(lastFocus)) {
			lastFocus.focus();
		}
	}
	function openIssueSheet(item) {
		cur = item;
		mode('issue');
		$('#pTitle').textContent = item.title;
		$('#pWhat').textContent = item.what;
		$('#pWhy').textContent = item.why || '';
		$('#pWhyWrap').hidden = !item.why;
		$('#pSteps').innerHTML = (item.steps || []).map(function (s) { return '<li>' + esc(s) + '</li>'; }).join('');
		var link = $('#pLink');
		link.hidden = !item.link;
		if (item.link) {
			link.firstElementChild.href = item.link;
			link.firstElementChild.textContent = item.linkText || item.link;
		}
		$('#pDl').innerHTML = (item.details || []).map(function (d) { return '<dt>' + esc(d[0]) + '</dt><dd>' + esc(d[1]) + '</dd>'; }).join('');
		$('#pMine').hidden = !item.canMark;
		$('#pDone').hidden = !item.canAct;
		$('footer[data-mode="issue"]', panel).hidden = !item.canMark && !item.canAct;
		openSheet();
	}
	if (panel) {
		$('#pClose').addEventListener('click', closeSheet);
		scrim.addEventListener('click', function () { closeSheet(); closeDrawer(); });
		$('#pMine').addEventListener('click', function (e) {
			busy(e.currentTarget, true);
			api('issue', { id: cur.id, action: 'expected' }).then(function () { reload(L.mine); }).catch(function () { busy(e.currentTarget, false); });
		});
		$('#pDone').addEventListener('click', function (e) {
			busy(e.currentTarget, true);
			api('issue', { id: cur.id, action: 'resolve' }).then(function () { reload(L.fixed); }).catch(function () { busy(e.currentTarget, false); });
		});
	}

	var connectForm = null;
	on('[data-connect]', 'click', function (b) {
		connectForm = $('form[data-channel="' + b.dataset.connect + '"]', panel);
		if (!connectForm) {
			return;
		}
		mode('connect');
		connectForm.hidden = false;
		$('#pTitle').textContent = connectForm.dataset.label;
		$('#cRemove').hidden = connectForm.dataset.connected !== '1';
		openSheet();
	});
	if ($('#cSave')) {
		var sendChannel = function (remove, btn) {
			var fields = {};
			$$('input', connectForm).forEach(function (i) { fields[i.name] = i.value; });
			busy(btn, true);
			api('channel', { channel: connectForm.dataset.channel, fields: fields, remove: remove }).then(function () {
				reload(fmt(remove ? L.removed : L.connected, connectForm.dataset.label));
			}).catch(function () { busy(btn, false); });
		};
		$('#cSave').addEventListener('click', function (e) { sendChannel(false, e.currentTarget); });
		$('#cRemove').addEventListener('click', function (e) { sendChannel(true, e.currentTarget); });
		$$('form.connect', panel).forEach(function (f) {
			f.addEventListener('submit', function (e) { e.preventDefault(); $('#cSave').click(); });
		});
	}

	on('[data-open]', 'click', function (el, e) {
		if (e.target.closest('[data-sel]') || (el.tagName === 'TR' && e.target.closest('button,a,input,label'))) {
			return;
		}
		var item = data.items && data.items[el.dataset.open];
		if (!item) {
			return;
		}
		if (typeof window.karetakerAdmin.openItem === 'function' && window.karetakerAdmin.openItem(item, el)) {
			return;
		}
		openIssueSheet(item);
	});

	/* ---------- sessions and test alert ---------- */
	on('[data-kt-sessions]', 'click', function (b) {
		var all = b.dataset.ktSessions === 'all';
		if (all && !window.confirm(L.confirmAll)) {
			return;
		}
		busy(b, true);
		api('sessions', all ? { scope: 'all' } : { user_id: +b.dataset.user, verifier: b.dataset.verifier })
			.then(function () { reload(all ? L.signedOutAll : L.signedOut); })
			.catch(function () { busy(b, false); });
	});
	on('[data-kt-test]', 'click', function (b) {
		busy(b, true);
		api('test-alert').then(function (res) {
			busy(b, false);
			toast(res.sent && res.sent.length ? fmt(L.testSent, res.sent.join(', ')) : L.testNone, !(res.sent && res.sent.length));
		}).catch(function () { busy(b, false); });
	});

	/* ---------- keyboard ---------- */
	document.addEventListener('keydown', function (e) {
		if (e.key === 'Escape') {
			if (panel) {
				closeSheet();
			}
			$$('.menu').forEach(function (m) { m.hidden = true; });
		}
	});
})();
