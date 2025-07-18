<?php
require_once __DIR__ . '/../../../wp-load.php';

echo "=== ПРОВЕРКА ОЧЕРЕДИ WORDPRESS ===\n";

// Проверяем задачи в cron
$cron_array = _get_cron_array();
$current_time = time();

echo "Текущее время: " . date('Y-m-d H:i:s', $current_time) . "\n";
echo "Ищем задачи process_accounts_update_batch за последние 10 минут...\n\n";

$found_tasks = 0;
foreach ($cron_array as $timestamp => $hooks) {
    // Ищем задачи за последние 10 минут или будущие задачи
    if ($timestamp > $current_time - 600) {
        foreach ($hooks as $hook_name => $hook_data) {
            if (strpos($hook_name, 'process_accounts_update_batch') !== false) {
                $found_tasks++;
                $time_diff = $timestamp - $current_time;
                echo "НАЙДЕНА ЗАДАЧА #{$found_tasks}:\n";
                echo "  Hook: {$hook_name}\n";
                echo "  Время: " . date('Y-m-d H:i:s', $timestamp);
                if ($time_diff > 0) {
                    echo " (через {$time_diff} сек)\n";
                } else {
                    echo " (" . abs($time_diff) . " сек назад)\n";
                }
                echo "  Данные: " . print_r($hook_data, true) . "\n";
            }
        }
    }
}

if ($found_tasks == 0) {
    echo "❌ Задачи process_accounts_update_batch НЕ НАЙДЕНЫ!\n";
} else {
    echo "✅ Найдено задач: {$found_tasks}\n";
}

echo "\n=== ПРОВЕРКА DAEMON ФУНКЦИИ ===\n";

// Проверяем есть ли функция в WordPress
if (function_exists('process_accounts_update_batch')) {
    echo "✅ Функция process_accounts_update_batch существует\n";
} else {
    echo "❌ Функция process_accounts_update_batch НЕ НАЙДЕНА!\n";
}

echo "\n=== ПОСЛЕДНИЕ 5 ЗАДАЧ В CRON ===\n";
$task_count = 0;
foreach ($cron_array as $timestamp => $hooks) {
    if ($task_count >= 5) break;
    foreach ($hooks as $hook_name => $hook_data) {
        echo "- {$hook_name} в " . date('H:i:s', $timestamp) . "\n";
        $task_count++;
        if ($task_count >= 5) break;
    }
} 