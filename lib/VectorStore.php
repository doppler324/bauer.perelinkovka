<?php

namespace Bauer\Perelinkovka;

use Bauer\Perelinkovka\Orm\VectorTable;

/**
 * Хранилище векторов целей. Вектор нормирован (длина 1), поэтому косинус = скалярное произведение.
 * В Битриксе — таблица b_bauer_perelinkovka_vector, в тестовой оболочке — файл vectors.json.
 */
class VectorStore
{
    private ?string $file;

    public function __construct(?string $jsonFile = null)
    {
        $this->file = $jsonFile;
        if ($jsonFile === null) {
            VectorTable::ensure();
        }
    }

    /**
     * @param string[] $keys
     * Векторы хранятся и отдаются как бинарная строка float32 (экономит память; разбор — Embeddings::floats).
     * @return array<string,array{h:string,b:string}>
     */
    public function get(array $keys): array
    {
        $out = [];
        if ($this->file !== null) {
            foreach ($this->readFile() as $k => $row) {
                if (in_array($k, $keys, true)) {
                    $out[$k] = ['h' => $row['h'], 'b' => (string)base64_decode($row['v'], true)];
                }
            }
            return $out;
        }
        foreach (array_chunk($keys, 500) as $part) {
            $res = VectorTable::getList(['filter' => ['@VKEY' => $part], 'select' => ['VKEY', 'HASH', 'VECTOR']]);
            while ($r = $res->fetch()) {
                $out[$r['VKEY']] = ['h' => $r['HASH'], 'b' => (string)base64_decode($r['VECTOR'], true)];
            }
        }
        return $out;
    }

    /** Хэши сохранённых векторов (без самих векторов) по ключам. */
    public function hashes(array $keys): array
    {
        $out = [];
        if ($this->file !== null) {
            foreach ($this->readFile() as $k => $row) {
                $out[$k] = $row['h'];
            }
            return array_intersect_key($out, array_flip($keys));
        }
        foreach (array_chunk($keys, 500) as $part) {
            $res = VectorTable::getList(['filter' => ['@VKEY' => $part], 'select' => ['VKEY', 'HASH']]);
            while ($r = $res->fetch()) {
                $out[$r['VKEY']] = $r['HASH'];
            }
        }
        return $out;
    }

    /** @param array<string,array{h:string,v:float[]}> $rows */
    public function put(array $rows): void
    {
        if ($this->file !== null) {
            $all = $this->readFile();
            foreach ($rows as $k => $row) {
                $all[$k] = ['h' => $row['h'], 'v' => self::pack($row['v'])];
            }
            file_put_contents($this->file, json_encode($all), LOCK_EX);
            return;
        }
        foreach ($rows as $k => $row) {
            $id = VectorTable::getList(['filter' => ['=VKEY' => $k], 'select' => ['ID'], 'limit' => 1])->fetch()['ID'] ?? 0;
            $fields = ['HASH' => $row['h'], 'VECTOR' => self::pack($row['v']), 'UPDATED_AT' => new \Bitrix\Main\Type\DateTime()];
            if ($id) {
                VectorTable::update($id, $fields);
            } else {
                VectorTable::add($fields + ['VKEY' => $k]);
            }
        }
    }

    public function count(): int
    {
        return $this->file !== null ? count($this->readFile()) : (int)VectorTable::getCount();
    }

    private function readFile(): array
    {
        $d = is_file($this->file) ? json_decode((string)file_get_contents($this->file), true) : [];
        return is_array($d) ? $d : [];
    }

    public static function pack(array $v): string
    {
        return base64_encode(pack('g*', ...$v));
    }

    public static function unpack(string $s): array
    {
        $bin = base64_decode($s, true);
        return $bin === false || $bin === '' ? [] : array_values(unpack('g*', $bin));
    }
}
