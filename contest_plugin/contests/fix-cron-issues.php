<?php
/**
 * Скрипт для исправления проблем с автообновлением и cron
 * Запуск: добавить ?page=fix-cron в URL админки
 */

// Защита от прямого доступа
if (!defined('ABSPATH')) {
    die('Прямой доступ запрещен');
}

// Подключаем необходимые классы
require_once plugin_dir_path(__FILE__) . 'includes/class-account-updater.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-cron-manager.php';

function fix_cron_auto_update_issues() {
    echo "=== ИСПРАВЛЕНИЕ ПРОБЛЕМ АВТООБНОВЛЕНИЯ ===\n\n";
    
    $issues_fixed = 0;
    
    // 1. Очистка дублированных событий cron
    echo "1. Очистка дублированных событий cron:\n";
    $cleaned = Contest_Cron_Manager::clean_duplicate_events();
    echo "- Удалено дублированных событий: {$cleaned}\n";
    if ($cleaned > 0) {
        $issues_fixed++;
    }
    
    // 2. Проверка и включение автообновления в настройках
    echo "\n2. Проверка настроек автообновления:\n";
    $auto_settings = get_option('fttrader_auto_update_settings', []);
    $enabled = isset($auto_settings['fttrader_auto_update_enabled']) ? $auto_settings['fttrader_auto_update_enabled'] : false;
    
    if (!$enabled) {
        echo "- Автообновление отключено. Включаю...\n";
        $auto_settings['fttrader_auto_update_enabled'] = true;
        // Устанавливаем разумные настройки по умолчанию
        if (!isset($auto_settings['fttrader_auto_update_interval'])) {
            $auto_settings['fttrader_auto_update_interval'] = 60; // 60 минут
        }
        if (!isset($auto_settings['fttrader_batch_size'])) {
            $auto_settings['fttrader_batch_size'] = 2; // 2 счета в пакете
        }
        if (!isset($auto_settings['fttrader_min_update_interval'])) {
            $auto_settings['fttrader_min_update_interval'] = 5; // 5 минут между обновлениями счета
        }
        
        update_option('fttrader_auto_update_settings', $auto_settings);
        echo "- Автообновление включено с настройками по умолчанию\n";
        $issues_fixed++;
    } else {
        echo "- Автообновление уже включено\n";
    }
    
    // 3. Восстановление расписания cron
    echo "\n3. Восстановление расписания cron:\n";
    $next_run = wp_next_scheduled('contest_create_queues');
    if (!$next_run) {
        echo "- Расписание cron отсутствует. Восстанавливаю...\n";
        Contest_Cron_Manager::activate();
        $next_run = wp_next_scheduled('contest_create_queues');
        if ($next_run) {
            echo "- Расписание cron восстановлено. Следующий запуск: " . date('d.m.Y H:i:s', $next_run) . "\n";
            $issues_fixed++;
        } else {
            echo "- ОШИБКА: Не удалось восстановить расписание cron\n";
        }
    } else {
        echo "- Расписание cron уже настроено. Следующий запуск: " . date('d.m.Y H:i:s', $next_run) . "\n";
    }
    
    // 4. Очистка зависших очередей
    echo "\n4. Очистка зависших очередей:\n";
    $cleared_result = Account_Updater::clear_all_queues();
    $cleared_queues = count($cleared_result['cleared_queues']);
    echo "- Очищено зависших очередей: {$cleared_queues}\n";
    if ($cleared_queues > 0) {
        echo "- Очищено списков активных очередей: " . count($cleared_result['cleared_lists']) . "\n";
        echo "- Очищено опций статусов: " . count($cleared_result['cleared_status_options']) . "\n";
        echo "- Очищено опций очередей: " . count($cleared_result['cleared_queue_options']) . "\n";
        $issues_fixed++;
    }
    
    // 5. Проверка доступности API
    echo "\n5. Проверка API сервера мониторинга:\n";
    $api_ip = get_option('ft_server_api_ip', '');
    $api_port = get_option('ft_server_api_port', '');
    
    if (empty($api_ip) || empty($api_port)) {
        echo "- ОШИБКА: Не настроены параметры API сервера (IP: '{$api_ip}', порт: '{$api_port}')\n";
        echo "  Настройте IP и порт сервера мониторинга в настройках плагина\n";
    } else {
        echo "- Настройки API: {$api_ip}:{$api_port}\n";
        
        // Простая проверка доступности
        $context = stream_context_create([
            'http' => [
                'timeout' => 5,
                'method' => 'GET'
            ]
        ]);
        
        $api_url = "http://{$api_ip}:{$api_port}/status";
        $response = @file_get_contents($api_url, false, $context);
        
        if ($response !== false) {
            echo "- Сервер мониторинга доступен\n";
        } else {
            echo "- ПРЕДУПРЕЖДЕНИЕ: Сервер мониторинга может быть недоступен по адресу {$api_url}\n";
            echo "  Это может быть причиной таймаутов очередей\n";
        }
    }
    
    // 6. Проверка настроек WP Cron для внешнего cron
    echo "\n6. Проверка конфигурации WP Cron:\n";
    $wp_cron_disabled = defined('DISABLE_WP_CRON') && DISABLE_WP_CRON;
    echo "- DISABLE_WP_CRON: " . ($wp_cron_disabled ? 'TRUE (внешний cron)' : 'FALSE (встроенный cron)') . "\n";
    
    if ($wp_cron_disabled) {
        echo "- Используется внешний cron. Убедитесь, что настроена задача:\n";
        echo "  */5 * * * * curl -s " . site_url('wp-cron.php') . " > /dev/null 2>&1\n";
        echo "  или\n";
        echo "  */5 * * * * wget -q -O - " . site_url('wp-cron.php') . " > /dev/null 2>&1\n";
        
        // Проверяем последний вызов wp-cron.php
        $cron_lock = get_transient('doing_cron');
        if ($cron_lock) {
            echo "- Cron активен (выполняется задача)\n";
        } else {
            echo "- Cron не активен\n";
        }
    }
    
    // 7. Рекомендации по настройке
    echo "\n7. Рекомендации:\n";
    if ($issues_fixed > 0) {
        echo "- Исправлено проблем: {$issues_fixed}\n";
    }
    
    $interval = isset($auto_settings['fttrader_auto_update_interval']) ? $auto_settings['fttrader_auto_update_interval'] : 60;
    $batch_size = isset($auto_settings['fttrader_batch_size']) ? $auto_settings['fttrader_batch_size'] : 2;
    
    echo "- Текущий интервал автообновления: {$interval} минут\n";
    echo "- Текущий размер пакета: {$batch_size} счетов\n";
    echo "- Тайм-аут очередей увеличен до 15 минут\n";
    
    if ($interval < 60) {
        echo "- РЕКОМЕНДАЦИЯ: Увеличьте интервал автообновления до 60+ минут для стабильности\n";
    }
    
    if ($batch_size > 3) {
        echo "- РЕКОМЕНДАЦИЯ: Уменьшите размер пакета до 2-3 счетов для предотвращения таймаутов\n";
    }
    
    // 8. Принудительный запуск cron для проверки
    echo "\n8. Тестирование системы:\n";
    echo "- Принудительно запускаю WP Cron...\n";
    spawn_cron();
    
    // Ждем немного и проверяем результат
    sleep(2);
    
    $updated_next_run = wp_next_scheduled('contest_create_queues');
    if ($updated_next_run) {
        echo "- Тест пройден. Следующий запуск: " . date('d.m.Y H:i:s', $updated_next_run) . "\n";
    } else {
        echo "- ОШИБКА: Cron не работает должным образом\n";
    }
    
    echo "\n=== ИСПРАВЛЕНИЕ ЗАВЕРШЕНО ===\n";
    
    if ($issues_fixed == 0) {
        echo "✅ Серьезных проблем не обнаружено\n";
    } else {
        echo "🔧 Исправлено проблем: {$issues_fixed}\n";
        echo "🔄 Рекомендуется перезагрузить страницу и проверить работу автообновления\n";
    }
    
    return $issues_fixed;
}

// Добавляем функцию для вызова через админку
if (is_admin() && isset($_GET['page']) && $_GET['page'] === 'fix-cron') {
    add_action('admin_init', function() {
        if (!current_user_can('manage_options')) {
            wp_die('У вас нет прав для выполнения этого действия.');
        }
        
        header('Content-Type: text/plain; charset=utf-8');
        fix_cron_auto_update_issues();
        exit;
    });
} 