<?php
/**
 * Шаблон для отображения отдельного конкурса трейдеров
 */

// Управление кешированием должно быть до get_header()
if (!is_user_logged_in()) {
    // Удаляем заголовки кеширования, так как они могут конфликтовать с WordPress
    // header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    // header('Cache-Control: post-check=0, pre-check=0', false);
    // header('Pragma: no-cache');
    
    // Используем WordPress способ управления кешированием
    nocache_headers();
}

get_header();

// Получаем все метаданные конкурса
$contest_data = get_post_meta(get_the_ID(), '_fttradingapi_contest_data', true);
if (!is_array($contest_data)) {
    $contest_data = array();
}

// Вспомогательная функция для получения значения
function get_contest_field($key, $data, $default = '') {
    return isset($data[$key]) && !empty($data[$key]) ? $data[$key] : $default;
}

// Основные данные конкурса
$contest_status = get_contest_field('contest_status', $contest_data, 'draft');
$registration_status = get_contest_field('registration', $contest_data, 'closed');
$start_date = get_contest_field('date_start', $contest_data);
$end_date = get_contest_field('date_end', $contest_data);
$end_registration = get_contest_field('end_registration', $contest_data);
$start_deposit = get_contest_field('start_deposit', $contest_data, '0');
$advisors_allowed = get_contest_field('advisors_allowed', $contest_data, '0');
$servers = get_contest_field('servers', $contest_data);
$broker_id = get_contest_field('broker_id', $contest_data);
$platform_id = get_contest_field('platform_id', $contest_data);
$server_val = get_contest_field('server', $contest_data);

$broker_name = '';
$platform_name = '';
if ($broker_id && class_exists('FTTrader_Brokers_Platforms')) {
    $broker_obj = FTTrader_Brokers_Platforms::get_broker($broker_id);
    if ($broker_obj) { $broker_name = $broker_obj->name; }
}
if ($platform_id && class_exists('FTTrader_Brokers_Platforms')) {
    $platform_obj = FTTrader_Brokers_Platforms::get_platform($platform_id);
    if ($platform_obj) { $platform_name = $platform_obj->name; }
}
$prize_places = get_contest_field('prize_places', $contest_data);
$trading_rules = get_contest_field('trading_rules', $contest_data);
$terms_link = get_contest_field('terms_link', $contest_data);
$description = get_contest_field('description', $contest_data);
$full_desc = get_contest_field('full_desc', $contest_data);

// Проверка на архивный конкурс
$is_archived = get_contest_field('is_archived', $contest_data, '0');
$sponsor = get_contest_field('sponsor', $contest_data);
$sponsor_logo = get_contest_field('sponsor_logo', $contest_data);

// Разбираем призовые места с помощью регулярного выражения
$parsed_prizes = array();
if (!empty($prize_places)) {
    // Разбиваем на строки, если введены с новой строки
    $prizes_lines = preg_split('/\r\n|\r|\n/', $prize_places);
    
    foreach ($prizes_lines as $line) {
        // Попробуем найти формат "X место - $Y" или вариации
        if (preg_match('/(\d+)[^\d-]*-[^$]*\$?(\d+)/i', $line, $matches)) {
            $place = $matches[1];
            $amount = $matches[2];
            $parsed_prizes[$place] = $amount;
        }
    }
}


// Получаем участников конкурса
global $wpdb;
$table_name = $wpdb->prefix . 'contest_members';
$contest_participants = $wpdb->get_results($wpdb->prepare(
    "SELECT * FROM $table_name 
     WHERE contest_id = %d 
     ORDER BY equity DESC",
    get_the_ID()
));

// Проверяем, есть ли уже счет текущего пользователя в этом конкурсе
$current_user_account = null;
if (is_user_logged_in()) {
    $current_user_id = get_current_user_id();
    foreach ($contest_participants as $participant) {
        if ($participant->user_id == $current_user_id) {
            $current_user_account = $participant;
            break;
        }
    }
}

// Переводы статусов для отображения
$status_labels = array(
    'draft' => 'Подготовка',
    'active' => 'Активен',
    'finished' => 'Завершён'
);

$registration_labels = array(
    'open' => 'Открыта',
    'closed' => 'Закрыта'
);

?>

<div class="contest-single-container">

<?php if ($is_archived == '1'): ?>
    <!-- Добавляем хлебные крошки для архивного конкурса -->
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
                        <span class="breadcrumb-active" itemprop="name"><?php the_title(); ?></span>
                        <meta content="2" itemprop="position">
                    </span>
                </li>
            </ul>
        </div>
    </div>

    <section class="section_offset">
        <div class="fx_content-title16 flex_title clearfix">
            <span class="fx_content-title-link">АРХИВНЫЙ КОНКУРС</span>
        </div>
    </section>
    
    <!-- Шаблон архивного конкурса -->
    <div class="contest-archive-notice">
        <h1 class="contest-title-new"><?php the_title(); ?></h1>
        
        <div class="contest-archive-content">
            <div class="contest-archive-message">
                <div class="contest-info-item">
                    <span class="contest-info-label">Статус:</span>
                    <span class="contest-info-value finished">Конкурс завершен и находится в архиве</span>
                </div>
                
                <?php if (!empty($start_date) && !empty($end_date)): ?>
                <div class="contest-info-item">
                    <span class="contest-info-label">Период:</span>
                    <span class="contest-info-value">
                        <?php echo date_i18n('d.m.Y', strtotime($start_date)); ?> - 
                        <?php echo date_i18n('d.m.Y', strtotime($end_date)); ?>
                    </span>
                </div>
                <?php endif; ?>
                
                <?php if (!empty($contest_data['prizes'])): ?>
                <div class="contest-info-item">
                    <span class="contest-info-label">Призовой фонд:</span>
                    <span class="contest-info-value">
                        <?php 
                        $total_prize = 0;
                        foreach ($contest_data['prizes'] as $prize) {
                            $amount = preg_replace('/[^0-9.]/', '', $prize['amount']);
                            $total_prize += floatval($amount);
                        }
                        echo '$' . number_format($total_prize, 0, '.', ' ');
                        ?>
                    </span>
                </div>
                <?php endif; ?>
                
                <?php if (!empty($sponsor)): ?>
                <div class="contest-info-item">
                    <span class="contest-info-label">Организатор:</span>
                    <span class="contest-info-value"><?php echo esc_html($sponsor); ?></span>
                </div>
                <?php endif; ?>
            </div>
            
            <?php if (!empty($sponsor_logo)): ?>
            <div class="contest-archive-sponsor">
                <img src="<?php echo esc_url($sponsor_logo); ?>" alt="<?php echo esc_attr($sponsor); ?>" class="sponsor-logo" />
            </div>
            <?php endif; ?>
        </div>
        
        <!-- Добавляем таблицу участников конкурса -->
        <div class="contest-archive-members">
            <h3>Участники конкурса</h3>
            
            <?php
            global $wpdb;
            $contest_id = get_the_ID();
            $members_table = $wpdb->prefix . 'contest_members';
            
            // Получаем всех участников конкурса, сортируя по прибыли (в процентах)
            $members = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM $members_table WHERE contest_id = %d ORDER BY profit_percent DESC",
                    $contest_id
                )
            );
            
            if ($members && count($members) > 0):
            ?>
            <div class="contest-members-table-wrapper">
                <table class="contest-members-table">
                    <thead>
                        <tr>
                            <th>Место</th>
                            <th>Трейдер</th>
                            <th>Счет</th>
                            <th>Баланс</th>
                            <th>Прибыль %</th>
                            <th>Кол-во сделок</th>
                            <th>Статус</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $place = 1;
                        foreach ($members as $member): 
                            // Определяем класс для статуса
                            $status_class = 'status-normal';
                            if ($member->connection_status == 'disqualified') {
                                $status_class = 'status-disqualified';
                            } elseif ($member->connection_status == 'quitted') {
                                $status_class = 'status-quitted';
                            } elseif ($member->connection_status == 'disconnected') {
                                $status_class = 'status-disconnected';
                            }
                            
                            // Определяем текст статуса
                            $status_text = 'Участвует';
                            if ($member->connection_status == 'disqualified') {
                                $status_text = 'Дисквалифицирован';
                            } elseif ($member->connection_status == 'quitted') {
                                $status_text = 'Выбыл';
                            } elseif ($member->connection_status == 'disconnected') {
                                $status_text = 'Ошибка подключения';
                            }
                            
                            // Получаем данные пользователя
                            $user_info = get_userdata($member->user_id);
                            $display_name = $user_info ? $user_info->display_name : 'Участник #' . $member->id;
                            if (empty($display_name)) {
                                $display_name = $user_info ? $user_info->user_login : 'Участник #' . $member->id;
                            }
                        ?>
                        <tr>
                            <td><?php echo esc_html($place++); ?></td>
                            <td>
                                <?php if ($user_info): ?>
                                <a href="<?php echo esc_url(add_query_arg(array('trader_id' => $member->user_id), home_url('/trader-statistics/'))); ?>" class="trader-name-link">
                                    <?php echo esc_html($display_name); ?>
                                </a>
                                <?php else: ?>
                                <?php echo esc_html($display_name); ?>
                                <?php endif; ?>
                            </td>
                            <td class="member-account-number"><?php echo esc_html($member->account_number); ?></td>
                            <td class="member-balance">$<?php echo number_format($member->balance, 2, '.', ' '); ?></td>
                            <td class="member-profit-percent <?php echo ($member->profit_percent >= 0) ? 'profit-positive' : 'profit-negative'; ?>">
                                <?php echo ($member->profit_percent >= 0 ? '+' : '') . number_format($member->profit_percent, 2, '.', ' ') . '%'; ?>
                            </td>
                            <td class="member-trades"><?php echo esc_html($member->orders_history_total); ?></td>
                            <td class="member-status <?php echo esc_attr($status_class); ?>">
                                <?php if ($member->connection_status === 'connected'): ?>
                                    <span class="status-indicator status-connected"></span>
                                <?php elseif ($member->connection_status === 'disqualified'): ?>
                                    <span class="status-indicator status-disqualified"></span>
                                <?php elseif ($member->connection_status === 'disconnected'): ?>
                                    <span class="status-indicator status-disconnected"></span>
                                <?php endif; ?>
                                <?php echo esc_html($status_text); ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
            <p class="no-members-message">Нет данных об участниках этого конкурса.</p>
            <?php endif; ?>
        </div>
        
        <div class="contest-archive-footer">
            <p>Данный конкурс завершен и перемещен в архив. Информация о нем сохранена для исторических целей.</p>
            <a href="<?php echo esc_url(get_post_type_archive_link('trader_contests')); ?>" class="contest-register-button">
                Все конкурсы
            </a>
        </div>
    </div>
<?php else: ?>
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
                        <span class="breadcrumb-active" itemprop="name"><?php the_title(); ?></span>
                        <meta content="2" itemprop="position">
                    </span>
                </li>
            </ul>
        </div>
    </div>

    <section class="section_offset">
        <div class="fx_content-title16 flex_title clearfix" style="display: flex; justify-content: space-between; align-items: center;">
            <span class="fx_content-title-link"><?php the_title(); ?></span>
        </div>
    </section>

    <article id="post-<?php the_ID(); ?>" <?php post_class(); ?>>

        <div class="contest-details">
            <div class="contest-main-content">
                <?php if (has_post_thumbnail()): ?>
                    <div class="contest-featured-image">
                        <?php the_post_thumbnail('large', ['class' => 'contest-image']); ?>
                    </div>
                <?php endif; ?>

                <!-- Описание конкурса -->
                <div class="contest-description">
                    <?php if (!empty($description)): ?>
                        <div class="contest-short-description">
                            <?php echo wpautop(esc_html($description)); ?>
                        </div>
                    <?php endif; ?>
                    
                    <?php if (!empty($full_desc)): ?>
                        <div class="contest-full-description">
                            <?php echo wpautop(esc_html($full_desc)); ?>
                        </div>
                    <?php else: ?>
                        <?php the_content(); ?>
                    <?php endif; ?>
                </div>
                
                <!-- Блок с призовыми местами -->
                <?php 
                // Получаем данные о призовых местах из метаданных
                $prizes = isset($contest_data['prizes']) ? $contest_data['prizes'] : array();

                // Проверяем, есть ли призовые места
                if (!empty($prizes) || !empty($prize_places)): 
                ?>
                <div class="contest-prizes-block">
                    <div class="contest-prizes-header">
                        <span class="contest-prizes-icon">🏆</span>
                        <h3>Призовые места</h3>
                    </div>
                    
                    <div class="contest-prizes-grid">
                        <?php 
                        // Новый формат призовых мест
                        if (!empty($prizes)): 
                            foreach ($prizes as $prize): 
                        ?>
                            <div class="prize-item place-<?php echo esc_attr($prize['place']); ?>">
                                <div class="prize-place"><?php echo esc_html($prize['place']); ?> место</div>
                                <div class="prize-amount"><?php echo esc_html($prize['amount']); ?></div>
                                <?php if (!empty($prize['description'])): ?>
                                <div class="prize-description"><?php echo esc_html($prize['description']); ?></div>
                                <?php endif; ?>
                            </div>
                        <?php 
                            endforeach; 
                        // Старый формат призовых мест
                        elseif (!empty($prize_places)):
                            // Преобразуем старый формат в новый для отображения
                            $prizes_lines = preg_split('/\r\n|\r|\n/', $prize_places);
                            foreach ($prizes_lines as $line): 
                                // Попробуем найти формат "X место - $Y" или вариации
                                if (preg_match('/(\d+)[^\d-]*-[^$]*\$?(\d+)/i', $line, $matches)): 
                                    $place = $matches[1];
                                    $amount = $matches[2];
                        ?>
                                <div class="prize-item place-<?php echo esc_attr($place); ?>">
                                    <div class="prize-place"><?php echo esc_html($place); ?> место</div>
                                    <div class="prize-amount">$<?php echo esc_html($amount); ?></div>
                                </div>
                        <?php 
                                endif;
                            endforeach;
                        endif; 
                        ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Блок условий дисквалификации -->
                <?php
                // Получаем данные об условиях дисквалификации из метаданных
                $check_initial_deposit = get_contest_field('check_initial_deposit', $contest_data, '0');
                $initial_deposit = get_contest_field('initial_deposit', $contest_data, '0');
                $check_leverage = get_contest_field('check_leverage', $contest_data, '0');
                $allowed_leverage = get_contest_field('allowed_leverage', $contest_data, '');
                $check_instruments = get_contest_field('check_instruments', $contest_data, '0');
                $allowed_instruments = get_contest_field('allowed_instruments', $contest_data, '*');
                $excluded_instruments = get_contest_field('excluded_instruments', $contest_data, '');
                $check_max_volume = get_contest_field('check_max_volume', $contest_data, '0');
                $max_volume = get_contest_field('max_volume', $contest_data, '0');
                $check_min_trades = get_contest_field('check_min_trades', $contest_data, '0');
                $min_trades = get_contest_field('min_trades', $contest_data, '0');
                $check_hedged_positions = get_contest_field('check_hedged_positions', $contest_data, '0');
                $check_pre_contest_trades = get_contest_field('check_pre_contest_trades', $contest_data, '0');
                $check_min_profit = get_contest_field('check_min_profit', $contest_data, '0');
                $min_profit = get_contest_field('min_profit', $contest_data, '0');

                // Проверяем, есть ли хотя бы одно условие дисквалификации
                $has_disqualification_conditions = (
                    $check_initial_deposit == '1' || 
                    $check_leverage == '1' || 
                    $check_instruments == '1' || 
                    $check_max_volume == '1' || 
                    $check_min_trades == '1' || 
                    $check_pre_contest_trades == '1' || 
                    $check_min_profit == '1'
                );

                if ($has_disqualification_conditions): 
                ?>
                <div id="disqualification-conditions" class="contest-disqualification-block">
                    <div class="contest-disqualification-header clickable">
                        <span class="contest-disqualification-icon">⚠️</span>
                        <h3>Условия дисквалификации</h3>
                        <span class="contest-disqualification-toggle">
                            <span class="toggle-icon">▼</span>
                        </span>
                    </div>
                    
                    <div class="contest-disqualification-list" style="display: none;">
                        <?php if ($check_initial_deposit == '1'): ?>
                        <div class="disqualification-item">
                            <div class="disqualification-title">Начальный депозит</div>
                            <div class="disqualification-value">
                                Участник дисквалифицируется, если начальный депозит не равен 
                                <strong>$<?php echo number_format(floatval($initial_deposit), 2); ?></strong>
                            </div>
                        </div>
                        <?php endif; ?>

                        <?php if ($check_leverage == '1'): ?>
                        <div class="disqualification-item">
                            <div class="disqualification-title">Кредитное плечо</div>
                            <div class="disqualification-value">
                                Участник дисквалифицируется, если кредитное плечо не равно 
                                <strong><?php echo esc_html($allowed_leverage); ?></strong>
                            </div>
                        </div>
                        <?php endif; ?>

                        <?php if ($check_instruments == '1'): ?>
                        <div class="disqualification-item">
                            <div class="disqualification-title">Торговые инструменты</div>
                            <div class="disqualification-value">
                                <div>
                                    <?php if ($allowed_instruments == '*'): ?>
                                    <p>Разрешены: <strong>Все инструменты</strong></p>
                                    <?php else: ?>
                                    <p>Разрешены: <strong><?php echo esc_html($allowed_instruments); ?></strong></p>
                                    <?php endif; ?>
                                </div>
                                <?php if (!empty($excluded_instruments)): ?>
                                <div>
                                    <p>Запрещены: <strong><?php echo esc_html($excluded_instruments); ?></strong></p>
                                </div>
                                <?php endif; ?>
                                <div>
                                    <p>Участник дисквалифицируется при использовании запрещенных или неразрешенных инструментов</p>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>

                        <?php if ($check_max_volume == '1'): ?>
                        <div class="disqualification-item">
                            <div class="disqualification-title">Максимальный объем</div>
                            <div class="disqualification-value">
                                Участник дисквалифицируется, если суммарный объем открытых сделок превысит
                                <strong><?php echo number_format(floatval($max_volume), 2); ?> лот</strong>
                            </div>
                        </div>
                        <?php endif; ?>

                        <?php if ($check_min_trades == '1'): ?>
                        <div class="disqualification-item">
                            <div class="disqualification-title">Минимальное количество сделок</div>
                            <div class="disqualification-value">
                                <p>Участник дисквалифицируется, если количество сделок на момент завершения конкурса меньше 
                                <strong><?php echo intval($min_trades); ?></strong></p>
                                <?php if ($check_hedged_positions == '1'): ?>
                                <p><small>* Одновременно открытые и закрытые сделки в одном направлении по одному активу считаются как одна позиция</small></p>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endif; ?>

                        <?php if ($check_pre_contest_trades == '1'): ?>
                        <div class="disqualification-item">
                            <div class="disqualification-title">Сделки до начала конкурса</div>
                            <div class="disqualification-value">
                                Участник дисквалифицируется, если будут обнаружены сделки, совершенные до даты начала конкурса
                            </div>
                        </div>
                        <?php endif; ?>

                        <?php if ($check_min_profit == '1'): ?>
                        <div class="disqualification-item">
                            <div class="disqualification-title">Минимальная прибыль</div>
                            <div class="disqualification-value">
                                Участник дисквалифицируется, если на момент завершения конкурса его прибыль меньше
                                <strong><?php echo number_format(floatval($min_profit), 2); ?>%</strong>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Блок статистики конкурса -->
                <?php if (!empty($contest_participants) && count($contest_participants) > 0): 
                    // Расчет статистики конкурса
                    $total_trades = 0;
                    $total_profit = 0;
                    $total_loss = 0;
                    $traders_in_profit = 0;
                    $traders_in_loss = 0;
                    $total_profit_amount = 0;
                    $total_loss_amount = 0;
                    
                    foreach ($contest_participants as $player) {
                        // Считаем общее количество сделок
                        $total_trades += isset($player->orders_history_total) ? intval($player->orders_history_total) : 0;
                        
                        // Находим начальный депозит в истории сделок (запись типа 'balance')
                        $history_table = $wpdb->prefix . 'contest_members_order_history';
                        $real_initial_deposit = isset($contest_data['start_deposit']) ? floatval($contest_data['start_deposit']) : 10000; // Значение по умолчанию
                        
                        $deposit_record = $wpdb->get_row($wpdb->prepare(
                            "SELECT * FROM $history_table 
                             WHERE account_id = %d AND type = 'balance'
                             ORDER BY open_time ASC
                             LIMIT 1",
                            $player->id
                        ));
                        
                        if ($deposit_record && $deposit_record->profit > 0) {
                            $real_initial_deposit = $deposit_record->profit;
                        }
                        
                        // Вычисляем прибыль (разница между начальным депозитом и текущим балансом)
                        $profit = $player->equity - $real_initial_deposit;
                        $profit_percent = ($real_initial_deposit > 0) ? ($profit / $real_initial_deposit) * 100 : 0;
                        $is_negative = $profit < 0;
                        
                        // Считаем трейдеров в прибыли и убытке
                        if ($profit > 0) {
                            $traders_in_profit++;
                            $total_profit_amount += $profit;
                        } else if ($profit < 0) {
                            $traders_in_loss++;
                            $total_loss_amount += abs($profit);
                        }
                    }
                    
                    // Вычисляем процентное соотношение
                    $profit_traders_ratio = $traders_in_profit > 0 ? ($traders_in_profit / count($contest_participants)) * 100 : 0;
                    $loss_traders_ratio = $traders_in_loss > 0 ? ($traders_in_loss / count($contest_participants)) * 100 : 0;
                    
                    // Определяем соотношение прибыли/убытка
                    $total_pnl = $total_profit_amount - $total_loss_amount;
                    $is_total_pnl_positive = $total_pnl >= 0;
                    
                    // Получаем временную метку последнего обновления
                    $latest_update_time = current_time('timestamp');
                    if (!empty($contest_participants)) {
                        $latest_update = max(array_map(function($p) {
                            return strtotime($p->last_update);
                        }, $contest_participants));
                        
                        if ($latest_update) {
                            $latest_update_time = $latest_update;
                        }
                    }
                ?>
                <div class="contest-stats-block" id="contest-stats">
                    <div class="contest-stats-header">
                        <div class="contest-stats-title">
                            <span class="contest-stats-icon">📊</span>
                            <h3>Статистика конкурса</h3>
                        </div>
                        <div class="contest-stats-timestamp" id="stats-timestamp">
                            Обновлено: <?php echo date_i18n('d.m.Y, H:i', $latest_update_time); ?>
                        </div>
                    </div>
                    
                    <div class="contest-stats-grid">
                        <!-- Карточка со статистикой трейдеров -->
                        <div class="contest-stat-card" id="stat-card-traders">
                            <div class="contest-stat-title">Трейдеры</div>
                            <div class="contest-stat-value" data-value="<?php echo count($contest_participants); ?>">
                                <?php echo count($contest_participants); ?>
                            </div>
                            <div class="stat-ratio-indicator">
                                <div class="stat-ratio-profit" style="width: <?php echo $profit_traders_ratio; ?>%;"></div>
                                <div class="stat-ratio-loss" style="width: <?php echo $loss_traders_ratio; ?>%;"></div>
                                <div class="stat-ratio-separator"></div>
                            </div>
                            <div class="contest-stat-details">
                                <span>В прибыли: <strong><?php echo $traders_in_profit; ?></strong></span>
                                <span>В убытке: <strong><?php echo $traders_in_loss; ?></strong></span>
                            </div>
                        </div>
                        
                        <!-- Карточка с общим P&L -->
                        <div class="contest-stat-card" id="stat-card-pnl">
                            <div class="contest-stat-title">Общий P&L</div>
                            <div class="contest-stat-value <?php echo $is_total_pnl_positive ? 'positive' : 'negative'; ?>" data-value="<?php echo $total_pnl; ?>">
                                <?php 
                                // Рассчитываем процентный показатель для общего P&L
                                $total_pnl_percent = 0;
                                
                                // Считаем сумму реальных начальных депозитов
                                $total_initial_deposit = 0;
                                
                                foreach ($contest_participants as $participant) {
                                    $history_table = $wpdb->prefix . 'contest_members_order_history';
                                    $account_initial_deposit = isset($contest_data['start_deposit']) ? floatval($contest_data['start_deposit']) : 10000; // Значение по умолчанию
                                    
                                    $deposit_record = $wpdb->get_row($wpdb->prepare(
                                        "SELECT * FROM $history_table 
                                         WHERE account_id = %d AND type = 'balance'
                                         ORDER BY open_time ASC
                                         LIMIT 1",
                                        $participant->id
                                    ));
                                    
                                    if ($deposit_record && $deposit_record->profit > 0) {
                                        $account_initial_deposit = $deposit_record->profit;
                                    }
                                    
                                    $total_initial_deposit += $account_initial_deposit;
                                }
                                
                                if ($total_initial_deposit > 0) {
                                    $total_pnl_percent = ($total_pnl / $total_initial_deposit) * 100;
                                }
                                echo ($is_total_pnl_positive ? '+' : '') . number_format($total_pnl, 2); ?> USD
                            </div>
                            <div class="pnl-percent-wrap">
                                <span class="pnl-percent-small <?php echo $is_total_pnl_positive ? 'positive' : 'negative'; ?>">
                                    (<?php echo ($is_total_pnl_positive ? '+' : '') . number_format($total_pnl_percent, 2); ?>%)
                                </span>
                            </div>
                            <div class="stat-progress-bar">
                                <?php if ($total_profit_amount > 0 || $total_loss_amount > 0): 
                                    $total_money_moved = $total_profit_amount + $total_loss_amount;
                                    $profit_width = $total_money_moved > 0 ? ($total_profit_amount / $total_money_moved) * 100 : 0;
                                ?>
                                <div class="stat-progress-fill profit" style="width: <?php echo $profit_width; ?>%;"></div>
                                <?php endif; ?>
                            </div>
                            <div class="contest-stat-details">
                                <?php
                                // Рассчитываем процентное соотношение для заработанных и потерянных средств
                                $profit_percent = 0;
                                $loss_percent = 0;
                                // Используем уже рассчитанное значение total_initial_deposit
                                if ($total_initial_deposit > 0) {
                                    $profit_percent = ($total_profit_amount / $total_initial_deposit) * 100;
                                    $loss_percent = ($total_loss_amount / $total_initial_deposit) * 100;
                                }
                                ?>
                                <span>Заработано: 
                                    <strong>$<?php echo number_format($total_profit_amount, 2); ?></strong>
                                    <span class="profit-percent positive">(+<?php echo number_format($profit_percent, 2); ?>%)</span>
                                </span>
                                <span>Потеряно: 
                                    <strong>$<?php echo number_format($total_loss_amount, 2); ?></strong>
                                    <span class="loss-percent negative">(-<?php echo number_format($loss_percent, 2); ?>%)</span>
                                </span>
                            </div>
                        </div>
                        
                        <!-- Карточка со сделками -->
                        <div class="contest-stat-card" id="stat-card-trades">
                            <div class="contest-stat-title">Всего сделок</div>
                            <div class="contest-stat-value" data-value="<?php echo $total_trades; ?>">
                                <?php echo $total_trades; ?>
                            </div>
                            <?php 
                                // Для демонстрации распределения сделок (в реальности эти данные нужно получать из БД)
                                $buy_trades_percent = 55; // Пример значения
                                $sell_trades_percent = 45; // Пример значения
                            ?>
                            <div class="stat-progress-bar">
                                <div class="stat-progress-fill profit" style="width: <?php echo $buy_trades_percent; ?>%;"></div>
                            </div>
                            <div class="trade-distribution">
                                <div class="trades-by-type">
                                    <span class="trades-type-buy"><span class="trades-icon">↗</span> Buy: <?php echo $buy_trades_percent; ?>%</span>
                                    <span class="trades-type-sell"><span class="trades-icon">↘</span> Sell: <?php echo $sell_trades_percent; ?>%</span>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Карточка с эффективностью -->
                        <div class="contest-stat-card" id="stat-card-efficiency">
                            <?php 
                                // Для демонстрации эффективности (в реальности эти данные нужно рассчитывать)
                                $win_rate = $traders_in_profit > 0 
                                    ? round(($traders_in_profit / count($contest_participants)) * 100) 
                                    : 0;
                                $is_win_rate_good = $win_rate >= 50;
                            ?>
                            <div class="contest-stat-title">
                                Эффективность 
                                <span class="tooltip-icon" data-tooltip="Показывает общую успешность участников конкурса. Значение (<?php echo $win_rate; ?>%) - это процент участников, находящихся в прибыли относительно общего числа участников конкурса. <?php echo $is_win_rate_good ? 'Более' : 'Менее'; ?> половины участников в прибыли, поэтому эффективность считается <?php echo $is_win_rate_good ? 'высокой' : 'низкой'; ?>.">?</span>
                            </div>
                            <div class="contest-stat-value <?php echo $is_win_rate_good ? 'positive' : 'negative'; ?>" data-value="<?php echo $win_rate; ?>">
                                <?php echo $win_rate; ?>%
                            </div>
                            <div class="stat-progress-bar">
                                <div class="stat-progress-fill <?php echo $is_win_rate_good ? 'profit' : 'loss'; ?>" style="width: <?php echo $win_rate; ?>%;"></div>
                            </div>
                            <div class="contest-stat-change <?php echo $is_win_rate_good ? 'positive' : 'negative'; ?>">
                                <span class="contest-stat-change-icon"><?php echo $is_win_rate_good ? '↗' : '↘'; ?></span>
                                <span><?php echo $is_win_rate_good ? 'Высокая эффективность' : 'Низкая эффективность'; ?></span>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Правила конкурса -->
                <?php if (!empty($trading_rules)): ?>
                <div class="contest-rules">
                    <h3>Правила конкурса</h3>
                    <?php echo wpautop(esc_html($trading_rules)); ?>
                </div>
                <?php endif; ?>
            </div>

            <aside class="contest-sidebar">
                <div class="contest-sidebar-section">
                    <h3 class="contest-sidebar-title">Информация о конкурсе</h3>
                    
                    <div class="contest-info-item">
                        <span class="contest-info-label">Статус:</span>
                        <span class="contest-info-value <?php echo esc_attr($contest_status); ?>">
                            <?php echo isset($status_labels[$contest_status]) ? $status_labels[$contest_status] : $contest_status; ?>
                        </span>
                    </div>
                    
                    <div class="contest-info-item">
                        <span class="contest-info-label">Регистрация:</span>
                        <span class="contest-info-value">
                            <?php echo isset($registration_labels[$registration_status]) ? $registration_labels[$registration_status] : $registration_status; ?>
                        </span>
                    </div>
                    
                    <?php if ($start_date): ?>
                    <div class="contest-info-item">
                        <span class="contest-info-label">Начало:</span>
                        <span class="contest-info-value"><?php echo date_i18n('d.m.Y', strtotime($start_date)); ?></span>
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($end_date): ?>
                    <div class="contest-info-item">
                        <span class="contest-info-label">Завершение:</span>
                        <span class="contest-info-value"><?php echo date_i18n('d.m.Y', strtotime($end_date)); ?></span>
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($end_registration): ?>
                    <div class="contest-info-item">
                        <span class="contest-info-label">Окончание регистрации:</span>
                        <span class="contest-info-value"><?php echo date_i18n('d.m.Y', strtotime($end_registration)); ?></span>
                    </div>
                    <?php endif; ?>
                    <!-- Ссылка на полные условия -->
                    <?php if (!empty($terms_link)): ?>
                    <div class="contest-info-item">
                        <span class="contest-info-label">Условия:</span>
                        <span class="contest-info-value">
                            <a href="<?php echo esc_url($terms_link); ?>" target="_blank">Полные условия конкурса</a>
                        </span>
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($start_deposit): ?>
                    <div class="contest-info-item">
                        <span class="contest-info-label">Стартовый депозит:</span>
                        <span class="contest-info-value">$<?php echo esc_html($start_deposit); ?></span>
                    </div>
                    <?php endif; ?>
                    
                    <div class="contest-info-item">
                        <span class="contest-info-label">Советники:</span>
                        <span class="contest-info-value">
                            <?php echo ($advisors_allowed == '1') ? 'Разрешены' : 'Запрещены'; ?>
                        </span>
                    </div>
                    
                    <?php
                    // Проверяем, есть ли условия дисквалификации
                    $has_disqualification_conditions = (
                        get_contest_field('check_initial_deposit', $contest_data, '0') == '1' || 
                        get_contest_field('check_leverage', $contest_data, '0') == '1' || 
                        get_contest_field('check_instruments', $contest_data, '0') == '1' || 
                        get_contest_field('check_max_volume', $contest_data, '0') == '1' || 
                        get_contest_field('check_min_trades', $contest_data, '0') == '1' || 
                        get_contest_field('check_pre_contest_trades', $contest_data, '0') == '1' || 
                        get_contest_field('check_min_profit', $contest_data, '0') == '1'
                    );
                    
                    if ($has_disqualification_conditions):
                    ?>
                    <div class="contest-info-item">
                        <span class="contest-info-label">Дисквалификация:</span>
                        <span class="contest-info-value">
                            <a href="#disqualification-conditions">Есть условия дисквалификации</a>
                        </span>
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($broker_name): ?>
                    <div class="contest-info-item">
                        <span class="contest-info-label">Брокер:</span>
                        <span class="contest-info-value"><?php echo esc_html($broker_name); ?></span>
                    </div>
                    <?php endif; ?>

                    <?php if ($platform_name): ?>
                    <div class="contest-info-item">
                        <span class="contest-info-label">Платформа:</span>
                        <span class="contest-info-value"><?php echo esc_html($platform_name); ?></span>
                    </div>
                    <?php endif; ?>

                    <?php if ($server_val): ?>
                    <div class="contest-info-item">
                        <span class="contest-info-label">Сервер:</span>
                        <span class="contest-info-value"><?php echo esc_html($server_val); ?></span>
                    </div>
                    <?php elseif (!empty($servers)): ?>
                    <div class="contest-info-item">
                        <span class="contest-info-label">Серверы:</span>
                        <span class="contest-info-value"><?php echo nl2br(esc_html($servers)); ?></span>
                    </div>
                    <?php endif; ?>
                    
                    <div class="contest-info-item">
                        <span class="contest-info-label">Участников:</span>
                        <span class="contest-info-value"><?php echo count($contest_participants); ?></span>
                    </div>
                </div>

                <?php if ($contest_status === 'active' || $contest_status === 'draft'): ?>
                <div class="contest-sidebar-section">
                    <h3 class="contest-sidebar-title">Участие в конкурсе</h3>
                    
                    <?php if ($current_user_account): ?>
                        <p>Вы уже зарегистрированы в этом конкурсе.</p>
                        <a href="<?php echo add_query_arg(['contest_account' => $current_user_account->id, 'contest_id' => get_the_ID()], get_permalink()); ?>" class="contest-account-button">
                            Просмотреть мой счет
                        </a>
                    <?php else: ?>
                        <?php 
                        // Проверяем, открыта ли регистрация
                        if ($registration_status === 'open'): 
                        ?>
                            <?php if (is_user_logged_in() && !$current_user_account): ?>
                                <!-- Кнопка для показа формы регистрации -->
                                <button id="show-registration-form" class="contest-register-button">
                                    Зарегистрировать счет
                                </button>
                            <?php else: ?>
                                <a href="<?php echo wp_login_url(get_permalink()); ?>" class="contest-register-button">
                                    Войти и зарегистрировать счет
                                </a>
                            <?php endif; ?>
                        <?php else: ?>
                            <p class="registration-closed-message">Регистрация в конкурсе завершена.</p>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <!-- Топ 5 участников конкурса -->
                <?php if (!empty($contest_participants) && count($contest_participants) > 0): ?>
                <div class="contest-sidebar-section contest-top-players">
                    <h3 class="contest-top-players-title">
                        <span class="contest-top-players-icon">🏅</span>
                        Топ 5 участников
                    </h3>
                    
                    <?php 
                    // Ограничиваем список первыми 5 участниками, так как список уже отсортирован
                    $top_players = array_slice($contest_participants, 0, 5);
                    
                    foreach ($top_players as $index => $player): 
                        $user_info = get_userdata($player->user_id);
                        $player_name = '';
                        
                        // Используем приоритетный подход к отображению имени
                        if ($user_info && !empty($user_info->user_nicename)) {
                            $player_name = html_entity_decode($user_info->user_nicename, ENT_QUOTES, 'UTF-8');
                        } elseif ($user_info && !empty($user_info->user_login)) {
                            $player_name = html_entity_decode($user_info->user_login, ENT_QUOTES, 'UTF-8');
                        } elseif (!empty($player->name)) {
                            $player_name = html_entity_decode($player->name, ENT_QUOTES, 'UTF-8');
                        } elseif (!empty($player->account_login)) {
                            $player_name = html_entity_decode($player->account_login, ENT_QUOTES, 'UTF-8');
                        } else {
                            $player_name = 'Участник';
                        }
                        
                        // Находим начальный депозит в истории сделок (запись типа 'balance')
                        $history_table = $wpdb->prefix . 'contest_members_order_history';
                        $real_initial_deposit = isset($contest_data['start_deposit']) ? floatval($contest_data['start_deposit']) : 10000; // Значение по умолчанию
                        
                        $deposit_record = $wpdb->get_row($wpdb->prepare(
                            "SELECT * FROM $history_table 
                             WHERE account_id = %d AND type = 'balance'
                             ORDER BY open_time ASC
                             LIMIT 1",
                            $player->id
                        ));
                        
                        if ($deposit_record && $deposit_record->profit > 0) {
                            $real_initial_deposit = $deposit_record->profit;
                        }
                        
                        // Вычисляем прибыль (разница между начальным депозитом и текущим балансом)
                        $profit = $player->equity - $real_initial_deposit;
                        $profit_percent = ($real_initial_deposit > 0) ? ($profit / $real_initial_deposit) * 100 : 0;
                        $is_negative = $profit < 0;
                        
                        // Считаем трейдеров в прибыли и убытке
                        if ($profit > 0) {
                            $traders_in_profit++;
                            $total_profit_amount += $profit;
                        } else if ($profit < 0) {
                            $traders_in_loss++;
                            $total_loss_amount += abs($profit);
                        }
                    ?>
                    <a href="<?php echo add_query_arg(['contest_account' => $player->id, 'contest_id' => get_the_ID()], get_permalink()); ?>" class="top-player-item">
                        <div class="top-player-rank top-player-rank-<?php echo $index + 1; ?>">
                            <?php echo $index + 1; ?>
                        </div>
                        <div class="top-player-info">
                            <div class="top-player-name"><?php echo esc_html($player_name); ?></div>
                            <div class="top-player-account"><?php echo esc_html($player->account_number); ?></div>
                        </div>
                        <div class="top-player-profit">
                            <div class="top-player-amount <?php echo $is_negative ? 'negative' : ''; ?>">
                                <?php echo ($profit >= 0 ? '+' : '') . number_format($profit, 2); ?> USD
                            </div>
                            <div class="top-player-percent <?php echo $is_negative ? 'negative' : ''; ?>">
                                <?php echo ($profit_percent >= 0 ? '+' : '') . number_format($profit_percent, 2); ?>%
                            </div>
                        </div>
                    </a>
                    <?php endforeach; ?>
                    
                    <?php if (count($contest_participants) > 5): ?>
                    <a href="#participants-table" class="all-participants-link">
                        Посмотреть всех участников
                    </a>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <div class="contest-sidebar-section">
                    <h3 class="contest-sidebar-title">Поделиться</h3>
                    <div class="contest-share-buttons">
                        <a href="https://www.facebook.com/sharer/sharer.php?u=<?php echo urlencode(get_permalink()); ?>" target="_blank" class="share-button facebook">Facebook</a>
                        <a href="https://twitter.com/intent/tweet?url=<?php echo urlencode(get_permalink()); ?>&text=<?php echo urlencode(get_the_title()); ?>" target="_blank" class="share-button twitter">Twitter</a>
                        <a href="https://t.me/share/url?url=<?php echo urlencode(get_permalink()); ?>&text=<?php echo urlencode(get_the_title()); ?>" target="_blank" class="share-button telegram">Telegram</a>
                    </div>
                </div>
            </aside>
        </div>

                
        <!-- Контейнер для загрузки формы через AJAX -->
        <div id="registration-form-container" class="account-registration-form" style="display: none;">
            <!-- Форма будет загружена сюда через AJAX -->
            <div class="loading-indicator">Загрузка формы...</div>
        </div>

        <!-- Добавить сразу после блока с участниками конкурса -->
        <?php if (!empty($contest_participants) && count($contest_participants) >= 2): ?>
            <!-- Найдите этот блок в single-contest.php -->
            <div class="contest-leaders-chart">
                <div class="chart-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
                    <h2>График лидеров конкурса</h2>
                </div>
                
                <div class="chart-controls">
                    <div class="chart-filter">
                        <label for="leaders_chart_period">Период:</label>
                        <select id="leaders_chart_period">
                            <option value="day">День</option>
                            <option value="week">Неделя</option>
                            <option value="month">Месяц</option>
                            <option value="year">Год</option>
                            <option value="all" selected>Все время</option>
                        </select>
                    </div>
                </div>
                
                <!-- Изменяем классы здесь -->
                <div class="leaders-chart-scroll-container">
                    <div class="leaders-chart-container">
                        <?php 
                        // Получаем количество призовых мест
                        $prizes_count = isset($contest_data['prizes']) ? count($contest_data['prizes']) : 3;
                        // Если призовых мест нет или меньше 3, показываем как минимум 3 лидеров
                        $top_count = max(3, $prizes_count);
                        ?>
                        <canvas id="leadersChart" 
                            data-contest-id="<?php echo get_the_ID(); ?>" 
                            data-nonce="<?php echo wp_create_nonce('leaders_chart_nonce'); ?>"
                            data-top-count="<?php echo esc_attr($top_count); ?>">
                        </canvas>
                        <div id="leaders-chart-loading" class="chart-loading">Загрузка данных...</div>
                    </div>
                </div>
                
                <!-- Легенда под графиком -->
                <div id="leadersChartLegend" class="chart-legend chart-legend-below"></div>
            </div>

        <?php endif; ?>
        <div class="contest-participants">
            <h2>Участники конкурса</h2>
            
            <?php if (!empty($contest_participants)): ?>
                <div class="participants-search">
                    <input type="text" id="search-participant" placeholder="Поиск по номеру счета..." 
                           value="<?php echo is_user_logged_in() && $current_user_account ? esc_attr($current_user_account->account_number) : ''; ?>">
                </div>
                
                <div class="participants-table-container">
                    <table class="participants-table" id="participants-table">
                        <thead>
                            <tr>
                                <th>№</th>
                                <th>Трейдер</th>
                                <th>Счет</th>
                                <th class="sortable" data-sort="balance">Баланс</th>
                                <th class="sortable" data-sort="equity">Средства</th>
                                <th>Сделки</th>
                                <th>Статус</th>
                                <th>Обновлен</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $rank = 1;
                            foreach ($contest_participants as $participant): 
                                $user_info = get_userdata($participant->user_id);
                                
                                // Форматируем время обновления
                                $last_update_time = strtotime($participant->last_update);
                                $current_time = current_time('timestamp');
                                $minutes_ago = round(($current_time - $last_update_time) / 60);
                                
                                // Определяем класс на основе времени: до 3ч зеленый, 3-6ч оранжевый, 6ч+ красный
                                if ($minutes_ago < 180) { // До 3 часов
                                    $time_class = 'recent';
                                } else if ($minutes_ago < 360) { // От 3 до 6 часов
                                    $time_class = 'moderate';
                                } else { // От 6 часов и больше
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
                                
                                // Выделяем счет текущего пользователя
                                $is_current_user = $current_user_account && $current_user_account->id === $participant->id;
                            ?>
                            <tr class="<?php echo $is_current_user ? 'current-user-account' : ''; ?>" data-account="<?php echo esc_attr($participant->account_number); ?>">
                                <td><?php echo $rank++; ?></td>
                                <td><?php 
                                // Используем приоритетный подход к отображению имени
                                $name_to_display = '';
                                
                                // 1. Проверяем user_nicename пользователя
                                if ($user_info && !empty($user_info->user_nicename)) {
                                    $name_to_display = html_entity_decode($user_info->user_nicename, ENT_QUOTES, 'UTF-8');
                                }
                                // 2. Проверяем user_login пользователя
                                else if ($user_info && !empty($user_info->user_login)) {
                                    $name_to_display = html_entity_decode($user_info->user_login, ENT_QUOTES, 'UTF-8');
                                }
                                // 3. Проверяем поле name в объекте участника
                                else if (!empty($participant->name)) {
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
                                
                                if ($user_info) {
                                    echo '<a href="' . esc_url(add_query_arg(array('trader_id' => $participant->user_id), home_url('/trader-statistics/'))) . '" class="trader-name-link">' . esc_html($name_to_display) . '</a>';
                                } else {
                                    echo esc_html($name_to_display);
                                }
                                ?></td>
                                <td>
                                    <a href="<?php echo add_query_arg(['contest_account' => $participant->id, 'contest_id' => get_the_ID()], get_permalink()); ?>">
                                    <?php echo esc_html($participant->account_number); ?>
                                    </a>
                                </td>
                                <td data-value="<?php echo esc_attr($participant->balance); ?>">
                                    <?php echo number_format($participant->balance, 2); ?> <?php echo esc_html($participant->currency); ?>
                                </td>
                                <td data-value="<?php echo esc_attr($participant->equity); ?>">
                                    <?php echo number_format($participant->equity, 2); ?> <?php echo esc_html($participant->currency); ?>
                                </td>
                                <td><?php echo isset($participant->orders_history_total) ? intval($participant->orders_history_total) : 0; ?></td>
                                <td>
                                    <?php if($participant->connection_status === 'connected'): ?>
                                        <span class="status-indicator status-connected"></span>Подключен
                                    <?php elseif($participant->connection_status === 'disqualified'): ?>
                                        <span class="status-indicator status-disqualified"></span>Дисквалифицирован
                                    <?php else: ?>
                                        <span class="status-indicator status-disconnected"></span>Ошибка подключения
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="update-time <?php echo esc_attr($time_class); ?>">
                                        <?php echo esc_html($time_text); ?>
                                    </span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="no-participants">
                    <p>В этом конкурсе пока нет участников. Будьте первым!</p>
                </div>
            <?php endif; ?>
        </div>
    </article>
</div>

<!-- JavaScript для загрузки формы через AJAX -->
<script type="text/javascript">
jQuery(document).ready(function($) {
    // Обработка показа/скрытия формы регистрации
    $('#show-registration-form').on('click', function(e) {
        var $container = $('#registration-form-container');
        
        // Если форма еще не загружена, загружаем её через AJAX
        if ($container.find('form').length === 0) {
            $container.slideDown('fast');
            
            // Отправляем AJAX запрос для загрузки формы
            $.ajax({
                url: '<?php echo admin_url('admin-ajax.php'); ?>',
                type: 'POST',
                data: {
                    action: 'load_registration_form',
                    contest_id: <?php echo get_the_ID(); ?>,
                    nonce: '<?php echo wp_create_nonce('load_registration_form_nonce'); ?>'
                },
                success: function(response) {
                    if (response.success) {
                        // Заменяем индикатор загрузки на форму
                        $container.html(response.data.html);
                    } else {
                        // Показываем сообщение об ошибке
                        $container.html('<div class="error-message">Ошибка загрузки формы: ' + (response.data.message || 'Неизвестная ошибка') + '</div>');
                    }
                },
                error: function() {
                    // Показываем сообщение об ошибке
                    $container.html('<div class="error-message">Ошибка загрузки формы. Пожалуйста, обновите страницу и попробуйте снова.</div>');
                }
            });
        } else {
            // Если форма уже загружена, просто показываем её
            $container.slideDown('fast');
        }
    });
    
    // Делегирование события для кнопки отмены (которая будет добавлена позже)
    $(document).on('click', '#cancel-registration', function() {
        $('#registration-form-container').slideUp('fast');
    });

    // Обработка сворачивания/разворачивания блока дисквалификации
    $('.contest-disqualification-header').on('click', function(e) {
        e.preventDefault();
        var $header = $(this).find('.contest-disqualification-toggle');
        var $content = $('.contest-disqualification-list');
        
        // Переключаем класс active для иконки
        $header.toggleClass('active');
        
        // Переключаем видимость содержимого
        if ($content.is(':visible')) {
            $content.slideUp(300);
        } else {
            $content.slideDown(300);
        }
    });

    // Проверяем, есть ли якорь #disqualification-conditions в URL
    if (window.location.hash === '#disqualification-conditions') {
        // Если есть, разворачиваем блок дисквалификации
        $('.contest-disqualification-toggle').addClass('active');
        $('.contest-disqualification-list').slideDown(300);
        
        // Плавно прокручиваем к блоку
        $('html, body').animate({
            scrollTop: $('#disqualification-conditions').offset().top - 20
        }, 300);
    }
});
</script>

<?php endif; ?>
</div>

<?php get_footer(); ?>

