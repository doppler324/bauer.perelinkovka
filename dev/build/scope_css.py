#!/usr/bin/env python3
"""Склеивает base.css + modern.css модуля и ограничивает все правила областью #bp-app,
чтобы стили не ломали интерфейс Битрикса. Результат: install/css/admin.css."""
import re, sys, pathlib

root = pathlib.Path(__file__).resolve().parent.parent.parent / 'install' / 'css'
SCOPE = '#bp-app'

def scope_sel(sel):
    sel = sel.strip()
    if not sel:
        return ''
    if sel in ('html', 'body', ':root'):
        return SCOPE
    if sel.startswith(':root'):
        return SCOPE + sel[5:]
    sel = re.sub(r'^(html|body)\s+', '', sel)
    return SCOPE + ' ' + sel

def process(css):
    css = re.sub(r'/\*.*?\*/', '', css, flags=re.S)
    out, i, n = [], 0, len(css)
    while i < n:
        j = css.find('{', i)
        if j < 0:
            break
        head = css[i:j].strip()
        # найти парную }
        depth, k = 1, j + 1
        while k < n and depth:
            depth += {'{': 1, '}': -1}.get(css[k], 0)
            k += 1
        body = css[j + 1:k - 1]
        if head.startswith('@media') or head.startswith('@supports'):
            out.append(head + '{' + process(body) + '}')
        elif head.startswith('@'):
            out.append(head + '{' + body + '}')   # keyframes, font-face — без изменений
        else:
            sels = ','.join(scope_sel(s) for s in head.split(','))
            out.append(sels + '{' + body.strip() + '}')
        i = k
    return '\n'.join(out)

src = ''.join((root / f).read_text(encoding='utf-8') + '\n' for f in ('base.css', 'modern.css'))
res = process(src)
# Родные элементы Битрикса (список, фильтр) лежат в .bp-native — наш сброс и размеры их не трогают
res = res.replace('#bp-app *{', '#bp-app :where(*:not(.bp-native):not(.bp-native *)){', 1)
# Битрикс задаёт свои шрифты и отступы: возвращаем нашу рамку
res = '#bp-app{font-family:Inter,-apple-system,"Segoe UI",Roboto,Arial,sans-serif;font-size:14px;color:#1f2937;margin:-8px -10px;}\n' + res
# Битрикс задаёт свои высоты и line-height для полей — возвращаем читаемые размеры
res += '''
#bp-app select:not(.bp-native *), #bp-app input[type=text]:not(.bp-native *), #bp-app input[type=number]:not(.bp-native *), #bp-app input[type=search]:not(.bp-native *), #bp-app input:not([type]):not(.bp-native *), #bp-app textarea:not(.bp-native *) {
  box-sizing: border-box !important; height: auto !important; min-height: 36px !important; line-height: 1.4 !important;
  padding: 7px 12px !important; font-size: 14px !important; font-family: inherit !important; color: #1f2937 !important; margin: 0;
}
#bp-app select:not(.bp-native *) { padding-right: 30px !important; }
#bp-app .bx-btn { line-height: 1.4 !important; height: auto !important; }
/* ссылки в строках родного списка Битрикса — стандартный синий, а не цвет ссылок модуля */
#bp-app .bp-native .adm-list-table-row .adm-list-table-cell a { color: #2067b0; text-decoration: none; }
#bp-app .bp-native .adm-list-table-row .adm-list-table-cell a:hover { color: #2067b0; text-decoration: underline; }
#bp-app input[type=checkbox], #bp-app input[type=radio] { height: auto !important; min-height: 0 !important; padding: 0 !important; }
'''
(root / 'admin.css').write_text(res, encoding='utf-8')
print('admin.css:', len(res), 'bytes')
