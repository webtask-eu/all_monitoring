<?php
/**
 * Класс для проверки условий дисквалификации участников конкурса
 * 
 * @since 1.0.0
 */
class Contest_Disqualification_Checker {
    /**
     * Проверяет счет на все условия дисквалификации конкурса
     * 
     * @param int $account_id ID счета для проверки
     * @return array Массив с результатом проверки [is_disqualified => bool, reasons => array]
     */
    public function check_account_disqualification($account_id) {
        global $wpdb;
        
        // Получаем данные счета
        $table_name = $wpdb->prefix . 'contest_members';
        $account = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_name WHERE id = %d",
            $account_id
        ));
        
        if (!$account) {
            return ['is_disqualified' => false, 'reasons' => []];
        }
        
        // Получаем данные конкурса
        $contest_data = get_post_meta($account->contest_id, '_fttradingapi_contest_data', true);
        if (!is_array($contest_data)) {
            return ['is_disqualified' => false, 'reasons' => []];
        }
        
        // Массив для хранения всех причин дисквалификации
        $reasons = [];
        
        // Проверяем каждое условие дисквалификации
        
        // 1. Проверка на начальный депозит
        if (isset($contest_data['check_initial_deposit']) && $contest_data['check_initial_deposit'] == '1') {
            $result = $this->check_initial_deposit($account_id, $contest_data['initial_deposit']);
            if ($result['is_disqualified']) {
                $reasons[] = $result['reason'];
            }
        }
        
        // 2. Проверка кредитного плеча
        if (isset($contest_data['check_leverage']) && $contest_data['check_leverage'] == '1') {
            $result = $this->check_leverage($account_id, $contest_data['allowed_leverage']);
            if ($result['is_disqualified']) {
                $reasons[] = $result['reason'];
            }
        }
        
        // 3. Проверка на инструменты
        if (isset($contest_data['check_instruments']) && $contest_data['check_instruments'] == '1') {
            $result = $this->check_instruments(
                $account_id, 
                $contest_data['allowed_instruments'] ?? '*', 
                $contest_data['excluded_instruments'] ?? ''
            );
            if ($result['is_disqualified']) {
                $reasons[] = $result['reason'];
            }
        }
        
        // 4. Максимальный суммарный объем
        if (isset($contest_data['check_max_volume']) && $contest_data['check_max_volume'] == '1') {
            $result = $this->check_max_volume($account_id, $contest_data['max_volume']);
            if ($result['is_disqualified']) {
                $reasons[] = $result['reason'];
            }
        }
        
        // 5. Минимальное количество сделок
        if (isset($contest_data['check_min_trades']) && $contest_data['check_min_trades'] == '1') {
            // Проверяем, закончился ли конкурс
            $end_date = isset($contest_data['end_date']) ? $contest_data['end_date'] : '';
            
            if (!empty($end_date) && current_time('mysql') >= $end_date) {
                // Проверяем только если конкурс завершен
                $check_hedged = isset($contest_data['check_hedged_positions']) && $contest_data['check_hedged_positions'] == '1';
                $result = $this->check_min_trades($account_id, $contest_data['min_trades'], $check_hedged);
                if ($result['is_disqualified']) {
                    $reasons[] = $result['reason'];
                }
            }
        }
        
        // 6. Проверка сделок до начала конкурса
        if (isset($contest_data['check_pre_contest_trades']) && $contest_data['check_pre_contest_trades'] == '1') {
            $result = $this->check_pre_contest_trades($account_id, $account->contest_id);
            if ($result['is_disqualified']) {
                $reasons[] = $result['reason'];
            }
        }
        
        // 7. Проверка на минимальную прибыль
        if (isset($contest_data['check_min_profit']) && $contest_data['check_min_profit'] == '1') {
            // Проверяем, закончился ли конкурс
            $end_date = isset($contest_data['end_date']) ? $contest_data['end_date'] : '';
            
            if (!empty($end_date) && current_time('mysql') >= $end_date) {
                // Проверяем только если конкурс завершен
                $result = $this->check_min_profit($account_id, $contest_data['min_profit']);
                if ($result['is_disqualified']) {
                    $reasons[] = $result['reason'];
                }
            }
        }
        
        // Если есть хотя бы одна причина дисквалификации, счет дисквалифицирован
        $is_disqualified = !empty($reasons);
        
        return [
            'is_disqualified' => $is_disqualified, 
            'reasons' => $reasons
        ];
    }
    
    /**
     * Проверяет соответствие начального депозита указанному значению
     * 
     * @param int $account_id ID счета
     * @param float $required_deposit Требуемый начальный депозит
     * @return array Результат проверки
     */
    public function check_initial_deposit($account_id, $required_deposit) {
        global $wpdb;
        
        error_log("[DISQUALIFICATION-CHECK] Начата проверка депозита для счета ID: {$account_id}, требуемый депозит: {$required_deposit}");
        
        // Получаем данные счета (проверим таблицу временных данных или основную)
        $temp_table = $wpdb->prefix . 'contest_members_temp';
        $main_table = $wpdb->prefix . 'contest_members';
        $history_table = $wpdb->prefix . 'contest_members_history';
        $order_history_table = $wpdb->prefix . 'contest_members_order_history';
        
        // Сначала ищем в истории сделок (приоритетный источник)
        $initial_balance = $wpdb->get_var($wpdb->prepare(
            "SELECT profit FROM $order_history_table 
            WHERE account_id = %d AND type = 'balance' 
            ORDER BY open_time ASC LIMIT 1",
            $account_id
        ));
        
        error_log("[DISQUALIFICATION-CHECK] Попытка получить начальный баланс из истории сделок: " . var_export($initial_balance, true));
        
        // Если не найдено в истории сделок, попробуем получить из истории изменений
        if ($initial_balance === null) {
            $initial_balance = $wpdb->get_var($wpdb->prepare(
                "SELECT new_value FROM $history_table 
                WHERE account_id = %d AND field_name = 'balance' 
                ORDER BY change_date ASC LIMIT 1",
                $account_id
            ));
            
            error_log("[DISQUALIFICATION-CHECK] Записи в истории сделок не найдено. Проверяем историю изменений: " . var_export($initial_balance, true));
        }
        
        // Если история пуста (для новых счетов), используем требуемый депозит из настроек
        if ($initial_balance === null) {
            error_log("[DISQUALIFICATION-CHECK] История пуста, используем значение из настроек конкурса");
            
            // Получаем ID конкурса для этого счета
            $contest_id = $wpdb->get_var($wpdb->prepare(
                "SELECT contest_id FROM $main_table WHERE id = %d 
                 UNION 
                 SELECT contest_id FROM $temp_table WHERE id = %d",
                $account_id, $account_id
            ));
            
            error_log("[DISQUALIFICATION-CHECK] ID конкурса: " . var_export($contest_id, true));
            
            if ($contest_id) {
                // Получаем значение start_deposit из настроек конкурса
                $contest_data = get_post_meta($contest_id, '_fttradingapi_contest_data', true);
                $expected_deposit = isset($contest_data['start_deposit']) ? 
                    floatval($contest_data['start_deposit']) : 
                    floatval($required_deposit); // Если нет, используем значение required_deposit
                
                error_log("[DISQUALIFICATION-CHECK] Настройки конкурса: " . var_export($contest_data, true));
                error_log("[DISQUALIFICATION-CHECK] Ожидаемый депозит: {$expected_deposit}, требуемый: {$required_deposit}");
                
                // Просто проверяем, равен ли required_deposit этому значению
                if (abs((float)$required_deposit - (float)$expected_deposit) > 0.01) {
                    error_log("[DISQUALIFICATION-CHECK] Несоответствие в настройках депозита");
                    return [
                        'is_disqualified' => true,
                        'reason' => 'Несоответствие в настройках депозита: ' . 
                                  $required_deposit . ' (должно быть ' . $expected_deposit . ')'
                    ];
                }
                
                // Если соответствует, считаем что условие выполнено
                error_log("[DISQUALIFICATION-CHECK] Настройки депозита соответствуют требованиям");
                return ['is_disqualified' => false, 'reason' => ''];
            } else {
                // Если не смогли найти конкурс, пропускаем проверку
                error_log("[DISQUALIFICATION-CHECK] Не удалось найти конкурс для счета");
                return ['is_disqualified' => false, 'reason' => ''];
            }
        }
        
        // Если есть история, проверяем соответствие начального баланса требуемому
        error_log("[DISQUALIFICATION-CHECK] Проверка соответствия: баланс {$initial_balance} vs требуемый {$required_deposit}");
        if (abs((float)$initial_balance - (float)$required_deposit) > 0.01) {
            error_log("[DISQUALIFICATION-CHECK] Начальный депозит не соответствует требуемому");
            return [
                'is_disqualified' => true,
                'reason' => 'Начальный депозит не соответствует требуемому: ' . 
                           $initial_balance . ' (должен быть ' . $required_deposit . ')'
            ];
        }
        
        error_log("[DISQUALIFICATION-CHECK] Начальный депозит соответствует требуемому");
        return ['is_disqualified' => false, 'reason' => ''];
    }
    
    /**
     * Проверяет соответствие кредитного плеча требованиям конкурса
     * 
     * @param int $account_id ID счета
     * @param string $allowed_leverage Допустимое кредитное плечо
     * @return array Результат проверки
     */
    public function check_leverage($account_id, $allowed_leverage) {
        global $wpdb;
        
        // Получаем информацию о счете
        $accounts_table = $wpdb->prefix . 'contest_members';
        $account = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $accounts_table WHERE id = %d",
            $account_id
        ));
        
        if (!$account) {
            return ['is_disqualified' => false, 'reason' => 'Счет не найден'];
        }
        
        // Получаем плечо счета из поля leverage
        $account_leverage = isset($account->leverage) ? intval($account->leverage) : 0;
        
        // Если торговое плечо равно 0 или NULL, не учитываем его как фактор дисквалификации
        if ($account_leverage <= 0) {
            return ['is_disqualified' => false, 'reason' => ''];
        }
        
        // Преобразуем строку разрешенных плечей в массив
        $allowed_leverages = array_map('trim', explode(',', $allowed_leverage));
        
        // Проверяем соответствие плеча счета разрешенным значениям
        if (in_array('*', $allowed_leverages)) {
            // Если разрешены все плечи (*), то не дисквалифицируем
            return ['is_disqualified' => false, 'reason' => ''];
        }
        
        $account_leverage_str = '1:' . $account_leverage;
        
        // Проверяем, соответствует ли плечо счета одному из разрешенных
        if (!in_array($account_leverage_str, $allowed_leverages) && !in_array((string)$account_leverage, $allowed_leverages)) {
            return [
                'is_disqualified' => true,
                'reason' => 'Неразрешенное кредитное плечо: ' . $account_leverage_str . ' (разрешены ' . $allowed_leverage . ')'
            ];
        }
        
        return ['is_disqualified' => false, 'reason' => ''];
    }
    
    /**
     * Проверяет использование разрешенных/запрещенных инструментов
     * 
     * @param int $account_id ID счета
     * @param string $allowed_instruments Разрешенные инструменты (через запятую)
     * @param string $excluded_instruments Запрещенные инструменты (через запятую)
     * @return array Результат проверки
     */
    public function check_instruments($account_id, $allowed_instruments, $excluded_instruments) {
        global $wpdb;
        
        // Подготавливаем массивы разрешенных и запрещенных инструментов
        $allowed_arr = array_map('trim', explode(',', $allowed_instruments));
        $excluded_arr = array_map('trim', explode(',', $excluded_instruments));
        
        // Получаем все уникальные инструменты из открытых ордеров и истории
        $orders_table = $wpdb->prefix . 'contest_members_orders';
        $history_table = $wpdb->prefix . 'contest_members_order_history';
        
        // Получаем символы из открытых ордеров
        $open_symbols = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT symbol FROM $orders_table WHERE account_id = %d",
            $account_id
        ));
        
        // Получаем символы из истории ордеров
        $history_symbols = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT symbol FROM $history_table WHERE account_id = %d",
            $account_id
        ));
        
        // Объединяем все символы
        $all_symbols = array_unique(array_merge($open_symbols, $history_symbols));
        
        // Проверяем каждый символ
        foreach ($all_symbols as $symbol) {
            // Игнорируем символ "-", так как он используется для технических данных
            if ($symbol === '-') {
                continue;
            }
            
            // Проверка на запрещенные инструменты
            foreach ($excluded_arr as $excluded) {
                if (empty($excluded)) continue;
                
                // Поддержка шаблонов с *
                if (strpos($excluded, '*') !== false) {
                    $pattern = '/^' . str_replace('*', '.*', $excluded) . '$/i';
                    if (preg_match($pattern, $symbol)) {
                        return [
                            'is_disqualified' => true,
                            'reason' => 'Использован запрещенный инструмент: ' . $symbol
                        ];
                    }
                } elseif ($symbol === $excluded) {
                    return [
                        'is_disqualified' => true,
                        'reason' => 'Использован запрещенный инструмент: ' . $symbol
                    ];
                }
            }
            
            // Если разрешены все инструменты (*)
            if (in_array('*', $allowed_arr)) {
                continue;
            }
            
            // Проверка на разрешенные инструменты
            $is_allowed = false;
            foreach ($allowed_arr as $allowed) {
                if (empty($allowed)) continue;
                
                // Поддержка шаблонов с *
                if (strpos($allowed, '*') !== false) {
                    $pattern = '/^' . str_replace('*', '.*', $allowed) . '$/i';
                    if (preg_match($pattern, $symbol)) {
                        $is_allowed = true;
                        break;
                    }
                } elseif ($symbol === $allowed) {
                    $is_allowed = true;
                    break;
                }
            }
            
            if (!$is_allowed) {
                return [
                    'is_disqualified' => true,
                    'reason' => 'Использован неразрешенный инструмент: ' . $symbol
                ];
            }
        }
        
        return ['is_disqualified' => false, 'reason' => ''];
    }
    
    /**
     * Проверяет максимальный суммарный объем открытых сделок
     * 
     * @param int $account_id ID счета
     * @param float $max_volume Максимальный объем
     * @return array Результат проверки
     */
    public function check_max_volume($account_id, $max_volume) {
        global $wpdb;
        
        // Получаем историю ордеров для анализа
        $history_table = $wpdb->prefix . 'contest_members_order_history';
        $all_orders = $wpdb->get_results($wpdb->prepare(
            "SELECT ticket, symbol, type, lots, open_time, close_time 
             FROM $history_table 
             WHERE account_id = %d AND type IN ('buy', 'sell') 
             ORDER BY open_time ASC",
            $account_id
        ));
        
        // Если нет истории, проверяем только текущие открытые сделки
        if (empty($all_orders)) {
            $orders_table = $wpdb->prefix . 'contest_members_orders';
            $total_volume = $wpdb->get_var($wpdb->prepare(
                "SELECT SUM(lots) FROM $orders_table WHERE account_id = %d AND type IN ('buy', 'sell')",
                $account_id
            ));
            
            if ($total_volume === null) {
                $total_volume = 0;
            }
            
            if ((float)$total_volume > (float)$max_volume) {
                return [
                    'is_disqualified' => true,
                    'reason' => 'Превышен максимальный суммарный объем открытых сделок: ' . 
                               $total_volume . ' (максимум ' . $max_volume . ')'
                ];
            }
            
            return ['is_disqualified' => false, 'reason' => ''];
        }
        
        // Создаем временную шкалу всех событий (открытие и закрытие сделок)
        $timeline = [];
        
        foreach ($all_orders as $order) {
            // Добавляем событие открытия сделки
            $timeline[] = [
                'time' => strtotime($order->open_time),
                'event' => 'open',
                'ticket' => $order->ticket,
                'volume' => $order->lots
            ];
            
            // Добавляем событие закрытия сделки
            $timeline[] = [
                'time' => strtotime($order->close_time),
                'event' => 'close',
                'ticket' => $order->ticket,
                'volume' => $order->lots
            ];
        }
        
        // Сортируем временную шкалу по времени
        usort($timeline, function($a, $b) {
            if ($a['time'] == $b['time']) {
                // Если времена одинаковы, закрытие должно идти перед открытием
                if ($a['event'] == 'close' && $b['event'] == 'open') {
                    return -1;
                } elseif ($a['event'] == 'open' && $b['event'] == 'close') {
                    return 1;
                } else {
                    return 0;
                }
            }
            return $a['time'] - $b['time'];
        });
        
        // Отслеживаем открытые позиции и их объем
        $open_positions = [];
        $max_total_volume = 0;
        $current_total_volume = 0;
        $violation_time = '';
        
        foreach ($timeline as $event) {
            if ($event['event'] == 'open') {
                // Открытие новой позиции
                $open_positions[$event['ticket']] = $event['volume'];
                $current_total_volume += $event['volume'];
            } else {
                // Закрытие позиции
                if (isset($open_positions[$event['ticket']])) {
                    $current_total_volume -= $open_positions[$event['ticket']];
                    unset($open_positions[$event['ticket']]);
                }
            }
            
            // Проверяем, был ли превышен максимальный объем
            if ($current_total_volume > $max_total_volume) {
                $max_total_volume = $current_total_volume;
                $violation_time = date('Y-m-d H:i:s', $event['time']);
            }
        }
        
        // Проверяем, есть ли нарушение
        if ($max_total_volume > (float)$max_volume) {
            return [
                'is_disqualified' => true,
                'reason' => 'Превышен максимальный суммарный объем открытых сделок: ' . 
                           number_format($max_total_volume, 2) . ' (максимум ' . $max_volume . 
                           '), обнаружено в ' . $violation_time
            ];
        }
        
        return ['is_disqualified' => false, 'reason' => ''];
    }
    
    /**
     * Проверяет минимальное количество сделок
     * 
     * @param int $account_id ID счета
     * @param int $min_trades Минимальное количество сделок
     * @param bool $check_hedged Проверять хеджированные позиции
     * @return array Результат проверки
     */
    public function check_min_trades($account_id, $min_trades, $check_hedged = false) {
        global $wpdb;
        
        $history_table = $wpdb->prefix . 'contest_members_order_history';
        
        if (!$check_hedged) {
            // Просто считаем количество сделок
            $total_trades = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $history_table WHERE account_id = %d",
                $account_id
            ));
        } else {
            // Считаем уникальные направления по каждому символу
            $unique_positions = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(DISTINCT CONCAT(symbol, type)) FROM $history_table WHERE account_id = %d",
                $account_id
            ));
            $total_trades = $unique_positions;
        }
        
        if ($total_trades < $min_trades) {
            return [
                'is_disqualified' => true,
                'reason' => 'Недостаточное количество сделок: ' . 
                           $total_trades . ' (минимум ' . $min_trades . ')'
            ];
        }
        
        return ['is_disqualified' => false, 'reason' => ''];
    }
    
    /**
     * Проверяет наличие сделок до даты начала конкурса
     * 
     * @param int $account_id ID счета
     * @param int $contest_id ID конкурса
     * @return array Результат проверки
     */
    public function check_pre_contest_trades($account_id, $contest_id) {
        global $wpdb;
        
        // Получаем дату начала конкурса
        $contest_data = get_post_meta($contest_id, '_fttradingapi_contest_data', true);
        if (!isset($contest_data['start_date']) || empty($contest_data['start_date'])) {
            return ['is_disqualified' => false, 'reason' => ''];
        }
        
        $start_date = $contest_data['start_date'];
        
        // Проверяем наличие сделок до даты начала конкурса
        $history_table = $wpdb->prefix . 'contest_members_order_history';
        $pre_contest_trades = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $history_table 
            WHERE account_id = %d AND open_time < %s",
            $account_id,
            $start_date
        ));
        
        if ($pre_contest_trades > 0) {
            return [
                'is_disqualified' => true,
                'reason' => 'Обнаружены сделки до начала конкурса: ' . $pre_contest_trades . ' шт.'
            ];
        }
        
        return ['is_disqualified' => false, 'reason' => ''];
    }
    
    /**
     * Проверяет минимальную прибыль на момент завершения конкурса
     * 
     * @param int $account_id ID счета
     * @param float $min_profit Минимальная прибыль в процентах
     * @return array Результат проверки
     */
    public function check_min_profit($account_id, $min_profit) {
        global $wpdb;
        
        // Получаем данные счета и конкурса
        $table_name = $wpdb->prefix . 'contest_members';
        $account = $wpdb->get_row($wpdb->prepare(
            "SELECT m.*, p.ID as contest_id 
             FROM $table_name m
             JOIN {$wpdb->posts} p ON m.contest_id = p.ID
             WHERE m.id = %d",
            $account_id
        ));
        
        if (!$account) {
            return ['is_disqualified' => false, 'reason' => ''];
        }
        
        // Получаем начальный депозит из настроек конкурса
        $contest_data = get_post_meta($account->contest_id, '_fttradingapi_contest_data', true);
        $initial_deposit = isset($contest_data['start_deposit']) ? floatval($contest_data['start_deposit']) : 10000;
        
        // Рассчитываем текущую прибыль в процентах
        $current_profit_percent = 0;
        if ($initial_deposit > 0) {
            $profit_amount = $account->equity - $initial_deposit;
            $current_profit_percent = ($profit_amount / $initial_deposit) * 100;
        }
        
        if ($current_profit_percent < (float)$min_profit) {
            return [
                'is_disqualified' => true,
                'reason' => 'Прибыль ниже минимальной: ' . 
                           number_format($current_profit_percent, 2) . '% (минимум ' . $min_profit . '%)'
            ];
        }
        
        return ['is_disqualified' => false, 'reason' => ''];
    }
    
    /**
     * Дисквалифицирует счет с указанной причиной
     * 
     * @param int $account_id ID счета
     * @param string|array $reason Причина или массив причин дисквалификации
     * @return bool Результат операции
     */
    public function disqualify_account($account_id, $reason) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'contest_members';
        
        // Обработка массива причин
        if (is_array($reason)) {
            // Объединяем все причины в одну строку с разделителями и двойным переносом строки
            $reason_str = implode("\n\n• ", array_map(function($item) {
                // Убираем маркеры списка, если они уже присутствуют
                return trim(str_replace(['• ', '- '], '', $item));
            }, $reason));
            
            // Добавляем маркер в начало, если у нас более одной причины
            if (count($reason) > 1) {
                $reason_str = "• " . $reason_str;
            }
        } else {
            $reason_str = $reason;
        }
        
        // Устанавливаем статус "disqualified" и сохраняем причину
        $result = $wpdb->update(
            $table_name,
            [
                'connection_status' => 'disqualified',
                'error_description' => $reason_str
            ],
            ['id' => $account_id],
            ['%s', '%s'],
            ['%d']
        );
        
        return $result !== false;
    }
    
    /**
     * Снимает дисквалификацию с аккаунта
     * 
     * @param int $account_id ID аккаунта
     * @return array Результат проверки после снятия дисквалификации
     */
    public function remove_account_disqualification($account_id) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'contest_members';
        
        // Логируем начало процесса снятия дисквалификации
        if (function_exists('ft_api_log')) {
            ft_api_log("Начало процесса снятия дисквалификации для счета $account_id", "Снятие дисквалификации", "info");
        }
        
        // Обновляем статус счета на 'connected' и очищаем описание ошибки
        $updated = $wpdb->update(
            $table_name,
            [
                'connection_status' => 'connected',
                'error_description' => ''
            ],
            ['id' => $account_id],
            ['%s', '%s'],
            ['%d']
        );
        
        if ($updated === false) {
            if (function_exists('ft_api_log')) {
                ft_api_log("Ошибка при обновлении статуса счета $account_id: " . $wpdb->last_error, "Снятие дисквалификации", "error");
            }
            return [
                'is_disqualified' => true,
                'reasons' => ['Ошибка базы данных при снятии дисквалификации: ' . $wpdb->last_error]
            ];
        }
        
        // После снятия дисквалификации проверяем счет заново
        $result = $this->check_account_disqualification($account_id);
        
        if (function_exists('ft_api_log')) {
            if ($result['is_disqualified']) {
                ft_api_log([
                    'account_id' => $account_id,
                    'is_still_disqualified' => true,
                    'reasons' => $result['reasons']
                ], "Счет остается дисквалифицированным", "warn");
            } else {
                ft_api_log("Дисквалификация успешно снята со счета $account_id", "Снятие дисквалификации", "info");
            }
        }
        
        return $result;
    }
} 