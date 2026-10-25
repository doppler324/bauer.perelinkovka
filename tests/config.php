<?php
/**
 * config.php
 * 
 * Конфигурация для тестовой админки.
 * Скопируйте в config.local.php и укажите свои ключи.
 * config.local.php не должен попасть в git.
 */

// API-ключ OpenRouter (https://openrouter.ai/keys)
if (is_file(__DIR__ . '/config.local.php')) { require __DIR__ . '/config.local.php'; }
if (!defined('OPENROUTER_API_KEY')) { define('OPENROUTER_API_KEY', getenv('OPENROUTER_API_KEY') ?: ''); }
