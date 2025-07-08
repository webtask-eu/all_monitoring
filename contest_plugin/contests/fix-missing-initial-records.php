<?php
/**
 * Скрипт для создания недостающих начальных записей в истории изменений
 * Запускать ОДИН РАЗ для исправления существующих счетов
 */

// Загружаем WordPress (исправленный путь)
require_once dirname(dirname(dirname(dirname(__FILE__)))) . '/wp-config.php';
require_once 'includes/class-api-handler.php';

// Проверяем права доступа
if (!is_user_logged_in() || !current_user_can('manage_options')) {
    die('Недостаточно прав доступа');
}

echo "<h2>Создание недостающих начальных записей в истории изменений</h2>\n";
echo "<p>Начинаем обработку...</p>\n";
flush();

// Включаем буферизацию для немедленного вывода
ob_implicit_flush(true);

global $wpdb;
$members_table = $wpdb->prefix . 'contest_members';
$history_table = $wpdb->prefix . 'contest_members_history';

// Получаем общую статистику
$total_accounts = $wpdb->get_var("SELECT COUNT(*) FROM {$members_table}");
echo "<p>Всего счетов для обработки: <strong>{$total_accounts}</strong></p>\n";

// Получаем счета, у которых нет записей о leverage
$accounts_without_leverage = $wpdb->get_results("
    SELECT m.id, m.account_number, m.leverage, m.registration_date
    FROM {$members_table} m
    LEFT JOIN {$history_table} h ON m.id = h.account_id AND h.field_name = 'leverage'
    WHERE h.id IS NULL AND m.leverage > 0
    ORDER BY m.registration_date DESC
");

$count_without_leverage = count($accounts_without_leverage);
echo "<p>Счетов без записей о кредитном плече: <strong>{$count_without_leverage}</strong></p>\n";

if ($count_without_leverage > 0) {
    echo "<h3>Примеры счетов без записей о leverage:</h3>\n";
    echo "<ul>\n";
    foreach (array_slice($accounts_without_leverage, 0, 5) as $account) {
        echo "<li>ID: {$account->id}, Номер: {$account->account_number}, Leverage: {$account->leverage}, Дата: {$account->registration_date}</li>\n";
    }
    echo "</ul>\n";
}

echo "<p><strong>Начинаем создание недостающих записей...</strong></p>\n";
flush();

// Вызываем функцию создания недостающих записей
$start_time = time();
create_missing_initial_records();
$end_time = time();

echo "<p>✅ <strong>Обработка завершена!</strong></p>\n";
echo "<p>Время выполнения: " . ($end_time - $start_time) . " секунд</p>\n";

// Проверяем результат
$accounts_with_leverage_after = $wpdb->get_var("
    SELECT COUNT(DISTINCT m.id) 
    FROM {$members_table} m
    JOIN {$history_table} h ON m.id = h.account_id 
    WHERE h.field_name = 'leverage' AND m.leverage > 0
");

echo "<p>Счетов с записями о leverage после обработки: <strong>{$accounts_with_leverage_after}</strong></p>\n";

// Проверяем конкретный счет 17296
$account_17296_records = $wpdb->get_var("
    SELECT COUNT(*) FROM {$history_table} 
    WHERE account_id = 17296 AND field_name = 'leverage'
");

echo "<p>Записей о leverage для счета 17296: <strong>{$account_17296_records}</strong></p>\n";

if ($account_17296_records > 0) {
    echo "<p>🎉 <strong>Счет 17296 теперь имеет записи о кредитном плече!</strong></p>\n";
    echo "<p>Попробуйте фильтр 'Кредитное плечо' на странице счета.</p>\n";
} else {
    echo "<p>⚠️ Для счета 17296 записи не созданы. Проверьте, есть ли у него значение leverage в БД.</p>\n";
}

echo "<h3>Готово!</h3>\n";
echo "<p>Теперь все фильтры в истории изменений должны показывать данные.</p>\n";
?> 