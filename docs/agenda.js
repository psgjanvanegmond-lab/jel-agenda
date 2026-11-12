/*
 * JEL Agenda — toont de agenda (agenda.json) op een webpagina.
 *
 * Insluiten:
 *   <div data-jel-agenda-embed data-maanden="2">Agenda laden…</div>
 *   <link rel="stylesheet" href="https://psgjanvanegmond-lab.github.io/jel-agenda/agenda.css">
 *   <script src="https://psgjanvanegmond-lab.github.io/jel-agenda/agenda.js" defer></script>
 *
 * Opties (data-attributen op de div):
 *   data-maanden="2"    aantal maanden tegelijk, met pijltjes (0 = alles onder elkaar)
 *   data-aantal="5"     alleen de eerstvolgende X activiteiten, zonder pijltjes
 *   data-verleden="ja"  ook afgelopen activiteiten tonen
 */
(function () {
	'use strict';

	var script = document.currentScript;
	var BASE = script && script.src ? script.src.replace(/[^\/]*$/, '') : '';

	var DOW = ['zo', 'ma', 'di', 'wo', 'do', 'vr', 'za'];
	var MON = ['jan', 'feb', 'mrt', 'apr', 'mei', 'jun', 'jul', 'aug', 'sep', 'okt', 'nov', 'dec'];
	var MONTHS = ['Januari', 'Februari', 'Maart', 'April', 'Mei', 'Juni', 'Juli', 'Augustus', 'September', 'Oktober', 'November', 'December'];

	var ICONS = {
		vakantie: '<circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/>',
		vrij: '<path d="M3 11l9-7 9 7"/><path d="M5 10v10h14V10"/><path d="M10 20v-5h4v5"/>',
		toets: '<path d="M14 3H6a1 1 0 0 0-1 1v16a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1V8z"/><path d="M14 3v5h5"/><path d="M8.5 14.5l2 2 4-4.5"/>',
		uitslag: '<path d="M2 9l10-5 10 5-10 5z"/><path d="M6 11v5c0 1.5 2.7 3 6 3s6-1.5 6-3v-5"/><path d="M22 9v6"/>',
		gesprek: '<path d="M4 5h11a1 1 0 0 1 1 1v7a1 1 0 0 1-1 1H9l-4 3v-3H4a1 1 0 0 1-1-1V6a1 1 0 0 1 1-1z"/><path d="M16 9h4a1 1 0 0 1 1 1v6a1 1 0 0 1-1 1h-1v3l-4-3h-3"/>',
		groep8: '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20c.6-3.5 3.2-5.5 6.5-5.5s5.9 2 6.5 5.5"/><path d="M19 8v6M16 11h6"/>',
		activiteit: '<path d="M12 3l2.6 5.6 6 .7-4.5 4.1 1.2 6L12 16.4 6.7 19.4l1.2-6L3.4 9.3l6-.7z"/>',
		rooster: '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
		klok: '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
		locatie: '<path d="M12 21s-7-6.2-7-11.5A7 7 0 0 1 19 9.5C19 14.8 12 21 12 21z"/><circle cx="12" cy="9.5" r="2.5"/>',
		links: '<path d="M19 12H5M11 18l-6-6 6-6"/>',
		rechts: '<path d="M5 12h14M13 6l6 6-6 6"/>',
		agenda: '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18M8 3v4M16 3v4"/>'
	};

	function icon(name) {
		return '<svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" focusable="false" aria-hidden="true">' + (ICONS[name] || ICONS.agenda) + '</svg>';
	}

	function esc(s) {
		return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
		});
	}

	function toDate(ymd) {
		var a = ymd.split('-');
		return new Date(+a[0], +a[1] - 1, +a[2]);
	}

	function todayYmd() {
		try {
			// Datum in Nederland, ongeacht waar de bezoeker is.
			return new Intl.DateTimeFormat('en-CA', { timeZone: 'Europe/Amsterdam', year: 'numeric', month: '2-digit', day: '2-digit' }).format(new Date());
		} catch (e) {
			var d = new Date();
			return d.getFullYear() + '-' + ('0' + (d.getMonth() + 1)).slice(-2) + '-' + ('0' + d.getDate()).slice(-2);
		}
	}

	function monthLabel(ym) {
		var a = ym.split('-');
		return MONTHS[+a[1] - 1] + ' ' + a[0];
	}

	function rangeLabel(s, e) {
		var a = DOW[s.getDay()] + ' ' + s.getDate();
		var b = DOW[e.getDay()] + ' ' + e.getDate() + ' ' + MON[e.getMonth()];
		if (s.getFullYear() === e.getFullYear() && s.getMonth() === e.getMonth()) return a + ' t/m ' + b;
		return a + ' ' + MON[s.getMonth()] + ' t/m ' + b;
	}

	function fullDate(d) {
		return DOW[d.getDay()] + ' ' + d.getDate() + ' ' + MON[d.getMonth()] + ' ' + d.getFullYear();
	}

	function renderItem(it) {
		var s = toDate(it.start);
		var e = toDate(it.end);
		var meta = [];
		if (it.end !== it.start) meta.push('<span class="jel-agenda__range">' + esc(rangeLabel(s, e)) + '</span>');
		if (it.start_time) {
			var t = it.end_time ? it.start_time + '–' + it.end_time + ' uur' : 'vanaf ' + it.start_time + ' uur';
			meta.push('<span class="jel-agenda__info">' + icon('klok') + esc(t) + '</span>');
		}
		if (it.location) meta.push('<span class="jel-agenda__info">' + icon('locatie') + esc(it.location) + '</span>');

		var type = ICONS[it.type] ? it.type : 'agenda';
		var h = '<li class="jel-agenda__item jel-agenda__item--' + type + '">';
		h += '<span class="jel-agenda__date" aria-hidden="true"><span class="jel-agenda__dow">' + DOW[s.getDay()] + '</span>';
		h += '<span class="jel-agenda__day">' + s.getDate() + '</span><span class="jel-agenda__mon">' + MON[s.getMonth()] + '</span></span>';
		h += '<span class="jel-agenda__icon" aria-hidden="true">' + icon(type) + '</span>';
		h += '<span class="jel-agenda__body"><span class="jel-agenda__sr">' + esc(fullDate(s)) + ': </span>';
		h += '<span class="jel-agenda__title">' + esc(it.title) + '</span>';
		if (meta.length) h += '<span class="jel-agenda__meta">' + meta.join('') + '</span>';
		if (it.description) h += '<span class="jel-agenda__desc">' + esc(it.description).replace(/\n/g, '<br>') + '</span>';
		return h + '</span></li>';
	}

	function render(el, items) {
		var perView = parseInt(el.getAttribute('data-maanden'), 10);
		if (isNaN(perView)) perView = 2;
		var limit = parseInt(el.getAttribute('data-aantal'), 10) || 0;
		var past = /^(ja|1|true)$/i.test(el.getAttribute('data-verleden') || '');
		if (limit) perView = 0;
		var today = todayYmd();

		var list = [];
		items.forEach(function (it) {
			if (!past && it.end < today) return;
			// Lopende activiteiten (bijv. een vakantie die al begonnen is) bij de huidige maand.
			var month = (!past && it.start < today ? today : it.start).slice(0, 7);
			list.push({ it: it, month: month });
		});
		list.sort(function (a, b) {
			var x = a.month + a.it.start + (a.it.start_time || ''), y = b.month + b.it.start + (b.it.start_time || '');
			return x < y ? -1 : x > y ? 1 : 0;
		});
		if (limit) list = list.slice(0, limit);

		if (!list.length) {
			el.innerHTML = '<div class="jel-agenda"><p class="jel-agenda__empty">Er staan op dit moment geen activiteiten in de agenda.</p></div>';
			return;
		}

		var h = '<div class="jel-agenda">';
		if (perView) {
			h += '<div class="jel-agenda__nav" hidden><button type="button" class="jel-agenda__arrow" data-prev aria-label="Vorige maand">' + icon('links') + '</button>';
			h += '<span class="jel-agenda__nav-label" aria-live="polite"></span>';
			h += '<button type="button" class="jel-agenda__arrow" data-next aria-label="Volgende maand">' + icon('rechts') + '</button></div>';
		}
		var current = null;
		list.forEach(function (row) {
			if (row.month !== current) {
				if (current !== null) h += '</ul></section>';
				current = row.month;
				var label = monthLabel(current);
				h += '<section class="jel-agenda__month" data-label="' + esc(label) + '"><h3 class="jel-agenda__month-title">' + esc(label) + '</h3><ul class="jel-agenda__list">';
			}
			h += renderItem(row.it);
		});
		h += '</ul></section></div>';
		el.innerHTML = h;

		if (perView) pager(el, perView);
	}

	function pager(el, perView) {
		var months = Array.prototype.slice.call(el.querySelectorAll('.jel-agenda__month'));
		var nav = el.querySelector('.jel-agenda__nav');
		if (!nav || months.length <= perView) return;
		var prev = nav.querySelector('[data-prev]');
		var next = nav.querySelector('[data-next]');
		var label = nav.querySelector('.jel-agenda__nav-label');
		var index = 0;
		var max = months.length - perView;

		function show(scroll) {
			months.forEach(function (m, i) { m.hidden = i < index || i >= index + perView; });
			var first = months[index].getAttribute('data-label');
			var last = months[Math.min(index + perView, months.length) - 1].getAttribute('data-label');
			if (first === last) label.textContent = first;
			else label.textContent = (first.slice(-4) === last.slice(-4) ? first.slice(0, -5) : first) + ' – ' + last;
			prev.disabled = index === 0;
			next.disabled = index >= max;
			if (scroll && nav.getBoundingClientRect().top < 0) nav.scrollIntoView({ behavior: 'smooth', block: 'start' });
		}
		prev.addEventListener('click', function () { if (index > 0) { index--; show(true); } });
		next.addEventListener('click', function () { if (index < max) { index++; show(true); } });
		nav.hidden = false;
		show(false);
	}

	function boot() {
		var els = document.querySelectorAll('[data-jel-agenda-embed]');
		if (!els.length) return;
		// Elke 10 minuten een verse versie ophalen (anders kan de browser een oude bewaren).
		var url = BASE + 'agenda.json?v=' + Math.floor(Date.now() / 600000);
		fetch(url, { credentials: 'omit' })
			.then(function (r) { if (!r.ok) throw new Error(r.status); return r.json(); })
			.then(function (data) {
				Array.prototype.forEach.call(els, function (el) { render(el, data.items || []); });
			})
			.catch(function () {
				Array.prototype.forEach.call(els, function (el) {
					el.innerHTML = '<div class="jel-agenda"><p class="jel-agenda__empty">De agenda kan op dit moment niet worden geladen. Probeer het later opnieuw.</p></div>';
				});
			});
	}

	if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
	else boot();
})();
