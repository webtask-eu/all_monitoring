<?php 

class FTTrader_Installer {
    public static function create_tables() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        // Таблица участников конкурса
        $table_name = $wpdb->prefix . 'contest_members';
        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            contest_id bigint(20) NOT NULL,
            user_id bigint(20) DEFAULT NULL,
            account_number varchar(50) NOT NULL,
            password varchar(50) NOT NULL,
            server varchar(100) NOT NULL,
            terminal varchar(50) NOT NULL,
            balance decimal(10,2) DEFAULT 0,
            equity decimal(10,2) DEFAULT 0,
            margin decimal(10,2) DEFAULT 0,
            profit decimal(10,2) DEFAULT 0,
            deprecated_margin_level decimal(10,2) DEFAULT 0,
            leverage decimal(10,2) DEFAULT 0,
            orders_total int DEFAULT 0,
            orders_history_total int DEFAULT 0,
            orders_history_profit decimal(10,2) DEFAULT 0,
            currency varchar(10) DEFAULT 'USD',
            broker varchar(100),
            platform varchar(100),
            name varchar(100),
            account_type varchar(50),
            gmt_offset int,
            last_update_time bigint,
            registration_date datetime DEFAULT CURRENT_TIMESTAMP,
            last_update datetime DEFAULT CURRENT_TIMESTAMP,
            last_history_time bigint(20) DEFAULT 0,
            connection_status varchar(20) DEFAULT 'disconnected', 
            error_description text DEFAULT NULL, 
            user_ip VARCHAR(45) DEFAULT NULL,
            user_country VARCHAR(100) DEFAULT NULL,
            country_code VARCHAR(2) DEFAULT NULL,
            profit_percent decimal(10,2) DEFAULT 0,
            PRIMARY KEY (id)
        ) $charset_collate;";

        // Таблица истории изменений
        $history_table = $wpdb->prefix . 'contest_members_history';
        $sql_history = "CREATE TABLE IF NOT EXISTS $history_table (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            account_id bigint(20) NOT NULL,
            field_name varchar(50) NOT NULL,
            old_value text,
            new_value text,
            change_percent decimal(10,2) DEFAULT NULL,
            error_description text DEFAULT NULL,
            change_date datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY account_id (account_id),
            KEY field_name (field_name)
        ) $charset_collate;";

        // Добавляем таблицу для ордеров
        $orders_table = $wpdb->prefix . 'contest_members_orders';
        $sql_orders = "CREATE TABLE IF NOT EXISTS $orders_table (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            account_id bigint(20) NOT NULL,
            ticket bigint(20) NOT NULL,
            symbol varchar(20) NOT NULL,
            type varchar(10) NOT NULL,
            lots decimal(10,2) NOT NULL,
            open_time datetime NOT NULL,
            open_price decimal(10,5) NOT NULL,
            sl decimal(10,5) DEFAULT NULL,
            tp decimal(10,5) DEFAULT NULL,
            profit decimal(10,2) DEFAULT 0,
            commission decimal(10,2) DEFAULT 0,
            swap decimal(10,2) DEFAULT 0,
            comment varchar(255),
            PRIMARY KEY (id),
            KEY account_id (account_id),
            KEY ticket (ticket),
            UNIQUE KEY unique_order (account_id, ticket)
        ) $charset_collate;";

        // Таблица истории ордеров
        $history_orders_table = $wpdb->prefix . 'contest_members_order_history';
        $sql_history_orders = "CREATE TABLE IF NOT EXISTS $history_orders_table (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            account_id bigint(20) NOT NULL,
            ticket bigint(20) NOT NULL,
            symbol varchar(20) NOT NULL,
            type varchar(10) NOT NULL,
            lots decimal(10,2) NOT NULL,
            open_time datetime NOT NULL,
            close_time datetime NOT NULL,
            open_price decimal(10,5) NOT NULL,
            close_price decimal(10,5) NOT NULL,
            sl decimal(10,5) DEFAULT NULL,
            tp decimal(10,5) DEFAULT NULL,
            profit decimal(10,2) DEFAULT 0,
            commission decimal(10,2) DEFAULT 0,
            swap decimal(10,2) DEFAULT 0,
            comment varchar(255),
            PRIMARY KEY (id),
            KEY account_id (account_id),
            KEY ticket (ticket),
            UNIQUE KEY unique_order (account_id, ticket)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        dbDelta($sql_history);
        dbDelta($sql_orders);
        dbDelta($sql_history_orders);
        
        // Обновляем индекс для существующей таблицы, если она уже создана
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") === $table_name;
        if ($table_exists) {
            // Проверяем, существует ли старый индекс
            $index_exists = $wpdb->get_results("SHOW INDEX FROM {$table_name} WHERE Key_name = 'account_server'");
            if (!empty($index_exists)) {
                // Удаляем старый индекс и создаем новый
                $wpdb->query("ALTER TABLE {$table_name} DROP INDEX account_server");
                $wpdb->query("ALTER TABLE {$table_name} ADD UNIQUE KEY account_server_contest (account_number, server, contest_id)");
                error_log('Индекс account_server обновлен до account_server_contest в таблице ' . $table_name);
            }
            
            // Проверяем, существует ли уже новый индекс
            $new_index_exists = $wpdb->get_results("SHOW INDEX FROM {$table_name} WHERE Key_name = 'account_server_contest'");
            if (empty($new_index_exists)) {
                // Создаем новый индекс, если он еще не существует
                $wpdb->query("ALTER TABLE {$table_name} ADD UNIQUE KEY account_server_contest (account_number, server, contest_id)");
                error_log('Создан новый индекс account_server_contest в таблице ' . $table_name);
            }
        }
    }

    /**
     * Обновление таблицы участников для добавления поля платформы
     */
    public static function update_tables() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'contest_members';
        
        // Проверяем, существует ли уже поле platform
        $column_exists = $wpdb->get_results($wpdb->prepare("
            SELECT * FROM INFORMATION_SCHEMA.COLUMNS 
            WHERE TABLE_SCHEMA = %s 
            AND TABLE_NAME = %s 
            AND COLUMN_NAME = 'platform'
        ", DB_NAME, $table_name));
        
        // Если поле не существует, добавляем его
        if (empty($column_exists)) {
            $wpdb->query("ALTER TABLE $table_name ADD COLUMN platform varchar(100) AFTER broker");
            error_log('Добавлено поле platform в таблицу ' . $table_name);
        }
    }
}
