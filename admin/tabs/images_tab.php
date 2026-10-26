<?php
/**
 * images_tab.php — вкладка «Подстановка картинок» (?tab=images).
 * Находит в статьях инфоблока блоки «[Изображение: описание]», генерирует картинки через OpenRouter
 * и подставляет их в текст. Сама подстановка идёт порциями с прогресс-баром (progress.js, форма #imagesForm).
 */

use Bauer\Perelinkovka\Env;
use Bauer\Perelinkovka\ImageTexts;

$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
?>
<h1 class="bx-page-title">Подстановка картинок</h1>
<?php if (!Env::isBitrix()): ?>
    <div class="bx-notification">Подстановка картинок работает только в Битриксе: тексты статей берутся из инфоблоков сайта.</div>
    <?php return; ?>
<?php endif;

$saved = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_image_settings'])) {
    ImageTexts::saveSettings($_POST);
    $saved = true;
}
$settings = ImageTexts::settings();

$iblockId = (int)($_REQUEST['img_iblock'] ?? 0);
if (!$iblockId && $iblocks) {
    $iblockId = (int)$iblocks[0]['id'];
}
$articles = $iblockId ? ImageTexts::findArticles($iblockId) : [];
$totalBlocks = array_sum(array_column($articles, 'blocks'));
?>
<?php if ($saved): ?>
    <div class="bx-notification bx-notification-success">✅ Настройки сохранены</div>
<?php endif; ?>

<form method="POST" action="?tab=images">
    <input type="hidden" name="save_image_settings" value="1">
    <input type="hidden" name="img_iblock" value="<?= (int)$iblockId ?>">
    <div class="bx-settings-section">
        <h3>Настройки генерации</h3>
        <p class="hint" style="margin:0 0 10px;">Ключ OpenRouter и прокси общие с остальным модулем: <?= Env::apiKey() !== '' ? 'ключ задан' : '<b>ключ не задан</b>' ?>, <?= Env::proxy() !== '' ? 'прокси задан' : 'прокси не задан (запросы идут напрямую)' ?>. Менять их нужно в настройках модуля: Настройки продукта → Модули → Перелинковка.</p>
        <p><label>Модель<br><input type="text" name="model" value="<?= $h($settings['model']) ?>" style="width:420px;"></label></p>
        <p><label>Общий стиль картинок (дописывается к описанию)<br><textarea name="style" rows="3" style="width:100%;font-family:inherit;"><?= $h($settings['style']) ?></textarea></label></p>
    </div>
    <button type="submit" class="bx-btn">Сохранить настройки</button>
</form>

<form method="POST" action="?tab=images" id="imagesForm" style="margin-top:20px;">
    <div class="bx-settings-section">
        <h3>Статьи с блоками «[Изображение: …]»</h3>
        <p><label>Инфоблок<br>
            <select name="img_iblock" onchange="this.form.querySelector('[name=img_refresh]').click()">
                <?php foreach ($iblocks as $ib): ?>
                    <option value="<?= (int)$ib['id'] ?>" <?= (int)$ib['id'] === $iblockId ? 'selected' : '' ?>><?= $h($ib['name']) ?></option>
                <?php endforeach; ?>
            </select></label>
            <button type="submit" name="img_refresh" value="1" class="bx-btn bx-btn-sm">Обновить</button>
        </p>
        <?php if (!$articles): ?>
            <p>В этом инфоблоке нет статей с блоками «[Изображение: …]».</p>
        <?php else: ?>
            <p>Статей: <b><?= count($articles) ?></b>, картинок к генерации: <b><?= (int)$totalBlocks ?></b>. Отметьте статьи, в которые нужно подставить картинки.</p>
            <div class="bx-table-wrap" style="margin-bottom:16px;">
                <div class="bx-table-toolbar">
                    <div class="left"><b>Статьи с блоками «[Изображение: …]»</b></div>
                    <div class="right"><button type="submit" name="img_run" value="1" class="bx-btn bx-btn-sm bx-btn-success">🖼️ Подставить в выбранные</button></div>
                </div>
                <div class="tk-filters">
                    <input type="search" id="imgSearch" placeholder="Поиск по названию или номеру">
                    <span class="mass-muted" id="imgSel"></span>
                </div>
                <table class="bx-table" id="imgTable">
                    <thead><tr><th style="width:30px;"><input type="checkbox" id="imgAll" title="Выбрать все найденные (на всех страницах)"></th><th style="width:90px;">ID</th><th>Статья</th><th style="width:110px;">Картинок</th></tr></thead>
                    <tbody>
                    <?php foreach ($articles as $a): ?>
                        <tr data-s="<?= $h(mb_strtolower('#' . $a['id'] . ' ' . $a['name'])) ?>">
                            <td><input type="checkbox" name="ids[]" value="<?= (int)$a['id'] ?>" data-blocks="<?= (int)$a['blocks'] ?>"></td>
                            <td><?= (int)$a['id'] ?></td><td><?= $h($a['name']) ?></td><td><?= (int)$a['blocks'] ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <div class="tk-foot"><span class="mass-muted" id="imgInfo"></span><span class="tk-pager" id="imgPager"></span>
                    <label class="mass-muted">на странице <select id="imgSize"><option>25</option><option selected>50</option><option>100</option><option>200</option></select></label></div>
            </div>
            <p class="hint">Картинки сохраняются в <code><?= $h(ImageTexts::SAVE_DIR) ?></code>, блок в тексте заменяется тегом картинки с описанием в alt. Если генерация блока не удалась, он остаётся в тексте.</p>
            <script>
            bpTable({table: '#imgTable', search: '#imgSearch', master: '#imgAll', pager: '#imgPager', info: '#imgInfo', selected: '#imgSel', sizeSel: '#imgSize', size: 50});
            </script>
        <?php endif; ?>
    </div>
</form>
