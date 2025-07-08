<?php
/**
 * Тест автономности плагина конкурсов
 * Проверяет, что плагин работает без внешних зависимостей
 * 
 * @version 1.0.0
 * @author IntellaraX
 */

// Предотвращаем прямой доступ
if (!defined('ABSPATH')) {
    exit;
}

// Подключаем WordPress
require_once(ABSPATH . 'wp-config.php');
require_once(ABSPATH . 'wp-includes/wp-db.php');
require_once(ABSPATH . 'wp-includes/pluggable.php');

/**
 * Тест автономности плагина
 */
function test_standalone_plugin() {
    echo "<h1>Тест автономности плагина FT Contests</h1>";
    echo "<div style='font-family: monospace; background: #f5f5f5; padding: 20px; border-radius: 5px;'>";
    
    $tests = array();
    
    // Тест 1: Проверка существования основных классов
    $tests['FT_Standalone_Cron'] = class_exists('FT_Standalone_Cron');
    $tests['Account_Updater'] = class_exists('Account_Updater');
    // ITX_Queue_Protection удален
    $tests['ITX_Queue_Admin'] = class_exists('ITX_Queue_Admin');
    
    // Тест 2: Проверка настроек автоматического обновления
    $settings = get_option('fttrader_auto_update_settings', array());
    $tests['Auto_Update_Settings'] = !empty($settings);
    $tests['Auto_Update_Enabled'] = isset($settings['fttrader_auto_update_enabled']) && $settings['fttrader_auto_update_enabled'];
    
    // Тест 3: Проверка расписания cron
    $next_run = wp_next_scheduled('contest_create_queues');
    $tests['Cron_Schedule'] = $next_run !== false;
    
    // Тест 4: Проверка регистрации хуков
    global $wp_filter;
    $tests['Auto_Update_Hook'] = isset($wp_filter['contest_create_queues']);
    $tests['Queue_Cleanup_Hook'] = isset($wp_filter['itx_queue_cleanup']);
    
    // Тест 5: Проверка кастомных интервалов cron
    $schedules = wp_get_schedules();
    $tests['Custom_Cron_Intervals'] = isset($schedules['contest_auto_update']);
    
    // Тест 6: Проверка таблиц базы данных
    global $wpdb;
    $table_name = $wpdb->prefix . 'contest_members';
    $tests['Database_Table'] = ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") == $table_name);
    
    // Тест 7: Проверка типа записи конкурсов
    $tests['Contest_Post_Type'] = post_type_exists('trader_contests');
    
    // Вывод результатов
    echo "<h2>Результаты тестов:</h2>";
    $passed = 0;
    $total = count($tests);
    
    foreach ($tests as $test_name => $result) {
        $status = $result ? "✅ PASSED" : "❌ FAILED";
        $color = $result ? "green" : "red";
        echo "<div style='color: $color; margin: 5px 0;'>";
        echo "<strong>$test_name:</strong> $status";
        echo "</div>";
        
        if ($result) {
            $passed++;
        }
    }
    
    echo "<hr>";
    echo "<h2>Итоговый результат:</h2>";
    $percentage = round(($passed / $total) * 100);
    $overall_color = $percentage >= 90 ? 'green' : ($percentage >= 70 ? 'orange' : 'red');
    echo "<div style='color: $overall_color; font-size: 18px; font-weight: bold;'>";
    echo "Тестов пройдено: $passed из $total ($percentage%)";
    echo "</div>";
    
    if ($percentage >= 90) {
        echo "<div style='color: green; font-size: 16px; margin-top: 10px;'>";
        echo "🎉 Плагин полностью автономен и готов к работе!";
        echo "</div>";
    } elseif ($percentage >= 70) {
        echo "<div style='color: orange; font-size: 16px; margin-top: 10px;'>";
        echo "⚠️ Плагин в основном автономен, но есть некоторые проблемы";
        echo "</div>";
    } else {
        echo "<div style='color: red; font-size: 16px; margin-top: 10px;'>";
        echo "❌ Плагин не готов к автономной работе";
        echo "</div>";
    }
    
    // Дополнительная информация
    echo "<h2>Дополнительная информация:</h2>";
    
    if ($next_run) {
        echo "<div><strong>Следующее автоматическое обновление:</strong> " . date('Y-m-d H:i:s', $next_run) . "</div>";
    }
    
    $last_run = get_option('contest_create_queues_last_run', 0);
    if ($last_run) {
        echo "<div><strong>Последнее автоматическое обновление:</strong> " . date('Y-m-d H:i:s', $last_run) . "</div>";
    }
    
    if (!empty($settings)) {
        echo "<div><strong>Интервал автоматического обновления:</strong> " . (isset($settings['fttrader_auto_update_interval']) ? $settings['fttrader_auto_update_interval'] . ' секунд' : 'не задан') . "</div>";
    }
    
    echo "</div>";
    
    // Рекомендации
    echo "<h2>Рекомендации для полной автономности:</h2>";
    echo "<ul>";
    
    if (!$tests['Auto_Update_Settings']) {
        echo "<li>Создайте настройки автоматического обновления в админке</li>";
    }
    
    if (!$tests['Cron_Schedule']) {
        echo "<li>Настройте расписание cron для автоматического обновления</li>";
    }
    
    if (!$tests['Database_Table']) {
        echo "<li>Создайте таблицы базы данных через активацию плагина</li>";
    }
    
    echo "<li>Убедитесь, что файл fortrader-settings.php отключен или удален</li>";
    echo "<li>Проверьте логи на наличие ошибок: wp-content/debug.log</li>";
    echo "</ul>";
}

// Запускаем тест
test_standalone_plugin(); 