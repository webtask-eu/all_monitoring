<?php
/*
Plugin Name: FT Trader Contests
Plugin URI: https://intellarax.com
Description: Плагин для проведения конкурсов трейдеров. Добавляет в админку тип записи "Конкурсы трейдеров" со всеми необходимыми полями.
Version: 1.0
Author: Yuriy Dzen
Author URI: https://intellarax.com
Text Domain: ft-trader-contests
Domain Path: /languages
License: GPL2
*/

// Определяем константу с путем к основному файлу плагина
if (!defined('FTTRADER_PLUGIN_FILE')) {
    define('FTTRADER_PLUGIN_FILE', __FILE__);
}

// Подключаем файл менеджера Cron
require_once plugin_dir_path(__FILE__) . 'includes/class-cron-manager.php';


// Подключаем общие классы
require_once plugin_dir_path(__FILE__) . 'includes/class-installer.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-api-handler.php';
require_once plugin_dir_path(__FILE__) . 'includes/admin-metaboxes.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-orders.php';
require_once plugin_dir_path(__FILE__) . 'admin/class-accounts-list-table.php';
require_once plugin_dir_path(__FILE__) . 'admin/class-settings-page.php'; 
// Загружаем классы для работы с графиками
require_once plugin_dir_path(__FILE__) . 'includes/class-account-chart-data.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-account-ajax-handlers.php';

// Подключаем класс для управления обновлениями счетов
require_once plugin_dir_path(__FILE__) . 'includes/class-account-updater.php';

// Подключаем класс для проверки условий дисквалификации
require_once plugin_dir_path(__FILE__) . 'includes/class-disqualification-checker.php';

// Подключаем новый фронтенд
require_once plugin_dir_path(__FILE__) . 'includes/front-templates.php';
require_once plugin_dir_path(__FILE__) . 'public/class-contest-ajax.php';

// Подключаем необходимые файлы
require_once plugin_dir_path(__FILE__) . 'includes/class-api-handler.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-account-updater.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-account-history.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-cron-manager.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-disqualification-checker.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-db-manager.php';

// Подключаем классы
require_once plugin_dir_path(__FILE__) . 'includes/class-installer.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-brokers-platforms.php';
require_once plugin_dir_path(__FILE__) . 'admin/class-brokers-platforms-admin.php';

/**
 * Активация плагина
 */
function fttradingapi_activate() {
    // Создаем таблицы в базе данных
    FTTrader_Installer::create_tables();
    FTTrader_Installer::update_tables();
    
    // Регистрируем тип записи для конкурсов, если еще не зарегистрирован
    fttradingapi_register_contests_post_type();
    
    // Обновляем поля сортировки конкурсов
    fttradingapi_update_sorting_on_activate();
    
    // Создаем таблицы для справочников
    do_action('ft_trader_contest_activate');
    
    // Принудительно создаем таблицы справочников
    if (class_exists('FTTrader_Brokers_Platforms')) {
        FTTrader_Brokers_Platforms::create_tables();
    }
    
    // Создаем базовые записи в справочниках, если они пусты
    fttradingapi_create_default_reference_data();
    
    // Сбрасываем правила перезаписи
    flush_rewrite_rules();
}
register_activation_hook(__FILE__, 'fttradingapi_activate');

/**
 * Деактивация плагина
 */
function fttradingapi_deactivate() {
    // Очистка расписания автоматического обновления
    if (class_exists('Contest_Cron_Manager')) {
        Contest_Cron_Manager::deactivate();
    }
    
    // Сбрасываем правила перезаписи
    flush_rewrite_rules();
}
register_deactivation_hook(__FILE__, 'fttradingapi_deactivate');


// Подключаем админку только если мы в админ панели
if (is_admin()) {
    
    require_once plugin_dir_path(__FILE__) . 'admin/class-admin-menu.php';
    require_once plugin_dir_path(__FILE__) . 'admin/class-admin-pages.php';
}

/**
 * Регистрация типа записи "Конкурсы трейдеров"
 */
function fttradingapi_register_contests_post_type() {
    $labels = array(
        'name'               => 'Конкурсы трейдеров',
        'singular_name'      => 'Конкурс трейдеров',
        'menu_name'          => 'Конкурсы трейдеров',
        'name_admin_bar'     => 'Конкурс трейдеров',
        'add_new'            => 'Добавить конкурс',
        'add_new_item'       => 'Добавить новый конкурс',
        'new_item'           => 'Новый конкурс',
        'edit_item'          => 'Редактировать конкурс',
        'view_item'          => 'Просмотреть конкурс',
        'all_items'          => 'Все конкурсы',
        'search_items'       => 'Искать конкурсы',
        'parent_item_colon'  => 'Родительский конкурс:',
        'not_found'          => 'Конкурсы не найдены.',
        'not_found_in_trash' => 'В корзине конкурсы не найдены.'
    );
    
    $args = array(
        'labels'             => $labels,
        'public'             => true,
        'publicly_queryable' => true,
        'show_ui'            => true,
        'show_in_menu'       => true,
        'query_var'          => true,
        'rewrite'            => array('slug' => 'trader-contests'),
        'has_archive'        => true,
        'hierarchical'       => false,
        'menu_position'      => null,
        'menu_icon'          => 'dashicons-awards',
        'supports' => array('title', 'editor', 'thumbnail', 'excerpt', 'custom-fields'),
    );
    
    register_post_type('trader_contests', $args);
}
add_action('init', 'fttradingapi_register_contests_post_type');

/**
 * Изменяет сортировку конкурсов на странице архива
 * Сортирует конкурсы по дате начала конкурса
 *
 * @param WP_Query $query Объект запроса WordPress
 */
function fttradingapi_sort_contests_by_start_date($query) {
    // Проверяем, что это основной запрос на странице архива конкурсов
    if (!is_admin() && $query->is_main_query() && $query->is_post_type_archive('trader_contests')) {
        // Используем вспомогательное поле _contest_sorting_start_date для сортировки
        // Оно уже заполняется в функции fttradingapi_update_contest_sorting_field
        $query->set('meta_key', '_contest_sorting_start_date');
        $query->set('orderby', 'meta_value');
        $query->set('order', 'DESC');
        
        // Установим максимальное количество конкурсов на странице
        $query->set('posts_per_page', 12);
        
        // Проверяем, когда последний раз обновлялись поля сортировки
        $last_update = get_option('fttradingapi_sorting_fields_last_update', 0);
        $current_time = time();
        
        // Обновляем поля не чаще одного раза в день (86400 секунд)
        if ($current_time - $last_update > 86400) {
            fttradingapi_update_all_contest_sorting_fields(true);
            update_option('fttradingapi_sorting_fields_last_update', $current_time);
        }
    }
}
add_action('pre_get_posts', 'fttradingapi_sort_contests_by_start_date');

/**
 * Обновляет вспомогательное мета-поле для сортировки при сохранении конкурса
 * 
 * @param int $post_id ID конкурса
 */
function fttradingapi_update_contest_sorting_field($post_id) {
    // Выходим, если это не наш тип записи
    if (get_post_type($post_id) !== 'trader_contests') {
        return;
    }
    
    // Проверяем старый формат даты начала
    $start_date = get_post_meta($post_id, '_contest_start_date', true);
    $start_date_source = 'old_format';
    
    // Если старый формат не найден, проверяем новый формат
    if (empty($start_date)) {
        $contest_data = get_post_meta($post_id, '_fttradingapi_contest_data', true);
        
        if (is_array($contest_data)) {
            // Сначала проверяем date_start (используется в админке)
            if (isset($contest_data['date_start']) && !empty($contest_data['date_start'])) {
                $start_date = $contest_data['date_start'];
                $start_date_source = 'new_format_date_start';
            } 
            // Затем проверяем start_date (используется в некоторых функциях)
            elseif (isset($contest_data['start_date']) && !empty($contest_data['start_date'])) {
                $start_date = $contest_data['start_date'];
                $start_date_source = 'new_format_start_date';
            }
        }
    }
    
    // Если дата найдена, обновляем специальное поле для сортировки
    if (!empty($start_date)) {
        // Нормализуем формат даты для корректной сортировки
        // Если дата в формате 'Y-m-d', добавляем время
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_date)) {
            $start_date .= ' 00:00:00';
        }
        
        update_post_meta($post_id, '_contest_sorting_start_date', $start_date);
        error_log(sprintf(
            'Обновлено поле сортировки для конкурса #%d: дата %s, источник: %s',
            $post_id,
            $start_date,
            $start_date_source
        ));
    } else {
        // Если дата не найдена, устанавливаем текущую дату для корректной сортировки
        $current_date = current_time('Y-m-d H:i:s');
        update_post_meta($post_id, '_contest_sorting_start_date', $current_date);
        error_log(sprintf(
            'Дата начала не найдена для конкурса #%d: установлена текущая дата %s для сортировки',
            $post_id,
            $current_date
        ));
    }
}

// Хук для обновления поля сортировки при сохранении конкурса
add_action('save_post', 'fttradingapi_update_contest_sorting_field');

// Также обновим поля сортировки для всех существующих конкурсов
function fttradingapi_update_all_contest_sorting_fields($force = false) {
    // Проверяем, что функция уже была запущена
    $updated = get_option('fttradingapi_sorting_fields_updated', false);
    if ($updated && !$force) {
        return;
    }
    
    // Получаем все конкурсы
    $contests = get_posts(array(
        'post_type' => 'trader_contests',
        'numberposts' => -1,
        'post_status' => 'any'
    ));
    
    // Обновляем поле сортировки для каждого конкурса
    $updated_count = 0;
    $debug_info = $force ? array() : null; // Собираем отладочную информацию только при принудительном обновлении
    
    foreach ($contests as $contest) {
        fttradingapi_update_contest_sorting_field($contest->ID);
        $updated_count++;
        
        // Собираем информацию о первых 5 конкурсах для отладки
        if ($force && count($debug_info) < 5) {
            $date = get_post_meta($contest->ID, '_contest_sorting_start_date', true);
            $debug_info[] = sprintf(
                'ID: %d, Название: %s, Дата сортировки: %s',
                $contest->ID,
                $contest->post_title,
                $date
            );
        }
    }
    
    // Отмечаем, что функция была запущена и фиксируем время
    update_option('fttradingapi_sorting_fields_updated', true);
    update_option('fttradingapi_sorting_fields_last_update', time());
    
    // Записываем отладочную информацию
    if ($force) {
        $log_message = sprintf(
            'Массовое обновление полей сортировки завершено. Обновлено конкурсов: %d. Примеры: %s',
            $updated_count,
            implode(' | ', $debug_info)
        );
    } else {
        $log_message = sprintf(
            'Массовое обновление полей сортировки завершено. Обновлено конкурсов: %d.',
            $updated_count
        );
    }
    
    error_log($log_message);
}

// Запускаем обновление полей сортировки при активации плагина
add_action('admin_init', 'fttradingapi_update_all_contest_sorting_fields');

// Добавляем обновление полей сортировки при активации плагина
function fttradingapi_update_sorting_on_activate() {
    fttradingapi_update_all_contest_sorting_fields(true);
}
register_activation_hook(__FILE__, 'fttradingapi_update_sorting_on_activate');

/**
 * Добавляет действие "Копировать" в список действий для конкурсов
 * 
 * @param array $actions Массив действий
 * @param WP_Post $post Объект поста
 * @return array Обновленный массив действий
 */
function fttradingapi_add_duplicate_contest_action($actions, $post) {
    if (current_user_can('edit_posts') && $post->post_type === 'trader_contests') {
        $actions['duplicate'] = sprintf(
            '<a href="%s" aria-label="%s">%s</a>',
            wp_nonce_url(
                add_query_arg(
                    array(
                        'action' => 'duplicate_contest',
                        'post_id' => $post->ID,
                    ),
                    admin_url('admin.php')
                ),
                'duplicate_contest_' . $post->ID
            ),
            esc_attr(sprintf(__('Копировать &#8220;%s&#8221;'), $post->post_title)),
            __('Копировать')
        );
    }
    return $actions;
}
add_filter('post_row_actions', 'fttradingapi_add_duplicate_contest_action', 10, 2);

/**
 * Обрабатывает запрос на копирование конкурса
 */
function fttradingapi_handle_duplicate_contest() {
    // Проверяем, передан ли ID конкурса и действие
    if (isset($_GET['action']) && $_GET['action'] === 'duplicate_contest' && (isset($_GET['post']) || isset($_GET['post_id']))) {
        // Проверяем права пользователя
        if (!current_user_can('edit_posts')) {
            wp_die(__('Вы не имеете прав для выполнения этого действия.'));
        }
        
        // Получаем ID поста. Проверяем post_id сначала, затем post
        $post_id = isset($_GET['post_id']) ? intval($_GET['post_id']) : intval($_GET['post']);
        
        // Проверяем nonce
        if (!wp_verify_nonce($_GET['_wpnonce'], 'duplicate_contest_' . $post_id)) {
            wp_die(__('Действие запрещено.'));
        }
        
        // Получаем данные исходного конкурса
        $post = get_post($post_id);
        if (empty($post) || $post->post_type !== 'trader_contests') {
            wp_die(__('Конкурс не найден.'));
        }
        
        // Создаем дубликат поста
        $new_post = array(
            'post_title'    => $post->post_title . ' (копия)',
            'post_content'  => $post->post_content,
            'post_excerpt'  => $post->post_excerpt,
            'post_status'   => 'draft',
            'post_type'     => $post->post_type,
            'post_author'   => get_current_user_id(),
            'ping_status'   => $post->ping_status,
            'post_password' => $post->post_password,
            'to_ping'       => $post->to_ping,
            'menu_order'    => $post->menu_order
        );
        
        // Вставляем новый пост
        $new_post_id = wp_insert_post($new_post);
        
        if ($new_post_id) {
            // Копируем метаданные
            $meta = get_post_meta($post_id, '_fttradingapi_contest_data', true);
            if (!empty($meta)) {
                update_post_meta($new_post_id, '_fttradingapi_contest_data', $meta);
            }
            
            // Копируем миниатюру, если есть
            if (has_post_thumbnail($post_id)) {
                $thumbnail_id = get_post_thumbnail_id($post_id);
                set_post_thumbnail($new_post_id, $thumbnail_id);
            }
            
            // Редирект на страницу редактирования нового конкурса
            wp_redirect(admin_url('post.php?post=' . $new_post_id . '&action=edit'));
            exit;
        } else {
            wp_die(__('Ошибка при создании копии конкурса.'));
        }
    }
}
add_action('admin_init', 'fttradingapi_handle_duplicate_contest');

/**
 * Подключение скриптов и стилей для админки
 */
function fttradingapi_enqueue_admin_scripts($hook) {
    $allowed_hooks = array(
        'toplevel_page_ft-trader-accounts',
        'post.php',
        'post-new.php', // Добавляем для страницы создания нового конкурса
        'trader_contests_page_trader_contests_accounts',
        'trader_contests_page_trader_contests_accounts_edit',
        'trader_contests_page_trader_contests_accounts_view'
    );

    // Проверяем, что мы на странице редактирования конкурса
    $screen = get_current_screen();
    $is_contest_edit = ($hook == 'post.php' || $hook == 'post-new.php') && $screen && $screen->post_type == 'trader_contests';

    if (in_array($hook, $allowed_hooks) || $is_contest_edit) {
        // CSS для админки
        wp_enqueue_style(
            'ft-trader-admin-css',
            plugins_url('admin/css/admin.css', __FILE__),
            array(),
            '1.0.1' // Увеличиваем версию для обновления кеша
        );
        
        // JavaScript для админки
        wp_enqueue_script(
            'ft-trader-admin-js',
            plugins_url('admin/js/admin.js', __FILE__),
            array('jquery', 'jquery-ui-sortable', 'media-upload'), // Добавляем зависимость от jquery-ui-sortable для перетаскивания
            '1.0.1', // Увеличиваем версию для обновления кеша
            true
        );

        // Локализация скрипта с необходимыми данными
        wp_localize_script('ft-trader-admin-js', 'ftTraderAdmin', array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('ft_trader_nonce'),
            'contestNonce' => wp_create_nonce('ft_contest_nonce'),
            'currentTime' => current_time('timestamp'),
            'accountHistoryNonce' => wp_create_nonce('account_history_nonce'),
            'i18n' => array(
                'confirmDeletePrize' => 'Вы уверены, что хотите удалить это призовое место?',
                'place' => 'Место',
                'amount' => 'Сумма',
                'description' => 'Описание',
                'delete' => 'Удалить'
            )
        ));

        // Добавляем Chart.js
        wp_enqueue_script(
            'chartjs',
            'https://cdn.jsdelivr.net/npm/chart.js@3.7.0/dist/chart.min.js',
            array(),
            '3.7.0',
            true
        );

        // Добавляем наш скрипт для работы с графиком
        wp_enqueue_script(
            'ft-trader-account-chart',
            plugins_url('admin/js/account-chart.js', __FILE__),
            array('jquery', 'chartjs', 'ft-trader-admin-js'),
            '1.0.0',
            true
        );

        // Локализация скрипта с необходимыми данными
        wp_localize_script('ft-trader-account-chart', 'ftAccountChart', array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('account_chart_nonce'),
            'i18n' => array(
                'loading' => 'Загрузка данных...',
                'error' => 'Ошибка загрузки данных',
                'noData' => 'Нет данных для отображения'
            )
        ));
    }
    wp_enqueue_media();
}

add_action('admin_enqueue_scripts', 'fttradingapi_enqueue_admin_scripts');




/**
 * Добавление метабоксов
 */
function fttradingapi_add_contest_metaboxes() {
    add_meta_box(
        'fttradingapi_contest_main_data',
        'Основная информация о конкурсе',
        'fttradingapi_contest_main_data_callback',
        'trader_contests',
        'normal',
        'high'
    );
    
    add_meta_box(
        'fttradingapi_contest_settings',
        'Настройки конкурса',
        'fttradingapi_contest_settings_callback',
        'trader_contests',
        'normal',
        'default'
    );

    add_meta_box(
        'fttradingapi_contest_conditions',
        'Условия конкурса',
        'fttradingapi_contest_conditions_callback',
        'trader_contests',
        'normal',
        'default'
    );
    
    // Добавляем новый метабокс для призовых мест
    add_meta_box(
        'fttradingapi_contest_prizes',
        'Призовые места',
        'fttradingapi_contest_prizes_callback',
        'trader_contests',
        'normal',
        'default'
    );
}
add_action('add_meta_boxes', 'fttradingapi_add_contest_metaboxes');



/**
 * CALLBACK ДЛЯ МЕТАПОЛЯ "Основная информация"
 */
function fttradingapi_contest_main_data_callback($post) {
    // Получим сохранённые метаданные (если они есть)
    $values = get_post_meta($post->ID, '_fttradingapi_contest_data', true);
    
    // Если метаданные ещё не созданы, инициализируем как пустой массив
    if (!is_array($values)) {
        $values = array();
    }
    
    // Подготовим вспомогательную функцию для удобного вывода
    function field_val($key, $arr) {
        return isset($arr[$key]) ? esc_attr($arr[$key]) : '';
    }
    
    // Поля
    $contest_name        = get_the_title($post->ID); // или field_val('contest_name', $values)
    $description         = field_val('description', $values);
    $full_desc           = field_val('full_desc', $values);
    $page_title          = field_val('page_title', $values);
    $seo_title           = field_val('seo_title', $values);
    $seo_keys            = field_val('seo_keys', $values);
    $seo_desc            = field_val('seo_desc', $values);
    $contest_slug        = field_val('contest_slug', $values);
    $contest_status      = field_val('contest_status', $values);
    $registration        = field_val('registration', $values);
    $start_deposit       = field_val('start_deposit', $values);
    $advisors_allowed    = field_val('advisors_allowed', $values);
    $broker_id           = field_val('broker_id', $values);
    $platform_id         = field_val('platform_id', $values);
    $server_val          = field_val('server', $values);
    $servers             = field_val('servers', $values); // legacy
    $servers_selected    = array_filter(array_map('trim', explode("\n", $servers)));
    $terminals           = field_val('terminals', $values); // legacy
    $end_registration    = field_val('end_registration', $values);
    $date_start          = field_val('date_start', $values);
    $date_end            = field_val('date_end', $values);

    $brokers_list   = class_exists('FTTrader_Brokers_Platforms') ? FTTrader_Brokers_Platforms::get_brokers() : array();
    $platforms_list = array();
    $servers_list   = array();

    if ($broker_id && class_exists('FTTrader_Brokers_Platforms')) {
        $platforms_list = FTTrader_Brokers_Platforms::get_broker_platforms($broker_id);
        if ($platform_id) {
            $servers_list = FTTrader_Brokers_Platforms::get_broker_servers($broker_id, $platform_id);
        }
    }

    // HTML-форма
    ?>
    <table class="form-table">
        <tbody>

        <!-- Название конкурса (по умолчанию это title) -->
        <tr>
            <th scope="row">
                <!-- Исправляем метку, убирая атрибут for, так как нет соответствующего элемента -->
                <span class="title-label">Название</span>
            </th>
            <td>
                <!-- Обычно мы используем стандартное поле "title" для названия,
                     поэтому здесь можем только подсказать, что оно заполняется сверху -->
                <em>Название задаётся в блоке выше («Заголовок»).</em>
            </td>
        </tr>
        
        <!-- Описание -->
        <tr>
            <th scope="row"><label for="description">Описание</label></th>
            <td>
                <textarea name="fttradingapi_contest_data[description]" id="description" rows="3" style="width:100%;"><?php echo esc_textarea($description); ?></textarea>
            </td>
        </tr>

        <!-- Full Desc -->
        <tr>
            <th scope="row"><label for="full_desc">Full Desc</label></th>
            <td>
                <textarea name="fttradingapi_contest_data[full_desc]" id="full_desc" rows="5" style="width:100%;"><?php echo esc_textarea($full_desc); ?></textarea>
            </td>
        </tr>

        <!-- Page Title -->
        <tr>
            <th scope="row"><label for="page_title">Page Title</label></th>
            <td>
                <input type="text" name="fttradingapi_contest_data[page_title]" id="page_title" value="<?php echo $page_title; ?>" style="width:100%;" />
            </td>
        </tr>

        <!-- Seo Title -->
        <tr>
            <th scope="row"><label for="seo_title">Seo Title</label></th>
            <td>
                <input type="text" name="fttradingapi_contest_data[seo_title]" id="seo_title" value="<?php echo $seo_title; ?>" style="width:100%;" />
            </td>
        </tr>

        <!-- Seo Keys -->
        <tr>
            <th scope="row"><label for="seo_keys">Seo Keys</label></th>
            <td>
                <input type="text" name="fttradingapi_contest_data[seo_keys]" id="seo_keys" value="<?php echo $seo_keys; ?>" style="width:100%;" />
            </td>
        </tr>

        <!-- Seo Desc -->
        <tr>
            <th scope="row"><label for="seo_desc">Seo Desc</label></th>
            <td>
                <textarea name="fttradingapi_contest_data[seo_desc]" id="seo_desc" rows="3" style="width:100%;"><?php echo esc_textarea($seo_desc); ?></textarea>
            </td>
        </tr>

        <!-- Изображение -->
        <tr>
            <th scope="row">
                <!-- Исправляем метку, убирая атрибут for, так как нет соответствующего элемента -->
                <span class="featured-image-label">Изображение конкурса</span>
            </th>
            <td>
                <p class="description">Используйте стандартный блок "Изображение записи" в правой колонке для загрузки изображения конкурса.</p>
            </td>
        </tr>

        <!-- Спонсор конкурса -->
        <tr>
            <th scope="row"><label for="sponsor">Спонсор конкурса</label></th>
            <td>
                <input type="text" name="fttradingapi_contest_data[sponsor]" id="sponsor" value="<?php echo field_val('sponsor', $values); ?>" style="width:100%;" />
            </td>
        </tr>

        <!-- Логотип спонсора -->
        <tr>
            <th scope="row"><label for="sponsor_logo">Логотип спонсора</label></th>
            <td>
                <div class="sponsor-logo-upload">
                    <input type="text" name="fttradingapi_contest_data[sponsor_logo]" id="sponsor_logo" value="<?php echo field_val('sponsor_logo', $values); ?>" style="width:80%;" />
                    <button type="button" class="button button-secondary" id="upload_sponsor_logo_button">Выбрать изображение</button>
                </div>
                <div class="sponsor-logo-preview" style="margin-top: 10px; max-width: 200px;">
                    <?php if (!empty(field_val('sponsor_logo', $values))): ?>
                        <img src="<?php echo field_val('sponsor_logo', $values); ?>" style="max-width: 100%; height: auto;" />
                    <?php endif; ?>
                </div>
                <p class="description">Загрузите логотип спонсора конкурса. Рекомендуемый размер: 200x60px.</p>
            </td>
        </tr>


        <!-- Contest slug -->
        <tr>
            <th scope="row"><label for="contest_slug">Contest slug</label></th>
            <td>
                <input type="text" name="fttradingapi_contest_data[contest_slug]" id="contest_slug" value="<?php echo $contest_slug; ?>" style="width:100%;" />
            </td>
        </tr>

        <!-- Статус -->
        <tr>
            <th scope="row"><label for="contest_status">Статус</label></th>
            <td>
                <select name="fttradingapi_contest_data[contest_status]" id="contest_status">
                    <option value="draft" <?php selected($contest_status, 'draft'); ?>>Черновик</option>
                    <option value="active" <?php selected($contest_status, 'active'); ?>>Активен</option>
                    <option value="finished" <?php selected($contest_status, 'finished'); ?>>Завершён</option>
                </select>
            </td>
        </tr>

        <!-- Архивный конкурс -->
        <tr>
            <th scope="row"><label for="is_archived">Архивный конкурс</label></th>
            <td>
                <input type="checkbox" name="fttradingapi_contest_data[is_archived]" id="is_archived" value="1" <?php checked(field_val('is_archived', $values), '1'); ?> />
                <p class="description">Если отмечено, для конкурса будет показана страница архива вместо обычной.</p>
            </td>
        </tr>

        <!-- Регистрация -->
        <tr>
            <th scope="row"><label for="registration">Регистрация</label></th>
            <td>
                <select name="fttradingapi_contest_data[registration]" id="registration">
                    <option value="open" <?php selected($registration, 'open'); ?>>Открыта</option>
                    <option value="closed" <?php selected($registration, 'closed'); ?>>Закрыта</option>
                </select>
            </td>
        </tr>

        <!-- Start Deposit -->
        <tr>
            <th scope="row"><label for="start_deposit">Start Deposit</label></th>
            <td>
                <input type="number" name="fttradingapi_contest_data[start_deposit]" id="start_deposit" value="<?php echo $start_deposit; ?>" style="width:100%;" />
            </td>
        </tr>

        <!-- Советники (разрешены/нет) -->
        <tr>
            <th scope="row"><label for="advisors_allowed">Советники</label></th>
            <td>
                <select name="fttradingapi_contest_data[advisors_allowed]" id="advisors_allowed">
                    <option value="1" <?php selected($advisors_allowed, '1'); ?>>Разрешены</option>
                    <option value="0" <?php selected($advisors_allowed, '0'); ?>>Запрещены</option>
                </select>
            </td>
        </tr>

        <!-- Брокер -->
        <tr>
            <th scope="row"><label for="broker">Брокер</label></th>
            <td>
                <select name="fttradingapi_contest_data[broker_id]" id="broker">
                    <option value="">Выберите брокера</option>
                    <?php foreach ($brokers_list as $broker): ?>
                        <option value="<?php echo esc_attr($broker->id); ?>" <?php selected($broker_id, $broker->id); ?>><?php echo esc_html($broker->name); ?></option>
                    <?php endforeach; ?>
                </select>
            </td>
        </tr>

        <!-- Платформа -->
        <tr>
            <th scope="row"><label for="platform">Платформа</label></th>
            <td>
                <select name="fttradingapi_contest_data[platform_id]" id="platform" data-selected="<?php echo esc_attr($platform_id); ?>" <?php echo $broker_id ? '' : 'disabled'; ?> >
                    <?php if (!$broker_id): ?>
                        <option value="">Сначала выберите брокера</option>
                    <?php else: ?>
                        <option value="">Выберите платформу</option>
                        <?php foreach ($platforms_list as $platform): ?>
                            <option value="<?php echo esc_attr($platform->id); ?>" <?php selected($platform_id, $platform->id); ?>><?php echo esc_html($platform->name); ?></option>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </select>
            </td>
        </tr>

        <!-- Сервер -->
        <tr>
            <th scope="row"><label for="servers-container">Серверы</label></th>
            <td>
                <div id="servers-container" class="servers-checkbox-container" data-selected="<?php echo esc_attr(implode(',', $servers_selected)); ?>" <?php echo ($broker_id && $platform_id) ? '' : 'data-disabled="true"'; ?>>
                    <?php if (!$platform_id): ?>
                        <div class="servers-placeholder">Сначала выберите платформу</div>
                    <?php else: ?>
                        <div class="servers-controls">
                            <a href="#" class="select-all-servers">Выбрать все</a> | 
                            <a href="#" class="deselect-all-servers">Снять выбор</a>
                        </div>
                        <div class="servers-list">
                            <?php foreach ($servers_list as $srv): ?>
                                <label class="server-checkbox-item">
                                    <input type="checkbox" 
                                           name="fttradingapi_contest_data[servers][]" 
                                           value="<?php echo esc_attr($srv->server_address); ?>" 
                                           <?php echo in_array($srv->server_address, $servers_selected, true) ? 'checked' : ''; ?>
                                           <?php echo ($broker_id && $platform_id) ? '' : 'disabled'; ?>>
                                    <span class="server-name"><?php echo esc_html($srv->name); ?></span>
                                    <span class="server-address"><?php echo esc_html($srv->server_address); ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="servers-selected-count" style="display:none;">
                    Выбрано серверов: <span class="count">0</span>
                </div>
            </td>
        </tr>

        <!-- Завершение регистрации (дата) -->
        <tr>
            <th scope="row"><label for="end_registration">Завершение регистрации</label></th>
            <td>
                <input type="date" name="fttradingapi_contest_data[end_registration]" id="end_registration" value="<?php echo $end_registration; ?>" />
            </td>
        </tr>

        <!-- Начало (дата) -->
        <tr>
            <th scope="row"><label for="date_start">Начало</label></th>
            <td>
                <input type="date" name="fttradingapi_contest_data[date_start]" id="date_start" value="<?php echo $date_start; ?>" />
            </td>
        </tr>

        <!-- Завершение (дата) -->
        <tr>
            <th scope="row"><label for="date_end">Завершение</label></th>
            <td>
                <input type="date" name="fttradingapi_contest_data[date_end]" id="date_end" value="<?php echo $date_end; ?>" />
            </td>
        </tr>

        </tbody>
    </table>
    <?php
}


/**
 * CALLBACK ДЛЯ МЕТАПОЛЯ "Настройки конкурса"
 */
function fttradingapi_contest_settings_callback($post) {
    $values = get_post_meta($post->ID, '_fttradingapi_contest_data', true);
    if (!is_array($values)) {
        $values = array();
    }

    $open_account_method     = isset($values['open_account_method']) ? $values['open_account_method'] : '';
    $show_leaderboard_chart  = isset($values['show_leaderboard_chart']) ? $values['show_leaderboard_chart'] : '';
    $stop_import_data        = isset($values['stop_import_data']) ? $values['stop_import_data'] : '';
    $stop_member_monitoring  = isset($values['stop_member_monitoring']) ? $values['stop_member_monitoring'] : '';
    ?>
    <table class="form-table">
        <tbody>

        <!-- Открытие счета -->
        <tr>
            <th scope="row"><span class="section-heading">Открытие счета</span></th>
            <td>
                <fieldset>
                    <legend class="screen-reader-text">Способы открытия счета</legend>
                    <label>
                        <input type="radio" name="fttradingapi_contest_data[open_account_method]" value="mt_api" <?php checked($open_account_method, 'mt_api'); ?> />
                        MetaTrader API
                    </label><br>
                    <label>
                        <input type="radio" name="fttradingapi_contest_data[open_account_method]" value="internal_mail" <?php checked($open_account_method, 'internal_mail'); ?> />
                        Внутренняя почта
                    </label><br>
                    <label>
                        <input type="radio" name="fttradingapi_contest_data[open_account_method]" value="manual" <?php checked($open_account_method, 'manual'); ?> />
                        Вручную трейдером
                    </label><br>
                    <label>
                        <input type="radio" name="fttradingapi_contest_data[open_account_method]" value="account_list" <?php checked($open_account_method, 'account_list'); ?> />
                        Список счетов
                    </label>
                </fieldset>
            </td>
        </tr>

        <!-- Показывать график лидеров -->
        <tr>
            <th scope="row"><label for="show_leaderboard_chart">Показывать график лидеров</label></th>
            <td>
                <input type="checkbox" name="fttradingapi_contest_data[show_leaderboard_chart]" id="show_leaderboard_chart" value="1" <?php checked($show_leaderboard_chart, '1'); ?> />
            </td>
        </tr>

        <!-- Stop import data -->
        <tr>
            <th scope="row"><label for="stop_import_data">Stop import data</label></th>
            <td>
                <input type="checkbox" name="fttradingapi_contest_data[stop_import_data]" id="stop_import_data" value="1" <?php checked($stop_import_data, '1'); ?> />
            </td>
        </tr>

        <!-- Stop member account monitoring and delete data -->
        <tr>
            <th scope="row"><label for="stop_member_monitoring">Stop member account monitoring and delete data</label></th>
            <td>
                <input type="checkbox" name="fttradingapi_contest_data[stop_member_monitoring]" id="stop_member_monitoring" value="1" <?php checked($stop_member_monitoring, '1'); ?> />
            </td>
        </tr>

        <!-- Управление участниками (пример) -->
        <tr>
            <th scope="row"><span class="section-heading">Управление участниками</span></th>
            <td>
                <em>Здесь можно реализовать логику «Завершение участников» (сделать кнопку или чекбокс)</em>
            </td>
        </tr>

        </tbody>
    </table>
    <?php
}


/**
 * CALLBACK ДЛЯ МЕТАПОЛЯ "Условия конкурса"
 */
function fttradingapi_contest_conditions_callback($post) {
    $values = get_post_meta($post->ID, '_fttradingapi_contest_data', true);
    if (!is_array($values)) {
        $values = array();
    }

    $trading_rules = isset($values['trading_rules']) ? $values['trading_rules'] : '';
    $terms_link    = isset($values['terms_link']) ? $values['terms_link'] : '';
    
    // Условия дисквалификации
    $check_initial_deposit = isset($values['check_initial_deposit']) ? $values['check_initial_deposit'] : '';
    $initial_deposit = isset($values['initial_deposit']) ? $values['initial_deposit'] : '';
    $check_leverage = isset($values['check_leverage']) ? $values['check_leverage'] : '';
    $allowed_leverage = isset($values['allowed_leverage']) ? $values['allowed_leverage'] : '';
    $check_instruments = isset($values['check_instruments']) ? $values['check_instruments'] : '';
    $allowed_instruments = isset($values['allowed_instruments']) ? $values['allowed_instruments'] : '';
    $excluded_instruments = isset($values['excluded_instruments']) ? $values['excluded_instruments'] : '';
    $check_max_volume = isset($values['check_max_volume']) ? $values['check_max_volume'] : '';
    $max_volume = isset($values['max_volume']) ? $values['max_volume'] : '';
    $check_min_trades = isset($values['check_min_trades']) ? $values['check_min_trades'] : '';
    $min_trades = isset($values['min_trades']) ? $values['min_trades'] : '';
    $check_hedged_positions = isset($values['check_hedged_positions']) ? $values['check_hedged_positions'] : '';
    $check_pre_contest_trades = isset($values['check_pre_contest_trades']) ? $values['check_pre_contest_trades'] : '';
    $check_min_profit = isset($values['check_min_profit']) ? $values['check_min_profit'] : '';
    $min_profit = isset($values['min_profit']) ? $values['min_profit'] : '';
    
    ?>
    <table class="form-table">
        <tbody>
            <tr>
                <th scope="row"><label for="trading_rules">Правила торговли</label></th>
                <td>
                    <textarea name="fttradingapi_contest_data[trading_rules]" id="trading_rules" rows="4" style="width:100%;" placeholder="Описать основные правила трейдинга..."><?php echo esc_textarea($trading_rules); ?></textarea>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="terms_link">Ссылка на условия</label></th>
                <td>
                    <input type="url" name="fttradingapi_contest_data[terms_link]" id="terms_link" style="width:100%;" value="<?php echo esc_attr($terms_link); ?>" placeholder="https://example.com/terms" />
                </td>
            </tr>
        </tbody>
    </table>
    
    <h3>Условия дисквалификации</h3>
    <table class="form-table">
        <tbody>
            <!-- 1. Проверка на начальный депозит -->
            <tr>
                <th scope="row">Проверка начального депозита</th>
                <td>
                    <fieldset>
                        <legend class="screen-reader-text">Проверка начального депозита</legend>
                        <input type="checkbox" name="fttradingapi_contest_data[check_initial_deposit]" id="check_initial_deposit" value="1" <?php checked($check_initial_deposit, '1'); ?> aria-controls="initial_deposit_row" />
                        <label for="check_initial_deposit">Дисквалифицировать при несовпадении начального депозита</label>
                    </fieldset>
                </td>
            </tr>
            <tr id="initial_deposit_row" <?php echo empty($check_initial_deposit) ? 'style="display:none;"' : ''; ?>>
                <th scope="row"><label for="initial_deposit">Требуемый начальный депозит</label></th>
                <td>
                    <input type="number" name="fttradingapi_contest_data[initial_deposit]" id="initial_deposit" style="width:150px;" value="<?php echo esc_attr($initial_deposit); ?>" step="0.01" min="0" />
                </td>
            </tr>
            
            <!-- 2. Проверка кредитного плеча -->
            <tr>
                <th scope="row">Проверка кредитного плеча</th>
                <td>
                    <fieldset>
                        <legend class="screen-reader-text">Проверка кредитного плеча</legend>
                        <input type="checkbox" name="fttradingapi_contest_data[check_leverage]" id="check_leverage" value="1" <?php checked($check_leverage, '1'); ?> aria-controls="allowed_leverage_row" />
                        <label for="check_leverage">Дисквалифицировать при несовпадении кредитного плеча</label>
                    </fieldset>
                </td>
            </tr>
            <tr id="allowed_leverage_row" <?php echo empty($check_leverage) ? 'style="display:none;"' : ''; ?>>
                <th scope="row"><label for="allowed_leverage">Допустимое кредитное плечо</label></th>
                <td>
                    <input type="text" name="fttradingapi_contest_data[allowed_leverage]" id="allowed_leverage" style="width:150px;" value="<?php echo esc_attr($allowed_leverage); ?>" placeholder="например: 1:100" />
                </td>
            </tr>
            
            <!-- 3. Проверка на инструменты -->
            <tr>
                <th scope="row">Проверка инструментов</th>
                <td>
                    <fieldset>
                        <legend class="screen-reader-text">Проверка инструментов</legend>
                        <input type="checkbox" name="fttradingapi_contest_data[check_instruments]" id="check_instruments" value="1" <?php checked($check_instruments, '1'); ?> aria-controls="allowed_instruments_row excluded_instruments_row" />
                        <label for="check_instruments">Дисквалифицировать при использовании запрещенных инструментов</label>
                    </fieldset>
                </td>
            </tr>
            <tr id="allowed_instruments_row" <?php echo empty($check_instruments) ? 'style="display:none;"' : ''; ?>>
                <th scope="row"><label for="allowed_instruments">Разрешенные инструменты</label></th>
                <td>
                    <textarea name="fttradingapi_contest_data[allowed_instruments]" id="allowed_instruments" rows="3" style="width:100%;" placeholder="EURUSD, GBPUSD, * (для всех)"><?php echo esc_textarea($allowed_instruments); ?></textarea>
                    <p class="description" id="allowed_instruments_desc">Укажите разрешенные инструменты через запятую. Используйте * для всех инструментов.</p>
                </td>
            </tr>
            <tr id="excluded_instruments_row" <?php echo empty($check_instruments) ? 'style="display:none;"' : ''; ?>>
                <th scope="row"><label for="excluded_instruments">Запрещенные инструменты</label></th>
                <td>
                    <textarea name="fttradingapi_contest_data[excluded_instruments]" id="excluded_instruments" rows="3" style="width:100%;" placeholder="*BTC*, USDJPY"><?php echo esc_textarea($excluded_instruments); ?></textarea>
                    <p class="description" id="excluded_instruments_desc">Укажите запрещенные инструменты через запятую. Используйте *XXX* для шаблона.</p>
                </td>
            </tr>
            
            <!-- 4. Максимальный суммарный объем -->
            <tr>
                <th scope="row">Проверка максимального объема</th>
                <td>
                    <fieldset>
                        <legend class="screen-reader-text">Проверка максимального объема</legend>
                        <input type="checkbox" name="fttradingapi_contest_data[check_max_volume]" id="check_max_volume" value="1" <?php checked($check_max_volume, '1'); ?> aria-controls="max_volume_row" />
                        <label for="check_max_volume">Дисквалифицировать при превышении максимального объема</label>
                    </fieldset>
                </td>
            </tr>
            <tr id="max_volume_row" <?php echo empty($check_max_volume) ? 'style="display:none;"' : ''; ?>>
                <th scope="row"><label for="max_volume">Максимальный суммарный объем открытых сделок</label></th>
                <td>
                    <input type="number" name="fttradingapi_contest_data[max_volume]" id="max_volume" style="width:150px;" value="<?php echo esc_attr($max_volume); ?>" step="0.01" min="0" />
                </td>
            </tr>
            
            <!-- 5. Минимальное количество сделок -->
            <tr>
                <th scope="row">Проверка минимального количества сделок</th>
                <td>
                    <fieldset>
                        <legend class="screen-reader-text">Проверка минимального количества сделок</legend>
                        <input type="checkbox" name="fttradingapi_contest_data[check_min_trades]" id="check_min_trades" value="1" <?php checked($check_min_trades, '1'); ?> aria-controls="min_trades_row check_hedged_positions_row" />
                        <label for="check_min_trades">Дисквалифицировать при недостаточном количестве сделок</label>
                    </fieldset>
                </td>
            </tr>
            <tr id="min_trades_row" <?php echo empty($check_min_trades) ? 'style="display:none;"' : ''; ?>>
                <th scope="row"><label for="min_trades">Минимальное количество сделок</label></th>
                <td>
                    <input type="number" name="fttradingapi_contest_data[min_trades]" id="min_trades" style="width:150px;" value="<?php echo empty($min_trades) || $min_trades === '0' ? '1' : esc_attr($min_trades); ?>" min="1" />
                </td>
            </tr>
            <tr id="check_hedged_positions_row" <?php echo empty($check_min_trades) ? 'style="display:none;"' : ''; ?>>
                <th scope="row">Проверка хеджированных позиций</th>
                <td>
                    <fieldset>
                        <legend class="screen-reader-text">Проверка хеджированных позиций</legend>
                        <input type="checkbox" name="fttradingapi_contest_data[check_hedged_positions]" id="check_hedged_positions" value="1" <?php checked($check_hedged_positions, '1'); ?> />
                        <label for="check_hedged_positions">Считать одновременно открытые и закрытые сделки в одном направлении по одному активу как одну позицию</label>
                    </fieldset>
                </td>
            </tr>
            
            <!-- 6. Проверка сделок до начала конкурса -->
            <tr>
                <th scope="row">Проверка сделок до конкурса</th>
                <td>
                    <fieldset>
                        <legend class="screen-reader-text">Проверка сделок до конкурса</legend>
                        <input type="checkbox" name="fttradingapi_contest_data[check_pre_contest_trades]" id="check_pre_contest_trades" value="1" <?php checked($check_pre_contest_trades, '1'); ?> />
                        <label for="check_pre_contest_trades">Дисквалифицировать при наличии сделок до даты начала конкурса</label>
                    </fieldset>
                </td>
            </tr>
            
            <!-- 7. Проверка на минимальную прибыль -->
            <tr>
                <th scope="row">Проверка минимальной прибыли</th>
                <td>
                    <fieldset>
                        <legend class="screen-reader-text">Проверка минимальной прибыли</legend>
                        <input type="checkbox" name="fttradingapi_contest_data[check_min_profit]" id="check_min_profit" value="1" <?php checked($check_min_profit, '1'); ?> aria-controls="min_profit_row" />
                        <label for="check_min_profit">Дисквалифицировать при прибыли меньше указанной на момент завершения</label>
                    </fieldset>
                </td>
            </tr>
            <tr id="min_profit_row" <?php echo empty($check_min_profit) ? 'style="display:none;"' : ''; ?>>
                <th scope="row"><label for="min_profit">Минимальная прибыль</label></th>
                <td>
                    <input type="number" name="fttradingapi_contest_data[min_profit]" id="min_profit" style="width:150px;" value="<?php echo esc_attr($min_profit); ?>" step="0.01" />
                </td>
            </tr>
        </tbody>
    </table>
    
    <script>
    jQuery(document).ready(function($) {
        // Функция для управления доступностью зависимых полей
        function toggleDependentFields(checkboxId, dependentFields) {
            var $checkbox = $('#' + checkboxId);
            var fieldsArray = dependentFields.split(' ');
            
            $checkbox.change(function() {
                var isChecked = $(this).is(':checked');
                
                // Показываем/скрываем зависимые поля
                fieldsArray.forEach(function(fieldId) {
                    if (fieldId) {
                        var $row = $('#' + fieldId);
                        if (isChecked) {
                            $row.show();
                            // Устанавливаем ARIA атрибуты
                            $checkbox.attr('aria-expanded', 'true');
                        } else {
                            $row.hide();
                            // Устанавливаем ARIA атрибуты
                            $checkbox.attr('aria-expanded', 'false');
                        }
                    }
                });
            });
            
            // Инициализация состояния
            $checkbox.change();
        }
        
        // Инициализируем все чекбоксы
        toggleDependentFields('check_initial_deposit', 'initial_deposit_row');
        toggleDependentFields('check_leverage', 'allowed_leverage_row');
        toggleDependentFields('check_instruments', 'allowed_instruments_row excluded_instruments_row');
        toggleDependentFields('check_max_volume', 'max_volume_row');
        toggleDependentFields('check_min_trades', 'min_trades_row check_hedged_positions_row');
        toggleDependentFields('check_min_profit', 'min_profit_row');
    });
    </script>
    <?php
}

/**
 * CALLBACK ДЛЯ МЕТАПОЛЯ "Призовые места"
 */
function fttradingapi_contest_prizes_callback($post) {
    // Получаем сохраненные призовые места
    $values = get_post_meta($post->ID, '_fttradingapi_contest_data', true);
    if (!is_array($values)) {
        $values = array();
    }
    
    // Получаем массив призовых мест или создаем пустой массив
    $prizes = isset($values['prizes']) ? $values['prizes'] : array();
    
    // Преобразуем в JSON для использования в JavaScript
    $prizes_json = json_encode($prizes);
    
    // Выводим интерфейс для управления призовыми местами
    ?>
    <div class="contest-prizes-container">
        <p class="description" id="prizes-description">Добавьте призовые места для конкурса. Вы можете добавить любое количество мест и указать для каждого сумму приза и описание.</p>
        
        <div class="prizes-table-container" aria-describedby="prizes-description">
            <table class="widefat prizes-table" id="prizes-table" role="grid" aria-label="Таблица призовых мест">
                <thead>
                    <tr>
                        <th class="prize-place-column" scope="col">Место</th>
                        <th class="prize-amount-column" scope="col">Сумма приза</th>
                        <th class="prize-description-column" scope="col">Описание</th>
                        <th class="prize-actions-column" scope="col">Действия</th>
                    </tr>
                </thead>
                <tbody id="prizes-list">
                    <!-- JavaScript будет добавлять строки сюда -->
                </tbody>
            </table>
        </div>
        
        <div class="prizes-actions">
            <button type="button" class="button button-secondary add-prize-button" id="add-prize-button" aria-controls="prizes-list" aria-label="Добавить новое призовое место">
                <span class="dashicons dashicons-plus" aria-hidden="true"></span> Добавить призовое место
            </button>
        </div>
        
        <!-- Скрытое поле для хранения JSON с призовыми местами -->
        <input type="hidden" name="fttradingapi_contest_data[prizes]" id="prizes-data" value="<?php echo esc_attr($prizes_json); ?>" />
    </div>
    <?php
}


/**
 * СОХРАНЕНИЕ МЕТАДАННЫХ
 */
function fttradingapi_save_contest_data($post_id) {
    // Проверим, что это не авто-сохранение
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
        return;
    }

    // Проверим права пользователя
    if (isset($_POST['post_type']) && $_POST['post_type'] === 'trader_contests') {
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }
    } else {
        return;
    }

    // Сохраняем данные только если есть массив с нашими полями
    if (isset($_POST['fttradingapi_contest_data']) && is_array($_POST['fttradingapi_contest_data'])) {
        $raw_data = $_POST['fttradingapi_contest_data'];

        if (isset($raw_data['servers']) && is_array($raw_data['servers'])) {
            $servers_array = array_map('sanitize_text_field', $raw_data['servers']);
            $raw_data['servers'] = implode("\n", $servers_array);
            $raw_data['server'] = $servers_array[0] ?? '';
        }

        $data = array_map('sanitize_text_field', $raw_data);

        // Для textarea (описание, правила и т.д.) лучше использовать sanitize_textarea_field
        if (isset($data['description'])) {
            $data['description'] = sanitize_textarea_field($_POST['fttradingapi_contest_data']['description']);
        }
        if (isset($data['full_desc'])) {
            $data['full_desc'] = sanitize_textarea_field($_POST['fttradingapi_contest_data']['full_desc']);
        }
        if (isset($data['seo_desc'])) {
            $data['seo_desc'] = sanitize_textarea_field($_POST['fttradingapi_contest_data']['seo_desc']);
        }
        if (isset($data['broker_id'])) {
            $data['broker_id'] = intval($_POST['fttradingapi_contest_data']['broker_id']);
        }
        if (isset($data['platform_id'])) {
            $data['platform_id'] = intval($_POST['fttradingapi_contest_data']['platform_id']);
        }
        // Поле servers уже обработано выше, значения server и servers установлены
        if (isset($data['terminals'])) {
            $data['terminals'] = sanitize_text_field($_POST['fttradingapi_contest_data']['terminals']);
        }
        if (isset($data['trading_rules'])) {
            $data['trading_rules'] = sanitize_textarea_field($_POST['fttradingapi_contest_data']['trading_rules']);
        }
        
        // Обработка полей дисквалификации
        // Checkbox поля - устанавливаем значения по умолчанию, если не включены
        $checkbox_fields = [
            'check_initial_deposit', 'check_leverage', 'check_instruments', 
            'check_max_volume', 'check_min_trades', 'check_hedged_positions',
            'check_pre_contest_trades', 'check_min_profit'
        ];
        
        foreach ($checkbox_fields as $field) {
            if (!isset($data[$field])) {
                $data[$field] = '0';
            }
        }
        
        // Числовые поля - преобразуем в числа
        $numeric_fields = ['initial_deposit', 'max_volume', 'min_trades', 'min_profit'];
        foreach ($numeric_fields as $field) {
            if (isset($data[$field])) {
                $data[$field] = floatval($data[$field]);
                
                // Для поля min_trades устанавливаем минимальное значение 1, если включена опция проверки
                if ($field === 'min_trades' && isset($data['check_min_trades']) && $data['check_min_trades'] === '1') {
                    $data[$field] = max(1, $data[$field]);
                }
            }
        }
        
        // Поля с текстовыми областями
        if (isset($data['allowed_instruments'])) {
            $data['allowed_instruments'] = sanitize_textarea_field($_POST['fttradingapi_contest_data']['allowed_instruments']);
        }
        if (isset($data['excluded_instruments'])) {
            $data['excluded_instruments'] = sanitize_textarea_field($_POST['fttradingapi_contest_data']['excluded_instruments']);
        }
        
        // Обработка призовых мест (они хранятся в JSON)
        if (isset($_POST['fttradingapi_contest_data']['prizes'])) {
            $prizes_json = stripslashes($_POST['fttradingapi_contest_data']['prizes']);
            $prizes = json_decode($prizes_json, true);
            
            // Проверяем, что данные корректно декодированы
            if (is_array($prizes)) {
                // Убираем возможные дубликаты мест
                $processed_places = array();
                $cleaned_prizes = array();
                
                // Санитизация каждого элемента массива призовых мест
                foreach ($prizes as &$prize) {
                    if (isset($prize['place'])) {
                        $prize['place'] = intval($prize['place']);
                        // Проверяем, не был ли уже обработан этот номер места
                        if (!in_array($prize['place'], $processed_places)) {
                            $processed_places[] = $prize['place'];
                            
                    if (isset($prize['amount'])) {
                        $prize['amount'] = sanitize_text_field($prize['amount']);
                    }
                    if (isset($prize['description'])) {
                        $prize['description'] = sanitize_textarea_field($prize['description']);
                            }
                            
                            $cleaned_prizes[] = $prize;
                        }
                    }
                }
                
                // Сортируем призовые места по номеру места
                usort($cleaned_prizes, function($a, $b) {
                    return $a['place'] - $b['place'];
                });
                
                // Пересчитываем номера мест
                foreach ($cleaned_prizes as $index => &$prize) {
                    $prize['place'] = $index + 1;
                }
                
                // Сохраняем обработанный массив
                $data['prizes'] = $cleaned_prizes;
            }
        }

        // Обработка URL логотипа спонсора
        if (isset($_POST['fttradingapi_contest_data']['sponsor_logo'])) {
            $data['sponsor_logo'] = esc_url_raw($_POST['fttradingapi_contest_data']['sponsor_logo']);
        }
        if (isset($_POST['fttradingapi_contest_data']['sponsor'])) {
            $data['sponsor'] = sanitize_text_field($_POST['fttradingapi_contest_data']['sponsor']);
        }

        update_post_meta($post_id, '_fttradingapi_contest_data', $data);
    }
}
add_action('save_post', 'fttradingapi_save_contest_data');

add_action('wp_ajax_fttradingapi_delete_account', 'handle_delete_account');

function handle_delete_account() {
    
    global $wpdb;
    $table_name = $wpdb->prefix . 'contest_members';
    
    $id = intval($_POST['id']);
    
    // Получаем информацию о счете, чтобы проверить владельца и получить ID конкурса
    $account = $wpdb->get_row($wpdb->prepare(
        "SELECT user_id, contest_id FROM $table_name WHERE id = %d",
        $id
    ));
    
    // Проверяем, что пользователь имеет право удалить счет (владелец или администратор)
    if (!$account || (!current_user_can('manage_options') && get_current_user_id() != $account->user_id)) {
        wp_send_json_error('У вас нет прав для удаления этого счета');
        return;
    }
    
    // Сохраняем ID конкурса для создания URL перенаправления
    $contest_id = isset($account->contest_id) ? $account->contest_id : 0;
    
    // Вызываем полную функцию удаления счета из Contest_Accounts_List_Table
    require_once plugin_dir_path(__FILE__) . 'admin/class-accounts-list-table.php';
    $accounts_table = new Contest_Accounts_List_Table();
    $result = $accounts_table->delete_account($id);
    
    if ($result) {
        // Формируем URL для перенаправления на страницу конкурса
        $redirect_url = $contest_id ? get_permalink($contest_id) : home_url('/trader-contests/');
        wp_send_json_success(['message' => 'Счет успешно удален', 'redirect' => $redirect_url]);
    } else {
        wp_send_json_error('Не удалось удалить счет');
    }
}

/**
 * Обработчик события cron для проверки дисквалификации счетов
 */
function fttradingapi_disqualification_check_handler() {
    global $wpdb;
    
    // Функция для вывода в консоль
    $console_log = function($message) {
        if (php_sapi_name() === 'cli') {
            echo '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
        }
    };
    
    $console_log('Начало проверки дисквалификации счетов');
    
    // Получаем все активные счета
    $table_name = $wpdb->prefix . 'contest_members';
    $accounts = $wpdb->get_results(
        "SELECT id, account_number, contest_id FROM $table_name 
         WHERE connection_status != 'disqualified'"
    );
    
    $total_accounts = count($accounts);
    $console_log("Найдено активных счетов: $total_accounts");
    
    if (empty($accounts)) {
        $console_log('Нет активных счетов для проверки');
        return;
    }
    
    // Инициализируем класс проверки дисквалификации
    $checker = new Contest_Disqualification_Checker();
    $disqualified_count = 0;
    
    // Проверяем каждый счет
    foreach ($accounts as $account) {
        // Получаем результат проверки
        $result = $checker->check_account_disqualification($account->id);
        
        // Если счет должен быть дисквалифицирован
        if ($result['is_disqualified']) {
            // Дисквалифицируем счет, передавая все причины
            $checker->disqualify_account($account->id, $result['reasons']);
            $disqualified_count++;
        }
    }
    
    // Завершение проверки
    $console_log(sprintf(
        'Проверка завершена. Дисквалифицировано счетов: %d из %d',
        $disqualified_count,
        $total_accounts
    ));
}

// Регистрируем обработчик для события cron
add_action('contest_accounts_disqualification_check', 'fttradingapi_disqualification_check_handler');

/**
 * Обработчик для отложенной проверки дисквалификации отдельного счета
 * 
 * @param int $account_id ID счета для проверки
 */
function fttradingapi_single_account_disqualification_check($account_id) {
    global $wpdb;
    
    // Запись в лог о начале проверки
    error_log("Starting disqualification check for single account ID: {$account_id}...");
    
    // Проверяем, существует ли счет
    $table_name = $wpdb->prefix . 'contest_members';
    $account = $wpdb->get_row($wpdb->prepare(
        "SELECT id, account_number, contest_id, connection_status FROM $table_name WHERE id = %d",
        $account_id
    ));
    
    if (!$account) {
        error_log("Account ID: {$account_id} not found.");
        return;
    }
    
    // Пропускаем счета, которые уже дисквалифицированы
    if ($account->connection_status === 'disqualified') {
        error_log("Account ID: {$account_id} is already disqualified, skipping check.");
        return;
    }
    
    // Инициализируем класс проверки дисквалификации
    require_once plugin_dir_path(__FILE__) . 'includes/class-disqualification-checker.php';
    $checker = new Contest_Disqualification_Checker();
    
    // Получаем результат проверки
    $result = $checker->check_account_disqualification($account_id);
    
    // Если счет должен быть дисквалифицирован
    if ($result['is_disqualified']) {
        // Дисквалифицируем счет
        $checker->disqualify_account($account_id, $result['reason']);
        
        // Запись в лог
        error_log(sprintf(
            'Single check: Account #%s (ID: %d) disqualified. Reason: %s',
            $account->account_number,
            $account->id,
            $result['reason']
        ));
    } else {
        error_log(sprintf('Single check: Account #%s (ID: %d) meets all contest conditions.', 
            $account->account_number, $account->id));
    }
}

// Регистрируем обработчик для отложенной проверки дисквалификации отдельного счета
add_action('check_account_disqualification', 'fttradingapi_single_account_disqualification_check');

/**
 * Обработчик события cron для проверки статуса регистрации конкурсов
 */
function fttradingapi_registration_status_check_handler() {
    global $wpdb;
    
    // Запись в лог о начале проверки
    error_log('Starting contests registration status check...');
    
    // Получаем все конкурсы с открытой регистрацией
    $args = array(
        'post_type' => 'trader_contests',
        'post_status' => 'publish',
        'posts_per_page' => -1,
        'meta_query' => array(
            array(
                'key' => '_fttradingapi_contest_data',
                'value' => 'open',
                'compare' => 'LIKE'
            )
        )
    );
    
    $contests = get_posts($args);
    
    if (empty($contests)) {
        error_log('No contests with open registration to check.');
        return;
    }
    
    $closed_count = 0;
    $current_time = current_time('timestamp');
    
    // Проверяем каждый конкурс
    foreach ($contests as $contest) {
        $contest_data = get_post_meta($contest->ID, '_fttradingapi_contest_data', true);
        
        // Проверяем, если это массив и регистрация открыта
        if (is_array($contest_data) && isset($contest_data['registration']) && $contest_data['registration'] == 'open') {
            // Проверяем дату окончания регистрации
            $end_registration = isset($contest_data['end_registration']) ? $contest_data['end_registration'] : '';
            
            // Если дата задана и она уже наступила, закрываем регистрацию
            if (!empty($end_registration) && strtotime($end_registration) <= $current_time) {
                // Меняем статус регистрации на "закрыта"
                $contest_data['registration'] = 'closed';
                
                // Обновляем данные конкурса
                update_post_meta($contest->ID, '_fttradingapi_contest_data', $contest_data);
                
                $closed_count++;
                
                // Запись в лог
                error_log(sprintf(
                    'Contest ID: %d "%s" - registration status changed from "open" to "closed". End registration date: %s',
                    $contest->ID,
                    $contest->post_title,
                    $end_registration
                ));
            }
        }
    }
    
    // Запись в лог о завершении проверки
    error_log(sprintf('Registration status check completed. %d of %d contests were closed.', 
        $closed_count, count($contests)));
}

// Регистрируем обработчик для события cron проверки статуса регистрации
add_action('contest_registration_status_check', 'fttradingapi_registration_status_check_handler');

/**
 * Добавляет CSS для горизонтальной прокрутки к таблице счетов
 */
function fttradingapi_add_table_scroll_css() {
    $screen = get_current_screen();
    
    // Применяем стили только на странице списка счетов
    if ($screen && $screen->id === 'trader_contests_page_trader_contests_accounts') {
        ?>
        <style type="text/css">
            /* Стиль для горизонтальной прокрутки */
            .wp-list-table {
                display: block;
                overflow-x: auto;
                white-space: nowrap;
            }
            
            /* Фиксируем колонку с чекбоксами при прокрутке (опционально) */
            .wp-list-table thead th.check-column,
            .wp-list-table tbody td.check-column {
                position: sticky;
                left: 0;
                background-color: #f1f1f1;
                z-index: 1;
            }
            
            /* Добавляем небольшую тень для визуального разделения фиксированной колонки */
            .wp-list-table thead th.check-column {
                box-shadow: 2px 0 5px -2px rgba(0,0,0,0.1);
            }
            
            .wp-list-table tbody td.check-column {
                box-shadow: 2px 0 5px -2px rgba(0,0,0,0.1);
            }
        </style>
        <?php
    }
}
add_action('admin_head', 'fttradingapi_add_table_scroll_css');

/**
 * Добавляет подсказку о загрузке изображения конкурса
 */
function fttradingapi_add_contest_image_notice() {
    global $post_type, $pagenow;
    
    if (($pagenow == 'post.php' || $pagenow == 'post-new.php') && $post_type == 'trader_contests') {
        echo '<div class="notice notice-info is-dismissible"><p>Для добавления изображения конкурса используйте блок "Изображение записи" в правой колонке.</p></div>';
    }
}
add_action('admin_notices', 'fttradingapi_add_contest_image_notice');

/**
 * Подключение скриптов и стилей для фронтенда
 */
function fttradingapi_enqueue_frontend_scripts() {
    // CSS для фронтенда
    wp_enqueue_style(
        'ft-trader-frontend-css',
        plugins_url('frontend/css/frontend.css', __FILE__),
                array(),
        time() // Используем timestamp вместо фиксированной версии
            );
            
    // JavaScript для фронтенда
    /* Закомментировано, чтобы избежать двойной загрузки скрипта
       Скрипт уже зарегистрирован и подключен в includes/front-templates.php
    wp_enqueue_script(
        'ft-trader-frontend-js',
        plugins_url('frontend/js/frontend.js', __FILE__),
        array('jquery'),
        time(), // Используем timestamp вместо фиксированной версии
                true
            );
    */

            // Локализация скрипта
    wp_localize_script('ft-frontend-scripts', 'ftTraderFrontend', array(
                'ajaxurl' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('ft_contest_nonce'),
        'isUserLoggedIn' => is_user_logged_in(),
        'refreshInterval' => 60000 // Интервал обновления в миллисекундах
    ));
}
add_action('wp_enqueue_scripts', 'fttradingapi_enqueue_frontend_scripts');

/**
 * Регистрирует обработчик для страницы статистики трейдера
 */
function fttradingapi_add_trader_statistics_endpoint() {
    add_rewrite_rule(
        '^trader-statistics/?$',
        'index.php?trader_statistics=1',
        'top'
    );
    
    add_rewrite_tag('%trader_statistics%', '([^&]+)');
}
add_action('init', 'fttradingapi_add_trader_statistics_endpoint');

/**
 * Загружает шаблон страницы статистики трейдера
 */
function fttradingapi_load_trader_statistics_template($template) {
    if (get_query_var('trader_statistics') == 1) {
        $new_template = plugin_dir_path(__FILE__) . 'templates/trader-statistics.php';
        if (file_exists($new_template)) {
            return $new_template;
        }
    }
    return $template;
}
add_filter('template_include', 'fttradingapi_load_trader_statistics_template');

// Закомментировано, так как функция fttradingapi_init нигде не определена
// add_action('wp_loaded', 'fttradingapi_init', 30);

/**
 * Создание базовых записей в справочниках
 */
function fttradingapi_create_default_reference_data() {
    // Проверяем, есть ли уже записи в таблицах
    global $wpdb;
    $platforms_table = $wpdb->prefix . 'trading_platforms';
    $brokers_table = $wpdb->prefix . 'brokers';
    
    // Проверяем наличие таблиц
    if (!$wpdb->get_var("SHOW TABLES LIKE '$platforms_table'")) {
        error_log("Таблица $platforms_table не существует");
        return;
    }
    
    if (!$wpdb->get_var("SHOW TABLES LIKE '$brokers_table'")) {
        error_log("Таблица $brokers_table не существует");
        return;
    }
    
    // Проверяем наличие записей
    $platforms_count = $wpdb->get_var("SELECT COUNT(*) FROM $platforms_table");
    $brokers_count = $wpdb->get_var("SELECT COUNT(*) FROM $brokers_table");
    
    // Если нет записей, создаем базовые записи
    if ($platforms_count == 0) {
        // Добавляем платформы
        $platforms = array(
            array('name' => 'MetaTrader 4', 'slug' => 'metatrader-4', 'description' => 'Торговая платформа MetaTrader 4'),
            array('name' => 'MetaTrader 5', 'slug' => 'metatrader-5', 'description' => 'Торговая платформа MetaTrader 5'),
            array('name' => 'cTrader', 'slug' => 'ctrader', 'description' => 'Торговая платформа cTrader')
        );
        
        foreach ($platforms as $platform) {
            $wpdb->insert($platforms_table, array(
                'name' => $platform['name'],
                'slug' => $platform['slug'],
                'description' => $platform['description'],
                'status' => 'active'
            ));
        }
        
        error_log("Добавлены базовые платформы");
    }
    
    if ($brokers_count == 0) {
        // Добавляем брокеров
        $brokers = array(
            array('name' => 'FxPro', 'slug' => 'fxpro', 'description' => 'Брокер FxPro'),
            array('name' => 'Alpari', 'slug' => 'alpari', 'description' => 'Брокер Alpari'),
            array('name' => 'RoboForex', 'slug' => 'roboforex', 'description' => 'Брокер RoboForex')
        );
        
        foreach ($brokers as $broker) {
            $wpdb->insert($brokers_table, array(
                'name' => $broker['name'],
                'slug' => $broker['slug'],
                'description' => $broker['description'],
                'status' => 'active'
            ));
        }
        
        error_log("Добавлены базовые брокеры");
    }
    
    // Проверяем, есть ли записи серверов
    $servers_table = $wpdb->prefix . 'broker_servers';
    $servers_count = $wpdb->get_var("SELECT COUNT(*) FROM $servers_table");
    
    if ($servers_count == 0) {
        // Получаем ID платформ и брокеров
        $mt4_id = $wpdb->get_var("SELECT id FROM $platforms_table WHERE slug = 'metatrader-4'");
        $mt5_id = $wpdb->get_var("SELECT id FROM $platforms_table WHERE slug = 'metatrader-5'");
        
        $fxpro_id = $wpdb->get_var("SELECT id FROM $brokers_table WHERE slug = 'fxpro'");
        $alpari_id = $wpdb->get_var("SELECT id FROM $brokers_table WHERE slug = 'alpari'");
        $roboforex_id = $wpdb->get_var("SELECT id FROM $brokers_table WHERE slug = 'roboforex'");
        
        // Добавляем серверы для брокеров
        $servers = array();
        
        if ($fxpro_id && $mt4_id) {
            $servers[] = array(
                'broker_id' => $fxpro_id,
                'platform_id' => $mt4_id,
                'name' => 'FxPro MT4 Server 1',
                'server_address' => 'fxpro.mt4.server1',
                'description' => 'Основной сервер FxPro MT4'
            );
        }
        
        if ($fxpro_id && $mt5_id) {
            $servers[] = array(
                'broker_id' => $fxpro_id,
                'platform_id' => $mt5_id,
                'name' => 'FxPro MT5 Server',
                'server_address' => 'fxpro.mt5.server',
                'description' => 'Сервер FxPro MT5'
            );
        }
        
        if ($alpari_id && $mt4_id) {
            $servers[] = array(
                'broker_id' => $alpari_id,
                'platform_id' => $mt4_id,
                'name' => 'Alpari MT4 Real',
                'server_address' => 'alpari.mt4.real',
                'description' => 'Сервер Alpari MT4 для реальных счетов'
            );
            
            $servers[] = array(
                'broker_id' => $alpari_id,
                'platform_id' => $mt4_id,
                'name' => 'Alpari MT4 Demo',
                'server_address' => 'alpari.mt4.demo',
                'description' => 'Сервер Alpari MT4 для демо-счетов'
            );
        }
        
        if ($roboforex_id && $mt5_id) {
            $servers[] = array(
                'broker_id' => $roboforex_id,
                'platform_id' => $mt5_id,
                'name' => 'RoboForex MT5',
                'server_address' => 'roboforex.mt5',
                'description' => 'Сервер RoboForex MT5'
            );
        }
        
        foreach ($servers as $server) {
            $wpdb->insert($servers_table, array(
                'broker_id' => $server['broker_id'],
                'platform_id' => $server['platform_id'],
                'name' => $server['name'],
                'server_address' => $server['server_address'],
                'description' => $server['description'],
                'status' => 'active'
            ));
        }
        
        error_log("Добавлены базовые серверы брокеров");
    }
}

