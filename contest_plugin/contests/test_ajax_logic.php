<?php
/**
 * Тестовый скрипт, копирующий логику AJAX обработчика check_account_disqualification
 */

// Подключение WordPress
$wp_load_path = '/var/www/vhosts/fortraders.org/httpdocs/wp-load.php';
if (!file_exists($wp_load_path)) {
    die("WordPress не найден по пути: $wp_load_path\n");
}

require_once $wp_load_path;

// ID счета для тестирования
$account_id = 17231;

echo "=== ТЕСТ ЛОГИКИ AJAX ОБРАБОТЧИКА ===\n";
echo "Тестируемый счет ID: $account_id\n\n";

global $wpdb;
$table_name = $wpdb->prefix . 'contest_members';

// Получаем данные счета (копируем из AJAX обработчика)
$account = $wpdb->get_row($wpdb->prepare(
    "SELECT * FROM $table_name WHERE id = %d",
    $account_id
));

if (!$account) {
    echo "ОШИБКА: Счет не найден\n";
    exit(1);
}

echo "Данные счета:\n";
echo "- Номер: {$account->account_number}\n";
echo "- Конкурс ID: {$account->contest_id}\n";
echo "- Статус: {$account->connection_status}\n";
echo "- Баланс: {$account->balance}\n";
echo "- Эквити: {$account->equity}\n\n";

// Загружаем класс для проверки дисквалификации (точная копия из AJAX обработчика)
try {
    $class_path = dirname(__FILE__) . '/includes/class-disqualification-checker.php';
    echo "Загружаем класс из: $class_path\n";
    
    if (!file_exists($class_path)) {
        throw new Exception("Файл класса не найден: $class_path");
    }
    
    require_once $class_path;
    
    if (!class_exists('Contest_Disqualification_Checker')) {
        throw new Exception("Класс Contest_Disqualification_Checker не найден после загрузки");
    }
    
    echo "Класс успешно загружен\n";
    
    // Проверяем дисквалификацию (точная копия из AJAX обработчика)
    $disqualification_checker = new Contest_Disqualification_Checker();
    echo "Объект checker создан\n";
    
    $result = $disqualification_checker->check_account_disqualification($account_id);
    echo "Проверка завершена\n";
    
} catch (Exception $e) {
    echo "ОШИБКА: " . $e->getMessage() . "\n";
    echo "Трассировка:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}

echo "=== РЕЗУЛЬТАТ ===\n";
echo "Дисквалифицирован: " . ($result['is_disqualified'] ? 'ДА' : 'НЕТ') . "\n";

if ($result['is_disqualified']) {
    echo "Причины:\n";
    foreach ($result['reasons'] as $reason) {
        echo "- $reason\n";
    }
    
    // Дисквалифицируем счет (копируем из AJAX)
    $disqualification_checker->disqualify_account($account_id, $result['reasons']);
    echo "\nСчет дисквалифицирован в базе данных.\n";
    
} else {
    echo "Счет соответствует всем условиям конкурса\n";
}

echo "\n=== КОНЕЦ ТЕСТА ===\n"; 