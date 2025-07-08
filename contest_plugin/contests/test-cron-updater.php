<?php
/**
 * Тест-фикс для обновления счетов конкурса
 * 
 * Этот файл содержит модифицированную версию функции Account_Updater::run_auto_update 
 * с дополнительным логированием, который можно использовать для отладки и восстановления 
 * работы автоматического обновления счетов.
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
echo "<html><head><title>Тест-фикс для обновления счетов</title></head><body>";
echo "<h1>Тест-фикс для обновления счетов</h1>";

// Функция для логирования
function debug_log($message) {
    echo "<p>" . htmlspecialchars($message) . "</p>";
    error_log($message);
}

// Проверяем, существует ли класс Account_Updater
if (!class_exists('Account_Updater')) {
    die("<p style='color: red;'>Класс Account_Updater не найден. Плагин не загружен.</p>");
}

/**
 * Патч для метода run_auto_update с расширенным логированием
 */
function patched_run_auto_update() {
    global $wpdb;
    
    debug_log("Запуск patched_run_auto_update в " . date('Y-m-d H:i:s'));
    
    // Получаем время последнего автообновления
    $last_run = get_option('contest_create_queues_last_run', 0);
    $now = time();
    
    debug_log("Последний запуск: " . ($last_run ? date('Y-m-d H:i:s', $last_run) : 'Никогда'));
    debug_log("Текущее время: " . date('Y-m-d H:i:s', $now));
    
    // Получаем настройки автообновления
    $settings = get_option('fttrader_auto_update_settings', []);
    debug_log("Настройки автообновления: " . print_r($settings, true));
    
    $interval = isset($settings['fttrader_auto_update_interval']) ?
        intval($settings['fttrader_auto_update_interval']) : 60; // По умолчанию 60 минут
    
    debug_log("Интервал обновления: {$interval} минут");
    
    // Проверяем, прошло ли достаточно времени с последнего обновления
    if (($now - $last_run) < ($interval * 60)) {
        debug_log("Недостаточно времени прошло с последнего обновления. Пропускаем.");
        debug_log("Прошло: " . ($now - $last_run) . " секунд. Требуется: " . ($interval * 60) . " секунд.");
        return;
    }
    
    // Обновляем время последнего запуска
    update_option('contest_create_queues_last_run', $now);
    debug_log("Обновлено время последнего запуска на " . date('Y-m-d H:i:s', $now));
    
    // Выбираем активные конкурсы и группируем счета по конкурсам
    $table_name = $wpdb->prefix . 'contest_members';
    $contests_table = $wpdb->prefix . 'contests';
    
    debug_log("Ищем активные конкурсы в таблице {$contests_table}");
    
    // Получаем ID активных конкурсов
    $active_contests = $wpdb->get_col(
        "SELECT id FROM $contests_table WHERE status = 'active'"
    );
    
    debug_log("Найдено активных конкурсов: " . count($active_contests));
    debug_log("IDs активных конкурсов: " . print_r($active_contests, true));
    
    if (empty($active_contests)) {
        debug_log("Нет активных конкурсов. Обновление не требуется.");
        return;
    }
    
    // Проверим наличие класса Account_Updater и метода init_queue
    if (!method_exists('Account_Updater', 'init_queue')) {
        debug_log("ОШИБКА: Метод Account_Updater::init_queue не существует!");
        return;
    }
    
    debug_log("Начинаем создание очередей обновления для каждого конкурса");
    
    // Для каждого активного конкурса создаем отдельную очередь обновления
    foreach ($active_contests as $contest_id) {
        debug_log("Обработка конкурса ID: {$contest_id}");
        
        // Получаем все счета данного конкурса
        $query = $wpdb->prepare(
            "SELECT id FROM $table_name WHERE contest_id = %d AND status = 'active'",
            $contest_id
        );
        debug_log("SQL запрос: {$query}");
        
        $contest_accounts = $wpdb->get_col($query);
        debug_log("Найдено активных счетов для конкурса {$contest_id}: " . count($contest_accounts));
        
        if (!empty($contest_accounts)) {
            debug_log("IDs счетов для конкурса {$contest_id}: " . implode(", ", $contest_accounts));
            
            // Инициализируем очередь обновления для этого конкурса
            $result = Account_Updater::init_queue($contest_accounts, true, $contest_id);
            debug_log("Результат инициализации очереди: " . print_r($result, true));
        } else {
            debug_log("Нет активных счетов для конкурса {$contest_id}. Пропускаем.");
        }
    }
    
    debug_log("Выполнение patched_run_auto_update завершено в " . date('Y-m-d H:i:s'));
}

// Опция для принудительного запуска
if (isset($_GET['force_run']) && $_GET['force_run'] === '1') {
    echo "<h2>Принудительный запуск модифицированной функции обновления счетов</h2>";
    
    // Запускаем модифицированную функцию
    patched_run_auto_update();
    
    echo "<p><a href='?'>Вернуться к информации</a></p>";
} else {
    echo "<h2>Информация о текущем состоянии</h2>";
    
    // Получаем настройки автообновления
    $settings = get_option('fttrader_auto_update_settings', []);
    echo "<h3>Настройки автообновления:</h3>";
    echo "<pre>" . print_r($settings, true) . "</pre>";
    
    // Получаем время последнего запуска
    $last_run = get_option('contest_create_queues_last_run', 0);
    echo "<h3>Информация о последнем запуске:</h3>";
    if ($last_run > 0) {
        echo "<p>Последний запуск: " . date('Y-m-d H:i:s', $last_run) . " (" . human_time_diff($last_run, time()) . " назад)</p>";
    } else {
        echo "<p>Информация о последнем запуске не найдена</p>";
    }
    
    // Проверяем активные конкурсы
    global $wpdb;
    $contests_table = $wpdb->prefix . 'contests';
    $active_contests = $wpdb->get_results("SELECT id, name FROM $contests_table WHERE status = 'active'");
    
    echo "<h3>Активные конкурсы:</h3>";
    if (!empty($active_contests)) {
        echo "<ul>";
        foreach ($active_contests as $contest) {
            $members_count = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}contest_members WHERE contest_id = %d AND status = 'active'",
                $contest->id
            ));
            echo "<li>ID: {$contest->id}, Название: {$contest->name}, Активных счетов: {$members_count}</li>";
        }
        echo "</ul>";
    } else {
        echo "<p>Активных конкурсов не найдено</p>";
    }
    
    // Проверяем логи Cron
    $executions = get_option('contest_cron_executions', []);
    echo "<h3>Последние запуски Cron:</h3>";
    if (!empty($executions)) {
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
        echo "<p>Логи выполнения Cron не найдены</p>";
    }
    
    // Проверяем хук
    global $wp_filter;
    echo "<h3>Статус хука contest_create_queues:</h3>";
    if (isset($wp_filter['contest_create_queues'])) {
        $actions = $wp_filter['contest_create_queues']->callbacks;
        $found = false;
        
        foreach ($actions as $priority => $callbacks) {
            foreach ($callbacks as $key => $callback) {
                if (is_array($callback['function']) && 
                    $callback['function'][0] === 'Account_Updater' && 
                    $callback['function'][1] === 'run_auto_update') {
                    echo "<p style='color: green;'>Хук contest_create_queues для метода Account_Updater::run_auto_update найден (приоритет: {$priority})</p>";
                    $found = true;
                    break 2;
                }
            }
        }
        
        if (!$found) {
            echo "<p style='color: red;'>Хук contest_create_queues зарегистрирован, но обработчик Account_Updater::run_auto_update не найден</p>";
        }
    } else {
        echo "<p style='color: red;'>Хук contest_create_queues не зарегистрирован</p>";
    }
    
    // Форма для принудительного запуска
    echo "<h3>Запустить модифицированную функцию обновления:</h3>";
    echo "<p><a href='?force_run=1' class='button'>Принудительно запустить обновление</a></p>";
    
    // Возможные решения проблемы
    echo "<h2>Возможные решения проблемы:</h2>";
    echo "<ol>";
    echo "<li>Если отсутствует хук contest_create_queues, добавьте в файл конкурса следующий код:";
    echo "<pre>add_action('contest_create_queues', ['Account_Updater', 'run_auto_update']);</pre></li>";
    
    echo "<li>Если у вас нет активных конкурсов, функция обновления не будет работать. Измените статус хотя бы одного конкурса на 'active'.</li>";
    
    echo "<li>Если в настройках отключено автоматическое обновление, включите его:";
    echo "<pre>update_option('fttrader_auto_update_settings', array_merge(get_option('fttrader_auto_update_settings', []), ['fttrader_auto_update_enabled' => true]));</pre></li>";
    
    echo "<li>Если WP Cron не работает, настройте системный Cron для регулярного вызова wp-cron.php:";
    echo "<pre>*/15 * * * * wget -q -O /dev/null 'http://example.com/wp-cron.php?doing_wp_cron' > /dev/null 2>&1</pre></li>";
    
    echo "<li>Если функция run_auto_update не запускается из-за интервала, попробуйте сбросить время последнего запуска:";
    echo "<pre>delete_option('contest_create_queues_last_run');</pre></li>";
    echo "</ol>";
}

echo "</body></html>"; 