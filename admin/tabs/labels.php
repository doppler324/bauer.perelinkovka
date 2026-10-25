<?php
/**
 * labels.php: русские названия типов связей и способов подбора для тестовой админки.
 */

function ru_type(?string $type): string
{
    $type = (string)$type;
    if (str_starts_with($type, 'auto_')) {
        $type = substr($type, 5);
    }
    return [
        'strong' => 'сильная', 'medium' => 'средняя', 'weak' => 'слабая',
        'manual' => 'ручная', 'related' => 'связанная', 'accessory' => 'аксессуар', 'section' => 'раздел',
    ][$type] ?? ($type !== '' ? $type : '—');
}

function ru_strategy(?string $strategy): string
{
    $names = [
        'keyword' => 'по ключевым словам', 'tfidf' => 'по тексту', 'embedding' => 'по смыслу',
        'llm' => 'ИИ', 'manual' => 'вручную', 'auto' => 'автоматически',
    ];
    $parts = array_filter(explode('+', (string)$strategy));
    if (!$parts) {
        return '—';
    }
    return implode(' + ', array_map(fn($p) => $names[$p] ?? $p, $parts));
}

/** Класс для цветной метки типа */
function type_css(?string $type): string
{
    $t = str_starts_with((string)$type, 'auto_') ? substr((string)$type, 5) : (string)$type;
    return in_array($t, ['strong', 'medium', 'weak', 'manual'], true) ? $t : 'auto';
}
