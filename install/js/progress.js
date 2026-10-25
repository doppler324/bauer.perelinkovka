/**
 * progress.js — прогресс-бар с этапами для долгих операций админки.
 * Работа делится на порции: браузер вызывает progress_api.php по очереди и показывает, что уже сделано.
 * Подключается к формам: #massForm (связи блока), #anchorsPlanForm и #anchorsApplyForm (анкоры в текстах).
 */
(function () {
    'use strict';

    var css = '.bpp-ov{position:fixed;inset:0;background:rgba(15,23,42,.45);display:flex;align-items:center;justify-content:center;z-index:9999}' +
        '.bpp{background:#fff;border-radius:14px;box-shadow:0 20px 50px rgba(0,0,0,.25);width:min(520px,92vw);padding:22px 24px;font-family:inherit}' +
        '.bpp h3{margin:0 0 14px;font-size:17px}' +
        '.bpp-steps{list-style:none;margin:0 0 14px;padding:0;display:flex;flex-direction:column;gap:6px;font-size:14px;color:#9aa0ad}' +
        '.bpp-steps li.is-now{color:#1f2430;font-weight:600}.bpp-steps li.is-done{color:#16a34a}' +
        '.bpp-bar{height:12px;background:#eceef5;border-radius:999px;overflow:hidden}' +
        '.bpp-fill{height:100%;width:0;background:linear-gradient(90deg,#4f46e5,#7c74ee);border-radius:999px;transition:width .25s}' +
        '.bpp-info{display:flex;justify-content:space-between;gap:12px;margin-top:8px;font-size:13px;color:#6b7280}' +
        '.bpp-err{margin-top:12px;color:#b91c1c;font-size:13px}' +
        '.bpp-foot{margin-top:16px;text-align:right}';
    var st = document.createElement('style');
    st.textContent = css;
    document.head.appendChild(st);

    function esc(s) { var d = document.createElement('div'); d.textContent = s; return d.innerHTML; }

    function Panel(title, steps) {
        this.cancelled = false;
        var ov = document.createElement('div');
        ov.className = 'bpp-ov';
        ov.innerHTML = '<div class="bpp"><h3>' + esc(title) + '</h3><ul class="bpp-steps"></ul>' +
            '<div class="bpp-bar"><div class="bpp-fill"></div></div>' +
            '<div class="bpp-info"><span class="bpp-text">Подготовка…</span><span class="bpp-time"></span></div>' +
            '<div class="bpp-err" hidden></div><div class="bpp-foot"><button type="button" class="bx-btn bx-btn-sm">Отмена</button></div></div>';
        document.body.appendChild(ov);
        this.ov = ov;
        this.steps = steps;
        this.list = ov.querySelector('.bpp-steps');
        this.fill = ov.querySelector('.bpp-fill');
        this.text = ov.querySelector('.bpp-text');
        this.time = ov.querySelector('.bpp-time');
        this.err = ov.querySelector('.bpp-err');
        this.btn = ov.querySelector('button');
        this.t0 = Date.now();
        var self = this;
        this.btn.onclick = function () { self.cancelled = true; self.btn.disabled = true; self.btn.textContent = 'Останавливаю…'; };
        this.timer = setInterval(function () { self.time.textContent = Math.round((Date.now() - self.t0) / 1000) + ' с'; }, 500);
        this.stage(0);
    }
    Panel.prototype.stage = function (i) {
        this.list.innerHTML = this.steps.map(function (s, k) {
            return '<li class="' + (k < i ? 'is-done' : k === i ? 'is-now' : '') + '">' + (k < i ? '✓ ' : k === i ? '● ' : '○ ') + esc(s) + '</li>';
        }).join('');
    };
    Panel.prototype.set = function (done, total, text) {
        var pct = total > 0 ? Math.min(100, Math.round(done * 100 / total)) : 0;
        this.fill.style.width = pct + '%';
        this.text.textContent = text + (total > 0 ? ' · ' + pct + '%' : '');
    };
    Panel.prototype.fail = function (msg) {
        clearInterval(this.timer);
        this.err.hidden = false;
        this.err.textContent = 'Ошибка: ' + msg;
        this.btn.disabled = false;
        this.btn.textContent = 'Закрыть';
        var self = this;
        this.btn.onclick = function () { self.close(); };
    };
    Panel.prototype.close = function () { clearInterval(this.timer); this.ov.remove(); };

    function api(params) {
        var body = new URLSearchParams();
        if (window.BP_SESSID) { body.append('sessid', window.BP_SESSID); }
        Object.keys(params).forEach(function (k) {
            var v = params[k];
            if (Array.isArray(v)) { v.forEach(function (x) { body.append(k + '[]', x); }); } else if (v !== undefined && v !== null) { body.append(k, v); }
        });
        return fetch(window.BP_API || 'progress_api.php', {method: 'POST', body: body, credentials: 'same-origin'}).then(function (r) {
            return r.text().then(function (t) {
                try { return JSON.parse(t); } catch (e) {
                    throw new Error('Сервер вернул не JSON (HTTP ' + r.status + '): ' + t.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 300));
                }
            });
        }).then(function (j) { if (!j.ok) { throw new Error(j.error || 'ошибка'); } return j; });
    }

    function formParams(form) {
        var o = {};
        new FormData(form).forEach(function (v, k) { if (typeof v === 'string') { o[k] = v; } });
        return o;
    }

    // ---------- Связи блока ----------
    function runLinks(form, action) {
        var code = form.elements.mass_block.value;
        if (!code) { return; }
        var save = action === 'mass_all';
        var p = new Panel(save ? 'Подбор и сохранение связей' : 'Подбор связей', ['Подбор по правилам блока', save ? 'Готово' : 'Показ результата']);
        var all = [], created = 0, skipped = 0, errors = 0, inbound = null, offset = 0, total = 0;
        (function step() {
            if (p.cancelled) { return finish(true); }
            api({step: 'links', block: code, mode: save ? 'save' : 'preview', offset: offset, limit: 5, inbound: inbound ? JSON.stringify(inbound) : ''}).then(function (r) {
                total = r.total; offset = r.next; inbound = r.inbound;
                created += r.created; skipped += r.skipped; errors += r.errors;
                all = all.concat(r.links || []);
                p.set(offset, total, 'Обработано страниц: ' + offset + ' из ' + total + ' · ' + (save ? 'создано связей: ' + created : 'найдено новых: ' + all.length));
                if (offset >= total || r.next === 0) { finish(false); } else { step(); }
            }).catch(function (e) { p.fail(e.message); });
        })();
        function finish(stopped) {
            p.stage(1);
            if (save) {
                p.close();
                bpNav('?tab=list&mass_block=' + encodeURIComponent(code) + '&mass_done=' + [created, skipped, errors, stopped ? 1 : 0].join('_'));
                return;
            }
            if (stopped) { p.close(); return; }
            var fd = new FormData();
            fd.append('action', 'mass_show'); fd.append('mass_block', code);
            fd.append('links_json', JSON.stringify(all)); fd.append('skipped', skipped);
            p.close();
            bpPost('?tab=list', fd);
        }
    }

    // ---------- Анкоры в текстах ----------
    function runAnchorsPlan(form) {
        var base = formParams(form), p = new Panel('Подбор ссылок в текстах', ['Подготовка', 'Подбор мест для ссылок', 'Показ результата']);
        var params = {
            m_iblock: base.m_iblock, pool: base.pool, scope: base.scope, one_per_block: base.one_per_block,
            scope_id: base.scope === 'section' ? base.scope_section : base.scope === 'article' ? base.scope_article : 0,
            with_children: base.with_children ? 1 : ''
        };
        api(Object.assign({step: 'anchors_init'}, params)).then(function (init) {
            var ids = init.ids, inbound = init.inbound, plan = {}, n = 0, found = 0, i = 0;
            if (!ids.length) { p.close(); alert('В выбранной области нет статей.'); return; }
            p.stage(1);
            (function step() {
                if (p.cancelled) { p.close(); return; }
                var chunk = ids.slice(i, i + 25);
                api({step: 'anchors_plan', m_iblock: base.m_iblock, pool: base.pool, one_per_block: base.one_per_block, ids: chunk, inbound: JSON.stringify(inbound),
                    max_links: base.max_links, max_inbound: base.max_inbound, low_threshold: base.low_threshold,
                    only_low: base.only_low ? 1 : '', balance: base.balance ? 1 : ''}).then(function (r) {
                    Object.keys(r.plan).forEach(function (k) { plan[k] = r.plan[k]; found += r.plan[k].length; });
                    inbound = r.inbound; i += (r.processed || chunk.length); n = i;
                    p.set(n, ids.length, 'Обработано статей: ' + n + ' из ' + ids.length + ' · найдено ссылок: ' + found);
                    if (i >= ids.length) {
                        p.stage(2); p.close();
                        var fd = new FormData(form);
                        fd.set('mass_action', 'plan_show');
                        fd.set('plan_json', JSON.stringify(plan));
                        fd.set('scope_total', ids.length);
                        bpPost(form.getAttribute('action') || location.href, fd);
                    } else { step(); }
                }).catch(function (e) { p.fail(e.message); });
            })();
        }).catch(function (e) { p.fail(e.message); });
    }

    function runAnchorsApply(form) {
        var pairs = Array.prototype.map.call(form.querySelectorAll('input[name="pairs[]"]:checked'), function (c) { return c.value; });
        if (!pairs.length) { alert('Ничего не выбрано.'); return; }
        var base = formParams(form), m = {}, order = [];
        pairs.forEach(function (v) { var a = v.split('|')[0]; if (!m[a]) { m[a] = []; order.push(a); } m[a].push(v); });
        var p = new Panel('Вставка ссылок в тексты', ['Вставка ссылок', 'Готово']), i = 0, applied = 0, touched = 0;
        (function step() {
            if (p.cancelled) { return finish(); }
            var chunk = order.slice(i, i + 3), list = [];
            chunk.forEach(function (a) { list = list.concat(m[a]); });
            api({step: 'anchors_apply', m_iblock: base.m_iblock, pool: base.pool, one_per_block: base.one_per_block, pairs: list}).then(function (r) {
                applied += r.applied; touched += r.articles; i += chunk.length;
                p.set(i, order.length, 'Обработано статей: ' + i + ' из ' + order.length + ' · вставлено ссылок: ' + applied);
                if (i >= order.length) { finish(); } else { step(); }
            }).catch(function (e) { p.fail(e.message); });
        })();
        function finish() {
            p.stage(1); p.close();
            bpNav('?tab=anchors_mass&m_iblock=' + encodeURIComponent(base.m_iblock) + '&done=' + applied + '_' + touched);
        }
    }

    // ---------- Эмбеддинги целей ----------
    function runEmbIndex() {
        var p = new Panel('Расчёт эмбеддингов целей', ['Подготовка', 'Расчёт векторов', 'Готово']);
        api({step: 'emb_init'}).then(function (init) {
            var keys = init.keys, i = 0, embedded = 0, tokens = 0;
            if (!keys.length) { p.close(); alert('Нет целей: проверьте настройки анкоров.'); return; }
            if (!confirm('Целей: ' + keys.length + '. Оценка: около ' + init.tokens + ' токенов, ' + init.cost + ' $ для text-embedding-3-small (пересчитываются только новые и изменённые). Продолжить?')) { p.close(); return; }
            p.stage(1);
            (function step() {
                if (p.cancelled) { return finish(); }
                api({step: 'emb_index', keys: keys.slice(i, i + 48)}).then(function (r) {
                    embedded += r.embedded; tokens += r.tokens; i += 48;
                    p.set(Math.min(i, keys.length), keys.length, 'Обработано целей: ' + Math.min(i, keys.length) + ' из ' + keys.length + ' · рассчитано новых: ' + embedded);
                    if (i >= keys.length) { finish(); } else { step(); }
                }).catch(function (e) { p.fail(e.message); });
            })();
            function finish() { p.stage(2); p.close(); bpNav('?tab=anchor_settings&emb_done=' + embedded + '_' + keys.length); }
        }).catch(function (e) { p.fail(e.message); });
    }

    // Перехватываем отправку форм раньше общего ajaxnav
    document.addEventListener('submit', function (e) {
        var f = e.target;
        if (!f || !f.id) { return; }
        var act = e.submitter ? e.submitter.value : '';
        if (f.id === 'massForm' && (act === 'mass_preview' || act === 'mass_all')) {
            e.preventDefault(); e.stopImmediatePropagation(); runLinks(f, act);
        } else if (f.id === 'anchorsPlanForm') {
            e.preventDefault(); e.stopImmediatePropagation(); runAnchorsPlan(f);
        } else if (f.id === 'embIndexForm') {
            e.preventDefault(); e.stopImmediatePropagation(); runEmbIndex();
        } else if (f.id === 'anchorsApplyForm') {
            e.preventDefault(); e.stopImmediatePropagation(); runAnchorsApply(f);
        }
    }, true);
})();
