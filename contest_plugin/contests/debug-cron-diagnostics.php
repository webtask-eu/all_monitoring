<?php
/**
 * Диагностический скрипт для проверки настроек автообновления и cron
 * Запуск: добавить ?page=debug-cron в URL админки
 */

// Защита от прямого доступа
if (!defined('ABSPATH')) {
    die('Прямой доступ запрещен');
}

// Подключаем необходимые классы
require_once plugin_dir_path(__FILE__) . 'includes/class-account-updater.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-cron-manager.php';

function debug_cron_auto_update() {
    echo "=== ДИАГНОСТИКА СИСТЕМЫ АВТООБНОВЛЕНИЯ ===\n\n";
    
    // 1. Проверяем настройки автообновления
    echo "1. НАСТРОЙКИ АВТООБНОВЛЕНИЯ:\n";
    $auto_settings = get_option('fttrader_auto_update_settings', []);
    echo "Полные настройки: " . print_r($auto_settings, true) . "\n";
    
    $enabled = isset($auto_settings['fttrader_auto_update_enabled']) ? $auto_settings['fttrader_auto_update_enabled'] : false;
    $interval = isset($auto_settings['fttrader_auto_update_interval']) ? $auto_settings['fttrader_auto_update_interval'] : 60;
    $batch_size = isset($auto_settings['fttrader_batch_size']) ? $auto_settings['fttrader_batch_size'] : 2;
    $min_update = isset($auto_settings['fttrader_min_update_interval']) ? $auto_settings['fttrader_min_update_interval'] : 5;
    
    echo "- Автообновление включено: " . ($enabled ? 'ДА' : 'НЕТ') . "\n";
    echo "- Интервал запуска: {$interval} минут\n";
    echo "- Размер пакета: {$batch_size} счетов\n";
    echo "- Мин. интервал между обновлениями: {$min_update} минут\n\n";
    
    // 2. Проверяем WP Cron
    echo "2. СОСТОЯНИЕ WP CRON:\n";
    $wp_cron_disabled = defined('DISABLE_WP_CRON') && DISABLE_WP_CRON;
    echo "- DISABLE_WP_CRON: " . ($wp_cron_disabled ? 'TRUE (внешний cron)' : 'FALSE (встроенный cron)') . "\n";
    
    $cron_status = Contest_Cron_Manager::check_cron_status();
    echo "- Хук запланирован: " . ($cron_status['our_hook_scheduled'] ? 'ДА' : 'НЕТ') . "\n";
    echo "- Интервал зарегистрирован: " . ($cron_status['our_interval_registered'] ? 'ДА' : 'НЕТ') . "\n";
    
    if ($cron_status['next_scheduled']) {
        echo "- Следующий запуск: " . date('d.m.Y H:i:s', $cron_status['next_scheduled']) . "\n";
    } else {
        echo "- Следующий запуск: НЕ ЗАПЛАНИРОВАН\n";
    }
    
    $last_run = get_option('contest_create_queues_last_run', 0);
    if ($last_run) {
        echo "- Последний запуск: " . date('d.m.Y H:i:s', $last_run) . "\n";
        echo "- Прошло с последнего запуска: " . round((time() - $last_run) / 60) . " минут\n";
    } else {
        echo "- Последний запуск: НИКОГДА\n";
    }
    echo "\n";
    
    // 3. Проверяем текущие очереди
    echo "3. АКТИВНЫЕ ОЧЕРЕДИ:\n";
    $queues_info = Account_Updater::get_all_active_queues();
    echo "- Всего конкурсов с очередями: " . $queues_info['contests'] . "\n";
    echo "- Всего активных очередей: " . $queues_info['total_running'] . "\n\n";
    
    if (!empty($queues_info['queues'])) {
        foreach ($queues_info['queues'] as $contest_info) {
            echo "Конкурс: {$contest_info['contest_title']} (ID: {$contest_info['contest_id']})\n";
            echo "- Очередей: {$contest_info['running_queues']}/{$contest_info['total_queues']}\n";
            
            foreach ($contest_info['queues'] as $queue) {
                echo "  - Очередь {$queue['queue_id']}: ";
                echo "{$queue['completed']}/{$queue['total']} ";
                echo "(" . round(($queue['completed']/$queue['total'])*100) . "%) ";
                echo ($queue['is_running'] ? 'АКТИВНА' : 'ОСТАНОВЛЕНА');
                if (isset($queue['timeout']) && $queue['timeout']) {
                    echo ' [ТАЙМАУТ]';
                    if (isset($queue['timeout_reason'])) {
                        echo " - {$queue['timeout_reason']}";
                    }
                }
                echo "\n";
                echo "    Начало: " . date('d.m.Y H:i:s', $queue['start_time']);
                echo ", Обновление: " . date('d.m.Y H:i:s', $queue['last_update']) . "\n";
                
                // Проверяем инициатора
                if (isset($queue['initiator'])) {
                    $initiator = $queue['initiator'];
                    echo "    Инициатор: " . ($initiator['type'] === 'auto' ? 'Автоматическое' : 'Ручное');
                    if ($initiator['type'] === 'manual') {
                        echo " ({$initiator['user_display_name']})";
                    }
                    echo "\n";
                }
            }
            echo "\n";
        }
    }
    
    // 4. Проверяем запланированные события
    echo "4. ЗАПЛАНИРОВАННЫЕ СОБЫТИЯ:\n";
    $crons = _get_cron_array();
    $auto_update_events = [];
    
    if (!empty($crons)) {
        foreach ($crons as $timestamp => $hooks) {
            if (isset($hooks['contest_create_queues'])) {
                foreach ($hooks['contest_create_queues'] as $event) {
                    $auto_update_events[] = [
                        'timestamp' => $timestamp,
                        'time' => date('d.m.Y H:i:s', $timestamp),
                        'schedule' => $event['schedule'] ?? 'once',
                        'args' => $event['args'] ?? []
                    ];
                }
            }
        }
    }
    
    echo "- Событий contest_create_queues: " . count($auto_update_events) . "\n";
    if (count($auto_update_events) > 1) {
        echo "⚠️  ОБНАРУЖЕНО ДУБЛИРОВАНИЕ СОБЫТИЙ!\n";
    }
    
    foreach ($auto_update_events as $event) {
        echo "  - {$event['time']} (расписание: {$event['schedule']})\n";
    }
    echo "\n";
    
    // 5. Проверяем активные конкурсы
    echo "5. АКТИВНЫЕ КОНКУРСЫ:\n";
    $contests = get_posts([
        'post_type' => 'trader_contests',
        'post_status' => 'publish',
        'posts_per_page' => -1
    ]);
    
    $active_contests = [];
    foreach ($contests as $contest) {
        $contest_data = get_post_meta($contest->ID, '_fttradingapi_contest_data', true);
        if (!empty($contest_data) && is_array($contest_data) && 
            isset($contest_data['contest_status']) && $contest_data['contest_status'] === 'active') {
            $active_contests[] = $contest;
        }
    }
    
    echo "- Всего конкурсов со статусом publish: " . count($contests) . "\n";
    echo "- Активных конкурсов (в метаданных): " . count($active_contests) . "\n";
    
    foreach ($active_contests as $contest) {
        echo "  - {$contest->post_title} (ID: {$contest->ID})\n";
        
        // Считаем счета в конкурсе
        global $wpdb;
        $accounts_count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}contest_members WHERE contest_id = %d",
            $contest->ID
        ));
        echo "    Счетов в конкурсе: {$accounts_count}\n";
    }
    echo "\n";
    
    // 6. Анализ проблем
    echo "6. АНАЛИЗ ПРОБЛЕМ:\n";
    
    if (!$enabled) {
        echo "🚨 КРИТИЧЕСКАЯ ПРОБЛЕМА: Автообновление отключено в настройках!\n";
    }
    
    if (count($auto_update_events) == 0) {
        echo "🚨 КРИТИЧЕСКАЯ ПРОБЛЕМА: Нет запланированных событий автообновления!\n";
    } elseif (count($auto_update_events) > 1) {
        echo "⚠️  ПРОБЛЕМА: Дублирование событий автообновления (возможны конфликты)\n";
    }
    
    if ($queues_info['total_running'] > 0) {
        $timeout_queues = 0;
        foreach ($queues_info['queues'] as $contest_info) {
            foreach ($contest_info['queues'] as $queue) {
                if (isset($queue['timeout']) && $queue['timeout']) {
                    $timeout_queues++;
                }
            }
        }
        
        if ($timeout_queues > 0) {
            echo "🚨 КРИТИЧЕСКАЯ ПРОБЛЕМА: {$timeout_queues} очередей завершились таймаутом!\n";
            echo "   Возможные причины:\n";
            echo "   - API сервера мониторинга недоступен\n";
            echo "   - Слишком маленький тайм-аут (5 минут)\n";
            echo "   - Проблемы с WP Cron\n";
            echo "   - Слишком большой размер пакета\n";
        }
    }
    
    if ($wp_cron_disabled && !$cron_status['our_hook_scheduled']) {
        echo "⚠️  ПРОБЛЕМА: Внешний cron настроен, но события не запланированы\n";
        echo "   Проверьте настройку внешнего cron на вызов wp-cron.php\n";
    }
    
    // Проверяем интервалы в логах
    if (!empty($queues_info['queues'])) {
        echo "\n7. АНАЛИЗ ИНТЕРВАЛОВ СОЗДАНИЯ ОЧЕРЕДЕЙ:\n";
        $all_start_times = [];
        foreach ($queues_info['queues'] as $contest_info) {
            foreach ($contest_info['queues'] as $queue) {
                $all_start_times[] = $queue['start_time'];
            }
        }
        
        if (count($all_start_times) >= 2) {
            sort($all_start_times);
            for ($i = 1; $i < count($all_start_times); $i++) {
                $interval_minutes = ($all_start_times[$i] - $all_start_times[$i-1]) / 60;
                echo "Интервал между очередями: " . round($interval_minutes) . " минут\n";
            }
            
            // Проверяем, соответствует ли интервал настройкам
            $expected_interval = $interval;
            $actual_intervals = [];
            for ($i = 1; $i < count($all_start_times); $i++) {
                $actual_intervals[] = ($all_start_times[$i] - $all_start_times[$i-1]) / 60;
            }
            $avg_interval = array_sum($actual_intervals) / count($actual_intervals);
            
            echo "Ожидаемый интервал: {$expected_interval} минут\n";
            echo "Средний фактический интервал: " . round($avg_interval) . " минут\n";
            
            if (abs($avg_interval - $expected_interval) > 10) {
                echo "🚨 ПРОБЛЕМА: Фактический интервал не соответствует настройкам!\n";
            }
        }
    }
    
    echo "\n=== КОНЕЦ ДИАГНОСТИКИ ===\n";
}

// Добавляем функцию для вызова через админку
if (is_admin() && isset($_GET['page']) && $_GET['page'] === 'debug-cron') {
    add_action('admin_init', function() {
        if (!current_user_can('manage_options')) {
            wp_die('У вас нет прав для просмотра этой страницы.');
        }
        
        header('Content-Type: text/plain; charset=utf-8');
        debug_cron_auto_update();
        exit;
    });
} 