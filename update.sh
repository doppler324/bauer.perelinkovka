#!/bin/bash
# Обновление модуля bauer.perelinkovka на сервере.
# Использование: положите update.sh рядом с папкой bauer.perelinkovka (из репозитория или архива) и запустите:
#   bash update.sh /путь/к/корню/сайта        (например /var/www/site/www)
# Скрипт копирует файлы модуля в /local/modules или /bitrix/modules (где модуль уже стоит),
# а JS и CSS в /bitrix/js и /bitrix/css. Перед копированием делает резервную копию папки модуля.
set -e

SRC="$(cd "$(dirname "$0")" && pwd)/bauer.perelinkovka"
ROOT="${1:-$DOCUMENT_ROOT}"
[ -d "$SRC" ] || { echo "Не найдена папка $SRC"; exit 1; }
[ -n "$ROOT" ] && [ -d "$ROOT/bitrix" ] || { echo "Укажите корень сайта: bash update.sh /путь/к/сайту"; exit 1; }

if [ -d "$ROOT/local/modules/bauer.perelinkovka" ]; then
    MOD="$ROOT/local/modules/bauer.perelinkovka"
elif [ -d "$ROOT/bitrix/modules/bauer.perelinkovka" ]; then
    MOD="$ROOT/bitrix/modules/bauer.perelinkovka"
else
    echo "Модуль не установлен на сайте: сначала установите его через админку"; exit 1
fi

BACKUP="${TMPDIR:-/tmp}/bauer.perelinkovka.backup-$(date +%Y%m%d-%H%M%S)"
cp -a "$MOD" "$BACKUP"
echo "Резервная копия: $BACKUP"

# резервная копия лежит вне /bitrix/modules, чтобы Битрикс не принял её за модуль
# файлы модуля (кроме тестов и установочных ресурсов, их копируем отдельно)
cp -a "$SRC/include.php" "$SRC/options.php" "$MOD/"
for d in admin lib lang install; do
    mkdir -p "$MOD/$d"
    cp -a "$SRC/$d/." "$MOD/$d/"
done

# публичные ресурсы
mkdir -p "$ROOT/bitrix/js/bauer.perelinkovka" "$ROOT/bitrix/css/bauer.perelinkovka"
cp -a "$SRC/install/js/." "$ROOT/bitrix/js/bauer.perelinkovka/"
cp -a "$SRC/install/css/." "$ROOT/bitrix/css/bauer.perelinkovka/"

# владелец как у корня сайта (если запущено от root)
if [ "$(id -u)" = "0" ]; then
    OWN="$(stat -c '%u:%g' "$ROOT/bitrix")"
    chown -R "$OWN" "$MOD" "$ROOT/bitrix/js/bauer.perelinkovka" "$ROOT/bitrix/css/bauer.perelinkovka"
fi

# сброс опкэша PHP, если доступен
php -r 'if (function_exists("opcache_reset")) { opcache_reset(); }' 2>/dev/null || true

echo "Готово. Модуль обновлён: $MOD"
echo "Обновите страницу админки (Ctrl+F5) и откройте раздел «Картинки»."
