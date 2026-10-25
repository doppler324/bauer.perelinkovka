<?php
if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) die();

use Bitrix\Main\Config\Option;

global $USER, $APPLICATION;
if (!$USER->IsAdmin()) {
    return;
}
$module = 'bauer.perelinkovka';
$request = \Bitrix\Main\Application::getInstance()->getContext()->getRequest();

if ($request->isPost() && check_bitrix_sessid() && ($request->getPost('save') !== null || $request->getPost('apply') !== null)) {
    $newKey = trim((string)$request->getPost('openrouter_api_key'));
    if ($newKey !== '' || $request->getPost('openrouter_api_key_clear') === 'Y') {
        Option::set($module, 'openrouter_api_key', $request->getPost('openrouter_api_key_clear') === 'Y' ? '' : $newKey);
    }
    Option::set($module, 'keywords_property', trim((string)$request->getPost('keywords_property')));
    Option::set($module, 'agent_enabled', $request->getPost('agent_enabled') === 'Y' ? 'Y' : 'N');
    Option::set($module, 'agent_interval_hours', (string)max(1, (int)$request->getPost('agent_interval_hours')));
    if ($request->getPost('agent_restart') === 'Y') {
        Option::set($module, 'agent_state', '');
    }
    LocalRedirect($APPLICATION->GetCurPage() . '?mid=' . urlencode($module) . '&lang=' . LANGUAGE_ID);
}

$state = json_decode(Option::get($module, 'agent_state', ''), true) ?: [];
if (!empty($state['running'])) {
    $stateText = 'идёт пересчёт: блок №' . ((int)($state['block'] ?? 0) + 1) . ', позиция ' . (int)($state['offset'] ?? 0);
} elseif (!empty($state['next_run'])) {
    $stateText = 'следующий пересчёт после ' . date('d.m.Y H:i', (int)$state['next_run']);
} else {
    $stateText = 'ещё не запускался (запустится ближайшим тиком агента)';
}

$tabControl = new CAdminTabControl('bpTabs', [['DIV' => 'main', 'TAB' => 'Настройки', 'TITLE' => 'Настройки модуля']]);
$tabControl->Begin();
?>
<form method="post" action="<?= $APPLICATION->GetCurPage() ?>?mid=<?= urlencode($module) ?>&lang=<?= LANGUAGE_ID ?>">
    <?= bitrix_sessid_post() ?>
    <?php $tabControl->BeginNextTab(); ?>
    <tr>
        <td width="40%">Код свойства с ключевыми словами:</td>
        <td><input type="text" name="keywords_property" size="30" value="<?= htmlspecialcharsbx(Option::get($module, 'keywords_property', 'KEYWORDS')) ?>">
            <br><small>Свойство элементов инфоблока (строка, можно через запятую). Пусто — ключевые слова не используются.</small></td>
    </tr>
    <tr>
        <td>Ключ OpenRouter (эмбеддинги):</td>
        <td><input type="password" name="openrouter_api_key" size="40" autocomplete="off" placeholder="<?= Option::get($module, 'openrouter_api_key', '') !== '' ? 'ключ сохранён, введите новый для замены' : 'sk-or-…' ?>">
            <br><label><input type="checkbox" name="openrouter_api_key_clear" value="Y"> удалить сохранённый ключ</label>
            <br><small>Нужен для смыслового подбора ссылок (Перелинковка внутри текстов → Настройки). Ключ хранится в настройках модуля и не показывается.</small></td>
    </tr>
    <tr>
        <td>Автоматический пересчёт связей агентом:</td>
        <td><input type="checkbox" name="agent_enabled" value="Y" <?= Option::get($module, 'agent_enabled', 'Y') === 'Y' ? 'checked' : '' ?>></td>
    </tr>
    <tr>
        <td>Интервал между пересчётами, часов:</td>
        <td><input type="text" name="agent_interval_hours" size="5" value="<?= (int)Option::get($module, 'agent_interval_hours', 6) ?>"></td>
    </tr>
    <tr>
        <td>Состояние агента:</td>
        <td><?= htmlspecialcharsbx($stateText) ?><br>
            <label><input type="checkbox" name="agent_restart" value="Y"> начать пересчёт заново при сохранении</label></td>
    </tr>
    <?php $tabControl->Buttons(); ?>
    <input type="submit" name="save" value="Сохранить" class="adm-btn-save">
    <?php $tabControl->End(); ?>
</form>
