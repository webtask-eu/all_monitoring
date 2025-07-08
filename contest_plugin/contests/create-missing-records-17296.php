<?php
/**
 * Создание всех недостающих записей для счета 17296
 */

// Загружаем WordPress
require_once dirname(dirname(dirname(dirname(__FILE__)))) . '/wp-config.php';

echo "=== Создание всех недостающих записей для счета 17296 ===\n";

global $wpdb;
$members_table = $wpdb->prefix . 'contest_members';
$history_table = $wpdb->prefix . 'contest_members_history';

$account_id = 17296;

// Получаем данные счета
$account = $wpdb->get_row("SELECT * FROM {$members_table} WHERE id = {$account_id}");

if (!$account) {
    echo "❌ Счет {$account_id} не найден\n";
    exit;
}

echo "Счет найден: {$account->account_number}\n";
echo "Дата регистрации: {$account->registration_date}\n";

// Поля для создания начальных записей
$fields_to_create = [
    'h_count' => $account->orders_history_total,
    'pass' => $account->password,
    'i_firma' => $account->broker,
    'i_fio' => $account->name,
    'i_dr' => $account->account_type,
    'i_cur' => $account->currency,
    'srvMt4' => $account->server,
    'connection_status' => $account->connection_status
];

$created_count = 0;

foreach ($fields_to_create as $field_name => $value) {
    // Проверяем, есть ли уже записи
    $existing = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$history_table} WHERE account_id = %d AND field_name = %s",
        $account_id, $field_name
    ));
    
    if ($existing == 0 && !empty($value)) {
        echo "Создаем запись для {$field_name}: {$value}\n";
        
        $result = $wpdb->insert(
            $history_table,
            [
                'account_id' => $account_id,
                'field_name' => $field_name,
                'old_value' => '',
                'new_value' => $value,
                'change_percent' => null,
                'change_date' => $account->registration_date ?: current_time('mysql')
            ],
            ['%d', '%s', '%s', '%s', '%f', '%s']
        );
        
        if ($result) {
            $created_count++;
            echo "✅ Создано!\n";
        } else {
            echo "❌ Ошибка: " . $wpdb->last_error . "\n";
        }
    } else {
        echo "Пропускаем {$field_name}: ";
        if ($existing > 0) {
            echo "уже есть записи ({$existing})\n";
        } else {
            echo "пустое значение\n";
        }
    }
}

// Создаем запись для active_orders_volume если нет
$volume_existing = $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$history_table} WHERE account_id = %d AND field_name = 'active_orders_volume'",
    $account_id
));

if ($volume_existing == 0) {
    echo "Создаем запись для active_orders_volume: 0\n";
    
    $result = $wpdb->insert(
        $history_table,
        [
            'account_id' => $account_id,
            'field_name' => 'active_orders_volume',
            'old_value' => '',
            'new_value' => '0',
            'change_percent' => null,
            'change_date' => $account->registration_date ?: current_time('mysql')
        ],
        ['%d', '%s', '%s', '%s', '%f', '%s']
    );
    
    if ($result) {
        $created_count++;
        echo "✅ Создано!\n";
    }
}

echo "\n=== Итого создано записей: {$created_count} ===\n";

// Показываем все записи для счета
echo "\n=== Все записи в истории для счета {$account_id} ===\n";
$all_records = $wpdb->get_results($wpdb->prepare(
    "SELECT field_name, COUNT(*) as count FROM {$history_table} WHERE account_id = %d GROUP BY field_name ORDER BY field_name",
    $account_id
));

foreach ($all_records as $record) {
    echo "{$record->field_name}: {$record->count} записей\n";
}

echo "\nГотово!\n";
?> 