<?php
/**
 * Тестовый скрипт для проверки защиты дисквалифицированных счетов при обновлении
 */

// Подключение WordPress
$wp_load_path = '/var/www/vhosts/fortraders.org/httpdocs/wp-load.php';
if (!file_exists($wp_load_path)) {
    die("WordPress не найден по пути: $wp_load_path\n");
}

require_once $wp_load_path;

// Подключаем API handler
require_once dirname(__FILE__) . '/includes/class-api-handler.php';

// ID дисквалифицированного счета для тестирования
$account_id = 17235; // Счет 2090882091 (дисквалифицирован)

echo "=== ТЕСТ ЗАЩИТЫ ДИСКВАЛИФИЦИРОВАННЫХ СЧЕТОВ ===\n";
echo "Тестируемый счет ID: $account_id (2090882091)\n\n";

global $wpdb;
$table_name = $wpdb->prefix . 'contest_members';

// 1. Проверяем начальное состояние
echo "1. СОСТОЯНИЕ ДО ОБНОВЛЕНИЯ:\n";
$before = $wpdb->get_row($wpdb->prepare(
    "SELECT id, account_number, connection_status, error_description, balance, equity FROM $table_name WHERE id = %d",
    $account_id
), ARRAY_A);

if (!$before) {
    echo "ОШИБКА: Счет не найден\n";
    exit(1);
}

echo "   - Номер: {$before['account_number']}\n";
echo "   - Статус: {$before['connection_status']}\n";
echo "   - Причина: {$before['error_description']}\n";
echo "   - Баланс: {$before['balance']}\n";
echo "   - Эквити: {$before['equity']}\n\n";

// Подтверждаем что счет дисквалифицирован
if ($before['connection_status'] !== 'disqualified') {
    echo "ОШИБКА: Счет не дисквалифицирован. Статус: {$before['connection_status']}\n";
    exit(1);
}

// 2. Симулируем обновление через process_trading_account
echo "2. ЗАПУСК ОБНОВЛЕНИЯ ЧЕРЕЗ process_trading_account():\n";

// Вызываем обновление счета (без передачи данных - только обновление из API)
$result = process_trading_account([], $account_id, null, 'test_disq_protection');

echo "   - Результат: " . ($result['success'] ? 'SUCCESS' : 'FAILED') . "\n";
echo "   - Сообщение: {$result['message']}\n\n";

// 3. Проверяем состояние после обновления
echo "3. СОСТОЯНИЕ ПОСЛЕ ОБНОВЛЕНИЯ:\n";
$after = $wpdb->get_row($wpdb->prepare(
    "SELECT id, account_number, connection_status, error_description, balance, equity, last_update FROM $table_name WHERE id = %d",
    $account_id
), ARRAY_A);

echo "   - Номер: {$after['account_number']}\n";
echo "   - Статус: {$after['connection_status']}\n";
echo "   - Причина: {$after['error_description']}\n";
echo "   - Баланс: {$after['balance']}\n";
echo "   - Эквити: {$after['equity']}\n";
echo "   - Обновлено: {$after['last_update']}\n\n";

// 4. Анализ результатов
echo "4. АНАЛИЗ ЗАЩИТЫ:\n";

if ($before['connection_status'] === 'disqualified' && $after['connection_status'] === 'disqualified') {
    echo "   ✅ ЗАЩИТА РАБОТАЕТ: Статус дисквалификации сохранен\n";
    
    if ($before['error_description'] === $after['error_description']) {
        echo "   ✅ ПРИЧИНА СОХРАНЕНА: Описание дисквалификации не изменилось\n";
    } else {
        echo "   ⚠️  ПРИЧИНА ИЗМЕНЕНА:\n";
        echo "      Было: {$before['error_description']}\n";
        echo "      Стало: {$after['error_description']}\n";
    }
    
    // Проверяем обновились ли финансовые данные
    if ($before['balance'] !== $after['balance'] || $before['equity'] !== $after['equity']) {
        echo "   ✅ ДАННЫЕ ОБНОВЛЕНЫ: Финансовые показатели получили новые значения\n";
        echo "      Баланс: {$before['balance']} → {$after['balance']}\n";
        echo "      Эквити: {$before['equity']} → {$after['equity']}\n";
    } else {
        echo "   ℹ️  ДАННЫЕ БЕЗ ИЗМЕНЕНИЙ: Финансовые показатели остались прежними\n";
    }
    
} else {
    echo "   ❌ ЗАЩИТА НЕ РАБОТАЕТ: Статус дисквалификации был изменен!\n";
    echo "      Было: {$before['connection_status']}\n";
    echo "      Стало: {$after['connection_status']}\n";
}

echo "\n=== ТЕСТ ЗАВЕРШЕН ===\n"; 