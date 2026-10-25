<?php
if (!check_bitrix_sessid()) {
    return;
}
global $APPLICATION;
?>
<form action="<?= $APPLICATION->GetCurPage() ?>">
    <?= bitrix_sessid_post() ?>
    <input type="hidden" name="lang" value="<?= LANGUAGE_ID ?>">
    <input type="hidden" name="id" value="bauer.perelinkovka">
    <input type="hidden" name="uninstall" value="Y">
    <input type="hidden" name="step" value="2">
    <?php CAdminMessage::ShowMessage('Внимание! Модуль будет удалён.'); ?>
    <p><label><input type="checkbox" name="savedata" value="Y" checked> Сохранить таблицы и настройки (блоки, связи, исходные тексты статей до расстановки ссылок)</label></p>
    <p>Если снять галочку, данные модуля будут удалены безвозвратно. Тексты статей с уже вставленными ссылками останутся в элементах как есть, но вернуть исходные тексты будет нельзя.</p>
    <input type="submit" value="Удалить модуль">
</form>
