<?php

/**
 * Класс для работы со справочниками платформ, брокеров и их серверов
 * 
 * @since 1.0.0
 */
class FTTrader_Brokers_Platforms {
    
    /**
     * Инициализация класса
     */
    public static function init() {
        // Создаем таблицы при активации
        add_action('ft_trader_contest_activate', array(__CLASS__, 'create_tables'));
        
        // Добавляем AJAX обработчики
        add_action('wp_ajax_get_broker_platforms', array(__CLASS__, 'ajax_get_broker_platforms'));
        add_action('wp_ajax_nopriv_get_broker_platforms', array(__CLASS__, 'ajax_get_broker_platforms'));
        
        add_action('wp_ajax_get_broker_servers', array(__CLASS__, 'ajax_get_broker_servers'));
        add_action('wp_ajax_nopriv_get_broker_servers', array(__CLASS__, 'ajax_get_broker_servers'));
    }
    
    /**
     * Создание таблиц в базе данных
     */
    public static function create_tables() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        
        // Справочник торговых платформ
        $platforms_table = $wpdb->prefix . 'trading_platforms';
        $sql_platforms = "CREATE TABLE IF NOT EXISTS $platforms_table (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            name varchar(100) NOT NULL,
            slug varchar(100) NOT NULL,
            description text,
            status varchar(20) DEFAULT 'active',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY platform_slug (slug)
        ) $charset_collate;";
        
        // Справочник брокеров
        $brokers_table = $wpdb->prefix . 'brokers';
        $sql_brokers = "CREATE TABLE IF NOT EXISTS $brokers_table (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            name varchar(100) NOT NULL,
            slug varchar(100) NOT NULL,
            description text,
            status varchar(20) DEFAULT 'active',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY broker_slug (slug)
        ) $charset_collate;";
        
        // Справочник серверов брокеров
        $servers_table = $wpdb->prefix . 'broker_servers';
        $sql_servers = "CREATE TABLE IF NOT EXISTS $servers_table (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            broker_id bigint(20) NOT NULL,
            platform_id bigint(20) NOT NULL,
            name varchar(100) NOT NULL,
            server_address varchar(255) NOT NULL,
            description text,
            status varchar(20) DEFAULT 'active',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY broker_id (broker_id),
            KEY platform_id (platform_id)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        $platforms_result = dbDelta($sql_platforms);
        $brokers_result = dbDelta($sql_brokers);
        $servers_result = dbDelta($sql_servers);
        
        // Проверка создания таблиц
        if (!$wpdb->get_var("SHOW TABLES LIKE '$platforms_table'")) {
            error_log("Не удалось создать таблицу $platforms_table");
        }
        
        if (!$wpdb->get_var("SHOW TABLES LIKE '$brokers_table'")) {
            error_log("Не удалось создать таблицу $brokers_table");
        }
        
        if (!$wpdb->get_var("SHOW TABLES LIKE '$servers_table'")) {
            error_log("Не удалось создать таблицу $servers_table");
        }
    }
    
    /**
     * Получение списка всех платформ
     * 
     * @return array Массив платформ
     */
    public static function get_platforms() {
        global $wpdb;
        $table = $wpdb->prefix . 'trading_platforms';
        
        return $wpdb->get_results("
            SELECT id, name, slug, description, status 
            FROM $table 
            WHERE status = 'active' 
            ORDER BY name ASC
        ");
    }
    
    /**
     * Получение платформы по ID
     * 
     * @param int $id ID платформы
     * @return object|null Объект платформы или null
     */
    public static function get_platform($id) {
        global $wpdb;
        $table = $wpdb->prefix . 'trading_platforms';
        
        return $wpdb->get_row($wpdb->prepare("
            SELECT id, name, slug, description, status 
            FROM $table 
            WHERE id = %d
        ", $id));
    }
    
    /**
     * Добавление платформы
     * 
     * @param array $data Данные платформы
     * @return int|false ID добавленной платформы или false в случае ошибки
     */
    public static function add_platform($data) {
        global $wpdb;
        $table = $wpdb->prefix . 'trading_platforms';
        
        // Генерируем slug, если не задан
        if (empty($data['slug'])) {
            $data['slug'] = sanitize_title($data['name']);
        }
        
        $result = $wpdb->insert($table, array(
            'name' => sanitize_text_field($data['name']),
            'slug' => sanitize_title($data['slug']),
            'description' => isset($data['description']) ? sanitize_textarea_field($data['description']) : '',
            'status' => isset($data['status']) ? sanitize_text_field($data['status']) : 'active'
        ));
        
        // Отладочная информация
        if ($result === false) {
            error_log('Ошибка при добавлении платформы: ' . $wpdb->last_error);
        }
        
        return $result ? $wpdb->insert_id : false;
    }
    
    /**
     * Обновление платформы
     * 
     * @param int $id ID платформы
     * @param array $data Данные платформы
     * @return bool Результат обновления
     */
    public static function update_platform($id, $data) {
        global $wpdb;
        $table = $wpdb->prefix . 'trading_platforms';
        
        // Генерируем slug, если не задан
        if (empty($data['slug'])) {
            $data['slug'] = sanitize_title($data['name']);
        }
        
        return $wpdb->update($table, array(
            'name' => sanitize_text_field($data['name']),
            'slug' => sanitize_title($data['slug']),
            'description' => isset($data['description']) ? sanitize_textarea_field($data['description']) : '',
            'status' => isset($data['status']) ? sanitize_text_field($data['status']) : 'active'
        ), array('id' => $id));
    }
    
    /**
     * Получение списка всех брокеров
     * 
     * @return array Массив брокеров
     */
    public static function get_brokers() {
        global $wpdb;
        $table = $wpdb->prefix . 'brokers';
        
        return $wpdb->get_results("
            SELECT id, name, slug, description, status 
            FROM $table 
            WHERE status = 'active' 
            ORDER BY name ASC
        ");
    }
    
    /**
     * Получение брокера по ID
     * 
     * @param int $id ID брокера
     * @return object|null Объект брокера или null
     */
    public static function get_broker($id) {
        global $wpdb;
        $table = $wpdb->prefix . 'brokers';
        
        return $wpdb->get_row($wpdb->prepare("
            SELECT id, name, slug, description, status 
            FROM $table 
            WHERE id = %d
        ", $id));
    }
    
    /**
     * Добавление брокера
     * 
     * @param array $data Данные брокера
     * @return int|false ID добавленного брокера или false в случае ошибки
     */
    public static function add_broker($data) {
        global $wpdb;
        $table = $wpdb->prefix . 'brokers';
        
        // Генерируем slug, если не задан
        if (empty($data['slug'])) {
            $data['slug'] = sanitize_title($data['name']);
        }
        
        $result = $wpdb->insert($table, array(
            'name' => sanitize_text_field($data['name']),
            'slug' => sanitize_title($data['slug']),
            'description' => isset($data['description']) ? sanitize_textarea_field($data['description']) : '',
            'status' => isset($data['status']) ? sanitize_text_field($data['status']) : 'active'
        ));
        
        return $result ? $wpdb->insert_id : false;
    }
    
    /**
     * Обновление брокера
     * 
     * @param int $id ID брокера
     * @param array $data Данные брокера
     * @return bool Результат обновления
     */
    public static function update_broker($id, $data) {
        global $wpdb;
        $table = $wpdb->prefix . 'brokers';
        
        // Генерируем slug, если не задан
        if (empty($data['slug'])) {
            $data['slug'] = sanitize_title($data['name']);
        }
        
        return $wpdb->update($table, array(
            'name' => sanitize_text_field($data['name']),
            'slug' => sanitize_title($data['slug']),
            'description' => isset($data['description']) ? sanitize_textarea_field($data['description']) : '',
            'status' => isset($data['status']) ? sanitize_text_field($data['status']) : 'active'
        ), array('id' => $id));
    }
    
    /**
     * Получение списка серверов брокера
     * 
     * @param int $broker_id ID брокера
     * @param int|null $platform_id ID платформы (опционально)
     * @return array Массив серверов
     */
    public static function get_broker_servers($broker_id, $platform_id = null) {
        global $wpdb;
        $table = $wpdb->prefix . 'broker_servers';
        
        $sql = "
            SELECT s.id, s.broker_id, s.platform_id, s.name, s.server_address, s.description, s.status,
                   b.name as broker_name, p.name as platform_name
            FROM $table s
            LEFT JOIN {$wpdb->prefix}brokers b ON s.broker_id = b.id
            LEFT JOIN {$wpdb->prefix}trading_platforms p ON s.platform_id = p.id
            WHERE s.broker_id = %d
            AND s.status = 'active'
        ";
        
        $params = array($broker_id);
        
        if ($platform_id) {
            $sql .= " AND s.platform_id = %d";
            $params[] = $platform_id;
        }
        
        $sql .= " ORDER BY s.name ASC";
        
        return $wpdb->get_results($wpdb->prepare($sql, $params));
    }
    
    /**
     * Получение платформ брокера
     * 
     * @param int $broker_id ID брокера
     * @return array Массив платформ
     */
    public static function get_broker_platforms($broker_id) {
        global $wpdb;
        $servers_table = $wpdb->prefix . 'broker_servers';
        $platforms_table = $wpdb->prefix . 'trading_platforms';
        
        return $wpdb->get_results($wpdb->prepare("
            SELECT DISTINCT p.id, p.name, p.slug, p.description
            FROM $platforms_table p
            INNER JOIN $servers_table s ON p.id = s.platform_id
            WHERE s.broker_id = %d
            AND p.status = 'active'
            AND s.status = 'active'
            ORDER BY p.name ASC
        ", $broker_id));
    }
    
    /**
     * Добавление сервера брокера
     * 
     * @param array $data Данные сервера
     * @return int|false ID добавленного сервера или false в случае ошибки
     */
    public static function add_broker_server($data) {
        global $wpdb;
        $table = $wpdb->prefix . 'broker_servers';
        
        $result = $wpdb->insert($table, array(
            'broker_id' => intval($data['broker_id']),
            'platform_id' => intval($data['platform_id']),
            'name' => sanitize_text_field($data['name']),
            'server_address' => sanitize_text_field($data['server_address']),
            'description' => isset($data['description']) ? sanitize_textarea_field($data['description']) : '',
            'status' => isset($data['status']) ? sanitize_text_field($data['status']) : 'active'
        ));
        
        return $result ? $wpdb->insert_id : false;
    }
    
    /**
     * Обновление сервера брокера
     * 
     * @param int $id ID сервера
     * @param array $data Данные сервера
     * @return bool Результат обновления
     */
    public static function update_broker_server($id, $data) {
        global $wpdb;
        $table = $wpdb->prefix . 'broker_servers';
        
        return $wpdb->update($table, array(
            'broker_id' => intval($data['broker_id']),
            'platform_id' => intval($data['platform_id']),
            'name' => sanitize_text_field($data['name']),
            'server_address' => sanitize_text_field($data['server_address']),
            'description' => isset($data['description']) ? sanitize_textarea_field($data['description']) : '',
            'status' => isset($data['status']) ? sanitize_text_field($data['status']) : 'active'
        ), array('id' => $id));
    }
    
    /**
     * AJAX обработчик для получения платформ брокера
     */
    public static function ajax_get_broker_platforms() {
        // Отладочная информация
        error_log('[DEBUG] AJAX запрос get_broker_platforms: ' . json_encode($_POST));
        
        // Проверка nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'ft_contest_nonce')) {
            error_log('[ERROR] Ошибка nonce в get_broker_platforms');
            wp_send_json_error(array('message' => 'Ошибка безопасности. Пожалуйста, обновите страницу и попробуйте снова.'));
        }
        
        // Проверка наличия ID брокера
        if (!isset($_POST['broker_id']) || empty($_POST['broker_id'])) {
            error_log('[ERROR] ID брокера не указан в get_broker_platforms');
            wp_send_json_error(array('message' => 'ID брокера не указан.'));
        }
        
        $broker_id = intval($_POST['broker_id']);
        $platforms = self::get_broker_platforms($broker_id);
        
        error_log('[DEBUG] Результат get_broker_platforms для брокера ' . $broker_id . ': ' . json_encode($platforms));
        
        // Проверка наличия платформ
        if (empty($platforms)) {
            error_log('[WARNING] Для брокера ID=' . $broker_id . ' не найдено платформ');
            
            // Проверяем, существует ли такой брокер
            $broker = self::get_broker($broker_id);
            if ($broker) {
                error_log('[INFO] Брокер ID=' . $broker_id . ' существует: ' . json_encode($broker));
                
                // Если брокер существует, но платформ нет, возвращаем пустой результат
                wp_send_json_success(array());
            } else {
                error_log('[ERROR] Брокер ID=' . $broker_id . ' не найден в базе данных');
                wp_send_json_error(array('message' => 'Брокер не найден.'));
            }
        } else {
            wp_send_json_success($platforms);
        }
    }
    
    /**
     * AJAX обработчик для получения серверов брокера
     */
    public static function ajax_get_broker_servers() {
        // Отладочная информация
        error_log('[DEBUG] AJAX запрос get_broker_servers: ' . json_encode($_POST));
        
        // Проверка nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'ft_contest_nonce')) {
            error_log('[ERROR] Ошибка nonce в get_broker_servers');
            wp_send_json_error(array('message' => 'Ошибка безопасности. Пожалуйста, обновите страницу и попробуйте снова.'));
        }
        
        // Проверка наличия ID брокера
        if (!isset($_POST['broker_id']) || empty($_POST['broker_id'])) {
            error_log('[ERROR] ID брокера не указан в get_broker_servers');
            wp_send_json_error(array('message' => 'ID брокера не указан.'));
        }
        
        // Проверка наличия ID платформы
        if (!isset($_POST['platform_id']) || empty($_POST['platform_id'])) {
            error_log('[ERROR] ID платформы не указан в get_broker_servers');
            wp_send_json_error(array('message' => 'ID платформы не указан.'));
        }
        
        $broker_id = intval($_POST['broker_id']);
        $platform_id = intval($_POST['platform_id']);
        
        $servers = self::get_broker_servers($broker_id, $platform_id);
        
        error_log('[DEBUG] Результат get_broker_servers для брокера ' . $broker_id . ', платформы ' . $platform_id . ': ' . json_encode($servers));
        
        // Проверка наличия серверов
        if (empty($servers)) {
            error_log('[WARNING] Для брокера ID=' . $broker_id . ' и платформы ID=' . $platform_id . ' не найдено серверов');
            
            // Проверяем, существуют ли такой брокер и платформа
            $broker = self::get_broker($broker_id);
            $platform = self::get_platform($platform_id);
            
            error_log('[INFO] Брокер ID=' . $broker_id . ' существует: ' . ($broker ? 'да' : 'нет'));
            error_log('[INFO] Платформа ID=' . $platform_id . ' существует: ' . ($platform ? 'да' : 'нет'));
            
            // Если всё существует, но серверов нет, возвращаем пустой результат
            wp_send_json_success(array());
        } else {
            wp_send_json_success($servers);
        }
    }
}

// Инициализация класса
FTTrader_Brokers_Platforms::init(); 