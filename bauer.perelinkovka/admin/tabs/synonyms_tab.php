<?php
/**
 * synonyms_tab.php — вкладка «Синонимы» (?tab=synonyms).
 * Словарь синонимов для анкоров: одна группа на строку, слова через запятую.
 */

use Bauer\Perelinkovka\Env;

$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$synonyms = Env::synonyms();
$synSaved = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_synonyms'])) {
    $synonyms->saveFromText((string)($_POST['synonyms_text'] ?? ''));
    $synSaved = true;
}
?>
<h1 class="bx-page-title">Синонимы для анкоров</h1>

<?php if ($synSaved): ?>
    <div class="bx-notification bx-notification-success">✅ Словарь сохранён: групп — <?= count($synonyms->getGroups()) ?></div>
<?php endif; ?>

<form method="POST" action="?tab=synonyms">
    <input type="hidden" name="save_synonyms" value="1">
    <div class="bx-settings-section">
        <h3>Словарь синонимов</h3>
        <p class="hint" style="margin:0 0 10px;">Одна группа на строку, слова через запятую. Если в статье встретится любое слово группы, можно сослаться на страницу, в названии которой есть другое слово этой же группы.<br>Пример: <code>смартфон, телефон, мобильник</code></p>
        <textarea name="synonyms_text" rows="16" style="width:100%;font-family:inherit;"><?= $h($synonyms->toText()) ?></textarea>
    </div>
    <button type="submit" class="bx-btn bx-btn-primary">Сохранить</button>
    <a href="?tab=anchor_settings" class="bx-btn">К настройкам анкоров</a>
</form>
