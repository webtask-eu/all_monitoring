<?php
// Простой скрипт для очистки логов WordPress

if (function_exists('error_log')) {
    // Очищаем error.log в корне WordPress (если есть)
    if (file_exists('error.log')) {
        file_put_contents('error.log', '');
        echo 'WordPress error.log очищен\n';
    }
    
    // Очищаем debug.log в wp-content (если есть)
    if (file_exists('wp-content/debug.log')) {
        file_put_contents('wp-content/debug.log', '');
        echo 'WordPress debug.log очищен\n';
    }
    
    echo 'Логи очищены. Время: ' . date('Y-m-d H:i:s') . '\n';
}
?>
