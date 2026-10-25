/**
 * ajaxnav.js — переходы и отправка форм без перезагрузки страницы.
 * Подменяет только шапку меню, хлебные крошки и .bx-content; после подмены запускает скрипты вкладки.
 * Отключить для ссылки или формы: атрибут data-noajax.
 */
(function () {
    'use strict';
    var token = 0;

    function content() { return document.querySelector('.bx-content'); }

    function swap(doc) {
        var cur = document.querySelectorAll('.bx-menu'), nw = doc.querySelectorAll('.bx-menu');
        cur.forEach(function (el, i) { if (nw[i]) { el.innerHTML = nw[i].innerHTML; } });
        var bc = document.querySelector('.breadcrumbs'), nbc = doc.querySelector('.breadcrumbs');
        if (bc && nbc) { bc.innerHTML = nbc.innerHTML; }
        var c = content();
        c.innerHTML = doc.querySelector('.bx-content').innerHTML;
        document.title = doc.title || document.title;
        // innerHTML не запускает скрипты — пересоздаём их
        c.querySelectorAll('script').forEach(function (old) {
            var s = document.createElement('script');
            s.text = old.textContent;
            old.parentNode.replaceChild(s, old);
        });
    }

    function go(url, opts, mode) {
        // mode: 'push' | 'replace' | 'none'; opts — параметры fetch
        if (window.BP_SESSID && opts && opts.body instanceof FormData && !opts.body.has('sessid')) { opts.body.append('sessid', window.BP_SESSID); }
        var my = ++token, c = content();
        if (!c) { location.href = url; return; }
        c.style.opacity = '.55';
        c.style.pointerEvents = 'none';
        var y = window.scrollY, prevPath = location.search;
        fetch(url, Object.assign({credentials: 'same-origin'}, opts || {})).then(function (r) {
            return r.text().then(function (t) { return {url: r.url, text: t}; });
        }).then(function (r) {
            if (my !== token) { return; }
            var doc = new DOMParser().parseFromString(r.text, 'text/html');
            if (!doc.querySelector('.bx-content')) { location.href = url; return; }
            swap(doc);
            if (mode === 'push' && r.url !== location.href) { history.pushState(null, '', r.url); }
            if (mode === 'replace') { history.replaceState(null, '', r.url); }
            var tab = function (s) { return new URLSearchParams(s).get('tab'); };
            window.scrollTo(0, tab(prevPath) === tab(new URL(r.url).search) ? y : 0);
        }).catch(function () {
            if (!opts || !opts.method || opts.method === 'GET') { location.href = url; } else { alert('Ошибка запроса, попробуйте ещё раз'); }
        }).then(function () {
            if (my === token) { c.style.opacity = ''; c.style.pointerEvents = ''; }
        });
    }

    function sameApp(u) { return u.origin === location.origin && u.pathname === location.pathname; }

    document.addEventListener('click', function (e) {
        if (e.defaultPrevented || e.button !== 0 || e.ctrlKey || e.metaKey || e.shiftKey || e.altKey) { return; }
        var a = e.target.closest ? e.target.closest('a[href]') : null;
        if (!a || a.target || a.hasAttribute('download') || a.hasAttribute('data-noajax')) { return; }
        var href = a.getAttribute('href');
        if (!href || href.charAt(0) === '#' || /^(javascript|mailto|tel):/i.test(href)) { return; }
        var u = new URL(a.href, location.href);
        if (!sameApp(u)) { return; }
        e.preventDefault();
        go(u.href, null, 'push');
    });

    document.addEventListener('submit', function (e) {
        if (e.defaultPrevented) { return; }
        var f = e.target;
        if (!f || f.tagName !== 'FORM' || f.target || f.hasAttribute('data-noajax')) { return; }
        var action = new URL(f.getAttribute('action') || location.href, location.href);
        if (!sameApp(action)) { return; }
        var fd = new FormData(f);
        if (e.submitter && e.submitter.name) { fd.append(e.submitter.name, e.submitter.value); }
        e.preventDefault();
        if ((f.method || 'get').toLowerCase() === 'post') {
            go(action.href, {method: 'POST', body: fd}, 'replace');
        } else {
            var p = new URLSearchParams();
            fd.forEach(function (v, k) { if (typeof v === 'string') { p.append(k, v); } });
            action.search = p.toString();
            go(action.href, null, 'push');
        }
    });

    // form.submit() из onchange не вызывает событие submit — направляем через requestSubmit
    var nativeSubmit = HTMLFormElement.prototype.submit;
    HTMLFormElement.prototype.submit = function () {
        if (this.requestSubmit && !this.hasAttribute('data-noajax')) { this.requestSubmit(); } else { nativeSubmit.call(this); }
    };

    window.addEventListener('popstate', function () { go(location.href, null, 'none'); });
    window.bpNav = function (url) { go(new URL(url, location.href).href, null, 'push'); };
    window.bpPost = function (url, formData) { go(new URL(url, location.href).href, {method: 'POST', body: formData}, 'replace'); };
})();
