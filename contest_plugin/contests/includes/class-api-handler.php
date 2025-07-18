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
 * Очищает лог HTTP запросов
 */
function clear_http_requests_log() {
    $http_log_path = plugin_dir_path(__FILE__) . 'logs/http_requests.log';
    if (file_exists($http_log_path)) {
        file_put_contents($http_log_path, '');
        error_log("HTTP requests log cleared: " . $http_log_path);
        return true;
    }
    return false;
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
    
    // ЗАЩИТА ОТ ДУБЛИРОВАНИЯ: Проверяем блокировку для существующих счетов
    if (!$is_new) {
        $lock_key = 'updating_account_' . $account_id;
        $lock_value = get_transient($lock_key);
        
        if ($lock_value) {
            error_log("[API-HANDLER] БЛОКИРОВКА: Счет ID {$account_id} уже обновляется. Запрос отклонен. Queue: " . ($queue_batch_id ?? 'unknown'));
            return [
                'success' => false,
                'message' => 'Счет уже обновляется, дублирующий запрос отклонен'
            ];
        }
        
        // Устанавливаем блокировку на 60 секунд
        set_transient($lock_key, $queue_batch_id ?? 'manual', 60);
        error_log("[API-HANDLER] БЛОКИРОВКА: Установлена блокировка для счета ID {$account_id}. Queue: " . ($queue_batch_id ?? 'manual'));
    }

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
    
    // ИСПРАВЛЕНИЕ: Декодируем HTML-сущности в пароле перед отправкой
    $decoded_password = html_entity_decode($password, ENT_QUOTES, 'UTF-8');
    if ($decoded_password !== $password) {
        ft_api_log([
            'original_password' => $password,
            'decoded_password' => $decoded_password,
            'changed' => true
        ], "Декодирование HTML-сущностей в пароле", "info");
        $password = $decoded_password;
        $params['password'] = $decoded_password;
    }
    
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
    
    // Получаем настройки автоматического обновления для таймаута
    $auto_update_settings = get_option('fttrader_auto_update_settings', []);
    $api_timeout = isset($auto_update_settings['fttrader_api_timeout']) ? 
        intval($auto_update_settings['fttrader_api_timeout']) : 30; // По умолчанию 30 секунд
    
    // ДЕТАЛЬНОЕ ЛОГИРОВАНИЕ HTTP ЗАПРОСА
    $request_id = 'req_' . uniqid();
    $request_start_time = microtime(true);
    $queue_id = isset($api_params['queue_batch_id']) ? $api_params['queue_batch_id'] : 'unknown';
    
    // Путь к специальному логу для HTTP запросов
    $http_log_path = plugin_dir_path(__FILE__) . 'logs/http_requests.log';
    $log_dir = dirname($http_log_path);
    if (!file_exists($log_dir)) {
        wp_mkdir_p($log_dir);
    }
    
    // Логируем начало запроса в отдельный файл
    $log_entry = "============================================================\n";
    $log_entry .= "🌐 HTTP REQUEST START\n";
    $log_entry .= "   ID: " . $request_id . "\n";
    $log_entry .= "   TIME: " . date('Y-m-d H:i:s', time()) . "\n";
    $log_entry .= "   ACCOUNT: " . $params['login'] . "\n";
    $log_entry .= "   SERVER: " . $params['server'] . "\n";
    $log_entry .= "   URL: " . $url . "\n";
    $log_entry .= "   QUEUE: " . $queue_id . "\n";
    $log_entry .= "   ------------------------------------------------------------\n";
    file_put_contents($http_log_path, $log_entry, FILE_APPEND | LOCK_EX);
    
    // Отправляем запрос с настраиваемым таймаутом
    $response = wp_remote_get($url, ['timeout' => $api_timeout, 'sslverify' => false]);
    
    // Вычисляем длительность запроса
    $request_end_time = microtime(true);
    $duration_ms = round(($request_end_time - $request_start_time) * 1000, 2);
    
    // Получаем информацию об ответе
    $http_code = is_wp_error($response) ? 'ERROR' : wp_remote_retrieve_response_code($response);
    $response_body = is_wp_error($response) ? '' : wp_remote_retrieve_body($response);
    $response_size = strlen($response_body);
    
    // Определяем статус запроса
    $request_status = 'ERROR';
    if (!is_wp_error($response) && $http_code >= 200 && $http_code < 300) {
        $request_status = 'SUCCESS';
    } elseif (!is_wp_error($response)) {
        $request_status = 'HTTP_ERROR';
    }
    
    // Логируем конец запроса в отдельный файл
    $end_time = time();
    $log_entry = "✅ HTTP REQUEST END\n";
    $log_entry .= "   ID: " . $request_id . "\n";
    $log_entry .= "   END_TIME: " . date('Y-m-d H:i:s', $end_time) . "\n";
    $log_entry .= "   DURATION: " . $duration_ms . "ms\n";
    $log_entry .= "   STATUS: " . $request_status . "\n";
    $log_entry .= "   HTTP_CODE: " . $http_code . "\n";
    $log_entry .= "   RESPONSE_SIZE: " . $response_size . " bytes\n";
    $log_entry .= "============================================================\n";
    
    // Записываем в файл
    file_put_contents($http_log_path, $log_entry, FILE_APPEND | LOCK_EX);

    if (is_wp_error($response)) {
        $error_message = $response->get_error_message();
        ft_api_log($error_message, "API Main Request Error", "error");
        
        // Расширенная информация об ошибке
        $error_data = $response->get_error_data();
        if (!empty($error_data)) {
            ft_api_log($error_data, "API Error Additional Data", "error");
        }
        
        return [
            'success' => false,
            'message' => "Ошибка соединения с API сервером: {$error_message}. Пожалуйста, попробуйте позже.",
            'debug_info' => $error_data
        ];
    }

    // Используем уже полученную переменную $response_body
    $body = $response_body;
    
    // Получаем код HTTP ответа  
    $status_code = $http_code;

    // Обрабатываем код 500 специальным образом
    if ($status_code == 500) {
        ft_api_log([
            'status_code' => $status_code,
            'headers' => wp_remote_retrieve_headers($response),
            'body_preview' => substr($body, 0, 500)
        ], "API вернул HTTP 500 - внутренняя ошибка сервера", "error");
        
        return [
            'success' => false,
            'message' => 'Сервер API временно недоступен. На сервере идет обновление. Пожалуйста, попробуйте снова через 5-10 минут.',
            'debug_info' => 'HTTP 500 - внутренняя ошибка сервера'
        ];
    }

    // Обрабатываем коды 4xx
    if ($status_code >= 400 && $status_code < 500) {
        ft_api_log([
            'status_code' => $status_code,
            'body' => $body
        ], "API Client Error {$status_code}", "error");
        
        return [
            'success' => false,
            'message' => "Ошибка запроса к API (код {$status_code}). Проверьте данные для входа.",
            'debug_info' => "HTTP {$status_code}: {$body}"
        ];
    }

    ft_api_log($body, "API Response", "info");

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
            // ЗАЩИТА ДИСКВАЛИФИКАЦИИ: Проверяем текущий статус перед обновлением
            $current_status = $wpdb->get_var($wpdb->prepare(
                "SELECT connection_status FROM $table_name WHERE id = %d",
                $account_id
            ));
            
            // Если счет дисквалифицирован, НЕ изменяем статус
            if ($current_status === 'disqualified') {
                error_log("[API-HANDLER] ЗАЩИТА: Пропускаем изменение статуса для дисквалифицированного счета ID: {$account_id}");
                // Обновляем только время последнего обновления
                $wpdb->update(
                    $table_name,
                    ['last_update' => current_time('mysql')],
                    ['id' => $account_id]
                );
            } else {
                // Обновляем только статус подключения и ошибку, финансовые показатели не трогаем
                $wpdb->update(
                    $table_name,
                    $db_data,
                    ['id' => $account_id]
                );
            }
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
            // ЗАЩИТА ДИСКВАЛИФИКАЦИИ: Проверяем текущий статус перед обновлением
            $current_status = $wpdb->get_var($wpdb->prepare(
                "SELECT connection_status FROM $table_name WHERE id = %d",
                $account_id
            ));
            
            // Если счет дисквалифицирован, НЕ изменяем статус
            if ($current_status === 'disqualified') {
                error_log("[API-HANDLER] ЗАЩИТА: Пропускаем изменение статуса для дисквалифицированного счета ID: {$account_id} (connection_status=disconnected)");
                // Обновляем только время последнего обновления
                $wpdb->update(
                    $table_name,
                    ['last_update' => current_time('mysql')],
                    ['id' => $account_id]
                );
            } else {
                // Обновляем только статус подключения и ошибку, не трогая финансовые показатели
                $wpdb->update(
                    $table_name,
                    $db_data,
                    ['id' => $account_id]
                );
            }
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
            // ЗАЩИТА ДИСКВАЛИФИКАЦИИ: Проверяем текущий статус перед обновлением
            $current_status = $wpdb->get_var($wpdb->prepare(
                "SELECT connection_status FROM $table_name WHERE id = %d",
                $account_id
            ));
            
            // Если счет дисквалифицирован, НЕ изменяем статус
            if ($current_status === 'disqualified') {
                error_log("[API-HANDLER] ЗАЩИТА: Пропускаем изменение статуса для дисквалифицированного счета ID: {$account_id} (missing fields)");
                // Обновляем только время последнего обновления
                $wpdb->update(
                    $table_name,
                    ['last_update' => current_time('mysql')],
                    ['id' => $account_id]
                );
            } else {
                // Обновляем только статус подключения и ошибку, финансовые показатели не трогаем
                $wpdb->update(
                    $table_name,
                    $db_data,
                    ['id' => $account_id]
                );
            }
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
            'h_count' => $account->orders_history_total,
            'pass' => $account->password,
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

        // Подготавливаем новые данные для отслеживания истории
        $new_data_for_history = $data['acc'];
        
        // Добавляем h_count из statistics если есть
        if (isset($data['statistics']['ACCOUNT_ORDERS_HISTORY_TOTAL'])) {
            $new_data_for_history['h_count'] = $data['statistics']['ACCOUNT_ORDERS_HISTORY_TOTAL'];
        }

        // Добавляем новый пароль если он был изменен
        if (isset($account_data['password']) && !empty($account_data['password'])) {
            $new_data_for_history['pass'] = $account_data['password'];
            }

        // Отслеживаем изменения для существующих счетов
        $history = new Account_History();
        $history->track_changes($account_id, $old_data, $new_data_for_history);
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
                
                // Исправленная логика v1.2.1 - корректная обработка нулей с учетом типа поля
                if ($value == 0) {
                    // Для orders_total (количество ордеров) никогда не используем отрицательные значения
                    if ($db_key == 'orders_total') {
                        // Количество ордеров может быть 0 - это нормально
                        $db_data[$db_key] = 0;
                        error_log("[API-HANDLER] Установлено 0 ордеров для поля $db_key (account_id: {$account_id})");
                    } else {
                        // Для других финансовых полей применяем отладочную логику
                        // Блок 1: Нули приходят из API при статусе connected
                        if (isset($data['acc']['connection_status']) && $data['acc']['connection_status'] === 'connected') {
                            $db_data[$db_key] = -1; // Блок 1: Нули из API при connected статусе
                            error_log("[API-HANDLER] ОТЛАДКА v1.2.1: Блок 1 (-1): нули из API при connected статусе для поля $db_key (account_id: {$account_id})");
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
                                        error_log("[API-HANDLER] ОТЛАДКА v1.2.1: Блок 1 (-1): нули для демо-счета для поля $db_key (account_id: {$account_id})");
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
                        if ($db_key === 'leverage') {
                            $db_data[$db_key] = null; // Для leverage используем NULL если данных нет
                        } else {
                            $db_data[$db_key] = 0; // Для остальных полей используем 0
                        }
                        error_log("[API-HANDLER] ОТЛАДКА v1.2.1: Блок 2: отсутствуют старые значения для поля $db_key, установлено значение: " . ($db_key === 'leverage' ? 'NULL' : '0') . " (account_id: {$account_id})");
                    }
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
                    'balance' => 0.0, // Нулевой баланс по умолчанию
                    'equity' => 0.0, // Нулевой эквити по умолчанию
                    'margin' => 0.0, // Нулевая маржа по умолчанию
                    'profit' => 0.0, // Нулевая прибыль по умолчанию
                    'leverage' => null, // Пустое значение если плечо не определено
                    'orders_total' => 0, // Количество ордеров не может быть отрицательным
                    'orders_history_total' => 0, // Нулевое количество исторических ордеров
                    'orders_history_profit' => 0.0 // Нулевая историческая прибыль
                ];
                $db_data[$db_key] = $default_values[$db_key];
                error_log("[API-HANDLER] ОТЛАДКА v1.2.1: Блок 2 (корректное значение): поле $db_key отсутствует в API, используем значение по умолчанию {$default_values[$db_key]} (account_id: {$account_id})");
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
            
            // Создаем записи начальных значений в истории изменений для нового счета
            create_initial_history_records($account_id, $db_data);
            
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
            // УЛУЧШЕННАЯ ЗАЩИТА ДИСКВАЛИФИКАЦИИ: Проверяем статус ПЕРЕД обновлением
            $current_account = $wpdb->get_row($wpdb->prepare(
                "SELECT connection_status, error_description FROM $table_name WHERE id = %d",
                $account_id
            ), ARRAY_A);
            
            $is_disqualified = isset($current_account['connection_status']) && $current_account['connection_status'] === 'disqualified';
            
            if ($is_disqualified) {
                // Если счет дисквалифицирован, НЕ изменяем статус подключения и описание ошибки
                error_log("[API-HANDLER] ЗАЩИТА: Сохраняем дисквалификацию для счета ID: {$account_id}");
                
                // Убираем поля статуса из обновления
                unset($db_data['connection_status']);
                unset($db_data['error_description']);
                
                // Если нет других полей для обновления, то обновляем только время
                if (count($db_data) <= 1) { // только last_update
                    $result = $wpdb->update(
                        $table_name, 
                        ['last_update' => current_time('mysql')], 
                        ['id' => $account_id]
                    );
                } else {
                    // Обновляем финансовые данные без изменения статуса
                    
                    $result = $wpdb->update($table_name, $db_data, ['id' => $account_id]);
                }
            } else {
                // Счет не дисквалифицирован - обновляем все данные включая статус
                
                $result = $wpdb->update($table_name, $db_data, ['id' => $account_id]);
            }

            if ($result === false) {
                return [
                    'success' => false,
                    'message' => 'Ошибка базы данных: ' . $wpdb->last_error
                ];
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

            // Снимаем блокировку перед успешным возвратом
            if (!$is_new && isset($account_id)) {
                $lock_key = 'updating_account_' . $account_id;
                delete_transient($lock_key);
                error_log("[API-HANDLER] БЛОКИРОВКА: Снята блокировка для счета ID {$account_id} (успешное обновление)");
            }
            
            // Явно возвращаем успешный результат с булевым значением success
            return [
                'success' => true, // Используем булево значение true вместо 1
                'message' => 'Данные счета успешно обновлены'
            ];
        } catch (Exception $e) {
            error_log('Exception during account update: ' . $e->getMessage());
            
            // Снимаем блокировку при исключении
            if (!$is_new && isset($account_id)) {
                $lock_key = 'updating_account_' . $account_id;
                delete_transient($lock_key);
                error_log("[API-HANDLER] БЛОКИРОВКА: Снята блокировка для счета ID {$account_id} (исключение)");
            }
            
            return [
                'success' => false, // Используем булево значение false
                'message' => 'Ошибка при обновлении счета: ' . $e->getMessage()
            ];
        }
    }
    
    // Если функция дошла до этого места без return, значит что-то пошло не так
    // Это не должно происходить, но добавим страховочный код
    error_log('[API-HANDLER] Критическая ошибка: функция process_trading_account завершилась без return');
    
    // Снимаем блокировку перед выходом
    if (!$is_new && isset($account_id)) {
        $lock_key = 'updating_account_' . $account_id;
        delete_transient($lock_key);
        error_log("[API-HANDLER] БЛОКИРОВКА: Снята блокировка для счета ID {$account_id} (критическая ошибка)");
    }
    
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
        'password' => !empty($_POST['password']) ? wp_unslash($_POST['password']) : '',
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
        'password' => !empty($_POST['password']) ? wp_unslash($_POST['password']) : '',
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
        $page = isset($_POST['page']) ? intval($_POST['page']) : 1;
        $per_page = isset($_POST['per_page']) ? intval($_POST['per_page']) : 10;

        $history = new Account_History();
        $result = $history->get_filtered_history($account_id, $field, $period, $sort, $page, $per_page);
        
        // Передаем массив изменений и информацию о пагинации в шаблон
        $changes = $result['results'];
        $pagination = [
            'total_items' => $result['total_items'],
            'total_pages' => $result['total_pages'],
            'current_page' => $result['current_page'],
            'per_page' => $result['per_page']
        ];

        include(plugin_dir_path(__FILE__) . '../admin/views/history-table.php');
        wp_die();
    } catch (Exception $e) {
        wp_send_json_error('Ошибка: ' . $e->getMessage());
    }
}

add_action('wp_ajax_load_account_history', 'fttradingapi_load_account_history');
add_action('wp_ajax_nopriv_load_account_history', 'fttradingapi_load_account_history');

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
    
    // ОТКЛЮЧЕНО: Немедленная обработка первых счетов вызывает дублирование с демоном
    // Если очередь создана успешно, передаем queue_id в запросы на обновление счетов
    /*
    if ($result['success'] && isset($result['queue_id'])) {
        $queue_id = $result['queue_id'];
        
        // Для первых 3 счетов в очереди (или меньше, если в очереди меньше счетов)
        // выполняем немедленное обновление с передачей queue_id
        $initial_processing = array_slice($account_ids, 0, 3);
        
        foreach ($initial_processing as $account_id) {
            // Запускаем обновление с передачей ID очереди для логирования
            process_trading_account([], $account_id, $contest_id, $queue_id);

            // === NEW: сразу отмечаем счет в статусе очереди ===
            $contest_prefix = $contest_id ? $contest_id : 'global';
            $status_option = 'contest_accounts_update_status_' . $contest_prefix . '_' . $queue_id;
            $status_data   = get_option($status_option, []);
            if (isset($status_data['accounts'][$account_id])) {
                $status_data['accounts'][$account_id]['status']   = 'success';
                $status_data['accounts'][$account_id]['message']  = 'Initial batch auto-update';
                $status_data['accounts'][$account_id]['end_time'] = time();
                $status_data['completed']++;
                $status_data['success']++;
                $status_data['last_update'] = time();
                update_option($status_option, $status_data);
            }
        }
    }
    */
    
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

/**
 * AJAX-обработчик для принудительного перезапуска зависшей очереди (диагностика)
 */
function fttradingapi_restart_queue_diagnostics()
{
    check_ajax_referer('ft_trader_nonce', 'nonce');

    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Недостаточно прав']);
    }

    $queue_id = isset($_POST['queue_id']) ? sanitize_text_field($_POST['queue_id']) : '';
    $contest_id = isset($_POST['contest_id']) ? intval($_POST['contest_id']) : null;
    
    if (empty($queue_id)) {
        wp_send_json_error(['message' => 'ID очереди не указан']);
    }
    
    error_log("=== ПРИНУДИТЕЛЬНЫЙ ПЕРЕЗАПУСК ОЧЕРЕДИ ===");
    error_log("Queue ID: {$queue_id}");
    error_log("Contest ID: " . ($contest_id ? $contest_id : 'NULL'));
    error_log("Инициатор: " . wp_get_current_user()->user_login);
    error_log("Время: " . date('Y-m-d H:i:s'));
    
    require_once plugin_dir_path(__FILE__) . 'class-account-updater.php';
    
    // Получаем статус очереди
    $status = Account_Updater::get_status($contest_id, $queue_id);
    
    if ($status['is_running']) {
        error_log("ОШИБКА: Очередь {$queue_id} все еще активна, перезапуск невозможен");
        wp_send_json_error(['message' => 'Очередь все еще активна. Перезапуск возможен только для зависших очередей.']);
    }
    
    if (empty($status) || $status['message'] === 'Очередь не найдена') {
        error_log("ОШИБКА: Очередь {$queue_id} не найдена в системе");
        wp_send_json_error(['message' => 'Очередь не найдена в системе']);
    }
    
    // Принудительно устанавливаем статус "активна" и сбрасываем счетчики
    $contest_prefix = $contest_id ? $contest_id : 'global';
    $status_option = 'contest_accounts_update_status_' . $contest_prefix . '_' . $queue_id;
    $queue_option = 'contest_accounts_update_queue_' . $contest_prefix . '_' . $queue_id;
    
    // Получаем текущий статус
    $current_status = get_option($status_option, []);
    
    if (empty($current_status)) {
        error_log("ОШИБКА: Статус очереди {$queue_id} не найден в опциях WordPress");
        wp_send_json_error(['message' => 'Статус очереди не найден в базе данных']);
    }
    
    // Подсчитываем необработанные счета
    $unprocessed_accounts = [];
    if (isset($current_status['accounts']) && is_array($current_status['accounts'])) {
        foreach ($current_status['accounts'] as $account_id => $account_status) {
            if ($account_status['status'] === 'pending' || $account_status['status'] === 'processing') {
                $unprocessed_accounts[] = intval($account_id);
            }
        }
    }
    
    error_log("Найдено необработанных счетов: " . count($unprocessed_accounts));
    error_log("ID необработанных счетов: " . implode(', ', $unprocessed_accounts));
    
    if (empty($unprocessed_accounts)) {
        error_log("ПРЕДУПРЕЖДЕНИЕ: Нет необработанных счетов, но очередь помечена как зависшая");
        wp_send_json_error(['message' => 'В очереди нет счетов для повторной обработки']);
    }
    
    // Сбрасываем статус для необработанных счетов
    foreach ($unprocessed_accounts as $account_id) {
        $current_status['accounts'][$account_id]['status'] = 'pending';
        $current_status['accounts'][$account_id]['message'] = 'Перезапущено для диагностики';
        $current_status['accounts'][$account_id]['start_time'] = 0;
        $current_status['accounts'][$account_id]['end_time'] = 0;
    }
    
    // Пересчитываем статистику
    $completed_count = 0;
    $success_count = 0;
    $failed_count = 0;
    
    foreach ($current_status['accounts'] as $account_status) {
        if ($account_status['status'] === 'success' || $account_status['status'] === 'failed') {
            $completed_count++;
            if ($account_status['status'] === 'success') {
                $success_count++;
            } else {
                $failed_count++;
            }
        }
    }
    
    // Обновляем статус очереди
    $current_status['is_running'] = true;
    $current_status['completed'] = $completed_count;
    $current_status['success'] = $success_count;
    $current_status['failed'] = $failed_count;
    $current_status['last_update'] = time();
    $current_status['current_batch'] = floor($completed_count / 2); // Предполагаем размер пакета 2
    $current_status['timeout'] = false;
    $current_status['message'] = 'Перезапущено для диагностики';
    
    // Добавляем информацию о перезапуске
    $current_status['restart_info'] = [
        'restart_time' => time(),
        'restart_user' => wp_get_current_user()->user_login,
        'restarted_accounts' => count($unprocessed_accounts)
    ];
    
    update_option($status_option, $current_status);
    
    // Обновляем очередь счетов (оставляем только необработанные)
    update_option($queue_option, $unprocessed_accounts);
    
    // Регистрируем очередь как активную
    $contest_key = 'contest_active_queues_' . ($contest_id ? $contest_id : 'global');
    $active_queues = get_option($contest_key, []);
    $active_queues[$queue_id] = [
        'status_option' => $status_option,
        'start_time' => time()
    ];
    update_option($contest_key, $active_queues);
    
    // Планируем выполнение через 1 секунду
    $scheduled = wp_schedule_single_event(time() + 1, 'process_accounts_update_batch', [$contest_id, $queue_id]);
    
    error_log("Очередь {$queue_id} перезапущена:");
    error_log("- Запланирована задача: " . ($scheduled ? 'YES' : 'NO'));
    error_log("- Необработанных счетов: " . count($unprocessed_accounts));
    error_log("- Новый статус: is_running=true");
    
    // Принудительный запуск cron
    spawn_cron();
    
    error_log("=== КОНЕЦ ПЕРЕЗАПУСКА ОЧЕРЕДИ ===");
    
    wp_send_json_success([
        'message' => "Очередь {$queue_id} перезапущена",
        'restarted_accounts' => count($unprocessed_accounts),
        'scheduled' => $scheduled
    ]);
}
add_action('wp_ajax_restart_queue_diagnostics', 'fttradingapi_restart_queue_diagnostics');

/**
 * AJAX обработчик для анализа таймаутов
 */
function fttradingapi_analyze_timeouts()
{
    check_ajax_referer('ft_trader_nonce', 'nonce');

    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Недостаточно прав']);
    }

    require_once plugin_dir_path(__FILE__) . 'class-account-updater.php';
    
    // Выполняем анализ в тестовом режиме
    $result = Account_Updater::cleanup_timeout_queues([
        'dry_run' => true,
        'older_than_hours' => 1, // Анализируем все старше 1 часа
        'include_completed' => true // Включаем завершенные
    ]);
    
    if ($result['success']) {
        $analysis_html = '<div class="analysis-results">';
        $analysis_html .= '<h4>📊 Результаты анализа</h4>';
        $analysis_html .= '<p><strong>Проанализировано очередей:</strong> ' . $result['analyzed_queues'] . '</p>';
        
        if (!empty($result['eligible_for_cleanup'])) {
            $analysis_html .= '<h5>🗑️ Готовы к очистке (' . count($result['eligible_for_cleanup']) . '):</h5>';
            $analysis_html .= '<ul style="max-height: 200px; overflow-y: auto;">';
            foreach ($result['eligible_for_cleanup'] as $queue) {
                $analysis_html .= sprintf(
                    '<li><code>%s</code> - %s (возраст: %.1fч, прогресс: %.1f%%)</li>',
                    $queue['queue_id'],
                    $queue['reason'],
                    $queue['age_hours'],
                    $queue['progress']
                );
            }
            $analysis_html .= '</ul>';
        }
        
        if (!empty($result['preserved_queues'])) {
            $analysis_html .= '<h5>✅ Будут сохранены (' . count($result['preserved_queues']) . '):</h5>';
            $analysis_html .= '<ul style="max-height: 150px; overflow-y: auto;">';
            foreach ($result['preserved_queues'] as $queue) {
                $analysis_html .= sprintf(
                    '<li><code>%s</code> - %s (возраст: %.1fч, прогресс: %.1f%%)</li>',
                    $queue['queue_id'],
                    $queue['reason'],
                    $queue['age_hours'],
                    $queue['progress']
                );
            }
            $analysis_html .= '</ul>';
        }
        
        $analysis_html .= '</div>';
        
        wp_send_json_success([
            'message' => $result['summary'],
            'html' => $analysis_html,
            'eligible_count' => count($result['eligible_for_cleanup']),
            'preserved_count' => count($result['preserved_queues'])
        ]);
    } else {
        wp_send_json_error(['message' => 'Ошибка анализа: ' . implode(', ', $result['errors'])]);
    }
}
add_action('wp_ajax_analyze_timeouts', 'fttradingapi_analyze_timeouts');

/**
 * AJAX обработчик для очистки старых таймаутов (24ч+)
 */
function fttradingapi_cleanup_old_timeouts()
{
    check_ajax_referer('ft_trader_nonce', 'nonce');

    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Недостаточно прав']);
    }

    error_log("=== ОЧИСТКА СТАРЫХ ТАЙМАУТОВ ===");
    error_log("Инициатор: " . wp_get_current_user()->user_login);
    
    require_once plugin_dir_path(__FILE__) . 'class-account-updater.php';
    
    // Очищаем только старые таймауты (24ч+)
    $result = Account_Updater::cleanup_timeout_queues([
        'dry_run' => false,
        'older_than_hours' => 24,
        'min_progress' => 0,
        'max_progress' => 100,
        'include_completed' => false // Не трогаем завершенные
    ]);
    
    if ($result['success']) {
        $cleanup_html = '<div class="cleanup-results">';
        $cleanup_html .= '<h4>✅ Очистка завершена</h4>';
        $cleanup_html .= '<p><strong>Результат:</strong> ' . $result['summary'] . '</p>';
        
        if (!empty($result['cleaned_queues'])) {
            $cleanup_html .= '<h5>🗑️ Очищенные очереди:</h5>';
            $cleanup_html .= '<ul>';
            foreach ($result['cleaned_queues'] as $queue) {
                $cleanup_html .= sprintf(
                    '<li><code>%s</code> (конкурс: %s, счетов: %d)</li>',
                    $queue['queue_id'],
                    $queue['contest_id'] ?: 'глобальные',
                    $queue['accounts_count']
                );
            }
            $cleanup_html .= '</ul>';
        }
        
        if (!empty($result['errors'])) {
            $cleanup_html .= '<h5>❌ Ошибки:</h5>';
            $cleanup_html .= '<ul>';
            foreach ($result['errors'] as $error) {
                $cleanup_html .= '<li style="color: #d63638;">' . esc_html($error) . '</li>';
            }
            $cleanup_html .= '</ul>';
        }
        
        $cleanup_html .= '<p><em>Страница обновится автоматически через 3 секунды...</em></p>';
        $cleanup_html .= '</div>';
        
        wp_send_json_success([
            'message' => $result['summary'],
            'html' => $cleanup_html,
            'cleaned_count' => count($result['cleaned_queues']),
            'error_count' => count($result['errors'])
        ]);
    } else {
        wp_send_json_error(['message' => 'Ошибка очистки: ' . implode(', ', $result['errors'])]);
    }
}
add_action('wp_ajax_cleanup_old_timeouts', 'fttradingapi_cleanup_old_timeouts');

/**
 * AJAX обработчик для очистки всех таймаутов
 */
function fttradingapi_cleanup_all_timeouts()
{
    check_ajax_referer('ft_trader_nonce', 'nonce');

    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Недостаточно прав']);
    }

    error_log("=== ОЧИСТКА ВСЕХ ТАЙМАУТОВ ===");
    error_log("Инициатор: " . wp_get_current_user()->user_login);
    
    require_once plugin_dir_path(__FILE__) . 'class-account-updater.php';
    
    // Очищаем ВСЕ таймауты независимо от возраста
    $result = Account_Updater::cleanup_timeout_queues([
        'dry_run' => false,
        'older_than_hours' => 0, // Любой возраст
        'min_progress' => 0,
        'max_progress' => 100,
        'include_completed' => false // Но не трогаем завершенные
    ]);
    
    if ($result['success']) {
        $cleanup_html = '<div class="cleanup-results">';
        $cleanup_html .= '<h4>⚠️ Агрессивная очистка завершена</h4>';
        $cleanup_html .= '<p><strong>Результат:</strong> ' . $result['summary'] . '</p>';
        
        if (!empty($result['cleaned_queues'])) {
            $cleanup_html .= '<h5>🗑️ Очищенные очереди:</h5>';
            $cleanup_html .= '<ul>';
            foreach ($result['cleaned_queues'] as $queue) {
                $cleanup_html .= sprintf(
                    '<li><code>%s</code> (конкурс: %s, счетов: %d)</li>',
                    $queue['queue_id'],
                    $queue['contest_id'] ?: 'глобальные',
                    $queue['accounts_count']
                );
            }
            $cleanup_html .= '</ul>';
        }
        
        if (!empty($result['preserved_queues'])) {
            $cleanup_html .= '<h5>✅ Сохранены (активные/новые):</h5>';
            $cleanup_html .= '<ul>';
            foreach ($result['preserved_queues'] as $queue) {
                $cleanup_html .= sprintf(
                    '<li><code>%s</code> - %s</li>',
                    $queue['queue_id'],
                    $queue['reason']
                );
            }
            $cleanup_html .= '</ul>';
        }
        
        if (!empty($result['errors'])) {
            $cleanup_html .= '<h5>❌ Ошибки:</h5>';
            $cleanup_html .= '<ul>';
            foreach ($result['errors'] as $error) {
                $cleanup_html .= '<li style="color: #d63638;">' . esc_html($error) . '</li>';
            }
            $cleanup_html .= '</ul>';
        }
        
        $cleanup_html .= '<p><em>Страница обновится автоматически через 3 секунды...</em></p>';
        $cleanup_html .= '</div>';
        
        wp_send_json_success([
            'message' => $result['summary'],
            'html' => $cleanup_html,
            'cleaned_count' => count($result['cleaned_queues']),
            'preserved_count' => count($result['preserved_queues']),
            'error_count' => count($result['errors'])
        ]);
    } else {
        wp_send_json_error(['message' => 'Ошибка очистки: ' . implode(', ', $result['errors'])]);
    }
}
add_action('wp_ajax_cleanup_all_timeouts', 'fttradingapi_cleanup_all_timeouts');

/**
 * Создает записи начальных значений в истории изменений для нового счета
 * 
 * @param int $account_id ID нового счета
 * @param array $db_data Данные счета для записи в историю
 */
function create_initial_history_records($account_id, $db_data) {
    global $wpdb;
    $history_table = $wpdb->prefix . 'contest_members_history';
    
    // Определяем все поля, которые нужно записать как начальные значения
    $fields_to_record = [
        // Финансовые поля
        'balance' => 'i_bal',
        'equity' => 'i_equi', 
        'margin' => 'i_marg',
        'profit' => 'i_prof',
        'leverage' => 'leverage',
        'orders_total' => 'i_ordtotal',
        'orders_history_total' => 'h_count',
        'orders_history_profit' => 'h_prof',
        
        // Информационные поля  
        'broker' => 'i_firma',
        'name' => 'i_fio',
        'account_type' => 'i_dr',
        'currency' => 'i_cur',
        'password' => 'pass',
        'server' => 'srvMt4',
        'connection_status' => 'connection_status'
    ];
    
    $current_time = current_time('mysql');
    
    foreach ($fields_to_record as $db_field => $history_field) {
        if (isset($db_data[$db_field])) {
            $value = $db_data[$db_field];
            
            // Пропускаем нулевые и пустые значения для некоторых полей
            if (in_array($db_field, ['balance', 'equity', 'margin', 'profit', 'leverage']) && 
                ($value === 0 || $value === '0' || $value === null || $value === '')) {
                continue;
            }
            
            // Вставляем запись начального значения
            $wpdb->insert(
                $history_table,
                [
                    'account_id' => $account_id,
                    'field_name' => $history_field,
                    'old_value' => '', // Для начальных значений старое значение пустое
                    'new_value' => $value,
                    'change_percent' => null, // Для начальных значений процент изменения не рассчитывается
                    'change_date' => $current_time
                ],
                ['%d', '%s', '%s', '%s', '%f', '%s']
            );
            
            error_log("[INITIAL-HISTORY] Создана запись начального значения: field={$history_field}, value={$value} (account_id: {$account_id})");
        }
    }
    
    // Создаем запись для active_orders_volume (всегда 0 для нового счета)
    $wpdb->insert(
        $history_table,
        [
            'account_id' => $account_id,
            'field_name' => 'active_orders_volume',
            'old_value' => '',
            'new_value' => '0',
            'change_percent' => null,
            'change_date' => $current_time
        ],
        ['%d', '%s', '%s', '%s', '%f', '%s']
    );
    
    error_log("[INITIAL-HISTORY] Созданы записи начальных значений для счета ID: {$account_id}");
}

/**
 * Создает недостающие записи начальных значений для существующих счетов
 * 
 * @param int $account_id ID счета (необязательно, если не указан - обработает все счета)
 */
function create_missing_initial_records($account_id = null) {
    global $wpdb;
    $members_table = $wpdb->prefix . 'contest_members';
    $history_table = $wpdb->prefix . 'contest_members_history';
    
    // Получаем список счетов для обработки
    if ($account_id) {
        $accounts = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$members_table} WHERE id = %d",
            $account_id
        ));
    } else {
        $accounts = $wpdb->get_results("SELECT * FROM {$members_table}");
    }
    
    $fields_to_check = [
        'balance' => 'i_bal',
        'equity' => 'i_equi', 
        'margin' => 'i_marg',
        'profit' => 'i_prof',
        'leverage' => 'leverage',
        'orders_total' => 'i_ordtotal',
        'orders_history_total' => 'h_count',
        'broker' => 'i_firma',
        'name' => 'i_fio',
        'account_type' => 'i_dr',
        'currency' => 'i_cur',
        'password' => 'pass',
        'server' => 'srvMt4',
        'connection_status' => 'connection_status'
    ];
    
    foreach ($accounts as $account) {
        $account_id = $account->id;
        $created_records = 0;
        
        foreach ($fields_to_check as $db_field => $history_field) {
            // Проверяем, есть ли уже записи для этого поля
            $existing_count = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$history_table} WHERE account_id = %d AND field_name = %s",
                $account_id,
                $history_field
            ));
            
            // Если записей нет, создаем начальную запись
            if ($existing_count == 0 && isset($account->{$db_field})) {
                $value = $account->{$db_field};
                
                // Пропускаем нулевые значения для финансовых полей
                if (in_array($db_field, ['balance', 'equity', 'margin', 'profit', 'leverage']) && 
                    ($value === 0 || $value === '0' || $value === null || $value === '')) {
                    continue;
                }
                
                // Используем дату регистрации как дату начального значения
                $initial_date = $account->registration_date ?: current_time('mysql');
                
                $wpdb->insert(
                    $history_table,
                    [
                        'account_id' => $account_id,
                        'field_name' => $history_field,
                        'old_value' => '',
                        'new_value' => $value,
                        'change_percent' => null,
                        'change_date' => $initial_date
                    ],
                    ['%d', '%s', '%s', '%s', '%f', '%s']
                );
                
                $created_records++;
                error_log("[MISSING-INITIAL] Создана начальная запись: account_id={$account_id}, field={$history_field}, value={$value}");
            }
        }
        
        // Проверяем active_orders_volume отдельно
        $existing_volume_count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$history_table} WHERE account_id = %d AND field_name = 'active_orders_volume'",
            $account_id
        ));
        
        if ($existing_volume_count == 0) {
            $initial_date = $account->registration_date ?: current_time('mysql');
            
            $wpdb->insert(
                $history_table,
                [
                    'account_id' => $account_id,
                    'field_name' => 'active_orders_volume',
                    'old_value' => '',
                    'new_value' => '0',
                    'change_percent' => null,
                    'change_date' => $initial_date
                ],
                ['%d', '%s', '%s', '%s', '%f', '%s']
            );
            
            $created_records++;
        }
        
        if ($created_records > 0) {
            error_log("[MISSING-INITIAL] Создано {$created_records} начальных записей для счета ID: {$account_id}");
        }
    }
}

// AJAX обработчик для создания недостающих начальных записей
function fttradingapi_create_missing_initial_records() {
    check_ajax_referer('ft_trader_nonce', 'nonce');
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Недостаточно прав']);
    }
    
    $account_id = isset($_POST['account_id']) ? intval($_POST['account_id']) : null;
    
    create_missing_initial_records($account_id);
    
    if ($account_id) {
        wp_send_json_success(['message' => 'Начальные записи созданы для счета ID: ' . $account_id]);
    } else {
        wp_send_json_success(['message' => 'Начальные записи созданы для всех счетов']);
    }
}

add_action('wp_ajax_create_missing_initial_records', 'fttradingapi_create_missing_initial_records');
