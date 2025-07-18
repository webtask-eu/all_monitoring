<?php
echo "=== ТЕСТ ЗАГРУЗКИ DAEMON ===\n";

try {
    echo "1. Загружаем WordPress...\n";
    require_once __DIR__ . '/../../../wp-load.php';
    echo "✅ WordPress загружен\n";

    echo "2. Проверяем did_action('plugins_loaded')...\n";
    echo "plugins_loaded: " . (did_action('plugins_loaded') ? 'ДА' : 'НЕТ') . "\n";

    echo "3. Загружаем главный файл плагина...\n";
    require_once __DIR__ . '/ft-trader-contest.php';
    echo "✅ Главный файл плагина загружен\n";

    echo "4. Проверяем класс FT_Trader_Contest...\n";
    if (class_exists('FT_Trader_Contest')) {
        echo "✅ FT_Trader_Contest найден\n";
    } else {
        echo "❌ FT_Trader_Contest НЕ найден\n";
    }

    echo "5. Загружаем Account_Updater...\n";
    require_once __DIR__ . '/includes/class-account-updater.php';
    if (class_exists('Account_Updater')) {
        echo "✅ Account_Updater найден\n";
        Account_Updater::init();
        echo "✅ Account_Updater::init() выполнен\n";
    } else {
        echo "❌ Account_Updater НЕ найден\n";
    }

    echo "6. Проверяем hook...\n";
    if (has_action('process_accounts_update_batch')) {
        echo "✅ Hook process_accounts_update_batch зарегистрирован\n";
    } else {
        echo "❌ Hook process_accounts_update_batch НЕ зарегистрирован\n";
    }

    echo "7. Тестируем выполнение hook...\n";
    ob_start();
    do_action('process_accounts_update_batch', 468990, 'TEST');
    $output = ob_get_clean();
    echo "Вывод от hook: " . ($output ? $output : 'ПУСТОЙ') . "\n";

    echo "\n✅ ВСЕ ТЕСТЫ ЗАВЕРШЕНЫ\n";

} catch (Exception $e) {
    echo "❌ ОШИБКА: " . $e->getMessage() . "\n";
    echo "Трассировка: " . $e->getTraceAsString() . "\n";
} 