<?php

namespace Bauer\Perelinkovka;

/**
 * Class SynonymProvider
 *
 * Группы синонимов из synonyms.json: [["смартфон","телефон"],["ноутбук","лэптоп"]].
 */
class SynonymProvider
{
    /** @var string[][] */
    private array $groups = [];
    private ?Store\StoreInterface $store = null;

    /**
     * @param string|Store\StoreInterface $source путь к synonyms.json или хранилище (имя "synonyms")
     */
    public function __construct($source)
    {
        $data = null;
        if ($source instanceof Store\StoreInterface) {
            $this->store = $source;
            $data = $source->read('synonyms');
        } elseif (is_file($source)) {
            $data = json_decode(file_get_contents($source), true);
        }
        if (is_array($data)) {
            $this->groups = array_values(array_filter($data, 'is_array'));
        }
    }

    /**
     * Сохраняет словарь из текста: одна группа на строку, термины через запятую.
     * Работает при создании со StoreInterface.
     */
    public function saveFromText(string $text): bool
    {
        $groups = [];
        foreach (preg_split('/\R/u', $text) as $line) {
            $terms = array_values(array_unique(array_filter(array_map('trim', explode(',', $line)), 'strlen')));
            if (count($terms) >= 2) {
                $groups[] = $terms;
            }
        }
        $this->groups = $groups;
        return $this->store ? $this->store->write('synonyms', $groups) : false;
    }

    /** Группы в виде текста для редактирования: строка на группу. */
    public function toText(): string
    {
        return implode("\n", array_map(fn($g) => implode(', ', $g), $this->groups));
    }

    public function getGroups(): array
    {
        return $this->groups;
    }

    /**
     * Варианты фразы: термин группы, найденный во фразе (по основам),
     * заменяется остальными терминами той же группы.
     * @return string[] варианты без исходной фразы
     */
    public function variants(string $phrase): array
    {
        $words = TextProcessor::words($phrase);
        $variants = [];
        foreach ($this->groups as $group) {
            foreach ($group as $term) {
                $tw = TextProcessor::words($term);
                $n = count($tw);
                if ($n === 0) {
                    continue;
                }
                for ($i = 0; $i + $n <= count($words); $i++) {
                    for ($j = 0; $j < $n && $words[$i + $j]['stem'] === $tw[$j]['stem']; $j++);
                    if ($j < $n) {
                        continue;
                    }
                    $before = substr($phrase, 0, $words[$i]['start']);
                    $after = substr($phrase, $words[$i + $n - 1]['end']);
                    foreach ($group as $other) {
                        if ($other !== $term) {
                            $variants[$before . $other . $after] = true;
                        }
                    }
                }
            }
        }
        unset($variants[$phrase]);
        return array_map('strval', array_keys($variants));
    }
}
