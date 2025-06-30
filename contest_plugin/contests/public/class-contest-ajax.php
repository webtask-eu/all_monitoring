<?php
/**
 * AJAX обработчики для публичной части плагина
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Класс для обработки AJAX запросов с фронтенда
 */
class Contest_Public_Ajax {
    
    /**
    * Инициализация AJAX обработчиков
    */
    public function init() {
        // Регистрация счета в конкурсе
        add_action('wp_ajax_register_contest_account', array($this, 'register_contest_account'));
        add_action('wp_ajax_nopriv_register_contest_account', array($this, 'register_contest_account_unauthorized'));
        
        // Добавьте эти строки, если их нет
        add_action('wp_ajax_update_contest_account_data', array($this, 'update_contest_account_data'));
        add_action('wp_ajax_nopriv_update_contest_account_data', array($this, 'register_contest_account_unauthorized'));
        
        // Получение данных для графика
        add_action('wp_ajax_get_account_chart_data', array($this, 'get_account_chart_data'));
        add_action('wp_ajax_nopriv_get_account_chart_data', array($this, 'get_account_chart_data'));
        
        // Новый обработчик для обновления данных конкурсов
        add_action('wp_ajax_update_contests_data', array($this, 'update_contests_data'));
        add_action('wp_ajax_nopriv_update_contests_data', array($this, 'update_contests_data')); 
        
        // Добавляем обработчик для загрузки формы регистрации
        add_action('wp_ajax_load_registration_form', array($this, 'load_registration_form'));
        add_action('wp_ajax_nopriv_load_registration_form', array($this, 'load_registration_form'));

        // Добавляем обработчик для обновления данных счета на фронтенде
        add_action('wp_ajax_update_account_frontend', array($this, 'update_account_frontend'));
        add_action('wp_ajax_check_account_disqualification', array($this, 'check_account_disqualification'));
        
        // Добавляем обработчик для снятия дисквалификации счета
        add_action('wp_ajax_remove_account_disqualification', array($this, 'remove_account_disqualification'));
    }
    
/**
 * AJAX-обработчик для загрузки формы регистрации счета
 */
public function load_registration_form() {
    // Проверяем nonce для безопасности
    check_ajax_referer('load_registration_form_nonce', 'nonce');
    
    // Получаем ID конкурса
    $contest_id = isset($_POST['contest_id']) ? intval($_POST['contest_id']) : 0;
    
    if (!$contest_id) {
        wp_send_json_error(['message' => 'ID конкурса не указан']);
        return;
    }
    
    // Загружаем шаблон формы
    ob_start();
    include plugin_dir_path(dirname(__FILE__)) . 'templates/parts/registration-form.php';
    $form_html = ob_get_clean();
    
    // Возвращаем HTML-код формы
    wp_send_json_success(['html' => $form_html]);
}

    
    /**
     * Обновление данных счета
     */
    public function update_contest_account_data() {
        // Проверка nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'ft_contest_nonce')) {
            wp_send_json_error('Ошибка безопасности');
            return;
        }
        
        // Проверка ID счета
        if (!isset($_POST['account_id'])) {
            wp_send_json_error('Не указан ID счета');
            return;
        }
        
        $account_id = intval($_POST['account_id']);
        
        // Проверка владельца счета
        global $wpdb;
        $user_id = get_current_user_id();
        
        $table_name = $wpdb->prefix . 'contest_members';
        
        // Получаем данные счета без проверки владельца
        $account = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_name WHERE id = %d",
            $account_id
        ));
        
        if (!$account) {
            wp_send_json_error('Счет не найден');
            return;
        }
        
        // Проверяем права: пользователь должен быть владельцем счета или администратором
        if ($account->user_id != $user_id && !current_user_can('manage_options')) {
            wp_send_json_error('У вас нет прав для обновления этого счета');
            return;
        }
        
        // Подготавливаем данные для обновления
        $account_data = [
            'account_number' => $account->account_number
        ];
        
        // Добавляем только те поля, которые были переданы
        if (isset($_POST['password']) && !empty($_POST['password'])) {
            $account_data['password'] = sanitize_text_field($_POST['password']);
        } else {
            $account_data['password'] = $account->password;
        }
        
        // Получаем данные брокера и платформы, если они указаны
        if (isset($_POST['broker']) && !empty($_POST['broker'])) {
            $broker_id = intval($_POST['broker']);
            
            // Получаем информацию о брокере по ID
            if (class_exists('FTTrader_Brokers_Platforms')) {
                $broker = FTTrader_Brokers_Platforms::get_broker($broker_id);
                if ($broker) {
                    $account_data['broker'] = $broker->name;
                }
            }
        } else if (!empty($account->broker)) {
            $account_data['broker'] = $account->broker;
        }
        
        // Получаем данные платформы
        if (isset($_POST['platform']) && !empty($_POST['platform'])) {
            $platform_id = intval($_POST['platform']);
            
            // Получаем информацию о платформе по ID
            if (class_exists('FTTrader_Brokers_Platforms')) {
                $platform = FTTrader_Brokers_Platforms::get_platform($platform_id);
                if ($platform) {
                    $account_data['platform'] = $platform->name;
                }
            }
        } else if (!empty($account->platform)) {
            $account_data['platform'] = $account->platform;
        }
        
        // Получаем адрес сервера
        if (isset($_POST['server'])) {
            $account_data['server'] = sanitize_text_field($_POST['server']);
        } else if (!empty($account->server)) {
            $account_data['server'] = $account->server;
        }
        
        if (isset($_POST['terminal'])) {
            $account_data['terminal'] = sanitize_text_field($_POST['terminal']);
        } else {
            $account_data['terminal'] = $account->terminal;
        }
        
        // Проверяем наличие файла и функции
        $api_handler_file = plugin_dir_path(dirname(__FILE__)) . 'includes/class-api-handler.php';
        if (!file_exists($api_handler_file)) {
            wp_send_json_error('Файл API обработчика не найден');
            return;
        }
        
        // Подключаем API-обработчик
        require_once($api_handler_file);
        
        // Проверяем существование функции
        if (!function_exists('process_trading_account')) {
            wp_send_json_error('Функция обновления счета не найдена');
            return;
        }
        
        // Генерируем очень короткий queue_batch_id для лучшей читаемости логов
        $letters = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $random_letters = '';
        // Ограничиваем до 4 символов (q + 4 буквы = 5 символов всего)
        for ($i = 0; $i < 4; $i++) {
            $random_letters .= $letters[rand(0, strlen($letters) - 1)];
        }
        // Финальный формат: q1234
        $queue_batch_id = 'q' . $random_letters;
        
        // Добавляем отладочный вывод
        if (function_exists('ft_api_log')) {
            ft_api_log([
                'account_id' => $account_id,
                'queue_batch_id' => $queue_batch_id,
                'user_id' => $user_id,
                'account_data' => $account_data
            ], "Запрос на обновление данных счета", "info");
        } else {
            error_log("QUEUE_BATCH_ID: созданный идентификатор: {$queue_batch_id}, длина: " . strlen($queue_batch_id));
        }
        
        // Вызываем функцию обновления счета для получения свежих данных с сервера
        try {
            // Передаем queue_batch_id в process_trading_account
            $result = process_trading_account($account_data, $account_id, null, $queue_batch_id);
            
            // Логируем результат для отладки
            if (function_exists('ft_api_log')) {
                ft_api_log($result, "Результат вызова process_trading_account", "info");
                ft_api_log("Проверка успешности результата: " . (isset($result['success']) ? ($result['success'] ? "TRUE" : "FALSE") : "Отсутствует"), "Проверка результата", "info");
            } else {
                error_log("[UPDATE_CONTEST_ACCOUNT_DATA] Результат вызова process_trading_account: " . print_r($result, true));
            }
            
            // Проверяем структуру результата
            if (!is_array($result)) {
                ft_api_log("Результат не является массивом: " . var_export($result, true), "Ошибка формата результата", "error");
                wp_send_json_error([
                    'message' => 'Некорректный формат ответа от API',
                    'queue_batch_id' => $queue_batch_id,
                    'debug_info' => 'Результат не является массивом: ' . var_export($result, true)
                ]);
                return;
            }
            
            // Явное приведение $result['success'] к булеву значению
            $is_success = false;
            if (isset($result['success'])) {
                // Приводим к булеву: все непустые значения (1, "1", "true", true и т.д.) станут true
                $is_success = (bool)$result['success']; 
                ft_api_log("Значение success после приведения: " . ($is_success ? "TRUE" : "FALSE"), "Обработка результата", "info");
            }
            
            if ($is_success) {
                // Получаем обновленные данные счета
                $updated_account = $wpdb->get_row($wpdb->prepare(
                    "SELECT * FROM $table_name WHERE id = %d",
                    $account_id
                ));
                
                // Проверяем, что данные успешно получены
                if (!$updated_account) {
                    ft_api_log("Не удалось получить обновленные данные счета $account_id из БД", "Ошибка получения данных", "error");
                    wp_send_json_error([
                        'message' => 'Ошибка: не удалось получить обновленные данные счета',
                        'queue_batch_id' => $queue_batch_id
                    ]);
                    return;
                }
                
                ft_api_log([
                    'account_id' => $account_id,
                    'balance' => $updated_account->balance,
                    'equity' => $updated_account->equity,
                    'connection_status' => $updated_account->connection_status
                ], "Данные счета обновлены успешно", "info");
                
                // Вместо немедленной проверки дисквалификации, планируем отложенную проверку через WP Cron
                if (!wp_next_scheduled('check_account_disqualification', [$account_id])) {
                    wp_schedule_single_event(time() + 5, 'check_account_disqualification', [$account_id]);
                    error_log("[DISQUALIFICATION] Запланирована отложенная проверка дисквалификации для счета ID: {$account_id}");
                }
                
                // Проверяем только текущий статус подключения для немедленного ответа
                // Проверка дисквалификации будет выполнена отложенно
                if ($updated_account->connection_status === 'disqualified') {
                    ft_api_log("Счет $account_id дисквалифицирован: " . $updated_account->error_description, "Обнаружена дисквалификация", "info");
                    
                    // Передаем причину дисквалификации в структурированном виде
                    wp_send_json_error([
                        'message' => 'Счет дисквалифицирован',
                        'disqualified' => true,
                        'error_description' => $updated_account->error_description,
                        'queue_batch_id' => $queue_batch_id
                    ]);
                    return;
                }
                
                // Включаем данные аккаунта в ответ для возможности асинхронного обновления на клиенте
                $account_data = [
                    'id' => $updated_account->id,
                    'balance' => $updated_account->balance,
                    'equity' => $updated_account->equity,
                    'margin' => $updated_account->margin,
                    'profit' => $updated_account->profit,
                    'leverage' => $updated_account->leverage,
                    'currency' => $updated_account->currency,
                    'connection_status' => $updated_account->connection_status,
                    'error_description' => $updated_account->error_description,
                    'last_update' => $updated_account->last_update
                ];
                
                // Добавляем заголовок с queue_batch_id для отслеживания в JavaScript
                header('X-Queue-Batch-ID: ' . $queue_batch_id);
                
                wp_send_json_success([
                    'message' => 'Данные счета успешно обновлены',
                    'time' => current_time('mysql'),
                    'server' => $updated_account->server,
                    'terminal' => $updated_account->terminal,
                    'account_data' => $account_data, // Данные для асинхронного обновления
                    'queue_batch_id' => $queue_batch_id // Добавляем queue_batch_id в ответ
                ]);
                return; // Явно завершаем функцию после отправки успешного ответа
            } else {
                $error_message = isset($result['message']) ? $result['message'] : 'Не удалось обновить данные счета';
                
                // Логируем ошибку для отладки
                if (function_exists('ft_api_log')) {
                    ft_api_log([
                        'error_message' => $error_message,
                        'result' => $result,
                        'account_id' => $account_id,
                        'queue_batch_id' => $queue_batch_id
                    ], "Ошибка обновления данных счета", "error");
                }
                
                // Проверка наличия сообщения об отсутствии финансовых данных
                if (strpos($error_message, 'Отсутствуют необходимые финансовые данные') !== false) {
                    // Заменяем на более понятное сообщение об ошибке подключения
                    $error_message = 'Не удалось подключиться к счёту. Проверьте логин, пароль и сервер. Что можно попробовать: 1) убедитесь, что пароль введён верно; 2) выберите другой сервер в списке; 3) подключитесь с торговым паролем (а не инвесторским); 4) перед добавлением счёта в конкурс закройте терминал на локальном компьютере.';
                }
                
                // Добавляем заголовок с queue_batch_id для отслеживания в JavaScript
                header('X-Queue-Batch-ID: ' . $queue_batch_id);
                
                // Добавляем информацию о текущем состоянии счёта
                $current_account = $wpdb->get_row($wpdb->prepare(
                    "SELECT * FROM $table_name WHERE id = %d",
                    $account_id
                ));
                
                // Добавляем queue_batch_id в ответ с ошибкой для отслеживания в консоли
                wp_send_json_error([
                    'message' => $error_message,
                    'queue_batch_id' => $queue_batch_id,
                    'account_status' => $current_account ? $current_account->connection_status : 'unknown',
                    'debug_info' => isset($result['debug_info']) ? $result['debug_info'] : null
                ]);
                return; // Явно завершаем функцию
            }
        } catch (Exception $e) {
            // Логируем подробную информацию об ошибке
            if (function_exists('ft_api_log')) {
                ft_api_log([
                    'message' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'trace' => $e->getTraceAsString(),
                    'account_id' => $account_id,
                    'queue_batch_id' => $queue_batch_id
                ], "Исключение при обновлении счета", "error");
            } else {
                error_log('[UPDATE_CONTEST_ACCOUNT_DATA] Исключение при обновлении счета: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
            }
            
            // Добавляем заголовок с queue_batch_id для отслеживания в JavaScript
            header('X-Queue-Batch-ID: ' . $queue_batch_id);
            
            wp_send_json_error([
                'message' => 'Ошибка: ' . $e->getMessage(),
                'queue_batch_id' => $queue_batch_id,
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
            return; // Явно завершаем функцию
        }
        
        // Защита от непредвиденных сценариев: если код дошел до этой точки, значит что-то пошло не так
        ft_api_log("Непредвиденное завершение метода update_contest_account_data без return", "Ошибка выполнения", "error");
        wp_send_json_error([
            'message' => 'Непредвиденная ошибка обработки запроса',
            'queue_batch_id' => $queue_batch_id
        ]);
    }

    

    /**
     * Обработчик для неавторизованных пользователей
     */
    public function update_contest_account_unauthorized() {
        wp_send_json_error('Вы должны быть авторизованы для обновления счета');
    }


    /**
     * Регистрация счета в конкурсе
     */
    public function register_contest_account() {
        // Отладочная информация в самом начале выполнения
        error_log('[REGISTER_CONTEST_ACCOUNT] Начало выполнения функции');
        error_log('[REGISTER_CONTEST_ACCOUNT] POST данные: ' . json_encode($_POST));
        
        // Проверка nonce для безопасности
        if (!isset($_POST['nonce']) || 
            (!wp_verify_nonce($_POST['nonce'], 'ft_contest_nonce') && 
            !wp_verify_nonce($_POST['nonce'], 'contest_register_nonce'))) {
            
            error_log('[REGISTER_CONTEST_ACCOUNT] Ошибка nonce');
            wp_send_json_error(['message' => 'Ошибка безопасности 3. Пожалуйста, обновите страницу и попробуйте снова.']);
            return;
        }

        // Проверка авторизации пользователя
        if (!is_user_logged_in()) {
            error_log('[REGISTER_CONTEST_ACCOUNT] Пользователь не авторизован');
            wp_send_json_error(array('message' => 'Вы должны быть авторизованы для регистрации счета.'));
            return;
        }
        
        // Проверка необходимых данных
        if (!isset($_POST['contest_id']) || !isset($_POST['account_number']) || !isset($_POST['password']) || !isset($_POST['server']) || !isset($_POST['terminal'])) {
            error_log('[REGISTER_CONTEST_ACCOUNT] Не все необходимые данные предоставлены. Получено: ' . json_encode(array_keys($_POST)));
            wp_send_json_error(array('message' => 'Не все необходимые данные были предоставлены.'));
            return;
        }
        
        // Получаем и проверяем данные
        $contest_id = intval($_POST['contest_id']);
        $account_number = sanitize_text_field($_POST['account_number']);
        $password = sanitize_text_field($_POST['password']);
        $server = sanitize_text_field($_POST['server']);
        $terminal = sanitize_text_field($_POST['terminal']);
        
        error_log('[REGISTER_CONTEST_ACCOUNT] Подготовленные данные: ' . json_encode([
            'contest_id' => $contest_id,
            'account_number' => $account_number,
            'password_length' => strlen($password),
            'server' => $server,
            'terminal' => $terminal
        ]));
        
        // Проверяем существование конкурса
        $contest = get_post($contest_id);
        if (!$contest || $contest->post_type !== 'trader_contests') {
            error_log('[REGISTER_CONTEST_ACCOUNT] Конкурс не найден: ' . $contest_id);
            wp_send_json_error(array('message' => 'Конкурс не найден.'));
            return;
        }
        
        // Проверяем, не закончился ли конкурс
        $end_date = get_post_meta($contest_id, '_contest_end_date', true);
        if ($end_date && strtotime($end_date) < current_time('timestamp')) {
            error_log('[REGISTER_CONTEST_ACCOUNT] Конкурс завершен: ' . $end_date);
            wp_send_json_error(array('message' => 'Конкурс завершен. Регистрация новых счетов недоступна.'));
            return;
        }
        
        // Проверяем, не начался ли период регистрации
        $reg_start_date = get_post_meta($contest_id, '_contest_registration_start', true);
        if ($reg_start_date && strtotime($reg_start_date) > current_time('timestamp')) {
            error_log('[REGISTER_CONTEST_ACCOUNT] Регистрация еще не началась: ' . $reg_start_date);
            wp_send_json_error(array('message' => 'Регистрация в конкурсе еще не началась.'));
            return;
        }
        
        // Проверяем, не закончился ли период регистрации
        $reg_end_date = get_post_meta($contest_id, '_contest_registration_end', true);
        if ($reg_end_date && strtotime($reg_end_date) < current_time('timestamp')) {
            error_log('[REGISTER_CONTEST_ACCOUNT] Период регистрации завершен: ' . $reg_end_date);
            wp_send_json_error(array('message' => 'Период регистрации в конкурсе завершен.'));
            return;
        }
        
        // Проверяем статус регистрации конкурса
        $contest_data = get_post_meta($contest_id, '_fttradingapi_contest_data', true);
        $registration_status = isset($contest_data['registration']) ? $contest_data['registration'] : 'open';
        
        if ($registration_status === 'closed') {
            error_log('[REGISTER_CONTEST_ACCOUNT] Регистрация закрыта. Статус: ' . $registration_status);
            wp_send_json_error(array('message' => 'Регистрация в конкурсе закрыта. Регистрация новых счетов недоступна.'));
            return;
        }
        
        // Проверяем, не зарегистрирован ли уже счет пользователя
        $user_id = get_current_user_id();
        global $wpdb;
        $table_name = $wpdb->prefix . 'contest_members';
        $existing_account = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_name WHERE user_id = %d AND contest_id = %d",
            $user_id,
            $contest_id
        ));
        
        if ($existing_account) {
            error_log('[REGISTER_CONTEST_ACCOUNT] Пользователь уже зарегистрировал счет. User ID: ' . $user_id . ', Account ID: ' . $existing_account->id);
            wp_send_json_error(array('message' => 'Вы уже зарегистрировали счет в этом конкурсе.'));
            return;
        }
        
        // Проверяем, не зарегистрирован ли уже этот счет в конкурсе
        $existing_account_number = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_name WHERE account_number = %s AND server = %s",
            $account_number,
            $server
        ));

        if ($existing_account_number) {
            error_log('[REGISTER_CONTEST_ACCOUNT] Счет уже зарегистрирован. Account number: ' . $account_number . ', Server: ' . $server);
            wp_send_json_error(array('message' => 'Этот номер счета уже зарегистрирован в системе.'));
            return;
        }

        // Создаем данные для проверки и регистрации счета
        $account_data = [
            'account_number' => $account_number,
            'password' => $password,
            'server' => $server,
            'terminal' => $terminal
        ];

        error_log('[REGISTER_CONTEST_ACCOUNT] Отправка на проверку в process_trading_account. Данные: ' . json_encode([
            'account_number' => $account_number,
            'password_length' => strlen($password),
            'server' => $server,
            'terminal' => $terminal,
            'contest_id' => $contest_id
        ]));

        // Для диагностики проверим существование всех нужных файлов и классов
        $api_handler_file = plugin_dir_path(dirname(__FILE__)) . 'includes/class-api-handler.php';
        if (!file_exists($api_handler_file)) {
            error_log('[REGISTER_CONTEST_ACCOUNT] КРИТИЧЕСКАЯ ОШИБКА: Файл class-api-handler.php не найден');
            wp_send_json_error(array('message' => 'Внутренняя ошибка сервера: файл API обработчика не найден'));
            return;
        }
        
        // Подключаем API обработчик
        require_once($api_handler_file);
        
        // Проверяем существование функции
        if (!function_exists('process_trading_account')) {
            error_log('[REGISTER_CONTEST_ACCOUNT] КРИТИЧЕСКАЯ ОШИБКА: Функция process_trading_account не найдена');
            wp_send_json_error(array('message' => 'Внутренняя ошибка сервера: функция обработки счета не найдена'));
            return;
        }

        // Используем функцию process_trading_account для регистрации счета
        // Эта же функция используется и для обновления счета
        $result = process_trading_account($account_data, null, $contest_id);

        error_log('[REGISTER_CONTEST_ACCOUNT] Результат process_trading_account: ' . json_encode($result));

        if (!$result['success']) {
            // Добавляем подробное логирование ошибки
            error_log('[REGISTER_CONTEST_ACCOUNT] Ошибка подключения: ' . ($result['message'] ?? 'Неизвестная ошибка'));
            if (isset($result['debug_info'])) {
                error_log('[REGISTER_CONTEST_ACCOUNT] Детали ошибки: ' . $result['debug_info']);
            }
            
            // Формируем информативное сообщение об ошибке
            $error_message = isset($result['message']) ? $result['message'] : 'Неизвестная ошибка';
            
            // Для стандартных ошибок подключения формируем более понятное сообщение
            if (strpos($error_message, 'не удалось подключиться к счёту') !== false || 
                strpos($error_message, 'Неверный логин или пароль') !== false ||
                strpos($error_message, 'Invalid account') !== false) {
                $error_message = 'Не удалось подключиться к счету. Пожалуйста, проверьте правильность введенных данных (номер счета, пароль, сервер). Также возможные причины: счет не активен, используется не инвесторский пароль, терминал уже подключен к этому счету.';
            }
            
            // Если API вернуло ошибку, сразу возвращаем ее клиенту
            wp_send_json_error(array('message' => 'Ошибка подключения: ' . $error_message));
            return;
        }
        
        // Получаем ID созданного счета
        $account_id = isset($result['account_data']['id']) ? $result['account_data']['id'] : 0;
        
        if (!$account_id) {
            error_log('[REGISTER_CONTEST_ACCOUNT] Не получен ID счета');
            wp_send_json_error(array('message' => 'Ошибка при создании счета: не получен ID счета'));
            return;
        }
        
        // URL для перенаправления на страницу счета
        $redirect_url = add_query_arg(array(
            'contest_account' => $account_id,
            'contest_id' => $contest_id
        ), get_permalink($contest_id));
        
        error_log('[REGISTER_CONTEST_ACCOUNT] Успешное завершение. ID счета: ' . $account_id . ', URL: ' . $redirect_url);
        
        // Отправляем успешный ответ
        wp_send_json_success(array(
            'message' => 'Счет успешно зарегистрирован в конкурсе!',
            'redirect' => $redirect_url
        ));
    }

    
    /**
     * Обработчик для неавторизованных пользователей
     */
    public function register_contest_account_unauthorized() {
        wp_send_json_error(array('message' => 'Вы должны войти в систему, чтобы зарегистрировать счет.'));
    }

    
    /**
     * Получение данных для графика счета
     */
    public function get_account_chart_data() {
        // Проверка nonce для безопасности
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'ft_contest_nonce')) {
            wp_send_json_error(['message' => 'Ошибка безопасности 4. Пожалуйста, обновите страницу и попробуйте снова.']);
        }
        
        // Проверка необходимых данных
        if (!isset($_POST['account_id']) || !isset($_POST['period'])) {
            wp_send_json_error(array('message' => 'Не все необходимые данные были предоставлены.'));
        }
        
        $account_id = intval($_POST['account_id']);
        $period = sanitize_text_field($_POST['period']);
        
        // Проверяем, существует ли счет
        global $wpdb;
        $table_name = $wpdb->prefix . 'contest_members';
        $account = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_name WHERE id = %d",
            $account_id
        ));
        
        if (!$account) {
            wp_send_json_error(array('message' => 'Счет не найден.'));
        }
        
        // Разрешаем просмотр графика владельцу счета и всем пользователям (т.к. график публичный)
        $account_chart = new Account_Chart_Data();
        $chart_data = $account_chart->get_chart_data($account_id, $period);
        
        // Отправляем данные
        wp_send_json_success($chart_data);
    }
    
    /**
     * Получение IP-адреса клиента
     */
    private function get_client_ip() {
        // Учитываем возможные прокси
        if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
            $ip = $_SERVER['HTTP_CLIENT_IP'];
        } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            // HTTP_X_FORWARDED_FOR может содержать несколько адресов, разделенных запятыми
            $ip_list = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
            $ip = trim($ip_list[0]);
        } else {
            $ip = $_SERVER['REMOTE_ADDR'];
        }
        
        return sanitize_text_field($ip);
    }
    
    /**
     * Получение информации о стране по IP адресу
     */
    private function get_country_by_ip($ip) {
        // Значения по умолчанию, если не удалось определить страну
        $country_data = array(
            'code' => '',
            'name' => ''
        );
        
        // Используем бесплатный API для определения страны
        $response = wp_remote_get("https://ipapi.co/{$ip}/json/");
        
        if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
            $data = json_decode(wp_remote_retrieve_body($response), true);
            
            if (isset($data['country']) && isset($data['country_name'])) {
                $country_data['code'] = strtolower($data['country']);
                $country_data['name'] = $data['country_name'];
            }
        }
        
        return $country_data;
    }
    /**
     * AJAX-обработчик для обновления данных конкурсов
     */
    public function update_contests_data() {
        // Управление кешированием
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Cache-Control: post-check=0, pre-check=0', false);
        header('Pragma: no-cache');
        
        // Проверка nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'ft_contest_nonce')) {
            wp_send_json_error('Ошибка безопасности');
            return;
        }
        
        global $wpdb;
        $table_name = $wpdb->prefix . 'contest_members';
        
        // Получаем ID конкурса (если передан)
        $contest_id = isset($_POST['contest_id']) ? intval($_POST['contest_id']) : 0;
        
        // Если ID конкурса передан, получаем участников только этого конкурса
        if ($contest_id > 0) {
            $contest_participants = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM $table_name WHERE contest_id = %d ORDER BY equity DESC",
                $contest_id
            ));
            
            $initial_deposit = get_post_meta($contest_id, '_fttradingapi_contest_data', true);
            $initial_deposit = isset($initial_deposit['start_deposit']) ? floatval($initial_deposit['start_deposit']) : 10000;
            
            // Рассчитываем статистику конкурса
            $statistics = $this->calculate_contest_statistics($contest_participants, $initial_deposit);
            
            // Форматируем участников для отправки
            $formatted_participants = [];
            foreach ($contest_participants as $participant) {
                $formatted_participants[] = [
                    'id' => $participant->id,
                    'equity' => number_format($participant->equity, 2) . ' ' . $participant->currency,
                    'balance' => number_format($participant->balance, 2) . ' ' . $participant->currency,
                    'status' => $this->get_status_text($participant->connection_status),
                    'orders_count' => isset($participant->orders_history_total) ? intval($participant->orders_history_total) : 0,
                    'last_update' => $participant->last_update,
                ];
            }
            
            // Отправляем данные
            wp_send_json_success([
                'participants' => $formatted_participants,
                'statistics' => $statistics,
                'timestamp' => date_i18n('d.m.Y, H:i', current_time('timestamp'))
            ]);
            return;
        }
        
        // Общее количество участников
        $total_participants = $wpdb->get_var("SELECT COUNT(*) FROM $table_name");
        
        // Общий призовой фонд
        $total_prize_fund = 0;
        $contests_data = [];
        
        $contests_query = new WP_Query([
            'post_type' => 'trader_contests',
            'posts_per_page' => -1,
            'fields' => 'ids'
        ]);
        
        if ($contests_query->have_posts()) {
            foreach ($contests_query->posts as $contest_id) {
                // Добавляем отладочные данные
                $contest_title = get_the_title($contest_id);
                error_log("Обработка конкурса ID: {$contest_id}, Название: {$contest_title}");
                
                // Сначала проверяем новый формат призовых мест
                $contest_data = get_post_meta($contest_id, '_fttradingapi_contest_data', true);
                $prizes = isset($contest_data['prizes']) ? $contest_data['prizes'] : array();

                if (!empty($prizes)) {
                    // Суммируем призы из структурированных данных
                    $contest_total = 0;
                    foreach ($prizes as $prize) {
                        // Извлекаем числовое значение из строки (например, "$1000" -> 1000)
                        $amount = preg_replace('/[^0-9.]/', '', $prize['amount']);
                        $contest_total += floatval($amount);
                        error_log("    Приз за {$prize['place']} место: {$prize['amount']}, извлечено: {$amount}");
                    }
                    error_log("    Итого призовой фонд конкурса (новый формат): {$contest_total}");
                    $total_prize_fund += $contest_total;
                } else {
                    // Если нет структурированных данных, используем старый формат
                    $prize_fund = get_post_meta($contest_id, '_contest_prize_fund', true);
                    if (!empty($prize_fund)) {
                        // Извлекаем числовое значение из строки (например, "$1000" -> 1000)
                        $prize_value = preg_replace('/[^0-9.]/', '', $prize_fund);
                        $contest_total = floatval($prize_value);
                        error_log("    Призовой фонд конкурса (старый формат): {$prize_fund}, извлечено: {$prize_value}");
                        $total_prize_fund += $contest_total;
                    } else {
                        error_log("    Призовой фонд не найден ни в новом, ни в старом формате");
                    }
                }
                
                error_log("    Промежуточная сумма общего призового фонда: {$total_prize_fund}");
                
                // Количество участников для каждого конкурса
                $participants_count = $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM $table_name WHERE contest_id = %d",
                    $contest_id
                ));
                
                $contests_data[$contest_id] = $participants_count;
            }
        }
        
        error_log("Общий призовой фонд после обработки всех конкурсов: {$total_prize_fund}");
        
        // Количество активных конкурсов
        $active_contests = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts} 
            WHERE post_type = 'trader_contests' 
            AND post_status = 'publish'"
        );
        
        // Получаем топ-5 лидеров по всем конкурсам
        $top_leaders = $wpdb->get_results(
            "SELECT m.id, m.user_id, m.contest_id, m.profit_percent, p.post_title as contest_title
            FROM $table_name m
            JOIN {$wpdb->posts} p ON m.contest_id = p.ID
            WHERE p.post_status = 'publish'
            ORDER BY m.profit_percent DESC
            LIMIT 5"
        );
        
        // Форматируем данные лидеров для отправки
        $formatted_leaders = [];
        foreach ($top_leaders as $leader) {
            $user_info = get_userdata($leader->user_id);
            $display_name = $user_info ? $user_info->display_name : 'Участник #' . $leader->id;
            
            $formatted_leaders[] = [
                'display_name' => $display_name,
                'contest_title' => $leader->contest_title,
                'profit_percent' => $leader->profit_percent
            ];
        }
        
        // Отправляем данные
        wp_send_json_success([
            'total_participants' => $total_participants,
            'total_prize_fund' => $total_prize_fund,
            'active_contests' => $active_contests,
            'contests' => $contests_data,
            'top_leaders' => $formatted_leaders
        ]);
        
        // Логируем информацию об исправлении
        $this->log_to_file(
            "Исправлена ошибка в подсчете общего призового фонда на странице списка конкурсов. " .
            "Добавлена поддержка нового формата хранения призовых мест в поле '_fttradingapi_contest_data'. " .
            "Теперь общий призовой фонд корректно суммирует все призовые места из всех конкурсов.", 
            'fix'
        );
    }
    
    /**
     * Логирование исправлений и ошибок
     *
     * @param string $message Сообщение для записи в лог
     * @param string $type Тип сообщения (error, fix, info)
     * @return void
     */
    private function log_to_file($message, $type = 'info') {
        $log_dir = plugin_dir_path(dirname(__FILE__)) . 'planning';
        
        // Создаем директорию, если она не существует
        if (!file_exists($log_dir)) {
            mkdir($log_dir, 0755, true);
        }
        
        $log_file = $log_dir . '/BUG_FIXES_LOG.md';
        
        // Если файл не существует, создаем его с заголовком
        if (!file_exists($log_file)) {
            $header = "# Журнал исправлений и ошибок\n\n";
            file_put_contents($log_file, $header);
        }
        
        $date = date('Y-m-d H:i:s');
        $log_entry = "## {$date} - {$type}\n{$message}\n\n";
        
        // Добавляем запись в начало файла после заголовка
        $current_content = file_get_contents($log_file);
        $header_part = substr($current_content, 0, strpos($current_content, "\n\n") + 2);
        $content_part = substr($current_content, strpos($current_content, "\n\n") + 2);
        
        $new_content = $header_part . $log_entry . $content_part;
        file_put_contents($log_file, $new_content);
    }
    
    /**
     * Расчет статистики конкурса
     * 
     * @param array $participants Список участников конкурса
     * @param float $initial_deposit Начальный депозит
     * @return array Массив со статистикой конкурса
     */
    private function calculate_contest_statistics($participants, $initial_deposit) {
        // Инициализация переменных для статистики
        $total_trades = 0;
        $total_profit = 0;
        $total_loss = 0;
        $traders_in_profit = 0;
        $traders_in_loss = 0;
        $total_profit_amount = 0;
        $total_loss_amount = 0;
        
        foreach ($participants as $player) {
            // Считаем общее количество сделок
            $total_trades += isset($player->orders_history_total) ? intval($player->orders_history_total) : 0;
            
            // Вычисляем прибыль/убыток
            $player_pnl = $player->equity - $initial_deposit;
            
            // Считаем трейдеров в прибыли и убытке
            if ($player_pnl > 0) {
                $traders_in_profit++;
                $total_profit_amount += $player_pnl;
            } else if ($player_pnl < 0) {
                $traders_in_loss++;
                $total_loss_amount += abs($player_pnl);
            }
        }
        
        // Вычисляем процентное соотношение
        $total_traders = count($participants);
        $profit_traders_ratio = $traders_in_profit > 0 ? ($traders_in_profit / $total_traders) * 100 : 0;
        $loss_traders_ratio = $traders_in_loss > 0 ? ($traders_in_loss / $total_traders) * 100 : 0;
        
        // Определяем соотношение прибыли/убытка
        $total_pnl = $total_profit_amount - $total_loss_amount;
        $is_total_pnl_positive = $total_pnl >= 0;
        
        // Для демонстрации распределения сделок (в реальности эти данные нужно получать из БД)
        $buy_trades_percent = 55; // Пример значения
        $sell_trades_percent = 45; // Пример значения
        
        // Эффективность (win rate)
        $win_rate = $traders_in_profit > 0 
            ? round(($traders_in_profit / $total_traders) * 100) 
            : 0;
        $is_win_rate_good = $win_rate >= 50;
        
        // Получаем временную метку последнего обновления
        $latest_update_time = current_time('timestamp');
        if (!empty($participants)) {
            $latest_update = max(array_map(function($p) {
                return strtotime($p->last_update);
            }, $participants));
            
            if ($latest_update) {
                $latest_update_time = $latest_update;
            }
        }
        
        // Формируем массив со статистикой
        $total_money_moved = $total_profit_amount + $total_loss_amount;
        $profit_width = $total_money_moved > 0 ? ($total_profit_amount / $total_money_moved) * 100 : 0;
        
        return [
            'timestamp' => date_i18n('d.m.Y, H:i', $latest_update_time),
            'traders' => [
                'value' => $total_traders,
                'profit_ratio' => $profit_traders_ratio,
                'loss_ratio' => $loss_traders_ratio,
                'details' => [
                    'profit-traders' => "В прибыли: <strong>" . $traders_in_profit . "</strong>",
                    'loss-traders' => "В убытке: <strong>" . $traders_in_loss . "</strong>"
                ]
            ],
            'pnl' => [
                'value' => $total_pnl,
                'is_positive' => $is_total_pnl_positive,
                'progress' => $profit_width,
                'details' => [
                    'profit-amount' => "Заработано: <strong>$" . number_format($total_profit_amount, 2) . "</strong>",
                    'loss-amount' => "Потеряно: <strong>$" . number_format($total_loss_amount, 2) . "</strong>"
                ]
            ],
            'trades' => [
                'value' => $total_trades,
                'progress' => $buy_trades_percent,
                'details' => [
                    'trades-by-type' => '<span class="trades-type-buy"><span class="trades-icon">↗</span> Buy: ' . $buy_trades_percent . '%</span><span class="trades-type-sell"><span class="trades-icon">↘</span> Sell: ' . $sell_trades_percent . '%</span>',
                    'trades-avg' => round($total_trades / $total_traders) . ' / трейдер'
                ]
            ],
            'efficiency' => [
                'value' => $win_rate,
                'is_positive' => $is_win_rate_good,
                'progress' => $win_rate,
                'progress_class' => $is_win_rate_good ? 'profit' : 'loss',
                'details' => [
                    'contest-stat-change' => '<span class="contest-stat-change-icon">' . ($is_win_rate_good ? '↗' : '↘') . '</span><span>' . ($is_win_rate_good ? 'Высокая эффективность' : 'Низкая эффективность') . '</span>'
                ]
            ]
        ];
    }

    private function get_status_text($status) {
        switch ($status) {
            case 'connected':
                return 'Подключен';
            case 'disconnected':
                return 'Отключен';
            case 'disqualified':
                return 'Дисквалифицирован';
            default:
                return 'Неизвестный статус';
        }
    }

    /**
     * Обработчик AJAX для обновления данных счета на фронтенде
     */
    public function update_account_frontend() {
        // Проверка nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'ft_contest_nonce')) {
            wp_send_json_error(['message' => 'Ошибка безопасности']);
            return;
        }
        
        // Проверка ID счета
        if (!isset($_POST['account_id'])) {
            wp_send_json_error(['message' => 'Не указан ID счета']);
            return;
        }
        
        $account_id = intval($_POST['account_id']);
        
        // Проверка владельца счета
        global $wpdb;
        $user_id = get_current_user_id();
        
        $table_name = $wpdb->prefix . 'contest_members';
        
        // Получаем данные счета
        $account = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_name WHERE id = %d",
            $account_id
        ));
        
        if (!$account) {
            wp_send_json_error(['message' => 'Счет не найден']);
            return;
        }
        
        // Проверяем права: пользователь должен быть владельцем счета или администратором
        if ($account->user_id != $user_id && !current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'У вас нет прав для обновления этого счета']);
            return;
        }
        
        // Подготавливаем данные для обновления
        $account_data = [
            'account_number' => $account->account_number,
            'password' => $account->password,
            'server' => $account->server,
            'terminal' => $account->terminal
        ];
        
        // Проверяем наличие файла и функции
        $api_handler_file = plugin_dir_path(dirname(__FILE__)) . 'includes/class-api-handler.php';
        if (!file_exists($api_handler_file)) {
            wp_send_json_error(['message' => 'Файл API обработчика не найден']);
            return;
        }
        
        // Подключаем API-обработчик
        require_once($api_handler_file);
        
        // Проверяем существование функции
        if (!function_exists('process_trading_account')) {
            wp_send_json_error(['message' => 'Функция обновления счета не найдена']);
            return;
        }
        
        // Вызываем функцию обновления счета для получения свежих данных с сервера
        try {
            $result = process_trading_account($account_data, $account_id);
            
            // Логируем результат для отладки
            error_log("[UPDATE_ACCOUNT_FRONTEND] Результат вызова process_trading_account: " . print_r($result, true));
            
            // Проверяем структуру результата
            if (!is_array($result)) {
                wp_send_json_error(['message' => 'Некорректный формат ответа от API']);
                return;
            }
            
            if (isset($result['success']) && $result['success']) {
                // Получаем обновленные данные счета
                $updated_account = $wpdb->get_row($wpdb->prepare(
                    "SELECT * FROM $table_name WHERE id = %d",
                    $account_id
                ));
                
                // Вместо немедленной проверки дисквалификации, планируем отложенную проверку через WP Cron
                if (!wp_next_scheduled('check_account_disqualification', [$account_id])) {
                    wp_schedule_single_event(time() + 5, 'check_account_disqualification', [$account_id]);
                    error_log("[DISQUALIFICATION] Запланирована отложенная проверка дисквалификации для счета ID: {$account_id}");
                }
                
                // Проверяем только текущий статус подключения для немедленного ответа
                // Проверка дисквалификации будет выполнена отложенно
                if ($updated_account->connection_status === 'disqualified') {
                    ft_api_log("Счет $account_id дисквалифицирован: " . $updated_account->error_description, "Обнаружена дисквалификация", "info");
                    
                    // Передаем причину дисквалификации в структурированном виде
                    wp_send_json_error([
                        'message' => 'Счет дисквалифицирован',
                        'disqualified' => true,
                        'error_description' => $updated_account->error_description,
                        'queue_batch_id' => $queue_batch_id
                    ]);
                    return;
                }
                
                // Если все хорошо, отправляем успешный ответ
                wp_send_json_success([
                    'message' => 'Данные счета успешно обновлены',
                    'time' => current_time('mysql')
                ]);
            } else {
                $error_message = isset($result['message']) ? $result['message'] : 'Не удалось обновить данные счета';
                
                // Логируем ошибку для отладки
                if (function_exists('ft_api_log')) {
                    ft_api_log([
                        'error_message' => $error_message,
                        'result' => $result,
                        'account_id' => $account_id,
                        'queue_batch_id' => $queue_batch_id
                    ], "Ошибка обновления данных счета", "error");
                }
                
                // Проверка наличия сообщения об отсутствии финансовых данных
                if (strpos($error_message, 'Отсутствуют необходимые финансовые данные') !== false) {
                    // Заменяем на более понятное сообщение об ошибке подключения
                    $error_message = 'Не удалось подключиться к счёту. Проверьте логин, пароль и сервер. Что можно попробовать: 1) убедитесь, что пароль введён верно; 2) выберите другой сервер в списке; 3) подключитесь с торговым паролем (а не инвесторским); 4) перед добавлением счёта в конкурс закройте терминал на локальном компьютере.';
                }
                
                wp_send_json_error(['message' => $error_message]);
            }
        } catch (Exception $e) {
            wp_send_json_error(['message' => 'Ошибка: ' . $e->getMessage()]);
        }
    }

    /**
     * Проверяет счет на дисквалификацию
     */
    public function check_account_disqualification() {
        // Проверка nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'ft_contest_nonce')) {
            wp_send_json_error(['message' => 'Ошибка безопасности']);
            return;
        }
        
        // Проверка ID счета
        if (!isset($_POST['account_id'])) {
            wp_send_json_error(['message' => 'Не указан ID счета']);
            return;
        }
        
        $account_id = intval($_POST['account_id']);
        $auto_remove = isset($_POST['auto_remove']) && $_POST['auto_remove'] == 'true';
        
        // Проверка владельца счета
        global $wpdb;
        $user_id = get_current_user_id();
        
        $table_name = $wpdb->prefix . 'contest_members';
        
        // Получаем данные счета
        $account = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_name WHERE id = %d",
            $account_id
        ));
        
        if (!$account) {
            wp_send_json_error(['message' => 'Счет не найден']);
            return;
        }
        
        // Проверяем права: пользователь должен быть владельцем счета или администратором
        if ($account->user_id != $user_id && !current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'У вас нет прав для проверки этого счета']);
            return;
        }
        
        // Загружаем класс для проверки дисквалификации
        require_once plugin_dir_path(dirname(__FILE__)) . 'includes/class-disqualification-checker.php';
        
        // Проверяем дисквалификацию
        $disqualification_checker = new Contest_Disqualification_Checker();
        $result = $disqualification_checker->check_account_disqualification($account_id);
        
        if ($result['is_disqualified']) {
            // Если счет должен быть дисквалифицирован, обновляем его статус
            $disqualification_checker->disqualify_account($account_id, $result['reasons']);
            
            // Преобразуем массив причин в форматированный HTML
            $reasons_html = '';
            if (count($result['reasons']) > 1) {
                $reasons_html = '<ul style="margin-top: 10px; margin-bottom: 10px;">';
                foreach ($result['reasons'] as $reason) {
                    $reasons_html .= '<li style="margin-bottom: 10px;">' . $reason . '</li>';
                }
                $reasons_html .= '</ul>';
            } else {
                $reasons_html = $result['reasons'][0];
            }
            
            wp_send_json_error([
                'message' => 'Счет дисквалифицирован по следующим причинам:',
                'reasons_html' => $reasons_html,
                'reasons' => $result['reasons'],
                'disqualified' => true
            ]);
        } else {
            // Счет соответствует всем условиям

            // Проверяем, имеет ли счет статус "дисквалифицирован"
            $is_disqualified = ($account->connection_status === 'disqualified');
            $disqualification_removed = false;

            // Если счет был дисквалифицирован и включен автоматический режим снятия
            if ($is_disqualified && $auto_remove) {
                $disqualification_checker = new Contest_Disqualification_Checker();
                $remove_result = $disqualification_checker->remove_account_disqualification($account_id);
                $disqualification_removed = !$remove_result['is_disqualified'];
                
                if (function_exists('ft_api_log')) {
                    ft_api_log("Автоматическое снятие дисквалификации счета $account_id. Результат: " . 
                        ($disqualification_removed ? "УСПЕШНО" : "НЕУДАЧНО"), 
                        "Снятие дисквалификации", "info");
                }
                
                $message = $disqualification_removed ? 
                    'Счет соответствует всем условиям конкурса. Дисквалификация успешно снята.' :
                    'Счет соответствует всем условиям конкурса, но не удалось снять дисквалификацию.';
            } else {
                $message = 'Счет соответствует всем условиям конкурса';
            }
            
            wp_send_json_success([
                'message' => $message,
                'disqualification_removed' => $disqualification_removed
            ]);
        }
    }

    /**
     * Добавляем обработчик для снятия дисквалификации счета
     */
    public function remove_account_disqualification() {
        // Проверка nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'ft_contest_nonce')) {
            wp_send_json_error(['message' => 'Ошибка безопасности']);
            return;
        }
        
        // Проверка ID счета
        if (!isset($_POST['account_id'])) {
            wp_send_json_error(['message' => 'Не указан ID счета']);
            return;
        }
        
        $account_id = intval($_POST['account_id']);
        
        // Проверка владельца счета
        global $wpdb;
        $user_id = get_current_user_id();
        
        $table_name = $wpdb->prefix . 'contest_members';
        
        // Получаем данные счета
        $account = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_name WHERE id = %d",
            $account_id
        ));
        
        if (!$account) {
            wp_send_json_error(['message' => 'Счет не найден']);
            return;
        }
        
        // Проверяем права: пользователь должен быть владельцем счета или администратором
        if ($account->user_id != $user_id && !current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'У вас нет прав для снятия дисквалификации с этого счета']);
            return;
        }
        
        // Загружаем класс для снятия дисквалификации
        require_once plugin_dir_path(dirname(__FILE__)) . 'includes/class-disqualification-checker.php';
        
        // Снимаем дисквалификацию
        $disqualification_checker = new Contest_Disqualification_Checker();
        $result = $disqualification_checker->remove_account_disqualification($account_id);
        
        if ($result['is_disqualified']) {
            // Если счет должен быть дисквалифицирован, обновляем его статус
            $disqualification_checker->disqualify_account($account_id, $result['reasons']);
            
            // Преобразуем массив причин в форматированный HTML
            $reasons_html = '';
            if (count($result['reasons']) > 1) {
                $reasons_html = '<ul style="margin-top: 10px; margin-bottom: 10px;">';
                foreach ($result['reasons'] as $reason) {
                    $reasons_html .= '<li style="margin-bottom: 10px;">' . $reason . '</li>';
                }
                $reasons_html .= '</ul>';
            } else {
                $reasons_html = $result['reasons'][0];
            }
            
            wp_send_json_error([
                'message' => 'Дисквалификация счета снята по следующим причинам:',
                'reasons_html' => $reasons_html,
                'reasons' => $result['reasons'],
                'disqualified' => true
            ]);
        } else {
            wp_send_json_success([
                'message' => 'Дисквалификация счета успешно снята'
            ]);
        }
    }
}

// Инициализация обработчиков
$contest_ajax = new Contest_Public_Ajax();
$contest_ajax->init();
