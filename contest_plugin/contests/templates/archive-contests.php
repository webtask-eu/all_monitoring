<?php
/**
 * Шаблон архивной страницы конкурсов трейдеров
 */
get_header();

// Получаем общую статистику по всем конкурсам
global $wpdb;
$table_name = $wpdb->prefix . 'contest_members';

// Общее количество участников
$total_participants = $wpdb->get_var("SELECT COUNT(*) FROM $table_name");

// Общий призовой фонд
$grand_total_prize_fund = 0;
$contests_query_all = new WP_Query([
    'post_type' => 'trader_contests',
    'posts_per_page' => -1,
    'fields' => 'ids'
]);

// Массив для сбора данных по спонсорам
$sponsors = [];

if ($contests_query_all->have_posts()) {
    foreach ($contests_query_all->posts as $contest_id) {
        // Сначала проверяем новый формат призовых мест
        $contest_data = get_post_meta($contest_id, '_fttradingapi_contest_data', true);
        $prizes = isset($contest_data['prizes']) ? $contest_data['prizes'] : array();

        // Собираем информацию о спонсорах
        $sponsor = isset($contest_data['sponsor']) ? $contest_data['sponsor'] : 'Tickmill';
        $sponsor_logo = isset($contest_data['sponsor_logo']) ? $contest_data['sponsor_logo'] : '';
        
        // Сохраняем спонсора и его логотип
        if (!isset($sponsors[$sponsor])) {
            $sponsors[$sponsor] = [
                'name' => $sponsor,
                'logo' => $sponsor_logo,
                'count' => 1
            ];
        } else {
            $sponsors[$sponsor]['count']++;
        }

        if (!empty($prizes)) {
            // Суммируем призы из структурированных данных
            foreach ($prizes as $prize) {
                // Извлекаем числовое значение из строки (например, "$1000" -> 1000)
                $amount = preg_replace('/[^0-9.]/', '', $prize['amount']);
                $grand_total_prize_fund += floatval($amount);
            }
        } else {
            // Если нет структурированных данных, используем старый формат
            $prize_fund = get_post_meta($contest_id, '_contest_prize_fund', true);
            if (!empty($prize_fund)) {
                // Извлекаем числовое значение из строки (например, "$1000" -> 1000)
                $prize_value = preg_replace('/[^0-9.]/', '', $prize_fund);
                $grand_total_prize_fund += floatval($prize_value);
            }
        }
    }
}

// Сортируем спонсоров по количеству конкурсов (по убыванию)
usort($sponsors, function($a, $b) {
    return $b['count'] - $a['count'];
});

// Берем только топ-5 спонсоров
$top_sponsors = array_slice($sponsors, 0, 5);

// Количество активных конкурсов
$active_contests = $wpdb->get_var(
    "SELECT COUNT(*) FROM {$wpdb->posts} 
    WHERE post_type = 'trader_contests' 
    AND post_status = 'publish'"
);

// Получаем топ-5 трейдеров по среднему проценту прибыли во всех конкурсах
// Сначала группируем по user_id, чтобы получить данные по каждому трейдеру
$top_traders = $wpdb->get_results(
    "SELECT 
        m.user_id, 
        COUNT(DISTINCT m.contest_id) as contests_count,
        AVG(m.profit_percent) as avg_profit_percent
    FROM $table_name m
    JOIN {$wpdb->posts} p ON m.contest_id = p.ID
    WHERE p.post_status = 'publish'
    GROUP BY m.user_id
    ORDER BY avg_profit_percent DESC
    LIMIT 5"
);

// Для каждого трейдера получаем детальную информацию о его участии в конкурсах
if (!empty($top_traders)) {
    foreach ($top_traders as $key => $trader) {
        $trader_contests = $wpdb->get_results($wpdb->prepare(
            "SELECT 
                m.contest_id, 
                m.profit_percent,
                p.post_title as contest_title
            FROM $table_name m
            JOIN {$wpdb->posts} p ON m.contest_id = p.ID
            WHERE m.user_id = %d
            ORDER BY m.profit_percent DESC",
            $trader->user_id
        ));
        
        // Добавляем информацию о конкурсах к данным трейдера
        $top_traders[$key]->contests = $trader_contests;
        
        // Получаем данные о пользователе WordPress
        $user_info = get_userdata($trader->user_id);
        
        // Используем display_name, если доступен, иначе nicename, логин или дефолтное имя
        if ($user_info) {
            if (!empty($user_info->display_name)) {
                $top_traders[$key]->display_name = $user_info->display_name;
            } elseif (!empty($user_info->user_nicename)) {
                $top_traders[$key]->display_name = $user_info->user_nicename;
            } elseif (!empty($user_info->user_login)) {
                $top_traders[$key]->display_name = $user_info->user_login;
            } else {
                $top_traders[$key]->display_name = 'Трейдер #' . $trader->user_id;
            }
        } else {
            $top_traders[$key]->display_name = 'Трейдер #' . $trader->user_id;
        }
    }
}

// Функция для правильного склонения слова "конкурс"
function plural_form_contests($n) {
    $forms = array('конкурс', 'конкурса', 'конкурсов');
    
    $n = abs($n) % 100;
    $n1 = $n % 10;
    
    if ($n > 10 && $n < 20) {
        return $forms[2];
    }
    
    if ($n1 > 1 && $n1 < 5) {
        return $forms[1];
    }
    
    if ($n1 == 1) {
        return $forms[0];
    }
    
    return $forms[2];
}
?>

<div class="contests-archive-container">

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
                            <span class="breadcrumb-active" itemprop="name">Конкурсы</span>
                        </a>
                        <meta content="1" itemprop="position">
                    </span>
                </li>
            </ul>
        </div>
    </div>

    <section class="section_offset">
        <div class="fx_content-title16 flex_title clearfix">
            <span class="fx_content-title-link"><?php echo post_type_archive_title('', false); ?></span>
        </div>
    </section>

    <?php if (have_posts()): ?>
        <div class="contests-layout">
            <div class="contests-main-content">
                <div class="contests-grid">
                    <?php while (have_posts()):
                        the_post();
                        // Получаем метаданные конкурса
                        $start_date = get_post_meta(get_the_ID(), '_contest_start_date', true);
                        $end_date = get_post_meta(get_the_ID(), '_contest_end_date', true);
                        $prize_fund = get_post_meta(get_the_ID(), '_contest_prize_fund', true);
                        $participants_count = get_post_meta(get_the_ID(), '_contest_participants_count', true);

                        // Подсчитываем реальное количество участников
                        global $wpdb;
                        $table_name = $wpdb->prefix . 'contest_members';
                        $real_participants = $wpdb->get_var($wpdb->prepare(
                            "SELECT COUNT(*) FROM $table_name WHERE contest_id = %d",
                            get_the_ID()
                        ));

                        // Определяем статус конкурса
                        $contest_data = get_post_meta(get_the_ID(), '_fttradingapi_contest_data', true);
                        $saved_status = isset($contest_data['contest_status']) ? $contest_data['contest_status'] : '';
                        $is_archived = isset($contest_data['is_archived']) ? $contest_data['is_archived'] : '0';

                        // Если конкурс архивный, изменяем статус на "archived"
                        if ($is_archived == '1') {
                            $status = 'archived';
                            $status_text = 'АРХИВ';
                        }
                        // Иначе используем сохраненный статус, если он есть
                        else if (!empty($saved_status)) {
                            if ($saved_status == 'active') {
                                $status = 'active';
                                $status_text = 'АКТИВЕН';
                            } elseif ($saved_status == 'finished') {
                                $status = 'completed';
                                $status_text = 'ЗАВЕРШЕН';
                            } elseif ($saved_status == 'draft') {
                                $status = 'upcoming';
                                $status_text = 'СКОРО';
                            }
                        } else {
                            // Если статус не сохранен, определяем по датам
                            $current_time = current_time('timestamp');
                            $start_timestamp = strtotime($start_date);
                            $end_timestamp = strtotime($end_date);

                            if ($current_time < $start_timestamp) {
                                $status = 'upcoming';
                                $status_text = 'СКОРО';
                            } elseif ($current_time > $end_timestamp) {
                                $status = 'completed';
                                $status_text = 'ЗАВЕРШЕН';
                            } else {
                                $status = 'active';
                                $status_text = 'АКТИВЕН';
                            }
                        }


                        // Получаем данные о призовых местах
                        $contest_data = get_post_meta(get_the_ID(), '_fttradingapi_contest_data', true);
                        $prizes = isset($contest_data['prizes']) ? $contest_data['prizes'] : array();

                        // Рассчитываем общий призовой фонд для текущего конкурса
                        $contest_prize_fund = 0;
                        if (!empty($prizes)) {
                            foreach ($prizes as $prize) {
                                // Извлекаем числовое значение из строки (например, "$1000" -> 1000)
                                $amount = preg_replace('/[^0-9.]/', '', $prize['amount']);
                                $contest_prize_fund += floatval($amount);
                            }
                        } elseif ($prize_fund) {
                            // Если используется старый формат
                            $contest_prize_fund = preg_replace('/[^0-9.]/', '', $prize_fund);
                        }

                        // Получаем спонсора конкурса (предполагаем, что это поле есть в метаданных)
                        $sponsor = isset($contest_data['sponsor']) ? $contest_data['sponsor'] : 'Tickmill';
                        $sponsor_logo = isset($contest_data['sponsor_logo']) ? $contest_data['sponsor_logo'] : '';
                        ?>
                        <a href="<?php the_permalink(); ?>" class="contest-card-link">
                            <div class="contest-card-new <?php echo esc_attr($status); ?>">
                                <div class="contest-status-badge-new <?php echo esc_attr($status); ?>">
                                    <?php echo esc_html($status_text); ?>
                                </div>

                                <h2 class="contest-title-new">
                                    <?php the_title(); ?>
                                </h2>

                                <div class="contest-data-block">
                                    <div class="contest-data-item">
                                        <span class="contest-data-label">ПРИЗОВОЙ ФОНД</span>
                                        <span
                                            class="contest-data-value prize-value">$<?php echo number_format($contest_prize_fund, 0, '.', ' '); ?></span>
                                    </div>

                                    <?php if (!empty($prizes)): ?>
                                        <div class="contest-data-item">
                                            <span class="contest-data-label">ПРИЗОВЫХ МЕСТ</span>
                                            <span class="contest-data-value"><?php echo count($prizes); ?></span>
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <div class="contest-sponsor">
                                    <?php
                                    // Получаем логотип спонсора из метаданных конкурса
                                    $contest_data = get_post_meta(get_the_ID(), '_fttradingapi_contest_data', true);
                                    $sponsor = isset($contest_data['sponsor']) ? $contest_data['sponsor'] : 'Tickmill';
                                    $sponsor_logo = isset($contest_data['sponsor_logo']) ? $contest_data['sponsor_logo'] : '';

                                    if (!empty($sponsor_logo)):
                                        ?>
                                        <img src="<?php echo esc_url($sponsor_logo); ?>" alt="<?php echo esc_attr($sponsor); ?>"
                                            class="sponsor-logo">
                                    <?php else: ?>
                                        <img src="<?php echo esc_url(plugins_url('/assets/images/tickmill-logo.png', dirname(__FILE__))); ?>"
                                            alt="Tickmill" class="sponsor-logo">
                                    <?php endif; ?>
                                    <span class="sponsor-text">Спонсор:
                                        <strong><?php echo esc_html($sponsor); ?></strong></span>
                                </div>

                            </div>
                        </a>
                    <?php endwhile; ?>
                </div>

                <?php the_posts_pagination([
                    'prev_text' => '&larr; Предыдущая',
                    'next_text' => 'Следующая &rarr;',
                    'class' => 'contests-pagination',
                    'screen_reader_text' => ' ',
                ]); ?>
            </div>

            <!-- Боковая панель с общей статистикой -->
            <div class="contests-stats-container">
                <div class="contests-sidebar">
                    <h3 class="sidebar-title">Общая статистика</h3>

                    <div class="stats-item">
                        <span class="stats-label">Общий призовой фонд</span>
                        <span class="stats-value prize animated-counter" id="total-prize-fund"
                            data-value="<?php echo esc_attr($grand_total_prize_fund); ?>">$<?php echo number_format($grand_total_prize_fund, 0, '.', ' '); ?></span>
                        <span class="stats-description">Суммарный призовой фонд всех конкурсов</span>
                    </div>

                    <div class="stats-item">
                        <span class="stats-label">Всего участников</span>
                        <span class="stats-value animated-counter" id="total-participants"
                            data-value="<?php echo esc_attr($total_participants); ?>"><?php echo number_format($total_participants, 0, '.', ' '); ?></span>
                        <span class="stats-description">Количество зарегистрированных участников</span>
                    </div>

                    <div class="stats-item">
                        <span class="stats-label">Активных конкурсов</span>
                        <span class="stats-value highlight animated-counter" id="active-contests"
                            data-value="<?php echo esc_attr($active_contests); ?>"><?php echo number_format($active_contests, 0, '.', ' '); ?></span>
                        <span class="stats-description">Количество текущих конкурсов</span>
                    </div>

                    <div class="top-leaders">
                        <h3 class="sidebar-title">Лучшие трейдеры</h3>

                        <?php if (!empty($top_traders)): ?>
                            <?php foreach ($top_traders as $index => $trader):
                                // Форматируем средний процент как целое число без разделителей
                                $avg_profit_formatted = number_format($trader->avg_profit_percent, 0, '', '');
                                $profit_class = $trader->avg_profit_percent >= 0 ? 'positive' : 'negative';
                                $rank_class = 'top-' . ($index + 1);
                                
                                // Формируем подсказку для трейдера с улучшенной структурой HTML
                                $tooltip = '<div class="trader-tooltip-title">Участие в ' . $trader->contests_count . ' ' . 
                                       plural_form_contests($trader->contests_count) . ':</div>';
                                
                                foreach ($trader->contests as $contest) {
                                    // Форматируем процент прибыли в каждом конкурсе как целое число
                                    $contest_profit_formatted = number_format($contest->profit_percent, 0, '', '');
                                    $contest_profit_class = $contest->profit_percent >= 0 ? 'positive' : 'negative';
                                    
                                    $tooltip .= '<div class="trader-tooltip-contest">' .
                                               '<div class="trader-tooltip-name">' . esc_html($contest->contest_title) . '</div>' .
                                               '<div class="trader-tooltip-profit ' . $contest_profit_class . '">' . 
                                               $contest_profit_formatted . '%</div>' .
                                               '</div>';
                                }
                                ?>
                                <div class="leader-item">
                                    <div class="leader-rank <?php echo esc_attr($rank_class); ?>">
                                        <?php echo esc_html($index + 1); ?></div>
                                    <div class="leader-info">
                                        <div class="leader-name"><?php echo esc_html($trader->display_name); ?></div>
                                        <div class="leader-contest"><?php echo $trader->contests_count . ' ' . 
                                            plural_form_contests($trader->contests_count); ?></div>
                                    </div>
                                    <div class="leader-profit <?php echo esc_attr($profit_class); ?>" 
                                         data-tooltip-content="<?php echo esc_attr($tooltip); ?>">
                                        <?php echo esc_html($avg_profit_formatted); ?>%
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <p>Нет данных о лидерах</p>
                        <?php endif; ?>
                    </div>
                    
                    <?php if (!empty($top_sponsors)): ?>
                    <div class="top-sponsors">
                        <h3 class="sidebar-title">Топ спонсоров</h3>
                        
                        <?php foreach ($top_sponsors as $index => $sponsor): ?>
                            <div class="sponsor-item">
                                <div class="sponsor-logo-wrap">
                                    <?php if (!empty($sponsor['logo'])): ?>
                                        <img src="<?php echo esc_url($sponsor['logo']); ?>" alt="<?php echo esc_attr($sponsor['name']); ?>" class="sponsor-logo-small">
                                    <?php else: ?>
                                        <div class="sponsor-no-logo"><?php echo substr($sponsor['name'], 0, 1); ?></div>
                                    <?php endif; ?>
                                </div>
                                <div class="sponsor-info">
                                    <div class="sponsor-name"><?php echo esc_html($sponsor['name']); ?></div>
                                    <div class="sponsor-contests-count">
                                        <?php echo esc_html($sponsor['count']); ?> <?php echo plural_form_contests($sponsor['count']); ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

    <?php else: ?>
        <div class="no-contests-found">
            <p>В данный момент нет активных конкурсов.</p>
        </div>
    <?php endif; ?>
</div>

<?php get_footer(); ?>