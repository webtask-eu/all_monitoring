<?php
// Устанавливаем таймер
$start_time = microtime(true);

// Функция для форматирования времени выполнения
function format_duration($seconds) {
    $hours = (int)($seconds / 3600);
    $minutes = (int)(($seconds % 3600) / 60);
    $seconds = $seconds % 60;
    
    $result = [];
    if ($hours > 0) $result[] = $hours . 'ч';
    if ($minutes > 0) $result[] = $minutes . 'м';
    $result[] = round($seconds, 2) . 'с';
    
    return implode(' ', $result);
}

// Функция для вывода в консоль с поддержкой цветов
function console_log($message, $type = 'info') {
    $colors = [
        'success' => "\033[32m", // Зеленый
        'error'   => "\033[31m", // Красный
        'warning' => "\033[33m", // Желтый
        'info'    => "\033[36m", // Голубой
        'reset'   => "\033[0m"   // Сброс цвета
    ];
    
    $color = $colors[$type] ?? $colors['info'];
    $time = date('H:i:s');
    echo "[{$time}] {$color}{$message}{$colors['reset']}" . PHP_EOL;
}

// Проверяем, запущено ли из командной строки
if (php_sapi_name() !== 'cli') {
    die('Этот скрипт должен быть запущен из командной строки');
}

// Загружаем WordPress
console_log('Загрузка WordPress...', 'info');

try {
    $wp_load_path = dirname(__FILE__) . '/../../../wp-load.php';
    if (!file_exists($wp_load_path)) {
        throw new Exception('Не найден файл wp-load.php');
    }
    
    require_once($wp_load_path);
    console_log('WordPress загружен', 'success');
    
    // Запускаем проверку дисквалификации
    console_log('Запуск проверки дисквалификации...', 'info');
    do_action('contest_accounts_disqualification_check');
    
} catch (Exception $e) {
    console_log('Ошибка: ' . $e->getMessage(), 'error');
    exit(1);
}

// Выводим статистику выполнения
$execution_time = microtime(true) - $start_time;
$memory_usage = round(memory_get_usage() / 1024 / 1024, 2);
$peak_memory = round(memory_get_peak_usage() / 1024 / 1024, 2);
console_log(sprintf(
    'Скрипт выполнен за %s. Память: %sMB/%sMB',
    format_duration($execution_time),
    $memory_usage,
    $peak_memory
), 'success');
// Защита от прямого доступа
defined('ABSPATH') || exit;

// Проверяем, что запрос пришел с локального сервера или от администратора
$is_local = in_array($_SERVER['REMOTE_ADDR'], ['127.0.0.1', '::1']);
$is_cli = (php_sapi_name() === 'cli');

if (!$is_cli && !$is_local && !current_user_can('manage_options')) {
    header('HTTP/1.0 403 Forbidden');
    exit('Доступ запрещен');
}

// Загружаем WordPress
require_once('wp-load.php');

// Запускаем проверку дисквалификации
do_action('contest_accounts_disqualification_check');

echo "Проверка на дисквалификацию успешно запущена.\n";

// Выводим информацию о времени
if (function_exists('current_time')) {
    echo "Время сервера: " . current_time('mysql') . "\n";
}

// Проверяем, запланирована ли следующая проверка
if (function_exists('wp_next_scheduled')) {
    $next = wp_next_scheduled('contest_accounts_disqualification_check');
    if ($next) {
        echo "Следующая автоматическая проверка: " . date('Y-m-d H:i:s', $next) . "\n";
    } else {
        echo "Автоматическая проверка не запланирована\n";
    }
}
