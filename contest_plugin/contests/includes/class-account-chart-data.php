<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Класс для работы с данными графика счета
 */
class Account_Chart_Data {
    
    /**
     * Получает агрегированные данные для графика баланса и equity
     *
     * @param int $account_id ID счета
     * @param string $period Период (week, month, year, all)
     * @return array Массив с данными для графика
     */
    public function get_chart_data($account_id, $period = 'week') {
        $this->current_period = $period;
        
        // Получаем данные счета
        global $wpdb;
        $table_name = $wpdb->prefix . 'contest_members';
        $account_data = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_name WHERE id = %d",
            $account_id
        ));
        
        if (!$account_data) {
            return [
                'error' => 'Счет не найден',
                'labels' => [],
                'balance' => [],
                'equity' => []
            ];
        }
        
        // Сначала получаем полную историю баланса за все время
        $full_history_data = $this->get_account_order_history($account_id, '1970-01-01 00:00:00');
        $full_balance_history = $this->reconstruct_balance_history($full_history_data, $account_data);
        
        // Определяем начальную дату для выбранного периода
        $start_date = $this->get_period_start_date($period);
        $start_timestamp = strtotime($start_date);
        
        // Если это не "all", фильтруем историю баланса по периоду,
        // но сначала находим ближайшую предыдущую точку как начальную
        if ($period !== 'all') {
            $initial_point = null;
            $filtered_balance_history = [];
            
            // Ищем ближайшую точку к началу периода для использования как начальной
            foreach ($full_balance_history as $point) {
                if ($point['timestamp'] < $start_timestamp) {
                    $initial_point = $point;
                } else {
                    break;
                }
            }
            
            // Если нашли начальную точку, добавляем её в начало отфильтрованной истории
            if ($initial_point) {
                // Создаем копию начальной точки с временной меткой начала периода
                $initial_point_copy = $initial_point;
                $initial_point_copy['timestamp'] = $start_timestamp;
                $initial_point_copy['date'] = $start_date;
                $filtered_balance_history[] = $initial_point_copy;
            }
            
            // Добавляем точки, которые входят в выбранный период
            foreach ($full_balance_history as $point) {
                if ($point['timestamp'] >= $start_timestamp) {
                    $filtered_balance_history[] = $point;
                }
            }
            
            // Используем отфильтрованную историю для графика
            $balance_history = $filtered_balance_history;
        } else {
            // Для периода "all" используем полную историю
            $balance_history = $full_balance_history;
        }
        
        // Получаем историю изменений equity
        $equity_history = $wpdb->get_results($wpdb->prepare(
            "SELECT change_date, new_value 
            FROM {$wpdb->prefix}contest_members_history 
            WHERE account_id = %d AND field_name = 'i_equi'
            AND change_date >= %s
            ORDER BY change_date ASC",
            $account_id,
            $start_date
        ));
        
        // Создаем отдельный массив для точек equity
        $equity_points = [];
        foreach ($equity_history as $equity_point) {
            $equity_timestamp = strtotime($equity_point->change_date);
            
            // Проверяем, что точка входит в выбранный период
            if ($equity_timestamp >= $start_timestamp) {
                $equity_points[] = [
                    'timestamp' => $equity_timestamp,
                    'date' => $equity_point->change_date,
                    'equity' => (float)$equity_point->new_value,
                    'is_equity_only' => true
                ];
            }
        }
        
        // Добавляем текущее значение equity, если оно отличается от последней точки
        $last_equity_point = end($equity_points);
        if (!$last_equity_point || $last_equity_point['equity'] != $account_data->equity) {
            $equity_points[] = [
                'timestamp' => strtotime($account_data->last_update),
                'date' => $account_data->last_update,
                'equity' => (float)$account_data->equity,
                'is_equity_only' => true
            ];
        }
        
        // Объединяем точки баланса и equity
        $all_points = array_merge($balance_history, $equity_points);
        
        // Сортируем все точки по времени
        usort($all_points, function($a, $b) {
            return $a['timestamp'] - $b['timestamp'];
        });
        
        // Обрабатываем точки, чтобы сохранить и баланс, и equity
        $processed_points = [];
        $seen_timestamps = [];
        
        foreach ($all_points as $point) {
            $timestamp = $point['timestamp'];
            
            if (!isset($seen_timestamps[$timestamp])) {
                // Новая уникальная точка
                $seen_timestamps[$timestamp] = count($processed_points);
                $processed_points[] = $point;
            } else {
                // Объединяем данные с существующей точкой
                $index = $seen_timestamps[$timestamp];
                
                // Добавляем баланс, если он есть в текущей точке
                if (isset($point['balance']) && !isset($processed_points[$index]['balance'])) {
                    $processed_points[$index]['balance'] = $point['balance'];
                    $processed_points[$index]['is_historical'] = $point['is_historical'] ?? false;
                }
                
                // Добавляем equity, если он есть в текущей точке
                if (isset($point['equity']) && !isset($processed_points[$index]['equity'])) {
                    $processed_points[$index]['equity'] = $point['equity'];
                }
            }
        }
        
        // Форматируем данные для графика
        $chart_data = $this->aggregate_data($processed_points);
        
        // Добавляем информацию о периоде и данных счета
        $chart_data['period'] = $period;
        $chart_data['current_balance'] = $account_data->balance;
        $chart_data['current_equity'] = $account_data->equity;
        
        return $chart_data;
    }


    
    
    // Добавим свойство для хранения текущего периода
    private $current_period = 'week';

    /**
     * Получает начальную дату для выбранного периода
     */
    private function get_period_start_date($period) {
        $current_time = current_time('timestamp');
        
        switch ($period) {
            case 'hour':
                return date('Y-m-d H:i:s', strtotime('-1 hour', $current_time));    
            case 'day':
                return date('Y-m-d H:i:s', strtotime('-1 day', $current_time));
            case 'week':
                return date('Y-m-d H:i:s', strtotime('-1 week', $current_time));
            case 'month':
                return date('Y-m-d H:i:s', strtotime('-1 month', $current_time));
            case 'year':
                return date('Y-m-d H:i:s', strtotime('-1 year', $current_time));
            case 'all':
                return '1970-01-01 00:00:00';
            default:
                return date('Y-m-d H:i:s', strtotime('-1 week', $current_time));
        }
    }
    
    /**
     * Возвращает формат даты и интервал агрегации для заданного периода
     */
    private function get_interval_for_period($period) {
        switch ($period) {
            case 'hour':
                return [
                    'interval' => 'minute',  // Агрегация по минутам
                    'interval_count' => 1,   // Каждая минута
                    'format' => 'Y-m-d H:i:s' // Формат до секунд
                ];
            case 'day':
                return [
                    'interval' => 'minute',  
                    'interval_count' => 10,  // Каждые 10 минут
                    'format' => 'Y-m-d H:i'
                ];
            // Остальные кейсы без изменений...
        }
    }

    /**
     * Получает данные счета
     */
    private function get_account_data($account_id) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'contest_members';
        
        return $wpdb->get_row($wpdb->prepare(
            "SELECT balance, equity, last_update FROM $table_name WHERE id = %d",
            $account_id
        ));
    }
    
    /**
     * Получает историю сделок счета
     */
    private function get_account_order_history($account_id, $start_date) {
        global $wpdb;
        $history_table = $wpdb->prefix . 'contest_members_order_history';
        
        // Для часа берем больше исторических данных
        $adjusted_date = $this->current_period === 'hour' 
            ? date('Y-m-d H:i:s', strtotime('-1 day', current_time('timestamp'))) 
            : $start_date;
        
        return $wpdb->get_results($wpdb->prepare(
            "SELECT ticket, symbol, type, lots, open_time, close_time, open_price, close_price, 
                    profit, commission, swap 
             FROM $history_table 
             WHERE account_id = %d AND close_time >= %s
             ORDER BY close_time ASC",
            $account_id,
            $adjusted_date
        ));
    }
    

/**
 * Восстанавливает историю баланса из истории сделок
 */
private function reconstruct_balance_history($history_data, $account_data) {
    $balance_history = [];
    
    // Сортируем историю от старых к новым
    usort($history_data, function($a, $b) {
        return strtotime($a->close_time) - strtotime($b->close_time);
    });
    
    // Начальный баланс (ищем первую транзакцию BALANCE)
    $current_balance = 0;
    $first_balance_found = false;
    
    // Обрабатываем историю в прямом порядке (от старых к новым)
    foreach ($history_data as $order) {
        // Если это первая транзакция BALANCE, устанавливаем начальный баланс
        if (!$first_balance_found && strtoupper($order->type) === 'BALANCE') {
            $current_balance = $order->profit;
            $first_balance_found = true;
        } else {
            // Обновляем баланс для каждой транзакции
            // Учитываем и profit, и комиссию
            $current_balance += $order->profit;
            $current_balance += $order->commission; // Комиссия обычно отрицательная
            
            // Также учитываем своп, если он есть
            if (isset($order->swap)) {
                $current_balance += $order->swap;
            }
        }
        
        // Проверяем, чтобы баланс не стал отрицательным
        if ($current_balance < 0) {
            $current_balance = 0.01; // Минимальное положительное значение
        }
        
        // Добавляем точку в историю
        $balance_history[] = [
            'timestamp' => strtotime($order->close_time),
            'date' => $order->close_time,
            'balance' => $current_balance,
            'equity' => null, // Для исторических точек equity неизвестен
            'is_historical' => true,
            'ticket' => $order->ticket,
            'type' => $order->type,
            'profit' => $order->profit,
            'commission' => $order->commission,
            'swap' => $order->swap ?? 0
        ];
    }
    
    // Если текущий баланс значительно отличается от последнего, добавляем точку с текущим балансом
    $last_historical_balance = end($balance_history)['balance'] ?? 0;
    if (abs($last_historical_balance - $account_data->balance) > 0.01) {
        $balance_history[] = [
            'timestamp' => strtotime($account_data->last_update),
            'date' => $account_data->last_update,
            'balance' => $account_data->balance,
            'equity' => $account_data->equity,
            'is_historical' => false
        ];
    }
    
    return $balance_history;
}
    
    /**
     * Метод для форматирования данных без агрегации
     */
    private function aggregate_data($balance_history, $interval = null, $max_intervals = null) {
        if (empty($balance_history)) {
            return [
                'labels' => [],
                'balance' => [],
                'equity' => [],
                'min_values' => [],
                'max_values' => []
            ];
        }
        
        // Всегда используем все точки без агрегации
        $labels = [];
        $balance_data = [];
        $equity_data = [];
        $min_values = [];
        $max_values = [];
        
        foreach ($balance_history as $point) {
            // Форматируем дату
            $formatted_date = date('Y-m-d H:i:s', $point['timestamp']);
            $labels[] = $formatted_date;
            
            // Добавляем точку баланса (null, если это только точка equity)
            if (isset($point['balance'])) {
                $balance_data[] = [
                    'y' => (float)$point['balance'],
                    'historical' => $point['is_historical'] ?? false
                ];
            } else {
                $balance_data[] = null;
            }
            
            // Добавляем точку equity, если есть
            if (isset($point['equity'])) {
                $equity_data[] = [
                    'y' => (float)$point['equity'],
                    'historical' => false // Equity всегда текущие
                ];
            } else {
                $equity_data[] = null; // Сохраняем null для сохранения индексации
            }
            
            // Сохраняем значения для расчета мин/макс
            if (isset($point['balance'])) {
                $min_values[] = (float)$point['balance'];
                $max_values[] = (float)$point['balance'];
            }
            if (isset($point['equity'])) {
                $min_values[] = (float)$point['equity'];
                $max_values[] = (float)$point['equity'];
            }
        }
        
        // Добавим отладочную информацию
        $debug = [
            'total_points' => count($balance_history),
            'balance_points' => count(array_filter($balance_history, function($p) { return isset($p['balance']); })),
            'equity_points' => count(array_filter($balance_history, function($p) { return isset($p['equity']); })),
            'historical_points' => count(array_filter($balance_history, function($p) { return $p['is_historical'] ?? false; }))
        ];
        
        return [
            'labels' => $labels,
            'balance' => $balance_data,
            'equity' => $equity_data,
            'min_values' => $min_values,
            'max_values' => $max_values,
            'debug' => $debug
        ];
    }    
    
    /**
     * Возвращает формат даты для заданного интервала
     */
    private function get_date_format_for_interval($interval) {
        switch ($interval) {
            case 'second':
                return 'Y-m-d H:i:s';
            case 'minute':
                return 'Y-m-d H:i'; // Формат для минут
            case 'hour':
                return 'Y-m-d H:00';
            case 'minute':
                return 'Y-m-d H:i'; // Формат для минут
            case 'day':
                return 'Y-m-d';
            case 'week':
                return 'Y-W'; // Год и номер недели
            case 'month':
                return 'Y-m';
            default:
                return 'Y-m-d';
        }
    }
    
    /**
     * Возвращает PHP строку интервала для strtotime
     */
    private function get_php_interval($interval) {
        switch ($interval) {
            case 'hour':
                return '+1 hour';
            case 'day':
                return '+1 day';
            case 'week':
                return '+1 week';
            case 'month':
                return '+1 month';
            default:
                return '+1 day';
        }
    }

/**
 * Получает данные графика для топ-N лидеров конкурса
 *
 * @param int $contest_id ID конкурса
 * @param int $top_count Количество лидеров (по умолчанию 3)
 * @param string $period Период (week, month, year, all)
 * @return array Массив с данными для графика
 */
public function get_leaders_chart_data($contest_id, $top_count = 3, $period = 'week') {
    global $wpdb;
    
    // Получаем топ-N лидеров конкурса
    $table_name = $wpdb->prefix . 'contest_members';
    $leaders = $wpdb->get_results($wpdb->prepare(
        "SELECT id, user_id, account_number, balance, equity 
         FROM $table_name 
         WHERE contest_id = %d 
         ORDER BY equity DESC 
         LIMIT %d",
        $contest_id,
        $top_count
    ));
    
    if (empty($leaders)) {
        return [
            'error' => 'Лидеры не найдены',
            'labels' => [],
            'datasets' => []
        ];
    }
    
    // Определяем начальную дату для выбранного периода
    $start_date = $this->get_period_start_date($period);
    
    // Формируем массив с данными для графика
    $result = [
        'labels' => [],
        'datasets' => []
    ];
    
    // Выбираем набор уникальных цветов для линий графика
    $colors = [
        ['rgba(255, 99, 132, 1)', 'rgba(255, 99, 132, 0.2)'],  // красный
        ['rgba(54, 162, 235, 1)', 'rgba(54, 162, 235, 0.2)'],  // синий
        ['rgba(255, 206, 86, 1)', 'rgba(255, 206, 86, 0.2)'],  // желтый
        ['rgba(75, 192, 192, 1)', 'rgba(75, 192, 192, 0.2)'],  // зеленый
        ['rgba(153, 102, 255, 1)', 'rgba(153, 102, 255, 0.2)'] // фиолетовый
    ];
    
    // Получаем данные для каждого лидера
    $all_labels = [];
    $datasets = [];
    
    foreach ($leaders as $index => $leader) {
        // Получаем историю баланса для этого счета
        $account_data = $this->get_chart_data($leader->id, $period);
        
        // Сохраняем все метки времени (для общей оси X)
        if (isset($account_data['labels'])) {
            $all_labels = array_merge($all_labels, $account_data['labels']);
        }
        
        // Получаем имя пользователя - используем приоритетный подход
        $name_to_display = '';
        
        if ($leader->user_id) {
            $user = get_userdata($leader->user_id);
            
            // 1. Проверяем user_nicename пользователя
            if ($user && !empty($user->user_nicename)) {
                $name_to_display = html_entity_decode($user->user_nicename, ENT_QUOTES, 'UTF-8');
            }
            // 2. Проверяем user_login пользователя
            else if ($user && !empty($user->user_login)) {
                $name_to_display = html_entity_decode($user->user_login, ENT_QUOTES, 'UTF-8');
            }
        }
        
        // Получаем дополнительные данные участника, если имя ещё не найдено
        if (empty($name_to_display)) {
            $participant = $wpdb->get_row($wpdb->prepare(
                "SELECT name, account_login FROM $table_name WHERE id = %d",
                $leader->id
            ));
            
            // 3. Проверяем поле name в объекте участника
            if (!empty($participant->name)) {
                $name_to_display = html_entity_decode($participant->name, ENT_QUOTES, 'UTF-8');
            }
            // 4. Проверяем поле account_login
            else if (!empty($participant->account_login)) {
                $name_to_display = html_entity_decode($participant->account_login, ENT_QUOTES, 'UTF-8');
            }
            // 5. Используем значение по умолчанию
            else {
                $name_to_display = 'Участник';
            }
        }
        
        // Добавляем в набор данных
        $datasets[] = [
            'account_id' => $leader->id,
            'account_number' => $leader->account_number,
            'trader_name' => $name_to_display,
            'balance_data' => isset($account_data['balance']) ? $account_data['balance'] : [],
            'equity_data' => isset($account_data['equity']) ? $account_data['equity'] : [],
            'labels' => isset($account_data['labels']) ? $account_data['labels'] : [],
            'color' => $colors[$index % count($colors)]
        ];
    }
    
    // Создаем уникальный отсортированный набор меток времени
    $all_labels = array_unique($all_labels);
    sort($all_labels);
    $result['labels'] = $all_labels;
    
    // Преобразуем данные в формат для Chart.js
    foreach ($datasets as $index => $dataset) {
        $equity_dataset = [
            'label' => $dataset['trader_name'],
            'data' => [],
            'borderColor' => $dataset['color'][0],
            'backgroundColor' => $dataset['color'][1],
            'borderWidth' => 2,
            'tension' => 0.1,
            'fill' => false,
            'pointRadius' => 0
        ];
        
        // Сопоставляем данные с общими метками времени
        foreach ($all_labels as $label) {
            $found = false;
            
            // Ищем соответствующую точку в данных этого трейдера
            for ($i = 0; $i < count($dataset['labels']); $i++) {
                if ($dataset['labels'][$i] === $label) {
                    // Используем equity для графика
                    $equity_value = isset($dataset['equity_data'][$i]) && $dataset['equity_data'][$i] !== null
                        ? $dataset['equity_data'][$i]['y']
                        : (isset($dataset['balance_data'][$i]) ? $dataset['balance_data'][$i]['y'] : null);
                    
                    $equity_dataset['data'][] = $equity_value;
                    $found = true;
                    break;
                }
            }
            
            // Если точка не найдена, добавляем null для непрерывности линии
            if (!$found) {
                $equity_dataset['data'][] = null;
            }
        }
        
        $result['datasets'][] = $equity_dataset;
    }
    
    return $result;
}

}
