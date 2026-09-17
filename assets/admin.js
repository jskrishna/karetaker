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
			if (key === 'advanced_mode') {
				reload(value ? L.advOn : L.advOff);
			} else if (el.dataset.reload) {
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

	/* ---------- drawer (Advanced) ---------- */
	var drawer = $('#drawer');
	var chip = function (cls, text) { return '<span class="chip ' + cls + '">' + esc(text) + '</span>'; };
	var sevText = { act: ['bad', L.sevAct], review: ['warn', L.sevReview], log: ['mute', L.sevLog] };
	var statusText = { open: ['bad', L.stOpen], ack: ['info', L.stAck], resolved: ['ok', L.stResolved], expected: ['amber', L.stExpected] };
	function dtab(n) {
		$$('.dtabs button', drawer).forEach(function (b) { b.setAttribute('aria-selected', b.dataset.dt === n ? 'true' : 'false'); });
		$$('[data-dp]', drawer).forEach(function (p) { p.hidden = p.dataset.dp !== n; });
	}
	function openDrawer(item) {
		cur = item;
		lastFocus = document.activeElement;
		var sev = sevText[item.sev] || sevText.log;
		var meta = chip(sev[0], sev[1]);
		if (statusText[item.status]) {
			meta += ' ' + chip(statusText[item.status][0], statusText[item.status][1]);
		}
		$('#dMeta').innerHTML = meta + ' <span class="muted">#' + esc(item.id) + ' · ' + esc(item.area) + ' · ' + esc(item.opened) + '</span>';
		$('#dTitle').textContent = item.title;
		$('#dWhat').textContent = item.what;
		$('#dWhy').textContent = item.why || '';
		$('#dSteps').innerHTML = (item.steps || []).map(function (s) { return '<li>' + esc(s) + '</li>'; }).join('');
		var link = $('#dLink');
		link.hidden = !item.link;
		if (item.link) {
			link.firstElementChild.href = item.link;
			link.firstElementChild.textContent = item.linkText || item.link;
		}
		$('#dDl').innerHTML = (item.details || []).map(function (d) { return '<dt>' + esc(d[0]) + '</dt><dd>' + esc(d[1]) + '</dd>'; }).join('');
		$('#dTl').innerHTML = (item.timeline || []).length ? item.timeline.map(function (t) {
			return '<li class="' + (t.bad ? 'bad' : '') + '"><time>' + esc(t.time) + '</time>' + esc(t.text) + '</li>';
		}).join('') : '<li><time>—</time>—</li>';
		$('#dRaw').textContent = item.raw || '{}';
		var issue = item.kind === 'issue';
		$('.dtabs [data-dt="timeline"]', drawer).hidden = !issue;
		$('#dOwner').hidden = !issue || !item.canAct;
		$('#dOwner').value = String(item.owner || 0);
		$('#dExpect').hidden = !item.canMark || item.status === 'expected';
		$('#dAck').hidden = !issue || !item.canAct || item.status !== 'open';
		$('#dResolve').hidden = !issue || !item.canAct || (item.status !== 'open' && item.status !== 'ack');
		$('#dReopen').hidden = !issue || !item.canAct || item.status === 'open';
		dtab('guide');
		drawer.classList.add('open');
		scrim.classList.add('open');
		$('#dClose').focus();
	}
	function closeDrawer() {
		if (!drawer || !drawer.classList.contains('open')) {
			return;
		}
		drawer.classList.remove('open');
		if (!panel.classList.contains('open')) {
			scrim.classList.remove('open');
		}
		if (lastFocus && document.contains(lastFocus)) {
			lastFocus.focus();
		}
	}
	if (drawer) {
		$$('.dtabs button', drawer).forEach(function (b) { b.addEventListener('click', function () { dtab(b.dataset.dt); }); });
		$('#dClose').addEventListener('click', closeDrawer);
		var issueAction = function (action, msg, btn) {
			busy(btn, true);
			api('issue', { id: cur.id, action: action }).then(function () { reload(msg); }).catch(function () { busy(btn, false); });
		};
		$('#dExpect').addEventListener('click', function (e) {
			if (cur.kind === 'event') {
				busy(e.currentTarget, true);
				api('bulk-expected', { ids: [cur.id] }).then(function () { reload(L.expected); }).catch(function () { busy(e.currentTarget, false); });
			} else {
				issueAction('expected', L.expected, e.currentTarget);
			}
		});
		$('#dAck').addEventListener('click', function (e) { issueAction('ack', L.acked, e.currentTarget); });
		$('#dResolve').addEventListener('click', function (e) { issueAction('resolve', L.resolved, e.currentTarget); });
		$('#dReopen').addEventListener('click', function (e) { issueAction('reopen', L.reopened, e.currentTarget); });
		$('#dOwner').addEventListener('change', function (e) {
			api('issue', { id: cur.id, action: 'assign', owner: +e.target.value }).then(function () { reload(L.ownerSet); });
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
		if (drawer && el.closest('.kt-adv')) {
			openDrawer(item);
		} else {
			openIssueSheet(item);
		}
	});

	/* ---------- incidents ---------- */
	var modal = $('#modal'), curCase = null, caseDirty = false;
	function renderCase() {
		var steps = $$('#mSteps li', modal), done = 0;
		steps.forEach(function (li) {
			var isDone = curCase.done.indexOf(li.dataset.key) !== -1, n = li.querySelector('.n');
			li.classList.toggle('done', isDone);
			n.textContent = isDone ? '✓' : n.dataset.n;
			li.querySelector('[data-step]').checked = isDone;
			li.querySelector('[data-step]').disabled = curCase.status !== 'open';
			done += isDone ? 1 : 0;
		});
		$('#mBar').style.width = (done / steps.length * 100) + '%';
		$('#mCount').textContent = fmt(L.stepsDone, done, steps.length);
		$('#mCloseCase').disabled = done < steps.length;
		$('#mCloseCase').hidden = curCase.status !== 'open';
		$('#mReopen').hidden = curCase.status === 'open';
	}
	function openCase(c) {
		curCase = { id: c.id, title: c.title, status: c.status, done: c.done.slice(), export: c.export };
		$('#mTitle').textContent = c.id + ' · ' + c.title;
		$('#mChip').innerHTML = c.status === 'open' ? chip('bad', statusText.open[1]) : chip('ok', L.stClosed);
		$('#mExport').href = c.export;
		renderCase();
		lastFocus = document.activeElement;
		modal.classList.add('open');
		$('#mClose').focus();
	}
	function closeModal() {
		if (!modal || !modal.classList.contains('open')) {
			return;
		}
		modal.classList.remove('open');
		if (caseDirty) {
			reload();
		} else if (lastFocus && document.contains(lastFocus)) {
			lastFocus.focus();
		}
	}
	if (modal) {
		$('#mClose').addEventListener('click', closeModal);
		modal.addEventListener('click', function (e) { if (e.target === modal) { closeModal(); } });
		$('#mSteps').addEventListener('change', function (e) {
			var box = e.target.closest('[data-step]');
			if (!box) {
				return;
			}
			var key = box.dataset.step, done = box.checked;
			api('case', { action: 'step', id: curCase.id, value: { key: key, done: done ? 1 : 0 } }).then(function (res) {
				curCase.done = Object.keys(res['case'].done || {});
				caseDirty = true;
				renderCase();
			}).catch(function () { box.checked = !done; });
		});
		$('#mCloseCase').addEventListener('click', function (e) {
			busy(e.currentTarget, true);
			api('case', { action: 'close', id: curCase.id }).then(function () { reload(L.caseClosed); }).catch(function () { busy(e.currentTarget, false); });
		});
		$('#mReopen').addEventListener('click', function () {
			api('case', { action: 'reopen', id: curCase.id }).then(function () { reload(); });
		});
		try {
			var pending = window.sessionStorage.getItem('ktOpenCase');
			if (pending && data.cases && data.cases[pending]) {
				window.sessionStorage.removeItem('ktOpenCase');
				openCase(data.cases[pending]);
			}
		} catch (e) {}
	}
	on('[data-kt-case]', 'click', function (b) {
		if (data.cases && data.cases[b.dataset.ktCase]) {
			openCase(data.cases[b.dataset.ktCase]);
		}
	});
	on('[data-kt-case-open]', 'click', function (b) {
		var ids = b.dataset.ktCaseOpen ? b.dataset.ktCaseOpen.split(',').map(Number) : [];
		busy(b, true);
		api('case', { action: 'open', issues: ids }).then(function (res) {
			try { window.sessionStorage.setItem('ktOpenCase', res['case'].id); } catch (e) {}
			reload(L.caseOpened);
		}).catch(function () { busy(b, false); });
	});

	/* ---------- sessions, expected, routing, windows ---------- */
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
	on('[data-kt-issue]', 'click', function (b) {
		busy(b, true);
		api('issue', { id: +b.dataset.id, action: b.dataset.ktIssue }).then(function () { reload(L.expected); }).catch(function () { busy(b, false); });
	});
	on('[data-kt-unmark]', 'click', function (b) {
		busy(b, true);
		api('unmark', { id: +b.dataset.ktUnmark }).then(function () { reload(L.saved); }).catch(function () { busy(b, false); });
	});
	on('[data-kt-route]', 'change', function (el) {
		api('setting', { key: 'route', channel: el.dataset.ktRoute, kind: el.dataset.kind, value: el.checked })
			.then(function () { toast(L.saved); })
			.catch(function () { el.checked = !el.checked; });
	});
	on('[data-kt-test]', 'click', function (b) {
		busy(b, true);
		api('test-alert').then(function (res) {
			busy(b, false);
			toast(res.sent && res.sent.length ? fmt(L.testSent, res.sent.join(', ')) : L.testNone, !(res.sent && res.sent.length));
		}).catch(function () { busy(b, false); });
	});
	on('[data-kt-window-add]', 'click', function (b) {
		busy(b, true);
		api('window', { action: 'save', index: -1, name: $('#wName').value, day: +$('#wDay').value, start: $('#wStart').value, end: $('#wEnd').value })
			.then(function () { reload(L.saved); })
			.catch(function () { busy(b, false); });
	});
	on('[data-kt-window-delete]', 'click', function (b) {
		busy(b, true);
		api('window', { action: 'delete', index: +b.dataset.ktWindowDelete }).then(function () { reload(L.saved); }).catch(function () { busy(b, false); });
	});

	/* ---------- activity log selection and saved views ---------- */
	var logBody = $('#logBody');
	if (logBody) {
		var sel = {};
		var syncBulk = function () {
			var ids = Object.keys(sel);
			$('#bulk').hidden = !ids.length;
			$('#selCount').textContent = ids.length;
			$$('[data-sel]', logBody).forEach(function (c) { c.closest('tr').classList.toggle('sel', !!sel[c.dataset.sel]); });
			var exp = $('#bulkExport');
			exp.href = exp.href.replace(/&ids=[^&]*/, '') + '&ids=' + ids.join(',');
		};
		logBody.addEventListener('change', function (e) {
			var c = e.target.closest('[data-sel]');
			if (c) {
				if (c.checked) { sel[c.dataset.sel] = true; } else { delete sel[c.dataset.sel]; }
				syncBulk();
			}
		});
		$('#selAll').addEventListener('change', function (e) {
			$$('[data-sel]', logBody).forEach(function (c) {
				c.checked = e.target.checked;
				if (c.checked) { sel[c.dataset.sel] = true; } else { delete sel[c.dataset.sel]; }
			});
			syncBulk();
		});
		$('#clearSel').addEventListener('click', function () {
			sel = {};
			$('#selAll').checked = false;
			$$('[data-sel]', logBody).forEach(function (c) { c.checked = false; });
			syncBulk();
		});
		if ($('#bulkExpect')) {
			$('#bulkExpect').addEventListener('click', function (e) {
				busy(e.currentTarget, true);
				api('bulk-expected', { ids: Object.keys(sel).map(Number) }).then(function (res) {
					reload(fmt(L.marked, res.marked));
				}).catch(function () { busy(e.currentTarget, false); });
			});
		}
	}
	on('[data-kt-view-save]', 'click', function (b) {
		var name = window.prompt(L.viewName);
		if (name) {
			api('saved-view', { name: name, url: b.dataset.ktViewSave }).then(function () { reload(L.saved); });
		}
	});
	on('[data-kt-view-delete]', 'click', function (b) {
		api('saved-view', { name: b.dataset.ktViewDelete, action: 'delete' }).then(function () { reload(); });
	});

	/* ---------- access: roles and tokens ---------- */
	on('[data-kt-roles]', 'click', function (b) {
		var matrix = {};
		$$('#roleMatrix [data-role]').forEach(function (c) {
			matrix[c.dataset.role] = matrix[c.dataset.role] || {};
			matrix[c.dataset.role][c.dataset.cap] = c.checked;
		});
		busy(b, true);
		api('roles', { matrix: matrix }).then(function () { reload(L.saved); }).catch(function () { busy(b, false); });
	});
	var showToken = function (value) {
		if (!value) {
			return;
		}
		$('#tokenValue').textContent = value;
		$('#tokenOnce').hidden = false;
		$('#tokenOnce').scrollIntoView({ block: 'center' });
	};
	on('[data-kt-token]', 'click', function (b) {
		var action = b.dataset.ktToken;
		if (action === 'revoke') {
			if (!window.confirm(L.confirmRevoke)) {
				return;
			}
			busy(b, true);
			api('token', { action: 'revoke', id: b.dataset.id, name: b.dataset.name }).then(function () { reload(L.tokenRevoked); }).catch(function () { busy(b, false); });
			return;
		}
		var body = { action: action, id: b.dataset.id || '' };
		if (action === 'create') {
			body.name = $('#tName').value;
			body.scopes = $$('[name="tScope"]:checked').map(function (c) { return c.value; });
			body.ips = $('#tIps').value;
			body.days = +$('#tDays').value;
		}
		busy(b, true);
		api('token', body).then(function (res) {
			busy(b, false);
			if (action === 'create') {
				$('#tokenForm').hidden = true;
			}
			showToken(res.token);
		}).catch(function () { busy(b, false); });
	});
	on('[data-kt-copy]', 'click', function (b) {
		var text = document.getElementById(b.dataset.ktCopy).textContent;
		if (navigator.clipboard) {
			navigator.clipboard.writeText(text).then(function () { toast(L.copied); });
		}
	});

	/* ---------- reports ---------- */
	var period = $('#rperiod');
	if (period) {
		var syncPeriod = function () { $('#rcustom').hidden = period.value !== 'custom'; };
		period.addEventListener('change', function () {
			syncPeriod();
			if (period.value !== 'custom') {
				window.location.href = period.dataset.ktPeriod + '&period=' + encodeURIComponent(period.value);
			}
		});
		syncPeriod();
	}
	on('[data-kt-logo]', 'click', function () {
		if (!window.wp || !window.wp.media) {
			return;
		}
		var frame = window.wp.media({ title: L.chooseLogo, library: { type: 'image' }, multiple: false });
		frame.on('select', function () {
			var file = frame.state().get('selection').first().toJSON();
			$('#rlogo').value = file.id;
			$('#rlogoName').textContent = file.title || file.filename;
		});
		frame.open();
	});
	on('[data-kt-logo-clear]', 'click', function (b) {
		$('#rlogo').value = '0';
		$('#rlogoName').textContent = '';
		b.hidden = true;
	});

	/* ---------- keyboard ---------- */
	document.addEventListener('keydown', function (e) {
		if (e.key === 'Escape') {
			closeModal();
			closeDrawer();
			if (panel) {
				closeSheet();
			}
			$$('.menu').forEach(function (m) { m.hidden = true; });
		}
	});
})();
