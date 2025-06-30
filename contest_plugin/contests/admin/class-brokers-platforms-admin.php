<?php

/**
 * Административный интерфейс для управления справочниками платформ и брокеров
 * 
 * @since 1.0.0
 */
class FTTrader_Brokers_Platforms_Admin {
    
    /**
     * Инициализация класса
     */
    public static function init() {
        // Добавляем страницы в административное меню
        add_action('admin_menu', array(__CLASS__, 'add_admin_menu'));
        
        // Обработка AJAX запросов
        add_action('wp_ajax_add_platform', array(__CLASS__, 'ajax_add_platform'));
        add_action('wp_ajax_update_platform', array(__CLASS__, 'ajax_update_platform'));
        add_action('wp_ajax_delete_platform', array(__CLASS__, 'ajax_delete_platform'));
        
        add_action('wp_ajax_add_broker', array(__CLASS__, 'ajax_add_broker'));
        add_action('wp_ajax_update_broker', array(__CLASS__, 'ajax_update_broker'));
        add_action('wp_ajax_delete_broker', array(__CLASS__, 'ajax_delete_broker'));
        
        add_action('wp_ajax_add_broker_server', array(__CLASS__, 'ajax_add_broker_server'));
        add_action('wp_ajax_update_broker_server', array(__CLASS__, 'ajax_update_broker_server'));
        add_action('wp_ajax_delete_broker_server', array(__CLASS__, 'ajax_delete_broker_server'));
    }
    
    /**
     * Добавление страниц в административное меню
     */
    public static function add_admin_menu() {
        // Страница платформ
        add_submenu_page(
            'edit.php?post_type=trader_contests',
            'Торговые платформы',
            'Торговые платформы',
            'manage_options',
            'trading-platforms',
            array(__CLASS__, 'platforms_page')
        );
        
        // Страница брокеров
        add_submenu_page(
            'edit.php?post_type=trader_contests',
            'Брокеры',
            'Брокеры',
            'manage_options',
            'brokers',
            array(__CLASS__, 'brokers_page')
        );
        
        // Страница серверов
        add_submenu_page(
            'edit.php?post_type=trader_contests',
            'Серверы брокеров',
            'Серверы брокеров',
            'manage_options',
            'broker-servers',
            array(__CLASS__, 'broker_servers_page')
        );
    }
    
    /**
     * Отображение страницы платформ
     */
    public static function platforms_page() {
        // Получаем список платформ
        $platforms = FTTrader_Brokers_Platforms::get_platforms();
        
        // Подключаем шаблон
        include plugin_dir_path(__FILE__) . 'views/platforms-list.php';
    }
    
    /**
     * Отображение страницы брокеров
     */
    public static function brokers_page() {
        // Получаем список брокеров
        $brokers = FTTrader_Brokers_Platforms::get_brokers();
        
        // Подключаем шаблон
        include plugin_dir_path(__FILE__) . 'views/brokers-list.php';
    }
    
    /**
     * Отображение страницы серверов брокеров
     */
    public static function broker_servers_page() {
        global $wpdb;
        
        // Получаем список брокеров и платформ для формы
        $brokers = FTTrader_Brokers_Platforms::get_brokers();
        $platforms = FTTrader_Brokers_Platforms::get_platforms();
        
        // Получаем список серверов
        $servers_table = $wpdb->prefix . 'broker_servers';
        $servers = $wpdb->get_results("
            SELECT s.*, b.name as broker_name, p.name as platform_name
            FROM $servers_table s
            LEFT JOIN {$wpdb->prefix}brokers b ON s.broker_id = b.id
            LEFT JOIN {$wpdb->prefix}trading_platforms p ON s.platform_id = p.id
            WHERE s.status = 'active'
            ORDER BY b.name ASC, p.name ASC, s.name ASC
        ");
        
        // Подключаем шаблон
        include plugin_dir_path(__FILE__) . 'views/servers-list.php';
    }
    
    /**
     * AJAX обработчик для добавления платформы
     */
    public static function ajax_add_platform() {
        // Проверка прав доступа
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'У вас нет прав для выполнения этого действия.'));
        }
        
        // Проверка nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'broker_platform_nonce')) {
            wp_send_json_error(array('message' => 'Ошибка безопасности. Пожалуйста, обновите страницу и попробуйте снова.'));
        }
        
        // Проверка данных
        if (!isset($_POST['name']) || empty($_POST['name'])) {
            wp_send_json_error(array('message' => 'Название платформы не может быть пустым.'));
        }
        
        // Подготавливаем данные
        $data = array(
            'name' => sanitize_text_field($_POST['name']),
            'slug' => isset($_POST['slug']) ? sanitize_title($_POST['slug']) : '',
            'description' => isset($_POST['description']) ? sanitize_textarea_field($_POST['description']) : ''
        );
        
        // Добавляем платформу
        $platform_id = FTTrader_Brokers_Platforms::add_platform($data);
        
        if ($platform_id) {
            wp_send_json_success(array(
                'message' => 'Платформа успешно добавлена.',
                'platform_id' => $platform_id,
                'platform' => FTTrader_Brokers_Platforms::get_platform($platform_id)
            ));
        } else {
            wp_send_json_error(array('message' => 'Не удалось добавить платформу.'));
        }
    }
    
    /**
     * AJAX обработчик для обновления платформы
     */
    public static function ajax_update_platform() {
        // Проверка прав доступа
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'У вас нет прав для выполнения этого действия.'));
        }
        
        // Проверка nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'broker_platform_nonce')) {
            wp_send_json_error(array('message' => 'Ошибка безопасности. Пожалуйста, обновите страницу и попробуйте снова.'));
        }
        
        // Проверка данных
        if (!isset($_POST['id']) || empty($_POST['id'])) {
            wp_send_json_error(array('message' => 'ID платформы не указан.'));
        }
        
        if (!isset($_POST['name']) || empty($_POST['name'])) {
            wp_send_json_error(array('message' => 'Название платформы не может быть пустым.'));
        }
        
        // Подготавливаем данные
        $data = array(
            'name' => sanitize_text_field($_POST['name']),
            'slug' => isset($_POST['slug']) ? sanitize_title($_POST['slug']) : '',
            'description' => isset($_POST['description']) ? sanitize_textarea_field($_POST['description']) : ''
        );
        
        // Обновляем платформу
        $result = FTTrader_Brokers_Platforms::update_platform(intval($_POST['id']), $data);
        
        if ($result !== false) {
            wp_send_json_success(array(
                'message' => 'Платформа успешно обновлена.',
                'platform' => FTTrader_Brokers_Platforms::get_platform(intval($_POST['id']))
            ));
        } else {
            wp_send_json_error(array('message' => 'Не удалось обновить платформу.'));
        }
    }
    
    /**
     * AJAX обработчик для удаления платформы
     */
    public static function ajax_delete_platform() {
        // Проверка прав доступа
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'У вас нет прав для выполнения этого действия.'));
        }
        
        // Проверка nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'broker_platform_nonce')) {
            wp_send_json_error(array('message' => 'Ошибка безопасности. Пожалуйста, обновите страницу и попробуйте снова.'));
        }
        
        // Проверка данных
        if (!isset($_POST['id']) || empty($_POST['id'])) {
            wp_send_json_error(array('message' => 'ID платформы не указан.'));
        }
        
        global $wpdb;
        $table = $wpdb->prefix . 'trading_platforms';
        
        // Проверяем, используется ли платформа в серверах
        $servers_table = $wpdb->prefix . 'broker_servers';
        $servers_count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $servers_table WHERE platform_id = %d",
            intval($_POST['id'])
        ));
        
        if ($servers_count > 0) {
            wp_send_json_error(array(
                'message' => 'Невозможно удалить платформу, так как она используется в серверах. Сначала удалите все серверы, связанные с этой платформой.'
            ));
            return;
        }
        
        // Удаляем платформу (мягкое удаление - изменение статуса)
        $result = $wpdb->update(
            $table, 
            array('status' => 'deleted'),
            array('id' => intval($_POST['id']))
        );
        
        if ($result !== false) {
            wp_send_json_success(array('message' => 'Платформа успешно удалена.'));
        } else {
            wp_send_json_error(array('message' => 'Не удалось удалить платформу.'));
        }
    }
    
    /**
     * AJAX обработчик для добавления брокера
     */
    public static function ajax_add_broker() {
        // Проверка прав доступа
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'У вас нет прав для выполнения этого действия.'));
        }
        
        // Проверка nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'broker_platform_nonce')) {
            wp_send_json_error(array('message' => 'Ошибка безопасности. Пожалуйста, обновите страницу и попробуйте снова.'));
        }
        
        // Проверка данных
        if (!isset($_POST['name']) || empty($_POST['name'])) {
            wp_send_json_error(array('message' => 'Название брокера не может быть пустым.'));
        }
        
        // Подготавливаем данные
        $data = array(
            'name' => sanitize_text_field($_POST['name']),
            'slug' => isset($_POST['slug']) ? sanitize_title($_POST['slug']) : '',
            'description' => isset($_POST['description']) ? sanitize_textarea_field($_POST['description']) : ''
        );
        
        // Добавляем брокера
        $broker_id = FTTrader_Brokers_Platforms::add_broker($data);
        
        if ($broker_id) {
            wp_send_json_success(array(
                'message' => 'Брокер успешно добавлен.',
                'broker_id' => $broker_id,
                'broker' => FTTrader_Brokers_Platforms::get_broker($broker_id)
            ));
        } else {
            wp_send_json_error(array('message' => 'Не удалось добавить брокера.'));
        }
    }
    
    /**
     * AJAX обработчик для обновления брокера
     */
    public static function ajax_update_broker() {
        // Проверка прав доступа
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'У вас нет прав для выполнения этого действия.'));
        }
        
        // Проверка nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'broker_platform_nonce')) {
            wp_send_json_error(array('message' => 'Ошибка безопасности. Пожалуйста, обновите страницу и попробуйте снова.'));
        }
        
        // Проверка данных
        if (!isset($_POST['id']) || empty($_POST['id'])) {
            wp_send_json_error(array('message' => 'ID брокера не указан.'));
        }
        
        if (!isset($_POST['name']) || empty($_POST['name'])) {
            wp_send_json_error(array('message' => 'Название брокера не может быть пустым.'));
        }
        
        // Подготавливаем данные
        $data = array(
            'name' => sanitize_text_field($_POST['name']),
            'slug' => isset($_POST['slug']) ? sanitize_title($_POST['slug']) : '',
            'description' => isset($_POST['description']) ? sanitize_textarea_field($_POST['description']) : ''
        );
        
        // Обновляем брокера
        $result = FTTrader_Brokers_Platforms::update_broker(intval($_POST['id']), $data);
        
        if ($result !== false) {
            wp_send_json_success(array(
                'message' => 'Брокер успешно обновлен.',
                'broker' => FTTrader_Brokers_Platforms::get_broker(intval($_POST['id']))
            ));
        } else {
            wp_send_json_error(array('message' => 'Не удалось обновить брокера.'));
        }
    }
    
    /**
     * AJAX обработчик для удаления брокера
     */
    public static function ajax_delete_broker() {
        // Проверка прав доступа
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'У вас нет прав для выполнения этого действия.'));
        }
        
        // Проверка nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'broker_platform_nonce')) {
            wp_send_json_error(array('message' => 'Ошибка безопасности. Пожалуйста, обновите страницу и попробуйте снова.'));
        }
        
        // Проверка данных
        if (!isset($_POST['id']) || empty($_POST['id'])) {
            wp_send_json_error(array('message' => 'ID брокера не указан.'));
        }
        
        global $wpdb;
        $table = $wpdb->prefix . 'brokers';
        
        // Проверяем, используется ли брокер в серверах
        $servers_table = $wpdb->prefix . 'broker_servers';
        $servers_count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $servers_table WHERE broker_id = %d",
            intval($_POST['id'])
        ));
        
        if ($servers_count > 0) {
            wp_send_json_error(array(
                'message' => 'Невозможно удалить брокера, так как он используется в серверах. Сначала удалите все серверы, связанные с этим брокером.'
            ));
            return;
        }
        
        // Удаляем брокера (мягкое удаление - изменение статуса)
        $result = $wpdb->update(
            $table, 
            array('status' => 'deleted'),
            array('id' => intval($_POST['id']))
        );
        
        if ($result !== false) {
            wp_send_json_success(array('message' => 'Брокер успешно удален.'));
        } else {
            wp_send_json_error(array('message' => 'Не удалось удалить брокера.'));
        }
    }
    
    /**
     * AJAX обработчик для добавления сервера брокера
     */
    public static function ajax_add_broker_server() {
        // Проверка прав доступа
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'У вас нет прав для выполнения этого действия.'));
        }
        
        // Проверка nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'broker_platform_nonce')) {
            wp_send_json_error(array('message' => 'Ошибка безопасности. Пожалуйста, обновите страницу и попробуйте снова.'));
        }
        
        // Проверка данных
        if (!isset($_POST['broker_id']) || empty($_POST['broker_id'])) {
            wp_send_json_error(array('message' => 'Брокер не выбран.'));
        }
        
        if (!isset($_POST['platform_id']) || empty($_POST['platform_id'])) {
            wp_send_json_error(array('message' => 'Платформа не выбрана.'));
        }
        
        if (!isset($_POST['name']) || empty($_POST['name'])) {
            wp_send_json_error(array('message' => 'Название сервера не может быть пустым.'));
        }
        
        if (!isset($_POST['server_address']) || empty($_POST['server_address'])) {
            wp_send_json_error(array('message' => 'Адрес сервера не может быть пустым.'));
        }
        
        // Подготавливаем данные
        $data = array(
            'broker_id' => intval($_POST['broker_id']),
            'platform_id' => intval($_POST['platform_id']),
            'name' => sanitize_text_field($_POST['name']),
            'server_address' => sanitize_text_field($_POST['server_address']),
            'description' => isset($_POST['description']) ? sanitize_textarea_field($_POST['description']) : ''
        );
        
        // Добавляем сервер
        $server_id = FTTrader_Brokers_Platforms::add_broker_server($data);
        
        if ($server_id) {
            global $wpdb;
            $servers_table = $wpdb->prefix . 'broker_servers';
            $server = $wpdb->get_row($wpdb->prepare("
                SELECT s.*, b.name as broker_name, p.name as platform_name
                FROM $servers_table s
                LEFT JOIN {$wpdb->prefix}brokers b ON s.broker_id = b.id
                LEFT JOIN {$wpdb->prefix}trading_platforms p ON s.platform_id = p.id
                WHERE s.id = %d
            ", $server_id));
            
            wp_send_json_success(array(
                'message' => 'Сервер успешно добавлен.',
                'server_id' => $server_id,
                'server' => $server
            ));
        } else {
            wp_send_json_error(array('message' => 'Не удалось добавить сервер.'));
        }
    }
    
    /**
     * AJAX обработчик для обновления сервера брокера
     */
    public static function ajax_update_broker_server() {
        // Проверка прав доступа
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'У вас нет прав для выполнения этого действия.'));
        }
        
        // Проверка nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'broker_platform_nonce')) {
            wp_send_json_error(array('message' => 'Ошибка безопасности. Пожалуйста, обновите страницу и попробуйте снова.'));
        }
        
        // Проверка данных
        if (!isset($_POST['id']) || empty($_POST['id'])) {
            wp_send_json_error(array('message' => 'ID сервера не указан.'));
        }
        
        if (!isset($_POST['broker_id']) || empty($_POST['broker_id'])) {
            wp_send_json_error(array('message' => 'Брокер не выбран.'));
        }
        
        if (!isset($_POST['platform_id']) || empty($_POST['platform_id'])) {
            wp_send_json_error(array('message' => 'Платформа не выбрана.'));
        }
        
        if (!isset($_POST['name']) || empty($_POST['name'])) {
            wp_send_json_error(array('message' => 'Название сервера не может быть пустым.'));
        }
        
        if (!isset($_POST['server_address']) || empty($_POST['server_address'])) {
            wp_send_json_error(array('message' => 'Адрес сервера не может быть пустым.'));
        }
        
        // Подготавливаем данные
        $data = array(
            'broker_id' => intval($_POST['broker_id']),
            'platform_id' => intval($_POST['platform_id']),
            'name' => sanitize_text_field($_POST['name']),
            'server_address' => sanitize_text_field($_POST['server_address']),
            'description' => isset($_POST['description']) ? sanitize_textarea_field($_POST['description']) : ''
        );
        
        // Обновляем сервер
        $result = FTTrader_Brokers_Platforms::update_broker_server(intval($_POST['id']), $data);
        
        if ($result !== false) {
            global $wpdb;
            $servers_table = $wpdb->prefix . 'broker_servers';
            $server = $wpdb->get_row($wpdb->prepare("
                SELECT s.*, b.name as broker_name, p.name as platform_name
                FROM $servers_table s
                LEFT JOIN {$wpdb->prefix}brokers b ON s.broker_id = b.id
                LEFT JOIN {$wpdb->prefix}trading_platforms p ON s.platform_id = p.id
                WHERE s.id = %d
            ", intval($_POST['id'])));
            
            wp_send_json_success(array(
                'message' => 'Сервер успешно обновлен.',
                'server' => $server
            ));
        } else {
            wp_send_json_error(array('message' => 'Не удалось обновить сервер.'));
        }
    }
    
    /**
     * AJAX обработчик для удаления сервера брокера
     */
    public static function ajax_delete_broker_server() {
        // Проверка прав доступа
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'У вас нет прав для выполнения этого действия.'));
        }
        
        // Проверка nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'broker_platform_nonce')) {
            wp_send_json_error(array('message' => 'Ошибка безопасности. Пожалуйста, обновите страницу и попробуйте снова.'));
        }
        
        // Проверка данных
        if (!isset($_POST['id']) || empty($_POST['id'])) {
            wp_send_json_error(array('message' => 'ID сервера не указан.'));
        }
        
        global $wpdb;
        $table = $wpdb->prefix . 'broker_servers';
        
        // Удаляем сервер (мягкое удаление - изменение статуса)
        $result = $wpdb->update(
            $table, 
            array('status' => 'deleted'),
            array('id' => intval($_POST['id']))
        );
        
        if ($result !== false) {
            wp_send_json_success(array('message' => 'Сервер успешно удален.'));
        } else {
            wp_send_json_error(array('message' => 'Не удалось удалить сервер.'));
        }
    }
}

// Инициализация класса
FTTrader_Brokers_Platforms_Admin::init(); 