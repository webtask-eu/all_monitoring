<?php
/**
 * Отладочный файл для отслеживания пути пароля через всю систему
 */

// Подключаем WordPress
require_once(dirname(dirname(dirname(__FILE__))) . '/wp-config.php');

// Тестируем пароль с символом <
$test_password = 'nX76<hv3sMRk';

echo "<h2>Отладка пути пароля через систему</h2>";
echo "<style>
table { border-collapse: collapse; width: 100%; }
th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
th { background-color: #f2f2f2; }
.error { color: red; }
.success { color: green; }
</style>";

echo "<table>";
echo "<tr><th>Этап</th><th>Функция</th><th>Результат</th><th>Комментарий</th></tr>";

// 1. Исходный пароль
echo "<tr><td>1. Исходный пароль</td><td>-</td><td>" . htmlspecialchars($test_password) . "</td><td>Как ввел пользователь</td></tr>";

// 2. Через wp_unslash (как в нашем коде)
$after_wp_unslash = wp_unslash($test_password);
echo "<tr><td>2. wp_unslash</td><td>wp_unslash()</td><td>" . htmlspecialchars($after_wp_unslash) . "</td><td>Удаление слешей WP</td></tr>";

// 3. Через preg_replace (удаление пробелов как в коде)
$after_trim = preg_replace('/\s+/', '', $after_wp_unslash);
echo "<tr><td>3. Удаление пробелов</td><td>preg_replace('/\\s+/', '', \$password)</td><td>" . htmlspecialchars($after_trim) . "</td><td>Как в API handler</td></tr>";

// 4. Через http_build_query (как в URL)
$params = ['password' => $after_trim];
$url_encoded = http_build_query($params);
echo "<tr><td>4. http_build_query</td><td>http_build_query()</td><td>" . htmlspecialchars($url_encoded) . "</td><td>Формирование URL</td></tr>";

// 5. Парсинг обратно
parse_str($url_encoded, $parsed_params);
echo "<tr><td>5. parse_str (обратно)</td><td>parse_str()</td><td>" . htmlspecialchars($parsed_params['password']) . "</td><td>Как получает сервер</td></tr>";

// 6. Сохранение в MySQL (симуляция)
global $wpdb;
$for_mysql = $wpdb->prepare("SELECT %s", $after_trim);
echo "<tr><td>6. wpdb->prepare</td><td>\$wpdb->prepare()</td><td>" . htmlspecialchars($for_mysql) . "</td><td>Подготовка для MySQL</td></tr>";

// 7. Тестируем сохранение/чтение из базы
$test_table = $wpdb->prefix . 'test_password_debug';
$wpdb->query("CREATE TABLE IF NOT EXISTS {$test_table} (id INT AUTO_INCREMENT PRIMARY KEY, password VARCHAR(255))");
$wpdb->insert($test_table, ['password' => $after_trim]);
$from_db = $wpdb->get_var("SELECT password FROM {$test_table} ORDER BY id DESC LIMIT 1");
echo "<tr><td>7. Чтение из БД</td><td>wpdb->get_var()</td><td>" . htmlspecialchars($from_db) . "</td><td>Как сохранено в MySQL</td></tr>";

// Очищаем тестовую таблицу
$wpdb->query("DROP TABLE IF EXISTS {$test_table}");

echo "</table>";

// Проверяем различные функции экранирования
echo "<h3>Тестирование различных функций экранирования</h3>";
echo "<table>";
echo "<tr><th>Функция</th><th>Результат</th><th>Как выглядит</th></tr>";

$functions = [
    'sanitize_text_field' => sanitize_text_field($test_password),
    'esc_html' => esc_html($test_password),
    'esc_attr' => esc_attr($test_password),
    'htmlspecialchars' => htmlspecialchars($test_password),
    'rawurlencode' => rawurlencode($test_password),
    'urlencode' => urlencode($test_password),
];

foreach ($functions as $func_name => $result) {
    echo "<tr><td>{$func_name}</td><td>" . htmlspecialchars($result) . "</td><td>{$result}</td></tr>";
}

echo "</table>";

// Проверяем, может ли проблема быть в передаче через JSON
echo "<h3>Тестирование JSON</h3>";
echo "<table>";
echo "<tr><th>Этап</th><th>Результат</th></tr>";

$json_encoded = json_encode(['password' => $test_password]);
echo "<tr><td>json_encode</td><td>" . htmlspecialchars($json_encoded) . "</td></tr>";

$json_decoded = json_decode($json_encoded, true);
echo "<tr><td>json_decode</td><td>" . htmlspecialchars($json_decoded['password']) . "</td></tr>";

echo "</table>";

// Проверяем реальный пример из базы данных
echo "<h3>Проверка реального пароля из базы данных</h3>";
$contest_table = $wpdb->prefix . 'contest_members';
$real_password = $wpdb->get_var("SELECT password FROM {$contest_table} WHERE password LIKE '%lt;%' LIMIT 1");
if ($real_password) {
    echo "<p><strong>Найден пароль с &lt; в базе:</strong> " . htmlspecialchars($real_password) . "</p>";
    echo "<p>Это означает, что пароль уже сохранен в базе в преобразованном виде.</p>";
} else {
    echo "<p>Пароли с &lt; в базе не найдены.</p>";
}

echo "<h3>Рекомендации</h3>";
echo "<ul>";
echo "<li>Если пароль уже сохранен в базе как 'nX76&lt;hv3sMRk', нужно исправить существующие записи</li>";
echo "<li>Проверить, не происходит ли дополнительное экранирование на стороне сервера API</li>";
echo "<li>Убедиться, что при сохранении в базу пароль не проходит через sanitize_text_field</li>";
echo "</ul>";
?> 