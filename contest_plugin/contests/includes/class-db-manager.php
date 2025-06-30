<?php

class DB_Manager {
    public function update_database() {
        global $wpdb;
        
        $current_version = get_option('fttrader_db_version', '1.0');
        $latest_version = $this->get_db_version();
        
        if (version_compare($current_version, $latest_version, '<')) {
            // Выполняем обновления базы данных
            
            // Обновление до версии 1.1
            if (version_compare($current_version, '1.1', '<')) {
                // Логика обновления до 1.1
            }
            
            // Обновление до версии X.X - добавьте здесь новые версии
            
            // Добавляем миграцию для переименования поля margin_level в leverage
            if (version_compare($current_version, '1.8', '<')) {
                $this->migrate_margin_level_to_leverage();
            }
            
            // Обновляем версию базы данных
            update_option('fttrader_db_version', $latest_version);
        }
    }
    
    /**
     * Миграция данных из поля margin_level в leverage
     */
    private function migrate_margin_level_to_leverage() {
        global $wpdb;
        
        // Проверяем, существует ли поле margin_level в таблице
        $members_table = $wpdb->prefix . 'contest_members';
        $columns = $wpdb->get_results("SHOW COLUMNS FROM $members_table", ARRAY_A);
        $has_margin_level = false;
        $has_leverage = false;
        
        foreach ($columns as $column) {
            if ($column['Field'] === 'margin_level') {
                $has_margin_level = true;
            }
            if ($column['Field'] === 'leverage') {
                $has_leverage = true;
            }
        }
        
        // Если есть margin_level, но нет leverage, добавляем поле leverage
        if ($has_margin_level && !$has_leverage) {
            $wpdb->query("ALTER TABLE $members_table ADD COLUMN leverage float DEFAULT 0 AFTER margin_level");
            
            // Копируем данные из margin_level в leverage
            $wpdb->query("UPDATE $members_table SET leverage = margin_level WHERE margin_level > 0");
            
            // Логируем миграцию
            error_log('[DB-MANAGER] Миграция: Данные из margin_level скопированы в leverage');
            
            // Опционально: можно добавить запрос для переноса значений из одного поля в другое
            // и пометить старое поле как устаревшее, добавив к нему префикс
            $wpdb->query("ALTER TABLE $members_table CHANGE COLUMN margin_level deprecated_margin_level float DEFAULT 0");
            error_log('[DB-MANAGER] Миграция: Поле margin_level переименовано в deprecated_margin_level');
        } elseif ($has_margin_level && $has_leverage) {
            // Если оба поля существуют, копируем данные из margin_level в leverage там, где leverage = 0
            $wpdb->query("UPDATE $members_table SET leverage = margin_level WHERE leverage = 0 AND margin_level > 0");
            
            // Переименовываем старое поле
            $wpdb->query("ALTER TABLE $members_table CHANGE COLUMN margin_level deprecated_margin_level float DEFAULT 0");
            error_log('[DB-MANAGER] Миграция: Поле margin_level переименовано в deprecated_margin_level');
        }
        
        // Также обновляем временные таблицы, если они существуют
        $temp_table = $wpdb->prefix . 'contest_members_temp';
        if ($wpdb->get_var("SHOW TABLES LIKE '$temp_table'") === $temp_table) {
            $columns = $wpdb->get_results("SHOW COLUMNS FROM $temp_table", ARRAY_A);
            $has_margin_level = false;
            $has_leverage = false;
            
            foreach ($columns as $column) {
                if ($column['Field'] === 'margin_level') {
                    $has_margin_level = true;
                }
                if ($column['Field'] === 'leverage') {
                    $has_leverage = true;
                }
            }
            
            if ($has_margin_level && !$has_leverage) {
                $wpdb->query("ALTER TABLE $temp_table ADD COLUMN leverage float DEFAULT 0 AFTER margin_level");
                $wpdb->query("UPDATE $temp_table SET leverage = margin_level WHERE margin_level > 0");
                error_log('[DB-MANAGER] Миграция: Данные из margin_level скопированы в leverage (временная таблица)');
                
                // Переименовываем старое поле
                $wpdb->query("ALTER TABLE $temp_table CHANGE COLUMN margin_level deprecated_margin_level float DEFAULT 0");
                error_log('[DB-MANAGER] Миграция: Поле margin_level переименовано в deprecated_margin_level (временная таблица)');
            } elseif ($has_margin_level && $has_leverage) {
                // Если оба поля существуют, копируем данные и переименовываем старое поле
                $wpdb->query("UPDATE $temp_table SET leverage = margin_level WHERE leverage = 0 AND margin_level > 0");
                $wpdb->query("ALTER TABLE $temp_table CHANGE COLUMN margin_level deprecated_margin_level float DEFAULT 0");
                error_log('[DB-MANAGER] Миграция: Поле margin_level переименовано в deprecated_margin_level (временная таблица)');
            }
        }
    }
} 