<?php
if (!check_bitrix_sessid()) {
    return;
}
global $APPLICATION;
CAdminMessage::ShowNote('Модуль «Перелинковка» удалён.');
?>
<form action="<?= $APPLICATION->GetCurPage() ?>">
    <input type="hidden" name="lang" value="<?= LANGUAGE_ID ?>">
    <input type="submit" value="Вернуться к списку модулей">
</form>
