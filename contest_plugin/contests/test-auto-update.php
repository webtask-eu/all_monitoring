<?php
/**
 * Тестовый файл для проверки автоматического обновления счетов
 * 
 * Как использовать:
 * 1. Загрузите этот файл в корневую директорию плагина
 * 2. Запустите файл из браузера: [URL вашего сайта]/wp-content/plugins/contests/test-auto-update.php
 * 3. Проверьте результаты и логи, которые будут выведены на экран
 */

// Подключаем WordPress
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
define('WP_DEBUG_DISPLAY', true);
@ini_set('display_errors', 1);

// Находим путь к файлу wp-load.php
$wp_load_path = '';
$current_dir = dirname(__FILE__);
for ($i = 0; $i < 10; $i++) {
    if (file_exists($current_dir . '/wp-load.php')) {
        $wp_load_path = $current_dir . '/wp-load.php';
        break;
    }
    $current_dir = dirname($current_dir);
}

if (empty($wp_load_path)) {
    die("Не удалось найти файл wp-load.php. Проверьте путь к файлу.");
}

// Подключаем WordPress
require_once($wp_load_path);

// Проверяем права доступа (только администраторы)
if (!current_user_can('manage_options')) {
    wp_die('Доступ запрещен');
}

// Заголовки для отображения результатов
header('Content-Type: text/html; charset=utf-8');
echo "<html><head><title>Тест автоматического обновления счетов</title></head><body>";
echo "<h1>Тест автоматического обновления счетов</h1>";

// Функция для вывода сообщений
function output_message($message, $type = 'info') {
    $color = ($type === 'error') ? 'red' : (($type === 'success') ? 'green' : 'blue');
    echo "<p style='color: {$color};'>{$message}</p>";
    flush();
}

// Тест 1: Проверка наличия необходимых классов
output_message("Тест 1: Проверка наличия необходимых классов", "info");

// Проверяем наличие класса Account_Updater
if (class_exists('Account_Updater')) {
    output_message("Класс Account_Updater найден", "success");
} else {
    output_message("Класс Account_Updater не найден. Проверьте, загружен ли плагин.", "error");
}

// Проверяем наличие класса Contest_Cron_Manager
if (class_exists('Contest_Cron_Manager')) {
    output_message("Класс Contest_Cron_Manager найден", "success");
} else {
    output_message("Класс Contest_Cron_Manager не найден. Проверьте, загружен ли плагин.", "error");
}

// Тест 2: Проверка регистрации хука
output_message("Тест 2: Проверка регистрации хука contest_create_queues", "info");

// Проверяем, зарегистрирован ли хук
global $wp_filter;
if (isset($wp_filter['contest_create_queues'])) {
    $actions = $wp_filter['contest_create_queues']->callbacks;
    $found = false;
    
    // Проходимся по всем колбэкам и ищем наш метод
    foreach ($actions as $priority => $callbacks) {
        foreach ($callbacks as $key => $callback) {
            if (is_array($callback['function']) && 
                $callback['function'][0] === 'Account_Updater' && 
                $callback['function'][1] === 'run_auto_update') {
                $found = true;
                output_message("Хук contest_create_queues для метода Account_Updater::run_auto_update найден (приоритет: {$priority})", "success");
                break 2;
            }
        }
    }
    
    if (!$found) {
        output_message("Хук contest_create_queues зарегистрирован, но обработчик Account_Updater::run_auto_update не найден", "error");
    }
} else {
    output_message("Хук contest_create_queues не зарегистрирован", "error");
}

// Тест 3: Проверка планирования WP Cron
output_message("Тест 3: Проверка планирования WP Cron", "info");

$next_scheduled = wp_next_scheduled('contest_create_queues');
if ($next_scheduled) {
    $time_diff = $next_scheduled - time();
    $human_readable = human_time_diff(time(), $next_scheduled);
    output_message("Следующее выполнение хука запланировано на " . date('Y-m-d H:i:s', $next_scheduled) . " (через {$human_readable})", "success");
} else {
    output_message("Хук contest_create_queues не запланирован в WP Cron", "error");
    
    // Пробуем восстановить расписание
    output_message("Пытаемся восстановить расписание...", "info");
    Contest_Cron_Manager::ensure_scheduled_events();
    
    // Проверяем еще раз
    $next_scheduled = wp_next_scheduled('contest_create_queues');
    if ($next_scheduled) {
        output_message("Расписание успешно восстановлено. Следующее выполнение: " . date('Y-m-d H:i:s', $next_scheduled), "success");
    } else {
        output_message("Не удалось восстановить расписание", "error");
    }
}

// Тест 4: Проверка настроек автообновления
output_message("Тест 4: Проверка настроек автообновления", "info");

$settings = get_option('fttrader_auto_update_settings', []);
echo "<pre>Настройки автообновления: " . print_r($settings, true) . "</pre>";

$auto_update_enabled = isset($settings['fttrader_auto_update_enabled']) ? $settings['fttrader_auto_update_enabled'] : false;
if ($auto_update_enabled) {
    output_message("Автоматическое обновление ВКЛЮЧЕНО", "success");
} else {
    output_message("Автоматическое обновление ОТКЛЮЧЕНО. Включите его в настройках плагина.", "error");
}

// Тест 5: Проверка времени последнего запуска
output_message("Тест 5: Проверка времени последнего запуска", "info");

$last_run = get_option('contest_create_queues_last_run', 0);
if ($last_run > 0) {
    $time_passed = time() - $last_run;
    $human_readable = human_time_diff($last_run, time());
    output_message("Последний запуск был " . date('Y-m-d H:i:s', $last_run) . " ({$human_readable} назад)", "success");
} else {
    output_message("Информация о последнем запуске не найдена", "error");
}

// Тест 6: Тестовый вызов метода run_auto_update с отладкой
output_message("Тест 6: Тестовый вызов метода run_auto_update с отладкой", "info");

// Добавляем временный фильтр для логирования активности
add_action('contest_cron_log', function($message) {
    output_message("LOG: " . $message);
}, 10, 1);

// Проверяем наличие активных конкурсов
global $wpdb;
$contests_table = $wpdb->prefix . 'contests';
$active_contests_count = $wpdb->get_var("SELECT COUNT(*) FROM {$contests_table} WHERE status = 'active'");

if ($active_contests_count > 0) {
    output_message("Найдено активных конкурсов: {$active_contests_count}", "success");
} else {
    output_message("Не найдено активных конкурсов. Автоматическое обновление не будет работать без активных конкурсов.", "error");
}

// Временно добавим фильтр для отслеживания SQL-запросов (только для отладки)
if (defined('WP_DEBUG') && WP_DEBUG) {
    add_filter('query', function($query) {
        if (strpos($query, 'contests') !== false || strpos($query, 'contest_members') !== false) {
            output_message("SQL: " . esc_html($query), "info");
        }
        return $query;
    });
}

// Выполняем метод run_auto_update с отслеживанием ошибок
try {
    output_message("Запускаем Account_Updater::run_auto_update()...", "info");
    ob_start();
    Account_Updater::run_auto_update();
    $output = ob_get_clean();
    
    if (!empty($output)) {
        output_message("Вывод метода run_auto_update:", "info");
        echo "<pre>{$output}</pre>";
    }
    
    // Проверяем время последнего запуска после выполнения метода
    $new_last_run = get_option('contest_create_queues_last_run', 0);
    if ($new_last_run > $last_run) {
        output_message("Метод run_auto_update успешно выполнен и обновил время последнего запуска", "success");
    } else {
        output_message("Метод run_auto_update выполнен, но время последнего запуска не обновлено", "error");
    }
} catch (Exception $e) {
    output_message("Ошибка при выполнении метода run_auto_update: " . $e->getMessage(), "error");
    echo "<pre>Трассировка: " . $e->getTraceAsString() . "</pre>";
}

// Тест 7: Проверка состояния системы WP Cron
output_message("Тест 7: Проверка состояния системы WP Cron", "info");

$cron_status = Contest_Cron_Manager::check_cron_status();
echo "<pre>Статус WP Cron: " . print_r($cron_status, true) . "</pre>";

if (!$cron_status['is_cron_enabled']) {
    output_message("WP Cron отключен на сервере (DISABLE_WP_CRON = true). Необходимо настроить системный Cron для вызова wp-cron.php", "info");
}

// Тест 8: Проверка логов выполнения Cron
output_message("Тест 8: Проверка логов выполнения Cron", "info");

$executions = get_option('contest_cron_executions', []);
if (!empty($executions)) {
    echo "<h3>Последние запуски Cron:</h3>";
    echo "<table border='1' style='border-collapse: collapse;'>";
    echo "<tr><th>Дата и время</th><th>ID запроса</th><th>Источник вызова</th></tr>";
    
    foreach ($executions as $execution) {
        echo "<tr>";
        echo "<td>" . $execution['timestamp'] . "</td>";
        echo "<td>" . $execution['request_id'] . "</td>";
        echo "<td>" . $execution['backtrace'] . "</td>";
        echo "</tr>";
    }
    
    echo "</table>";
} else {
    output_message("Логи выполнения Cron не найдены", "error");
}

// Тест 9: Проверка параметров системы
output_message("Тест 9: Проверка параметров системы", "info");
echo "<ul>";
echo "<li>PHP Version: " . PHP_VERSION . "</li>";
echo "<li>WordPress Version: " . get_bloginfo('version') . "</li>";
echo "<li>Server Time: " . date('Y-m-d H:i:s') . "</li>";
echo "<li>WordPress Time: " . current_time('Y-m-d H:i:s') . "</li>";
echo "<li>DISABLE_WP_CRON: " . (defined('DISABLE_WP_CRON') && DISABLE_WP_CRON ? 'true' : 'false') . "</li>";
echo "</ul>";

// Заключение
output_message("Тестирование завершено. Проверьте результаты выше для определения проблемы с автоматическим обновлением счетов.", "info");

// Предложение решений
echo "<h2>Возможные решения:</h2>";
echo "<ol>";
echo "<li>Если WP Cron отключен (DISABLE_WP_CRON = true), убедитесь, что системный Cron корректно настроен для вызова wp-cron.php.</li>";
echo "<li>Проверьте, включено ли автоматическое обновление в настройках плагина.</li>";
echo "<li>Если хук не зарегистрирован, проверьте файл class-account-updater.php и убедитесь, что строка с add_action('contest_create_queues', ...) не отсутствует или не закомментирована.</li>";
echo "<li>Если в системе нет активных конкурсов, метод run_auto_update не будет обновлять счета.</li>";
echo "<li>Проверьте, что настройки интервала обновления корректны в разделе настроек плагина.</li>";
echo "<li>Проверьте журнал ошибок PHP для выявления возможных ошибок при выполнении метода run_auto_update.</li>";
echo "</ol>";

echo "</body></html>"; 