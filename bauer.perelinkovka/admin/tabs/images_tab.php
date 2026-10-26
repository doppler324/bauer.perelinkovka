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
        <p class="hint" style="margin:0 0 10px;">Ключ OpenRouter хранится на сервере и в браузер не отдаётся. Пустое поле оставляет прежний ключ<?= $settings['api_key'] !== '' ? ' (сейчас ключ задан)' : ' (сейчас ключ не задан)' ?>.</p>
        <p><label>API-ключ OpenRouter<br><input type="password" name="api_key" value="" autocomplete="off" style="width:420px;"></label></p>
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
            <p>Статей: <b><?= count($articles) ?></b>, картинок к генерации: <b><?= (int)$totalBlocks ?></b>.</p>
            <table class="bx-table">
                <tr><th>ID</th><th>Статья</th><th>Картинок</th></tr>
                <?php foreach ($articles as $a): ?>
                    <tr><td><?= (int)$a['id'] ?></td><td><?= $h($a['name']) ?></td><td><?= (int)$a['blocks'] ?></td></tr>
                <?php endforeach; ?>
            </table>
            <p class="hint">Картинки сохраняются в <code><?= $h(ImageTexts::SAVE_DIR) ?></code>, блок в тексте заменяется тегом картинки с описанием в alt. Если генерация блока не удалась, он остаётся в тексте.</p>
            <script>window.BP_IMAGE_IDS = <?= json_encode(array_column($articles, 'id')) ?>;</script>
            <button type="submit" name="img_run" value="1" class="bx-btn bx-btn-primary">Подставить картинки</button>
        <?php endif; ?>
    </div>
</form>
