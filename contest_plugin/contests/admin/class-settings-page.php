<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Класс для страницы настроек плагина
 */
class FTTrader_Settings_Page
{
    /**
     * Инициализация класса
     */
    public function __construct()
    {
        add_action('admin_menu', [$this, 'add_settings_page']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_init', [$this, 'handle_actions']);
        
        // Добавляем AJAX для отладки сохранения настроек
        add_action('wp_ajax_debug_save_settings', [$this, 'debug_save_settings']);
        add_action('wp_ajax_debug_php_info', [$this, 'debug_php_info']);
        
        // Добавляем AJAX для проверки соединения с SERVERAPI
        add_action('wp_ajax_check_direct_connection', [$this, 'check_direct_connection']);
    }

    /**
     * Добавление страницы настроек в меню админки
     */
    public function add_settings_page()
    {
        add_submenu_page(
            'edit.php?post_type=trader_contests',
            'Настройки конкурсов',
            'Настройки',
            'manage_options',
            'fttrader_settings',
            [$this, 'render_settings_page']
        );
        
        // Добавляем JavaScript для очистки зависших очередей и восстановления расписания
        add_action('admin_footer', function() {
            ?>
            <script type="text/javascript">
            jQuery(document).ready(function($) {
                $('#clear-stalled-queues').on('click', function() {
                    var $button = $(this);
                    var $status = $('#clear-queues-status');
                    
                    // Блокируем кнопку и показываем сообщение
                    $button.prop('disabled', true);
                    $status.html('<span style="color: gray;">Очистка зависших очередей...</span>');
                    
                    // Отправляем AJAX запрос
                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'fttradingapi_clear_all_queues',
                            nonce: ftTraderAdmin.nonce
                        },
                        success: function(response) {
                            if (response.success) {
                                $status.html('<span style="color: green;">' + response.data.message + '</span>');
                                // Опционально обновляем страницу через 2 секунды
                                setTimeout(function() {
                                    location.reload();
                                }, 2000);
                            } else {
                                $status.html('<span style="color: red;">Ошибка: ' + (response.data ? response.data.message : 'Неизвестная ошибка') + '</span>');
                                $button.prop('disabled', false);
                            }
                        },
                        error: function() {
                            $status.html('<span style="color: red;">Ошибка соединения</span>');
                            $button.prop('disabled', false);
                        }
                    });
                });
                
                // Обработчик для кнопки восстановления расписания автообновления
                $('#restore-auto-update-schedule').on('click', function() {
                    var $button = $(this);
                    var $status = $('#restore-schedule-status');
                    
                    // Блокируем кнопку и показываем сообщение
                    $button.prop('disabled', true);
                    $status.html('<span style="color: gray;">Восстановление расписания автообновления...</span>');
                    
                    // Отправляем AJAX запрос
                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'fttradingapi_restore_auto_update_schedule',
                            nonce: ftTraderAdmin.nonce
                        },
                        success: function(response) {
                            if (response.success) {
                                $status.html('<span style="color: green;">' + response.data.message + '</span>');
                                // Опционально обновляем страницу через 2 секунды
                                setTimeout(function() {
                                    location.reload();
                                }, 2000);
                            } else {
                                $status.html('<span style="color: red;">Ошибка: ' + (response.data ? response.data.message : 'Неизвестная ошибка') + '</span>');
                                $button.prop('disabled', false);
                            }
                        },
                        error: function() {
                            $status.html('<span style="color: red;">Ошибка соединения</span>');
                            $button.prop('disabled', false);
                        }
                    });
                });
            });
            </script>
            <?php
        });
    }

    /**
     * Регистрация настроек в WordPress
     */
    public function register_settings()
    {
        // Регистрируем группу настроек
        register_setting(
            'fttrader_settings_group',
            'fttradingapi_history_thresholds',
            [$this, 'sanitize_thresholds']
        );

        // Добавляем секцию настроек для режима работы API
        add_settings_section(
            'fttrader_api_mode_section',
            'Настройки режима работы API',
            [$this, 'render_api_mode_section_description'],
            'fttrader_settings'
        );

        // Поле для выбора режима API
        add_settings_field(
            'ft_api_mode',
            'Режим API',
            [$this, 'render_api_mode_field'],
            'fttrader_settings',
            'fttrader_api_mode_section'
        );

        // Поле для настройки IP-адреса сервера (отображается только в режиме direct)
        add_settings_field(
            'ft_server_api_ip',
            'IP-адрес сервера',
            [$this, 'render_server_api_ip_field'],
            'fttrader_settings',
            'fttrader_api_mode_section'
        );

        // Поле для настройки порта сервера (отображается только в режиме direct)
        add_settings_field(
            'ft_server_api_port',
            'Порт сервера',
            [$this, 'render_server_api_port_field'],
            'fttrader_settings',
            'fttrader_api_mode_section'
        );

        // Регистрируем опции для настроек API
        register_setting('fttrader_settings', 'ft_api_mode');
        register_setting('fttrader_settings', 'ft_server_api_ip');
        register_setting('fttrader_settings', 'ft_server_api_port');

        // Добавляем секцию настроек
        add_settings_section(
            'fttradingapi_history_thresholds_section',
            'Пороги изменения для записи в историю',
            [$this, 'render_section_description'],
            'fttrader_settings'
        );

        // Поля для финансовых показателей
        $this->add_threshold_field('i_bal', 'Баланс', 2);
        $this->add_threshold_field('i_equi', 'Средства', 2);
        $this->add_threshold_field('i_marg', 'Использованная маржа', 2);
        $this->add_threshold_field('i_prof', 'Плавающая прибыль/убыток', 1000);
        $this->add_threshold_field('leverage', 'Торговое плечо', 2);
        // Добавляем регистрацию настроек графика
        $this->register_graph_settings();

        // Добавляем новую секцию для настроек автоматического обновления
        add_settings_section(
            'fttrader_auto_update_section',
            'Настройки автоматического обновления счетов',
            [$this, 'render_auto_update_section_description'],
            'fttrader_settings'
        );

        // Минимальный интервал между обновлениями одного счета (в минутах)
        add_settings_field(
            'fttrader_min_update_interval',
            'Минимальный интервал обновления (минуты)',
            [$this, 'render_number_field'],
            'fttrader_settings',
            'fttrader_auto_update_section',
            [
                'label_for' => 'fttrader_min_update_interval',
                'description' => 'Минимальный интервал между обновлениями одного счета в минутах. Счета, обновленные ранее, будут пропущены.',
                'default' => 5,
                'min' => 1,
                'max' => 1440 // 24 часа
            ]
        );

        // Интервал обновления счетов с ошибками (в минутах)
        add_settings_field(
            'fttrader_error_accounts_interval',
            'Интервал обновления счетов с ошибками (минуты)',
            [$this, 'render_number_field'],
            'fttrader_settings',
            'fttrader_auto_update_section',
            [
                'label_for' => 'fttrader_error_accounts_interval',
                'description' => 'Интервал обновления счетов, у которых была ошибка соединения. Указывается в минутах. При значении 0 используется стандартный интервал.',
                'default' => 30,
                'min' => 0,
                'max' => 1440 // 24 часа
            ]
        );

        // Количество счетов для одновременного обновления
        add_settings_field(
            'fttrader_batch_size',
            'Размер пакета обновления',
            [$this, 'render_number_field'],
            'fttrader_settings',
            'fttrader_auto_update_section',
            [
                'label_for' => 'fttrader_batch_size',
                'description' => 'Количество счетов, обновляемых за один запуск.',
                'default' => 2,
                'min' => 1,
                'max' => 20
            ]
        );

        // Интервал между запусками автообновления
        add_settings_field(
            'fttrader_auto_update_interval',
            'Интервал запуска автообновления (минуты)',
            [$this, 'render_number_field'],
            'fttrader_settings',
            'fttrader_auto_update_section',
            [
                'label_for' => 'fttrader_auto_update_interval',
                'description' => 'Интервал между запусками автоматического обновления счетов в минутах (15, 30, 60, 720, 1440).',
                'default' => 60,
                'min' => 15,
                'max' => 1440 // 24 часа
            ]
        );

        // Включение/выключение автоматического обновления
        add_settings_field(
            'fttrader_auto_update_enabled',
            'Автоматическое обновление',
            [$this, 'render_checkbox_field'],
            'fttrader_settings',
            'fttrader_auto_update_section',
            [
                'label_for' => 'fttrader_auto_update_enabled',
                'description' => 'Включить автоматическое обновление счетов через WP Cron.',
                'default' => false
            ]
        );

        // Регистрируем настройки
        register_setting(
            'fttrader_settings',
            'fttrader_auto_update_settings',
            [$this, 'sanitize_auto_update_settings']
        );

        register_setting(
            'fttrader_settings',
            'fttrader_min_update_interval',
            ['type' => 'integer']
        );

        register_setting(
            'fttrader_settings',
            'fttrader_error_accounts_interval',
            ['type' => 'integer']
        );

        register_setting(
            'fttrader_settings',
            'fttrader_batch_size',
            ['type' => 'integer']
        );
    }

    // Описание секции автоматического обновления
    public function render_auto_update_section_description()
    {
        echo '<p>Настройте параметры автоматического обновления счетов. Обновление выполняется через WP Cron.</p>';
    }

    // Отрисовка поля с числовым значением
    public function render_number_field($args)
    {
        $options = get_option('fttrader_auto_update_settings', []);
        $id = $args['label_for'];
        $value = isset($options[$id]) ? $options[$id] : $args['default'];

        echo '<input type="number" id="' . esc_attr($id) . '" name="fttrader_auto_update_settings[' . esc_attr($id) . ']" value="' . esc_attr($value) . '" min="' . esc_attr($args['min']) . '" max="' . esc_attr($args['max']) . '" class="regular-text">';

        if (isset($args['description'])) {
            echo '<p class="description">' . esc_html($args['description']) . '</p>';
        }
    }

    // Отрисовка поля с чекбоксом
    public function render_checkbox_field($args)
    {
        $options = get_option('fttrader_auto_update_settings', []);
        $id = $args['label_for'];
        $checked = isset($options[$id]) ? $options[$id] : $args['default'];

        echo '<input type="checkbox" id="' . esc_attr($id) . '" name="fttrader_auto_update_settings[' . esc_attr($id) . ']" ' . checked($checked, true, false) . ' value="1">';

        if (isset($args['description'])) {
            echo '<p class="description">' . esc_html($args['description']) . '</p>';
        }
    }

    // Валидация настроек автоматического обновления
    public function sanitize_auto_update_settings($input)
    {
        $sanitized_input = [];

        // Минимальный интервал обновления
        if (isset($input['fttrader_min_update_interval'])) {
            $sanitized_input['fttrader_min_update_interval'] = intval($input['fttrader_min_update_interval']);
            if ($sanitized_input['fttrader_min_update_interval'] < 1) {
                $sanitized_input['fttrader_min_update_interval'] = 1;
            } elseif ($sanitized_input['fttrader_min_update_interval'] > 1440) {
                $sanitized_input['fttrader_min_update_interval'] = 1440;
            }
        } else {
            $sanitized_input['fttrader_min_update_interval'] = 5; // Значение по умолчанию
        }

        // Интервал обновления счетов с ошибками (в минутах)
        if (isset($input['fttrader_error_accounts_interval'])) {
            $sanitized_input['fttrader_error_accounts_interval'] = intval($input['fttrader_error_accounts_interval']);
            if ($sanitized_input['fttrader_error_accounts_interval'] < 0) {
                $sanitized_input['fttrader_error_accounts_interval'] = 0;
            } elseif ($sanitized_input['fttrader_error_accounts_interval'] > 1440) {
                $sanitized_input['fttrader_error_accounts_interval'] = 1440;
            }
        } else {
            $sanitized_input['fttrader_error_accounts_interval'] = 30; // Значение по умолчанию
        }

        // Размер пакета
        if (isset($input['fttrader_batch_size'])) {
            $sanitized_input['fttrader_batch_size'] = intval($input['fttrader_batch_size']);
            if ($sanitized_input['fttrader_batch_size'] < 1) {
                $sanitized_input['fttrader_batch_size'] = 1;
            } elseif ($sanitized_input['fttrader_batch_size'] > 20) {
                $sanitized_input['fttrader_batch_size'] = 20;
            }
        } else {
            $sanitized_input['fttrader_batch_size'] = 5; // Значение по умолчанию
        }

        // Интервал между запусками автообновления
        if (isset($input['fttrader_auto_update_interval'])) {
            $sanitized_input['fttrader_auto_update_interval'] = intval($input['fttrader_auto_update_interval']);
            if ($sanitized_input['fttrader_auto_update_interval'] < 15) {
                $sanitized_input['fttrader_auto_update_interval'] = 15;
            } elseif ($sanitized_input['fttrader_auto_update_interval'] > 1440) {
                $sanitized_input['fttrader_auto_update_interval'] = 1440;
            }
        } else {
            $sanitized_input['fttrader_auto_update_interval'] = 60; // Значение по умолчанию
        }

        // Включение/выключение автоматического обновления
        $sanitized_input['fttrader_auto_update_enabled'] = isset($input['fttrader_auto_update_enabled']) ? true : false;
        
        // Перенастраиваем расписание автоматического обновления после сохранения настроек
        /*
        if (class_exists('Account_Updater')) {
            // Временно сохраняем настройки, чтобы функция setup_auto_update_schedule могла их использовать
            update_option('fttrader_auto_update_settings', $sanitized_input);
            Account_Updater::setup_auto_update_schedule();
            error_log('Настройки автообновления изменены. Расписание обновлено.');
        }
        */

        return $sanitized_input;
    }

    /**
     * Отображает информацию о статусе автоматического обновления
     */
    public function render_auto_update_status()
    {
        // Получаем настройки автоматического обновления
        $auto_update_settings = get_option('fttrader_auto_update_settings', []);
        $enabled = isset($auto_update_settings['fttrader_auto_update_enabled']) ?
            $auto_update_settings['fttrader_auto_update_enabled'] : false;

        // Получаем информацию о последнем запуске
        $last_run = get_option('contest_accounts_auto_update_last_run', 0);

        // Получаем информацию о следующем запланированном запуске
        $next_run = wp_next_scheduled('contest_accounts_auto_update');

        // Получаем текущий статус обновления
        require_once plugin_dir_path(dirname(__FILE__)) . 'includes/class-account-updater.php';
        $status = Account_Updater::get_status();

        echo '<div class="auto-update-status">';
        echo '<h3>Статус автоматического обновления</h3>';

        echo '<table class="form-table" role="presentation">';
        echo '<tbody>';

        // Статус включения
        echo '<tr>';
        echo '<th scope="row">Автоматическое обновление</th>';
        echo '<td>';
        if ($enabled) {
            echo '<span class="status-enabled">Включено</span>';
        } else {
            echo '<span class="status-disabled">Отключено</span>';
        }
        echo '</td>';
        echo '</tr>';

        // Статус выполнения
        echo '<tr>';
        echo '<th scope="row">Текущий статус</th>';
        echo '<td>';
        if (isset($status['is_running']) && $status['is_running']) {
            echo '<span class="status-running">Выполняется</span>';

            // Отображаем прогресс
            if (isset($status['completed']) && isset($status['total']) && $status['total'] > 0) {
                $percent = round(($status['completed'] / $status['total']) * 100);
                echo ' (' . $status['completed'] . ' из ' . $status['total'] . ' счетов, ' . $percent . '%)';
                echo '<div class="progress-bar-container"><div class="progress-bar" style="width: ' . $percent . '%"></div></div>';
            }
        } else {
            echo '<span>Ожидание</span>';
        }
        echo '</td>';
        echo '</tr>';

        // Последний запуск
        echo '<tr>';
        echo '<th scope="row">Последний запуск</th>';
        echo '<td>';
        if ($last_run) {
            echo '<span class="server-time" data-timestamp="' . esc_attr($last_run) . '">' .
                date_i18n('d.m.Y H:i:s', $last_run) . '</span>';
            echo ' <span class="relative-time" data-timestamp="' . esc_attr($last_run) . '">' .
                human_time_diff($last_run, time()) . ' назад</span>';
        } else {
            echo 'Никогда';
        }
        echo '</td>';
        echo '</tr>';

        // Следующий запуск
        echo '<tr>';
        echo '<th scope="row">Следующий запуск</th>';
        echo '<td>';
        if ($next_run) {
            echo '<span class="server-time" data-timestamp="' . esc_attr($next_run) . '">' .
                date_i18n('d.m.Y H:i:s', $next_run) . '</span>';
            echo ' <span class="relative-time" data-timestamp="' . esc_attr($next_run) . '">через ' .
                human_time_diff(time(), $next_run) . '</span>';
        } else {
            echo 'Не запланирован';
        }
        echo '</td>';
        echo '</tr>';

        // Статистика обновлений
        if (isset($status['success']) || isset($status['failed'])) {
            echo '<tr>';
            echo '<th scope="row">Статистика последнего обновления</th>';
            echo '<td>';
            echo 'Успешно: ' . (isset($status['success']) ? $status['success'] : 0);
            echo ', Ошибок: ' . (isset($status['failed']) ? $status['failed'] : 0);
            echo '</td>';
            echo '</tr>';
        }

        echo '</tbody>';
        echo '</table>';

        // Добавляем кнопки действий
        echo '<p>';

        // Кнопка для запуска обновления вручную
        echo '<a href="' . wp_nonce_url(admin_url('admin.php?page=fttrader_settings&action=run_auto_update'), 'run_auto_update_nonce') . '" class="button button-primary">Запустить обновление сейчас</a> ';

        // Кнопка для просмотра логов
        if (file_exists(WP_CONTENT_DIR . '/debug.log')) {
            $logs_url = wp_nonce_url(admin_url('admin.php?page=fttrader_settings&tab=logs'), 'view_logs_nonce');
            echo '<a href="' . esc_url($logs_url) . '" class="button">Просмотр логов</a> ';
        }
        
        // Добавляем кнопку очистки зависших очередей
        echo '<button id="clear-stalled-queues" class="button button-secondary">Очистить зависшие очереди</button> ';
        
        // Добавляем кнопку восстановления расписания автообновления
        echo '<button id="restore-auto-update-schedule" class="button button-secondary">Восстановить расписание автообновления</button>';
        
        echo '<span id="clear-queues-status" style="margin-left: 10px;"></span>';
        echo '<span id="restore-schedule-status" style="margin-left: 10px;"></span>';

        echo '</p>';

        echo '</div>';
    }

    // Новый метод для регистрации настроек графика
    public function register_graph_settings()
    {
        // Регистрируем группу настроек для графика
        register_setting(
            'fttrader_settings_group',
            'fttradingapi_graph_settings',
            [$this, 'sanitize_graph_settings']
        );

        // Добавляем секцию настроек графика
        add_settings_section(
            'fttradingapi_graph_settings_section',
            'Настройки графика счета участника',
            [$this, 'render_graph_section_description'],
            'fttrader_settings'
        );

        // Добавляем поле выбора интервала агрегации
        add_settings_field(
            'fttradingapi_graph_aggregation_interval',
            'Интервал агрегации данных',
            [$this, 'render_graph_select_field'],
            'fttrader_settings',
            'fttradingapi_graph_settings_section',
            [
                'field_key' => 'aggregation_interval',
                'default_value' => 'day',
                'options' => [
                    'hour' => 'По часам',
                    'day' => 'По дням',
                    'week' => 'По неделям',
                    'month' => 'По месяцам'
                ],
                'description' => 'Интервал, по которому будут группироваться данные для отображения.'
            ]
        );

        // Добавляем поле для максимального количества точек
        add_settings_field(
            'fttradingapi_graph_data_points',
            'Максимальное количество интервалов',
            [$this, 'render_graph_field'],
            'fttrader_settings',
            'fttradingapi_graph_settings_section',
            [
                'field_key' => 'max_intervals',
                'default_value' => 100,
                'description' => 'Максимальное количество интервалов для отображения на графике. Большее количество может замедлить загрузку.'
            ]
        );

        // Добавляем переключатель для выбора отображения дополнительных данных
        add_settings_field(
            'fttradingapi_graph_show_range',
            'Отображать диапазон колебаний',
            [$this, 'render_graph_checkbox_field'],
            'fttrader_settings',
            'fttradingapi_graph_settings_section',
            [
                'field_key' => 'show_range',
                'default_value' => true,
                'description' => 'Показывать минимальные и максимальные значения для каждого интервала.'
            ]
        );

        // Настройки цветов
        add_settings_field(
            'fttradingapi_graph_balance_color',
            'Цвет линии баланса',
            [$this, 'render_graph_color_field'],
            'fttrader_settings',
            'fttradingapi_graph_settings_section',
            [
                'field_key' => 'balance_color',
                'default_value' => '#4285f4',
                'description' => 'Цвет линии баланса на графике.'
            ]
        );

        add_settings_field(
            'fttradingapi_graph_equity_color',
            'Цвет линии средств (equity)',
            [$this, 'render_graph_color_field'],
            'fttrader_settings',
            'fttradingapi_graph_settings_section',
            [
                'field_key' => 'equity_color',
                'default_value' => '#34a853',
                'description' => 'Цвет линии средств (equity) на графике.'
            ]
        );

        add_settings_field(
            'fttradingapi_graph_history_color',
            'Цвет исторической линии',
            [$this, 'render_graph_color_field'],
            'fttrader_settings',
            'fttradingapi_graph_settings_section',
            [
                'field_key' => 'history_color',
                'default_value' => '#9e9e9e',
                'description' => 'Цвет линии для исторических данных, восстановленных из истории сделок.'
            ]
        );

        add_settings_field(
            'fttradingapi_graph_range_color',
            'Цвет для диапазона колебаний',
            [$this, 'render_graph_color_field'],
            'fttrader_settings',
            'fttradingapi_graph_settings_section',
            [
                'field_key' => 'range_color',
                'default_value' => '#f1f1f1',
                'description' => 'Цвет фона, отображающего диапазон между минимальным и максимальным значением.'
            ]
        );
    }

    // Добавим метод для отрисовки чекбокса
    public function render_graph_checkbox_field($args)
    {
        $options = get_option('fttradingapi_graph_settings', []);
        $field_key = $args['field_key'];
        $default_value = $args['default_value'];
        $value = isset($options[$field_key]) ? $options[$field_key] : $default_value;

        echo '<input type="checkbox" name="fttradingapi_graph_settings[' . $field_key . ']" 
            value="1" ' . checked(1, $value, false) . '/>';

        if (!empty($args['description'])) {
            echo '<p class="description">' . esc_html($args['description']) . '</p>';
        }
    }

    // Метод для вывода описания секции
    public function render_graph_section_description()
    {
        echo '<p>Настройки отображения графика изменения баланса и средств счета участника конкурса.</p>';
    }

    // Метод для отрисовки обычного поля ввода
    public function render_graph_field($args)
    {
        $options = get_option('fttradingapi_graph_settings', []);
        $field_key = $args['field_key'];
        $default_value = $args['default_value'];
        $value = isset($options[$field_key]) ? $options[$field_key] : $default_value;

        echo '<input type="number" name="fttradingapi_graph_settings[' . $field_key . ']" 
            value="' . esc_attr($value) . '" class="regular-text" />';

        if (!empty($args['description'])) {
            echo '<p class="description">' . esc_html($args['description']) . '</p>';
        }
    }

    // Метод для отрисовки поля выбора
    public function render_graph_select_field($args)
    {
        $options = get_option('fttradingapi_graph_settings', []);
        $field_key = $args['field_key'];
        $default_value = $args['default_value'];
        $value = isset($options[$field_key]) ? $options[$field_key] : $default_value;

        echo '<select name="fttradingapi_graph_settings[' . $field_key . ']" class="regular-text">';
        foreach ($args['options'] as $option_key => $option_label) {
            echo '<option value="' . esc_attr($option_key) . '" ' . selected($value, $option_key, false) . '>' .
                esc_html($option_label) . '</option>';
        }
        echo '</select>';

        if (!empty($args['description'])) {
            echo '<p class="description">' . esc_html($args['description']) . '</p>';
        }
    }

    // Метод для отрисовки поля выбора цвета
    public function render_graph_color_field($args)
    {
        $options = get_option('fttradingapi_graph_settings', []);
        $field_key = $args['field_key'];
        $default_value = $args['default_value'];
        $value = isset($options[$field_key]) ? $options[$field_key] : $default_value;

        echo '<input type="color" name="fttradingapi_graph_settings[' . $field_key . ']" 
            value="' . esc_attr($value) . '" class="color-field" />';

        if (!empty($args['description'])) {
            echo '<p class="description">' . esc_html($args['description']) . '</p>';
        }
    }

    // Метод для валидации настроек графика
    public function sanitize_graph_settings($input)
    {
        // Временно упрощаем для отладки - просто возвращаем входные данные
        return $input;
        
        /*
        $sanitized_input = [];

        // Интервал агрегации
        if (isset($input['aggregation_interval'])) {
            $allowed_intervals = ['hour', 'day', 'week', 'month'];
            $sanitized_input['aggregation_interval'] = in_array($input['aggregation_interval'], $allowed_intervals)
                ? $input['aggregation_interval']
                : 'day';
        }

        // Максимальное количество интервалов
        if (isset($input['max_intervals'])) {
            $sanitized_input['max_intervals'] = absint($input['max_intervals']);
            if ($sanitized_input['max_intervals'] < 10) {
                $sanitized_input['max_intervals'] = 10;
            } elseif ($sanitized_input['max_intervals'] > 1000) {
                $sanitized_input['max_intervals'] = 1000;
            }
        }

        // Показывать диапазон колебаний
        $sanitized_input['show_range'] = isset($input['show_range']) ? 1 : 0;

        // Цвета
        $color_fields = ['balance_color', 'equity_color', 'history_color', 'range_color'];
        foreach ($color_fields as $field) {
            if (isset($input[$field])) {
                // Регулярное выражение для проверки HEX-цвета
                $sanitized_input[$field] = preg_match('/^#[a-f0-9]{6}$/i', $input[$field])
                    ? $input[$field]
                    : $this->get_default_color($field);
            }
        }

        return $sanitized_input;
        */
    }

    // Обновляем метод для получения цвета по умолчанию
    private function get_default_color($field)
    {
        $defaults = [
            'balance_color' => '#4285f4',
            'equity_color' => '#34a853',
            'history_color' => '#9e9e9e',
            'range_color' => '#f1f1f1'
        ];

        return isset($defaults[$field]) ? $defaults[$field] : '#000000';
    }

    /**
     * Добавление поля для настройки порога изменения
     */
    private function add_threshold_field($field_key, $field_label, $default_value)
    {
        add_settings_field(
            'fttradingapi_threshold_' . $field_key,
            $field_label,
            [$this, 'render_threshold_field'],
            'fttrader_settings',
            'fttradingapi_history_thresholds_section',
            [
                'field_key' => $field_key,
                'field_label' => $field_label,
                'default_value' => $default_value
            ]
        );
    }

    /**
     * Отображение описания секции
     */
    public function render_section_description()
    {
        echo '<p>Укажите минимальный процент изменения для записи в историю. Изменения меньше указанного процента не будут сохраняться.</p>';
    }

    /**
     * Отображение поля настройки порога
     */
    public function render_threshold_field($args)
    {
        $field_key = $args['field_key'];
        $default_value = $args['default_value'];

        $options = get_option('fttradingapi_history_thresholds', []);
        $value = isset($options[$field_key]) ? $options[$field_key] : $default_value;

        echo '<input type="number" step="0.01" min="0.01" name="fttradingapi_history_thresholds[' . $field_key . ']" 
              value="' . esc_attr($value) . '" class="regular-text" /> %';

        echo '<p class="description">Порог изменения в процентах (по умолчанию: ' . $default_value . '%)</p>';
    }

    /**
     * Проверка и очистка введенных значений
     */
    public function sanitize_thresholds($input)
    {
        // Временно упрощаем для отладки - просто возвращаем входные данные
        return $input;
        
        /*
        $sanitized_input = [];

        foreach ($input as $key => $value) {
            $sanitized_input[$key] = floatval($value);

            // Минимальное значение 0.01%
            if ($sanitized_input[$key] < 0.01) {
                $sanitized_input[$key] = 0.01;
            }
        }

        return $sanitized_input;
        */
    }

    /**
     * Отображает страницу настроек
     */
    public function render_settings_page()
    {
        // Проверяем права доступа
        if (!current_user_can('manage_options')) {
            wp_die(__('У вас недостаточно прав для доступа к этой странице.', 'fttrader'));
        }

        // Определяем текущую вкладку
        $current_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'settings';

        // Список доступных вкладок
        $tabs = [
            'settings' => 'Настройки',
            'logs' => 'Логи'
        ];

        echo '<div class="wrap">';
        echo '<h1>' . esc_html(get_admin_page_title()) . '</h1>';

        // Выводим навигацию по вкладкам
        echo '<h2 class="nav-tab-wrapper">';
        foreach ($tabs as $tab_key => $tab_name) {
            $active = $current_tab === $tab_key ? 'nav-tab-active' : '';
            // Добавляем nonce к URL для безопасности
            $url = add_query_arg([
                'page' => 'fttrader_settings',
                'tab' => $tab_key,
            ], admin_url('admin.php'));

            // Если это вкладка логов, добавляем nonce
            if ($tab_key === 'logs') {
                $url = wp_nonce_url($url, 'view_logs_nonce');
            }

            echo '<a href="' . esc_url($url) . '" class="nav-tab ' . $active . '">' . $tab_name . '</a>';
        }
        echo '</h2>';

        // Выводим содержимое текущей вкладки
        if ($current_tab === 'settings') {
            // Добавляем уведомление о проблемах с сохранением
            echo '<div class="notice notice-warning" style="margin: 20px 0;">
                <p><strong>Внимание!</strong> При сохранении настроек может возникнуть ошибка 502 Bad Gateway. 
                Если это происходит, пожалуйста, используйте кнопку "Сохранить через AJAX" после нажатия на "Сохранить настройки".</p>
            </div>';
            
            // Форма настроек
            echo '<form action="options.php" method="post" id="settings-form">';
            settings_fields('fttrader_settings');
            do_settings_sections('fttrader_settings');
            echo '</form>';

            // Добавляем кнопку для AJAX-сохранения под формой
            echo '<div style="margin-top: 20px;">';
            echo '<button type="button" id="direct-ajax-save" class="button button-primary">Сохранить через AJAX</button>
            <span id="ajax-save-status" style="margin-left: 10px;"></span>';
            echo '</div>';
            
            // Добавляем JavaScript для кнопки прямого AJAX-сохранения
            ?>
            <script type="text/javascript">
            jQuery(document).ready(function($) {
                $('#direct-ajax-save').on('click', function() {
                    var $button = $(this);
                    var $status = $('#ajax-save-status');
                    
                    // Собираем данные формы
                    var formData = $('#settings-form').serialize();
                    
                    // Блокируем кнопку и показываем сообщение
                    $button.prop('disabled', true);
                    $status.html('<span style="color: gray;">Сохранение настроек...</span>');
                    
                    // Отправляем AJAX запрос
                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'debug_save_settings',
                            nonce: '<?php echo wp_create_nonce('fttrader_debug_nonce'); ?>',
                            form_data: formData
                        },
                        success: function(response) {
                            if (response.success) {
                                $status.html('<span style="color: green;">' + response.data.message + '</span>');
                                
                                // Выводим детали сохранения
                                if (response.data.details) {
                                    var detailsHtml = '<ul style="margin-top: 5px;">';
                                    for (var key in response.data.details) {
                                        detailsHtml += '<li>' + response.data.details[key] + '</li>';
                                    }
                                    detailsHtml += '</ul>';
                                    $status.append(detailsHtml);
                                }
                            } else {
                                $status.html('<span style="color: red;">Ошибка: ' + 
                                    (response.data ? response.data.message : 'Неизвестная ошибка') + '</span>');
                            }
                            $button.prop('disabled', false);
                        },
                        error: function(xhr, status, error) {
                            $status.html('<span style="color: red;">Ошибка соединения: ' + error + '</span>');
                            $button.prop('disabled', false);
                        }
                    });
                });
            });
            </script>
            <?php
            
            // Статус автоматического обновления
            $this->render_auto_update_status();
        } elseif ($current_tab === 'logs') {
            // Проверяем nonce для вкладки логов
            if (!isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'view_logs_nonce')) {
                // Если nonce отсутствует или неверный, перенаправляем на вкладку настроек
                wp_redirect(admin_url('admin.php?page=fttrader_settings'));
                exit;
            }

            // Просмотр логов
            $this->render_logs_tab();
        }

        echo '</div>';

        echo '<style>
            .server-time {
                font-family: monospace;
                color: #666;
            }
            .relative-time {
                color: #666;
                font-style: italic;
            }
            #browser-local-time {
                font-weight: bold;
                color: #0073aa;
            }
        </style>';

        // Добавляем JavaScript для обновления времени
        ?>
        <script type="text/javascript">
        jQuery(document).ready(function($) {
            // Функция для форматирования даты
            function formatDate(timestamp) {
                var date = new Date(timestamp * 1000);
                var day = ("0" + date.getDate()).slice(-2);
                var month = ("0" + (date.getMonth() + 1)).slice(-2);
                var year = date.getFullYear();
                var hours = ("0" + date.getHours()).slice(-2);
                var minutes = ("0" + date.getMinutes()).slice(-2);
                var seconds = ("0" + date.getSeconds()).slice(-2);
                return day + "." + month + "." + year + " " + hours + ":" + minutes + ":" + seconds;
            }

            // Функция для расчета относительного времени
            function getRelativeTime(timestamp, isFuture = false) {
                var now = Math.floor(Date.now() / 1000);
                var diff = isFuture ? timestamp - now : now - timestamp;
                
                if (diff < 60) {
                    return "только что";
                } else if (diff < 3600) {
                    var minutes = Math.floor(diff / 60);
                    var text = minutes + " " + (minutes === 1 ? "минуту" : minutes < 5 ? "минуты" : "минут");
                    return isFuture ? "через " + text : text + " назад";
                } else if (diff < 86400) {
                    var hours = Math.floor(diff / 3600);
                    var minutes = Math.floor((diff % 3600) / 60);
                    var text = hours + " " + (hours === 1 ? "час" : hours < 5 ? "часа" : "часов");
                    if (minutes > 0) {
                        text += " " + minutes + " " + (minutes === 1 ? "минуту" : minutes < 5 ? "минуты" : "минут");
                    }
                    return isFuture ? "через " + text : text + " назад";
                } else {
                    var days = Math.floor(diff / 86400);
                    var text = days + " " + (days === 1 ? "день" : days < 5 ? "дня" : "дней");
                    return isFuture ? "через " + text : text + " назад";
                }
            }

            // Функция для обновления всех временных меток
            function updateAllTimestamps() {
                $('.server-time').each(function() {
                    var timestamp = parseInt($(this).data('timestamp'));
                    if (!isNaN(timestamp)) {
                        $(this).text(formatDate(timestamp));
                    }
                });

                $('.relative-time').each(function() {
                    var timestamp = parseInt($(this).data('timestamp'));
                    if (!isNaN(timestamp)) {
                        var text = $(this).text();
                        var isFuture = text.includes('через');
                        $(this).text(getRelativeTime(timestamp, isFuture));
                    }
                });
            }

            // Обновляем время каждую минуту
            updateAllTimestamps();
            setInterval(updateAllTimestamps, 60000);
        });
        </script>
        <?php
    }

    /**
     * Отображает вкладку с логами
     */
    public function render_logs_tab()
    {
        $log_file = WP_CONTENT_DIR . '/debug.log';

        // Обработка действий
        if (isset($_GET['clean_duplicates']) && $_GET['clean_duplicates'] === '1') {
            $this->clean_duplicate_log_entries();
            echo '<div class="notice notice-success"><p>Дублирующиеся записи в истории запусков очищены.</p></div>';
        }

        // Отображение текущего времени браузера
        echo '<div class="browser-time-container">';
        echo '<strong>Текущее время браузера:</strong> <span id="browser-local-time">...</span>';
        echo '</div>';

        // Отображаем историю запусков cron
        echo '<div class="cron-executions">';
        echo '<h4>История запусков Cron</h4>';

        // Получаем время последнего запуска
        $last_execution = get_option('contest_cron_last_execution', 0);
        $executions = get_option('contest_cron_executions', []);

        if ($last_execution) {
            echo '<p><strong>Последний запуск:</strong> <span class="server-time" data-timestamp="' . esc_attr($last_execution) . '">' .
                date('d.m.Y H:i:s', $last_execution) . '</span></p>';

            // Отображаем таблицу с историей запусков
            if (!empty($executions)) {
                echo '<table class="widefat fixed" cellspacing="0">';
                echo '<thead><tr><th>№</th><th>Дата и время запуска</th><th>Прошло времени</th><th>ID запроса</th><th>Источник вызова</th></tr></thead>';
                echo '<tbody>';

                foreach ($executions as $index => $execution) {
                    echo '<tr>';
                    echo '<td>' . ($index + 1) . '</td>';
                    echo '<td><span class="server-time" data-timestamp="' . esc_attr($execution['time']) . '">' .
                        date('d.m.Y H:i:s', $execution['time']) . '</span></td>';
                    echo '<td class="relative-time" data-timestamp="' . esc_attr($execution['time']) . '">' .
                        human_time_diff($execution['time'], time()) . ' назад</td>';
                    echo '<td>' . (isset($execution['request_id']) ? esc_html($execution['request_id']) : 'Н/Д') . '</td>';
                    echo '<td>' . (isset($execution['backtrace']) ? esc_html($execution['backtrace']) : 'Н/Д') . '</td>';
                    echo '</tr>';
                }

                echo '</tbody></table>';
            }
        } else {
            echo '<p>Нет данных о запусках cron. Возможно, задача еще не выполнялась после добавления логирования.</p>';
        }

        echo '</div>';

        // Получаем текущий статус обновления
        $status = Account_Updater::get_status();
        $update_history = get_option('contest_accounts_update_history', []);

        // Отображаем диагностическую информацию о WP Cron
        echo '<div class="cron-diagnostics">';
        echo '<h4>Диагностика WP Cron</h4>';

        // Получаем информацию о состоянии WP Cron
        require_once plugin_dir_path(dirname(__FILE__)) . 'includes/class-cron-manager.php';
        $cron_status = Contest_Cron_Manager::check_cron_status();

        echo '<table class="widefat fixed" cellspacing="0">';
        echo '<thead><tr><th>Параметр</th><th>Значение</th></tr></thead>';
        echo '<tbody>';

        // WP Cron включен
        echo '<tr>';
        echo '<td>WP Cron включен</td>';
        echo '<td>' . ($cron_status['is_cron_enabled'] ? 'Да' : 'Нет (DISABLE_WP_CRON = true)') . '</td>';
        echo '</tr>';

        // Наш хук запланирован
        echo '<tr>';
        echo '<td>Хук contest_accounts_auto_update запланирован</td>';
        echo '<td>' . ($cron_status['our_hook_scheduled'] ? 'Да' : 'Нет') . '</td>';
        echo '</tr>';

        // Следующий запуск
        echo '<tr>';
        echo '<td>Следующий запуск</td>';
        echo '<td>';
        if ($cron_status['next_scheduled']) {
            echo '<span class="server-time" data-timestamp="' . $cron_status['next_scheduled'] . '">' .
                date('d.m.Y H:i:s', $cron_status['next_scheduled']) . '</span>';
        } else {
            echo 'Не запланирован';
        }
        echo '</td>';
        echo '</tr>';

        // Наш интервал зарегистрирован
        echo '<tr>';
        echo '<td>Интервал contest_auto_update зарегистрирован</td>';
        echo '<td>' . ($cron_status['our_interval_registered'] ? 'Да' : 'Нет') . '</td>';
        echo '</tr>';

        // Доступность URL WP Cron
        echo '<tr>';
        echo '<td>URL wp-cron.php доступен</td>';
        echo '<td>' . ($cron_status['cron_url_accessible'] ? 'Да (код: ' . $cron_status['cron_response_code'] . ')' : 'Нет: ' . $cron_status['cron_error']) . '</td>';
        echo '</tr>';

        // Текущее время
        echo '<tr>';
        echo '<td>Текущее время сервера</td>';
        echo '<td>' . date('d.m.Y H:i:s', $cron_status['current_time']) . '</td>';
        echo '</tr>';

        // Время WordPress
        echo '<tr>';
        echo '<td>Текущее время WordPress</td>';
        echo '<td>' . date('d.m.Y H:i:s', $cron_status['wp_time']) . '</td>';
        echo '</tr>';

        echo '</tbody></table>';

        // Отображаем все запланированные события
        if (!empty($cron_status['all_scheduled_events'])) {
            echo '<h4>Все запланированные события WP Cron</h4>';
            echo '<table class="widefat fixed" cellspacing="0">';
            echo '<thead><tr><th>Хук</th><th>Время выполнения</th><th>Расписание</th></tr></thead>';
            echo '<tbody>';

            foreach ($cron_status['all_scheduled_events'] as $event) {
                $highlight = $event['hook'] === 'contest_accounts_auto_update' ? ' style="background-color: #e7f7e3;"' : '';
                echo '<tr' . $highlight . '>';
                echo '<td>' . esc_html($event['hook']) . '</td>';
                echo '<td><span class="server-time" data-timestamp="' . $event['timestamp'] . '">' .
                    $event['time'] . '</span></td>';
                echo '<td>' . esc_html($event['schedule']) . '</td>';
                echo '</tr>';
            }

            echo '</tbody></table>';
        }

        echo '</div>';

        echo '<h3>Статус обновления счетов</h3>';
        echo '<div class="status-details">';

        if (!empty($status) && isset($status['is_running'])) {
            if ($status['is_running']) {
                echo '<div class="status-running">';
                echo '<p><strong>Статус:</strong> <span class="status-badge running">Выполняется</span></p>';

                // Отображаем прогресс
                if (isset($status['completed']) && isset($status['total'])) {
                    $percent = round(($status['completed'] / $status['total']) * 100);
                    echo '<p><strong>Прогресс:</strong> ' . $status['completed'] . ' из ' . $status['total'] . ' счетов (' . $percent . '%)</p>';
                    echo '<div class="progress-bar-container"><div class="progress-bar" style="width: ' . $percent . '%"></div></div>';
                }

                // Отображаем время начала и последнего обновления
                if (isset($status['start_time'])) {
                    echo '<p><strong>Время начала:</strong> <span class="server-time" data-timestamp="' . $status['start_time'] . '">' .
                        date('d.m.Y H:i:s', $status['start_time']) . '</span></p>';
                }

                if (isset($status['last_update'])) {
                    echo '<p><strong>Последнее обновление:</strong> <span class="server-time" data-timestamp="' . $status['last_update'] . '">' .
                        date('d.m.Y H:i:s', $status['last_update']) . '</span></p>';
                }

                echo '</div>';
            } else {
                echo '<div class="status-idle">';
                echo '<p><strong>Статус:</strong> <span class="status-badge idle">Ожидание</span></p>';

                // Отображаем информацию о последнем запуске
                $last_run = get_option('contest_accounts_auto_update_last_run', 0);
                if ($last_run) {
                    echo '<p><strong>Последний запуск:</strong> <span class="server-time" data-timestamp="' . $last_run . '">' .
                        date('d.m.Y H:i:s', $last_run) . '</span></p>';
                } else {
                    echo '<p><strong>Последний запуск:</strong> Никогда</p>';
                }

                // Отображаем информацию о следующем запуске
                $next_run = wp_next_scheduled('contest_accounts_auto_update');
                if ($next_run) {
                    echo '<p><strong>Следующий запуск:</strong> <span class="server-time" data-timestamp="' . $next_run . '">' .
                        date('d.m.Y H:i:s', $next_run) . '</span></p>';
                } else {
                    echo '<p><strong>Следующий запуск:</strong> Не запланирован</p>';
                }

                echo '</div>';
            }

            // Отображаем статистику успешных/неуспешных обновлений
            if (isset($status['success']) || isset($status['failed'])) {
                echo '<div class="update-statistics">';
                echo '<h4>Статистика последнего обновления</h4>';
                echo '<p><strong>Успешно обновлено:</strong> ' . (isset($status['success']) ? $status['success'] : 0) . ' счетов</p>';
                echo '<p><strong>Ошибок обновления:</strong> ' . (isset($status['failed']) ? $status['failed'] : 0) . ' счетов</p>';
                echo '</div>';
            }

            // Отображаем кнопку для запуска обновления вручную
            echo '<p>';
            echo '<a href="' . wp_nonce_url(admin_url('admin.php?page=fttrader_settings&tab=logs&action=run_auto_update'), 'run_auto_update_nonce') . '" class="button button-primary">Запустить обновление сейчас</a>';
            echo '</p>';

            // Добавьте эту кнопку для принудительного запуска WP Cron
            echo ' <a href="' . wp_nonce_url(admin_url('admin.php?page=fttrader_settings&tab=logs&action=force_cron'), 'force_cron_nonce') . '" class="button button-secondary">Принудительно запустить WP Cron</a>';
        } else {
            echo '<p>Информация о статусе обновления недоступна.</p>';
        }

        echo '</div>';

        // Отображаем историю обновлений
        if (!empty($update_history)) {
            echo '<div class="update-history">';
            echo '<h4>История обновлений</h4>';
            echo '<table class="widefat fixed" cellspacing="0">';
            echo '<thead><tr>';
            echo '<th>Дата и время</th><th>Тип</th><th>Счетов</th><th>Успешно</th><th>Ошибок</th><th>Длительность</th>';
            echo '</tr></thead>';
            echo '<tbody>';

            foreach (array_slice(array_reverse($update_history), 0, 10) as $history_item) {
                $type = isset($history_item['is_auto_update']) && $history_item['is_auto_update'] ? 'Авто' : 'Ручное';
                $duration = isset($history_item['end_time']) && isset($history_item['start_time']) ?
                    gmdate('H:i:s', $history_item['end_time'] - $history_item['start_time']) : 'Н/Д';

                echo '<tr>';
                echo '<td><span class="server-time" data-timestamp="' . $history_item['start_time'] . '">' .
                    date('d.m.Y H:i:s', $history_item['start_time']) . '</span></td>';
                echo '<td>' . $type . '</td>';
                echo '<td>' . (isset($history_item['total']) ? $history_item['total'] : 0) . '</td>';
                echo '<td>' . (isset($history_item['success']) ? $history_item['success'] : 0) . '</td>';
                echo '<td>' . (isset($history_item['failed']) ? $history_item['failed'] : 0) . '</td>';
                echo '<td>' . $duration . '</td>';
                echo '</tr>';
            }

            echo '</tbody></table>';
            echo '</div>';
        }

        // Отображаем логи
        echo '<div class="logs-container">';
        echo '<h3>Логи обновления счетов</h3>';

        if (file_exists($log_file)) {
            // Получаем последние 100 строк лога
            $logs = $this->get_last_lines($log_file, 100);

            // Фильтруем только строки, относящиеся к обновлению счетов
            $filtered_logs = [];
            foreach ($logs as $log) {
                if (
                    strpos($log, 'process_batch') !== false ||
                    strpos($log, 'Account_Updater') !== false ||
                    strpos($log, 'auto_update') !== false
                ) {
                    $filtered_logs[] = $log;
                }
            }

            if (!empty($filtered_logs)) {
                echo '<div class="log-entries">';
                echo '<pre>';
                foreach (array_reverse($filtered_logs) as $log) {
                    echo htmlspecialchars($log) . "\n";
                }
                echo '</pre>';
                echo '</div>';
            } else {
                echo '<p>Логи обновления счетов не найдены.</p>';
            }
        } else {
            echo '<p>Файл логов не найден. Убедитесь, что включено логирование в WordPress.</p>';
        }

        // JavaScript для обновления времени
        echo '<script type="text/javascript">
        document.addEventListener("DOMContentLoaded", function() {
            // Функция для форматирования даты в 24-часовом формате
            function formatDate(timestamp) {
                var date = new Date(timestamp * 1000);
                
                // Получаем компоненты даты и времени
                var year = date.getFullYear();
                var month = ("0" + (date.getMonth() + 1)).slice(-2);
                var day = ("0" + date.getDate()).slice(-2);
                var hours = ("0" + date.getHours()).slice(-2);
                var minutes = ("0" + date.getMinutes()).slice(-2);
                var seconds = ("0" + date.getSeconds()).slice(-2);
                
                // Форматируем в строку ДД.ММ.ГГГГ ЧЧ:ММ:СС
                return day + "." + month + "." + year + " " + hours + ":" + minutes + ":" + seconds;
            }
            
            // Функция для расчета относительного времени
            function getRelativeTime(timestamp) {
                var now = Math.floor(Date.now() / 1000);
                var diff = now - timestamp;
                
                if (diff < 60) {
                    return "только что";
                } else if (diff < 3600) {
                    var minutes = Math.floor(diff / 60);
                    return minutes + " " + (minutes === 1 ? "минуту" : minutes < 5 ? "минуты" : "минут") + " назад";
                } else if (diff < 86400) {
                    var hours = Math.floor(diff / 3600);
                    var minutes = Math.floor((diff % 3600) / 60);
                    return hours + " " + (hours === 1 ? "час" : hours < 5 ? "часа" : "часов") + 
                           (minutes > 0 ? " " + minutes + " " + (minutes === 1 ? "минуту" : minutes < 5 ? "минуты" : "минут") : "") + 
                           " назад";
                } else {
                    var days = Math.floor(diff / 86400);
                    return days + " " + (days === 1 ? "день" : days < 5 ? "дня" : "дней") + " назад";
                }
            }
            
            // Функция для обновления локального времени
            function updateLocalTime() {
                var now = new Date();
                var year = now.getFullYear();
                var month = ("0" + (now.getMonth() + 1)).slice(-2);
                var day = ("0" + now.getDate()).slice(-2);
                var hours = ("0" + now.getHours()).slice(-2);
                var minutes = ("0" + now.getMinutes()).slice(-2);
                var seconds = ("0" + now.getSeconds()).slice(-2);
                
                var localTime = day + "." + month + "." + year + " " + hours + ":" + minutes + ":" + seconds;
                
                if (document.getElementById("browser-local-time")) {
                    document.getElementById("browser-local-time").textContent = localTime;
                }
            }
            
            // Функция для обновления всех временных меток
            function updateAllTimestamps() {
                var timeElements = document.querySelectorAll(".server-time");
                timeElements.forEach(function(element) {
                    var timestamp = parseInt(element.getAttribute("data-timestamp"));
                    if (!isNaN(timestamp)) {
                        var formattedDate = formatDate(timestamp);
                        var relativeTime = getRelativeTime(timestamp);
                        element.textContent = formattedDate + " (" + relativeTime + ")";
                    }
                });
            }
            
            // Обновляем время сразу и затем каждую минуту
            updateLocalTime();
            updateAllTimestamps();
            setInterval(updateLocalTime, 1000);
            setInterval(updateAllTimestamps, 60000);
        });
        </script>';

        echo '</div>';

        // Добавляем кнопку для очистки дублирующихся событий
        echo '<div class="clean-cron-events">';
        echo '<h3>Управление задачами Cron</h3>';

        if (isset($_GET['clean_cron']) && $_GET['clean_cron'] === '1') {
            require_once plugin_dir_path(dirname(__FILE__)) . 'includes/class-cron-manager.php';
            $cleaned = Contest_Cron_Manager::clean_duplicate_events();
            echo '<div class="notice notice-success"><p>Очищено дублирующихся задач: ' . $cleaned . '</p></div>';
        }

        echo '<a href="' . admin_url('admin.php?page=fttrader_settings&tab=logs&clean_cron=1') . '" class="button">Очистить дублирующиеся задачи Cron</a>';
        echo '</div>';
    }

    /**
     * Очищает дублирующиеся записи в истории запусков
     */
    private function clean_duplicate_log_entries()
    {
        $executions = get_option('contest_cron_executions', []);
        if (empty($executions)) {
            return;
        }

        // Используем временные метки как ключи для определения дубликатов
        $unique_executions = [];
        $seen_times = [];

        foreach ($executions as $execution) {
            $time = $execution['time'];

            // Если такое время уже было, пропускаем запись
            if (in_array($time, $seen_times)) {
                continue;
            }

            $seen_times[] = $time;
            $unique_executions[] = $execution;
        }

        // Сохраняем только уникальные записи
        update_option('contest_cron_executions', $unique_executions);
    }

    /**
     * Получает последние N строк из файла
     * 
     * @param string $file Путь к файлу
     * @param int $lines Количество строк
     * @return array Массив строк
     */
    private function get_last_lines($file, $lines)
    {
        $result = [];

        $fp = fopen($file, 'r');
        if ($fp) {
            // Перемещаемся в конец файла
            fseek($fp, 0, SEEK_END);
            $pos = ftell($fp);

            // Читаем с конца файла
            $line_count = 0;
            $chars = 4096; // Размер буфера

            while ($pos > 0 && $line_count < $lines) {
                // Определяем размер блока для чтения
                $read_size = min($chars, $pos);
                $pos -= $read_size;

                // Читаем блок
                fseek($fp, $pos);
                $buffer = fread($fp, $read_size);

                // Разбиваем на строки
                $lines_in_buffer = explode("\n", $buffer);

                // Добавляем строки в результат
                $result = array_merge($lines_in_buffer, $result);

                // Обновляем счетчик строк
                $line_count = count($result);
            }

            fclose($fp);
        }

        // Ограничиваем количество строк
        return array_slice($result, 0, $lines);
    }

    /**
     * Обрабатывает действия на странице настроек
     */
    public function handle_actions()
    {
        // Обработчик для очистки истории запусков cron
        if (isset($_GET['action']) && $_GET['action'] === 'clear_cron_history' && check_admin_referer('clear_cron_history_nonce')) {
            // Очищаем историю запусков
            delete_option('contest_cron_executions');

            // Добавляем сообщение об успехе
            add_settings_error(
                'fttrader_settings',
                'cron_history_cleared',
                'История запусков cron успешно очищена.',
                'success'
            );

            // Перенаправляем обратно на страницу логов
            wp_redirect(admin_url('admin.php?page=fttrader_settings&tab=logs'));
            exit;
        }

        // Добавьте этот блок для обработки принудительного запуска WP Cron
        if (isset($_GET['action']) && $_GET['action'] === 'force_cron' && check_admin_referer('force_cron_nonce')) {
            // Получаем все запланированные задачи
            $crons = _get_cron_array();

            // Принудительно запускаем WP Cron
            spawn_cron();

            // Также явно вызываем нашу функцию обновления
            require_once plugin_dir_path(dirname(__FILE__)) . 'includes/class-cron-manager.php';
            Contest_Cron_Manager::run_now();

            // Добавляем сообщение об успехе
            add_settings_error(
                'fttrader_settings',
                'cron_forced',
                'WP Cron принудительно запущен. Проверьте логи для подробностей.',
                'success'
            );

            // Перенаправляем обратно на страницу логов
            wp_redirect(admin_url('admin.php?page=fttrader_settings&tab=logs&cron_forced=1'));
            exit;
        }

        if (isset($_GET['action']) && $_GET['action'] === 'run_auto_update') {
            check_admin_referer('run_auto_update_nonce');

            if (class_exists('Contest_Cron_Manager')) {
                // Если указан параметр manual=1, явно устанавливаем is_auto_update в true
                // при создании очередей обновления
                if (isset($_GET['manual']) && $_GET['manual'] == '1') {
                    // Создаем глобальную переменную для использования в классе Account_Updater
                    $GLOBALS['force_auto_update_flag'] = true;
                }
                
                Contest_Cron_Manager::run_now();
                add_settings_error(
                    'fttrader_settings',
                    'auto_update_started',
                    'Автоматическое обновление счетов и проверка дисквалификации запущены.',
                    'success'
                );
            } else {
                add_settings_error(
                    'fttrader_settings',
                    'auto_update_error',
                    'Ошибка: класс Contest_Cron_Manager не найден.',
                    'error'
                );
            }
        }
    }

    /**
     * Форматирует временную метку с учетом часового пояса пользователя
     */
    private function format_user_time($timestamp)
    {
        // Получаем текущее время сервера и пользователя
        $server_time = time();
        $user_time = current_time('timestamp');

        // Вычисляем разницу между временем сервера и пользователя
        $time_diff = $user_time - $server_time;

        // Применяем эту разницу к переданной временной метке
        $adjusted_timestamp = $timestamp + $time_diff;

        // Форматируем дату и время
        return date('d.m.Y H:i:s', $adjusted_timestamp);
    }

    /**
     * AJAX обработчик для отладки сохранения настроек
     */
    public function debug_save_settings() {
        // Проверяем права
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Недостаточно прав']);
            return;
        }
        
        // Проверяем nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'fttrader_debug_nonce')) {
            wp_send_json_error(['message' => 'Неверный security token']);
            return;
        }
        
        // Получаем данные формы
        $form_data = isset($_POST['form_data']) ? $_POST['form_data'] : '';
        parse_str($form_data, $settings_data);
        
        // Логируем данные для отладки
        error_log('Debug Save Settings: Начало обработки');
        
        // Попытка сохранить настройки вручную
        $result = [];
        
        try {
            // Проверяем, какие группы настроек у нас есть
            if (isset($settings_data['fttrader_auto_update_settings'])) {
                // Обновляем настройки без обновления расписания
                update_option('fttrader_auto_update_settings', $settings_data['fttrader_auto_update_settings']);
                error_log('Debug Save Settings: Настройки автообновления сохранены');
                $result['auto_update'] = 'Настройки автообновления сохранены';
            }
            
            if (isset($settings_data['fttradingapi_history_thresholds'])) {
                update_option('fttradingapi_history_thresholds', $settings_data['fttradingapi_history_thresholds']);
                error_log('Debug Save Settings: Пороги записи истории сохранены');
                $result['thresholds'] = 'Пороги записи истории сохранены';
            }
            
            if (isset($settings_data['fttrader_graph_settings'])) {
                update_option('fttrader_graph_settings', $settings_data['fttrader_graph_settings']);
                error_log('Debug Save Settings: Настройки графиков сохранены');
                $result['graph'] = 'Настройки графиков сохранены';
            }
            
            // Отправляем результат
            error_log('Debug Save Settings: Все настройки успешно сохранены');
            wp_send_json_success([
                'message' => 'Настройки сохранены через AJAX',
                'details' => $result
            ]);
        } catch (Exception $e) {
            error_log('Debug Save Settings: Ошибка: ' . $e->getMessage());
            wp_send_json_error([
                'message' => 'Ошибка при сохранении настроек: ' . $e->getMessage()
            ]);
        }
    }

    /**
     * AJAX обработчик для получения информации о PHP
     */
    public function debug_php_info() {
        // Проверяем права
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Недостаточно прав']);
            return;
        }
        
        // Проверяем nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'fttrader_debug_nonce')) {
            wp_send_json_error(['message' => 'Неверный security token']);
            return;
        }
        
        // Собираем информацию о настройках PHP
        $php_info = [
            'max_execution_time' => ini_get('max_execution_time'),
            'memory_limit' => ini_get('memory_limit'),
            'post_max_size' => ini_get('post_max_size'),
            'upload_max_filesize' => ini_get('upload_max_filesize'),
        ];
        
        wp_send_json_success($php_info);
    }

    /**
     * Описание секции настроек режима API
     */
    public function render_api_mode_section_description()
    {
        echo '<p>Настройки режима подключения к API сервера MT4.</p>';
    }

    /**
     * Поле для выбора режима API
     */
    public function render_api_mode_field()
    {
        $api_mode = get_option('ft_api_mode', 'proxy');
        ?>
        <select name="ft_api_mode" id="ft_api_mode">
            <option value="proxy" <?php selected($api_mode, 'proxy'); ?>>Через WebAPI (128.140.100.35)</option>
            <option value="direct" <?php selected($api_mode, 'direct'); ?>>Прямое подключение к SERVERAPI</option>
        </select>
        <p class="description">Способ подключения к серверу MT4</p>

        <script>
        jQuery(document).ready(function($) {
            // Функция для управления видимостью полей настроек сервера
            function toggleServerSettings() {
                if ($('#ft_api_mode').val() === 'direct') {
                    $('.ft-server-settings').show();
                } else {
                    $('.ft-server-settings').hide();
                }
            }
            
            // Инициализация видимости при загрузке страницы
            toggleServerSettings();
            
            // Изменение видимости при смене режима
            $('#ft_api_mode').on('change', function() {
                toggleServerSettings();
            });
        });
        </script>
        <?php
    }

    /**
     * Поле для IP-адреса SERVERAPI
     */
    public function render_server_api_ip_field()
    {
        $server_ip = get_option('ft_server_api_ip', 'localhost');
        ?>
        <div class="ft-server-settings">
            <input type="text" id="ft_server_api_ip" name="ft_server_api_ip" value="<?php echo esc_attr($server_ip); ?>" class="regular-text">
            <p class="description">IP-адрес сервера SERVERAPI для прямого подключения</p>
        </div>
        <?php
    }

    /**
     * Поле для порта SERVERAPI
     */
    public function render_server_api_port_field()
    {
        $server_port = get_option('ft_server_api_port', '80');
        ?>
        <div class="ft-server-settings">
            <input type="text" id="ft_server_api_port" name="ft_server_api_port" value="<?php echo esc_attr($server_port); ?>" class="small-text">
            <p class="description">Порт сервера SERVERAPI для прямого подключения</p>
            
            <div style="margin-top: 10px;">
                <button type="button" id="check_connection_button" class="button-secondary">Проверить соединение</button>
                <span id="connection_status" style="margin-left: 10px;"></span>
            </div>
            
            <script>
            jQuery(document).ready(function($) {
                $('#check_connection_button').on('click', function() {
                    var $button = $(this);
                    var $status = $('#connection_status');
                    var server_ip = $('#ft_server_api_ip').val();
                    var server_port = $('#ft_server_api_port').val();
                    
                    // Блокируем кнопку и показываем сообщение
                    $button.prop('disabled', true);
                    $status.html('<span style="color: gray;">Проверка соединения...</span>');
                    
                    // Отправляем AJAX запрос
                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'check_direct_connection',
                            nonce: '<?php echo wp_create_nonce('check_direct_connection_nonce'); ?>',
                            server_ip: server_ip,
                            server_port: server_port
                        },
                        success: function(response) {
                            if (response.success) {
                                $status.html('<span style="color: green;">' + response.data.message + '</span>');
                            } else {
                                $status.html('<span style="color: red;">Ошибка: ' + 
                                    (response.data ? response.data.message : 'Неизвестная ошибка') + '</span>');
                            }
                            $button.prop('disabled', false);
                        },
                        error: function(xhr, status, error) {
                            $status.html('<span style="color: red;">Ошибка соединения: ' + error + '</span>');
                            $button.prop('disabled', false);
                        }
                    });
                });
            });
            </script>
        </div>
        <?php
    }
    
    /**
     * Обработчик AJAX для проверки соединения с SERVERAPI
     */
    public function check_direct_connection()
    {
        // Проверяем nonce
        check_ajax_referer('check_direct_connection_nonce', 'nonce');
        
        // Проверяем права доступа
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Недостаточно прав']);
            return;
        }
        
        // Получаем параметры
        $server_ip = isset($_POST['server_ip']) ? sanitize_text_field($_POST['server_ip']) : '';
        $server_port = isset($_POST['server_port']) ? sanitize_text_field($_POST['server_port']) : '';
        
        if (empty($server_ip) || empty($server_port)) {
            wp_send_json_error(['message' => 'IP-адрес или порт не указаны']);
            return;
        }
        
        $url = "http://{$server_ip}:{$server_port}/";
        
        $response = wp_remote_get($url, ['timeout' => 5]);
        
        if (is_wp_error($response)) {
            wp_send_json_error(['message' => 'Ошибка соединения: ' . $response->get_error_message()]);
            return;
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        
        if ($status_code === 200) {
            // Пытаемся распарсить ответ как JSON
            $data = json_decode($body, true);
            if (isset($data['status']) && $data['status'] === 'online') {
                wp_send_json_success(['message' => 'Соединение с SERVERAPI установлено успешно']);
                return;
            }
        }
        
        wp_send_json_error(['message' => "Не удалось установить соединение. Код ответа: {$status_code}"]);
    }
}

// Инициализация страницы настроек
new FTTrader_Settings_Page();
