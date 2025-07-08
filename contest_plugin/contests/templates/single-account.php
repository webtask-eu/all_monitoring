<?php
/**
 * Шаблон для отображения информации о счете участника конкурса
 */

/**
 * Получает название месяца на русском языке по его номеру
 *
 * @param int $month_number Номер месяца (1-12)
 * @param bool $short Использовать сокращенный формат для месяцев длиннее 3 символов
 * @return string Название месяца в родительном падеже
 */
function get_month_name($month_number, $short = true) {
    $months_full = [
        1 => 'Января',
        2 => 'Февраля',
        3 => 'Марта',
        4 => 'Апреля',
        5 => 'Мая',
        6 => 'Июня',
        7 => 'Июля',
        8 => 'Августа',
        9 => 'Сентября',
        10 => 'Октября',
        11 => 'Ноября',
        12 => 'Декабря'
    ];
    
    $months_short = [
        1 => 'Янв.',
        2 => 'Фев.',
        3 => 'Мар.',
        4 => 'Апр.',
        5 => 'Мая',
        6 => 'Июн.',
        7 => 'Июл.',
        8 => 'Авг.',
        9 => 'Сен.',
        10 => 'Окт.',
        11 => 'Ноя.',
        12 => 'Дек.'
    ];
    
    if (!isset($months_full[$month_number])) {
        return '';
    }
    
    // Если нужен сокращенный формат и длина названия месяца больше 3 символов
    if ($short && mb_strlen($months_full[$month_number]) > 3 && $month_number != 5) { // Май оставляем как есть
        return $months_short[$month_number];
    }
    
    return $months_full[$month_number];
}

get_header();

// Проверяем наличие параметров
if (!isset($_GET['contest_account']) || !isset($_GET['contest_id'])) {
    wp_redirect(home_url());
    exit;
}

$account_id = intval($_GET['contest_account']);
$contest_id = intval($_GET['contest_id']);

// Получаем информацию о счете
global $wpdb;
$table_name = $wpdb->prefix . 'contest_members';
$account = $wpdb->get_row($wpdb->prepare(
    "SELECT * FROM $table_name WHERE id = %d AND contest_id = %d",
    $account_id,
    $contest_id
));

// Если счет не найден, перенаправляем на страницу конкурса
if (!$account) {
    wp_redirect(get_permalink($contest_id));
    exit;
}

// Включаем и инициализируем класс для расчета торговых метрик
require_once plugin_dir_path(dirname(__FILE__)) . 'includes/class-account-trading-metrics.php';
$metrics_calculator = new Account_Trading_Metrics();
$profit_factor = $metrics_calculator->calculate_profit_factor($account_id);
$metrics = $metrics_calculator->calculate_metrics($account_id);
$win_rate = $metrics['win_rate'];

// Получаем информацию о конкурсе
$contest = get_post($contest_id);
if (!$contest || $contest->post_type !== 'trader_contests') {
    wp_redirect(home_url());
    exit;
}

// Получаем информацию о пользователе счета
$account_user = get_userdata($account->user_id);

// Получаем открытые ордера
$orders_table = $wpdb->prefix . 'contest_members_orders';
$open_orders = $wpdb->get_results($wpdb->prepare(
    "SELECT * FROM $orders_table WHERE account_id = %d ORDER BY open_time DESC",
    $account_id
));

// Получаем историю сделок с пагинацией
$history_table = $wpdb->prefix . 'contest_members_order_history';
$per_page = 20;
$current_page = isset($_GET['history_page']) ? max(1, intval($_GET['history_page'])) : 1;
$offset = ($current_page - 1) * $per_page;

$total_history = $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM $history_table WHERE account_id = %d",
    $account_id
));

$order_history = $wpdb->get_results($wpdb->prepare(
    "SELECT * FROM $history_table 
     WHERE account_id = %d 
     ORDER BY close_time DESC 
     LIMIT %d OFFSET %d",
    $account_id,
    $per_page,
    $offset
));

$total_pages = ceil($total_history / $per_page);

// Получаем первую сделку из истории (закрытые сделки)
$first_closed_trade = $wpdb->get_row($wpdb->prepare(
    "SELECT open_time FROM {$wpdb->prefix}contest_members_order_history 
     WHERE account_id = %d 
     AND type NOT IN ('balance', 'credit', 'deposit', 'withdrawal')
     AND type NOT LIKE %s
     AND type NOT LIKE %s
     AND type NOT LIKE %s
     ORDER BY open_time ASC 
     LIMIT 1",
    $account_id,
    '%limit%',
    '%stop%',
    '%pending%'
));

// Получаем первую сделку из открытых ордеров
$first_open_trade = $wpdb->get_row($wpdb->prepare(
    "SELECT open_time FROM {$wpdb->prefix}contest_members_orders 
     WHERE account_id = %d 
     AND type NOT IN ('balance', 'credit', 'deposit', 'withdrawal')
     AND type NOT LIKE %s
     AND type NOT LIKE %s
     AND type NOT LIKE %s
     ORDER BY open_time ASC 
     LIMIT 1",
    $account_id,
    '%limit%',
    '%stop%',
    '%pending%'
));

// Определяем самую раннюю сделку среди закрытых и открытых
$first_trade = null;
if ($first_closed_trade && $first_open_trade) {
    // Если есть и те, и другие - выбираем более раннюю
    if (strtotime($first_closed_trade->open_time) <= strtotime($first_open_trade->open_time)) {
        $first_trade = $first_closed_trade;
    } else {
        $first_trade = $first_open_trade;
    }
} elseif ($first_closed_trade) {
    // Если есть только закрытые
    $first_trade = $first_closed_trade;
} elseif ($first_open_trade) {
    // Если есть только открытые
    $first_trade = $first_open_trade;
}

// Находим начальный депозит
$initial_deposit = 10000; // Значение по умолчанию

// Сначала ищем в истории сделок
$deposit_record = $wpdb->get_row($wpdb->prepare(
    "SELECT * FROM {$wpdb->prefix}contest_members_order_history 
     WHERE account_id = %d AND type = %s
     ORDER BY open_time ASC
     LIMIT 1",
    $account_id,
    'balance'
));

if ($deposit_record && $deposit_record->profit > 0) {
    $initial_deposit = $deposit_record->profit;
    error_log("[ACCOUNT-PAGE] Используем начальный депозит из истории: {$initial_deposit}");
} else {
    // Если в истории не найдено, используем настройки конкурса
    $contest_data = get_post_meta($contest_id, '_fttradingapi_contest_data', true);
    if (is_array($contest_data) && isset($contest_data['start_deposit'])) {
        $initial_deposit = floatval($contest_data['start_deposit']);
        error_log("[ACCOUNT-PAGE] Запись в истории не найдена. Используем начальный депозит из настроек конкурса: {$initial_deposit}");
    } else {
        error_log("[ACCOUNT-PAGE] Ни записи в истории, ни настроек конкурса не найдено. Используем начальный депозит по умолчанию: {$initial_deposit}");
    }
}

// Вычисляем реальную прибыль (текущий баланс - начальный депозит)
$real_profit = $account->balance - $initial_deposit;

// Форматируем время последнего обновления
$last_update_time = strtotime($account->last_update);
$current_time = current_time('timestamp');
$minutes_ago = round(($current_time - $last_update_time) / 60);

// Определяем класс на основе времени
if ($minutes_ago < 30) {
    $time_class = 'recent';
} else if ($minutes_ago < 120) {
    $time_class = 'moderate';
} else {
    $time_class = 'stale';
}

// Форматируем вывод времени
if ($minutes_ago < 1) {
    $time_text = 'только что';
} else if ($minutes_ago < 60) {
    $time_text = $minutes_ago . ' мин. назад';
} else if ($minutes_ago < 1440) {
    $hours = floor($minutes_ago / 60);
    $remaining_minutes = $minutes_ago % 60;
    $time_text = $hours . ' ч. ' . $remaining_minutes . ' мин. назад';
} else {
    $days = floor($minutes_ago / 1440);
    $time_text = $days . ' д. назад';
}

?>
<style>
/* Стили для подсказки */
.tooltip-icon {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 18px;
    height: 18px;
    background-color: #e0e0e0;
    color: #333;
    border-radius: 50%;
    font-size: 12px;
    margin-left: 5px;
    cursor: help;
    position: relative;
    font-weight: bold;
}

.tooltip-icon:hover::after {
    content: attr(data-tooltip);
    position: absolute;
    bottom: 100%;
    left: 50%;
    transform: translateX(-50%);
    background-color: rgba(0, 0, 0, 0.8);
    color: #fff;
    padding: 10px 15px;
    border-radius: 6px;
    width: 300px;
    z-index: 10;
    font-weight: normal;
    font-size: 13px;
    line-height: 1.4;
    box-shadow: 0 4px 10px rgba(0, 0, 0, 0.2);
    margin-bottom: 10px;
    text-align: left;
}

.tooltip-icon:hover::before {
    content: '';
    position: absolute;
    bottom: 100%;
    left: 50%;
    transform: translateX(-50%);
    border-width: 6px;
    border-style: solid;
    border-color: transparent transparent rgba(0, 0, 0, 0.8) transparent;
    z-index: 11;
    margin-bottom: 4px;
}

/* Стили для процентного значения */
.profit-percent {
    font-size: 16px;
    margin-top: 5px;
    font-weight: 600;
}

.profit-positive {
    color: #34a853;
}

.profit-negative {
    color: #ea4335;
}

/* Дополнительные стили для улучшения отображения */
.financial-item {
    position: relative;
    overflow: visible;
}

.financial-value {
    font-weight: bold;
    font-size: 20px;
}

/* Стили для сообщения о дисквалификации */
.disqualification-message {
    margin-top: 10px;
    background-color: #fff8f8;
    border: 1px solid #ffecec;
    padding: 10px 15px;
    border-radius: 4px;
}

.disqualification-title {
    color: #d83b3b;
    font-weight: bold;
    margin-bottom: 8px;
}

.disqualification-reasons ul {
    margin: 5px 0 5px 20px;
    padding: 0;
}

.disqualification-reasons li {
    margin-bottom: 5px;
    color: #333;
}

#disqualification-status {
    max-width: 100%;
    overflow-wrap: break-word;
}

.account-error-description {
    white-space: pre-wrap;
    line-height: 1.5;
    padding: 10px;
}
</style>

<div class="account-single-container">

    <!-- Добавляем хлебные крошки -->
    <div class="clearfix">
        <div class="breadcrumbs alignleft">
            <ul class="breadcrumb clearfix" itemscope="" itemtype="http://schema.org/BreadcrumbList">
                <li>
                    <span itemtype="http://schema.org/ListItem" itemscope="" itemprop="itemListElement">
                        <a href="/" itemprop="item">
                            <span itemprop="name">Главная</span>
                            <meta content="0" itemprop="position">
                        </a>
                    </span>
                </li>
                <li>
                    <span itemtype="http://schema.org/ListItem" itemscope="" itemprop="itemListElement">
                        <a itemprop="item" title="Конкурсы" href="/trader-contests">
                            <span itemprop="name">Конкурсы</span>
                        </a>
                        <meta content="1" itemprop="position">
                    </span>
                </li>
                <li>
                    <span itemtype="http://schema.org/ListItem" itemscope="" itemprop="itemListElement">
                        <a itemprop="item" href="<?php echo get_permalink($contest_id); ?>">
                            <span itemprop="name"><?php echo esc_html($contest->post_title); ?></span>
                        </a>
                        <meta content="2" itemprop="position">
                    </span>
                </li>
                <li>
                    <span itemtype="http://schema.org/ListItem" itemscope="" itemprop="itemListElement">
                        <span class="breadcrumb-active" itemprop="name">Счет
                            #<?php echo esc_html($account->account_number); ?></span>
                        <meta content="3" itemprop="position">
                    </span>
                </li>
            </ul>
        </div>
    </div>

    <section class="section_offset">
        <div class="fx_content-title16 flex_title clearfix">
            <span class="fx_content-title-link">Счет #<?php echo esc_html($account->account_number); ?> </span>
        </div>
    </section>


    <header class="account-header">

        <div class="account-meta">
            <span class="account-owner">Трейдер: 
                <?php 
                // Используем приоритетный подход для определения имени
                $name_to_display = '';
                
                // 1. Проверяем user_nicename пользователя
                if ($account_user && !empty($account_user->user_nicename)) {
                    $name_to_display = html_entity_decode($account_user->user_nicename, ENT_QUOTES, 'UTF-8');
                }
                // 2. Проверяем user_login пользователя
                else if ($account_user && !empty($account_user->user_login)) {
                    $name_to_display = html_entity_decode($account_user->user_login, ENT_QUOTES, 'UTF-8');
                }
                // 3. Проверяем поле name в объекте счета
                else if (!empty($account->name)) {
                    $name_to_display = html_entity_decode($account->name, ENT_QUOTES, 'UTF-8');
                }
                // 4. Проверяем account_login
                else if (!empty($account->account_login)) {
                    $name_to_display = html_entity_decode($account->account_login, ENT_QUOTES, 'UTF-8');
                }
                // 5. Используем значение по умолчанию
                else {
                    $name_to_display = 'Участник';
                }
                
                echo $name_to_display;
                ?>
            </span>

            <?php if ($account->connection_status === 'connected'): ?>
                <span class="account-status connected">
                    <span class="status-indicator status-connected"></span>Подключен
                </span>
            <?php elseif ($account->connection_status === 'disqualified'): ?>
                <span class="account-status disqualified">
                    <span class="status-indicator status-disqualified"></span>Дисквалифицирован
                </span>
            <?php else: ?>
                <span class="account-status disconnected">
                    <span class="status-indicator status-disconnected"></span>Ошибка подключения
                </span>
            <?php endif; ?>

            <span class="account-updated <?php echo esc_attr($time_class); ?>">
                Обновлено: <?php echo esc_html($time_text); ?>
            </span>
        </div>
    </header>

    <?php if ($account->connection_status !== 'connected' && !empty($account->error_description)): ?>
    <div class="account-error-block <?php echo $account->connection_status === 'disqualified' ? 'disqualification-block' : ''; ?>">
        <div class="account-error-title">
            <?php if ($account->connection_status === 'disqualified'): ?>
                Причина дисквалификации:
            <?php else: ?>
                Информация об ошибке:
            <?php endif; ?>
        </div>
        <div class="account-error-description"><?php echo esc_html($account->error_description); ?></div>
    </div>
    <?php endif; ?>

    <div class="account-details">
        <div class="account-main-content">
            <section class="account-section">
                <h2>Финансовые показатели</h2>
                <div class="financial-grid">
                    <div class="financial-item">
                        <div class="financial-label">
                            Чистая прибыль 
                            <span class="tooltip-icon" data-tooltip="Чистая прибыль (Net Profit) - это разница между текущим балансом и начальным депозитом. Формула расчета: Net Profit = Текущий баланс (<?php echo number_format($account->balance, 2); ?> <?php echo esc_html($account->currency); ?>) - Начальный депозит (<?php echo number_format($initial_deposit, 2); ?> <?php echo esc_html($account->currency); ?>)">?</span>
                        </div>
                        <div class="financial-value <?php echo $real_profit >= 0 ? 'profit-positive' : 'profit-negative'; ?>">
                            <?php echo number_format($real_profit, 2); ?>
                            <?php echo esc_html($account->currency); ?>
                        </div>
                        <div class="profit-percent <?php echo $real_profit >= 0 ? 'profit-positive' : 'profit-negative'; ?>">
                            <?php 
                                // Расчет процента прибыли
                                $profit_percent = ($initial_deposit > 0) ? ($real_profit / $initial_deposit) * 100 : 0;
                                echo ($profit_percent > 0 ? '+' : '') . number_format($profit_percent, 1) . '%';
                            ?>
                        </div>
                    </div>

                    <div class="financial-item">
                        <div class="financial-label">
                            Средства
                            <?php
                            // Средства (Equity) = Баланс + Прибыль по открытым сделкам
                            
                            // Сначала посчитаем сумму прибыли по открытым сделкам
                            $open_positions_profit = 0;
                            if (!empty($open_orders)) {
                                foreach ($open_orders as $order) {
                                    $open_positions_profit += $order->profit;
                                }
                            }
                            
                            // Упрощенный тултип
                            $equity_tooltip = "Средства (Equity) - это сумма баланса счета и текущей прибыли по всем открытым позициям.\n\n";
                            $equity_tooltip .= "Формула расчета:\n";
                            $equity_tooltip .= number_format($account->balance, 2) . " " . $account->currency . " (баланс) + ";
                            $equity_tooltip .= number_format($open_positions_profit, 2) . " " . $account->currency . " (сумма открытых позиций) = ";
                            $equity_tooltip .= number_format($account->equity, 2) . " " . $account->currency;
                            ?>
                            <span class="tooltip-icon" data-tooltip="<?php echo esc_attr($equity_tooltip); ?>">?</span>
                        </div>
                        <div class="financial-value <?php echo ($account->equity > $initial_deposit) ? 'profit-positive' : 'profit-negative'; ?>">
                            <?php echo number_format($account->equity, 2); ?>
                            <?php echo esc_html($account->currency); ?>
                        </div>
                        <div class="profit-percent <?php echo ($account->equity > $initial_deposit) ? 'profit-positive' : 'profit-negative'; ?>">
                            <?php 
                                // Расчет процента средств от начального депозита
                                $equity_percent = ($initial_deposit > 0) ? (($account->equity - $initial_deposit) / $initial_deposit) * 100 : 0;
                                echo ($equity_percent > 0 ? '+' : '') . number_format($equity_percent, 1) . '%';
                            ?>
                        </div>
                    </div>

                    <div class="financial-item" id="drawdown-container">
                        <div class="financial-label">
                            Просадка
                            <span class="tooltip-icon" id="drawdown-tooltip" data-tooltip="Просадка (Drawdown) - это снижение от исторического максимума средств счета.">?</span>
                        </div>
                        <div class="financial-value" id="drawdown-value">
                            <span class="loading-indicator">...</span>
                        </div>
                        <div class="profit-percent" id="drawdown-percent">
                            <span class="loading-indicator">...</span>
                        </div>
                    </div>

                    <div class="financial-item">
                        <div class="financial-label">
                            Профит фактор
                            <span class="tooltip-icon" data-tooltip="Профит фактор (Profit Factor) - это отношение прибыли к убыткам. Рассчитывается как сумма всех прибыльных сделок, деленная на абсолютное значение суммы всех убыточных сделок. Значение больше 1 означает прибыльную торговлю.">?</span>
                        </div>
                        <div class="financial-value <?php echo $profit_factor > 1 ? 'profit-positive' : 'profit-negative'; ?>">
                            <?php 
                                // V2024.05.07 - Обновлен способ отображения значения бесконечности
                                if ($profit_factor === 'inf') {
                                    echo '∞';
                                } else {
                                    echo number_format($profit_factor, 2);
                                }
                            ?>
                        </div>
                    </div>

                    <div class="financial-item">
                        <div class="financial-label">
                            Процент побед (%)
                            <span class="tooltip-icon" data-tooltip="Процент побед (Win Rate) - отношение количества прибыльных сделок к общему количеству сделок, выраженное в процентах. Формула расчета: (<?php echo $metrics['winning_trades']; ?> прибыльных сделок / <?php echo $metrics['total_trades']; ?> всего сделок) × 100% = <?php echo number_format($win_rate, 2); ?>%">?</span>
                        </div>
                        <div class="financial-value <?php echo $win_rate >= 50 ? 'profit-positive' : 'profit-negative'; ?>">
                            <?php echo number_format($win_rate, 2); ?>%
                        </div>
                    </div>

                    <div class="financial-item">
                        <div class="financial-label">
                            Кол-во сделок
                            <span class="tooltip-icon" data-tooltip="Общее количество совершенных сделок, включая прибыльные (<?php echo $metrics['winning_trades']; ?>) и убыточные (<?php echo $metrics['losing_trades']; ?>).">?</span>
                        </div>
                        <div class="financial-value"><?php echo intval($metrics['total_trades']); ?></div>
                    </div>

                    <div class="financial-item">
                        <div class="financial-label">
                            Риск/прибыль
                            <span class="tooltip-icon" data-tooltip="Риск/прибыль (Risk/Reward Ratio) - показатель, отражающий отношение средней прибыли по выигрышным сделкам к среднему убытку по проигрышным.

Формула расчета:
<?php echo number_format($metrics['avg_profit'], 2); ?> <?php echo esc_html($account->currency); ?> (средняя прибыль) / 
<?php echo number_format($metrics['avg_loss'], 2); ?> <?php echo esc_html($account->currency); ?> (средний убыток) = 
<?php echo $metrics['risk_reward_ratio'] === 'inf' ? '∞' : number_format($metrics['risk_reward_ratio'], 2); ?>

Значение больше 1.5 означает эффективное управление капиталом. Чем выше показатель, тем лучше соотношение прибыли и риска.

Интерпретация значения <?php echo $metrics['risk_reward_ratio'] === 'inf' ? '∞' : number_format($metrics['risk_reward_ratio'], 2); ?>:
• Меньше 1.0: Средний убыток превышает среднюю прибыль, что указывает на неэффективное управление рисками
• 1.0-1.5: Приемлемое соотношение, но требует улучшения
• Больше 1.5: Хорошее соотношение, эффективное управление капиталом">?</span>
                        </div>
                        <div class="financial-value <?php echo $metrics['risk_reward_ratio'] >= 1.5 ? 'profit-positive' : 'profit-negative'; ?>">
                            <?php 
                                // V2024.05.07 - Добавлена обработка бесконечности
                                if ($metrics['risk_reward_ratio'] === 'inf') {
                                    echo '∞';
                                } else {
                                    echo number_format($metrics['risk_reward_ratio'], 2);
                                }
                            ?>
                        </div>
                    </div>

                    <div class="financial-item">
                        <div class="financial-label">
                            Время сделки
                            <span class="tooltip-icon" data-tooltip="Средняя продолжительность сделки. Позволяет понять стиль торговли трейдера - скальпинг (короткие сделки), среднесрочная или долгосрочная торговля.">?</span>
                        </div>
                        <div class="financial-value">
                            <?php echo $metrics['avg_trade_duration']; ?>
                        </div>
                    </div>

                    <!-- Новый блок для мини-графика Equity -->
                    <div class="financial-item equity-curve-item">
                        <div class="financial-label">
                            График средств
                            <span class="tooltip-icon" data-tooltip="График движения средств (Equity)">?</span>
                        </div>
                        <div class="financial-value equity-curve-container">
                            <svg id="equityCurveChart" data-account-id="<?php echo $account_id; ?>" width="100%" height="30"></svg>
                        </div>
                    </div>
                </div>
            </section>

            <!-- После блока с финансовыми показателями -->
            <!-- Блок с графиком счета -->
            <div class="account-section">
                <h2>График баланса и средств</h2>

                <div class="chart-controls">
                    <div class="chart-period-control">
                        <label for="chart_period">Период:</label>
                        <select id="chart_period">
                            <option value="day">День</option>
                            <option value="week">Неделя</option>
                            <option value="month">Месяц</option>
                            <option value="year">Год</option>
                            <option value="all" selected>Все время</option>
                        </select>
                    </div>
                    <div id="chartLegend" class="chart-legend"></div>
                </div>

                <!-- Изменить структуру контейнеров, чтобы соответствовать single-contest.php -->
                <div class="chart-scroll-container">
                    <div class="chart-container">
                        <canvas id="accountChart" data-account-id="<?php echo $account_id; ?>"></canvas>
                        <div id="chart-loading" class="chart-loading">Загрузка данных...</div>
                    </div>
                </div>
                
                <!-- Кнопка для отладки расчета просадки v2 -->
                <div style="margin-top: 10px; display: none;">
                    <button id="calculate-drawdown-manually" class="button button-small">Рассчитать просадку (отладка)</button>
                    <span id="drawdown-debug-info" style="margin-left: 10px; font-size: 12px;"></span>
                </div>

                <!-- Добавляем новую секцию для лога значений графика -->
                <div class="chart-data-log-section" style="display: none;">
                    <h3>Лог значений графика</h3>
                    <button id="toggleChartDataLog" class="button button-small">Показать/скрыть данные</button>
                    <div id="chartDataLog" class="chart-data-log" style="display: none; max-height: 300px; overflow-y: auto; margin-top: 10px; font-size: 12px; border: 1px solid #ddd; padding: 10px;"></div>
                </div>
            </div>

            <section class="account-section symbol-statistics">
                <h2>Статистика по инструментам</h2>
                
                <?php
                // Получаем статистику по символам
                $symbols_stats = $metrics_calculator->calculate_symbols_statistics($account_id);
                
                if (!empty($symbols_stats)):
                ?>
                <div class="symbols-controls">
                    <button id="expandAllSymbols" class="button button-small">Развернуть все</button>
                    <button id="collapseAllSymbols" class="button button-small">Свернуть все</button>
                    <input type="text" id="symbolFilter" placeholder="Фильтр по символу..." class="symbol-filter">
                </div>
                
                <div class="symbols-table-container">
                    <table class="symbols-table" id="symbolsTable">
                        <thead>
                            <tr>
                                <th class="sortable" data-sort="symbol">Символ</th>
                                <th class="sortable" data-sort="trades">Сделок</th>
                                <th class="sortable" data-sort="volume">Объем</th>
                                <th class="sortable" data-sort="winrate">% успеха</th>
                                <th class="sortable" data-sort="pf">Профит фактор</th>
                                <th class="sortable" data-sort="profit">Результат</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($symbols_stats as $symbol): ?>
                            <tr class="symbol-row" data-symbol="<?php echo esc_attr($symbol['symbol']); ?>">
                                <td class="symbol-name">
                                    <span class="expand-icon">►</span>
                                    <?php echo esc_html($symbol['symbol']); ?>
                                </td>
                                <td><?php echo esc_html($symbol['total_trades']); ?></td>
                                <td><?php echo number_format($symbol['total_volume'], 2); ?></td>
                                <td><?php echo number_format($symbol['win_rate'], 2); ?>%</td>
                                <td class="<?php echo $symbol['profit_factor'] > 1 ? 'profit-positive' : 'profit-negative'; ?>">
                                    <?php 
                                        // V2024.05.07 - Обновлена проверка на бесконечность
                                        if ($symbol['profit_factor'] === 'inf') {
                                            echo '∞';
                                        } else {
                                            echo number_format($symbol['profit_factor'], 2); 
                                        }
                                    ?>
                                </td>
                                <td class="<?php echo $symbol['total_profit'] >= 0 ? 'profit-positive' : 'profit-negative'; ?>">
                                    <?php echo number_format($symbol['total_profit'], 2); ?>
                                </td>
                            </tr>
                            <tr class="direction-row buy-row" data-parent="<?php echo esc_attr($symbol['symbol']); ?>" style="display: none;">
                                <td class="direction-name">
                                    <span class="direction-indent"></span>
                                    <span class="direction-icon">►</span> BUY
                                </td>
                                <td><?php echo esc_html($symbol['buy']['total_trades']); ?></td>
                                <td><?php echo number_format($symbol['buy']['total_volume'], 2); ?></td>
                                <td><?php echo number_format($symbol['buy']['win_rate'], 2); ?>%</td>
                                <td><?php 
                                    // V2024.05.07 - Обновлена проверка на бесконечность
                                    if ($symbol['buy']['profit_factor'] === 'inf') {
                                        echo '∞';
                                    } elseif ($symbol['buy']['profit_factor']) {
                                        echo number_format($symbol['buy']['profit_factor'], 2);
                                    } else {
                                        echo '—';
                                    }
                                ?></td>
                                <td class="<?php echo $symbol['buy']['total_profit'] >= 0 ? 'profit-positive' : 'profit-negative'; ?>">
                                    <?php echo number_format($symbol['buy']['total_profit'], 2); ?>
                                </td>
                            </tr>
                            <tr class="trades-row positive-trades-row" data-direction="buy" data-parent="<?php echo esc_attr($symbol['symbol']); ?>" style="display: none;">
                                <td class="trades-name">
                                    <span class="trades-indent"></span>
                                    <span class="trades-indent"></span>
                                    В плюсе
                                </td>
                                <td><?php echo esc_html($symbol['buy']['positive']['total_trades']); ?></td>
                                <td><?php echo number_format($symbol['buy']['positive']['total_volume'], 2); ?></td>
                                <td>100%</td>
                                <td>—</td>
                                <td class="profit-positive">
                                    <?php echo number_format($symbol['buy']['positive']['total_profit'], 2); ?>
                                </td>
                            </tr>
                            <tr class="trades-row negative-trades-row" data-direction="buy" data-parent="<?php echo esc_attr($symbol['symbol']); ?>" style="display: none;">
                                <td class="trades-name">
                                    <span class="trades-indent"></span>
                                    <span class="trades-indent"></span>
                                    В минусе
                                </td>
                                <td><?php echo esc_html($symbol['buy']['negative']['total_trades']); ?></td>
                                <td><?php echo number_format($symbol['buy']['negative']['total_volume'], 2); ?></td>
                                <td>0%</td>
                                <td>—</td>
                                <td class="profit-negative">
                                    <?php echo number_format($symbol['buy']['negative']['total_profit'], 2); ?>
                                </td>
                            </tr>
                            <tr class="direction-row sell-row" data-parent="<?php echo esc_attr($symbol['symbol']); ?>" style="display: none;">
                                <td class="direction-name">
                                    <span class="direction-indent"></span>
                                    <span class="direction-icon">►</span> SELL
                                </td>
                                <td><?php echo esc_html($symbol['sell']['total_trades']); ?></td>
                                <td><?php echo number_format($symbol['sell']['total_volume'], 2); ?></td>
                                <td><?php echo number_format($symbol['sell']['win_rate'], 2); ?>%</td>
                                <td><?php 
                                    // V2024.05.07 - Обновлена проверка на бесконечность
                                    if ($symbol['sell']['profit_factor'] === 'inf') {
                                        echo '∞';
                                    } elseif ($symbol['sell']['profit_factor']) {
                                        echo number_format($symbol['sell']['profit_factor'], 2);
                                    } else {
                                        echo '—';
                                    }
                                ?></td>
                                <td class="<?php echo $symbol['sell']['total_profit'] >= 0 ? 'profit-positive' : 'profit-negative'; ?>">
                                    <?php echo number_format($symbol['sell']['total_profit'], 2); ?>
                                </td>
                            </tr>
                            <tr class="trades-row positive-trades-row" data-direction="sell" data-parent="<?php echo esc_attr($symbol['symbol']); ?>" style="display: none;">
                                <td class="trades-name">
                                    <span class="trades-indent"></span>
                                    <span class="trades-indent"></span>
                                    В плюсе
                                </td>
                                <td><?php echo esc_html($symbol['sell']['positive']['total_trades']); ?></td>
                                <td><?php echo number_format($symbol['sell']['positive']['total_volume'], 2); ?></td>
                                <td>100%</td>
                                <td>—</td>
                                <td class="profit-positive">
                                    <?php echo number_format($symbol['sell']['positive']['total_profit'], 2); ?>
                                </td>
                            </tr>
                            <tr class="trades-row negative-trades-row" data-direction="sell" data-parent="<?php echo esc_attr($symbol['symbol']); ?>" style="display: none;">
                                <td class="trades-name">
                                    <span class="trades-indent"></span>
                                    <span class="trades-indent"></span>
                                    В минусе
                                </td>
                                <td><?php echo esc_html($symbol['sell']['negative']['total_trades']); ?></td>
                                <td><?php echo number_format($symbol['sell']['negative']['total_volume'], 2); ?></td>
                                <td>0%</td>
                                <td>—</td>
                                <td class="profit-negative">
                                    <?php echo number_format($symbol['sell']['negative']['total_profit'], 2); ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                <p class="no-data">Нет доступной статистики по инструментам.</p>
                <?php endif; ?>
            </section>

            <section class="account-open-orders">
                <h2>Открытые позиции</h2>
                <?php if (!empty($open_orders)): ?>
                    <div class="orders-table-container">
                        <table class="orders-table">
                            <thead>
                                <tr>
                                    <th>Тикет</th>
                                    <th>Символ</th>
                                    <th>Тип</th>
                                    <th>Объем</th>
                                    <th>Время открытия</th>
                                    <th>Цена</th>
                                    <th>S/L</th>
                                    <th>T/P</th>
                                    <th>Прибыль</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($open_orders as $order): ?>
                                    <tr>
                                        <td><?php echo esc_html($order->ticket); ?></td>
                                        <td><?php echo esc_html($order->symbol); ?></td>
                                        <td class="<?php 
                                            $buy_types = ['buy', 'buylimit', 'buystop'];
                                            $sell_types = ['sell', 'selllimit', 'sellstop'];
                                            if (in_array($order->type, $buy_types)) {
                                                echo 'order-buy';
                                            } elseif (in_array($order->type, $sell_types)) {
                                                echo 'order-sell';
                                            } else {
                                                echo 'order-unknown';
                                            }
                                        ?>">
                                            <?php echo esc_html(strtoupper($order->type)); ?>
                                        </td>
                                        <td><?php echo number_format($order->lots, 2); ?></td>
                                        <td title="<?php echo date('d.m.Y H:i:s', strtotime($order->open_time)); ?>">
                                            <?php echo date('j', strtotime($order->open_time)) . ' ' . get_month_name(date('n', strtotime($order->open_time))); ?>
                                        </td>
                                        <td>
                                            <?php 
                                                // Проверяем, есть ли дробная часть
                                                $price = floatval($order->open_price);
                                                // Убираем разделители тысяч, удаляем лишние нули
                                                $formatted_price = (floor($price) == $price) ? 
                                                    number_format($price, 0, '.', '') : 
                                                    rtrim(rtrim(number_format($price, 5, '.', ''), '0'), '.');
                                                echo $formatted_price;
                                            ?>
                                        </td>
                                        <td>
                                            <?php 
                                                if ($order->sl && $order->sl != 0) {
                                                    $sl = floatval($order->sl);
                                                    // Убираем разделители тысяч, удаляем лишние нули
                                                    $formatted_sl = (floor($sl) == $sl) ? 
                                                        number_format($sl, 0, '.', '') : 
                                                        rtrim(rtrim(number_format($sl, 5, '.', ''), '0'), '.');
                                                    echo $formatted_sl;
                                                } else {
                                                    echo '—';
                                                }
                                            ?>
                                        </td>
                                        <td>
                                            <?php 
                                                if ($order->tp && $order->tp != 0) {
                                                    $tp = floatval($order->tp);
                                                    // Убираем разделители тысяч, удаляем лишние нули
                                                    $formatted_tp = (floor($tp) == $tp) ? 
                                                        number_format($tp, 0, '.', '') : 
                                                        rtrim(rtrim(number_format($tp, 5, '.', ''), '0'), '.');
                                                    echo $formatted_tp;
                                                } else {
                                                    echo '—';
                                                }
                                            ?>
                                        </td>
                                        <td class="<?php echo $order->profit >= 0 ? 'profit-positive' : 'profit-negative'; ?>">
                                            <?php 
                                                $profit = floatval($order->profit);
                                                // Всегда сохраняем до 2 знаков для прибыли, убираем лишние нули и разделители тысяч
                                                $formatted_profit = rtrim(rtrim(number_format($profit, 2, '.', ''), '0'), '.');
                                                // Если после удаления нулей нет десятичной части, добавляем ".0"
                                                if (strpos($formatted_profit, '.') === false && $profit != 0) {
                                                    $formatted_profit .= '.0';
                                                }
                                                echo $formatted_profit;
                                            ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <p class="no-orders">Нет открытых позиций.</p>
                    <?php endif; ?>
                </section>

            <section class="account-order-history">
                <h2>История сделок</h2>

                <?php if (!empty($order_history)): ?>
                    <!-- Пагинация -->
                    <?php if ($total_pages > 1): ?>
                        <div class="pagination">
                            <form method="get" action="">
                                <?php foreach ($_GET as $key => $value): ?>
                                    <?php if ($key !== 'history_page'): ?>
                                        <input type="hidden" name="<?php echo esc_attr($key); ?>"
                                            value="<?php echo esc_attr($value); ?>">
                                    <?php endif; ?>
                                <?php endforeach; ?>

                                <select name="history_page" onchange="this.form.submit()">
                                    <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                                        <option value="<?php echo $i; ?>" <?php selected($current_page, $i); ?>>
                                            Страница <?php echo $i; ?> из <?php echo $total_pages; ?>
                                        </option>
                                    <?php endfor; ?>
                                </select>
                            </form>
                        </div>
                    <?php endif; ?>

                    <div class="orders-table-container">
                        <table class="orders-table">
                            <thead>
                                <tr>
                                    <th>Тикет</th>
                                    <th>Символ</th>
                                    <th>Тип</th>
                                    <th>Объем</th>
                                    <th>Время открытия</th>
                                    <th>Время закрытия</th>
                                    <th>Цена откр.</th>
                                    <th>Цена закр.</th>
                                    <th>Прибыль</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($order_history as $order): ?>
                                    <tr>
                                        <td><?php echo esc_html($order->ticket); ?></td>
                                        <td><?php echo esc_html($order->symbol); ?></td>
                                        <td
                                            class="<?php echo in_array($order->type, ['buy', 'balance']) ? 'order-buy' : 'order-sell'; ?>">
                                            <?php echo esc_html(strtoupper($order->type)); ?>
                                        </td>
                                        <td><?php echo number_format($order->lots, 2); ?></td>
                                        <td title="<?php echo date('d.m.Y H:i:s', strtotime($order->open_time)); ?>">
                                            <?php echo date('j', strtotime($order->open_time)) . ' ' . get_month_name(date('n', strtotime($order->open_time))); ?>
                                        </td>
                                        <td title="<?php echo date('d.m.Y H:i:s', strtotime($order->close_time)); ?>">
                                            <?php echo date('j', strtotime($order->close_time)) . ' ' . get_month_name(date('n', strtotime($order->close_time))); ?>
                                        </td>
                                        <td>
                                            <?php 
                                                // Проверяем, есть ли дробная часть
                                                $open_price = floatval($order->open_price);
                                                // Убираем разделители тысяч, удаляем лишние нули
                                                $formatted_open_price = (floor($open_price) == $open_price) ? 
                                                    number_format($open_price, 0, '.', '') : 
                                                    rtrim(rtrim(number_format($open_price, 5, '.', ''), '0'), '.');
                                                echo $formatted_open_price;
                                            ?>
                                        </td>
                                        <td>
                                            <?php 
                                                $close_price = floatval($order->close_price);
                                                // Убираем разделители тысяч, удаляем лишние нули
                                                $formatted_close_price = (floor($close_price) == $close_price) ? 
                                                    number_format($close_price, 0, '.', '') : 
                                                    rtrim(rtrim(number_format($close_price, 5, '.', ''), '0'), '.');
                                                echo $formatted_close_price;
                                            ?>
                                        </td>
                                        <td class="<?php echo $order->profit >= 0 ? 'profit-positive' : 'profit-negative'; ?>">
                                            <?php 
                                                $profit = floatval($order->profit);
                                                // Всегда сохраняем до 2 знаков для прибыли, убираем лишние нули и разделители тысяч
                                                $formatted_profit = rtrim(rtrim(number_format($profit, 2, '.', ''), '0'), '.');
                                                // Если после удаления нулей нет десятичной части, добавляем ".0"
                                                if (strpos($formatted_profit, '.') === false && $profit != 0) {
                                                    $formatted_profit .= '.0';
                                                }
                                                echo $formatted_profit;
                                            ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <p class="no-orders">История сделок пуста.</p>
                <?php endif; ?>
            </section>

            <!-- История изменений счета -->
            <section class="account-change-history">
                <h2>История изменений счета</h2>
                <input type="hidden" id="account_id" value="<?php echo $account_id; ?>">
                
                <div class="history-filters">
                    <select id="field_filter" class="history-filter">
                        <option value="">Все поля</option>
                        <optgroup label="Финансовые показатели">
                            <option value="i_bal">Баланс</option>
                            <option value="i_equi">Средства</option>
                            <option value="i_marg">Использованная маржа</option>
                            <option value="i_prof">Плавающая прибыль/убыток</option>
                            <option value="leverage">Кредитное плечо</option>
                            <option value="i_ordtotal">Количество открытых ордеров</option>
                            <option value="active_orders_volume">Суммарный объем открытых сделок</option>
                            <option value="h_count">Количество записей в истории</option>
                        </optgroup>
                        <optgroup label="Другие параметры">
                            <option value="pass">Пароль</option>
                            <option value="srvMt4">Сервер MT4</option>
                            <option value="i_firma">Брокер</option>
                                                        <option value="i_fio">Имя</option>
                            <option value="connection_status">Статус подключения</option>
                        </optgroup>
                    </select>

                    <select id="period_filter" class="history-filter">
                        <option value="all">За все время</option>
                        <option value="day" selected>За сегодня</option>
                        <option value="week">За неделю</option>
                        <option value="month">За месяц</option>
                        <option value="year">За год</option>
                    </select>

                    <button id="sort_date" class="button" data-sort="desc">
                        <span class="dashicons dashicons-arrow-down-alt2"></span> По дате
                    </button>
                </div>

                <div id="account-history-wrapper">Загрузка...</div>
            </section>
        </div>

        <aside class="account-sidebar">
            <div class="account-sidebar-section">
                <h3>Информация о счете</h3>

                <div class="account-info-item">
                    <span class="account-info-label">Номер счета:</span>
                    <span class="account-info-value"><?php echo esc_html($account->account_number); ?></span>
                </div>

                <div class="account-info-item">
                    <span class="account-info-label">Брокер:</span>
                    <span class="account-info-value"><?php echo esc_html($account->broker); ?></span>
                </div>

                <div class="account-info-item">
                    <span class="account-info-label">Тип счета:</span>
                    <span class="account-info-value"><?php echo esc_html($account->account_type); ?></span>
                </div>

                <div class="account-info-item">
                    <span class="account-info-label">Торговое плечо:</span>
                    <span class="account-info-value"><?php echo isset($account->leverage) ? '1:' . intval($account->leverage) : 'нет данных'; ?></span>
                </div>

                <div class="account-info-item">
                    <span class="account-info-label">Валюта:</span>
                    <span class="account-info-value"><?php echo esc_html($account->currency); ?></span>
                </div>

                <div class="account-info-item">
                    <span class="account-info-label">Начальный депозит:</span>
                    <span class="account-info-value"><?php echo number_format($initial_deposit, 2); ?> <?php echo esc_html($account->currency); ?></span>
                </div>

                <div class="account-info-item">
                    <span class="account-info-label">Имя владельца:</span>
                    <span class="account-info-value"><?php echo str_repeat('*', 10); ?></span>
                </div>

                <?php if (!empty($account->user_country)): ?>
                    <div class="account-info-item">
                        <span class="account-info-label">Страна:</span>
                        <span class="account-info-value">
                            <?php if (!empty($account->country_code)): ?>
                                <img src="https://flagcdn.com/16x12/<?php echo strtolower($account->country_code); ?>.png"
                                    alt="<?php echo esc_attr($account->user_country); ?>"
                                    title="<?php echo esc_attr($account->user_country); ?>" width="16" height="12"
                                    style="margin-right: 5px; vertical-align: middle;" />
                            <?php endif; ?>
                            <?php echo esc_html($account->user_country); ?>
                        </span>
                    </div>
                <?php endif; ?>

                <div class="account-info-item">
                    <span class="account-info-label">Регистрация в конкурсе:</span>
                    <span class="account-info-value"><?php echo date_i18n('d.m.Y', strtotime($account->registration_date)); ?></span>
                </div>

                <div class="account-info-item">
                    <span class="account-info-label">Первая сделка:</span>
                    <span class="account-info-value">
                        <?php 
                        if ($first_trade && !empty($first_trade->open_time)) {
                            echo date_i18n('d.m.Y', strtotime($first_trade->open_time));
                        } else {
                            echo 'Еще не совершена';
                        }
                        ?>
                    </span>
                </div>
            </div>

            <div class="account-sidebar-section">
                <h3>Действия</h3>

                <a href="<?php echo get_permalink($contest_id); ?>" class="account-action-button">
                    Вернуться к конкурсу
                </a>

                <?php if (is_user_logged_in() && (get_current_user_id() == $account->user_id || current_user_can('manage_options'))): ?>
                    <button id="refresh-account-data" class="account-action-button refresh"
                        data-account-id="<?php echo $account_id; ?>">
                        Обновить данные счета
                    </button>
                    <div id="refresh-status" class="refresh-status"></div>

                    <!-- Кнопка редактирования счета -->
                    <div class="account-actions">
                        <button id="show-edit-account-form" class="account-action-button">
                            Редактировать данные счета
                        </button>
                    </div>

                    <?php if (current_user_can('manage_options')): ?>
                    <!-- Кнопка удаления истории сделок (только для админов) -->
                    <button id="clear-order-history" class="account-action-button delete"
                        data-account-id="<?php echo $account_id; ?>">
                        Удалить сделки
                    </button>
                    <div id="clear-order-status" class="delete-status"></div>
                    <?php endif; ?>

                    <!-- Кнопка удаления счета -->
                    <button id="delete-account-data" class="account-action-button delete"
                        data-account-id="<?php echo $account_id; ?>" data-contest-id="<?php echo $contest_id; ?>">
                        Удалить счет
                    </button>
                    <div id="delete-status" class="delete-status"></div>

                    <!-- Кнопка проверки на дисквалификацию -->
                    <button id="check-disqualification" class="account-action-button"
                        data-account-id="<?php echo esc_attr($account->id); ?>">
                        Проверить на дисквалификацию
                    </button>

                    <div id="disqualification-status" class="account-status-message mt-3"></div>
                <?php endif; ?>
            </div>
        </aside>
    </div>

    <!-- Форма редактирования счета -->
    <?php if (is_user_logged_in() && (get_current_user_id() == $account->user_id || current_user_can('manage_options'))): ?>
        <!-- Контейнер для формы редактирования счета -->
        <div id="edit-account-form-container" style="display: none;" class="account-edit-container">
            <?php
            // Подключаем форму редактирования/регистрации счета
            // Передаем объект $account для заполнения полей
            include plugin_dir_path(dirname(__FILE__)) . 'templates/parts/registration-form.php';
            ?>
        </div>
    <?php endif; ?>

</div>

<?php get_footer(); ?>

<script type="text/javascript">
    // Установим идентификатор счета для использования в frontend.js
    var accountId = '<?php echo $account_id; ?>';
</script>

<?php
// Подключение JS файлов для страницы счета
wp_enqueue_script('ft-frontend-scripts');
?>