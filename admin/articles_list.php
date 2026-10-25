<?php
/**
 * articles_list.php — список статей вкладки «Анкоры» на стандартном CAdminList (только в Битриксе).
 * bp_articles_list_prepare() вызывается из page.php до prolog_admin_after (нужно для ajax-режима CheckListMode),
 * вкладка потом выводит список через bp_articles_list_display().
 */

use Bauer\Perelinkovka\Env;

const BP_ARTICLES_TABLE = 'bp_articles';

/** Адрес вкладки для статьи с сохранением фильтра. */
function bp_articles_url(int $iblockId, int $articleId, $section, string $search): string
{
    return '?' . http_build_query(['lang' => LANGUAGE_ID, 'tab' => 'anchors', 'article_iblock' => $iblockId,
        'section' => $section ?? '', 'search' => $search, 'article' => $articleId]);
}

/** Строит список, возвращает CAdminList (или null, если статей нет). */
function bp_articles_list_prepare(): ?CAdminList
{
    $dp = Env::provider();
    $iblockIds = [];
    foreach ($dp->getIBlocks() as $ib) {
        if ($dp->countElements(['iblock_id' => (int)$ib['id'], 'has_text' => true]) > 0) {
            $iblockIds[] = (int)$ib['id'];
        }
    }
    $iblockId = (int)($_REQUEST['article_iblock'] ?? 0);
    if (!in_array($iblockId, $iblockIds, true)) {
        $iblockId = $iblockIds[0] ?? 0;
    }
    if (!$iblockId) {
        return $GLOBALS['BP_ARTICLES_LIST'] = null;
    }

    // фильтр
    $section = isset($_GET['section']) && $_GET['section'] !== '' ? (int)$_GET['section'] : null;
    $search = trim((string)($_GET['search'] ?? ''));
    $selected = (int)($_REQUEST['article'] ?? 0);

    // названия разделов с путём
    $secById = [];
    foreach ($dp->getSections(['iblock_id' => $iblockId]) as $sec) {
        $secById[(int)$sec['id']] = $sec;
    }
    $secPath = function (int $id) use ($secById): string {
        $parts = [];
        for ($i = 0; $id && isset($secById[$id]) && $i < 10; $i++) {
            array_unshift($parts, $secById[$id]['name']);
            $id = (int)($secById[$id]['parent_id'] ?? 0);
        }
        return implode(' › ', $parts);
    };

    $store = Env::anchorStore();
    $rows = [];
    foreach ($dp->getElements(['iblock_id' => $iblockId, 'has_text' => true]) as $e) {
        $sid = (int)($e['section_id'] ?? 0);
        if ($section !== null && $sid !== $section) {
            continue;
        }
        if ($search !== '' && mb_stripos('#' . $e['id'] . ' ' . $e['name'], $search) === false) {
            continue;
        }
        $rows[] = ['ID' => (int)$e['id'], 'NAME' => (string)$e['name'],
            'SECTION' => $sid ? $secPath($sid) : 'Без раздела', 'DONE' => $store->get((int)$e['id']) ? 1 : 0];
    }

    $oSort = new CAdminSorting(BP_ARTICLES_TABLE, 'ID', 'asc');
    $by = strtoupper((string)($_GET['by'] ?? 'ID'));
    $by = in_array($by, ['ID', 'NAME'], true) ? $by : 'ID';
    $desc = strtolower((string)($_GET['order'] ?? 'asc')) === 'desc';
    usort($rows, function ($x, $y) use ($by, $desc) {
        $r = $by === 'NAME' ? strcasecmp($x['NAME'], $y['NAME']) : $x['ID'] <=> $y['ID'];
        return $desc ? -$r : $r;
    });

    $lAdmin = new CAdminList(BP_ARTICLES_TABLE, $oSort);
    $lAdmin->AddHeaders([
        ['id' => 'ID', 'content' => 'ID', 'sort' => 'ID', 'default' => true],
        ['id' => 'NAME', 'content' => 'Название', 'sort' => 'NAME', 'default' => true],
        ['id' => 'SECTION', 'content' => 'Раздел', 'default' => true],
        ['id' => 'DONE', 'content' => 'Ссылки', 'default' => true],
    ]);

    $rs = new CDBResult();
    $rs->InitFromArray($rows);
    $rs = new CAdminResult($rs, BP_ARTICLES_TABLE);
    $rs->NavStart(CAdminResult::GetNavSize(BP_ARTICLES_TABLE, 10));
    $lAdmin->NavText($rs->GetNavPrint('Статьи'));

    while ($r = $rs->NavNext(true, 'f_')) {
        $row = $lAdmin->AddRow($r['ID'], $r);
        $name = htmlspecialchars($r['NAME'], ENT_QUOTES, 'UTF-8');
        $href = htmlspecialchars(bp_articles_url($iblockId, (int)$r['ID'], $section, $search), ENT_QUOTES, 'UTF-8');
        $row->AddViewField('NAME', '<a href="' . $href . '">' . ((int)$r['ID'] === $selected ? '<b>' . $name . '</b>' : $name) . '</a>');
        $row->AddViewField('DONE', $r['DONE'] ? '<span style="color:#2a8a2a;">✓ есть</span>' : '');
    }

    $lAdmin->AddAdminContextMenu([]);
    $lAdmin->CheckListMode();
    return $GLOBALS['BP_ARTICLES_LIST'] = $lAdmin;
}

function bp_articles_list_display(): void
{
    $l = $GLOBALS['BP_ARTICLES_LIST'] ?? null;
    if ($l) {
        $l->DisplayList();
    }
}
