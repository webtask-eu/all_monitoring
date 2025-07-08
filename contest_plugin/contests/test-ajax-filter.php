<?php
/**
 * Тестовый скрипт для проверки AJAX фильтрации истории
 */

// Загружаем WordPress
require_once dirname(dirname(dirname(dirname(__FILE__)))) . '/wp-config.php';
require_once 'includes/class-account-history.php';

echo "=== Тест AJAX фильтрации истории ===\n";

$account_id = 17296;
$field = 'leverage';
$period = 'all';
$sort = 'desc';
$page = 1;
$per_page = 10;

echo "Параметры запроса:\n";
echo "- account_id: {$account_id}\n";
echo "- field: {$field}\n";
echo "- period: {$period}\n";
echo "- sort: {$sort}\n";
echo "- page: {$page}\n";
echo "- per_page: {$per_page}\n\n";

// Создаем экземпляр класса и выполняем запрос
$history = new Account_History();
$result = $history->get_filtered_history($account_id, $field, $period, $sort, $page, $per_page);

echo "=== Результат запроса ===\n";
echo "Общее количество записей: {$result['total_items']}\n";
echo "Всего страниц: {$result['total_pages']}\n";
echo "Текущая страница: {$result['current_page']}\n";
echo "Записей на странице: {$result['per_page']}\n\n";

if (empty($result['results'])) {
    echo "❌ РЕЗУЛЬТАТ ПУСТОЙ!\n\n";
    
    // Проверяем, есть ли вообще записи для этого счета
    echo "=== Проверка всех записей для счета {$account_id} ===\n";
    $all_result = $history->get_filtered_history($account_id, '', 'all', 'desc', 1, 100);
    echo "Всего записей для счета: {$all_result['total_items']}\n";
    
    if (!empty($all_result['results'])) {
        echo "Доступные поля:\n";
        $fields = array_unique(array_column($all_result['results'], 'field_name'));
        foreach ($fields as $field_name) {
            echo "- {$field_name}\n";
        }
        
        echo "\nПоследние 5 записей:\n";
        foreach (array_slice($all_result['results'], 0, 5) as $record) {
            echo "- {$record->field_name}: {$record->old_value} → {$record->new_value} ({$record->change_date})\n";
        }
    }
} else {
    echo "✅ Найдены записи!\n\n";
    foreach ($result['results'] as $record) {
        echo "Запись ID: {$record->id}\n";
        echo "Поле: {$record->field_name}\n";
        echo "Старое значение: '{$record->old_value}'\n";
        echo "Новое значение: '{$record->new_value}'\n";
        echo "Процент изменения: {$record->change_percent}\n";
        echo "Дата изменения: {$record->change_date}\n";
        echo "---\n";
    }
}

// Тестируем другие фильтры
echo "\n=== Тест других фильтров ===\n";
$test_fields = ['h_count', 'pass', 'i_firma', 'connection_status'];

foreach ($test_fields as $test_field) {
    $test_result = $history->get_filtered_history($account_id, $test_field, 'all', 'desc', 1, 10);
    echo "{$test_field}: {$test_result['total_items']} записей\n";
}

echo "\nГотово!\n";
?> 