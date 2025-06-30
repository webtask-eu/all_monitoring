<?php
/**
 * Скрипт для сброса всех очередей обновления и счетчика активных запросов API
 * 
 * Запустите этот скрипт с правами администратора, если необходимо сбросить зависшие очереди
 */

// Определение корня WordPress
if (file_exists('wp-load.php')) {
    require_once('wp-load.php');
} else if (file_exists('../wp-load.php')) {
    require_once('../wp-load.php');
} else if (file_exists('../../wp-load.php')) {
    require_once('../../wp-load.php');
} else {
    die("WordPress не найден. Пожалуйста, разместите этот скрипт в корне сайта WordPress.");
}

// Проверка прав доступа
if (!current_user_can('manage_options')) {
    die("У вас недостаточно прав для выполнения этой операции. Пожалуйста, войдите как администратор.");
}

// Подключаем необходимые классы
$base_path = plugin_dir_path(__FILE__) . 'PLUGIN/contests/includes/';
if (!file_exists($base_path . 'class-account-updater.php')) {
    $base_path = WP_PLUGIN_DIR . '/contests/includes/';
    if (!file_exists($base_path . 'class-account-updater.php')) {
        die("Не найдены необходимые файлы плагина. Убедитесь, что плагин установлен и активирован.");
    }
}

require_once($base_path . 'class-account-updater.php');
require_once($base_path . 'class-api-config.php');

// Добавляем заголовок страницы
echo '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Сброс очередей обновления</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 20px;
            line-height: 1.6;
        }
        h1 {
            color: #333;
            border-bottom: 1px solid #ccc;
            padding-bottom: 10px;
        }
        .container {
            max-width: 800px;
            margin: 0 auto;
        }
        .success {
            color: green;
            background-color: #f0fff0;
            padding: 10px;
            border: 1px solid green;
            margin: 10px 0;
        }
        .error {
            color: red;
            background-color: #fff0f0;
            padding: 10px;
            border: 1px solid red;
            margin: 10px 0;
        }
        .details {
            background-color: #f8f8f8;
            padding: 10px;
            border: 1px solid #ddd;
            margin: 10px 0;
            overflow: auto;
            max-height: 300px;
        }
        .button {
            display: inline-block;
            background-color: #2271b1;
            color: white;
            padding: 8px 15px;
            text-decoration: none;
            border-radius: 3px;
            margin-top: 20px;
        }
        .button:hover {
            background-color: #135e96;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Сброс очередей обновления</h1>';

try {
    // Сбрасываем счетчик активных запросов API
    $prev_active_requests = get_option('ft_active_requests', 0);
    FT_API_Config::reset_active_requests();
    echo '<div class="success">Счетчик активных API-запросов сброшен. Предыдущее значение: ' . $prev_active_requests . '</div>';
    
    // Очищаем все очереди обновления
    $result = Account_Updater::clear_all_queues();
    
    if ($result['success']) {
        echo '<div class="success">Все очереди обновления успешно очищены.</div>';
        echo '<div class="details"><h3>Детали операции:</h3><pre>';
        print_r($result);
        echo '</pre></div>';
    } else {
        echo '<div class="error">Произошла ошибка при очистке очередей:</div>';
        echo '<div class="details"><pre>';
        print_r($result);
        echo '</pre></div>';
    }
} catch (Exception $e) {
    echo '<div class="error">Произошла ошибка: ' . $e->getMessage() . '</div>';
}

// Добавляем ссылки для возврата
echo '<p>
        <a href="' . admin_url('admin.php?page=fttradingapi-accounts') . '" class="button">Вернуться к списку счетов</a>
        <a href="' . admin_url() . '" class="button">Вернуться в панель управления</a>
    </p>
    </div>
</body>
</html>'; 