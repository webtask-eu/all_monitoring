<?php
/**
 * Упрощенный скрипт для создания недостающих начальных записей
 * Без проверки авторизации для CLI запуска
 */

// Загружаем WordPress
require_once dirname(dirname(dirname(dirname(__FILE__)))) . '/wp-config.php';

echo "=== Создание недостающих начальных записей ===\n";

global $wpdb;
$members_table = $wpdb->prefix . 'contest_members';
$history_table = $wpdb->prefix . 'contest_members_history';

// Проверяем конкретный счет 17296
echo "Проверяем счет 17296...\n";
$account_17296 = $wpdb->get_row("SELECT * FROM {$members_table} WHERE id = 17296");

if ($account_17296) {
    echo "Счет найден: {$account_17296->account_number}, leverage: {$account_17296->leverage}\n";
    
    // Проверяем, есть ли записи о leverage
    $leverage_records = $wpdb->get_var("SELECT COUNT(*) FROM {$history_table} WHERE account_id = 17296 AND field_name = 'leverage'");
    echo "Записей о leverage: {$leverage_records}\n";
    
    if ($leverage_records == 0 && $account_17296->leverage > 0) {
        echo "Создаем запись о leverage...\n";
        
        $result = $wpdb->insert(
            $history_table,
            [
                'account_id' => 17296,
                'field_name' => 'leverage',
                'old_value' => '',
                'new_value' => $account_17296->leverage,
                'change_percent' => null,
                'change_date' => $account_17296->registration_date ?: current_time('mysql')
            ],
            ['%d', '%s', '%s', '%s', '%f', '%s']
        );
        
        if ($result) {
            echo "✅ Запись создана!\n";
        } else {
            echo "❌ Ошибка создания записи: " . $wpdb->last_error . "\n";
        }
    } else {
        echo "Запись уже существует или leverage = 0\n";
    }
} else {
    echo "❌ Счет 17296 не найден\n";
}

// Общая статистика
echo "\n=== Общая статистика ===\n";
$total_accounts = $wpdb->get_var("SELECT COUNT(*) FROM {$members_table}");
echo "Всего счетов: {$total_accounts}\n";

$accounts_without_leverage = $wpdb->get_var("
    SELECT COUNT(m.id)
    FROM {$members_table} m
    LEFT JOIN {$history_table} h ON m.id = h.account_id AND h.field_name = 'leverage'
    WHERE h.id IS NULL AND m.leverage > 0
");
echo "Счетов без записей о leverage: {$accounts_without_leverage}\n";

echo "\nГотово!\n";
?> 