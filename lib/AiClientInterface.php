<?php

namespace Bauer\Perelinkovka;

/**
 * Interface AiClientInterface
 * 
 * Абстракция для работы с AI-провайдерами (OpenRouter, DeepSeek и др.).
 * Все провайдеры используют OpenAI-совместимый формат API.
 */
interface AiClientInterface
{
    /**
     * Получить embedding (вектор) для текста
     * @param string $text
     * @return array - массив float значений
     */
    public function getEmbedding(string $text): array;

    /**
     * Получить embedding для нескольких текстов сразу (batch)
     * @param array $texts
     * @return array - массив массивов float значений
     */
    public function getEmbeddings(array $texts): array;

    /**
     * Отправить chat completion запрос
     * @param array $messages - массив сообщений [{role, content}]
     * @param array $options - доп. опции (model, temperature, max_tokens)
     * @return array - ответ с содержимым
     */
    public function chat(array $messages, array $options = []): array;

    /**
     * Проверить, доступен ли клиент (есть ли API-ключ)
     * @return bool
     */
    public function isAvailable(): bool;
}
