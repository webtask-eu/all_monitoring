<?php

/**
 * Функция для логирования данных в файл
 * 
 * @param mixed $data Данные для логирования
 * @param string $message Сообщение для лога
 * @param string $type Тип сообщения (error, info, warn)
 * @return void
 */
function ft_api_log($data, $message = '', $type = 'info') {
    $log_dir = plugin_dir_path(dirname(__FILE__)) . 'logs';
    
    // Создаем директорию для логов, если она не существует
    if (!file_exists($log_dir)) {
        mkdir($log_dir, 0755, true);
    }
    
    $log_file = $log_dir . '/api_debug.log';
    
    // Формируем запись для лога
    $time = current_time('mysql');
    $prefix = "[$time] [$type] ";
    
    if (!empty($message)) {
        $prefix .= "$message: ";
    }
    
    // Преобразуем данные в строку
    if (is_array($data) || is_object($data)) {
        $data_string = print_r($data, true);
    } else {
        $data_string = (string) $data;
    }
    
    // Записываем в файл
    $log_entry = $prefix . $data_string . PHP_EOL . PHP_EOL;
    file_put_contents($log_file, $log_entry, FILE_APPEND);
}

/**
 * Универсальная функция для работы со счетами
 * 
 * @param array $account_data Массив с данными счета (account_number, password, server и т.д.)
 * @param int|null $account_id ID существующего счета (null для создания нового)
 * @param int|null $contest_id ID конкурса (обязательно для новых счетов)
 * @param string|null $queue_batch_id ID очереди для логирования на сервере
 * @return array Результат операции с сообщением и статусом
 */

require_once 'class-account-history.php';
require_once 'class-api-config.php';

function process_trading_account($account_data, $account_id = null, $contest_id = null, $queue_batch_id = null)
{
    global $wpdb;
    $table_name = $wpdb->prefix . 'contest_members';

    $is_new = $account_id === null;
    $account = null;

    // Если это существующий счет, получаем его данные
    if (!$is_new) {
        $account = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_name WHERE id = %d",
            $account_id
        ));

        if (!$account) {
            return [
                'success' => false,
                'message' => 'Счет не найден'
            ];
        }
    } else {
        // Для нового счета проверяем наличие обязательных параметров
        if (
            empty($account_data['account_number']) || empty($account_data['password']) ||
            empty($account_data['server']) || empty($account_data['terminal']) || empty($contest_id)
        ) {
            return [
                'success' => false,
                'message' => 'Отсутствуют обязательные параметры'
            ];
        }

        // Проверяем, существует ли уже такой счет
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $table_name WHERE account_number = %s AND server = %s",
            $account_data['account_number'],
            $account_data['server']
        ));

        if ($existing) {
            return [
                'success' => false,
                'message' => 'Этот счет уже зарегистрирован'
            ];
        }
    }

    // ==================== ПРОВЕРКА СТАТУСА КОНКУРСА ====================
    // Определяем contest_id для проверки статуса
    $effective_contest_id = null;
    if ($is_new && $contest_id) {
        $effective_contest_id = $contest_id;
    } elseif (!$is_new && $account && $account->contest_id) {
        $effective_contest_id = $account->contest_id;
    }

    // Проверяем статус конкурса, если contest_id определен
    if ($effective_contest_id) {
        $contest_data = get_post_meta($effective_contest_id, '_fttradingapi_contest_data', true);
        
        if (!empty($contest_data) && is_array($contest_data)) {
            $contest_status = isset($contest_data['contest_status']) ? $contest_data['contest_status'] : '';
            $is_archived = isset($contest_data['is_archived']) ? $contest_data['is_archived'] : '0';
            
            // Блокируем обновление для завершенных и архивных конкурсов
            if ($contest_status === 'finished' || $is_archived === '1') {
                $status_text = $is_archived === '1' ? 'архивном' : 'завершенном';
                
                ft_api_log([
                    'contest_id' => $effective_contest_id,
                    'contest_status' => $contest_status,
                    'is_archived' => $is_archived,
                    'account_id' => $account_id,
                    'is_new' => $is_new
                ], "Блокировка обновления счета в {$status_text} конкурсе", "info");
                
                return [
                    'success' => false,
                    'message' => "Обновление счетов невозможно - конкурс находится в {$status_text} состоянии",
                    'contest_status' => $contest_status,
                    'is_archived' => $is_archived,
                    'debug_info' => "Contest ID: {$effective_contest_id}, Status: {$contest_status}, Archived: {$is_archived}"
                ];
            }
        }
    }
    // ====================================================================

    // Получаем данные для API запроса
    $api_params = [
        'account_number' => $is_new ? $account_data['account_number'] : $account->account_number,
        'password' => $is_new ? $account_data['password'] : $account->password,
        'server' => $is_new ? $account_data['server'] : $account->server,
        'terminal' => $is_new ? $account_data['terminal'] : $account->terminal,
        'last_history_time' => $is_new ? 0 : $account->last_history_time
    ];

    // Если данные передали при обновлении/редактировании, используем их
    if (!$is_new && !empty($account_data)) {
        foreach (['password', 'server', 'terminal'] as $field) {
            if (isset($account_data[$field])) {
                $api_params[$field] = $account_data[$field];
            }
        }
    }

    // Добавляем параметр queue_batch_id, если он предоставлен
    if ($queue_batch_id !== null) {
        $api_params['queue_batch_id'] = $queue_batch_id;
        
        // ДОБАВЛЕНО: Подробный лог для отладки queue_batch_id
        error_log("===== API Handler Debug =====");
        error_log("Добавлен queue_batch_id в параметры запроса: " . $queue_batch_id);
        error_log("account_id: " . $account_id);
        error_log("Тип вызова: " . ($is_new ? 'Новый счет' : 'Обновление счета'));
        error_log("Время выполнения: " . date('Y-m-d H:i:s'));
        error_log("=========================");
    }

    // Запрос в API с использованием класса FT_API_Config
    $api_url = FT_API_Config::get_api_url();
    
    // Сервер часто возвращает 500, пропускаем ping и сразу делаем основной запрос
    /*
    // Закомментировано до исправления ошибки на API-сервере
    // Сначала проверяем доступность API сервера с помощью простого ping запроса
    $ping_url = $api_url . '?action=ping';
    $ping_response = wp_remote_get($ping_url, ['timeout' => 5]);
    
    // Проверяем результат ping
    if (is_wp_error($ping_response)) {
        $error_message = $ping_response->get_error_message();
        ft_api_log($error_message, "API Ping Error", "error");
        return [
            'success' => false,
            'message' => "Ошибка соединения с API сервером: {$error_message}. Пожалуйста, попробуйте позже.",
            'debug_info' => 'Ping запрос к API серверу не удался.'
        ];
    }
    
    $ping_status_code = wp_remote_retrieve_response_code($ping_response);
    if ($ping_status_code !== 200) {
        ft_api_log($ping_status_code, "API Ping Error: Сервер вернул код", "error");
        return [
            'success' => false,
            'message' => "API сервер недоступен (код: {$ping_status_code}). Пожалуйста, попробуйте позже.",
            'debug_info' => 'Статус ответа сервера: ' . $ping_status_code
        ];
    }
    */
    
    // Логируем пропуск ping-проверки
    ft_api_log("Ping-проверка временно отключена до исправления ошибки на API-сервере", "API Handler Info", "info");
    
    // Продолжаем с основным запросом
    $params = [
        'action' => 'get_data',
        'account_number' => $api_params['account_number'],
        'password' => $api_params['password'],
        'server' => $api_params['server'],
        'terminal' => $api_params['terminal'],
        'last_history_time' => $api_params['last_history_time']
    ];
    
    // Расширенное логирование параметров запроса
    ft_api_log([
        'account_number' => $api_params['account_number'],
        'server' => $api_params['server'],
        'terminal' => $api_params['terminal'],
        'password_length' => strlen($api_params['password']),
        'has_password' => !empty($api_params['password']),
        'account_id' => $account_id,
        'is_new' => $is_new,
        'context' => $is_new ? 'Регистрация нового счета' : 'Обновление существующего счета'
    ], "Расширенные параметры запроса", "info");
    
    // Проверяем валидность параметров, особенно account_number
    if (empty($params['account_number'])) {
        ft_api_log("Ошибка: Номер счета пуст или не определен", "API Parameter Error", "error");
        return [
            'success' => false,
            'message' => "Ошибка параметров: номер счета не указан",
            'debug_info' => 'account_number отсутствует или пуст'
        ];
    }
    
    // Проверяем валидность пароля
    if (empty($params['password'])) {
        ft_api_log("Ошибка: Пароль счета пуст или не определен", "API Parameter Error", "error");
        return [
            'success' => false,
            'message' => "Ошибка параметров: пароль счета не указан",
            'debug_info' => 'password отсутствует или пуст'
        ];
    }
    
    // Выполняем дополнительную проверку пароля на типичные проблемы
    $password = $params['password'];
    
    // Убираем все пробельные символы из пароля
    $trimmed_password = preg_replace('/\s+/', '', $password);
    if ($trimmed_password !== $password) {
        ft_api_log("Предупреждение: пароль содержал пробелы, они были удалены", "API Parameter Warning", "warn");
        $params['password'] = $trimmed_password;
    }
    
    // Проверяем минимальную длину пароля
    if (strlen($trimmed_password) < 6) {
        ft_api_log("Ошибка: Пароль счета слишком короткий (меньше 6 символов)", "API Parameter Error", "error");
        return [
            'success' => false,
            'message' => "Пароль счета слишком короткий. Минимальная длина - 6 символов",
            'debug_info' => 'password too short'
        ];
    }
    
    // Проверяем, не является ли пароль стандартным торговым паролем вместо инвесторского
    $investor_password_patterns = [
        '/^investor\d*$/i',       // investor, investor123
        '/^readonly\d*$/i',       // readonly, readonly123
        '/^view\d*$/i',           // view, view123
        '/^read\d*$/i',           // read, read123
        '/^inv\d*$/i'             // inv, inv123
    ];
    
    $is_likely_investor_password = false;
    foreach ($investor_password_patterns as $pattern) {
        if (preg_match($pattern, $trimmed_password)) {
            $is_likely_investor_password = true;
            break;
        }
    }
    
    if (!$is_likely_investor_password) {
        // Проверяем, похож ли пароль на торговый
        $trading_password_patterns = [
            '/^trading\d*$/i',     // trading, trading123
            '/^trade\d*$/i',       // trade, trade123
            '/^master\d*$/i',      // master, master123
            '/^main\d*$/i',        // main, main123
            '/^admin\d*$/i',       // admin, admin123
            '/^mt[45]\d*$/i'       // mt4, mt5, mt41234
        ];
        
        foreach ($trading_password_patterns as $pattern) {
            if (preg_match($pattern, $trimmed_password)) {
                ft_api_log("Предупреждение: пароль похож на торговый, а не инвесторский", "API Parameter Warning", "warn");
                // Не блокируем, но логируем предупреждение
                break;
            }
        }
    }
    
    // Проверяем валидность сервера
    if (empty($params['server'])) {
        ft_api_log("Ошибка: Сервер счета пуст или не определен", "API Parameter Error", "error");
        return [
            'success' => false,
            'message' => "Ошибка параметров: сервер счета не указан",
            'debug_info' => 'server отсутствует или пуст'
        ];
    }
    
    // Проверяем валидность терминала
    if (empty($params['terminal'])) {
        ft_api_log("Ошибка: Терминал счета пуст или не определен", "API Parameter Error", "error");
        return [
            'success' => false,
            'message' => "Ошибка параметров: терминал счета не указан",
            'debug_info' => 'terminal отсутствует или пуст'
        ];
    }
    
    // Логируем параметры запроса для отладки
    ft_api_log([
        'account_number_length' => strlen($params['account_number']),
        'account_number_first_chars' => substr($params['account_number'], 0, 4) . '...',
        'account_number_last_chars' => '...' . substr($params['account_number'], -4),
        'server' => $params['server'],
        'terminal' => $params['terminal']
    ], "Параметры основного запроса", "info");
    
    // Добавляем queue_batch_id в параметры запроса если он предоставлен
    if (isset($api_params['queue_batch_id'])) {
        $params['queue_batch_id'] = $api_params['queue_batch_id'];
        
        // ДОБАВЛЕНО: Отдельный лог для проверки передачи queue_batch_id в параметрах HTTP запроса
        error_log("QUEUE_BATCH_ID_DEBUG: Передаем в HTTP запрос queue_batch_id=" . $api_params['queue_batch_id']);
    }

    $url = $api_url . '?' . http_build_query($params);
    
    // Добавляем подробную информацию о запросе в лог
    ft_api_log([
        'url_length' => strlen($url),
        'url_base' => parse_url($url, PHP_URL_SCHEME) . '://' . parse_url($url, PHP_URL_HOST) . parse_url($url, PHP_URL_PATH),
        'timeout' => 30
    ], "Отправка запроса на API", "info");
    
    // Увеличиваем таймаут для получения данных
    $response = wp_remote_get($url, ['timeout' => 30, 'sslverify' => false]);

    if (is_wp_error($response)) {
        $error_message = $response->get_error_message();
        ft_api_log($error_message, "API Main Request Error", "error");
        
        // Расширенная информация об ошибке
        $error_data = $response->get_error_data();
        if (!empty($error_data)) {
            ft_api_log($error_data, "API Error Additional Data", "error");
        }
        
        // Формируем более дружественное сообщение об ошибке
        $friendly_message = 'Ошибка подключения к серверу: ';
        
        // Проверяем типичные ошибки
        if (strpos($error_message, 'cURL error 28') !== false) {
            $friendly_message .= 'превышено время ожидания запроса. Сервер не отвечает, попробуйте позже.';
        } elseif (strpos($error_message, 'cURL error 6') !== false || 
                 strpos($error_message, 'Could not resolve host') !== false) {
            $friendly_message .= 'не удалось найти сервер. Проверьте ваше интернет-соединение.';
        } elseif (strpos($error_message, 'cURL error 7') !== false) {
            $friendly_message .= 'не удалось соединиться с сервером. Сервер может быть недоступен.';
        } else {
            $friendly_message .= $error_message;
        }
        
        return [
            'success' => false,
            'message' => $friendly_message,
            'debug_info' => 'WP_Error в API запросе: ' . $error_message
        ];
    }

    $body = wp_remote_retrieve_body($response);
    
    // Получаем код HTTP ответа
    $status_code = wp_remote_retrieve_response_code($response);

    // Обрабатываем код 500 специальным образом
    if ($status_code == 500) {
        ft_api_log([
            'status_code' => $status_code,
            'headers' => wp_remote_retrieve_headers($response),
            'body_preview' => substr(wp_remote_retrieve_body($response), 0, 500)
        ], "API вернул HTTP 500 - внутренняя ошибка сервера", "error");
        
        return [
            'success' => false,
            'message' => 'Сервер API временно недоступен. На сервере идет обновление. Пожалуйста, попробуйте снова через 5-10 минут.',
            'debug_info' => 'HTTP 500 - внутренняя ошибка сервера'
        ];
    }

    // 1. Логирование исходящих параметров
    $debug_outgoing_params = [
        'url' => $url,
        'api_params' => $params,
        'account_data' => $account_data,
        'account_id' => $account_id,
        'is_new' => $is_new,
        'status_code' => $status_code
    ];
    ft_api_log($debug_outgoing_params, "API Request Debug", "info");

    // Дополнительная проверка пустого ответа
    if (empty($body)) {
        ft_api_log($status_code, "API Empty Response: Получен пустой ответ от API сервера, HTTP код", "error");
        return [
            'success' => false,
            'message' => 'Сервер API вернул пустой ответ. HTTP код: ' . $status_code
        ];
    }

    // 2. Логирование HTTP-ответа
    $debug_response = [
        'http_code' => $status_code,
        'headers' => wp_remote_retrieve_headers($response),
        'body_length' => strlen($body),
        'body_preview' => substr($body, 0, 1000) . (strlen($body) > 1000 ? '...[обрезано]' : '')
    ];
    ft_api_log($debug_response, "API Response Debug", "info");

    // Проверка на валидный JSON
    $data = json_decode($body, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        $json_error = json_last_error_msg();
        ft_api_log([$json_error, substr($body, 0, 1000)], "API JSON Error", "error");
        return [
            'success' => false,
            'message' => 'Получен некорректный ответ от сервера API: ' . $json_error
        ];
    }

    // 3. Логирование декодированных данных
    ft_api_log($data, "API Decoded Data", "info");

    // Проверка данных счета в ответе
    if (!isset($data['acc'])) {
        ft_api_log("В ответе API отсутствует секция 'acc' с данными счета", "API Response Error", "error");
        return [
            'success' => false,
            'message' => 'Ошибка в ответе API: отсутствуют данные счета',
            'debug_info' => 'Отсутствует секция acc в ответе API'
        ];
    }

    if (isset($data['error'])) {
        // Если есть ошибка, обновляем только статус подключения и ошибку
        $db_data = [
            'connection_status' => 'disconnected',
            'error_description' => $data['error'],
            'last_update' => current_time('mysql')
        ];
        
        if (!$is_new) {
            // Обновляем только статус подключения и ошибку, финансовые показатели не трогаем
            $wpdb->update(
                $table_name,
                $db_data,
                ['id' => $account_id]
            );
        }
        
        // Добавляем подробное логирование ошибки
        ft_api_log("Ошибка в ответе API: " . $data['error'], "API Error", "error");
        
        return [
            'success' => false,
            'message' => 'Ошибка API: ' . $data['error']
        ];
    }

    // ВАЖНО: Проверяем статус подключения - это приоритетная проверка
    if (isset($data['acc']['connection_status']) && $data['acc']['connection_status'] === 'disconnected') {
        $error_message = isset($data['acc']['error_description']) && !empty($data['acc']['error_description']) 
            ? $data['acc']['error_description'] 
            : 'Не удалось подключиться к счёту. Проверьте логин, пароль и сервер. Что можно попробовать: 1) убедитесь, что пароль введён верно; 2) выберите другой сервер в списке; 3) подключитесь с торговым паролем (а не инвесторским); 4) перед добавлением счёта в конкурс закройте терминал на локальном компьютере.';
        
        // Обновляем только статус подключения и ошибку
        $db_data = [
            'connection_status' => 'disconnected',
            'error_description' => $error_message,
            'last_update' => current_time('mysql')
        ];
        
        if (!$is_new) {
            // Обновляем только статус подключения и ошибку, не трогая финансовые показатели
            $wpdb->update(
                $table_name,
                $db_data,
                ['id' => $account_id]
            );
        }
        
        return [
            'success' => false,
            'message' => $error_message
        ];
    }

    // Проверяем, что все финансовые поля получены - эта проверка делается ТОЛЬКО если статус подключения не disconnected
    $required_financial_fields = ['i_bal', 'i_equi', 'i_marg', 'i_prof', 'leverage'];
    $missing_fields = array_filter($required_financial_fields, function($field) use ($data) {
        return !isset($data['acc'][$field]) || $data['acc'][$field] === '' || $data['acc'][$field] === null;
    });

    if (!empty($missing_fields)) {
        // Если какие-то финансовые поля отсутствуют, устанавливаем статус disconnected
        // Используем понятное объяснение проблемы подключения вместо технического сообщения
        $error_message = 'Не удалось подключиться к счёту. Проверьте логин, пароль и сервер. Что можно попробовать: 1) убедитесь, что пароль введён верно; 2) выберите другой сервер в списке; 3) подключитесь с торговым паролем (а не инвесторским); 4) перед добавлением счёта в конкурс закройте терминал на локальном компьютере.';
        
        $db_data = [
            'connection_status' => 'disconnected',
            'error_description' => $error_message,
            'last_update' => current_time('mysql')
        ];
        
        if (!$is_new) {
            // Обновляем только статус подключения и ошибку, финансовые показатели не трогаем
            $wpdb->update(
                $table_name,
                $db_data,
                ['id' => $account_id]
            );
        }
        
        return [
            'success' => false,
            'message' => $error_message
        ];
    }

    // Для существующих счетов: сохраняем старые данные для истории
    if (!$is_new) {
        $old_data = [
            'i_bal' => $account->balance,
            'i_equi' => $account->equity,
            'i_marg' => $account->margin,
            'i_prof' => $account->profit,
            'leverage' => $account->leverage,
            'i_ordtotal' => $account->orders_total,
            'i_firma' => $account->broker,
            'i_fio' => $account->name,
            'i_dr' => $account->account_type,
            'connection_status' => $account->connection_status,
            'error_description' => $account->error_description
        ];

        // Расчет объема активных ордеров для старых данных
        require_once 'class-orders.php';
        $orders_handler = new Account_Orders();
        $old_data['active_orders_volume'] = $orders_handler->get_active_orders_volume($account_id);

        // Отслеживаем изменения для существующих счетов
        $history = new Account_History();
        $history->track_changes($account_id, $old_data, $data['acc']);
    }

    // Получаем IP пользователя и страну только для новых счетов
    $user_ip = '';
    $user_country = '';
    $country_code = '';

    if ($is_new) {
        $user_ip = $_SERVER['REMOTE_ADDR'];
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
            $user_ip = trim($ips[0]);
        }

        $geo_response = wp_remote_get('http://ip-api.com/json/' . $user_ip);
        if (!is_wp_error($geo_response)) {
            $geo_data = json_decode(wp_remote_retrieve_body($geo_response), true);
            if (isset($geo_data['status']) && $geo_data['status'] === 'success') {
                $user_country = $geo_data['country'];
                $country_code = strtolower($geo_data['countryCode']);
            }
        }
    }

    // Готовим данные для БД
    // Формируем данные для БД только если они реально есть, иначе пропускаем поле
    $db_data = [
        // 'balance' => $data['acc']['i_bal'] ?? 0, // старый вариант
    ];
    $fields_map = [
        'balance' => ['acc', 'i_bal'],
        'equity' => ['acc', 'i_equi'],
        'margin' => ['acc', 'i_marg'],
        'profit' => ['acc', 'i_prof'],
        'leverage' => ['acc', 'leverage'],
        'orders_total' => ['acc', 'i_ordtotal'],
        'orders_history_total' => ['statistics', 'ACCOUNT_ORDERS_HISTORY_TOTAL'],
        'orders_history_profit' => ['statistics', 'ACCOUNT_ORDERS_HISTORY_PROFIT'],
        'currency' => ['acc', 'i_cur'],
        'broker' => ['acc', 'i_firma'],
        'name' => ['acc', 'i_fio'],
        'account_type' => ['acc', 'i_dr'],
        'gmt_offset' => ['acc', 'sGmt'],
        'last_update_time' => ['acc', 'time_last_update'],
        'connection_status' => ['acc', 'connection_status'],
        'error_description' => ['acc', 'error_description'],
    ];
    
    // Добавляем поле last_update
    $db_data['last_update'] = current_time('mysql');
    
    foreach ($fields_map as $db_key => $path) {
        $section = $path[0];
        $key = $path[1];
        
        if (isset($data[$section][$key]) && $data[$section][$key] !== '' && $data[$section][$key] !== null) {
            // Для финансовых полей проверяем, чтобы значение не было нулем
            if (in_array($db_key, ['balance','equity','margin','profit','leverage','orders_total','orders_history_total','orders_history_profit'])) {
                $value = floatval($data[$section][$key]);
                
                // Логируем получение данных и их проверку для отладки
                error_log("[API-HANDLER] Получено значение для поля $db_key: $value (account_id: {$account_id})");
                
                // Проверка на подозрительные нули - если соединение успешно, но все финансовые показатели равны 0
                $suspicious_zeros = false;
                
                // ОТЛАДКА v1.2.0 - Упрощенная диагностика проблемы нулей
                if ($value == 0) {
                    // Блок 1: Нули приходят из API при статусе connected
                    if (isset($data['acc']['connection_status']) && $data['acc']['connection_status'] === 'connected') {
                        $db_data[$db_key] = -1; // Блок 1: Нули из API при connected статусе
                        error_log("[API-HANDLER] ОТЛАДКА v1.2.0: Блок 1 (-1): нули из API при connected статусе для поля $db_key (account_id: {$account_id})");
                        continue;
                    }
                    
                    // Обычная обработка для других случаев
                    if (!$is_new) {
                        $old_value = $account->{$db_key};
                        if ($old_value !== null && $old_value !== '' && floatval($old_value) != 0) {
                            // Если баланс действительно мог стать нулем, используем новое значение
                            // В других случаях предпочитаем старое значение
                            if (($db_key == 'balance' || $db_key == 'equity') && $account->connection_status == 'connected') {
                                error_log("[API-HANDLER] Обнаружено изменение с $old_value на 0 для поля $db_key (account_id: {$account_id})");
                                // Для демо-счетов
                                if (isset($account->account_type) && $account->account_type == 'demo') {
                                    $db_data[$db_key] = -1; // Блок 1: Нули для демо счетов
                                    error_log("[API-HANDLER] ОТЛАДКА v1.2.0: Блок 1 (-1): нули для демо-счета для поля $db_key (account_id: {$account_id})");
                                } else {
                                    $db_data[$db_key] = $old_value;
                                    error_log("[API-HANDLER] Для реального счета используем старое значение $old_value для поля $db_key (account_id: {$account_id})");
                                }
                            } else {
                                $db_data[$db_key] = $old_value;
                                error_log("[API-HANDLER] Используем старое значение $old_value для поля $db_key (account_id: {$account_id})");
                            }
                            continue;
                        }
                    }
                    
                    // Блок 2: Нет старого значения или оно тоже 0
                    $db_data[$db_key] = -2; // Блок 2: Отсутствуют старые значения
                    error_log("[API-HANDLER] ОТЛАДКА v1.2.0: Блок 2 (-2): отсутствуют старые значения для поля $db_key (account_id: {$account_id})");
                } else {
                    // Если значение не 0, используем его
                    $db_data[$db_key] = $value;
                }
            } else {
                // Для не-финансовых полей используем значение как есть
                $db_data[$db_key] = $data[$section][$key];
            }
        } else {
            // Для финансовых полей используем старое значение или значение по умолчанию
            if (in_array($db_key, ['balance','equity','margin','profit','leverage','orders_total','orders_history_total','orders_history_profit'])) {
                if (!$is_new) {
                    // Если это существующий счет, используем старое значение
                    $old_value = $account->{$db_key};
                    if ($old_value !== null && $old_value !== '') {
                        $db_data[$db_key] = $old_value;
                        error_log("[API-HANDLER] Поле $db_key отсутствует в API, используем старое значение: $old_value (account_id: {$account_id})");
                        continue;
                    }
                }
                // Если старое значение пустое, используем значение по умолчанию
                $default_values = [
                    'balance' => -2, // Блок 2: Поле отсутствует в API
                    'equity' => -2, // Блок 2: Поле отсутствует в API
                    'margin' => -2, // Блок 2: Поле отсутствует в API
                    'profit' => -2, // Блок 2: Поле отсутствует в API
                    'leverage' => -2, // Блок 2: Поле отсутствует в API
                    'orders_total' => -2, // Блок 2: Поле отсутствует в API
                    'orders_history_total' => -2, // Блок 2: Поле отсутствует в API
                    'orders_history_profit' => -2 // Блок 2: Поле отсутствует в API
                ];
                $db_data[$db_key] = $default_values[$db_key];
                error_log("[API-HANDLER] ОТЛАДКА v1.2.0: Блок 2 (-2): поле $db_key отсутствует в API (account_id: {$account_id})");
            } else {
                // Для остальных полей логируем отсутствие данных
                error_log("[API-HANDLER] Не получено значение для поля $db_key (account_id: {$account_id}) — поле не будет обновлено!");
            }
        }
    }
    // Значения по умолчанию для валюты/статусов, если не пришли
    if (!isset($db_data['currency'])) $db_data['currency'] = 'USD';
    if (!isset($db_data['connection_status'])) $db_data['connection_status'] = 'connected';
    if (!isset($db_data['error_description'])) $db_data['error_description'] = '';
    
    // Рассчитываем profit_percent (процент прибыли)
    if (isset($db_data['profit']) && isset($db_data['balance']) && $db_data['balance'] > 0) {
        // Получаем начальный депозит (balance - profit)
        $initial_deposit = $db_data['balance'] - $db_data['profit'];
        if ($initial_deposit > 0) {
            // Расчет процента прибыли
            $profit_percent = ($db_data['profit'] / $initial_deposit) * 100;
            $db_data['profit_percent'] = round($profit_percent, 2);
            error_log("[API-HANDLER] Расчет profit_percent: " . $db_data['profit_percent'] . "% (profit: " . $db_data['profit'] . ", initial_deposit: " . $initial_deposit . ")");
        } else {
            error_log("[API-HANDLER] Невозможно рассчитать profit_percent: начальный депозит <= 0 (balance: " . $db_data['balance'] . ", profit: " . $db_data['profit'] . ")");
        }
    } else {
        error_log("[API-HANDLER] Невозможно рассчитать profit_percent: отсутствуют необходимые данные (profit или balance)");
    }

    // Для новых счетов добавляем дополнительные данные
    if ($is_new) {
        $db_data = array_merge($db_data, [
            'account_number' => $account_data['account_number'],
            'password' => $account_data['password'],
            'server' => $account_data['server'],
            'terminal' => $account_data['terminal'],
            'contest_id' => $contest_id,
            'user_id' => get_current_user_id(),
            'user_ip' => $user_ip,
            'user_country' => $user_country,
            'country_code' => $country_code,
            'registration_date' => current_time('mysql')
        ]);

        // Подробное логирование при создании нового счета
        ft_api_log([
            'creating_new_account' => true,
            'account_number' => $account_data['account_number'],
            'server' => $account_data['server'],
            'terminal' => $account_data['terminal'],
            'contest_id' => $contest_id,
            'user_id' => get_current_user_id(),
            'db_data_keys' => array_keys($db_data)
        ], "Создание нового счета в БД", "info");
        
        // Вставляем запись в БД
        try {
            $insert_result = $wpdb->insert($table_name, $db_data);
            
            if ($insert_result === false) {
                ft_api_log("Ошибка при вставке нового счета в БД: " . $wpdb->last_error, "DB Insert Error", "error");
                return [
                    'success' => false,
                    'message' => 'Ошибка базы данных при создании счета: ' . $wpdb->last_error,
                    'debug_info' => 'Ошибка SQL при вставке в БД'
                ];
            }
            
            // Получаем ID нового счета
            $account_id = $wpdb->insert_id;
            
            if (!$account_id) {
                ft_api_log("Ошибка: не получен ID нового счета после вставки", "DB Insert Error", "error");
                return [
                    'success' => false,
                    'message' => 'Ошибка базы данных: не удалось получить ID нового счета',
                    'debug_info' => 'insert_id вернул 0 или NULL'
                ];
            }
            
            ft_api_log("Новый счет успешно создан, ID: " . $account_id, "Account Created", "info");
            
            // Обрабатываем историю сделок, если есть
            if (isset($data['open_orders']) && is_array($data['open_orders'])) {
                require_once 'class-orders.php';
                $orders = new Account_Orders();
                $orders->update_orders($account_id, $data['open_orders']);
            }
            
            // Обрабатываем историю сделок, если есть
            if (isset($data['order_history']) && is_array($data['order_history'])) {
                require_once 'class-orders.php';
                $orders = new Account_Orders();
                $orders->update_order_history($account_id, $data['order_history']);
            }
            
            // Возвращаем успешный результат с данными счета
            return [
                'success' => true,
                'message' => 'Счет успешно создан',
                'account_data' => [
                    'id' => $account_id,
                    'account_number' => $account_data['account_number'],
                    'server' => $account_data['server'],
                    'terminal' => $account_data['terminal'],
                    'contest_id' => $contest_id
                ]
            ];
        } catch (Exception $e) {
            ft_api_log("Исключение при создании счета: " . $e->getMessage(), "Exception", "error");
            return [
                'success' => false,
                'message' => 'Ошибка при создании счета: ' . $e->getMessage(),
                'debug_info' => 'Исключение в блоке создания счета'
            ];
        }
    } else if (!empty($account_data)) {
        // Для обновления используем переданные параметры
        foreach (['password', 'server', 'terminal', 'contest_id'] as $field) { // Добавляем contest_id
            if (isset($account_data[$field])) {
                $db_data[$field] = $account_data[$field];
            }
        }
    }

    // Если это обновление существующего счета
    if (!$is_new) {
        try {
            // Получаем текущие данные счета перед обновлением
            $current_account = $wpdb->get_row($wpdb->prepare(
                "SELECT connection_status, error_description FROM $table_name WHERE id = %d",
                $account_id
            ), ARRAY_A);
            
            // Запоминаем был ли счет дисквалифицирован
            $was_disqualified = isset($current_account['connection_status']) && $current_account['connection_status'] === 'disqualified';
            $disqualification_reason = $was_disqualified ? $current_account['error_description'] : '';
            
            // Выполняем обновление записи в БД
            $result = $wpdb->update($table_name, $db_data, ['id' => $account_id]);

            if ($result === false) {
                return [
                    'success' => false,
                    'message' => 'Ошибка базы данных: ' . $wpdb->last_error
                ];
            }

            // Если счет был дисквалифицирован ранее, восстанавливаем статус дисквалификации
            if ($was_disqualified) {
                $wpdb->update(
                    $table_name,
                    [
                        'connection_status' => 'disqualified',
                        'error_description' => $disqualification_reason
                    ],
                    ['id' => $account_id]
                );
                error_log("[API-HANDLER] Восстановлен статус дисквалификации для счета ID: {$account_id}. Причина: {$disqualification_reason}");
            }

            // Обработка ордеров если необходимо
            if (isset($data['open_orders']) && is_array($data['open_orders'])) {
                $orders = new Account_Orders();
                try {
                    $orders->update_orders($account_id, $data['open_orders']);
                    
                    // После обновления ордеров сразу рассчитываем объем и записываем в историю
                    $active_volume = $orders->get_active_orders_volume($account_id);
                    
                    // Если объем изменился, добавляем запись в историю
                    if (!isset($old_data['active_orders_volume']) || $old_data['active_orders_volume'] != $active_volume) {
                        $history = new Account_History();
                        $history->track_changes($account_id, 
                            ['active_orders_volume' => $old_data['active_orders_volume'] ?? 0], 
                            ['active_orders_volume' => $active_volume, 'connection_status' => 'connected']);
                    }
                } catch (Exception $e) {
                    // Логируем, но не прерываем выполнение
                    error_log('Error updating orders: ' . $e->getMessage());
                }
            }

            // Обрабатываем историю сделок
            if (isset($data['order_history']) && is_array($data['order_history'])) {
                $orders = new Account_Orders();
                $orders->update_order_history($account_id, $data['order_history']);
            }

            // Явно возвращаем успешный результат с булевым значением success
            return [
                'success' => true, // Используем булево значение true вместо 1
                'message' => 'Данные счета успешно обновлены'
            ];
        } catch (Exception $e) {
            error_log('Exception during account update: ' . $e->getMessage());
            return [
                'success' => false, // Используем булево значение false
                'message' => 'Ошибка при обновлении счета: ' . $e->getMessage()
            ];
        }
    }
    
    // Если функция дошла до этого места без return, значит что-то пошло не так
    // Это не должно происходить, но добавим страховочный код
    error_log('[API-HANDLER] Критическая ошибка: функция process_trading_account завершилась без return');
    return [
        'success' => false,
        'message' => 'Внутренняя ошибка сервера: функция завершилась без результата',
        'debug_info' => 'process_trading_account завершилась без return'
    ];
}

// AJAX-обработчик для регистрации счета
function fttradingapi_register_account()
{
    $result = process_trading_account([
        'account_number' => !empty($_POST['account_number']) ? sanitize_text_field($_POST['account_number']) : '',
        'password' => !empty($_POST['password']) ? sanitize_text_field($_POST['password']) : '',
        'server' => !empty($_POST['server']) ? sanitize_text_field($_POST['server']) : '',
        'terminal' => !empty($_POST['terminal']) ? sanitize_text_field($_POST['terminal']) : ''
    ], null, intval($_POST['contest_id']));

    if ($result['success']) {
        wp_send_json_success($result);
    } else {
        wp_send_json_error(['message' => $result['message']]);
    }
}

// Регистрируем AJAX-обработчик для добавления счета
add_action('wp_ajax_fttradingapi_register_account', 'fttradingapi_register_account');


// AJAX-обработчик для редактирования счета
function fttradingapi_edit_account()
{
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Недостаточно прав']);
    }

    $result = process_trading_account([
        'password' => !empty($_POST['password']) ? sanitize_text_field($_POST['password']) : '',
        'server' => !empty($_POST['server']) ? sanitize_text_field($_POST['server']) : '',
        'terminal' => !empty($_POST['terminal']) ? sanitize_text_field($_POST['terminal']) : '',
        'contest_id' => !empty($_POST['contest_id']) ? intval($_POST['contest_id']) : 0 // Добавляем конкурс
    ], intval($_POST['id']));

    if ($result['success']) {
        wp_send_json_success(['message' => $result['message']]);
    } else {
        wp_send_json_error(['message' => $result['message']]);
    }
}



// В конце файла убедитесь, что есть этот хук:
add_action('wp_ajax_fttradingapi_edit_account', 'fttradingapi_edit_account');


// AJAX-обработчик для обновления данных счета
// Улучшаем обработчик обновления счета, чтобы возвращать нужные данные
function fttradingapi_ajax_update_account_data()
{
    check_ajax_referer('ft_trader_nonce', 'nonce');

    $account_id = isset($_POST['account_id']) ? intval($_POST['account_id']) : 0;
    if (!$account_id) {
        wp_send_json_error(['message' => 'ID счета не указан']);
    }
    
    // Генерируем короткий queue_batch_id для одиночного обновления
    $letters = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $random_letters = '';
    for ($i = 0; $i < 4; $i++) {
        $random_letters .= $letters[rand(0, strlen($letters) - 1)];
    }
    $queue_batch_id = 's' . $random_letters; // s означает single update
    
    // Добавляем информацию в лог
    error_log('Одиночное обновление счета ' . $account_id . ' с queue_batch_id: ' . $queue_batch_id);

    $result = process_trading_account([], $account_id, null, $queue_batch_id);

    if ($result['success']) {
        // Получаем ВСЕ данные счета одним запросом
        global $wpdb;
        $table_name = $wpdb->prefix . 'contest_members';
        $account = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_name WHERE id = %d",
            $account_id
        ), ARRAY_A);  // Получаем как массив

        // Возвращаем обновленные данные (все обращения к $account как к массиву)
        $response = [
            'message' => $result['message'],
            'account_id' => $account_id,
            'account_data' => [
                'id' => $account_id,
                'balance' => $account['balance'],
                'equity' => $account['equity'],
                'margin' => $account['margin'],
                'profit' => $account['profit'],
                'leverage' => $account['leverage'],
                'currency' => $account['currency'],
                'connection_status' => $account['connection_status'],
                'error_description' => $account['error_description'],
                'last_update' => $account['last_update'],
                'orders_total' => $account['orders_total'],
                'orders_history_total' => $account['orders_history_total'],
                'profit_percent' => $account['profit_percent']
            ],
            // Добавляем queue_batch_id в ответ для отладки
            'queue_batch_id' => $queue_batch_id
        ];
        
        // Добавляем заголовок с queue_batch_id для отслеживания в JavaScript
        header('X-Queue-Batch-ID: ' . $queue_batch_id);
        
        wp_send_json_success($response);
    } else {
        // В случае ошибки тоже добавляем queue_batch_id
        $error_response = [
            'message' => $result['message'],
            'queue_batch_id' => $queue_batch_id
        ];
        
        // Добавляем заголовок с queue_batch_id
        header('X-Queue-Batch-ID: ' . $queue_batch_id);
        
        wp_send_json_error($error_response);
    }
}


// В конце файла убедитесь, что есть этот хук:
add_action('wp_ajax_fttradingapi_update_account_data', 'fttradingapi_ajax_update_account_data');



function fttradingapi_load_account_history()
{
    // Убираем проверку nonce для исправления ошибки 403
    // check_ajax_referer('account_history_nonce', 'nonce');
    try {
        $account_id = isset($_POST['account_id']) ? intval($_POST['account_id']) : 0;

        error_log('Account ID received: ' . $account_id); // Для отладки

        if (!$account_id) {
            wp_send_json_error(['message' => 'ID счета не указан']);
        }

        $field = isset($_POST['field']) ? sanitize_text_field($_POST['field']) : '';
        // Меняем значение по умолчанию с 'all' на 'day'
        $period = isset($_POST['period']) ? sanitize_text_field($_POST['period']) : 'day';
        $sort = isset($_POST['sort']) ? sanitize_text_field($_POST['sort']) : 'desc';

        $history = new Account_History();
        $changes = $history->get_filtered_history($account_id, $field, $period, $sort);

        include(plugin_dir_path(__FILE__) . '../admin/views/history-table.php');
        wp_die();
    } catch (Exception $e) {
        wp_send_json_error('Ошибка: ' . $e->getMessage());
    }
}

add_action('wp_ajax_load_account_history', 'fttradingapi_load_account_history');

/**
 * AJAX-обработчик для создания очереди обновления счетов
 */
function fttradingapi_create_update_queue()
{
    check_ajax_referer('ft_trader_nonce', 'nonce');

    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Недостаточно прав']);
    }

    $account_ids = isset($_POST['account_ids']) ? array_map('intval', $_POST['account_ids']) : [];
    $is_auto_update = isset($_POST['is_auto_update']) ? (bool) $_POST['is_auto_update'] : false;
    $contest_id = isset($_POST['contest_id']) ? intval($_POST['contest_id']) : null;
    
    // Логирование для отладки
    error_log('fttradingapi_create_update_queue: account_ids=' . json_encode($account_ids) . 
        ', is_auto_update=' . ($is_auto_update ? 'true' : 'false') . 
        ', contest_id=' . ($contest_id ? $contest_id : 'null'));

    require_once plugin_dir_path(__FILE__) . 'class-account-updater.php';
    $result = Account_Updater::init_queue($account_ids, $is_auto_update, $contest_id);
    
    // Если очередь создана успешно, передаем queue_id в запросы на обновление счетов
    if ($result['success'] && isset($result['queue_id'])) {
        $queue_id = $result['queue_id'];
        
        // Для первых 3 счетов в очереди (или меньше, если в очереди меньше счетов)
        // выполняем немедленное обновление с передачей queue_id
        $initial_processing = array_slice($account_ids, 0, 3);
        
        foreach ($initial_processing as $account_id) {
            // Запускаем обновление с передачей ID очереди для логирования
            process_trading_account([], $account_id, $contest_id, $queue_id);
        }
    }
    
    // Логирование результата
    error_log('init_queue result: success=' . ($result['success'] ? 'true' : 'false') . 
        ', message=' . (isset($result['message']) ? $result['message'] : '') . 
        ', contest_id=' . (isset($result['contest_id']) ? $result['contest_id'] : 'null'));

    if ($result['success']) {
        wp_send_json_success($result);
    } else {
        wp_send_json_error($result);
    }
}

/**
 * AJAX-обработчик для получения статуса обновления
 */
function fttradingapi_get_update_status()
{
    check_ajax_referer('ft_trader_nonce', 'nonce');

    $contest_id = isset($_POST['contest_id']) ? intval($_POST['contest_id']) : null;
    $queue_id = isset($_POST['queue_id']) ? sanitize_text_field($_POST['queue_id']) : null;
    
    // Логирование для отладки
    error_log('fttradingapi_get_update_status: contest_id=' . ($contest_id ? $contest_id : 'null') . 
        ', queue_id=' . ($queue_id ? $queue_id : 'null'));

    require_once plugin_dir_path(__FILE__) . 'class-account-updater.php';
    $status = Account_Updater::get_status($contest_id, $queue_id);
    
    // Логирование результата
    error_log('get_status result: is_running=' . ($status['is_running'] ? 'true' : 'false') . 
        ', queues_count=' . (isset($status['queues_count']) ? $status['queues_count'] : '-') .
        ', queue_id=' . (isset($status['queue_id']) ? $status['queue_id'] : '-'));

    wp_send_json_success($status);
}

/**
 * AJAX-обработчик для очистки истории изменений счета
 */
function fttradingapi_clear_account_history()
{
    check_ajax_referer('ft_trader_nonce', 'nonce');

    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Недостаточно прав для выполнения этого действия']);
    }

    $account_id = isset($_POST['account_id']) ? intval($_POST['account_id']) : 0;

    if (!$account_id) {
        wp_send_json_error(['message' => 'ID счета не указан']);
    }

    $history = new Account_History();
    $result = $history->clear_account_history($account_id);

    if ($result) {
        wp_send_json_success(['message' => 'История изменений счета успешно очищена']);
    } else {
        wp_send_json_error(['message' => 'Произошла ошибка при очистке истории']);
    }
}
add_action('wp_ajax_clear_account_history', 'fttradingapi_clear_account_history');

/**
 * AJAX-обработчик для очистки истории сделок счета
 */
function fttradingapi_clear_order_history()
{
    check_ajax_referer('ft_contest_nonce', 'nonce');

    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Недостаточно прав для выполнения этого действия']);
    }

    $account_id = isset($_POST['account_id']) ? intval($_POST['account_id']) : 0;

    if (!$account_id) {
        wp_send_json_error(['message' => 'ID счета не указан']);
    }

    global $wpdb;
    $history_table = $wpdb->prefix . 'contest_members_order_history';
    $orders_table = $wpdb->prefix . 'contest_members_orders';
    $members_table = $wpdb->prefix . 'contest_members';
    
    // Начинаем транзакцию
    $wpdb->query('START TRANSACTION');
    
    try {
        // Удаляем записи истории сделок
        $delete_history_result = $wpdb->delete(
            $history_table,
            ['account_id' => $account_id],
            ['%d']
        );
        
        // Удаляем открытые сделки
        $delete_orders_result = $wpdb->delete(
            $orders_table,
            ['account_id' => $account_id],
            ['%d']
        );
        
        // Сбрасываем last_history_time
        $update_result = $wpdb->update(
            $members_table,
            ['last_history_time' => 0],
            ['id' => $account_id],
            ['%d'],
            ['%d']
        );
        
        // Если все операции успешны, фиксируем транзакцию
        if ($delete_history_result !== false && $delete_orders_result !== false && $update_result !== false) {
            $wpdb->query('COMMIT');
            wp_send_json_success(['message' => 'Все сделки успешно очищены. При следующем обновлении данных счета будет загружена полная история.']);
        } else {
            // В случае ошибки откатываем изменения
            $wpdb->query('ROLLBACK');
            wp_send_json_error(['message' => 'Произошла ошибка при очистке сделок']);
        }
    } catch (Exception $e) {
        $wpdb->query('ROLLBACK');
        wp_send_json_error(['message' => 'Ошибка: ' . $e->getMessage()]);
    }
}
add_action('wp_ajax_clear_order_history', 'fttradingapi_clear_order_history');

// Регистрация AJAX-обработчиков
add_action('wp_ajax_fttradingapi_create_update_queue', 'fttradingapi_create_update_queue');
add_action('wp_ajax_fttradingapi_get_update_status', 'fttradingapi_get_update_status');
