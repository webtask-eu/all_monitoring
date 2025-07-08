<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Класс для работы с шаблонами фронтенд-части
 */
class FT_Contest_Templates {
    /**
     * Инициализация класса
     */
    public static function init() {
        // Фильтры для подключения шаблонов
        add_filter('template_include', [self::class, 'template_include']);
        add_filter('body_class', [self::class, 'add_body_classes']);
        
        // Регистрация стилей и скриптов
        add_action('wp_enqueue_scripts', [self::class, 'register_assets']);
        
        // Регистрация шорткодов
        add_shortcode('contest_registration_form', [self::class, 'registration_form_shortcode']);
        
        // AJAX-обработчики
        add_action('wp_ajax_get_leaders_chart_data', [self::class, 'get_leaders_chart_data']);
        add_action('wp_ajax_nopriv_get_leaders_chart_data', [self::class, 'get_leaders_chart_data']);
    }
    
    /**
     * AJAX-обработчик для получения данных графика лидеров
     */
    public static function get_leaders_chart_data() {
        // Проверяем nonce
        check_ajax_referer('leaders_chart_nonce', 'nonce');
        
        // Получаем ID конкурса и период
        $contest_id = isset($_POST['contest_id']) ? intval($_POST['contest_id']) : 0;
        $period = isset($_POST['period']) ? sanitize_text_field($_POST['period']) : 'all';
        $top_count = isset($_POST['top_count']) ? intval($_POST['top_count']) : 3;
        
        if (!$contest_id) {
            wp_send_json_error(['message' => 'ID конкурса не указан']);
            return;
        }
        
        // Получаем данные для графика лидеров
        $chart_data = new Account_Chart_Data();
        $data = $chart_data->get_leaders_chart_data($contest_id, $top_count, $period);
        
        if (isset($data['error'])) {
            wp_send_json_error(['message' => $data['error']]);
        } else {
            wp_send_json_success($data);
        }
    }

    /**
     * Регистрация и подключение стилей и скриптов
     */
    public static function register_assets() {
        $plugin_url = plugin_dir_url(dirname(__FILE__));
        $plugin_path = plugin_dir_path(dirname(__FILE__));
        
        // Определяем, находимся ли мы на страницах плагина
        global $post;
        $is_contest_page = is_object($post) && 'trader_contests' === $post->post_type;
        $is_contests_archive = is_post_type_archive('trader_contests');
        $is_account_page = isset($_GET['contest_account']) && isset($_GET['contest_id']);
        
        // Проверяем наличие шорткода формы регистрации на странице
        $has_registration_form = false;
        if (is_object($post) && has_shortcode($post->post_content, 'contest_registration_form')) {
            $has_registration_form = true;
        }
        
        // 1. Регистрация общих ресурсов
        // ------------------------------------------
        
        // Регистрация Chart.js
        wp_register_script(
            'chartjs',
            'https://cdn.jsdelivr.net/npm/chart.js@3.7.0/dist/chart.min.js',
            [],
            '3.7.0',
            true
        );
        
        // Регистрация скрипта графика счета
        wp_register_script(
            'ft-account-chart',
            plugin_dir_url(dirname(__FILE__)) . 'admin/js/account-chart.js',
            ['jquery', 'chartjs'],
            filemtime(plugin_dir_path(dirname(__FILE__)) . 'admin/js/account-chart.js'),
            true
        );
        
        // Регистрация стилей фронтенда
        wp_register_style(
            'ft-contest-styles', 
            $plugin_url . 'frontend/css/frontend.css',
            [],
            filemtime($plugin_path . 'frontend/css/frontend.css')
        );
        
        // Регистрация основных скриптов
        wp_register_script(
            'ft-contest-scripts',
            $plugin_url . 'public/js/contest-scripts.js',
            ['jquery'],
            filemtime($plugin_path . 'public/js/contest-scripts.js'),
            true
        );
        
        // Регистрация скриптов фронтенда (каскадные выпадающие списки и другие функции)
        wp_register_script(
            'ft-frontend-scripts',
            $plugin_url . 'frontend/js/frontend.js',
            ['jquery'],
            filemtime($plugin_path . 'frontend/js/frontend.js'),
            true
        );
        
        // Отключение отладочных сообщений jQuery Migrate
        add_action('wp_default_scripts', function($scripts) {
            if (!empty($scripts->registered['jquery'])) {
                $scripts->registered['jquery']->deps = array_diff($scripts->registered['jquery']->deps, ['jquery-migrate']);
            }
        });
        
        // Добавляем код для отключения сообщений jQuery Migrate
        wp_add_inline_script('jquery', 'jQuery.migrateMute = true; jQuery.migrateTrace = false;');
        

        
        // 2. Подключение ресурсов на основе типа страницы
        // ------------------------------------------
        
        // Подключаем стили на всех страницах плагина
        if ($is_contest_page || $is_contests_archive || $is_account_page || $has_registration_form) {
            wp_enqueue_style('ft-contest-styles');
            
            // Подключаем основные скрипты фронтенда на всех страницах плагина
            wp_enqueue_script('ft-frontend-scripts');
            wp_localize_script('ft-frontend-scripts', 'ftContestData', [
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('ft_contest_nonce')
            ]);
        }
        
        // A. Для страницы архива конкурсов
        if ($is_contests_archive) {
            // Подключаем дополнительные скрипты для архивной страницы
            // Примечание: ft-frontend-scripts уже подключен выше
        }
        
        // B. Для страницы отдельного конкурса (НЕ счета)
        if ($is_contest_page && !$is_account_page) {
            wp_enqueue_script('chartjs');
            wp_enqueue_script('ft-contest-scripts');
            
            // Локализация для графиков конкурса
            wp_localize_script('ft-contest-scripts', 'ftLeadersChart', [
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('leaders_chart_nonce')
            ]);
        }
        
        // C. Для страницы счета
        if ($is_account_page) {
            // Подключаем Chart.js
            wp_enqueue_script('chartjs');
            
            // Подключаем только скрипт графика счета (НЕ contest-scripts.js)
            wp_enqueue_script('ft-account-chart');
            
            // Локализация данных для скрипта графика
            wp_localize_script('ft-account-chart', 'ftAccountChart', [
                'ajaxurl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('account_chart_nonce'),
                'i18n' => [
                    'loading' => 'Загрузка данных...',
                    'error' => 'Ошибка загрузки данных',
                    'noData' => 'Нет данных для отображения'
                ]
            ]);
        }
    }
    
    /**
     * Подключение кастомных шаблонов
     */
    public static function template_include($template) {
        global $post;
        
        // Путь к папке с шаблонами
        $templates_path = plugin_dir_path(dirname(__FILE__)) . 'templates/';
        
        // Сначала проверяем наличие параметров для страницы счета
        if (isset($_GET['contest_account']) && isset($_GET['contest_id'])) {
            // Страница конкретного счета в конкурсе
            $custom_template = $templates_path . 'single-account.php';
            if (file_exists($custom_template)) {
                return $custom_template;
            }
        } elseif (is_singular('trader_contests')) {
            // Страница конкретного конкурса
            $custom_template = $templates_path . 'single-contest.php';
            if (file_exists($custom_template)) {
                return $custom_template;
            }
        } elseif (is_post_type_archive('trader_contests')) {
            // Страница со списком конкурсов
            $custom_template = $templates_path . 'archive-contests.php';
            if (file_exists($custom_template)) {
                return $custom_template;
            }
        }
        
        return $template;
    }
    
    /**
     * Добавление классов к body для стилизации
     */
    public static function add_body_classes($classes) {
        if (is_singular('trader_contests')) {
            $classes[] = 'ft-single-contest-page';
        } elseif (is_post_type_archive('trader_contests')) {
            $classes[] = 'ft-contests-archive-page';
        } elseif (isset($_GET['contest_account']) && isset($_GET['contest_id'])) {
            $classes[] = 'ft-contest-account-page';
        }
        
        return $classes;
    }
    
    /**
     * Шорткод для формы регистрации счета
     */
    public static function registration_form_shortcode($atts) {
        $atts = shortcode_atts([
            'contest_id' => 0,
        ], $atts, 'contest_registration_form');
        
        // Если пользователь не авторизован, показываем сообщение
        if (!is_user_logged_in()) {
            return '<div class="ft-contest-login-required">
                <p>Для регистрации счета в конкурсе необходимо <a href="' . wp_login_url(get_permalink()) . '">авторизоваться</a>.</p>
            </div>';
        }
        
        // Получаем ID конкурса
        $contest_id = intval($atts['contest_id']);
        if (!$contest_id && is_singular('trader_contests')) {
            global $post;
            $contest_id = $post->ID;
        }
        
        if (!$contest_id) {
            return '<div class="ft-contest-error">Конкурс не найден.</div>';
        }
        
        // Загружаем шаблон формы
        ob_start();
        include plugin_dir_path(dirname(__FILE__)) . 'templates/parts/registration-form.php';
        return ob_get_clean();
    }
    
}

// Инициализация класса
FT_Contest_Templates::init();
