<?php
if (!class_exists('WP_List_Table')) {
    require_once(ABSPATH . 'wp-admin/includes/class-wp-list-table.php');
}

class Contest_Accounts_List_Table extends WP_List_Table {
    
    /**
     * Конструктор класса
     */
    public function __construct() {
        parent::__construct([
            'singular' => 'account',    // Единственное число
            'plural'   => 'accounts',   // Множественное число
            'ajax'     => false,         // Поддержка AJAX для сортировки?
            'class'    => ['widefat', 'fixed', 'striped', 'accounts-table', 'responsive'],  // Добавляем класс 'responsive'
        ]);
    }

        /**
     * Переопределяем метод вывода таблицы, чтобы убрать нижние заголовки столбцов
     */
    public function display() {
        $singular = $this->_args['singular'];

        $this->display_tablenav('top');

        $this->screen->render_screen_reader_content('heading_list');
        ?>
        <table class="wp-list-table <?php echo implode(' ', $this->get_table_classes()); ?>">
            <thead>
            <tr>
                <?php $this->print_column_headers(); ?>
            </tr>
            </thead>

            <tbody id="the-list"<?php
            if ($singular) {
                echo " data-wp-lists='list:$singular'";
            } ?>>
            <?php $this->display_rows_or_placeholder(); ?>
            </tbody>

            <!-- Здесь мы убираем вывод нижних заголовков (thead) -->

        </table>
        <?php
        $this->display_tablenav('bottom');
        ?>
        <script type="text/javascript">
            jQuery(document).ready(function($) {
                console.log('[DEBUG-TABLE] Инициализация обработчиков в таблице');
                
                // Правильные селекторы для чекбоксов
                var headerCheckbox = $('.wp-list-table thead .check-column input[type="checkbox"], #cb-select-all-1');
                var rowCheckboxes = $('.wp-list-table tbody .column-cb input[type="checkbox"], .wp-list-table tbody .cb input[type="checkbox"]');
                
                console.log('[DEBUG-TABLE] Главный чекбокс найден:', headerCheckbox.length, 'ID:', headerCheckbox.attr('id'));
                console.log('[DEBUG-TABLE] Чекбоксы в строках найдены:', rowCheckboxes.length);
                
                // Обработчик клика на главный чекбокс
                headerCheckbox.on('click', function() {
                    var isChecked = $(this).prop('checked');
                    console.log('[DEBUG-TABLE] Клик главного чекбокса, состояние:', isChecked);
                    
                    // Применяем то же состояние ко всем чекбоксам в строках
                    rowCheckboxes.prop('checked', isChecked);
                    console.log('[DEBUG-TABLE] Установлено состояние для', rowCheckboxes.length, 'чекбоксов');
                    
                    // Подсвечиваем строки для наглядности
                    if (isChecked) {
                        $('.wp-list-table tbody tr').addClass('selected-row');
                    } else {
                        $('.wp-list-table tbody tr').removeClass('selected-row');
                    }
                });
                
                // Стиль для выделенных строк
                $('<style>.selected-row { background-color: #f7fcfe !important; }</style>').appendTo('head');
            });
        </script>
        <?php
    }


    /**
     * Переопределяем стандартный метод search_box, чтобы он ничего не выводил
     */
    public function search_box($text, $input_id) {
        // Этот метод намеренно пустой, чтобы предотвратить стандартный вывод поля поиска
        // Мы добавим поле поиска непосредственно в extra_tablenav
    }

    /**
     * Отображение дополнительных элементов в навигации таблицы
    */
    public function extra_tablenav($which) {
        if ($which !== 'top') {
            return;
        }
        
        // Получаем текущий выбранный конкурс
        $filter_contest = isset($_REQUEST['filter_contest']) ? intval($_REQUEST['filter_contest']) : 0;
        
        // Получаем список конкурсов для фильтра
        $contests = get_posts(['post_type' => 'trader_contests', 'posts_per_page' => -1]);
        
        // Получаем текущее значение поиска
        $search_term = isset($_REQUEST['s']) ? sanitize_text_field($_REQUEST['s']) : '';
        ?>
        <div class="alignleft actions">
            <label class="screen-reader-text" for="filter_contest">
                Фильтр по конкурсу
            </label>
            <select name="filter_contest" id="filter_contest">
                <option value="0">Все конкурсы</option>
                <?php foreach ($contests as $contest): ?>
                    <option value="<?php echo $contest->ID; ?>" <?php selected($filter_contest, $contest->ID); ?>>
                        <?php echo esc_html($contest->post_title); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            
            <!-- Добавляем поле поиска прямо здесь -->
            <input type="search" id="search-input" name="s" value="<?php echo esc_attr($search_term); ?>" placeholder="Поиск счетов">
            
            <?php submit_button('Применить фильтры', '', 'filter_action', false); ?>
        </div>
        <?php
    }


    /**
     * Получение данных из базы
     */
    public function get_accounts($per_page = 20, $page_number = 1, $search = '', $contest_id = 0) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'contest_members';
        
        // Начало SQL запроса
        $sql = "SELECT * FROM $table_name WHERE 1=1";
        $params = [];
        
        // Добавляем фильтр по конкурсу
        if ($contest_id > 0) {
            $sql .= " AND contest_id = %d";
            $params[] = $contest_id;
        }
        
        // Добавляем поиск
        if (!empty($search)) {
            $sql .= " AND (account_number LIKE %s OR name LIKE %s OR user_ip LIKE %s)";
            $search_term = '%' . $wpdb->esc_like($search) . '%';
            $params[] = $search_term;
            $params[] = $search_term;
            $params[] = $search_term;
        }
        
        // Сортировка и пагинация
        $orderby = isset($_REQUEST['orderby']) ? sanitize_text_field($_REQUEST['orderby']) : 'registration_date';
        $order = isset($_REQUEST['order']) ? sanitize_text_field($_REQUEST['order']) : 'desc';
        
        if (!empty($orderby) && !empty($order)) {
            $sql .= " ORDER BY $orderby $order";
        } else {
            $sql .= " ORDER BY registration_date DESC";
        }
        
        $sql .= " LIMIT %d OFFSET %d";
        $params[] = $per_page;
        $params[] = ($page_number - 1) * $per_page;
        
        // Подготавливаем запрос
        if (!empty($params)) {
            $sql = $wpdb->prepare($sql, $params);
        }
        
        // Возвращаем результаты
        return $wpdb->get_results($sql, ARRAY_A);
    }
    
    /**
     * Определение количества записей
     */
    public function record_count($search = '', $contest_id = 0) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'contest_members';
        
        $sql = "SELECT COUNT(*) FROM $table_name WHERE 1=1";
        $params = [];
        
        if ($contest_id > 0) {
            $sql .= " AND contest_id = %d";
            $params[] = $contest_id;
        }
        
        if (!empty($search)) {
            $sql .= " AND (account_number LIKE %s OR name LIKE %s OR user_ip LIKE %s)";
            $search_term = '%' . $wpdb->esc_like($search) . '%';
            $params[] = $search_term;
            $params[] = $search_term;
            $params[] = $search_term;
        }
        
        if (!empty($params)) {
            $sql = $wpdb->prepare($sql, $params);
        }
        
        return $wpdb->get_var($sql);
    }
    
    /**
     * Текст, отображаемый когда нет данных
     */
    public function no_items() {
        echo 'Счета пока не добавлены.';
    }
    
    /**
     * Определение колонок таблицы
     */
    public function get_columns() {
        $columns = [
            'cb'              => '<input type="checkbox" />',
            'number'          => '№',
            'id'              => 'ID',
            'contest_id'      => 'Конкурс',
            'name'            => 'Имя',
            'account_number'  => 'Номер счёта',
            'user_ip'         => 'IP адрес',
            'user_country'    => 'Страна',
            'balance'         => 'Баланс', 
            'server'          => 'Сервер',
            'terminal'        => 'Терминал',
            'status'          => 'Статус',
            'last_update'     => 'Обновлено',
            'registration_date' => 'Дата регистрации',
            'actions'         => 'Действия'
        ];
        
        return $columns;
    }

    
    /**
     * Колонки, которые можно сортировать
     */
    public function get_sortable_columns() {
        $sortable_columns = [
            'id'              => ['id', true],
            'account_number'  => ['account_number', false],
            'contest_id'      => ['contest_id', false],
            'balance'         => ['balance', false],
            'registration_date' => ['registration_date', true]
        ];
        
        return $sortable_columns;
    }
    
    /**
     * Отображение значений в колонках
     */
    /**
 * Отображение значений в колонках
 */
public function column_default($item, $column_name) {
    switch ($column_name) {
        case 'number':
            // Порядковый номер можно вычислять динамически
            static $row_number = 0;
            return ++$row_number;
        case 'id':
            return $item['id'];
        case 'account_number':
            return esc_html($item['account_number']);
        case 'contest_id':
            $contest_post = get_post($item['contest_id']);
            return $contest_post ? esc_html($contest_post->post_title) : '—';
        case 'name':
            if (!empty($item['user_id'])) {
                $user = get_userdata($item['user_id']);
                if ($user) {
                    return html_entity_decode($user->display_name) . ' (' . esc_html($user->user_login) . ')';
                } else {
                    return 'Пользователь #' . esc_html($item['user_id']);
                }
            } else {
                return 'Гость';
            }
        case 'user_ip':
            return !empty($item['user_ip']) ? esc_html($item['user_ip']) : '—';
        case 'user_country':
            if (!empty($item['country_code'])) {
                return '<img src="https://flagcdn.com/16x12/' . esc_attr($item['country_code']) . '.png" 
                    alt="' . esc_attr($item['user_country']) . '" 
                    title="' . esc_attr($item['user_country']) . '" 
                    width="16" height="12" style="margin-right: 5px; vertical-align: middle;" />' . 
                    esc_html($item['user_country']);
            } else {
                return '—';
            }
        case 'balance':
            return number_format($item['balance'], 2, '.', ' ') . ' $';
        case 'server':
            return esc_html($item['server']);
        case 'terminal':
            return esc_html($item['terminal']);
        case 'registration_date':
            return esc_html($item['registration_date']);
        case 'status':
            if ($item['connection_status'] === 'connected') {
                $status_class = 'status-indicator connected';
                $status_text = 'Подключен';
            } else if ($item['connection_status'] === 'disqualified') {
                $status_class = 'status-indicator disqualified';
                $status_text = 'Дисквалифицирован';
            } else {
                $status_class = 'status-indicator disconnected';
                $status_text = 'Отключен';
            }
            
            // Добавляем атрибут title с описанием ошибки, если статус отключен или дисквалифицирован
            $title_attr = '';
            if ($item['connection_status'] !== 'connected' && !empty($item['error_description'])) {
                $title_attr = ' title="' . esc_attr($item['error_description']) . '"';
            }
            return sprintf(
                '<span class="%s"%s>%s</span>',
                $status_class,
                $title_attr,
                $status_text
            );
        case 'last_update':
            return format_time_ago($item['last_update']);
        case 'actions':
            return sprintf(
                '<a href="%s" class="button button-small"><span class="dashicons dashicons-edit"></span></a> 
                 <a href="%s" class="button button-small"><span class="dashicons dashicons-visibility"></span></a>',
                admin_url('edit.php?post_type=trader_contests&page=trader_contests_accounts_edit&id=' . $item['id']),
                admin_url('edit.php?post_type=trader_contests&page=trader_contests_accounts_view&id=' . $item['id'])
            );
        default:
            return isset($item[$column_name]) ? $item[$column_name] : '';
    }
}

    
    /**
     * Отображение чекбокса для выбора строки
     */
    public function column_cb($item) {
        return sprintf(
            '<input type="checkbox" id="cb-select-%s" name="account_id[]" value="%s" class="check-item" data-item-id="%s" />' .
            '<label for="cb-select-%s" class="screen-reader-text">%s</label>',
            $item['id'], $item['id'], $item['id'], $item['id'], 
            sprintf('Выбрать запись #%s', $item['id'])
        );
    }
    
    /**
     * Подготовка элементов перед отображением
     */
    public function prepare_items() {
        // Определяем колонки
        $columns = $this->get_columns();
        $hidden = [];
        $sortable = $this->get_sortable_columns();
        
        $this->_column_headers = [$columns, $hidden, $sortable];
        
        // Обработка групповых действий
        $this->process_bulk_action();
        
        // Пагинация
        $per_page = 20;
        $current_page = $this->get_pagenum();
        
        // Получаем параметры фильтрации
        $search = isset($_REQUEST['s']) ? sanitize_text_field($_REQUEST['s']) : '';
        $contest_id = isset($_REQUEST['filter_contest']) ? intval($_REQUEST['filter_contest']) : 0;
        
        // Общее количество элементов для пагинации
        $total_items = $this->record_count($search, $contest_id);
        
        // Данные для таблицы
        $this->items = $this->get_accounts($per_page, $current_page, $search, $contest_id);
        
        // Настройка пагинации
        $this->set_pagination_args([
            'total_items' => $total_items,
            'per_page'    => $per_page,
            'total_pages' => ceil($total_items / $per_page)
        ]);
    }

        /**
     * Определение доступных групповых действий
     */
    public function get_bulk_actions() {
        $actions = [
            'update' => 'Обновить счета',
            'delete' => 'Удалить счета'
        ];
        return $actions;
    }
    
    /**
     * Обработка групповых действий
     */
    public function process_bulk_action() {
            
        // Проверка nonce для безопасности
        $nonce = isset($_REQUEST['_wpnonce']) ? $_REQUEST['_wpnonce'] : '';
        if (!wp_verify_nonce($nonce, 'bulk-' . $this->_args['plural'])) {
            return;
        }
        
        // Проверка nonce для безопасности
        if ('delete' === $this->current_action()) {
            // Проверяем права пользователя
            if (!current_user_can('manage_options')) {
                wp_die('У вас недостаточно прав для выполнения этого действия.');
            }
            
            // Убедимся, что у нас есть ID счетов
            if (isset($_POST['account_id']) && is_array($_POST['account_id'])) {
                $account_ids = array_map('intval', $_POST['account_id']);
                
                // Удаляем каждый выбранный счет
                foreach ($account_ids as $account_id) {
                    $this->delete_account($account_id);
                }
                
                // Добавляем сообщение об успешном удалении
                add_action('admin_notices', function() use ($account_ids) {
                    $count = count($account_ids);
                    $message = sprintf(
                        _n(
                            'Счет успешно удален.',
                            '%s счетов успешно удалены.',
                            $count,
                            'ft-trader-contests'
                        ),
                        number_format_i18n($count)
                    );
                    echo '<div class="notice notice-success is-dismissible"><p>' . $message . '</p></div>';
                });
            }
        } 
        // Сохраняем существующую обработку действия update
        else if ('update' === $this->current_action()) {
            // Убедимся, что у нас есть ID счетов
            if (isset($_POST['account_id']) && is_array($_POST['account_id'])) {
                $account_ids = array_map('intval', $_POST['account_id']);
                
                // Сохраняем ID счетов для обновления в транзиент
                set_transient('accounts_to_update', $account_ids, 60*10); // хранится 10 минут
                
                // Проверяем, является ли запрос AJAX-запросом
                if (!wp_doing_ajax()) {
                    // Если это обычная отправка формы, перенаправляем на страницу обновления
                    wp_redirect(add_query_arg('bulk_update', 'accounts', admin_url('edit.php?post_type=trader_contests&page=trader_contests_accounts')));
                    exit;
                }
                // Для AJAX-запросов ничего не делаем, так как обработка будет выполняться в JavaScript
            }
        }
        
    }

    /**
     * Вспомогательный метод для удаления счета и связанных с ним данных
     */
    public function delete_account($account_id) {
        global $wpdb;
        
        // Удаляем записи из основной таблицы счетов
        $wpdb->delete(
            $wpdb->prefix . 'contest_members', 
            ['id' => $account_id], 
            ['%d']
        );
        
        // Удаляем связанные ордера
        $wpdb->delete(
            $wpdb->prefix . 'contest_members_orders', 
            ['account_id' => $account_id], 
            ['%d']
        );
        
        // Удаляем историю ордеров
        $wpdb->delete(
            $wpdb->prefix . 'contest_members_order_history', 
            ['account_id' => $account_id], 
            ['%d']
        );
        
        // Удаляем историю изменений счета
        $wpdb->delete(
            $wpdb->prefix . 'contest_members_history', 
            ['account_id' => $account_id], 
            ['%d']
        );
        
        return true;
    }

    /**
     * Отображение строк таблицы с атрибутом data-contest-id
     */
    public function display_rows() {
        $records = $this->items;
        
        list($columns, $hidden, $sortable, $primary) = $this->get_column_info();
        
        if (!empty($records)) {
            foreach ($records as $item) {
                echo '<tr data-contest-id="' . esc_attr($item['contest_id']) . '">';
                
                foreach ($columns as $column_name => $column_display_name) {
                    $classes = $column_name . ' column-' . $column_name;
                    if ($primary === $column_name) {
                        $classes .= ' has-row-actions column-primary';
                    }
                    
                    if (in_array($column_name, $hidden)) {
                        $classes .= ' hidden';
                    }
                    
                    // Проверяем, нужно ли добавить атрибут для сортировки
                    $data_colname = '';
                    if (in_array($column_name, array_keys($sortable))) {
                        $data_colname = 'data-colname="' . esc_attr($column_display_name) . '"';
                    }
                    
                    echo '<td class="' . esc_attr($classes) . '" ' . $data_colname . '>';
                    
                    switch ($column_name) {
                        case 'cb':
                            echo $this->column_cb($item);
                            break;
                        default:
                            echo $this->column_default($item, $column_name);
                            break;
                    }
                    
                    echo '</td>';
                }
                
                echo '</tr>';
            }
        }
    }

}
