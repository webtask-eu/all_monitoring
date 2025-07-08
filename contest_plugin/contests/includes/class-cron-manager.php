<?php
/**
 * Класс для управления задачами WP Cron
 */
class Contest_Cron_Manager
{

    /**
     * Получает интервал автоматического обновления из настроек (в секундах)
     * 
     * @return int Интервал в секундах
     */
    public static function get_auto_update_interval() {
        $settings = get_option('fttrader_auto_update_settings', []);
        $minutes = isset($settings['fttrader_min_update_interval']) ? intval($settings['fttrader_min_update_interval']) : 5;
        return $minutes * 60; // Конвертируем минуты в секунды
    }
    
    /**
     * Получает интервал обновления счетов с ошибками из настроек (в секундах)
     * 
     * @return int Интервал в секундах
     */
    public static function get_error_accounts_interval() {
        $settings = get_option('fttrader_auto_update_settings', []);
        $minutes = isset($settings['fttrader_error_accounts_interval']) ? intval($settings['fttrader_error_accounts_interval']) : 60;
        return $minutes * 60; // Конвертируем минуты в секунды
    }

    /**
     * Инициализация менеджера Cron
     */
    public static function init()
    {
        // Регистрируем хук для активации плагина
        register_activation_hook(FTTRADER_PLUGIN_FILE, [self::class, 'activate']);

        // Регистрируем хук для деактивации плагина
        register_deactivation_hook(FTTRADER_PLUGIN_FILE, [self::class, 'deactivate']);

        // Добавляем кастомный интервал для WP Cron
        add_filter('cron_schedules', [self::class, 'add_cron_interval']);

        // Проверяем и при необходимости восстанавливаем расписание
        add_action('admin_init', [self::class, 'ensure_scheduled_events']);
    }

    /**
     * Добавляет кастомный интервал для WP Cron
     * 
     * @param array $schedules Существующие интервалы
     * @return array Обновленные интервалы
     */
    public static function add_cron_interval($schedules)
    {
        $interval = self::get_auto_update_interval();
        $minutes = $interval / 60;
        
        $schedules['contest_auto_update'] = [
            'interval' => $interval,
            'display' => sprintf(__('Каждые %d минут (обновление счетов)'), $minutes)
        ];
        
        // Добавляем расписание для проверки дисквалификации - каждый час
        $schedules['contest_hourly_check'] = [
            'interval' => 3600,
            'display' => __('Каждый час (проверка дисквалификации)')
        ];
        
        // Добавляем расписание для проверки даты окончания регистрации - каждый час
        $schedules['contest_registration_check'] = [
            'interval' => 3600,
            'display' => __('Каждый час (проверка статуса регистрации)')
        ];
        
        return $schedules;
    }

    public static function clean_duplicate_events() {
        $crons = _get_cron_array();
        $found_timestamp = 0;
        $found_disq_timestamp = 0;
        $cleaned = 0;
        
        if (is_array($crons)) {
            foreach ($crons as $timestamp => $hooks) {
                if (isset($hooks['contest_create_queues'])) {
                    if ($found_timestamp === 0) {
                        // Сохраняем первое найденное событие
                        $found_timestamp = $timestamp;
                    } else {
                        // Удаляем все последующие события
                        foreach ($hooks['contest_create_queues'] as $key => $event) {
                            wp_unschedule_event($timestamp, 'contest_create_queues', $event['args'] ?? []);
                            $cleaned++;
                        }
                    }
                }
                
                if (isset($hooks['contest_accounts_disqualification_check'])) {
                    if ($found_disq_timestamp === 0) {
                        // Сохраняем первое найденное событие
                        $found_disq_timestamp = $timestamp;
                    } else {
                        // Удаляем все последующие события
                        foreach ($hooks['contest_accounts_disqualification_check'] as $key => $event) {
                            wp_unschedule_event($timestamp, 'contest_accounts_disqualification_check', $event['args'] ?? []);
                            $cleaned++;
                        }
                    }
                }
            }
        }
        
        return $cleaned;
    }

    
    /**
     * Активация расписания при активации плагина
     */
    public static function activate() {
        // Сначала удаляем все существующие события для наших хуков
        $crons = _get_cron_array();
        if (is_array($crons)) {
            foreach ($crons as $timestamp => $hooks) {
                if (isset($hooks['contest_create_queues'])) {
                    foreach ($hooks['contest_create_queues'] as $key => $event) {
                        wp_unschedule_event($timestamp, 'contest_create_queues', $event['args'] ?? []);
                    }
                }
                
                if (isset($hooks['contest_accounts_disqualification_check'])) {
                    foreach ($hooks['contest_accounts_disqualification_check'] as $key => $event) {
                        wp_unschedule_event($timestamp, 'contest_accounts_disqualification_check', $event['args'] ?? []);
                    }
                }
            }
        }
        
        // Теперь планируем новые события
        wp_schedule_event(time(), 'contest_auto_update', 'contest_create_queues');
        wp_schedule_event(time(), 'contest_hourly_check', 'contest_accounts_disqualification_check');
        wp_schedule_event(time(), 'contest_registration_check', 'contest_registration_status_check');
    }
    

    /**
     * Удаление расписания при деактивации плагина
     */
    public static function deactivate()
    {
        // Отменяем запланированные задачи
        $timestamp = wp_next_scheduled('contest_create_queues');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'contest_create_queues');
        }
        
        $timestamp = wp_next_scheduled('contest_accounts_disqualification_check');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'contest_accounts_disqualification_check');
        }
        
        $timestamp = wp_next_scheduled('contest_registration_status_check');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'contest_registration_status_check');
        }
    }

    /**
     * Проверяет и при необходимости восстанавливает расписание
     */
    public static function ensure_scheduled_events() {
        // Проверяем, настроено ли автоматическое обновление счетов
        $auto_events = wp_next_scheduled('contest_create_queues');
        if ($auto_events === false) {
            wp_schedule_event(time(), 'contest_auto_update', 'contest_create_queues');
        }
        
        // Проверяем, настроена ли автоматическая проверка дисквалификации
        $disq_events = wp_next_scheduled('contest_accounts_disqualification_check');
        if ($disq_events === 0) {
            wp_schedule_event(time(), 'contest_hourly_check', 'contest_accounts_disqualification_check');
        }
        
        // Проверяем, настроена ли автоматическая проверка статуса регистрации
        $reg_events = wp_next_scheduled('contest_registration_status_check');
        if ($reg_events === 0) {
            wp_schedule_event(time(), 'contest_registration_check', 'contest_registration_status_check');
        }
    }
    


    /**
     * Запускает задачу обновления счетов вручную
     */
    public static function run_now()
    {
        // Перед запуском обновления, загружаем класс Account_Updater
        // и проверяем наличие метода run_auto_update
        if (class_exists('Account_Updater') || require_once dirname(__FILE__) . '/class-account-updater.php') {
            // Создаем запись в логе, что запущено ручное обновление
            self::log_cron_execution();
            
            // Запускаем обновление с явной установкой флага is_auto_update в true
            do_action('contest_create_queues');
            
            // Запускаем проверку условий дисквалификации сразу после обновления счетов
            self::run_disqualification_check_now();
            
            error_log('Ручное обновление счетов и проверка дисквалификации запущены.');
        }
    }

    /**
     * Запускает задачу проверки дисквалификации счетов вручную
     */
    public static function run_disqualification_check_now()
    {
        do_action('contest_accounts_disqualification_check');
    }

    /**
     * Запускает задачу проверки статуса регистрации вручную
     */
    public static function run_registration_status_check_now()
    {
        do_action('contest_registration_status_check');
    }

    /**
     * Проверяет состояние WP Cron и возвращает диагностическую информацию
     * 
     * @return array Массив с диагностической информацией
     */
    public static function check_cron_status()
    {
        $status = array(
            'is_cron_enabled' => !defined('DISABLE_WP_CRON') || !DISABLE_WP_CRON,
            'next_scheduled' => wp_next_scheduled('contest_create_queues'),
            'all_scheduled_events' => array(),
            'registered_intervals' => wp_get_schedules(),
            'last_run' => get_option('contest_create_queues_last_run', 0),
            'current_time' => time(),
            'wp_time' => current_time('timestamp'),
            'auto_update_settings' => get_option('fttrader_auto_update_settings', array())
        );

        // Получаем все запланированные события
        $crons = _get_cron_array();
        if (!empty($crons)) {
            foreach ($crons as $timestamp => $cronhooks) {
                foreach ($cronhooks as $hook => $events) {
                    foreach ($events as $key => $event) {
                        $status['all_scheduled_events'][] = array(
                            'hook' => $hook,
                            'timestamp' => $timestamp,
                            'schedule' => isset($event['schedule']) ? $event['schedule'] : 'once',
                            'time' => date('Y-m-d H:i:s', $timestamp)
                        );
                    }
                }
            }
        }

        // Проверяем, есть ли наш хук в запланированных событиях
        $status['our_hook_scheduled'] = false;
        foreach ($status['all_scheduled_events'] as $event) {
            if ($event['hook'] === 'contest_create_queues') {
                $status['our_hook_scheduled'] = true;
                break;
            }
        }

        // Проверяем, зарегистрирован ли наш интервал
        $status['our_interval_registered'] = isset($status['registered_intervals']['contest_auto_update']);

        // Проверяем, не блокируется ли WP Cron на уровне сервера
        $status['cron_url_accessible'] = false;
        $response = wp_remote_get(site_url('wp-cron.php'));
        if (!is_wp_error($response)) {
            $status['cron_url_accessible'] = true;
            $status['cron_response_code'] = wp_remote_retrieve_response_code($response);
        } else {
            $status['cron_error'] = $response->get_error_message();
        }

        return $status;
    }

    /**
     * Записывает информацию о запуске cron-задачи
     */
    public static function log_cron_execution() {
        // Записываем время запуска
        $current_time = time();
        
        // Получаем предыдущие запуски
        $executions = get_option('contest_cron_executions', []);
        
        // Проверяем, не было ли недавнего запуска (в течение последних 30 секунд)
        if (!empty($executions)) {
            $last_execution = $executions[0];
            
            // Если последний запуск был менее 30 секунд назад, не добавляем новую запись
            if (isset($last_execution['time']) && ($current_time - $last_execution['time']) < 30) {
                return;
            }
        }
        
        // Обновляем время последнего запуска
        update_option('contest_cron_last_execution', $current_time);
        
        // Добавляем текущий запуск в начало массива с дополнительной информацией
        array_unshift($executions, [
            'time' => $current_time,
            'timestamp' => date('Y-m-d H:i:s', $current_time),
            'wp_time' => current_time('mysql'),
            'server_time' => date('Y-m-d H:i:s'),
            'request_id' => uniqid(), // Уникальный идентификатор запроса
            'backtrace' => self::get_simplified_backtrace() // Добавляем информацию о вызове
        ]);
        
        // Ограничиваем количество сохраняемых записей
        if (count($executions) > 20) {
            $executions = array_slice($executions, 0, 20);
        }
        
        // Сохраняем обновленный список запусков
        update_option('contest_cron_executions', $executions);
    }

    /**
     * Получает упрощенный стек вызовов для отладки
     * 
     * @return string Упрощенный стек вызовов
     */
    private static function get_simplified_backtrace() {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 5);
        $simplified = [];
        
        foreach ($trace as $call) {
            if (isset($call['class']) && isset($call['function'])) {
                $simplified[] = $call['class'] . '::' . $call['function'];
            } elseif (isset($call['function'])) {
                $simplified[] = $call['function'];
            }
        }
        
        return implode(' <- ', $simplified);
    }

}

// Инициализация менеджера Cron
Contest_Cron_Manager::init();
