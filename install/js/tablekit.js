/**
 * tablekit.js — поиск, фильтры и постраничный вывод для таблицы на стороне браузера.
 * Все строки лежат в таблице, лишние скрываются, поэтому галочки работают сразу по всем страницам.
 *
 * bpTable({table, search, filters:[{el, attr}], master, pager, info, selected, size, sizeSel})
 * Строки tbody tr: data-s — текст для поиска (в нижнем регистре), data-<attr> — значения для фильтров.
 */
(function () {
    'use strict';
    function $(s) { return s ? document.querySelector(s) : null; }

    window.bpTable = function (o) {
        var table = $(o.table);
        if (!table) { return; }
        var rows = Array.prototype.slice.call(table.querySelectorAll('tbody tr[data-s]'));
        var search = $(o.search), master = $(o.master), pager = $(o.pager), info = $(o.info), selBox = $(o.selected), sizeSel = $(o.sizeSel);
        var filters = (o.filters || []).map(function (f) { return {el: $(f.el), attr: f.attr}; });
        var size = o.size || 50, page = 1, match = [];

        function boxes(list) { return list.map(function (r) { return r.querySelector('input[type=checkbox]'); }).filter(Boolean); }

        function apply(resetPage) {
            var q = search ? search.value.trim().toLowerCase() : '';
            match = rows.filter(function (r) {
                if (q && r.dataset.s.indexOf(q) === -1) { return false; }
                return filters.every(function (f) { return !f.el || !f.el.value || r.getAttribute('data-' + f.attr) === f.el.value; });
            });
            if (resetPage) { page = 1; }
            render();
        }

        function render() {
            var pages = Math.max(1, Math.ceil(match.length / size));
            if (page > pages) { page = pages; }
            var from = (page - 1) * size, to = from + size, shown = {};
            match.slice(from, to).forEach(function (r) { shown[r.rowIndex] = true; });
            rows.forEach(function (r) { r.hidden = !shown[r.rowIndex]; });
            if (info) {
                info.textContent = match.length ? 'Показано ' + (from + 1) + '–' + Math.min(to, match.length) + ' из ' + match.length +
                    (match.length !== rows.length ? ' (всего ' + rows.length + ')' : '') : 'Ничего не найдено';
            }
            if (pager) { drawPager(pages); }
            updateSelected();
        }

        function drawPager(pages) {
            var html = '', b = function (p, label, cur, dis) {
                return '<button type="button" class="bx-btn bx-btn-sm' + (cur ? ' bx-btn-primary' : '') + '" data-p="' + p + '"' + (dis ? ' disabled' : '') + '>' + label + '</button>';
            };
            if (pages > 1) {
                html += b(page - 1, '←', false, page === 1);
                var start = Math.max(1, page - 2), end = Math.min(pages, page + 2);
                if (start > 1) { html += b(1, '1') + (start > 2 ? '<span class="tk-dots">…</span>' : ''); }
                for (var p = start; p <= end; p++) { html += b(p, p, p === page); }
                if (end < pages) { html += (end < pages - 1 ? '<span class="tk-dots">…</span>' : '') + b(pages, pages); }
                html += b(page + 1, '→', false, page === pages);
            }
            pager.innerHTML = html;
        }

        function updateSelected() {
            var all = boxes(rows), on = all.filter(function (c) { return c.checked; }).length;
            if (selBox) { selBox.textContent = 'Выбрано: ' + on + ' из ' + all.length; }
            if (master) {
                var m = boxes(match);
                master.checked = m.length > 0 && m.every(function (c) { return c.checked; });
            }
        }

        if (pager) {
            pager.addEventListener('click', function (e) {
                var b = e.target.closest('button[data-p]');
                if (!b) { return; }
                page = parseInt(b.getAttribute('data-p'), 10);
                render();
                table.scrollIntoView({block: 'nearest'});
            });
        }
        if (search) { search.addEventListener('input', function () { apply(true); }); }
        filters.forEach(function (f) { if (f.el) { f.el.addEventListener('change', function () { apply(true); }); } });
        if (sizeSel) { sizeSel.addEventListener('change', function () { size = parseInt(sizeSel.value, 10) || 50; apply(true); }); }
        if (master) {
            // «выбрать все» действует на все найденные строки, не только на текущую страницу
            master.addEventListener('change', function () { boxes(match).forEach(function (c) { c.checked = master.checked; }); updateSelected(); });
        }
        table.addEventListener('change', function (e) { if (e.target.type === 'checkbox' && e.target !== master) { updateSelected(); } });
        apply(true);
    };
})();
