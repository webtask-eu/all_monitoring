<?php
/**
 * Шаблон для отображения статистики трейдера
 */
get_header();

// Проверяем, передан ли ID пользователя
if (!isset($_GET['trader_id']) || empty($_GET['trader_id'])) {
    // Если ID не передан, перенаправляем на главную
    wp_redirect(home_url());
    exit;
}

// Получаем ID трейдера из параметра URL
$trader_id = intval($_GET['trader_id']);

// Получаем данные пользователя
$trader_user_data = get_userdata($trader_id);
if (!$trader_user_data) {
    // Если пользователь не найден, перенаправляем на главную
    wp_redirect(home_url());
    exit;
}

// Получаем имя пользователя для отображения
$trader_name = $trader_user_data->display_name;
if (empty($trader_name)) {
    $trader_name = $trader_user_data->user_login;
}

// Дата регистрации
$registration_date = date_i18n('M j, Y', strtotime($trader_user_data->user_registered));

// Получаем все участия трейдера в конкурсах
global $wpdb;
$members_table = $wpdb->prefix . 'contest_members';
$trader_contests = $wpdb->get_results($wpdb->prepare(
    "SELECT m.*, p.post_title as contest_name, m.connection_status as member_status,
            pm.meta_value as sorting_date
     FROM {$members_table} m 
     LEFT JOIN {$wpdb->posts} p ON m.contest_id = p.ID 
     LEFT JOIN {$wpdb->postmeta} pm ON m.contest_id = pm.post_id AND pm.meta_key = '_contest_sorting_start_date'
     WHERE m.user_id = %d 
     ORDER BY pm.meta_value DESC",
    $trader_id
));

// Получаем общую статистику
$contests_count = count($trader_contests);
$best_place = 9999;
$total_prizes = 0;
$finished_contests = 0;
$active_contests = 0;

// Общая статистика торговли
$total_trades = 0;
$total_winning_trades = 0;
$total_profit = 0;
$total_loss = 0;
$instruments_count = [];

// Лучшие и худшие сделки
$best_trades = [];
$worst_trades = [];

// Проходим по всем конкурсам трейдера
foreach ($trader_contests as $contest) {
    // Считаем активные и завершенные конкурсы по статусу участия
    // Статус участия: active, disqualified, quitted, finished
    if (isset($contest->member_status) && $contest->member_status === 'active') {
        $active_contests++;
    } else {
        $finished_contests++;
    }
    
    // Ищем лучшее место
    if (isset($contest->place) && $contest->place > 0 && $contest->place < $best_place) {
        $best_place = $contest->place;
    }
    
    // Считаем призы
    if (isset($contest->prize) && $contest->prize > 0) {
        $total_prizes += $contest->prize;
    }
    
    // Получаем историю сделок для этого конкурса
    $history_table = $wpdb->prefix . 'contest_members_order_history';
    $trades = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM {$history_table} 
         WHERE account_id = %d 
         AND type NOT IN ('balance', 'deposit', 'withdrawal')",
        $contest->id
    ));
    
    // Считаем сделки
    foreach ($trades as $trade) {
        $total_trades++;
        
        // Учитываем выигрышные сделки
        if (isset($trade->profit) && $trade->profit > 0) {
            $total_winning_trades++;
            $total_profit += $trade->profit;
        } else if (isset($trade->profit) && $trade->profit < 0) {
            $total_loss += abs($trade->profit);
        }
        
        // Считаем сделки по инструментам
        if (!empty($trade->symbol)) {
            if (!isset($instruments_count[$trade->symbol])) {
                $instruments_count[$trade->symbol] = 0;
            }
            $instruments_count[$trade->symbol]++;
        }
        
        // Собираем лучшие сделки
        if (count($best_trades) < 5) {
            $best_trades[] = $trade;
            usort($best_trades, function($a, $b) {
                return (isset($b->profit) ? $b->profit : 0) - (isset($a->profit) ? $a->profit : 0);
            });
        } else if (isset($trade->profit) && isset($best_trades[4]->profit) && $trade->profit > $best_trades[4]->profit) {
            $best_trades[4] = $trade;
            usort($best_trades, function($a, $b) {
                return (isset($b->profit) ? $b->profit : 0) - (isset($a->profit) ? $a->profit : 0);
            });
        }
        
        // Собираем худшие сделки
        if (count($worst_trades) < 5) {
            $worst_trades[] = $trade;
            usort($worst_trades, function($a, $b) {
                return (isset($a->profit) ? $a->profit : 0) - (isset($b->profit) ? $b->profit : 0);
            });
        } else if (isset($trade->profit) && isset($worst_trades[4]->profit) && $trade->profit < $worst_trades[4]->profit) {
            $worst_trades[4] = $trade;
            usort($worst_trades, function($a, $b) {
                return (isset($a->profit) ? $a->profit : 0) - (isset($b->profit) ? $b->profit : 0);
            });
        }
    }
}

// Форматируем значения
$win_rate = $total_trades > 0 ? round(($total_winning_trades / $total_trades) * 100, 1) : 0;
$profit_factor = $total_loss > 0 ? round($total_profit / $total_loss, 2) : ($total_profit > 0 ? 'inf' : 0);

// Получаем любимые инструменты (сортируем по количеству сделок)
arsort($instruments_count);
$favorite_instruments = array_slice($instruments_count, 0, 6);

// Включаем класс для расчета метрик
require_once plugin_dir_path(dirname(__FILE__)) . 'includes/class-account-trading-metrics.php';
$metrics_calculator = new Account_Trading_Metrics();

// Получаем историю всех сделок для средней продолжительности
$history_table = $wpdb->prefix . 'contest_members_order_history';
$all_account_ids = [];

// Безопасно извлекаем ID аккаунтов
foreach ($trader_contests as $contest) {
    if (isset($contest->id) && !empty($contest->id)) {
        $all_account_ids[] = $contest->id;
    }
}

$account_ids_str = !empty($all_account_ids) ? implode(',', array_map('intval', $all_account_ids)) : '';
$avg_duration = 'N/A';

if (!empty($account_ids_str)) {
    $avg_duration_seconds = $wpdb->get_var(
        "SELECT AVG(TIMESTAMPDIFF(SECOND, open_time, close_time)) 
         FROM {$history_table} 
         WHERE account_id IN ({$account_ids_str})
         AND type NOT IN ('balance', 'deposit', 'withdrawal')"
    );
    
    if ($avg_duration_seconds) {
        // Упрощенный формат - только дни
        $days = floor($avg_duration_seconds / 86400);
        $avg_duration = $days . '.1 days';
    }
}
?>

<div class="trader-statistics-container">
    <!-- Хлебные крошки -->
    <div class="trader-breadcrumbs">
        <a href="<?php echo esc_url(home_url()); ?>">Главная</a>
        <span class="separator">›</span>
        <a href="<?php echo esc_url(home_url('/trader-contests/')); ?>">Конкурсы трейдеров</a>
        <span class="separator">›</span>
        <span><?php echo esc_html($trader_name); ?></span>
    </div>

    <!-- Заголовок страницы с именем трейдера -->
    <div class="trader-header">
        <h1 class="trader-name"><?php echo esc_html($trader_name); ?></h1>
    </div>
    
    <!-- Блок с основной статистикой -->
    <div class="trader-stats-cards">
        <div class="trader-stat-card registered">
            <div class="stat-icon">
                <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <path d="M8 2V5M16 2V5M3.5 9.09H20.5M21 8.5V17C21 20 19.5 22 16 22H8C4.5 22 3 20 3 17V8.5C3 5.5 4.5 3.5 8 3.5H16C19.5 3.5 21 5.5 21 8.5Z" stroke="currentColor" stroke-width="1.5" stroke-miterlimit="10" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
            </div>
            <div class="stat-info">
                <div class="stat-title">Registered</div>
                <div class="stat-value"><?php echo esc_html($registration_date); ?></div>
            </div>
        </div>
        
        <div class="trader-stat-card contests">
            <div class="stat-icon">
                <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <path d="M12 15C13.6569 15 15 13.6569 15 12C15 10.3431 13.6569 9 12 9C10.3431 9 9 10.3431 9 12C9 13.6569 10.3431 15 12 15Z" stroke="currentColor" stroke-width="1.5" stroke-miterlimit="10" stroke-linecap="round" stroke-linejoin="round"/>
                    <path d="M2 12.88V11.12C2 10.08 2.85 9.22 3.9 9.22C5.71 9.22 6.45 7.94 5.54 6.37C5.02 5.47 5.33 4.3 6.24 3.78L7.97 2.79C8.76 2.32 9.78 2.6 10.25 3.39L10.36 3.58C11.26 5.15 12.74 5.15 13.65 3.58L13.76 3.39C14.23 2.6 15.25 2.32 16.04 2.79L17.77 3.78C18.68 4.3 18.99 5.47 18.47 6.37C17.56 7.94 18.3 9.22 20.11 9.22C21.15 9.22 22.01 10.07 22.01 11.12V12.88C22.01 13.92 21.16 14.78 20.11 14.78C18.3 14.78 17.56 16.06 18.47 17.63C18.99 18.54 18.68 19.7 17.77 20.22L16.04 21.21C15.25 21.68 14.23 21.4 13.76 20.61L13.65 20.42C12.75 18.85 11.27 18.85 10.36 20.42L10.25 20.61C9.78 21.4 8.76 21.68 7.97 21.21L6.24 20.22C5.33 19.7 5.02 18.53 5.54 17.63C6.45 16.06 5.71 14.78 3.9 14.78C2.85 14.78 2 13.92 2 12.88Z" stroke="currentColor" stroke-width="1.5" stroke-miterlimit="10" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
            </div>
            <div class="stat-info">
                <div class="stat-title">Contests</div>
                <div class="stat-value"><?php echo esc_html($contests_count); ?></div>
            </div>
        </div>
        
        <div class="trader-stat-card active">
            <div class="stat-icon">
                <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <path d="M22 12C22 17.52 17.52 22 12 22C6.48 22 2 17.52 2 12C2 6.48 6.48 2 12 2C17.52 2 22 6.48 22 12Z" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                    <path d="M15.71 15.18L12.61 13.33C12.07 13.01 11.63 12.24 11.63 11.61V7.51001" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
            </div>
            <div class="stat-info">
                <div class="stat-title">Active</div>
                <div class="stat-value"><?php echo esc_html($active_contests); ?></div>
            </div>
        </div>
        
        <div class="trader-stat-card best-place">
            <div class="stat-icon">
                <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <path d="M12 16.5V18.6" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                    <path d="M7.5 10.5C7.5 7.6 9.8 5.3 12.7 5.3H14.8V3.2C14.8 2.54 14.26 2 13.6 2H6.7C2.5 2 2.5 4.4 2.5 6V10.5C2.5 13.04 4.96 15.5 7.5 15.5H12" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                    <path d="M7.5 10.5C7.5 7.6 9.8 5.3 12.7 5.3H14.8V7.4C14.8 8.06 15.34 8.6 16 8.6H18.1V10.5C18.1 13.4 15.8 15.7 12.9 15.7H7.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                    <path d="M5.5 22H14.5C15.33 22 16 21.33 16 20.5V19.5C16 18.67 15.33 18 14.5 18H5.5C4.67 18 4 18.67 4 19.5V20.5C4 21.33 4.67 22 5.5 22Z" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
            </div>
            <div class="stat-info">
                <div class="stat-title">Best Place</div>
                <div class="stat-value"><?php echo $best_place < 9999 ? esc_html($best_place) : 'N/A'; ?></div>
            </div>
        </div>
    </div>
    
    <!-- Блок с торговой статистикой -->
    <div class="trader-trading-stats">
        <div class="trading-stat-block">
            <div class="trading-stat-title">Trades</div>
            <div class="trading-stat-value"><?php echo esc_html($total_trades); ?></div>
        </div>
        
        <div class="trading-stat-block">
            <div class="trading-stat-title">Wvh rate</div>
            <div class="trading-stat-value"><?php echo esc_html($win_rate); ?> %</div>
            <div class="trading-stat-graph">
                <svg width="120" height="30" viewBox="0 0 120 30" class="win-rate-graph">
                    <path d="M0,25 C10,20 20,22 30,15 C40,10 50,18 60,12 C70,8 80,5 90,10 C100,15 110,5 120,0" stroke="#6EBCB3" fill="none" stroke-width="2"/>
                </svg>
            </div>
        </div>
        
        <div class="trading-stat-block">
            <div class="trading-stat-title">Profit factor</div>
            <div class="trading-stat-value"><?php echo esc_html($profit_factor); ?></div>
            <div class="trading-stat-graph">
                <svg width="120" height="30" viewBox="0 0 120 30" class="profit-factor-graph">
                    <path d="M0,25 C10,28 20,18 30,15 C40,12 50,10 60,5 C70,10 80,12 90,18 C100,22 110,25 120,20" stroke="#647DB0" fill="none" stroke-width="2"/>
                    <path d="M0,25 C10,28 20,18 30,15 C40,12 50,10 60,5 C70,10 80,12 90,18 C100,22 110,25 120,20" fill="url(#profitGradient)" fill-opacity="0.2" />
                    <defs>
                        <linearGradient id="profitGradient" x1="0%" y1="0%" x2="0%" y2="100%">
                            <stop offset="0%" stop-color="#647DB0" stop-opacity="0.5"/>
                            <stop offset="100%" stop-color="#647DB0" stop-opacity="0"/>
                        </linearGradient>
                    </defs>
                </svg>
            </div>
        </div>
        
        <div class="trading-stat-block">
            <div class="trading-stat-title">Avg trade</div>
            <div class="trading-stat-value"><?php echo esc_html($avg_duration); ?></div>
        </div>
    </div>
    
    <!-- Блок со сделками по валютным парам -->
    <div class="trader-trades-by-pair">
        <h2>Trades by Pair</h2>
        
        <div class="trades-pairs-list">
            <?php 
            // Расчет максимальной ширины для нормализации баров
            $max_count = !empty($favorite_instruments) ? max($favorite_instruments) : 0;
            
            // Вывод торговых пар с количеством сделок и барами
            $pair_colors = ['#6EBCB3']; // Основной цвет для всех баров
            $i = 0;
            
            foreach ($favorite_instruments as $symbol => $count):
                $percent = $max_count > 0 ? ($count / $max_count) * 100 : 0;
            ?>
            <div class="pair-row">
                <div class="pair-name"><?php echo esc_html($symbol); ?></div>
                <div class="pair-bar-container">
                    <div class="pair-bar" style="width: <?php echo esc_attr($percent); ?>%; background-color: <?php echo esc_attr($pair_colors[0]); ?>"></div>
                </div>
                <div class="pair-count"><?php echo esc_html($count); ?></div>
            </div>
            <?php 
                $i++;
                if ($i >= 6) break; // Ограничиваем количество отображаемых пар до 6
            endforeach; 
            ?>
        </div>
    </div>
    
    <!-- Блок с историей конкурсов -->
    <div class="trader-contest-history">
        <h2>Contest history</h2>
        
        <?php if (empty($trader_contests)): ?>
            <p class="no-contests-message">Трейдер еще не участвовал в конкурсах.</p>
        <?php else: ?>
            <div class="contest-history-table-wrapper">
                <table class="contest-history-table">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Symbol</th>
                            <th>Start Date</th>
                            <th>End Date</th>
                            <th>Position</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($trader_contests as $contest): 
                            // Получаем данные о конкурсе
                            $contest_data = get_post_meta($contest->contest_id, '_fttradingapi_contest_data', true);
                            $start_date = isset($contest_data['date_start']) ? date_i18n('M j, Y', strtotime($contest_data['date_start'])) : 'N/A';
                            $end_date = isset($contest_data['date_end']) ? date_i18n('M j, Y', strtotime($contest_data['date_end'])) : 'N/A';
                            
                            // Определяем статус участия
                            $status_text = 'Unknown';
                            $status_class = 'status-unknown';
                            
                            if (isset($contest->connection_status)) {
                                if ($contest->connection_status === 'disqualified') {
                                    $status_text = 'Unknown';
                                    $status_class = 'status-unknown';
                                } elseif ($contest->connection_status === 'quitted') {
                                    $status_text = 'Unknown';
                                    $status_class = 'status-unknown';
                                } elseif ($contest->connection_status === 'active') {
                                    $status_text = 'In progress';
                                    $status_class = 'status-in-progress';
                                } elseif ($contest->connection_status === 'finished') {
                                    $status_text = 'Unknown';
                                    $status_class = 'status-unknown';
                                }
                            }
                            
                            // Получаем символ основной торговой пары конкурса (или берем первую из истории сделок)
                            $contest_symbol = 'EUR/USD'; // По умолчанию EUR/USD
                            if (!empty($instruments_count)) {
                                $contest_symbol = array_key_first($instruments_count);
                            }
                        ?>
                        <tr>
                            <td>
                                <a href="<?php echo esc_url(get_permalink($contest->contest_id)); ?>" class="contest-link">
                                    <?php echo esc_html($contest->contest_name); ?>
                                </a>
                            </td>
                            <td><?php echo esc_html($contest_symbol); ?></td>
                            <td><?php echo esc_html($start_date); ?></td>
                            <td><?php echo esc_html($end_date); ?></td>
                            <td><?php echo isset($contest->place) && $contest->place > 0 ? esc_html($contest->place) . ' %' : 'N/A'; ?></td>
                            <td class="<?php echo esc_attr($status_class); ?>"><?php echo esc_html($status_text); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php
get_footer(); 