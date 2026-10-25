#!/usr/bin/env bash
# Обновление модуля на сервере: git pull + копирование файлов в папки Битрикса с заменой.
# Запуск из любой папки:  bash /путь/к/local/modules/bauer.perelinkovka/update.sh [корень_сайта]
# Корень сайта определяется сам (папка выше /local/modules или /bitrix/modules); можно передать первым аргументом.
set -euo pipefail

MOD="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ID="$(basename "$MOD")"

ROOT="${1:-}"
if [ -z "$ROOT" ]; then
    case "$MOD" in
        */local/modules/*)  ROOT="${MOD%/local/modules/*}" ;;
        */bitrix/modules/*) ROOT="${MOD%/bitrix/modules/*}" ;;
        *) echo "Не удалось определить корень сайта, передайте его аргументом: bash update.sh /var/www/site" >&2; exit 1 ;;
    esac
fi
[ -d "$ROOT/bitrix" ] || { echo "В $ROOT нет папки bitrix" >&2; exit 1; }

echo "Модуль: $MOD"
echo "Сайт:   $ROOT"

git -C "$MOD" pull

copy() { # откуда внутри install -> куда
    mkdir -p "$2"
    cp -a "$MOD/install/$1/." "$2/"
    echo "  install/$1 -> $2"
}
copy admin      "$ROOT/bitrix/admin"
copy js         "$ROOT/bitrix/js/$ID"
copy css        "$ROOT/bitrix/css/$ID"
copy components "$ROOT/bitrix/components"

echo "Готово. Обновите страницу в админке (Ctrl+F5)."
