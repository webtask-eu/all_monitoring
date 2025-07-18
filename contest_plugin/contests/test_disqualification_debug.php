<?php
/**
 * Тестовый скрипт для диагностики проверки дисквалификации
 * Проверяет конкретный счет с детальным логированием
 */

// Подключение WordPress
$wp_load_path = '/var/www/vhosts/fortraders.org/httpdocs/wp-load.php';
if (!file_exists($wp_load_path)) {
    die("WordPress не найден по пути: $wp_load_path\n");
}

require_once $wp_load_path;

// Подключение необходимых классов
require_once dirname(__FILE__) . '/includes/class-disqualification-checker.php';

// Функция для логирования
function debug_log($message, $level = 'INFO') {
    $timestamp = date('Y-m-d H:i:s');
    echo "[$timestamp] [$level] $message\n";
}

// ID счета для тестирования (можно изменить)
$test_account_id = 17231; // Активный счет из конкурса 468990

debug_log("=== ТЕСТОВАЯ ПРОВЕРКА ДИСКВАЛИФИКАЦИИ ===");
debug_log("Тестируемый счет ID: $test_account_id");

global $wpdb;

// Получаем информацию о счете
$table_name = $wpdb->prefix . 'contest_members';
$account = $wpdb->get_row($wpdb->prepare(
    "SELECT * FROM $table_name WHERE id = %d",
    $test_account_id
));

if (!$account) {
    debug_log("ОШИБКА: Счет с ID $test_account_id не найден", 'ERROR');
    exit(1);
}

debug_log("Счет найден:");
debug_log("  - Номер счета: {$account->account_number}");
debug_log("  - Конкурс ID: {$account->contest_id}");
debug_log("  - Статус: {$account->connection_status}");
debug_log("  - Баланс: {$account->balance}");
debug_log("  - Эквити: {$account->equity}");
debug_log("  - Плечо: {$account->leverage}");

// Получаем настройки конкурса
$contest_data = get_post_meta($account->contest_id, '_fttradingapi_contest_data', true);
if (!is_array($contest_data)) {
    debug_log("ОШИБКА: Настройки конкурса не найдены", 'ERROR');
    exit(1);
}

debug_log("Настройки конкурса:");
debug_log("  - Статус конкурса: " . ($contest_data['contest_status'] ?? 'не указан'));
debug_log("  - Дата начала: " . ($contest_data['date_start'] ?? 'не указана'));
debug_log("  - Дата завершения: " . ($contest_data['date_end'] ?? 'не указана'));
debug_log("  - Начальный депозит: " . ($contest_data['initial_deposit'] ?? 'не указан'));

// Проверяем какие проверки включены
$checks = [
    'check_initial_deposit' => 'Проверка начального депозита',
    'check_leverage' => 'Проверка кредитного плеча',
    'check_instruments' => 'Проверка инструментов',
    'check_max_volume' => 'Проверка максимального объема',
    'check_min_trades' => 'Проверка минимального количества сделок',
    'check_pre_contest_trades' => 'Проверка сделок до конкурса',
    'check_min_profit' => 'Проверка минимальной прибыли'
];

debug_log("Активные проверки:");
foreach ($checks as $key => $name) {
    $enabled = isset($contest_data[$key]) && $contest_data[$key] == '1' ? 'ДА' : 'НЕТ';
    debug_log("  - $name: $enabled");
    if ($enabled === 'ДА' && isset($contest_data[str_replace('check_', '', $key)])) {
        $value = $contest_data[str_replace('check_', '', $key)];
        debug_log("    Значение: $value");
    }
}

// Инициализируем чекер
$checker = new Contest_Disqualification_Checker();

debug_log("=== ЗАПУСК ПРОВЕРКИ ===");

// Выполняем проверку с детальным логированием
$result = $checker->check_account_disqualification($test_account_id);

debug_log("=== РЕЗУЛЬТАТ ПРОВЕРКИ ===");
debug_log("Дисквалифицирован: " . ($result['is_disqualified'] ? 'ДА' : 'НЕТ'));

if ($result['is_disqualified']) {
    debug_log("Причины дисквалификации:");
    foreach ($result['reasons'] as $reason) {
        debug_log("  - $reason");
    }
} else {
    debug_log("Нарушений не обнаружено");
}

debug_log("=== ЗАВЕРШЕНИЕ ТЕСТА ==="); 