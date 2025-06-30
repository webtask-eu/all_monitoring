<?php 

class Account_Orders {
    public function update_orders($account_id, $orders_data) {
        global $wpdb;
        $orders_table = $wpdb->prefix . 'contest_members_orders';
    
        // Логируем удаление старых ордеров
        $deleted = $wpdb->delete($orders_table, ['account_id' => $account_id]);
        error_log("Deleted {$deleted} old orders for account {$account_id}");
    
        if (!empty($orders_data)) {
            foreach ($orders_data as $order) {
                $result = $wpdb->insert(
                    $orders_table,
                    [
                        'account_id' => $account_id,
                        'ticket' => $order['ticket'],
                        'symbol' => $order['symbol'],
                        'type' => $order['type'],
                        'lots' => $order['lots'],
                        'open_time' => date('Y-m-d H:i:s', $order['open_time']),
                        'open_price' => $order['openprice'],
                        'sl' => $order['sl'],
                        'tp' => $order['tp'],
                        'profit' => $order['profit'],
                        'commission' => $order['commission'],
                        'swap' => $order['swap'],
                        'comment' => $order['comment']
                    ],
                    [
                        '%d', '%d', '%s', '%s', '%f', 
                        '%s', '%f', '%f', '%f', '%f',
                        '%f', '%f', '%s'
                    ]
                );
    
                if ($result === false) {
                    error_log("Error inserting order: " . $wpdb->last_error);
                } else {
                    error_log("Successfully inserted order {$order['ticket']}");
                }
            }
        }
    }
    

    public function get_account_orders($account_id) {
        global $wpdb;
        $orders_table = $wpdb->prefix . 'contest_members_orders';
        
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $orders_table WHERE account_id = %d ORDER BY open_time DESC",
            $account_id
        ));
    }


// Добавляем метод для обновления истории сделок
public function update_order_history($account_id, $history_data) {
    global $wpdb;
    $history_table = $wpdb->prefix . 'contest_members_order_history';
    $members_table = $wpdb->prefix . 'contest_members';
    
    $last_history_time = 0;
    
    // Если есть новая история - добавляем ее
    if (!empty($history_data)) {
        foreach ($history_data as $order) {
            // Проверяем, существует ли уже такой ордер
            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM $history_table WHERE account_id = %d AND ticket = %d",
                $account_id, $order['ticket']
            ));
            
            // Если ордер не существует, добавляем его
            if (!$exists) {
                $wpdb->insert(
                    $history_table,
                    [
                        'account_id' => $account_id,
                        'ticket' => $order['ticket'],
                        'symbol' => $order['symbol'],
                        'type' => $order['type'],
                        'lots' => $order['lots'],
                        'open_time' => date('Y-m-d H:i:s', $order['open_time']),
                        'close_time' => date('Y-m-d H:i:s', $order['close_time']),
                        'open_price' => $order['openprice'],
                        'close_price' => $order['closeprice'],
                        'sl' => $order['sl'],
                        'tp' => $order['tp'],
                        'profit' => $order['profit'],
                        'commission' => $order['commission'],
                        'swap' => $order['swap'],
                        'comment' => $order['comment']
                    ],
                    [
                        '%d', '%d', '%s', '%s', '%f', 
                        '%s', '%s', '%f', '%f', '%f',
                        '%f', '%f', '%f', '%f', '%s'
                    ]
                );
            }
            
            // Обновляем last_history_time
            $close_time = (int)$order['close_time'];
            if ($close_time > $last_history_time) {
                $last_history_time = $close_time;
            }
        }
        
        // Обновляем last_history_time в таблице счетов, если найдено новое значение
        if ($last_history_time > 0) {
            $wpdb->update(
                $members_table,
                ['last_history_time' => $last_history_time],
                ['id' => $account_id]
            );
        }
    }
}


// Добавляем метод для получения истории сделок
public function get_account_order_history($account_id, $items_per_page = 15, $page = 1) {
    global $wpdb;
    $history_table = $wpdb->prefix . 'contest_members_order_history';
    
    // Проверка, чтобы items_per_page не был равен нулю
    $items_per_page = max(1, (int)$items_per_page);
    
    // Получаем общее количество записей для расчета страниц
    $total_items = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM $history_table WHERE account_id = %d",
        $account_id
    ));
    
    // Вычисляем смещение для запроса
    $offset = ($page - 1) * $items_per_page;
    
    // Получаем данные для текущей страницы
    $sql = $wpdb->prepare(
        "SELECT * FROM $history_table WHERE account_id = %d ORDER BY close_time DESC LIMIT %d OFFSET %d",
        $account_id,
        $items_per_page,
        $offset
    );
    
    $results = $wpdb->get_results($sql);
    
    return [
        'results' => $results,
        'total_items' => $total_items,
        'total_pages' => ceil($total_items / $items_per_page),
        'current_page' => $page
    ];
}

/**
 * Рассчитывает общий объем открытых сделок (без учета отложенных ордеров) для счета
 * 
 * @param int $account_id ID счета
 * @return float Общий объем открытых сделок в лотах
 */
public function get_active_orders_volume($account_id) {
    global $wpdb;
    $orders_table = $wpdb->prefix . 'contest_members_orders';
    
    // Получаем только активные ордера (типы 0-1), без отложенных (типы 2-5)
    // 0 - BUY, 1 - SELL, 2-5 - отложенные ордера
    $active_volume = $wpdb->get_var($wpdb->prepare(
        "SELECT SUM(lots) FROM $orders_table WHERE account_id = %d AND type IN ('0', '1', 'buy', 'sell')",
        $account_id
    ));
    
    return (float)$active_volume ?: 0;
}

}
