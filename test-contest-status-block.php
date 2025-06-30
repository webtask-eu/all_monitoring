<?php
/**
 * Тестовый скрипт для проверки блокировки обновления счетов 
 * в завершенных и архивных конкурсах
 * 
 * Использование: поместите файл в корень WordPress и откройте в браузере
 */

// Подключаем WordPress
require_once('wp-config.php');

// Стили для красивого отображения
echo '<style>
.test-section { margin: 20px 0; padding: 15px; background: #f8f9fa; border-left: 4px solid #007cba; }
.success { border-left-color: #46b450; }
.error { border-left-color: #dc3232; }
.warning { border-left-color: #ffb900; }
.code { background: #23282d; color: #f1f1f1; padding: 10px; border-radius: 4px; font-family: monospace; }
table { width: 100%; border-collapse: collapse; margin: 10px 0; }
th, td { padding: 8px; text-align: left; border: 1px solid #ddd; }
th { background-color: #f2f2f2; }
</style>';

echo '<h1>🚦 Тест блокировки обновлений завершенных конкурсов</h1>';

// Проверяем, подключены ли плагины
if (!function_exists('process_trading_account')) {
    echo '<div class="test-section error"><h3>❌ Ошибка</h3>';
    echo '<p>Функция process_trading_account не найдена. Убедитесь, что плагин конкурсов активен.</p></div>';
    exit;
}

echo '<div class="test-section success"><h3>✅ Плагин конкурсов активен</h3></div>';

// Получаем список всех конкурсов
global $wpdb;
$contests = $wpdb->get_results(
    "SELECT ID, post_title, post_status FROM $wpdb->posts 
     WHERE post_type = 'trader_contests' 
     ORDER BY ID DESC LIMIT 10"
);

if (empty($contests)) {
    echo '<div class="test-section warning"><h3>⚠️ Конкурсы не найдены</h3>';
    echo '<p>В системе нет конкурсов для тестирования.</p></div>';
    exit;
}

echo '<div class="test-section"><h3>📊 Найденные конкурсы</h3>';
echo '<table>';
echo '<tr><th>ID</th><th>Название</th><th>Статус поста</th><th>Статус конкурса</th><th>Архивный</th><th>Статус блокировки</th></tr>';

foreach ($contests as $contest) {
    $contest_data = get_post_meta($contest->ID, '_fttradingapi_contest_data', true);
    $contest_status = !empty($contest_data) && isset($contest_data['contest_status']) ? 
        $contest_data['contest_status'] : 'не установлен';
    $is_archived = !empty($contest_data) && isset($contest_data['is_archived']) ? 
        $contest_data['is_archived'] : '0';
    
    // Определяем, будет ли заблокировано обновление
    $will_block = ($contest_status === 'finished' || $is_archived === '1');
    $block_status = $will_block ? '🚫 Заблокировано' : '✅ Разрешено';
    $row_class = $will_block ? 'style="background-color: #ffebee;"' : '';
    
    echo "<tr $row_class>";
    echo "<td>{$contest->ID}</td>";
    echo "<td>" . esc_html($contest->post_title) . "</td>";
    echo "<td>{$contest->post_status}</td>";
    echo "<td>{$contest_status}</td>";
    echo "<td>" . ($is_archived === '1' ? 'Да' : 'Нет') . "</td>";
    echo "<td>{$block_status}</td>";
    echo "</tr>";
}

echo '</table></div>';

// Получаем счета для тестирования
$test_accounts = $wpdb->get_results(
    "SELECT cm.id, cm.contest_id, cm.account_number, cm.connection_status, p.post_title 
     FROM {$wpdb->prefix}contest_members cm
     JOIN $wpdb->posts p ON cm.contest_id = p.ID
     WHERE p.post_type = 'trader_contests' 
     ORDER BY cm.id DESC LIMIT 5"
);

if (!empty($test_accounts)) {
    echo '<div class="test-section"><h3>🧪 Тестовые счета</h3>';
    echo '<table>';
    echo '<tr><th>ID счета</th><th>Номер</th><th>Конкурс</th><th>Статус подключения</th><th>Результат теста</th></tr>';
    
    foreach ($test_accounts as $account) {
        echo "<tr>";
        echo "<td>{$account->id}</td>";
        echo "<td>{$account->account_number}</td>";
        echo "<td>" . esc_html($account->post_title) . "</td>";
        echo "<td>{$account->connection_status}</td>";
        
        // Тестируем функцию process_trading_account
        $result = process_trading_account([], $account->id);
        
        if (!$result['success'] && (
            (isset($result['contest_status']) && $result['contest_status'] === 'finished') ||
            (isset($result['is_archived']) && $result['is_archived'] === '1')
        )) {
            echo '<td style="color: #46b450;">🚫 Корректно заблокировано: ' . esc_html($result['message']) . '</td>';
        } elseif (!$result['success']) {
            echo '<td style="color: #ffb900;">⚠️ Заблокировано по другой причине: ' . esc_html($result['message']) . '</td>';
        } else {
            echo '<td style="color: #007cba;">✅ Обновление разрешено</td>';
        }
        
        echo "</tr>";
    }
    
    echo '</table></div>';
} else {
    echo '<div class="test-section warning"><h3>⚠️ Тестовые счета не найдены</h3></div>';
}

// Тест создания нового счета в завершенном конкурсе
$finished_contest = null;
foreach ($contests as $contest) {
    $contest_data = get_post_meta($contest->ID, '_fttradingapi_contest_data', true);
    if (!empty($contest_data) && 
        (isset($contest_data['contest_status']) && $contest_data['contest_status'] === 'finished' ||
         isset($contest_data['is_archived']) && $contest_data['is_archived'] === '1')) {
        $finished_contest = $contest;
        break;
    }
}

if ($finished_contest) {
    echo '<div class="test-section"><h3>🆕 Тест регистрации нового счета в завершенном конкурсе</h3>';
    
    $test_account_data = [
        'account_number' => 'TEST123456',
        'password' => 'testpass123',
        'server' => 'TestServer',
        'terminal' => 'TestTerminal'
    ];
    
    $result = process_trading_account($test_account_data, null, $finished_contest->ID);
    
    echo '<div class="code">';
    echo 'Результат попытки регистрации в завершенном конкурсе "' . esc_html($finished_contest->post_title) . '":<br>';
    echo 'Успех: ' . ($result['success'] ? 'true' : 'false') . '<br>';
    echo 'Сообщение: ' . esc_html($result['message']) . '<br>';
    if (isset($result['debug_info'])) {
        echo 'Debug: ' . esc_html($result['debug_info']);
    }
    echo '</div>';
    
    if (!$result['success'] && strpos($result['message'], 'завершенном') !== false) {
        echo '<p style="color: #46b450;">✅ <strong>Тест пройден успешно!</strong> Регистрация новых счетов в завершенном конкурсе корректно заблокирована.</p>';
    } else {
        echo '<p style="color: #dc3232;">❌ <strong>Тест не пройден!</strong> Ожидалась блокировка регистрации.</p>';
    }
} else {
    echo '<div class="test-section warning"><h3>⚠️ Нет завершенных конкурсов для тестирования</h3></div>';
}

echo '<div class="test-section"><h3>📋 Инструкции по тестированию</h3>';
echo '<ol>';
echo '<li><strong>Ручное тестирование:</strong> Зайдите в админку WordPress, найдите завершенный конкурс и попробуйте обновить его счета через массовые действия.</li>';
echo '<li><strong>Ajax тестирование:</strong> На фронтенде попробуйте обновить счет в завершенном конкурсе через кнопку "Обновить данные".</li>';
echo '<li><strong>Автоматическое обновление:</strong> Автообновление уже корректно исключает завершенные конкурсы в Account_Updater::run_auto_update().</li>';
echo '</ol>';
echo '<p><strong>Примечание:</strong> После завершения тестирования удалите этот файл из корня сайта!</p>';
echo '</div>';

echo '<div class="test-section"><h3>🔍 Логи</h3>';
echo '<p>Проверьте логи WordPress на наличие записей "Блокировка обновления счета в завершенном/архивном конкурсе".</p>';
echo '</div>';
?> 