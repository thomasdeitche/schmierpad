(function () {
	'use strict';

	var PRIORITIES = {
		1: 'Jetzt sofort',
		2: 'Extrem wichtig',
		3: 'Sehr wichtig',
		4: 'Wichtig',
		5: 'Bald',
		6: 'Demnächst',
		7: 'Bei Gelegenheit',
		8: 'Wenn mal Zeit ist'
	};
	var URGENT_MAX = 4;        // Prioritaet 1-4 (Rottoene) = obere Liste "Dringend"
	var DEFAULT_PRIO = 6;

	var MESSAGES = {
		no_mac: ['Arbeitsplatz nicht erkannt', 'Dieser Arbeitsplatz konnte im Netzwerk nicht eindeutig erkannt werden. Der Zugriff auf SchmierPAD funktioniert nur innerhalb des Unternehmens.'],
		off_subnet: ['Kein Zugriff', 'Der Zugriff auf SchmierPAD funktioniert nur innerhalb des Unternehmens.'],
		list_not_empty: ['Import nicht möglich', 'Auf diesem Arbeitsplatz existiert bereits eine Liste. Fremde Listen können leider nicht in eine bestehende Liste importiert werden – bitte die Einträge manuell anlegen.'],
		invalid_file: ['Import nicht möglich', 'Die gewählte Datei ist keine gültige SchmierPAD-Export-Datei.'],
		not_done: ['Löschen nicht möglich', 'Aufgaben können erst gelöscht werden, wenn sie als erledigt markiert sind.'],
		too_many_tasks: ['Zu viele Einträge', 'Es sind maximal 5000 Einträge pro Arbeitsplatz möglich.'],
		too_large: ['Datei zu groß', 'Die Datei ist zu groß für den Import.']
	};

	var $ = function (id) { return document.getElementById(id); };
	var state = { tasks: [], skew: 0, mac: null, blocked: false };
	var newPrio = DEFAULT_PRIO;

	/* ---------- API ---------- */

	function api(action, body) {
		var opts = { headers: {} };
		if (body !== undefined) {
			opts.method = 'POST';
			opts.headers['Content-Type'] = 'application/json';
			opts.headers['X-SchmierPAD'] = '1';
			opts.body = JSON.stringify(body);
		}
		return fetch('api.php?action=' + action, opts).then(function (res) {
			return res.text().then(function (text) {
				var data = null;
				try { data = JSON.parse(text); } catch (e) { /* kein JSON */ }
				if (!res.ok || (data && data.error)) {
					var err = new Error((data && data.error) || 'http_' + res.status);
					err.code = err.message;
					throw err;
				}
				return data;
			});
		});
	}

	/* ---------- Zeit ---------- */

	function nowSec() { return Math.floor(Date.now() / 1000) + state.skew; }

	function unit(n, one, many) { return n + ' ' + (n === 1 ? one : many); }

	// <1 Std.: Minuten | <24 Std.: Std. | <7 Tage: Tage+Std. | <1 Monat: Wochen+Tage+Std. | danach Monate+Wochen+Tage+Std.
	// (Monat = 30 Tage; Einheiten mit Wert 0 werden weggelassen)
	function formatAge(sec) {
		if (sec < 0) sec = 0;
		var min = Math.floor(sec / 60);
		var hours = Math.floor(sec / 3600);
		var days = Math.floor(sec / 86400);
		var h = hours % 24;
		var parts = [];

		if (hours < 1) return unit(Math.max(min, 1), 'Min.', 'Min.');
		if (days < 1) return unit(hours, 'Std.', 'Std.');

		if (days >= 30) {
			var months = Math.floor(days / 30);
			days = days % 30;
			parts.push(unit(months, 'Monat', 'Monate'));
		}
		if (parts.length || days >= 7) {
			var weeks = Math.floor(days / 7);
			days = days % 7;
			if (weeks) parts.push(unit(weeks, 'Woche', 'Wochen'));
		}
		if (days) parts.push(unit(days, 'Tag', 'Tage'));
		if (h) parts.push(h + ' Std.');
		return parts.join(' ');
	}

	function formatDate(ts) {
		return new Date(ts * 1000).toLocaleString('de-DE', {
			day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit'
		});
	}

	function ageText(t) {
		if (t.done) return 'erledigt nach ' + formatAge((t.done_ts || t.created_ts) - t.created_ts);
		return 'besteht seit ' + formatAge(nowSec() - t.created_ts);
	}

	/* ---------- Rendering ---------- */

	function el(tag, cls, text) {
		var e = document.createElement(tag);
		if (cls) e.className = cls;
		if (text !== undefined) e.textContent = text;
		return e;
	}

	// Mülleimer: Bootstrap Icons "trash" (MIT), als Inline-SVG — kein externer Request, Farbe per currentColor
	var TRASH_PATHS = [
		'M5.5 5.5A.5.5 0 0 1 6 6v6a.5.5 0 0 1-1 0V6a.5.5 0 0 1 .5-.5m2.5 0a.5.5 0 0 1 .5.5v6a.5.5 0 0 1-1 0V6a.5.5 0 0 1 .5-.5m3 .5a.5.5 0 0 0-1 0v6a.5.5 0 0 0 1 0z',
		'M14.5 3a1 1 0 0 1-1 1H13v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V4h-.5a1 1 0 0 1-1-1V2a1 1 0 0 1 1-1H6a1 1 0 0 1 1-1h2a1 1 0 0 1 1 1h3.5a1 1 0 0 1 1 1zM4.118 4 4 4.059V13a1 1 0 0 0 1 1h6a1 1 0 0 0 1-1V4.059L11.882 4zM2.5 3h11V2h-11z'
	];

	function trashIcon() {
		var ns = 'http://www.w3.org/2000/svg';
		var svg = document.createElementNS(ns, 'svg');
		svg.setAttribute('viewBox', '0 0 16 16');
		svg.setAttribute('fill', 'currentColor');
		svg.setAttribute('aria-hidden', 'true');
		svg.setAttribute('class', 'trash-icon');
		TRASH_PATHS.forEach(function (d) {
			var p = document.createElementNS(ns, 'path');
			p.setAttribute('d', d);
			svg.appendChild(p);
		});
		return svg;
	}

	function buildTask(t) {
		var li = el('li', 'task p' + t.priority + (t.done ? ' done' : ''));
		li.dataset.id = String(t.id);
		li.tabIndex = 0;
		li.setAttribute('role', 'checkbox');
		li.setAttribute('aria-checked', t.done ? 'true' : 'false');

		var body = el('div', 'body');
		body.appendChild(el('div', 'title', t.title));
		var meta = el('div', 'meta');
		meta.appendChild(el('span', 'created', 'erstellt ' + formatDate(t.created_ts)));
		var age = el('span', 'age', ageText(t));
		if (!t.done) age.dataset.since = String(t.created_ts);
		meta.appendChild(age);
		body.appendChild(meta);
		li.appendChild(body);

		var chip = el('button', 'chip p' + t.priority, t.priority + ' · ' + PRIORITIES[t.priority]);
		chip.type = 'button';
		chip.dataset.action = 'prio';
		chip.title = 'Priorität ändern';
		li.appendChild(chip);

		// Löschen nur bei erledigten Aufgaben (Server erzwingt das ebenfalls)
		if (t.done) {
			var del = el('button', 'del');
			del.type = 'button';
			del.dataset.action = 'delete';
			del.title = 'Löschen';
			del.setAttribute('aria-label', 'Löschen');
			del.appendChild(trashIcon());
			li.appendChild(del);
		}
		return li;
	}

	function byPrioThenAge(a, b) {
		return a.priority - b.priority || a.created_ts - b.created_ts || a.id - b.id;
	}

	function render() {
		closePopover();
		var open = state.tasks.filter(function (t) { return !t.done; });
		var done = state.tasks.filter(function (t) { return t.done; });

		var urgent = open.filter(function (t) { return t.priority <= URGENT_MAX; }).sort(byPrioThenAge);
		var rest = open.filter(function (t) { return t.priority > URGENT_MAX; }).sort(byPrioThenAge);
		done.sort(function (a, b) { return (b.done_ts || 0) - (a.done_ts || 0); });

		var urgentList = $('urgentList');
		var mainList = $('mainList');
		urgentList.textContent = '';
		mainList.textContent = '';
		urgent.forEach(function (t) { urgentList.appendChild(buildTask(t)); });
		rest.concat(done).forEach(function (t) { mainList.appendChild(buildTask(t)); });

		$('urgentSection').hidden = urgent.length === 0;
		$('mainHeading').textContent = urgent.length ? 'Weitere Aufgaben' : 'Aufgaben';
		$('emptyHint').hidden = state.tasks.length !== 0;
		$('mainList').hidden = rest.length + done.length === 0;
	}

	function tickAges() {
		var nodes = document.querySelectorAll('.age[data-since]');
		for (var i = 0; i < nodes.length; i++) {
			nodes[i].textContent = 'besteht seit ' + formatAge(nowSec() - Number(nodes[i].dataset.since));
		}
	}

	/* ---------- Prioritaets-Auswahl ---------- */

	function buildPicker(container, current, onPick) {
		container.textContent = '';
		for (var p = 1; p <= 8; p++) {
			(function (p) {
				var b = el('button', 'prio-dot p' + p, String(p));
				b.type = 'button';
				b.setAttribute('role', 'radio');
				b.setAttribute('aria-checked', p === current ? 'true' : 'false');
				b.title = p + ' · ' + PRIORITIES[p];
				b.addEventListener('click', function (ev) { ev.stopPropagation(); onPick(p); });
				container.appendChild(b);
			})(p);
		}
	}

	function setNewPrio(p) {
		newPrio = p;
		buildPicker($('newPrio'), p, setNewPrio);
	}

	var popoverFor = null;

	function closePopover() {
		$('prioPopover').hidden = true;
		popoverFor = null;
	}

	function openPopover(li, chip) {
		var id = Number(li.dataset.id);
		if (popoverFor === id) { closePopover(); return; }
		var t = findTask(id);
		if (!t) return;
		popoverFor = id;
		buildPicker($('popPicker'), t.priority, function (p) {
			closePopover();
			if (p !== t.priority) updateTask(id, { priority: p });
		});
		var pop = $('prioPopover');
		pop.hidden = false;
		var r = chip.getBoundingClientRect();
		var left = r.right + window.scrollX - pop.offsetWidth;
		pop.style.left = Math.max(8, left) + 'px';
		pop.style.top = (r.bottom + window.scrollY + 6) + 'px';
	}

	/* ---------- Daten ---------- */

	function findTask(id) {
		for (var i = 0; i < state.tasks.length; i++) if (state.tasks[i].id === id) return state.tasks[i];
		return null;
	}

	function showNotice(title, text, onClose) {
		$('modalTitle').textContent = title;
		$('modalText').textContent = text;
		$('modal').hidden = false;
		$('modalOk').focus();
		$('modalOk').onclick = function () {
			$('modal').hidden = true;
			if (onClose) onClose();
		};
	}

	function handleError(err) {
		var m = MESSAGES[err.code];
		if (err.code === 'no_mac' || err.code === 'off_subnet') {
			state.blocked = true;
			document.body.classList.add('blocked');
			$('addForm').querySelectorAll('input,button').forEach(function (n) { n.disabled = true; });
			$('exportBtn').disabled = true;
			$('importBtn').disabled = true;
		}
		if (m) showNotice(m[0], m[1]);
		else showNotice('Fehler', 'Die Aktion konnte nicht ausgeführt werden (' + (err.code || 'unbekannt') + '). Bitte erneut versuchen.');
	}

	function load() {
		return api('list').then(function (data) {
			state.tasks = data.tasks;
			state.mac = data.mac;
			state.skew = data.now - Math.floor(Date.now() / 1000);
			$('macLabel').textContent = data.mac;
			render();
		}).catch(handleError);
	}

	function addTask(title) {
		return api('add', { title: title, priority: newPrio }).then(function (data) {
			state.tasks.push(data.task);
			render();
		}).catch(handleError);
	}

	function updateTask(id, changes) {
		changes.id = id;
		return api('update', changes).then(function (data) {
			var t = findTask(id);
			if (t) Object.assign(t, data.task); else state.tasks.push(data.task);
			render();
		}).catch(function (err) {
			handleError(err);
			load();
		});
	}

	function deleteTask(id) {
		return api('delete', { id: id }).then(function () {
			state.tasks = state.tasks.filter(function (t) { return t.id !== id; });
			render();
		}).catch(function (err) {
			handleError(err);
			load();
		});
	}

	/* ---------- Export / Import ---------- */

	function exportList() {
		fetch('api.php?action=export').then(function (res) {
			if (!res.ok) throw new Error('http_' + res.status);
			return res.blob();
		}).then(function (blob) {
			var a = document.createElement('a');
			var d = new Date();
			var pad = function (n) { return (n < 10 ? '0' : '') + n; };
			a.href = URL.createObjectURL(blob);
			a.download = 'schmierpad-export-' + d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()) + '.json';
			document.body.appendChild(a);
			a.click();
			a.remove();
			setTimeout(function () { URL.revokeObjectURL(a.href); }, 1000);
		}).catch(function (err) {
			handleError({ code: err.message });
		});
	}

	function importFile(file) {
		var reader = new FileReader();
		reader.onload = function () {
			var parsed;
			try { parsed = JSON.parse(String(reader.result)); } catch (e) { handleError({ code: 'invalid_file' }); return; }
			api('import', parsed).then(function (data) {
				return load().then(function () {
					showNotice('Import abgeschlossen', data.imported + ' Einträge wurden übernommen.');
				});
			}).catch(handleError);
		};
		reader.onerror = function () { handleError({ code: 'invalid_file' }); };
		reader.readAsText(file);
	}

	/* ---------- Events ---------- */

	$('addForm').addEventListener('submit', function (ev) {
		ev.preventDefault();
		var input = $('newTitle');
		var title = input.value.trim();
		if (!title) { showNotice('Nichts eingetragen', 'Es wurde nichts eingetragen.', function () { input.focus(); }); return; }
		input.value = '';
		addTask(title);
	});

	function onListClick(ev) {
		var li = ev.target.closest('.task');
		if (!li) return;
		var id = Number(li.dataset.id);
		var action = ev.target.closest('[data-action]');
		ev.stopPropagation();
		if (action && action.dataset.action === 'delete') { deleteTask(id); return; }
		if (action && action.dataset.action === 'prio') { openPopover(li, action); return; }
		var t = findTask(id);
		if (t) updateTask(id, { done: !t.done });
	}

	function onListKey(ev) {
		if ((ev.key === 'Enter' || ev.key === ' ') && ev.target.classList && ev.target.classList.contains('task')) {
			ev.preventDefault();
			var t = findTask(Number(ev.target.dataset.id));
			if (t) updateTask(t.id, { done: !t.done });
		}
	}

	['urgentList', 'mainList'].forEach(function (id) {
		$(id).addEventListener('click', onListClick);
		$(id).addEventListener('keydown', onListKey);
	});

	document.addEventListener('click', function (ev) {
		if (!ev.target.closest('#prioPopover')) closePopover();
	});
	document.addEventListener('keydown', function (ev) {
		if (ev.key === 'Escape') { closePopover(); closeDialog(); }
	});

	/* ---------- Info-Dialoge (Export/Import, Prioritäten) ---------- */

	var openDialog = null;   // { modal, opener }

	function showDialog(modalId, opener) {
		var m = $(modalId);
		m.hidden = false;
		openDialog = { modal: m, opener: opener };
		// Fokus auf "Schließen", ohne den Text nach unten zu scrollen — der Dialog soll oben beginnen
		m.querySelector('[data-close-dialog]').focus({ preventScroll: true });
		m.querySelector('.info-box').scrollTop = 0;
	}
	function closeDialog() {
		if (!openDialog) return;
		openDialog.modal.hidden = true;
		openDialog.opener.focus();
		openDialog = null;
	}
	Array.prototype.forEach.call(document.querySelectorAll('[data-open-dialog]'), function (btn) {
		btn.addEventListener('click', function () { showDialog(btn.dataset.openDialog, btn); });
	});
	Array.prototype.forEach.call(document.querySelectorAll('.info-dialog'), function (m) {
		m.addEventListener('click', function (ev) {
			// "Schließen"-Button oder Klick auf den abgedunkelten Hintergrund
			if (ev.target === m || ev.target.closest('[data-close-dialog]')) closeDialog();
		});
	});

	$('exportBtn').addEventListener('click', exportList);
	$('importBtn').addEventListener('click', function () {
		// Schon vor der Dateiauswahl blocken: Import geht nur in eine leere Liste
		if (state.tasks.length > 0) { handleError({ code: 'list_not_empty' }); return; }
		$('importFile').click();
	});
	$('importFile').addEventListener('change', function () {
		var f = this.files && this.files[0];
		this.value = '';
		if (f) importFile(f);
	});

	/* ---------- Start ---------- */

	setNewPrio(DEFAULT_PRIO);
	load();
	setInterval(tickAges, 30000);
})();
