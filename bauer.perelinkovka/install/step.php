<?php
if (!check_bitrix_sessid()) {
    return;
}
global $APPLICATION;
if ($ex = $APPLICATION->GetException()) {
    CAdminMessage::ShowMessage(['TYPE' => 'ERROR', 'MESSAGE' => 'Модуль не установлен', 'DETAILS' => $ex->GetString(), 'HTML' => true]);
} else {
    CAdminMessage::ShowNote('Модуль «Перелинковка» установлен. Раздел: Сервисы → Перелинковка.');
}
?>
<form action="<?= $APPLICATION->GetCurPage() ?>">
    <input type="hidden" name="lang" value="<?= LANGUAGE_ID ?>">
    <input type="submit" value="Вернуться к списку модулей">
</form>
