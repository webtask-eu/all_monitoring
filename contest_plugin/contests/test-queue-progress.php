<?php
/**
 * Тестирование прогресса выполнения очередей 
 * с обновленными настройками таймаута (30 минут + 1 минута cron)
 * 
 * @package ITX_Contest_Plugin
 * @author IntellaraX
 * @version 1.0
 */

// Загружаем WordPress только если еще не загружен
if (!defined('ABSPATH')) {
    require_once dirname(dirname(dirname(dirname(__FILE__)))) . "/wp-load.php";
}

// Загружаем необходимые классы
require_once plugin_dir_path(__FILE__) . 'includes/class-account-updater.php';

/**
 * Проверяет текущее состояние всех очередей
 */
function check_current_queues_status() {
    echo "=== ТЕКУЩЕЕ СОСТОЯНИЕ ОЧЕРЕДЕЙ ===\n";
    
    $all_queues = Account_Updater::get_all_active_queues();
    
    echo "Всего конкурсов с очередями: " . count($all_queues['queues']) . "\n";
    echo "Активных очередей: " . $all_queues['total_running'] . "\n\n";
    
    if (!empty($all_queues['queues'])) {
        foreach ($all_queues['queues'] as $queue_info) {
            foreach ($queue_info['queues'] as $queue) {
                echo "🔍 Очередь: " . $queue['queue_id'] . "\n";
                echo "  Конкурс: " . $queue_info['contest_title'] . " (ID: " . $queue_info['contest_id'] . ")\n";
                echo "  Статус: " . ($queue['is_running'] ? '🟢 АКТИВНА' : '🔴 ОСТАНОВЛЕНА') . "\n";
                echo "  Прогресс: " . $queue['completed'] . "/" . $queue['total'] . " (" . round(($queue['completed']/$queue['total'])*100) . "%)\n";
                echo "  Успешно: " . ($queue['success'] ?? 0) . ", Ошибок: " . ($queue['failed'] ?? 0) . "\n";
                echo "  Начало: " . date('d.m.Y H:i:s', $queue['start_time']) . "\n";
                echo "  Последнее обновление: " . date('d.m.Y H:i:s', $queue['last_update']) . "\n";
                
                if (isset($queue['timeout']) && $queue['timeout']) {
                    echo "  ⚠️ ТАЙМАУТ: " . ($queue['message'] ?? 'Неизвестная причина') . "\n";
                }
                
                // Проверяем время неактивности
                $inactive_time = time() - $queue['last_update'];
                echo "  Неактивна: " . round($inactive_time / 60) . " минут\n";
                
                if ($inactive_time > 1800) { // 30 минут
                    echo "  🚨 КРИТИЧЕСКИ ДОЛГО БЕЗ ОБНОВЛЕНИЙ!\n";
                }
                
                echo "\n";
            }
        }
    } else {
        echo "❌ Активных очередей не найдено\n\n";
    }
}

/**
 * Создает тестовую очередь для мониторинга
 */
function create_test_queue() {
    global $wpdb;
    
    echo "=== СОЗДАНИЕ ТЕСТОВОЙ ОЧЕРЕДИ ===\n";
    
    // Находим активный конкурс с счетами
    $contest_id = 468990; // Используем известный конкурс
    
    // Получаем несколько счетов для тестирования
    $table_name = $wpdb->prefix . 'contest_members';
    $test_accounts = $wpdb->get_col($wpdb->prepare(
        "SELECT id FROM $table_name WHERE contest_id = %d AND connection_status != 'disqualified' LIMIT 3",
        $contest_id
    ));
    
    if (empty($test_accounts)) {
        echo "❌ Не найдено счетов для тестирования в конкурсе $contest_id\n";
        return false;
    }
    
    echo "Найдено счетов для тестирования: " . count($test_accounts) . "\n";
    echo "ID счетов: " . implode(', ', $test_accounts) . "\n";
    
    // Создаем очередь
    $result = Account_Updater::init_queue($test_accounts, false, $contest_id);
    
    if ($result['success']) {
        echo "✅ Тестовая очередь создана!\n";
        echo "Queue ID: " . $result['queue_id'] . "\n";
        echo "Contest ID: " . $result['contest_id'] . "\n";
        echo "Счетов в очереди: " . $result['total'] . "\n\n";
        return $result['queue_id'];
    } else {
        echo "❌ Ошибка создания очереди: " . $result['message'] . "\n";
        return false;
    }
}

/**
 * Мониторит прогресс очереди в режиме реального времени
 */
function monitor_queue_progress($queue_id, $contest_id = 468990, $duration_minutes = 10) {
    echo "=== МОНИТОРИНГ ОЧЕРЕДИ $queue_id ===\n";
    echo "Длительность мониторинга: $duration_minutes минут\n";
    echo "Обновление каждые 30 секунд\n\n";
    
    $start_time = time();
    $end_time = $start_time + ($duration_minutes * 60);
    
    while (time() < $end_time) {
        $status = Account_Updater::get_status($contest_id, $queue_id);
        
        $current_time = date('H:i:s');
        echo "[$current_time] ";
        
        if ($status['is_running']) {
            echo "🟢 АКТИВНА - ";
        } else {
            echo "🔴 ОСТАНОВЛЕНА - ";
        }
        
        echo "Прогресс: " . $status['completed'] . "/" . $status['total'];
        echo " (" . round(($status['completed']/$status['total'])*100) . "%)";
        echo " | Успешно: " . ($status['success'] ?? 0);
        echo " | Ошибок: " . ($status['failed'] ?? 0);
        
        if (isset($status['timeout']) && $status['timeout']) {
            echo " | ⚠️ ТАЙМАУТ";
        }
        
        echo "\n";
        
        // Если очередь завершилась, выходим
        if (!$status['is_running'] && $status['completed'] >= $status['total']) {
            echo "✅ Очередь полностью завершена!\n";
            break;
        }
        
        // Если очередь зависла с таймаутом, выходим
        if (!$status['is_running'] && isset($status['timeout']) && $status['timeout']) {
            echo "❌ Очередь остановлена по таймауту\n";
            break;
        }
        
        sleep(30); // Ждем 30 секунд
    }
    
    echo "\n=== КОНЕЦ МОНИТОРИНГА ===\n";
}

/**
 * Принудительно запускает WP Cron для тестирования
 */
function trigger_wp_cron() {
    echo "=== ПРИНУДИТЕЛЬНЫЙ ЗАПУСК WP CRON ===\n";
    
    // Проверяем запланированные задачи
    $crons = _get_cron_array();
    $contest_tasks = 0;
    
    foreach ($crons as $timestamp => $hooks) {
        foreach ($hooks as $hook => $events) {
            if (in_array($hook, ['contest_create_queues', 'process_accounts_update_batch'])) {
                $contest_tasks++;
                echo "Найдена задача: $hook на " . date('H:i:s', $timestamp) . "\n";
            }
        }
    }
    
    echo "Всего задач конкурсов: $contest_tasks\n";
    
    if ($contest_tasks > 0) {
        echo "Запускаю spawn_cron()...\n";
        spawn_cron();
        echo "✅ spawn_cron() выполнен\n";
    } else {
        echo "❌ Нет запланированных задач для выполнения\n";
    }
    
    echo "\n";
}

// ================ ОСНОВНОЙ СКРИПТ ================

if (php_sapi_name() === 'cli') {
    // Режим командной строки
    echo "🧪 ТЕСТИРОВАНИЕ СИСТЕМЫ ОЧЕРЕДЕЙ\n";
    echo "Версия: обновленная система (30 мин таймаут + 1 мин cron)\n";
    echo "Время запуска: " . date('d.m.Y H:i:s') . "\n\n";
    
    // Шаг 1: Проверяем текущие очереди
    check_current_queues_status();
    
    // Шаг 2: Принудительно запускаем cron
    trigger_wp_cron();
    
    // Шаг 3: Создаем тестовую очередь
    $queue_id = create_test_queue();
    
    if ($queue_id) {
        // Шаг 4: Принудительно запускаем cron для обработки
        echo "Запускаю обработку новой очереди...\n";
        trigger_wp_cron();
        
        // Шаг 5: Мониторим прогресс
        monitor_queue_progress($queue_id, 468990, 5); // 5 минут мониторинга
        
        // Шаг 6: Финальный статус
        echo "\n=== ФИНАЛЬНЫЙ СТАТУС ===\n";
        check_current_queues_status();
    }
    
    echo "🏁 ТЕСТИРОВАНИЕ ЗАВЕРШЕНО\n";
    
} else {
    // Веб-режим - выводим простую информацию
    echo "<h2>Текущее состояние очередей</h2>";
    echo "<pre>";
    check_current_queues_status();
    echo "</pre>";
    
    echo "<p><strong>Для полного тестирования запустите скрипт из командной строки:</strong></p>";
    echo "<code>php " . __FILE__ . "</code>";
} 