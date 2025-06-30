<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Класс для расчета торговых метрик счета v1.0.4
 */
class Account_Trading_Metrics {
    
    /**
     * Рассчитывает профит фактор для счета
     * 
     * @param int $account_id ID счета
     * @return float Profit Factor
     */
    public function calculate_profit_factor($account_id) {
        global $wpdb;
        
        // Получаем данные из истории ордеров
        $history_table = $wpdb->prefix . 'contest_members_order_history';
        
        // Запрос на расчет Profit Factor
        $profit_factor = $wpdb->get_var($wpdb->prepare(
            "SELECT 
                CASE 
                    WHEN ABS(SUM(CASE WHEN profit < 0 THEN profit ELSE 0 END)) = 0 
                    THEN 'inf' 
                    ELSE ROUND(SUM(CASE WHEN profit > 0 THEN profit ELSE 0 END) / 
                         ABS(SUM(CASE WHEN profit < 0 THEN profit ELSE 0 END)), 2) 
                END AS profit_factor 
            FROM {$history_table} 
            WHERE account_id = %d 
            AND type NOT IN ('balance', 'deposit', 'withdrawal')",
            $account_id
        ));
        
        // Если нет данных или профит фактор не рассчитан
        if (is_null($profit_factor)) {
            return 0;
        }
        
        // V2024.05.07 - Обрабатываем строковое значение 'inf'
        if ($profit_factor === 'inf') {
            return 'inf';
        }
        
        return (float)$profit_factor;
    }
    
    /**
     * Рассчитывает среднюю продолжительность сделки
     * 
     * @param int $account_id ID счета
     * @return string Форматированная продолжительность
     */
    public function calculate_avg_trade_duration($account_id) {
        global $wpdb;
        
        // Получаем данные из истории ордеров
        $history_table = $wpdb->prefix . 'contest_members_order_history';
        
        // Запрос на расчет средней продолжительности сделки в секундах
        $avg_duration = $wpdb->get_var($wpdb->prepare(
            "SELECT 
                AVG(TIMESTAMPDIFF(SECOND, open_time, close_time)) AS avg_duration
            FROM {$history_table} 
            WHERE account_id = %d 
            AND type NOT IN ('balance', 'deposit', 'withdrawal')",
            $account_id
        ));
        
        // Если нет данных
        if (is_null($avg_duration)) {
            return 'Н/Д';
        }
        
        return $this->format_duration((int)$avg_duration);
    }

    /**
     * Форматирует продолжительность в секундах в читаемый формат
     * 
     * @param int $seconds Продолжительность в секундах
     * @return string Форматированная строка
     */
    private function format_duration($seconds) {
        if (is_null($seconds) || $seconds <= 0) {
            return 'Н/Д';
        }
        
        $days = floor($seconds / 86400);
        $seconds %= 86400;
        
        $hours = floor($seconds / 3600);
        $seconds %= 3600;
        
        $minutes = floor($seconds / 60);
        
        if ($days > 0) {
            return $days . ' д. ' . $hours . ' ч.';
        } elseif ($hours > 0) {
            return $hours . ' ч. ' . $minutes . ' мин.';
        } else {
            return $minutes . ' мин.';
        }
    }
    
    /**
     * Публичный метод для форматирования продолжительности в секундах
     * 
     * @param int $seconds Продолжительность в секундах
     * @return string Форматированная строка
     */
    public function format_duration_public($seconds) {
        return $this->format_duration($seconds);
    }
    
    /**
     * Рассчитывает все торговые метрики для счета
     * 
     * @param int $account_id ID счета
     * @return array Массив с торговыми метриками
     */
    public function calculate_metrics($account_id) {
        global $wpdb;
        
        // Получаем данные из истории ордеров
        $history_table = $wpdb->prefix . 'contest_members_order_history';
        
        $history_data = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$history_table} 
            WHERE account_id = %d",
            $account_id
        ));
        
        // Инициализируем массив для метрик
        $metrics = array(
            'total_trades' => 0,
            'winning_trades' => 0,
            'losing_trades' => 0,
            'win_rate' => 0,
            'profit_factor' => 0,
            'total_profit' => 0,
            'total_loss' => 0,
            'avg_profit' => 0,
            'avg_loss' => 0,
            'risk_reward_ratio' => 0,
            'avg_trade_duration' => $this->calculate_avg_trade_duration($account_id)
        );
        
        if (empty($history_data)) {
            return $metrics;
        }
        
        // Считаем базовые метрики
        $total_profit = 0;
        $total_loss = 0;
        $winning_trades = 0;
        $losing_trades = 0;
        
        foreach ($history_data as $order) {
            // Пропускаем балансовые операции
            if (in_array(strtolower($order->type), ['balance', 'deposit', 'withdrawal'])) {
                continue;
            }
            
            $metrics['total_trades']++;
            
            if ($order->profit > 0) {
                $total_profit += $order->profit;
                $winning_trades++;
            } else if ($order->profit < 0) {
                $total_loss += abs($order->profit);
                $losing_trades++;
            }
        }
        
        $metrics['winning_trades'] = $winning_trades;
        $metrics['losing_trades'] = $losing_trades;
        $metrics['total_profit'] = $total_profit;
        $metrics['total_loss'] = $total_loss;
        
        // Расчет win rate
        if ($metrics['total_trades'] > 0) {
            $metrics['win_rate'] = round(($winning_trades / $metrics['total_trades']) * 100, 2);
        }
        
        // Расчет profit factor
        if ($total_loss > 0) {
            $metrics['profit_factor'] = round($total_profit / $total_loss, 2);
        } else {
            $metrics['profit_factor'] = $total_profit > 0 ? 999 : 0;
        }
        
        // Расчет средней прибыли и среднего убытка
        if ($winning_trades > 0) {
            $metrics['avg_profit'] = round($total_profit / $winning_trades, 2);
        }
        
        if ($losing_trades > 0) {
            $metrics['avg_loss'] = round($total_loss / $losing_trades, 2);
        }
        
        // Расчет Risk/Reward Ratio
        if ($metrics['avg_loss'] > 0) {
            $metrics['risk_reward_ratio'] = round($metrics['avg_profit'] / $metrics['avg_loss'], 2);
        } else {
            $metrics['risk_reward_ratio'] = $metrics['avg_profit'] > 0 ? 'inf' : 0;
        }
        
        return $metrics;
    }

    /**
     * Рассчитывает статистику по инструментам для счета v1.0.2
     * 
     * @param int $account_id ID счета
     * @return array Массив со статистикой по инструментам
     */
    public function calculate_symbols_statistics($account_id) {
        global $wpdb;
        
        // Получаем данные из истории ордеров
        $history_table = $wpdb->prefix . 'contest_members_order_history';
        
        // SQL запрос для получения базовой статистики по символам
        $symbols_data = $wpdb->get_results($wpdb->prepare(
            "SELECT 
                symbol,
                COUNT(*) as total_trades,
                SUM(lots) as total_volume,
                SUM(profit) as total_profit,
                SUM(CASE WHEN profit > 0 THEN 1 ELSE 0 END) as winning_trades,
                SUM(CASE WHEN profit < 0 THEN 1 ELSE 0 END) as losing_trades,
                SUM(CASE WHEN profit > 0 THEN profit ELSE 0 END) as gross_profit,
                SUM(CASE WHEN profit < 0 THEN profit ELSE 0 END) as gross_loss,
                AVG(TIMESTAMPDIFF(SECOND, open_time, close_time)) as avg_duration
            FROM {$history_table} 
            WHERE account_id = %d 
            AND type NOT IN ('balance', 'deposit', 'withdrawal')
            GROUP BY symbol
            ORDER BY total_profit DESC",
            $account_id
        ));
        
        // Получим детализацию по направлениям (BUY/SELL) для каждого символа
        $symbols_stats = [];
        
        if (!empty($symbols_data)) {
            foreach ($symbols_data as $symbol_data) {
                $symbol = $symbol_data->symbol;
                
                // Получаем статистику по BUY операциям
                $buy_stats = $wpdb->get_row($wpdb->prepare(
                    "SELECT 
                        COUNT(*) as total_trades,
                        SUM(lots) as total_volume,
                        SUM(profit) as total_profit,
                        SUM(CASE WHEN profit > 0 THEN 1 ELSE 0 END) as winning_trades,
                        SUM(CASE WHEN profit < 0 THEN 1 ELSE 0 END) as losing_trades,
                        SUM(CASE WHEN profit > 0 THEN profit ELSE 0 END) as gross_profit,
                        SUM(CASE WHEN profit < 0 THEN profit ELSE 0 END) as gross_loss,
                        AVG(TIMESTAMPDIFF(SECOND, open_time, close_time)) as avg_duration
                    FROM {$history_table} 
                    WHERE account_id = %d 
                    AND symbol = %s
                    AND type = 'buy'
                    AND type NOT IN ('balance', 'deposit', 'withdrawal')",
                    $account_id,
                    $symbol
                ));
                
                // Получаем статистику по положительным BUY сделкам
                $buy_positive_stats = $wpdb->get_row($wpdb->prepare(
                    "SELECT 
                        COUNT(*) as total_trades,
                        SUM(lots) as total_volume,
                        SUM(profit) as total_profit,
                        AVG(TIMESTAMPDIFF(SECOND, open_time, close_time)) as avg_duration
                    FROM {$history_table} 
                    WHERE account_id = %d 
                    AND symbol = %s
                    AND type = 'buy'
                    AND profit > 0",
                    $account_id,
                    $symbol
                ));
                
                // Получаем статистику по отрицательным BUY сделкам
                $buy_negative_stats = $wpdb->get_row($wpdb->prepare(
                    "SELECT 
                        COUNT(*) as total_trades,
                        SUM(lots) as total_volume,
                        SUM(profit) as total_profit,
                        AVG(TIMESTAMPDIFF(SECOND, open_time, close_time)) as avg_duration
                    FROM {$history_table} 
                    WHERE account_id = %d 
                    AND symbol = %s
                    AND type = 'buy'
                    AND profit < 0",
                    $account_id,
                    $symbol
                ));
                
                // Получаем статистику по SELL операциям
                $sell_stats = $wpdb->get_row($wpdb->prepare(
                    "SELECT 
                        COUNT(*) as total_trades,
                        SUM(lots) as total_volume,
                        SUM(profit) as total_profit,
                        SUM(CASE WHEN profit > 0 THEN 1 ELSE 0 END) as winning_trades,
                        SUM(CASE WHEN profit < 0 THEN 1 ELSE 0 END) as losing_trades,
                        SUM(CASE WHEN profit > 0 THEN profit ELSE 0 END) as gross_profit,
                        SUM(CASE WHEN profit < 0 THEN profit ELSE 0 END) as gross_loss,
                        AVG(TIMESTAMPDIFF(SECOND, open_time, close_time)) as avg_duration
                    FROM {$history_table} 
                    WHERE account_id = %d 
                    AND symbol = %s
                    AND type = 'sell'
                    AND type NOT IN ('balance', 'deposit', 'withdrawal')",
                    $account_id,
                    $symbol
                ));
                
                // Получаем статистику по положительным SELL сделкам
                $sell_positive_stats = $wpdb->get_row($wpdb->prepare(
                    "SELECT 
                        COUNT(*) as total_trades,
                        SUM(lots) as total_volume,
                        SUM(profit) as total_profit,
                        AVG(TIMESTAMPDIFF(SECOND, open_time, close_time)) as avg_duration
                    FROM {$history_table} 
                    WHERE account_id = %d 
                    AND symbol = %s
                    AND type = 'sell'
                    AND profit > 0",
                    $account_id,
                    $symbol
                ));
                
                // Получаем статистику по отрицательным SELL сделкам
                $sell_negative_stats = $wpdb->get_row($wpdb->prepare(
                    "SELECT 
                        COUNT(*) as total_trades,
                        SUM(lots) as total_volume,
                        SUM(profit) as total_profit,
                        AVG(TIMESTAMPDIFF(SECOND, open_time, close_time)) as avg_duration
                    FROM {$history_table} 
                    WHERE account_id = %d 
                    AND symbol = %s
                    AND type = 'sell'
                    AND profit < 0",
                    $account_id,
                    $symbol
                ));
                
                // Расчет процента выигрышных сделок
                $win_rate = 0;
                if ($symbol_data->total_trades > 0) {
                    $win_rate = round(($symbol_data->winning_trades / $symbol_data->total_trades) * 100, 2);
                }
                
                // Расчет профит фактора для символа
                $profit_factor = 0;
                if (abs($symbol_data->gross_loss) > 0) {
                    $profit_factor = round($symbol_data->gross_profit / abs($symbol_data->gross_loss), 2);
                } else {
                    $profit_factor = $symbol_data->gross_profit > 0 ? 'inf' : 0;
                }
                
                // Расчет профит фактора для BUY
                $buy_profit_factor = 0;
                if (isset($buy_stats->gross_loss) && abs($buy_stats->gross_loss) > 0) {
                    $buy_profit_factor = round($buy_stats->gross_profit / abs($buy_stats->gross_loss), 2);
                } else {
                    $buy_profit_factor = isset($buy_stats->gross_profit) && $buy_stats->gross_profit > 0 ? 'inf' : 0;
                }
                
                // Расчет профит фактора для SELL
                $sell_profit_factor = 0;
                if (isset($sell_stats->gross_loss) && abs($sell_stats->gross_loss) > 0) {
                    $sell_profit_factor = round($sell_stats->gross_profit / abs($sell_stats->gross_loss), 2);
                } else {
                    $sell_profit_factor = isset($sell_stats->gross_profit) && $sell_stats->gross_profit > 0 ? 'inf' : 0;
                }
                
                // Форматирование продолжительности
                $avg_duration = $this->format_duration((int)$symbol_data->avg_duration);
                $buy_duration = $this->format_duration((int)$buy_stats->avg_duration);
                $sell_duration = $this->format_duration((int)$sell_stats->avg_duration);
                $buy_positive_duration = $this->format_duration((int)$buy_positive_stats->avg_duration);
                $buy_negative_duration = $this->format_duration((int)$buy_negative_stats->avg_duration);
                $sell_positive_duration = $this->format_duration((int)$sell_positive_stats->avg_duration);
                $sell_negative_duration = $this->format_duration((int)$sell_negative_stats->avg_duration);
                
                // Формируем итоговую статистику
                $symbols_stats[] = [
                    'symbol' => $symbol,
                    'total_trades' => (int)$symbol_data->total_trades,
                    'total_volume' => round((float)$symbol_data->total_volume, 2),
                    'total_profit' => round((float)$symbol_data->total_profit, 2),
                    'win_rate' => $win_rate,
                    'profit_factor' => $profit_factor,
                    'avg_duration' => $avg_duration,
                    'buy' => [
                        'total_trades' => (int)$buy_stats->total_trades,
                        'total_volume' => round((float)$buy_stats->total_volume, 2),
                        'total_profit' => round((float)$buy_stats->total_profit, 2),
                        'win_rate' => $buy_stats->total_trades > 0 ? round(($buy_stats->winning_trades / $buy_stats->total_trades) * 100, 2) : 0,
                        'profit_factor' => $buy_profit_factor,
                        'avg_duration' => $buy_duration,
                        'positive' => [
                            'total_trades' => (int)$buy_positive_stats->total_trades,
                            'total_volume' => round((float)$buy_positive_stats->total_volume, 2),
                            'total_profit' => round((float)$buy_positive_stats->total_profit, 2),
                            'avg_duration' => $buy_positive_duration
                        ],
                        'negative' => [
                            'total_trades' => (int)$buy_negative_stats->total_trades,
                            'total_volume' => round((float)$buy_negative_stats->total_volume, 2),
                            'total_profit' => round((float)$buy_negative_stats->total_profit, 2),
                            'avg_duration' => $buy_negative_duration
                        ]
                    ],
                    'sell' => [
                        'total_trades' => (int)$sell_stats->total_trades,
                        'total_volume' => round((float)$sell_stats->total_volume, 2),
                        'total_profit' => round((float)$sell_stats->total_profit, 2),
                        'win_rate' => $sell_stats->total_trades > 0 ? round(($sell_stats->winning_trades / $sell_stats->total_trades) * 100, 2) : 0,
                        'profit_factor' => $sell_profit_factor,
                        'avg_duration' => $sell_duration,
                        'positive' => [
                            'total_trades' => (int)$sell_positive_stats->total_trades,
                            'total_volume' => round((float)$sell_positive_stats->total_volume, 2),
                            'total_profit' => round((float)$sell_positive_stats->total_profit, 2),
                            'avg_duration' => $sell_positive_duration
                        ],
                        'negative' => [
                            'total_trades' => (int)$sell_negative_stats->total_trades,
                            'total_volume' => round((float)$sell_negative_stats->total_volume, 2),
                            'total_profit' => round((float)$sell_negative_stats->total_profit, 2),
                            'avg_duration' => $sell_negative_duration
                        ]
                    ]
                ];
            }
        }
        
        return $symbols_stats;
    }
} 