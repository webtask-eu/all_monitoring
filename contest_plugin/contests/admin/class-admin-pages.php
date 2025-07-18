<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Форматирует время последнего обновления в удобочитаемый вид
 * 
 * @param string $last_update Дата/время последнего обновления в формате MySQL
 * @return string Отформатированная HTML строка со временем
 */
function format_time_ago($last_update) {
    $last_update_time = strtotime($last_update);
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
    
    return '<span class="' . $time_class . '">' . $time_text . '</span>';
}

/**
 * Получает и отображает все активные очереди обновления
 * 
 * @return string HTML с информацией о всех активных очередях
 */
function get_active_update_queues_html() {
    global $wpdb;
    
    // Выберем сначала все конкурсы
    $contests = get_posts([
        'post_type' => 'trader_contests',
        'posts_per_page' => -1,
        'fields' => 'ids'
    ]);
    
    // Добавим "глобальную" очередь (без привязки к конкретному конкурсу)
    $contests[] = 'global';
    
    $html = '';
    $found_queues = false;
    
    foreach ($contests as $contest_id) {
        $contest_key = 'contest_active_queues_' . $contest_id;
        $active_queues = get_option($contest_key, []);
        
        if (!empty($active_queues)) {
            $found_queues = true;
            
            // Если это ID конкурса, получим его название
            $contest_title = ($contest_id === 'global') ? 'Все конкурсы' : get_the_title($contest_id);
            
            $html .= '<div class="active-queue-group">';
            $html .= '<h3>Активные очереди: ' . esc_html($contest_title) . '</h3>';
            $html .= '<table class="wp-list-table widefat fixed striped active-queues-table">';
            $html .= '<thead><tr>
                        <th>ID очереди</th>
                        <th>Всего счетов</th>
                        <th>Обработано</th>
                        <th>Успешно</th>
                        <th>Ошибок</th>
                        <th>Прогресс</th>
                        <th>Начало</th>
                        <th>Статус</th>
                    </tr></thead>';
            $html .= '<tbody>';
            
            foreach ($active_queues as $queue_id => $queue_info) {
                $status_option = $queue_info['status_option'];
                $status_data = get_option($status_option, []);
                
                if (empty($status_data)) {
                    continue; // Пропускаем, если данные о статусе не найдены
                }
                
                // Получаем время начала в удобочитаемом формате
                $start_time = isset($status_data['start_time']) ? date('d.m.Y H:i:s', $status_data['start_time']) : 'Неизвестно';
                
                // Вычисляем прогресс в процентах
                $total = isset($status_data['total']) ? intval($status_data['total']) : 0;
                $completed = isset($status_data['completed']) ? intval($status_data['completed']) : 0;
                $success = isset($status_data['success']) ? intval($status_data['success']) : 0;
                $failed = isset($status_data['failed']) ? intval($status_data['failed']) : 0;
                
                $progress = ($total > 0) ? round(($completed / $total) * 100) : 0;
                
                // Определяем статус
                $status_text = 'Неизвестно';
                $status_class = '';
                $timeout_info = '';
                
                if (isset($status_data['is_running'])) {
                    if ($status_data['is_running']) {
                        $status_text = 'Выполняется';
                        $status_class = 'running';
                    } else {
                        if ($completed >= $total) {
                            $status_text = 'Завершено';
                            $status_class = 'completed';
                        } else {
                            // Проверяем, был ли таймаут
                            if (isset($status_data['timeout']) && $status_data['timeout'] === true) {
                                $status_text = 'Таймаут';
                                $status_class = 'timeout';
                                
                                // Добавляем информацию о причине таймаута
                                if (isset($status_data['timeout_reason'])) {
                                    $timeout_info = '<div class="timeout-reason">Причина: ' . esc_html($status_data['timeout_reason']) . '</div>';
                                }
                            } else {
                                $status_text = 'Остановлено';
                                $status_class = 'stopped';
                            }
                        }
                    }
                }
                
                $html .= '<tr>';
                $html .= '<td>' . esc_html($queue_id) . '</td>';
                $html .= '<td>' . esc_html($total) . '</td>';
                $html .= '<td>' . esc_html($completed) . '</td>';
                $html .= '<td>' . esc_html($success) . '</td>';
                $html .= '<td>' . esc_html($failed) . '</td>';
                $html .= '<td>
                        <div class="progress-bar-container">
                            <div class="progress-bar" style="width:' . esc_attr($progress) . '%"></div>
                            <span class="progress-text">' . esc_html($progress) . '%</span>
                        </div>
                    </td>';
                $html .= '<td>' . esc_html($start_time) . '</td>';
                $html .= '<td><span class="queue-status ' . esc_attr($status_class) . '">' . esc_html($status_text) . '</span>' . $timeout_info . '</td>';
                $html .= '</tr>';
            }
            
            $html .= '</tbody></table></div>';
        }
    }
    
    // Если не найдено ни одной активной очереди
    if (!$found_queues) {
        $html = '<div class="notice notice-info"><p>Активных очередей обновления не найдено.</p></div>';
    } else {
        // Добавляем стили для таблиц и статусов
        $html .= '
        <style>
            .active-queue-group {
                margin-bottom: 20px;
            }
            .active-queues-table {
                border-collapse: collapse;
                width: 100%;
            }
            .progress-bar-container {
                width: 100%;
                background-color: #f1f1f1;
                border-radius: 4px;
                position: relative;
                height: 20px;
            }
            .progress-bar {
                height: 20px;
                background-color: #4CAF50;
                border-radius: 4px;
                position: absolute;
                top: 0;
                left: 0;
            }
            .progress-text {
                position: absolute;
                top: 0;
                left: 0;
                width: 100%;
                text-align: center;
                line-height: 20px;
                color: #000;
                font-weight: bold;
            }
            .queue-status {
                display: inline-block;
                padding: 2px 8px;
                border-radius: 4px;
                font-weight: bold;
            }
            .queue-status.running {
                background-color: #2196F3;
                color: white;
            }
            .queue-status.completed {
                background-color: #4CAF50;
                color: white;
            }
            .queue-status.stopped {
                background-color: #f44336;
                color: white;
            }
            .queue-status.timeout {
                background-color: #ff9800;
                color: white;
            }
            .timeout-reason {
                font-size: 11px;
                color: #666;
                margin-top: 4px;
                font-style: italic;
                line-height: 1.2;
            }
        </style>';
    }
    
    return $html;
}


// Модифицируйте функцию fttradingapi_accounts_page_callback()

function fttradingapi_accounts_page_callback()
{
    // Обработка групповых обновлений, если есть запрос на обновление
    if (isset($_GET['bulk_update']) && $_GET['bulk_update'] === 'accounts') {
        $account_ids = get_transient('accounts_to_update');
        if (!empty($account_ids)) {
            // Обновим статус для отображения
            $status_message = '';
            
            // Здесь можно реализовать страницу с прогрессом обновления
            // или создать нотис об обновлении
            echo '<div class="updated notice is-dismissible"><p>Выбранные счета поставлены в очередь на обновление.</p></div>';
            
            // Очистим транзиент
            delete_transient('accounts_to_update');
        }
    }
    ?>
    <div class="wrap">
        <h1>Все счета</h1>
        
        <div class="active-queues-container">
            <?php echo get_active_update_queues_html(); ?>
        </div>
        
        <!-- Форма добавления из admin-metaboxes.php -->
        <h2>Добавить счёт</h2>
        <table class="form-table">
            <tbody>
                <tr>
                    <th scope="row">
                        <label for="contest_id">Конкурс</label>
                    </th>
                    <td>
                        <select id="contest_id">
                            <?php
                            $contests = get_posts(['post_type' => 'trader_contests']);
                            foreach($contests as $contest) {
                                echo '<option value="' . $contest->ID . '">' . $contest->post_title . '</option>';
                            }
                            ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="account_number">Номер счета</label></th>
                    <td><input type="text" id="account_number" class="regular-text"></td>
                </tr>
                <tr>
                    <th scope="row"><label for="password">Пароль</label></th>
                    <td><input type="text" id="password" class="regular-text"></td>
                </tr>
                <tr>
                    <th scope="row"><label for="server">Сервер</label></th>
                    <td><input type="text" id="server" value="MetaQuotes-Demo" class="regular-text"></td>
                </tr>
                <tr>
                    <th scope="row"><label for="terminal">Терминал</label></th>
                    <td><input type="text" id="terminal" value="metatrader4" class="regular-text"></td>
                </tr>
                <tr>
                    <td colspan="2">
                        <button type="button" id="register_account" class="button button-primary">Добавить счет</button>
                        <span id="register_status"></span>
                    </td>
                </tr>
            </tbody>
        </table>

        <hr>

        <?php
        // Создаем экземпляр нашей таблицы
        $accounts_table = new Contest_Accounts_List_Table();
        
        // Удаляем отдельную форму фильтрации - она теперь в таблице

        // Форма для отображения таблицы и групповых действий
        ?>
        <form method="post">
            <input type="hidden" name="post_type" value="trader_contests">
            <input type="hidden" name="page" value="trader_contests_accounts">
            <?php
            // Подготавливаем данные
            $accounts_table->prepare_items();
            
            // Поле поиска и другие фильтры
            $accounts_table->search_box('Поиск счетов', 'search_id');
            
            // Отображаем таблицу
            $accounts_table->display();
            ?>
        </form>
        </div>
        <?php
}


function fttradingapi_edit_account_page_callback() {
    if (!isset($_GET['id'])) {
        wp_die('ID счета не указан');
    }
    
    global $wpdb;
    $table_name = $wpdb->prefix . 'contest_members';
    $account = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $table_name WHERE id = %d",
        intval($_GET['id'])
    ));

    if (!$account) {
        wp_die('Счет не найден');
    }

    ?>
    <div class="wrap">
        <h1>Редактирование счета #<?php echo esc_html($account->account_number); ?></h1>
        <form id="edit_account_form">
            <input type="hidden" id="account_id" value="<?php echo esc_attr($account->id); ?>">
            <input type="hidden" id="account_number" value="<?php echo esc_attr($account->account_number); ?>">
            <table class="form-table">
                <tr>
                    <th><label for="edit_contest_id">Конкурс</label></th>
                    <td>
                        <select id="edit_contest_id" class="regular-text">
                            <?php
                            $contests = get_posts(['post_type' => 'trader_contests', 'posts_per_page' => -1]);
                            foreach($contests as $contest) {
                                $selected = ($contest->ID == $account->contest_id) ? 'selected' : '';
                                echo '<option value="' . $contest->ID . '" ' . $selected . '>' . $contest->post_title . '</option>';
                            }
                            ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th><label for="edit_password">Пароль</label></th>
                    <td><input type="text" id="edit_password" value="<?php echo str_replace('"', '&quot;', $account->password); ?>" class="regular-text"></td>
                </tr>
                <tr>
                    <th><label for="edit_server">Сервер</label></th>
                    <td><input type="text" id="edit_server" value="<?php echo esc_attr($account->server); ?>" class="regular-text"></td>
                </tr>
                <tr>
                    <th><label for="edit_terminal">Терминал</label></th>
                    <td><input type="text" id="edit_terminal" value="<?php echo esc_attr($account->terminal); ?>" class="regular-text"></td>
                </tr>
            </table>
            <p class="submit">
                <button type="button" id="save_account" class="button button-primary">Сохранить изменения</button>
                <span id="edit_status"></span>
                <button type="button" id="delete_account" class="button">Удалить счет</button>
            </p>
        </form>
    </div>
    <?php
}


function fttradingapi_view_account_page_callback() {
    if (!isset($_GET['id'])) {
        wp_die('ID счета не указан');
    }
    
    $account_id = intval($_GET['id']);
    
    global $wpdb;
    $table_name = $wpdb->prefix . 'contest_members';
    $account = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $table_name WHERE id = %d",
        $account_id
    ));

    if (!$account) {
        wp_die('Счет не найден');
    }

    // Локализация скрипта с nonce (добавьте в функцию, отвечающую за вывод страницы)
    wp_localize_script('ft-trader-admin', 'ftTraderAdmin', array(
        'nonce' => wp_create_nonce('ft_trader_nonce')
    ));

      // Получаем открытые ордера
      $orders = new Account_Orders();
      $open_orders = $orders->get_account_orders($account->id);
      
      // ВАЖНО: Инициализируем переменные для пагинации ДО их использования
      $per_page = 20; // Количество записей на странице
      $current_page = isset($_GET['history_page']) ? max(1, intval($_GET['history_page'])) : 1;
      
      // Получаем историю сделок с пагинацией
      $history_data = $orders->get_account_order_history($account->id, $per_page, $current_page);
      $order_history = $history_data['results'];
    
    ?>
    <div class="wrap">
    <h1>Информация о счете #<?php echo esc_html($account->account_number); ?></h1>
    
    <div class="account-actions" style="margin: 15px 0; padding: 10px; background: #f8f8f8; border-left: 4px solid #2271b1; display: flex; gap: 10px; align-items: center;">
        <button id="update_account_data" class="button button-primary" data-account-id="<?php echo $account_id; ?>">
            <span class="dashicons dashicons-update" style="margin-top: 3px;"></span> 
            Обновить данные
        </button>
        <a href="<?php echo admin_url('edit.php?post_type=trader_contests&page=trader_contests_accounts_edit&id=' . $account_id); ?>" class="button button-secondary">
            <span class="dashicons dashicons-edit" style="margin-top: 3px;"></span>
            Редактировать счет
        </a>
        <?php if (current_user_can('manage_options')): ?>
        <button id="clear_order_history" class="button button-secondary" data-account-id="<?php echo $account_id; ?>">
            <span class="dashicons dashicons-trash" style="margin-top: 3px;"></span>
            Удалить сделки
        </button>
        <?php endif; ?>
        <span id="update_status"></span>
    </div>
    
    <div class="account-info-wrapper">
            <!-- Основная информация -->
            <div class="account-section">
                <h2>Основные данные</h2>
                <table class="widefat">
                    <tr>
                        <th>Номер счета:</th>
                        <td><?php echo esc_html($account->account_number); ?></td>
                    </tr>

                    <tr>
                        <th>Пароль:</th>
                        <td><?php echo htmlspecialchars($account->password, ENT_NOQUOTES, 'UTF-8'); ?></td>
                    </tr>
                    <!-- Добавляем новую строку для статуса подключения -->
                    <tr>
                        <th>Статус подключения:</th>
                            <td>
                                <?php if($account->connection_status === 'connected'): ?>
                                    <span class="status-indicator connected">Подключен</span>
                                <?php else: ?>
                                    <span class="status-indicator disconnected" title="<?php echo esc_attr($account->error_description); ?>">Ошибка подключения</span>
                                    
                                    <?php if(!empty($account->error_description)): ?>
                                        <div class="error-details"><?php echo esc_html($account->error_description); ?></div>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </td>
                    </tr>
                    <!-- Добавляем новую строку для времени обновления -->
                    <tr>
                        <th>Последнее обновление:</th>
                        <td class="update-time-cell" data-updated="<?php echo esc_attr($account->last_update); ?>">
                            <?php echo format_time_ago($account->last_update); ?>
                        </td>
                    </tr>
                    <tr>
                        <th>IP адрес регистрации:</th>
                        <td><?php echo !empty($account->user_ip) ? esc_html($account->user_ip) : 'Не определен'; ?></td>
                    </tr>

                    <tr>
                        <th>Страна:</th>
                        <td>
                            <?php 
                            if (!empty($account->country_code)) {
                                // Показываем флаг страны, используя код напрямую из БД
                                echo '<img src="https://flagcdn.com/16x12/' . esc_attr($account->country_code) . '.png" 
                                    alt="' . esc_attr($account->user_country) . '" 
                                    title="' . esc_attr($account->user_country) . '" 
                                    width="16" height="12" style="margin-right: 5px; vertical-align: middle;" />';
                                echo esc_html($account->user_country);
                            } else {
                                echo 'Не определена';
                            }
                            ?>
                        </td>
                    </tr>
                <tr>
                    <th>Баланс:</th>
                        <td><?php echo number_format($account->balance, 2); ?> <?php echo $account->currency; ?></td>
                    </tr>
                    <tr>
                        <th>Средства:</th>
                        <td><?php echo number_format($account->equity, 2); ?> <?php echo $account->currency; ?></td>
                    </tr>
                    <tr>
                        <th>Прибыль:</th>
                        <td><?php echo number_format($account->profit, 2); ?> <?php echo $account->currency; ?></td>
                    </tr>
                    <tr>
                        <th>Использованная маржа:</th>
                        <td><?php echo number_format($account->margin, 2); ?> <?php echo $account->currency; ?></td>
                    </tr>
                    <tr>
                        <th>Плавающая прибыль/убыток (Floating P/L):</th>
                        <td><?php echo number_format($account->profit, 2); ?> <?php echo $account->currency; ?></td>
                    </tr>
                    <tr>
                        <th>Торговое плечо:</th>
                        <td>1:<?php echo intval($account->leverage); ?></td>
                    </tr>
                    <tr>
                        <th>Общее количество открытых ордеров (Total Orders):</th>
                        <td><?php echo intval($account->orders_total); ?></td>
                    </tr>
                    <tr>
                        <th>Брокер:</th>
                        <td><?php echo esc_html($account->broker); ?></td>
                    </tr>
                    <tr>
                        <th>Имя:</th>
                        <td><?php echo esc_html($account->name); ?></td>
                    </tr>
                    <tr>
                        <th>Тип счета:</th>
                        <td><?php echo esc_html($account->account_type); ?></td>
                    </tr>
                    <tr>
                        <th>Всего ордеров:</th>
                        <td><?php echo intval($account->orders_total); ?></td>
                    </tr>
                    <tr>
                        <th>Всего сделок в истории:</th>
                        <td><?php echo intval($account->orders_history_total); ?></td>
                    </tr>
                    <tr>
                        <th>Прибыль по истории:</th>
                        <td><?php echo number_format($account->orders_history_profit, 2); ?> <?php echo $account->currency; ?></td>
                    </tr>
                </table>
            </div>

            <!-- График изменения баланса и средств -->
            <div class="account-section">
                <h2>График баланса и средств</h2>
                
                <div class="chart-controls">
                    <select id="chart_period" class="chart-filter">
                        <option value="hour">За час</option>
                        <option value="day">За день</option>
                        <option value="week">За неделю</option>
                        <option value="month" selected>За месяц</option>
                        <option value="year">За год</option>
                        <option value="all">За всё время</option>
                    </select>
                    <div id="chartLegend" class="chart-legend"></div>
                </div>
                
                <!-- Добавляем обертку с прокруткой -->
                <div class="chart-scroll-container">
                    <div class="chart-container">
                        <div id="chart-loading">Загрузка данных...</div>
                        <canvas id="accountChart"></canvas>
                    </div>
                </div>
                
                <!-- Опционально: отладочная информация -->
                <div class="debug-info">
                    <h4>Отладочная информация графика</h4>
                    <button id="toggleDebugInfo" class="button">Показать/скрыть исходные данные</button>
                    <div class="debug-info-content"></div>
                </div>
            </div>

            <?php
            // Получаем открытые ордера
            $orders = new Account_Orders();
            $open_orders = $orders->get_account_orders($account->id);
            ?>
            <div class="account-section">
                <h2>Открытые позиции</h2>
                <table class="widefat">
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
                            <th>Своп</th>
                            <th>Комиссия</th>
                            <th>Прибыль</th>
                            <th>Комментарий</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!empty($open_orders)): ?>
                        <?php foreach ($open_orders as $order): ?>
                            <tr>
                                <td><?php echo esc_html($order->ticket); ?></td>
                                <td><?php echo esc_html($order->symbol); ?></td>
                                <td><?php 
                                    $buy_types = ['buy', 'buylimit', 'buystop'];
                                    $sell_types = ['sell', 'selllimit', 'sellstop'];
                                    if (in_array($order->type, $buy_types)) {
                                        $type_class = 'order-buy';
                                    } elseif (in_array($order->type, $sell_types)) {
                                        $type_class = 'order-sell';
                                    } else {
                                        $type_class = 'order-unknown';
                                    }
                                    echo "<span class='{$type_class}'>" . esc_html(strtoupper($order->type)) . "</span>"; 
                                ?></td>
                                <td><?php echo number_format($order->lots, 2); ?></td>
                                <td><?php echo date('Y.m.d H:i:s', strtotime($order->open_time)); ?></td>
                                <td><?php echo number_format($order->open_price, 5); ?></td>
                                <td><?php echo $order->sl ? number_format($order->sl, 5) : '—'; ?></td>
                                <td><?php echo $order->tp ? number_format($order->tp, 5) : '—'; ?></td>
                                <td><?php echo number_format($order->swap, 2); ?></td>
                                <td><?php echo number_format($order->commission, 2); ?></td>
                                <td class="<?php echo $order->profit >= 0 ? 'profit-positive' : 'profit-negative'; ?>">
                                    <?php echo number_format($order->profit, 2); ?>
                                </td>
                                <td><?php echo esc_html($order->comment); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="12">Нет открытых позиций</td>
                        </tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>


            <!-- История сделок -->
            <div class="account-section">
                <h2>История сделок</h2>
                <div class="history-filters">
                    <form method="get" action="" style="display:inline-block;">
                        <?php 
                        // Сохраняем все существующие GET параметры
                        foreach ($_GET as $key => $value) {
                            if ($key !== 'history_page') {
                                echo '<input type="hidden" name="' . esc_attr($key) . '" value="' . esc_attr($value) . '">';
                            }
                        }
                        ?>
                        <select name="history_page" class="history-filter" onchange="this.form.submit()">
                            <?php for ($i = 1; $i <= $history_data['total_pages']; $i++): ?>
                                <option value="<?php echo $i; ?>" <?php selected($current_page, $i); ?>>
                                    Страница <?php echo $i; ?> из <?php echo $history_data['total_pages']; ?>
                                </option>
                            <?php endfor; ?>
                        </select>
                    </form>
                </div>

                <?php

                ?>
                <table class="widefat">
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
                            <th>Своп</th>
                            <th>Комиссия</th>
                            <th>Прибыль</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!empty($order_history)): ?>
                        <?php foreach ($order_history as $order): ?>
                            <tr>
                                <td><?php echo esc_html($order->ticket); ?></td>
                                <td><?php echo esc_html($order->symbol); ?></td>
                                <td><?php 
                                    $type_class = in_array($order->type, ['buy', 'balance']) ? 'order-buy' : 'order-sell';
                                    echo "<span class='{$type_class}'>" . esc_html(strtoupper($order->type)) . "</span>"; 
                                ?></td>
                                <td><?php echo number_format($order->lots, 2); ?></td>
                                <td><?php echo date('Y.m.d H:i:s', strtotime($order->open_time)); ?></td>
                                <td><?php echo date('Y.m.d H:i:s', strtotime($order->close_time)); ?></td>
                                <td><?php echo number_format($order->open_price, 5); ?></td>
                                <td><?php echo number_format($order->close_price, 5); ?></td>
                                <td><?php echo number_format($order->swap, 2); ?></td>
                                <td><?php echo number_format($order->commission, 2); ?></td>
                                <td class="<?php echo $order->profit >= 0 ? 'profit-positive' : 'profit-negative'; ?>">
                                    <?php echo number_format($order->profit, 2); ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="11">История сделок пуста</td>
                        </tr>
                    <?php endif; ?>                    </tbody>
                </table>
            </div>

            <!-- История изменений счета -->
            <div class="account-section">
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

                    <!-- Добавляем кнопку очистки истории -->
                    <button id="clear_history" class="button button-secondary" data-account-id="<?php echo $account_id; ?>">
                        <span class="dashicons dashicons-trash"></span> Очистить историю
                    </button>
                    
                </div>

                <div id="history_table_wrapper">
                    <!-- Сюда загрузится таблица -->
                </div>
            </div>
        </div>

    
    <?php
}