<?php 

class Account_History {
    private $financial_fields = [
        'i_bal',
        'i_equi', 
        'i_marg',
        'i_prof',
        'active_orders_volume',
        'leverage'
    ];

    // Поля, которые отслеживаются на точные изменения (без процентных порогов)
    private $tracked_fields = [
        'pass',
        'srvMt4',
        'last_logMt4',
        'i_server',
        'i_firma',
        'i_fio',
        'i_cur',
        'i_dr',
        'i_ordtotal',
        'h_count',
        'connection_status' // Добавляем статус подключения
    ];

    /**
     * Проверяет процентное изменение для финансовых полей
     */
    private function check_percent_change($field_name, $old_value, $new_value) {
        // Если значения одинаковые, не записываем изменение
        if ($old_value == $new_value) return false;
        
        // Если старое значение равно нулю, но новое не равно нулю, записываем изменение
        if ($old_value == 0 && $new_value != 0) return true;
        
        // Вычисляем процент изменения
        $percent_change = abs(($new_value - $old_value) / $old_value * 100);
        
        // Получаем настраиваемые пороги
        $thresholds = get_option('fttradingapi_history_thresholds', []);
        
        // Определяем порог для текущего поля (с значениями по умолчанию)
        $default_thresholds = [
            'i_bal' => 2,
            'i_equi' => 2,
            'i_marg' => 2,
            'i_prof' => 1000,
            'leverage' => 2
        ];
        
        $threshold = isset($thresholds[$field_name]) ? $thresholds[$field_name] : 
                    (isset($default_thresholds[$field_name]) ? $default_thresholds[$field_name] : 2);
        
        // Проверяем, превышает ли изменение порог
        return $percent_change > $threshold;
    }

    /**
     * Сравнивает значения обычных полей с учетом типов данных
     */
    private function is_value_changed($old_value, $new_value) {
        // Для числовых значений используем нестрогое сравнение
        if (is_numeric($old_value) && is_numeric($new_value)) {
            return (float)$old_value != (float)$new_value;
        }
        
        // Для булевых значений
        if (is_bool($old_value) || is_bool($new_value)) {
            return (bool)$old_value !== (bool)$new_value;
        }
        
        // Для строк и прочих типов
        return (string)$old_value !== (string)$new_value;
    }


    /**
     * Вычисляет процент изменения
     */
    private function calculate_percent_change($old_value, $new_value) {
        if ($old_value == 0) return 0;
        return ($new_value - $old_value) / $old_value * 100;
    }

    public function track_changes($account_id, $old_data, $new_data) {
        global $wpdb;
        $history_table = $wpdb->prefix . 'contest_members_history';
        
        // Добавляем расчет объема активных ордеров, если он не присутствует в данных
        if (!isset($new_data['active_orders_volume']) && isset($new_data['connection_status']) && $new_data['connection_status'] === 'connected') {
            require_once 'class-orders.php';
            $orders = new Account_Orders();
            $new_data['active_orders_volume'] = $orders->get_active_orders_volume($account_id);
            
            // Если нет предыдущего значения, инициализируем его
            if (!isset($old_data['active_orders_volume'])) {
                $old_data['active_orders_volume'] = 0;
            }
            
            error_log("[ACCOUNT-HISTORY] Добавлен расчет active_orders_volume: " . $new_data['active_orders_volume'] . " (account_id: {$account_id})");
        }
        
        // Логируем весь входящий пакет данных для отладки
        error_log("[ACCOUNT-HISTORY] Получены данные для истории account_id={$account_id}: " . 
                 "old_data=" . json_encode($old_data) . ", new_data=" . json_encode($new_data));
        
    // Проверяем финансовые поля
    foreach ($this->financial_fields as $field) {
        if (isset($old_data[$field], $new_data[$field])) {
            // ОТЛАДКА v1.4.0: Расширенное логирование при нулевых значениях
            if ($new_data[$field] === '0' || $new_data[$field] === 0) {
                // Создаем полную диагностику API-ответа
                $api_diagnostic = [
                    'timestamp' => current_time('mysql'),
                    'account_id' => $account_id,
                    'field' => $field,
                    'value' => $new_data[$field],
                    'i_fio' => $new_data['i_fio'] ?? 'ОТСУТСТВУЕТ',
                    'connection_status' => $new_data['connection_status'] ?? 'НЕИЗВЕСТНО',
                    'fields_check' => []
                ];
                
                // Проверяем ключевые поля на соответствие ожидаемым типам и значениям
                $fields_to_check = ['i_bal', 'i_equi', 'i_marg', 'i_prof', 'leverage', 'i_fio', 'i_firma', 'i_cur', 'i_dr'];
                foreach ($fields_to_check as $check_field) {
                    $api_diagnostic['fields_check'][$check_field] = [
                        'exists' => isset($new_data[$check_field]),
                        'value' => $new_data[$check_field] ?? 'ОТСУТСТВУЕТ',
                        'type' => isset($new_data[$check_field]) ? gettype($new_data[$check_field]) : 'ОТСУТСТВУЕТ'
                    ];
                }
                
                // ИСПРАВЛЕНО v1.5.0: Убираем замену значений на -1, оставляем только логирование
                if (empty($new_data['i_fio'])) {
                    error_log("[ACCOUNT-HISTORY] ИСПРАВЛЕНО v1.5.0: Обнаружен 0 в {$field} при пустом i_fio - ДИАГНОСТИКА (БЕЗ ЗАМЕНЫ НА -1): " . 
                             json_encode($api_diagnostic, JSON_PRETTY_PRINT));
                } else {
                    // Логируем обнаружение нуля, когда i_fio заполнено (вероятно, реальный ноль)
                    error_log("[ACCOUNT-HISTORY] ИСПРАВЛЕНО v1.5.0: Обнаружен 0 в {$field}, i_fio заполнено ({$new_data['i_fio']}) - реальный ноль, сохраняем как есть: " . 
                             json_encode($api_diagnostic, JSON_PRETTY_PRINT));
                }
            }
            
            // Передаем имя поля для определения порога изменения
            if ($this->check_percent_change($field, $old_data[$field], $new_data[$field])) {
                // Вычисляем процент изменения для финансовых показателей
                $percent_change = $this->calculate_percent_change($old_data[$field], $new_data[$field]);
                
                // Вставляем запись в историю
                $wpdb->insert(
                    $history_table,
                    [
                        'account_id' => $account_id,
                        'field_name' => $field,
                        'old_value' => $old_data[$field],
                        'new_value' => $new_data[$field],
                        'change_percent' => $percent_change,
                        'change_date' => (isset($new_data['time_last_update']) && is_numeric($new_data['time_last_update']))
                            ? date('Y-m-d H:i:s', (int) $new_data['time_last_update'])
                            : current_time('mysql')
                    ],
                    ['%d', '%s', '%s', '%s', '%f', '%s']
                );
                
                error_log("[ACCOUNT-HISTORY] Запись добавлена: field={$field}, old={$old_data[$field]}, new={$new_data[$field]}, percent={$percent_change} (account_id: {$account_id})");
            }
        }
    }
        
        // Проверяем остальные отслеживаемые поля
        foreach ($this->tracked_fields as $field) {
            if (isset($old_data[$field], $new_data[$field])) {
                if ($this->is_value_changed($old_data[$field], $new_data[$field])) {
                    $insert_data = [
                        'account_id' => $account_id,
                        'field_name' => $field,
                        'old_value' => $old_data[$field],
                        'new_value' => $new_data[$field],
                        'change_date' => (isset($new_data['time_last_update']) && is_numeric($new_data['time_last_update']))
                            ? date('Y-m-d H:i:s', (int) $new_data['time_last_update'])
                            : current_time('mysql')
                    ];
                    
                    // Для статуса подключения сохраняем описание ошибки
                    if ($field === 'connection_status' && $new_data[$field] === 'disconnected') {
                        $insert_data['error_description'] = $new_data['error_description'] ?? '';
                    }
                    
                    $wpdb->insert(
                        $history_table,
                        $insert_data,
                        ['%d', '%s', '%s', '%s', '%s']
                    );
                }
            }
        }
    }
    
    public function format_field_value($field_name, $value, $row) {
        switch ($field_name) {
            case 'connection_status':
                if ($value === 'connected') {
                    return '<span style="color: green;">Подключен</span>';
                } else {
                    $error_info = isset($row->error_description) && !empty($row->error_description) 
                        ? ': ' . esc_html($row->error_description) 
                        : '';
                    return '<span style="color: red;">Отключен' . $error_info . '</span>';
                }
                break;
            case 'active_orders_volume':
                return esc_html($value) . ' лот.';
                break;
            case 'leverage':
                return '1:' . intval($value);
                break;
            case 'pass':
                // Показываем только звездочки для безопасности
                return str_repeat('*', min(strlen($value), 8));
                break;
            // Другие форматирования...
            default:
                return $value;
        }
    }
    
    /**
     * Получает историю изменений для счета
     */
    public function get_account_history($account_id, $limit = 50) {
        global $wpdb;
        $history_table = $wpdb->prefix . 'contest_members_history';
        
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$history_table} 
            WHERE account_id = %d 
            ORDER BY change_date DESC 
            LIMIT %d",
            $account_id,
            $limit
        ));
    }


// Добавляем в класс Account_History новый метод:

public function get_filtered_history($account_id, $field = '', $period = 'all', $sort = 'desc', $page = 1, $per_page = 10) {
    global $wpdb;
    $history_table = $wpdb->prefix . 'contest_members_history';
    
    $where = ['account_id = %d'];
    $params = [$account_id];

    // Фильтр по полю
    if (!empty($field)) {
        $where[] = 'field_name = %s';
        $params[] = $field;
    }

    // Фильтр по периоду
    switch ($period) {
        case 'day':
            $where[] = 'change_date >= DATE_SUB(NOW(), INTERVAL 1 DAY)';
            break;
        case 'week':
            $where[] = 'change_date >= DATE_SUB(NOW(), INTERVAL 1 WEEK)';
            break;
        case 'month':
            $where[] = 'change_date >= DATE_SUB(NOW(), INTERVAL 1 MONTH)';
            break;
        case 'year':
            $where[] = 'change_date >= DATE_SUB(NOW(), INTERVAL 1 YEAR)';
            break;
    }

    // Получаем общее количество записей
    $count_sql = "SELECT COUNT(*) FROM {$history_table} WHERE " . implode(' AND ', $where);
    $total_items = $wpdb->get_var($wpdb->prepare($count_sql, $params));

    // Вычисляем смещение для пагинации
    $page = max(1, (int)$page);
    $per_page = max(1, (int)$per_page);
    $offset = ($page - 1) * $per_page;

    // Основной запрос с пагинацией
    $sql = "SELECT * FROM {$history_table} 
            WHERE " . implode(' AND ', $where) . " 
            ORDER BY change_date " . ($sort === 'desc' ? 'DESC' : 'ASC') . "
            LIMIT %d OFFSET %d";
    
    $params[] = $per_page;
    $params[] = $offset;

    $results = $wpdb->get_results($wpdb->prepare($sql, $params));

    return [
        'results' => $results,
        'total_items' => $total_items,
        'total_pages' => ceil($total_items / $per_page),
        'current_page' => $page,
        'per_page' => $per_page
    ];
}

/**
 * Очищает всю историю изменений для указанного счета
 * 
 * @param int $account_id ID счета
 * @return bool Результат операции
 */
public function clear_account_history($account_id) {
    global $wpdb;
    $history_table = $wpdb->prefix . 'contest_members_history';
    
    $result = $wpdb->delete(
        $history_table,
        ['account_id' => $account_id],
        ['%d']
    );
    
    return $result !== false;
}


}
