<?php
/**
 * Отладочный скрипт для проверки призовых фондов конкурсов
 * 
 * Инструкция по использованию:
 * 1. Разместите этот файл в корневой папке плагина
 * 2. Откройте его в браузере: https://ваш-сайт.ru/wp-content/plugins/contests/debug-prize-funds.php
 */

// Загружаем WordPress
if (!defined('ABSPATH')) {
    define('WP_USE_THEMES', false);
    require_once('../../../../wp-load.php');
}

// Проверяем авторизацию пользователя
if (!current_user_can('manage_options')) {
    wp_die('Доступ запрещен. Только администраторы могут запускать этот скрипт.');
}

?><!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Отладка призовых фондов</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; line-height: 1.6; }
        h1 { color: #333; }
        h2 { margin-top: 20px; padding-top: 10px; border-top: 1px solid #ddd; }
        table { border-collapse: collapse; width: 100%; margin-top: 10px; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        th { background-color: #f5f5f5; }
        .total { font-weight: bold; background-color: #f0f0f0; }
        .error { color: #c00; }
        .green { color: #0a0; }
        pre { background: #f5f5f5; padding: 10px; overflow: auto; }
    </style>
</head>
<body>
    <h1>Отладка призовых фондов конкурсов</h1>
    
    <?php
    // Получаем все конкурсы
    $contests_query = new WP_Query([
        'post_type' => 'trader_contests',
        'posts_per_page' => -1,
        'orderby' => 'ID',
        'order' => 'ASC'
    ]);
    
    $total_prize_fund = 0;
    
    if ($contests_query->have_posts()) {
        ?>
        <table>
            <tr>
                <th>ID</th>
                <th>Название конкурса</th>
                <th>Новый формат (prizes)</th>
                <th>Старый формат (_contest_prize_fund)</th>
                <th>Суммарное значение</th>
            </tr>
        <?php
        
        while ($contests_query->have_posts()) {
            $contests_query->the_post();
            $contest_id = get_the_ID();
            $contest_title = get_the_title();
            
            // Получаем данные нового формата
            $contest_data = get_post_meta($contest_id, '_fttradingapi_contest_data', true);
            $prizes = isset($contest_data['prizes']) ? $contest_data['prizes'] : array();
            
            // Получаем данные старого формата
            $prize_fund_old = get_post_meta($contest_id, '_contest_prize_fund', true);
            
            // Вычисляем сумму для нового формата
            $new_format_total = 0;
            if (!empty($prizes)) {
                foreach ($prizes as $prize) {
                    $amount = preg_replace('/[^0-9.]/', '', $prize['amount']);
                    $new_format_total += floatval($amount);
                }
            }
            
            // Вычисляем значение для старого формата
            $old_format_value = 0;
            if (!empty($prize_fund_old)) {
                $old_format_value = floatval(preg_replace('/[^0-9.]/', '', $prize_fund_old));
            }
            
            // Определяем, какое значение использовать
            $contest_total = $new_format_total > 0 ? $new_format_total : $old_format_value;
            $total_prize_fund += $contest_total;
            
            // Вывод данных о конкурсе
            ?>
            <tr>
                <td><?php echo $contest_id; ?></td>
                <td><?php echo $contest_title; ?></td>
                <td>
                    <?php 
                    if (!empty($prizes)) {
                        echo '<strong>Сумма: $' . number_format($new_format_total, 2) . '</strong><br>';
                        echo 'Детали призов:<br>';
                        foreach ($prizes as $prize) {
                            echo 'Место ' . $prize['place'] . ': ' . $prize['amount'] . '<br>';
                        }
                        echo '<pre>' . print_r($prizes, true) . '</pre>';
                    } else {
                        echo '<span class="error">Не задано</span>';
                    }
                    ?>
                </td>
                <td>
                    <?php 
                    if (!empty($prize_fund_old)) {
                        echo $prize_fund_old . ' → $' . number_format($old_format_value, 2);
                    } else {
                        echo '<span class="error">Не задано</span>';
                    }
                    ?>
                </td>
                <td>
                    <strong>$<?php echo number_format($contest_total, 2); ?></strong>
                </td>
            </tr>
            <?php
        }
        
        // Вывод итоговой суммы
        ?>
        <tr class="total">
            <td colspan="4" align="right">Общий призовой фонд:</td>
            <td><strong>$<?php echo number_format($total_prize_fund, 2); ?></strong></td>
        </tr>
        </table>
        <?php
    } else {
        echo '<p class="error">Конкурсы не найдены.</p>';
    }
    
    // Выводим данные из HTML-страницы
    echo '<h2>Проверка HTML-разметки на странице архива</h2>';
    
    // Получаем URL архивной страницы
    $archive_url = get_post_type_archive_link('trader_contests');
    
    if ($archive_url) {
        $response = wp_remote_get($archive_url);
        
        if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
            $html = wp_remote_retrieve_body($response);
            
            // Попытка найти блок со статистикой
            if (preg_match('/<span class="stats-value prize animated-counter"[^>]*>(.*?)<\/span>/s', $html, $matches)) {
                echo '<p>Найдено значение общего призового фонда на странице: <strong class="green">' . $matches[1] . '</strong></p>';
            } else {
                echo '<p class="error">Не удалось найти блок с общим призовым фондом на странице.</p>';
            }
            
            // Получаем атрибут data-value для проверки
            if (preg_match('/<span class="stats-value prize animated-counter"[^>]*data-value="([^"]*)"/', $html, $matches)) {
                echo '<p>Значение атрибута data-value: <strong>' . $matches[1] . '</strong></p>';
                if (floatval($matches[1]) === $total_prize_fund) {
                    echo '<p class="green">Значение в data-value совпадает с расчетной суммой: $' . number_format($total_prize_fund, 2) . '</p>';
                } else {
                    echo '<p class="error">Расхождение! В data-value: ' . floatval($matches[1]) . ', расчетная сумма: ' . $total_prize_fund . '</p>';
                }
            }
        } else {
            echo '<p class="error">Не удалось загрузить страницу архива конкурсов.</p>';
        }
    } else {
        echo '<p class="error">Не удалось получить URL архивной страницы.</p>';
    }
    
    // Восстанавливаем глобальные переменные
    wp_reset_postdata();
    ?>
    
    <h2>Рекомендации по исправлению</h2>
    <p>Если общая сумма отличается от отображаемой на сайте, убедитесь, что:</p>
    <ol>
        <li>Метод <code>update_contests_data()</code> в файле <code>contests/public/class-contest-ajax.php</code> корректно суммирует призовые фонды.</li>
        <li>JavaScript-функция обновления данных на фронтенде корректно обрабатывает полученное значение (используйте parseFloat вместо parseInt).</li>
        <li>Статичное значение в HTML-шаблоне <code>archive-contests.php</code> соответствует реальной сумме.</li>
    </ol>
    
</body>
</html>
<?php 