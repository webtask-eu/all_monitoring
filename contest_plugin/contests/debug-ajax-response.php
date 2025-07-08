<?php
/**
 * Отладочный скрипт для симуляции AJAX запроса и проверки ответа
 */

// Загружаем WordPress
require_once dirname(dirname(dirname(dirname(__FILE__)))) . '/wp-config.php';
require_once 'includes/class-account-history.php';

echo "Content-Type: text/html; charset=utf-8\n\n";
echo "<h2>Отладка AJAX ответа для фильтра leverage</h2>\n";

// Симулируем AJAX запрос
$_POST = [
    'account_id' => 17296,
    'field' => 'leverage',
    'period' => 'all',
    'sort' => 'desc',
    'page' => 1,
    'per_page' => 10
];

echo "<h3>Параметры запроса:</h3>\n";
echo "<pre>" . print_r($_POST, true) . "</pre>\n";

// Получаем данные
$history = new Account_History();
$result = $history->get_filtered_history(
    $_POST['account_id'],
    $_POST['field'],
    $_POST['period'],
    $_POST['sort'],
    $_POST['page'],
    $_POST['per_page']
);

echo "<h3>Результат запроса:</h3>\n";
echo "<p>Общее количество записей: <strong>{$result['total_items']}</strong></p>\n";
echo "<p>Всего страниц: <strong>{$result['total_pages']}</strong></p>\n";

if (!empty($result['results'])) {
    echo "<h3>HTML таблица (как на фронтенде):</h3>\n";
    echo "<div style='border: 1px solid #ccc; padding: 10px;'>\n";
    
    // Включаем переменные для шаблона
    $changes = $result['results'];
    $pagination = [
        'total_items' => $result['total_items'],
        'total_pages' => $result['total_pages'],
        'current_page' => $result['current_page'],
        'per_page' => $result['per_page']
    ];
    
    // Включаем шаблон таблицы
    ob_start();
    include(dirname(__FILE__) . '/admin/views/history-table.php');
    $html_output = ob_get_clean();
    
    echo $html_output;
    echo "</div>\n";
    
    echo "<h3>Сырые данные:</h3>\n";
    echo "<pre>" . print_r($changes, true) . "</pre>\n";
} else {
    echo "<h3>❌ Нет данных!</h3>\n";
    echo "<p>Результат пустой, проверьте базу данных.</p>\n";
}

echo "<h3>Проверка других фильтров:</h3>\n";
$test_filters = [
    '' => 'Все поля',
    'h_count' => 'Количество записей в истории',
    'pass' => 'Пароль',
    'i_firma' => 'Брокер'
];

foreach ($test_filters as $filter_field => $filter_name) {
    $test_result = $history->get_filtered_history(17296, $filter_field, 'all', 'desc', 1, 10);
    echo "<p><strong>{$filter_name}:</strong> {$test_result['total_items']} записей</p>\n";
}

echo "<h3>Готово!</h3>\n";
echo "<p>Если вы видите данные выше, но не видите их на сайте - проблема в кешировании браузера или JavaScript.</p>\n";
echo "<p>Попробуйте:</p>\n";
echo "<ul>\n";
echo "<li>Обновить страницу (Ctrl+F5)</li>\n";
echo "<li>Очистить кеш браузера</li>\n";
echo "<li>Открыть страницу в режиме инкогнито</li>\n";
echo "</ul>\n";
?> 