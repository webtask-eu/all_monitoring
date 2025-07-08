<?php
if (!defined('ABSPATH')) {
    exit;
}

function fttradingapi_add_accounts_submenu() {
    // Существующий код для основной страницы счетов
    add_submenu_page(
        'edit.php?post_type=trader_contests',
        'Все счета',
        'Все счета', 
        'manage_options',
        'trader_contests_accounts',
        'fttradingapi_accounts_page_callback'
    );

    // Добавляем страницу редактирования
    add_submenu_page(
        null, // Скрытое меню
        'Редактирование счета',
        'Редактирование счета',
        'manage_options',
        'trader_contests_accounts_edit',
        'fttradingapi_edit_account_page_callback'
    );

    // Существующий код для просмотра
    add_submenu_page(
        null,
        'Просмотр счета',
        'Просмотр счета',
        'manage_options',
        'trader_contests_accounts_view',
        'fttradingapi_view_account_page_callback'
    );
}
add_action('admin_menu', 'fttradingapi_add_accounts_submenu');

/**
 * Регистрация скриптов и стилей администратора
 */
function enqueue_admin_scripts() {
    $screen = get_current_screen();
    
    // Проверяем, находимся ли мы на странице нашего плагина
    if (strpos($screen->id, 'trader_contests') !== false) {
        // Общие стили и скрипты для всех страниц плагина
        wp_enqueue_style('ft-trader-admin-style', plugin_dir_url(__FILE__) . 'css/admin.css', array(), FTTRADER_PLUGIN_VERSION);
        wp_enqueue_script('ft-trader-admin', plugin_dir_url(__FILE__) . 'js/admin.js', array('jquery'), FTTRADER_PLUGIN_VERSION, true);
        
        // Добавляем локализацию для скриптов
        wp_localize_script('ft-trader-admin', 'ftTraderAdmin', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('ft_trader_nonce')
        ));
        
        // На странице списка счетов добавляем основные админские скрипты WordPress для работы с чекбоксами
        if ($screen->id === 'trader-contests_page_trader_contests_accounts') {
            wp_enqueue_script('common');
            wp_enqueue_script('wp-lists');
            wp_enqueue_script('postbox');
        }

        // Подключаем скрипты и стили только для страницы создания/редактирования конкурса
        if (($screen->id === 'trader_contests' && $screen->base === 'post') || 
            ($screen->id === 'trader-contests_page_trader_contests_accounts_edit')) {
            
            // Подключаем датапикер jQuery UI
            wp_enqueue_script('jquery-ui-datepicker');
            wp_enqueue_style('jquery-ui-style', 'https://code.jquery.com/ui/1.12.1/themes/base/jquery-ui.css');
            
            // Подключаем медиа-загрузчик WordPress
            wp_enqueue_media();
            
            // Дополнительная локализация для jQuery датапикера
            wp_localize_script('ft-trader-admin', 'datepicker_vars', array(
                'dateFormat' => 'dd.mm.yy',
                'dayNames' => array(
                    'Воскресенье', 'Понедельник', 'Вторник', 'Среда',
                    'Четверг', 'Пятница', 'Суббота'
                ),
                'dayNamesMin' => array('Вс', 'Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб'),
                'monthNames' => array(
                    'Январь', 'Февраль', 'Март', 'Апрель', 'Май', 'Июнь',
                    'Июль', 'Август', 'Сентябрь', 'Октябрь', 'Ноябрь', 'Декабрь'
                ),
                'firstDay' => 1
            ));
        }
    }
}
add_action('admin_enqueue_scripts', 'enqueue_admin_scripts');