<?php
/**
 * Класс для управления процессом обновления счетов на сервере
 */
class Account_Updater
{
    // Ключи для хранения данных в опциях WordPress
    const QUEUE_OPTION_PREFIX = 'contest_accounts_update_queue_';
    const STATUS_OPTION_PREFIX = 'contest_accounts_update_status_';
    const AUTO_UPDATE_LAST_RUN = 'contest_create_queues_last_run';
    const BATCH_SIZE = 2; // Размер пакета по умолчанию для одного запуска - уменьшено до 2, в соответствии с ограничениями API сервера V2023.11.21
    const BATCH_INTERVAL = 60; // Интервал между пакетами в секундах (1 минута) для батчевого режима

    /**
     * Получает таймаут очередей из настроек (в секундах)
     * 
     * @return int Таймаут в секундах
     */
    private static function get_queue_timeout() {
        $auto_update_settings = get_option('fttrader_auto_update_settings', []);
        $timeout_minutes = isset($auto_update_settings['fttrader_auto_update_timeout']) 
            ? intval($auto_update_settings['fttrader_auto_update_timeout']) 
            : 30; // 30 минут по умолчанию
        
        return $timeout_minutes * 60; // Конвертируем в секунды
    }

    /**
     * Инициализирует очередь обновления счетов
     *
     * @param array $account_ids Массив ID счетов для обновления
     * @param bool $is_auto_update Флаг, указывающий, что это автоматическое обновление
     * @param int|null $contest_id ID конкурса (если применимо)
     * @return array Информация о созданной очереди
     */
    public static function init_queue($account_ids, $is_auto_update = false, $contest_id = null)
    {
        global $wpdb;

        // Подключаем API-обработчик для доступа к функции process_trading_account
        $api_handler_file = plugin_dir_path(__FILE__) . 'class-api-handler.php';
        if (file_exists($api_handler_file)) {
            require_once $api_handler_file;
        }

        // Если contest_id не передан, определяем его из первого счета
        if (empty($contest_id) && !empty($account_ids)) {
            $first_account_id = reset($account_ids);
            $table_name = $wpdb->prefix . 'contest_members';
            $contest_id = $wpdb->get_var($wpdb->prepare(
                "SELECT contest_id FROM $table_name WHERE id = %d",
                $first_account_id
            ));
        }

        // Получаем настройки обработки
        $auto_update_settings = get_option('fttrader_auto_update_settings', []);
        $processing_mode = isset($auto_update_settings['fttrader_processing_mode']) ? 
            $auto_update_settings['fttrader_processing_mode'] : 'batch';

        // Создаем уникальный ID для этой очереди
        $letters = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $random_letters = '';
        for ($i = 0; $i < 4; $i++) {
            $random_letters .= $letters[rand(0, strlen($letters) - 1)];
        }
        $queue_id = 'q' . $random_letters;
        
        // ДОБАВЛЕНО: Логируем созданный ID очереди и режим обработки
        error_log("Created queue_id: " . $queue_id . " (mode: " . $processing_mode . ")");
        
        // Выводим информацию в консоль через wp_add_inline_script
        $mode_label = ($processing_mode === 'sequential') ? 'Последовательный' : 'Батчевый';
        $script = "console.log('%c🆔 Создан Queue ID: " . $queue_id . " (" . $mode_label . ")', 'background:#3498db;color:white;padding:4px 8px;border-radius:3px;font-weight:bold;');";
        wp_add_inline_script('jquery', $script);
        
        // Используем contest_id + queue_id для формирования уникальных ключей опций
        // Это позволит запускать несколько параллельных обновлений внутри одного конкурса
        $contest_prefix = $contest_id ? $contest_id : 'global';
        $queue_option = self::QUEUE_OPTION_PREFIX . $contest_prefix . '_' . $queue_id;
        $status_option = self::STATUS_OPTION_PREFIX . $contest_prefix . '_' . $queue_id;

        if (empty($account_ids)) {
            return ['success' => false, 'message' => 'Не выбрано ни одного счета', 'contest_id' => $contest_id, 'queue_id' => $queue_id];
        }

        // Получаем информацию об инициаторе
        $current_user = wp_get_current_user();
        $initiator_info = [
            'type' => $is_auto_update ? 'auto' : 'manual',
            'user_id' => $current_user->ID,
            'user_login' => $current_user->user_login,
            'user_display_name' => $current_user->display_name,
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
        ];

        // Формируем данные о статусе
        $status = [
            'queue_id' => $queue_id,
            'contest_id' => $contest_id,
            'total' => count($account_ids),
            'completed' => 0,
            'success' => 0,
            'failed' => 0,
            'start_time' => time(),
            'last_update' => time(),
            'is_running' => true,
            'current_batch' => 0,
            'is_auto_update' => $is_auto_update,
            'processing_mode' => $processing_mode, // Сохраняем режим обработки
            'initiator' => $initiator_info, // Информация об инициаторе
            'accounts' => [], // Для хранения статуса каждого счета
            'status_option' => $status_option, // Сохраняем имя опции, чтобы легко находить статус
            'queue_option' => $queue_option // Сохраняем имя опции очереди
        ];

        // Инициализируем статус для каждого счета
        foreach ($account_ids as $id) {
            $status['accounts'][$id] = [
                'status' => 'pending', // pending, processing, success, failed
                'message' => '',
                'start_time' => 0,
                'end_time' => 0
            ];
        }

        // Сохраняем статус и очередь
        update_option($status_option, $status);
        update_option($queue_option, $account_ids);
        
        // Добавляем запись о новой очереди в список активных очередей для этого конкурса
        self::register_active_queue($contest_id, $queue_id, $status_option);

        // Планируем обработку с минимальной задержкой
        $initial_delay = 2; // Минимальная задержка для всех режимов
        $scheduled = wp_schedule_single_event(time() + $initial_delay, 'process_accounts_update_batch', [$contest_id, $queue_id]);
        
        // ДОБАВЛЕНО: Детальная диагностика планирования задач
        error_log("=== ДИАГНОСТИКА ПЛАНИРОВАНИЯ ОЧЕРЕДИ {$queue_id} ===");
        error_log("Режим обработки: " . $processing_mode);
        error_log("Начальная задержка: " . $initial_delay . " сек");
        error_log("Результат wp_schedule_single_event: " . ($scheduled ? 'SUCCESS' : 'FAILED'));
        error_log("Contest ID: " . ($contest_id ? $contest_id : 'global'));
        error_log("Время планирования: " . date('Y-m-d H:i:s', time() + $initial_delay));
        error_log("Количество счетов в очереди: " . count($account_ids));
        
        // Проверяем, что задача действительно запланирована
        $scheduled_events = wp_get_scheduled_event('process_accounts_update_batch', [$contest_id, $queue_id]);
        error_log("Запланированное событие найдено: " . ($scheduled_events ? 'YES' : 'NO'));
        if ($scheduled_events) {
            error_log("Время выполнения события: " . date('Y-m-d H:i:s', $scheduled_events->timestamp));
        }
        
        // Проверяем общее состояние WP Cron
        $cron_array = _get_cron_array();
        $our_events_count = 0;
        if (is_array($cron_array)) {
            foreach ($cron_array as $timestamp => $events) {
                if (isset($events['process_accounts_update_batch'])) {
                    $our_events_count += count($events['process_accounts_update_batch']);
                }
            }
        }
        error_log("Всего событий 'process_accounts_update_batch' в очереди WP Cron: " . $our_events_count);
        error_log("=== КОНЕЦ ДИАГНОСТИКИ ПЛАНИРОВАНИЯ ===");
        
        // Принудительный запуск задач WP Cron сразу после планирования
        if ($scheduled) {
            // ИСПРАВЛЕНО: Принудительный запуск для ВСЕХ очередей (убрано ограничение на 50 счетов)
            error_log("Очередь ({$queue_id}): запуск принудительного spawn_cron");
            spawn_cron();
            
            // Дополнительная попытка через 1 секунду для всех очередей
            wp_schedule_single_event(time() + 1, 'process_accounts_update_batch', [$contest_id, $queue_id]);
            error_log("Запланирована дополнительная попытка обработки через 1 секунду для очереди {$queue_id}");
        } else {
            // Если планирование не удалось, обрабатываем первую порцию напрямую
            error_log("КРИТИЧЕСКАЯ ОШИБКА: Планирование не удалось для очереди {$queue_id}. Запуск обработки через 10 секунд.");
            $direct_process_result = self::process_batch($contest_id, $queue_id);
            error_log("Результат обработки: " . ($direct_process_result ? 'SUCCESS' : 'FAILED'));
        }

        return [
            'success' => true,
            'queue_id' => $queue_id,
            'contest_id' => $contest_id,
            'total' => count($account_ids),
            'message' => 'Очередь обновления создана'
        ];
    }

    /**
     * Регистрирует активную очередь для конкурса
     * 
     * @param int|null $contest_id ID конкурса
     * @param string $queue_id ID очереди
     * @param string $status_option Имя опции статуса
     */
    private static function register_active_queue($contest_id, $queue_id, $status_option) {
        $contest_key = 'contest_active_queues_' . ($contest_id ? $contest_id : 'global');
        
        // Получаем текущий список активных очередей
        $active_queues = get_option($contest_key, []);
        
        // Добавляем новую очередь
        $active_queues[$queue_id] = [
            'status_option' => $status_option,
            'start_time' => time()
        ];
        
        // Сохраняем обновленный список
        update_option($contest_key, $active_queues);
    }

    /**
     * Удаляет очередь из списка активных
     * 
     * @param int|null $contest_id ID конкурса
     * @param string $queue_id ID очереди
     */
    private static function unregister_active_queue($contest_id, $queue_id) {
        $contest_key = 'contest_active_queues_' . ($contest_id ? $contest_id : 'global');
        
        // Получаем текущий список активных очередей
        $active_queues = get_option($contest_key, []);
        
        // Удаляем очередь
        if (isset($active_queues[$queue_id])) {
            unset($active_queues[$queue_id]);
            update_option($contest_key, $active_queues);
        }
    }

    /**
     * Обрабатывает порцию счетов из очереди
     *
     * @param int|null $contest_id ID конкурса
     * @param string|null $queue_id ID очереди
     * @return bool Успешно ли обработана порция
     */
    public static function process_batch($contest_id = null, $queue_id = null)
    {
        global $wpdb;

        // ДОБАВЛЕНО: Детальная диагностика начала обработки пакета
        error_log("=== НАЧАЛО ОБРАБОТКИ ПАКЕТА ===");
        error_log("Queue ID: " . ($queue_id ? $queue_id : 'NULL'));
        error_log("Contest ID: " . ($contest_id ? $contest_id : 'NULL')); 
        error_log("Время вызова: " . date('Y-m-d H:i:s'));
        error_log("Вызвано из: " . wp_debug_backtrace_summary());

        // Если queue_id не передан, пытаемся найти активную очередь для конкурса (для обратной совместимости)
        if (empty($queue_id)) {
            $status_option = self::STATUS_OPTION_PREFIX . ($contest_id ? $contest_id : 'global');
            $queue_option = self::QUEUE_OPTION_PREFIX . ($contest_id ? $contest_id : 'global');
            error_log("Используется старый формат опций (без queue_id)");
        } else {
            // Используем переданный queue_id
            $contest_prefix = $contest_id ? $contest_id : 'global';
            $status_option = self::STATUS_OPTION_PREFIX . $contest_prefix . '_' . $queue_id;
            $queue_option = self::QUEUE_OPTION_PREFIX . $contest_prefix . '_' . $queue_id;
            error_log("Сформированы опции для queue_id {$queue_id}:");
            error_log("- Status option: {$status_option}");
            error_log("- Queue option: {$queue_option}");
        }
        
        // Получаем текущий статус
        $status = get_option($status_option, []);
        $queue = get_option($queue_option, []);

        // ДОБАВЛЕНО: Диагностика получения статуса и очереди
        error_log("Статус очереди получен: " . (empty($status) ? 'ПУСТОЙ' : 'НАЙДЕН'));
        if (!empty($status)) {
            error_log("- is_running: " . ($status['is_running'] ? 'true' : 'false'));
            error_log("- total: " . ($status['total'] ?? 'не задано'));
            error_log("- completed: " . ($status['completed'] ?? 'не задано'));
            error_log("- current_batch: " . ($status['current_batch'] ?? 'не задано'));
            error_log("- contest_id в статусе: " . ($status['contest_id'] ?? 'не задано'));
        }
        
        error_log("Очередь счетов получена: " . (empty($queue) ? 'ПУСТАЯ' : count($queue) . ' счетов'));

        // Проверяем, что очередь существует и процесс запущен
        if (empty($status) || empty($queue) || !$status['is_running']) {
            error_log("ОСТАНОВКА: Очередь не прошла базовые проверки");
            error_log("- empty(status): " . (empty($status) ? 'true' : 'false'));
            error_log("- empty(queue): " . (empty($queue) ? 'true' : 'false'));
            error_log("- is_running: " . (isset($status['is_running']) ? ($status['is_running'] ? 'true' : 'false') : 'не задано'));
            error_log("=== КОНЕЦ ОБРАБОТКИ (НЕУДАЧА) ===");
            return false;
        }

        // Проверяем соответствие contest_id в статусе, если он был передан
        if ($contest_id !== null && isset($status['contest_id']) && $status['contest_id'] != $contest_id) {
            error_log("ОСТАНОВКА: Несоответствие contest_id");
            error_log("- Переданный contest_id: {$contest_id} (тип: " . gettype($contest_id) . ")");
            error_log("- contest_id в статусе: " . $status['contest_id'] . " (тип: " . gettype($status['contest_id']) . ")");
            error_log("=== КОНЕЦ ОБРАБОТКИ (НЕУДАЧА) ===");
            return false;
        }

        // ДОБАВЛЕНО: Координация параллельных очередей для предотвращения перегрузки API
        $parallel_delay = self::get_parallel_processing_delay();
        if ($parallel_delay > 0) {
            error_log("Обнаружены параллельные очереди. Добавлена задержка: {$parallel_delay} сек для очереди {$queue_id}");
            sleep($parallel_delay);
        }

        // Получаем настройки размера пакета и интервала
        $auto_update_settings = get_option('fttrader_auto_update_settings', []);
        $processing_mode = isset($status['processing_mode']) ? $status['processing_mode'] : 'batch';
        
        // Размер пакета остается одинаковым для обоих режимов
        $batch_size = isset($auto_update_settings['fttrader_batch_size']) ? 
            intval($auto_update_settings['fttrader_batch_size']) : self::BATCH_SIZE;
        
        error_log("НЕМЕДЛЕННАЯ ОБРАБОТКА: Обрабатываем пакет из {$batch_size} счетов (минимальные задержки между пакетами)");

        // Вычисляем начало и конец текущей порции
        $batch_start = $status['current_batch'] * $batch_size;
        $current_batch = array_slice($queue, $batch_start, $batch_size);
        
        // ЗАЩИТА ОТ ДУБЛИРОВАНИЯ: Убираем уже обрабатываемые счета из пакета
        $filtered_batch = [];
        foreach ($current_batch as $account_id) {
            if (isset($status['accounts'][$account_id]) && $status['accounts'][$account_id]['status'] === 'processing') {
                // Проверяем сколько времени счет в обработке
                $processing_time = time() - $status['accounts'][$account_id]['start_time'];
                if ($processing_time > 300) { // 5 минут - сбрасываем зависший счет
                    $status['accounts'][$account_id]['status'] = 'pending';
                    $status['accounts'][$account_id]['message'] = 'Сброшен после зависания';
                    error_log("СБРОС ЗАВИСАНИЯ: Счет {$account_id} сброшен после {$processing_time} сек обработки");
                    $filtered_batch[] = $account_id;
                } else {
                    error_log("ЗАЩИТА ОТ ДУБЛИРОВАНИЯ: Пропуск счета {$account_id} - уже обрабатывается " . ($processing_time) . " сек");
                }
            } else {
                $filtered_batch[] = $account_id;
            }
        }
        
        // Используем отфильтрованный пакет
        $current_batch = $filtered_batch;

        error_log("Сформирован пакет: начало={$batch_start}, размер=" . count($current_batch) . " счетов");
        if (!empty($current_batch)) {
            error_log("ID счетов в пакете: " . implode(', ', $current_batch));
        }

        // Если порция пуста, проверяем завершена ли очередь
        if (empty($current_batch)) {
            error_log("ПАКЕТ ПУСТОЙ: Нет счетов для обработки");
            
            // Увеличиваем номер порции и проверяем есть ли еще счета
            $status['current_batch']++;
            update_option($status_option, $status);
            
            // Проверяем есть ли еще необработанные счета
            $remaining_accounts = 0;
            foreach ($status['accounts'] as $account_status) {
                if ($account_status['status'] === 'pending') {
                    $remaining_accounts++;
                }
            }
            
            if ($remaining_accounts > 0) {
                error_log("ПЕРЕХОД К СЛЕДУЮЩЕМУ ПАКЕТУ: Осталось {$remaining_accounts} необработанных счетов");
                // Планируем следующий пакет через 1 секунду
                wp_schedule_single_event(time() + 1, 'process_accounts_update_batch', [$contest_id, $queue_id]);
                return false;
            } else {
                error_log("ЗАВЕРШЕНИЕ: Все счета обработаны");
                self::complete_queue($contest_id, $queue_id, $status_option, $queue_option);
                error_log("=== КОНЕЦ ОБРАБОТКИ (ЗАВЕРШЕНО) ===");
                return true;
            }
        }

        // Проверяем доступность функции process_trading_account
        if (!function_exists('process_trading_account')) {
            error_log("ПРОВЕРКА: Функция process_trading_account НЕ НАЙДЕНА, попытка загрузки API handler");
            
            // Проверим, загружен ли файл с API-обработчиком
            $api_handler_file = plugin_dir_path(__FILE__) . 'class-api-handler.php';
            if (file_exists($api_handler_file)) {
                require_once $api_handler_file;
                error_log("API handler файл загружен: {$api_handler_file}");
                
                if (!function_exists('process_trading_account')) {
                    error_log("КРИТИЧЕСКАЯ ОШИБКА: Функция process_trading_account ВСЕ ЕЩЕ недоступна после загрузки файла");
                    
                    // Отмечаем эту порцию как проблемную
                    foreach ($current_batch as $account_id) {
                        $status['accounts'][$account_id]['status'] = 'failed';
                        $status['accounts'][$account_id]['message'] = 'Ошибка: Функция обработки счетов недоступна';
                        $status['completed']++;
                        $status['failed']++;
                    }
                    $status['current_batch']++;
                    $status['last_update'] = time();
                    update_option($status_option, $status);
                    
                    // Планируем следующую порцию, если есть еще счета
                    if ($status['completed'] < $status['total']) {
                        wp_schedule_single_event(time() + 1, 'process_accounts_update_batch', [$contest_id, $queue_id]);
                        error_log("Запланирована следующая порция несмотря на ошибку функции");
                    } else {
                        self::complete_queue($contest_id, $queue_id, $status_option, $queue_option);
                        error_log("Очередь завершена из-за ошибки функции");
                    }
                    
                    error_log("=== КОНЕЦ ОБРАБОТКИ (ОШИБКА ФУНКЦИИ) ===");
                    return false;
                } else {
                    error_log("УСПЕХ: Функция process_trading_account найдена после загрузки");
                }
            } else {
                error_log("КРИТИЧЕСКАЯ ОШИБКА: API handler файл НЕ НАЙДЕН: {$api_handler_file}");
                
                // Отмечаем эту порцию как проблемную
                foreach ($current_batch as $account_id) {
                    $status['accounts'][$account_id]['status'] = 'failed';
                    $status['accounts'][$account_id]['message'] = 'Ошибка: API обработчик недоступен';
                    $status['completed']++;
                    $status['failed']++;
                }
                $status['current_batch']++;
                $status['last_update'] = time();
                update_option($status_option, $status);
                
                error_log("=== КОНЕЦ ОБРАБОТКИ (ОШИБКА ФАЙЛА API) ===");
                return false;
            }
        } else {
            error_log("ПРОВЕРКА: Функция process_trading_account ДОСТУПНА");
        }

        // Обновляем счета в порции
        $batch_size_actual = min($batch_size, count($current_batch));
        $current_batch_success_count = 0; // Счетчик успешных обновлений в текущем пакете
        $account_index = 0; // Счетчик обработанных счетов в пакете
        
        error_log("НАЧАЛО: Обработка пакета #{$status['current_batch']} ({$batch_size_actual} счетов)");
        
        // ВЫБОР РЕЖИМА ОБРАБОТКИ: параллельный или последовательный
        if ($processing_mode === 'batch') {
            // ПАРАЛЛЕЛЬНАЯ ОБРАБОТКА для batch режима
            error_log("РЕЖИМ: Параллельная обработка пакета из {$batch_size_actual} счетов");
            
            // Генерируем queue_batch_id для пакета
            if (!empty($queue_id)) {
                $queue_batch_id = $queue_id;
            } else {
                $letters = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
                $random_letters = '';
                for ($i = 0; $i < 4; $i++) {
                    $random_letters .= $letters[rand(0, strlen($letters) - 1)];
                }
                $queue_batch_id = 'b' . $random_letters;
            }
            
            // Помечаем все счета как обрабатываемые
            foreach ($current_batch as $account_id) {
                $status['accounts'][$account_id]['status'] = 'processing';
                $status['accounts'][$account_id]['start_time'] = time();
            }
            update_option($status_option, $status);
            
            // Выполняем параллельную обработку
            $parallel_results = self::process_accounts_parallel($current_batch, $queue_batch_id);
            
            // Обрабатываем результаты параллельной обработки
            foreach ($current_batch as $account_id) {
                $account_index++;
                
                if (isset($parallel_results[$account_id])) {
                    $result = $parallel_results[$account_id];
                    
                    error_log("ПАРАЛЛЕЛЬНЫЙ РЕЗУЛЬТАТ для счета {$account_id}: " . ($result['success'] ? 'SUCCESS' : 'FAILED') . " - " . $result['message']);
                    
                    // Обновляем статус счета
                    if ($result['success']) {
                        $status['accounts'][$account_id]['status'] = 'success';
                        $status['accounts'][$account_id]['message'] = $result['message'];
                        $status['success']++;
                        $current_batch_success_count++;
                    } else {
                        $status['accounts'][$account_id]['status'] = 'failed';
                        $status['accounts'][$account_id]['message'] = $result['message'];
                        $status['failed']++;
                    }
                    
                    $status['accounts'][$account_id]['end_time'] = time();
                    $status['completed']++;
                } else {
                    // Счет не был обработан
                    error_log("ОШИБКА: Счет {$account_id} не найден в результатах параллельной обработки");
                    $status['accounts'][$account_id]['status'] = 'failed';
                    $status['accounts'][$account_id]['message'] = 'Счет не был обработан';
                    $status['accounts'][$account_id]['end_time'] = time();
                    $status['failed']++;
                    $status['completed']++;
                }
            }
            
        } else {
            // ПОСЛЕДОВАТЕЛЬНАЯ ОБРАБОТКА для sequential режима
            error_log("РЕЖИМ: Последовательная обработка пакета из {$batch_size_actual} счетов");
            
            foreach ($current_batch as $account_id) {
                $account_index++;
                error_log("Обработка счета ID: {$account_id} ({$account_index}/{$batch_size_actual} в пакете)");
                
                // Помечаем счет как обрабатываемый
                $status['accounts'][$account_id]['status'] = 'processing';
                $status['accounts'][$account_id]['start_time'] = time();
                update_option($status_option, $status);

                try {
                    // Вызываем функцию обновления счета с передачей queue_batch_id
                    if (!empty($queue_id)) {
                        $queue_batch_id = $queue_id;
                    } else {
                        // Генерируем короткий queue_batch_id для пакетного обновления
                        $letters = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
                        $random_letters = '';
                        for ($i = 0; $i < 4; $i++) {
                            $random_letters .= $letters[rand(0, strlen($letters) - 1)];
                        }
                        $queue_batch_id = 'b' . $random_letters; // b означает batch update
                    }
                    
                    error_log("Вызов process_trading_account для счета {$account_id} с queue_batch_id: {$queue_batch_id}");
                    $result = process_trading_account([], $account_id, null, $queue_batch_id);
                    error_log("=== РЕЗУЛЬТАТ API ДЛЯ СЧЕТА {$account_id} ===");
                    error_log("SUCCESS: " . ($result['success'] ? 'TRUE' : 'FALSE'));
                    error_log("MESSAGE: " . ($result['message'] ?? 'НЕТ СООБЩЕНИЯ'));
                    error_log("ДАННЫЕ: " . print_r($result, true));
                    error_log("=== КОНЕЦ РЕЗУЛЬТАТА ===");
                    error_log("Результат обработки счета {$account_id}: " . ($result['success'] ? 'SUCCESS' : 'FAILED') . " - " . $result['message']);

                    // Получаем актуальный статус подключения из базы
                    $account_data = $wpdb->get_row($wpdb->prepare(
                        "SELECT connection_status, error_description, balance, equity, margin, leverage FROM {$wpdb->prefix}contest_members WHERE id = %d",
                        $account_id
                    ), ARRAY_A);

                    // Обновляем статус счета в очереди
                    if ($result['success']) {
                        $status['accounts'][$account_id]['status'] = 'success';
                        $status['accounts'][$account_id]['message'] = $result['message'];
                        $status['success']++;
                        $current_batch_success_count++;
                    } else {
                        $status['accounts'][$account_id]['status'] = 'failed';
                        $status['accounts'][$account_id]['message'] = $result['message'];
                        $status['failed']++;
                    }

                    $status['accounts'][$account_id]['end_time'] = time();
                    $status['completed']++;
                    $status['last_update'] = time();
                    update_option($status_option, $status);

                } catch (Exception $e) {
                    error_log("ИСКЛЮЧЕНИЕ при обработке счета {$account_id}: " . $e->getMessage());
                    $status['accounts'][$account_id]['status'] = 'failed';
                    $status['accounts'][$account_id]['message'] = 'Ошибка: ' . $e->getMessage();
                    $status['accounts'][$account_id]['end_time'] = time();
                    $status['failed']++;
                    $status['completed']++;
                    $status['last_update'] = time();
                    update_option($status_option, $status);
                }
            }
        }

        // Обновляем общий статус очереди
        $status['last_update'] = time();
        update_option($status_option, $status);

        error_log("ЗАВЕРШЕНО: Обработка пакета #{$status['current_batch']} ({$batch_size_actual} счетов). Статистика: завершено={$status['completed']}, успешно={$status['success']}, ошибок={$status['failed']}");

        // Увеличиваем номер порции
        $status['current_batch']++;
        update_option($status_option, $status);

        // Планируем следующую порцию немедленно (с минимальной задержкой)
        if ($status['completed'] < $status['total']) {
            $remaining_accounts = $status['total'] - $status['completed'];
            $remaining_batches = ceil($remaining_accounts / $batch_size);
            error_log("ПЛАНИРОВАНИЕ: Есть еще счета для обработки ({$status['completed']}/{$status['total']}). Осталось: {$remaining_accounts} счетов в ~{$remaining_batches} пакетах");
            
            // Определяем задержку в зависимости от режима обработки
            if ($processing_mode === 'batch') {
                // Batch режим: запуск сразу после завершения предыдущего пакета
                error_log("BATCH РЕЖИМ: Запуск следующего пакета НЕМЕДЛЕННО после завершения предыдущего");
                // Запускаем следующий пакет сразу без задержки
                return self::process_batch($contest_id, $queue_id);
            } else {
                // Sequential режим: стандартная задержка 1 секунда
                $delay = 1;
                error_log("SEQUENTIAL РЕЖИМ: Следующий пакет запланирован через {$delay} сек");
            }
            
            // Планирование только для sequential режима (для batch уже выполнили return выше)
            if ($processing_mode === 'sequential') {
                // Проверяем, не запланирована ли уже задача для этой очереди
                $existing_task = wp_next_scheduled('process_accounts_update_batch', [$contest_id, $queue_id]);
                error_log("ПЛАНИРОВАНИЕ_DEBUG: Проверка существующих задач для очереди {$queue_id}. Найдено: " . ($existing_task ? date('Y-m-d H:i:s', $existing_task) : 'нет'));
                if ($existing_task) {
                    // Проверяем, не просрочена ли задача
                    if ($existing_task > time()) {
                        error_log("ПРЕДУПРЕЖДЕНИЕ: Задача для очереди {$queue_id} уже запланирована на " . date('Y-m-d H:i:s', $existing_task) . ". Пропускаем планирование.");
                        return true;
                    } else {
                        // Удаляем просроченную задачу
                        wp_unschedule_event($existing_task, 'process_accounts_update_batch', [$contest_id, $queue_id]);
                        error_log("ПЛАНИРОВАНИЕ_DEBUG: Удалена просроченная задача для очереди {$queue_id}");
                    }
                }
                
                error_log("ПЛАНИРОВАНИЕ_DEBUG: Попытка запланировать задачу для очереди {$queue_id} через {$delay} сек");
                $scheduled = wp_schedule_single_event(time() + $delay, 'process_accounts_update_batch', [$contest_id, $queue_id]);
                error_log("ПЛАНИРОВАНИЕ_DEBUG: Результат wp_schedule_single_event: " . ($scheduled ? 'SUCCESS' : 'FAILED'));
                
                // Если планирование не удалось, обрабатываем следующую порцию немедленно
                if (!$scheduled) {
                    // Логируем ошибку планирования и не запускаем рекурсивно
                    error_log(sprintf('ОШИБКА ПЛАНИРОВАНИЯ: WP-Cron не смог запланировать следующий пакет для очереди %s (contest %s). Remaining accounts will not be processed automatically.', $queue_id, $contest_id));
                    // Возможно, пометить оставшиеся счета как failed или error_scheduling
                    // ... добавить логику пометки счетов, если необходимо
                    // return self::process_batch($contest_id, $queue_id);
                } else {
                    $next_batch_number = $status['current_batch'] + 1;
                    error_log("УСПЕХ: Следующий пакет #{$next_batch_number} запланирован на " . date('Y-m-d H:i:s', time() + $delay));
                    
                    // Явный вызов spawn_cron для запуска WP Cron
                    spawn_cron();
                    
                    error_log("Следующий пакет #{$next_batch_number} очереди {$queue_id} запланирован через {$delay} сек");
                }
            }
        } else {
            // Все счета обработаны, завершаем процесс
            error_log("ВСЕ СЧЕТА ОБРАБОТАНЫ: Завершение очереди {$queue_id}");
            self::complete_queue($contest_id, $queue_id, $status_option, $queue_option);
        }
        
        error_log("=== КОНЕЦ ОБРАБОТКИ ПАКЕТА (УСПЕХ) ===");
        return true;
    }

    /**
     * Завершает процесс обновления очереди
     * 
     * @param int|null $contest_id ID конкурса
     * @param string|null $queue_id ID очереди
     * @param string $status_option Имя опции статуса
     * @param string $queue_option Имя опции очереди
     */
    public static function complete_queue($contest_id = null, $queue_id = null, $status_option = '', $queue_option = '')
    {
        // Если опции не переданы и queue_id не передан, используем старый формат (для обратной совместимости)
        if (empty($status_option) || empty($queue_option)) {
            if (empty($queue_id)) {
                $queue_option = self::QUEUE_OPTION_PREFIX . ($contest_id ? $contest_id : 'global');
                $status_option = self::STATUS_OPTION_PREFIX . ($contest_id ? $contest_id : 'global');
            } else {
                $contest_prefix = $contest_id ? $contest_id : 'global';
                $status_option = self::STATUS_OPTION_PREFIX . $contest_prefix . '_' . $queue_id;
                $queue_option = self::QUEUE_OPTION_PREFIX . $contest_prefix . '_' . $queue_id;
            }
        }
        
        $status = get_option($status_option, []);

        if (!empty($status)) {
            $status['is_running'] = false;
            $status['end_time'] = time();
            update_option($status_option, $status);

            // Сохраняем историю обновлений
            $update_history = get_option('contest_accounts_update_history', []);

            // Ограничиваем размер истории до 50 записей
            if (count($update_history) >= 50) {
                $update_history = array_slice($update_history, -49);
            }

            // Добавляем текущее обновление в историю
            $update_history[] = [
                'start_time' => $status['start_time'],
                'end_time' => $status['end_time'],
                'total' => $status['total'],
                'success' => $status['success'],
                'failed' => $status['failed'],
                'contest_id' => $contest_id,
                'queue_id' => $queue_id,
                'is_auto_update' => isset($status['is_auto_update']) ? $status['is_auto_update'] : false
            ];

            update_option('contest_accounts_update_history', $update_history);
            
            // Удаляем очередь из списка активных
            if (!empty($queue_id)) {
                self::unregister_active_queue($contest_id, $queue_id);
            }
        }

        // Очищаем очередь
        delete_option($queue_option);
    }

    /**
     * Получает текущий статус обновления
     *
     * @param int|null $contest_id ID конкурса
     * @param string|null $queue_id ID очереди (если нужен статус конкретной очереди)
     * @return array Информация о текущем статусе
     */
    public static function get_status($contest_id = null, $queue_id = null)
    {
        // Если указан конкретный queue_id, возвращаем статус только этой очереди
        if (!empty($queue_id)) {
            $contest_prefix = $contest_id ? $contest_id : 'global';
            $status_option = self::STATUS_OPTION_PREFIX . $contest_prefix . '_' . $queue_id;
            
            $status = get_option($status_option, []);
            
            if (empty($status)) {
                return [
                    'is_running' => false,
                    'message' => 'Очередь не найдена',
                    'contest_id' => $contest_id,
                    'queue_id' => $queue_id
                ];
            }
            
            // Проверяем таймаут для конкретной очереди
            $timeout = self::get_queue_timeout(); // Получаем из настроек
            if ($status['is_running'] && (time() - $status['last_update']) > $timeout) {
                $status['is_running'] = false;
                $timeout_duration = time() - $status['last_update'];
                $status['message'] = sprintf('Процесс был прерван из-за тайм-аута (%d мин)', round($timeout_duration / 60));
                $status['timeout'] = true;
                $status['timeout_reason'] = self::determine_timeout_reason($status, $timeout_duration);
                update_option($status_option, $status);
            }
            
            // Убедимся, что contest_id и queue_id всегда возвращаются в ответе
            if (!isset($status['contest_id'])) {
                $status['contest_id'] = $contest_id;
            }
            if (!isset($status['queue_id'])) {
                $status['queue_id'] = $queue_id;
            }
            
            return $status;
        }
        
        // Проверяем все активные очереди для конкурса
        $contest_key = 'contest_active_queues_' . ($contest_id ? $contest_id : 'global');
        $active_queues = get_option($contest_key, []);
        
        if (empty($active_queues)) {
            // Для обратной совместимости проверяем старый формат
            $old_status_option = self::STATUS_OPTION_PREFIX . ($contest_id ? $contest_id : 'global');
            $old_status = get_option($old_status_option, []);
            
            if (!empty($old_status) && isset($old_status['is_running']) && $old_status['is_running']) {
                // Проверяем таймаут
                $timeout = self::get_queue_timeout(); // Получаем из настроек
                if ((time() - $old_status['last_update']) > $timeout) {
                    $old_status['is_running'] = false;
                    $timeout_duration = time() - $old_status['last_update'];
                    $old_status['message'] = sprintf('Процесс был прерван из-за тайм-аута (%d мин)', round($timeout_duration / 60));
                    $old_status['timeout'] = true;
                    $old_status['timeout_reason'] = self::determine_timeout_reason($old_status, $timeout_duration);
                    update_option($old_status_option, $old_status);
                }
                
                // Убедимся, что contest_id всегда возвращается в ответе
                if (!isset($old_status['contest_id'])) {
                    $old_status['contest_id'] = $contest_id;
                }
                
                return $old_status;
            }
            
            return [
                'is_running' => false,
                'message' => 'Нет активных процессов обновления',
                'contest_id' => $contest_id,
                'queues' => []
            ];
        }
        
        // Собираем информацию о всех активных очередях
        $queues = [];
        $total_accounts = 0;
        $completed_accounts = 0;
        $any_running = false;
        
        foreach ($active_queues as $q_id => $queue_info) {
            $status_option = $queue_info['status_option'];
            $queue_status = get_option($status_option, []);
            
            if (empty($queue_status)) {
                // Если статус очереди не найден, удаляем её из списка активных
                unset($active_queues[$q_id]);
                continue;
            }
            
            // Проверяем таймаут для очереди
            $timeout = self::get_queue_timeout(); // Получаем из настроек
            if ($queue_status['is_running'] && (time() - $queue_status['last_update']) > $timeout) {
                $queue_status['is_running'] = false;
                $timeout_duration = time() - $queue_status['last_update'];
                $queue_status['message'] = sprintf('Процесс был прерван из-за тайм-аута (%d мин)', round($timeout_duration / 60));
                $queue_status['timeout'] = true;
                $queue_status['timeout_reason'] = self::determine_timeout_reason($queue_status, $timeout_duration);
                update_option($status_option, $queue_status);
            }
            
            // Добавляем информацию о queue_id
            $queue_status['queue_id'] = $q_id;
            
            // Обновляем суммарную статистику
            if ($queue_status['is_running']) {
                $any_running = true;
            }
            
            $total_accounts += isset($queue_status['total']) ? $queue_status['total'] : 0;
            $completed_accounts += isset($queue_status['completed']) ? $queue_status['completed'] : 0;
            
            // Добавляем в список очередей
            $queues[$q_id] = $queue_status;
        }
        
        // Обновляем список активных очередей (если были удалены очереди без статуса)
        if (count($active_queues) !== count($queues)) {
            update_option($contest_key, $active_queues);
        }
        
        // Формируем итоговый статус
        $result = [
            'is_running' => $any_running,
            'message' => $any_running ? 'Процесс обновления выполняется' : 'Нет активных процессов обновления',
            'contest_id' => $contest_id,
            'total' => $total_accounts,
            'completed' => $completed_accounts,
            'progress' => $total_accounts > 0 ? round(($completed_accounts / $total_accounts) * 100) : 0,
            'queues' => $queues,
            'queues_count' => count($queues)
        ];
        
        return $result;
    }

    /**
     * Определяет причину таймаута на основе данных очереди
     * 
     * @param array $status Статус очереди
     * @param int $timeout_duration Длительность таймаута в секундах
     * @return string Описание причины таймаута
     */
    private static function determine_timeout_reason($status, $timeout_duration)
    {
        $reasons = [];
        
        // Анализируем статистику обработки
        $progress = isset($status['completed'], $status['total']) && $status['total'] > 0 
            ? ($status['completed'] / $status['total']) * 100 
            : 0;
            
        if ($progress == 0) {
            $reasons[] = 'Очередь не начала обработку';
        } elseif ($progress < 10) {
            $reasons[] = 'Остановилась в начале обработки';
        } elseif ($progress < 90) {
            $reasons[] = 'Остановилась в середине обработки';
        } else {
            $reasons[] = 'Остановилась почти в конце';
        }
        
        // Анализируем продолжительность
        $queue_timeout = self::get_queue_timeout();
        if ($timeout_duration > $queue_timeout) { // Более настроенного таймаута
            $reasons[] = 'Длительная неактивность (возможны проблемы с WP Cron)';
        } elseif ($timeout_duration > 10 * 60) { // Более 10 минут
            $reasons[] = 'Средняя задержка (возможны проблемы с API)';
        } else {
            $reasons[] = 'Короткая задержка';
        }
        
        // Проверяем количество неудачных счетов
        $failed_ratio = isset($status['failed'], $status['completed']) && $status['completed'] > 0
            ? ($status['failed'] / $status['completed']) * 100
            : 0;
            
        if ($failed_ratio > 50) {
            $reasons[] = 'Высокий процент ошибок';
        } elseif ($failed_ratio > 20) {
            $reasons[] = 'Средний процент ошибок';
        }
        
        // Проверяем тип обновления
        if (isset($status['is_auto_update']) && $status['is_auto_update']) {
            $reasons[] = 'Автоматическое обновление';
        } else {
            $reasons[] = 'Ручное обновление';
        }
        
        return implode(', ', $reasons);
    }

    /**
     * Получает информацию о всех активных очередях обновления для всех конкурсов
     * 
     * @return array Массив с информацией о всех активных очередях
     */
    public static function get_all_active_queues()
    {
        global $wpdb;
        
        $all_queues = [];
        $total_running = 0;
        $processed_queues = []; // Отслеживаем обработанные очереди для предотвращения дублирования
        
        // Получаем все опции с активными очередями
        // Ищем как в списках активных очередей, так и напрямую в статусах
        $active_queue_lists = $wpdb->get_results(
            "SELECT option_name, option_value FROM $wpdb->options 
             WHERE option_name LIKE 'contest_active_queues_%' 
             OR option_name LIKE 'contest_accounts_update_status_%'"
        );
        
        if (empty($active_queue_lists)) {
            return [
                'queues' => [],
                'total_running' => 0,
                'contests' => []
            ];
        }
        
        foreach ($active_queue_lists as $option) {
            // Определяем тип опции
            if (strpos($option->option_name, 'contest_active_queues_') === 0) {
                // Это список активных очередей
                $contest_prefix = str_replace('contest_active_queues_', '', $option->option_name);
                $contest_id = $contest_prefix === 'global' ? null : intval($contest_prefix);
                $active_queues = maybe_unserialize($option->option_value);
            } elseif (strpos($option->option_name, 'contest_accounts_update_status_') === 0) {
                // Это прямой статус очереди - обрабатываем напрямую
                $queue_status = maybe_unserialize($option->option_value);
                if (!is_array($queue_status) || !isset($queue_status['queue_id'])) {
                    continue;
                }
                
                // Извлекаем contest_id из статуса очереди
                $contest_id = isset($queue_status['contest_id']) ? intval($queue_status['contest_id']) : null;
                $queue_id = $queue_status['queue_id'];
                
                // Проверяем уникальность очереди
                $queue_key = $contest_id . '_' . $queue_id;
                if (isset($processed_queues[$queue_key])) {
                    continue; // Пропускаем уже обработанную очередь
                }
                $processed_queues[$queue_key] = true;
                
                // Проверяем таймаут
                $timeout = self::get_queue_timeout(); // Получаем из настроек
                if (isset($queue_status['is_running']) && $queue_status['is_running'] && 
                    isset($queue_status['last_update']) && (time() - $queue_status['last_update']) > $timeout) {
                    $queue_status['is_running'] = false;
                    $timeout_duration = time() - $queue_status['last_update'];
                    $queue_status['message'] = sprintf('Процесс был прерван из-за тайм-аута (%d мин)', round($timeout_duration / 60));
                    $queue_status['timeout'] = true;
                    $queue_status['timeout_reason'] = self::determine_timeout_reason($queue_status, $timeout_duration);
                    update_option($option->option_name, $queue_status);
                }
                
                // Получаем подробную информацию о счетах в очереди
                if (isset($queue_status['accounts']) && is_array($queue_status['accounts'])) {
                    $accounts_details = [];
                    foreach ($queue_status['accounts'] as $account_id => $account_status) {
                        // Получаем информацию о счете из БД
                        $account_info = $wpdb->get_row($wpdb->prepare(
                            "SELECT account_number, name, broker, platform 
                             FROM {$wpdb->prefix}contest_members 
                             WHERE id = %d",
                            $account_id
                        ), ARRAY_A);
                        
                        if ($account_info) {
                            $accounts_details[$account_id] = array_merge($account_status, [
                                'account_number' => $account_info['account_number'],
                                'trader_name' => $account_info['name'],
                                'broker_name' => $account_info['broker'] ?: 'Не указан',
                                'platform_name' => $account_info['platform'] ?: 'Не указана'
                            ]);
                        } else {
                            // Если счет не найден в БД
                            $accounts_details[$account_id] = array_merge($account_status, [
                                'account_number' => 'Неизвестный счет #' . $account_id,
                                'trader_name' => '',
                                'broker_name' => '',
                                'platform_name' => ''
                            ]);
                        }
                    }
                    $queue_status['accounts_details'] = $accounts_details;
                }
                
                // Получаем название конкурса
                $contest_title = 'Неизвестный конкурс';
                if ($contest_id) {
                    $contest_post = get_post($contest_id);
                    if ($contest_post) {
                        $contest_title = $contest_post->post_title;
                    }
                } else {
                    $contest_title = 'Глобальные очереди';
                }
                
                // Добавляем прямо в результат
                $all_queues[] = [
                    'contest_id' => $contest_id,
                    'contest_title' => $contest_title,
                    'queues' => [$queue_status],
                    'running_queues' => (isset($queue_status['is_running']) && $queue_status['is_running']) ? 1 : 0,
                    'total_queues' => 1
                ];
                
                if (isset($queue_status['is_running']) && $queue_status['is_running']) {
                    $total_running++;
                }
                
                continue; // Переходим к следующей опции
            } else {
                continue;
            }
            
            if (!empty($active_queues) && is_array($active_queues)) {
                $contest_info = [];
                $contest_running_queues = 0;
                
                foreach ($active_queues as $queue_id => $queue_info) {
                    // Проверяем уникальность очереди
                    $queue_key = $contest_id . '_' . $queue_id;
                    if (isset($processed_queues[$queue_key])) {
                        continue; // Пропускаем уже обработанную очередь
                    }
                    $processed_queues[$queue_key] = true;
                    
                    if (isset($queue_info['status_option'])) {
                        $status_option = $queue_info['status_option'];
                        $queue_status = get_option($status_option, []);
                        
                        if (!empty($queue_status)) {
                            // Проверяем таймаут
                            $timeout = self::get_queue_timeout(); // Получаем из настроек
                            if ($queue_status['is_running'] && (time() - $queue_status['last_update']) > $timeout) {
                                $queue_status['is_running'] = false;
                                $timeout_duration = time() - $queue_status['last_update'];
                                $queue_status['message'] = sprintf('Процесс был прерван из-за тайм-аута (%d мин)', round($timeout_duration / 60));
                                $queue_status['timeout'] = true;
                                $queue_status['timeout_reason'] = self::determine_timeout_reason($queue_status, $timeout_duration);
                                update_option($status_option, $queue_status);
                            }
                            
                            $queue_status['queue_id'] = $queue_id;
                            $queue_status['contest_id'] = $contest_id;
                            $queue_status['start_time_from_list'] = $queue_info['start_time'];
                            
                            // Получаем подробную информацию о счетах в очереди
                            if (isset($queue_status['accounts']) && is_array($queue_status['accounts'])) {
                                $accounts_details = [];
                                foreach ($queue_status['accounts'] as $account_id => $account_status) {
                                    // Получаем информацию о счете из БД
                                    $account_info = $wpdb->get_row($wpdb->prepare(
                                        "SELECT account_number, name, broker, platform 
                                         FROM {$wpdb->prefix}contest_members 
                                         WHERE id = %d",
                                        $account_id
                                    ), ARRAY_A);
                                    
                                    if ($account_info) {
                                        $accounts_details[$account_id] = array_merge($account_status, [
                                            'account_number' => $account_info['account_number'],
                                            'trader_name' => $account_info['name'],
                                            'broker_name' => $account_info['broker'] ?: 'Не указан',
                                            'platform_name' => $account_info['platform'] ?: 'Не указана'
                                        ]);
                                    } else {
                                        // Если счет не найден в БД
                                        $accounts_details[$account_id] = array_merge($account_status, [
                                            'account_number' => 'Неизвестный счет #' . $account_id,
                                            'trader_name' => '',
                                            'broker_name' => '',
                                            'platform_name' => ''
                                        ]);
                                    }
                                }
                                $queue_status['accounts_details'] = $accounts_details;
                            }
                            
                            $contest_info[] = $queue_status;
                            
                            if ($queue_status['is_running']) {
                                $contest_running_queues++;
                                $total_running++;
                            }
                        }
                    }
                }
                
                if (!empty($contest_info)) {
                    // Получаем название конкурса
                    $contest_title = 'Неизвестный конкурс';
                    if ($contest_id) {
                        $contest_post = get_post($contest_id);
                        if ($contest_post) {
                            $contest_title = $contest_post->post_title;
                        }
                    } else {
                        $contest_title = 'Глобальные очереди';
                    }
                    
                    $all_queues[] = [
                        'contest_id' => $contest_id,
                        'contest_title' => $contest_title,
                        'queues' => $contest_info,
                        'running_queues' => $contest_running_queues,
                        'total_queues' => count($contest_info)
                    ];
                }
            }
        }
        
        return [
            'queues' => $all_queues,
            'total_running' => $total_running,
            'contests' => count($all_queues)
        ];
    }

    /**
     * Запускает автоматическое обновление счетов для всех активных конкурсов
     */
    public static function run_auto_update()
    {
        global $wpdb;

        // Получаем время последнего автообновления
        $last_run = get_option(self::AUTO_UPDATE_LAST_RUN, 0);
        $now = time();

        // Получаем настройки автообновления
        $settings = get_option('fttrader_auto_update_settings', []);
        $interval = isset($settings['fttrader_auto_update_interval']) ?
            intval($settings['fttrader_auto_update_interval']) : 60; // По умолчанию 60 минут
        // Интервал обновления дисквалифицированных счетов (секунды)
        $disq_minutes = isset($settings['fttrader_disq_accounts_interval']) ? intval($settings['fttrader_disq_accounts_interval']) : 1440;
        $disq_interval_sec = $disq_minutes * 60;

        // Проверяем, прошло ли достаточно времени с последнего обновления
        // Пропускаем эту проверку, если установлен флаг принудительного запуска
        if (!isset($GLOBALS['force_auto_update_flag']) && ($now - $last_run) < ($interval * 60)) {
            return;
        }

        // Обновляем время последнего запуска
        update_option(self::AUTO_UPDATE_LAST_RUN, $now);

        // Выбираем активные конкурсы и группируем счета по конкурсам
        $table_name = $wpdb->prefix . 'contest_members';
        
        // Получаем ID активных конкурсов (со статусом publish)
        $contest_posts = $wpdb->get_results(
            "SELECT ID FROM $wpdb->posts 
             WHERE post_type = 'trader_contests' 
             AND post_status = 'publish'"
        );

        if (empty($contest_posts)) {
            return;
        }

        // Дополнительно проверяем статус конкурса в метаданных
        $active_contests = [];
        foreach ($contest_posts as $contest_post) {
            $contest_data = get_post_meta($contest_post->ID, '_fttradingapi_contest_data', true);
            
            // Проверяем, распарсились ли данные и активен ли конкурс
            if (!empty($contest_data) && is_array($contest_data) && 
                isset($contest_data['contest_status']) && $contest_data['contest_status'] === 'active') {
                $active_contests[] = $contest_post->ID;
            }
        }

        if (empty($active_contests)) {
            return;
        }

        // Проверяем наличие флага принудительного запуска через глобальную переменную
        $is_forced_update = isset($GLOBALS['force_auto_update_flag']) && $GLOBALS['force_auto_update_flag'] === true;

        // Для каждого активного конкурса создаем отдельную очередь обновления
        foreach ($active_contests as $contest_id) {
            // ПРОВЕРЯЕМ КОЛЛИЗИИ: Если есть запущенные очереди для этого конкурса, пропускаем
            $existing_status = self::get_status($contest_id);
            if ($existing_status['is_running']) {
                error_log("Автоматическое обновление пропущено для конкурса {$contest_id}: есть запущенная очередь");
                continue;
            }
            
            // Получаем активные счета данного конкурса
            $contest_accounts = $wpdb->get_col($wpdb->prepare(
                "SELECT id FROM $table_name WHERE contest_id = %d AND connection_status != 'disqualified'",
                $contest_id
            ));

            // Также получаем дисквалифицированные счета, которые не обновлялись более суток
            $stale_disqualified = $wpdb->get_col($wpdb->prepare(
                "SELECT id FROM $table_name WHERE contest_id = %d AND connection_status = 'disqualified' AND (last_update_time IS NULL OR last_update_time < %d)",
                $contest_id,
                $now - $disq_interval_sec
            ));

            $all_accounts = array_merge($contest_accounts, $stale_disqualified);

            if (!empty($all_accounts)) {
                // Инициализируем очередь обновления с явно установленным флагом is_auto_update
                error_log("Автоматическое обновление: создана очередь для конкурса {$contest_id} с " . count($all_accounts) . " счетами");
                self::init_queue($all_accounts, true, $contest_id);
            }
        }
        
        // Сбрасываем флаг после использования
        if ($is_forced_update) {
            unset($GLOBALS['force_auto_update_flag']);
        }
    }

    /**
     * Очищает все зависшие очереди обновления
     * 
     * @return array Результат операции с информацией об очищенных очередях
     */
    public static function clear_all_queues()
    {
        global $wpdb;
        
        $result = [
            'success' => true,
            'cleared_queues' => [],
            'cleared_status_options' => [],
            'cleared_queue_options' => [],
            'cleared_lists' => [],
            'message' => 'Все зависшие очереди очищены'
        ];
        
        // 1. Получаем все опции, начинающиеся с contest_active_queues_
        $active_queue_lists = $wpdb->get_results(
            "SELECT option_name, option_value FROM $wpdb->options 
             WHERE option_name LIKE 'contest_active_queues_%'"
        );
        
        if (!empty($active_queue_lists)) {
            foreach ($active_queue_lists as $option) {
                $active_queues = maybe_unserialize($option->option_value);
                
                if (!empty($active_queues) && is_array($active_queues)) {
                    // Для каждой активной очереди получаем соответствующие опции статуса и данных
                    foreach ($active_queues as $queue_id => $queue_info) {
                        // Получаем имя опции статуса из информации о очереди
                        if (isset($queue_info['status_option'])) {
                            $status_option = $queue_info['status_option'];
                            delete_option($status_option);
                            $result['cleared_status_options'][] = $status_option;
                            
                            // Вычисляем имя опции с данными очереди на основе имени опции статуса
                            // Обычно это замена STATUS на QUEUE в имени опции
                            $queue_option = str_replace(
                                self::STATUS_OPTION_PREFIX, 
                                self::QUEUE_OPTION_PREFIX, 
                                $status_option
                            );
                            delete_option($queue_option);
                            $result['cleared_queue_options'][] = $queue_option;
                            
                            $result['cleared_queues'][] = [
                                'queue_id' => $queue_id,
                                'status_option' => $status_option,
                                'queue_option' => $queue_option
                            ];
                        }
                    }
                    
                    // Очищаем список активных очередей
                    delete_option($option->option_name);
                    $result['cleared_lists'][] = $option->option_name;
                }
            }
        } else {
            $result['message'] = 'Активные списки очередей не найдены';
        }
        
        // 2. Дополнительно ищем и очищаем старые/потерянные опции статусов и очередей
        $status_options = $wpdb->get_col(
            "SELECT option_name FROM $wpdb->options 
             WHERE option_name LIKE '". self::STATUS_OPTION_PREFIX ."%'"
        );
        
        foreach ($status_options as $status_option) {
            if (!in_array($status_option, $result['cleared_status_options'])) {
                delete_option($status_option);
                $result['cleared_status_options'][] = $status_option;
                
                // Также удаляем соответствующую опцию очереди
                $queue_option = str_replace(
                    self::STATUS_OPTION_PREFIX, 
                    self::QUEUE_OPTION_PREFIX, 
                    $status_option
                );
                delete_option($queue_option);
                $result['cleared_queue_options'][] = $queue_option;
            }
        }
        
        return $result;
    }

    /**
     * Настраивает расписание автоматического обновления счетов
     * 
     * @return bool Успешно ли установлено расписание
     */
    public static function setup_auto_update_schedule()
    {
        // Получаем настройки автообновления
        $settings = get_option('fttrader_auto_update_settings', []);
        $enabled = isset($settings['fttrader_auto_update_enabled']) ? $settings['fttrader_auto_update_enabled'] : false;
        
        // Удаляем существующее расписание
        $timestamp = wp_next_scheduled('contest_create_queues');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'contest_create_queues');
        }
        
        // Если автообновление отключено, выходим
        if (!$enabled) {
            return false;
        }
        
        // Получаем интервал
        $interval = isset($settings['fttrader_auto_update_interval']) ? 
            intval($settings['fttrader_auto_update_interval']) : 60; // По умолчанию 60 минут
        
        // Проверяем/регистрируем кастомный интервал
        if (!wp_get_schedule('contest_create_queues')) {
            // Используем стандартный интервал или кастомный
            $schedule = 'hourly'; // По умолчанию
            
            if ($interval == 60) {
                $schedule = 'hourly';
            } elseif ($interval == 1440) {
                $schedule = 'daily';
            } else {
                // Проверяем, зарегистрирован ли наш кастомный интервал
                if (!wp_get_schedules()['contest_auto_update']) {
                    // Регистрируем кастомный интервал
                    add_filter('cron_schedules', function($schedules) use ($interval) {
                        $schedules['contest_auto_update'] = [
                            'interval' => $interval * 60,
                            'display' => sprintf('Каждые %d минут', $interval)
                        ];
                        return $schedules;
                    });
                }
                $schedule = 'contest_auto_update';
            }
            
            // Планируем событие
            // Получаем время последнего запуска
            $last_run = get_option(self::AUTO_UPDATE_LAST_RUN, 0);
            
            if ($last_run > 0) {
                // Планируем следующее событие от времени последнего запуска + интервал
                $first_run = $last_run + ($interval * 60);
                // Если это время уже в прошлом, планируем через интервал от текущего времени
                if ($first_run <= time()) {
                    $first_run = time() + ($interval * 60);
                }
            } else {
                // Если никогда не запускалось, планируем через интервал от текущего времени
                $first_run = time() + ($interval * 60);
            }
            
            $scheduled = wp_schedule_event($first_run, $schedule, 'contest_create_queues');
            
            // Принудительно запускаем WP Cron
            spawn_cron();
            
            return $scheduled !== false;
        }
        
        return true;
    }

    /**
     * Получает задержку для координации параллельных очередей
     * 
     * @return int Задержка в секундах
     */
    private static function get_parallel_processing_delay()
    {
        $all_queues = self::get_all_active_queues();
        $total_running = $all_queues['total_running'];
        
        if ($total_running <= 1) {
            return 0; // Нет параллельных очередей
        }
        
        // Случайная задержка от 0 до 3 секунд для распределения нагрузки
        return rand(0, min(3, $total_running - 1));
    }
    
    /**
     * Подсчитывает общее количество активных очередей во всех конкурсах
     * 
     * @return int Количество активных очередей
     */
    private static function count_all_active_queues()
    {
        $all_queues = self::get_all_active_queues();
        return $all_queues['total_running'];
    }
    
    /**
     * Вычисляет адаптивную задержку между пакетами при параллельной работе
     * 
     * @param int $active_queues_count Количество активных очередей
     * @param int $base_delay Базовая задержка в секундах
     * @return int Адаптивная задержка в секундах
     */
    private static function get_adaptive_delay($active_queues_count, $base_delay)
    {
        if ($active_queues_count <= 1) {
            return $base_delay;
        }
        
        // Увеличиваем задержку пропорционально количеству активных очередей
        // При 2 очередях: 5 * 1.5 = 7.5 сек
        // При 3 очередях: 5 * 2.0 = 10 сек  
        // При 4+ очередях: 5 * 2.5 = 12.5 сек
        $multiplier = 1 + (($active_queues_count - 1) * 0.5);
        $multiplier = min($multiplier, 2.5); // Максимум x2.5
        
        return intval($base_delay * $multiplier);
    }

    /**
     * Безопасно очищает старые очереди в таймауте
     * 
     * @param array $options Параметры очистки
     * @return array Результат операции
     */
    public static function cleanup_timeout_queues($options = [])
    {
        global $wpdb;
        
        // Параметры по умолчанию
        $defaults = [
            'older_than_hours' => 24,      // Старше 24 часов
            'min_progress' => 0,           // Минимальный прогресс (0 = любой)
            'max_progress' => 100,         // Максимальный прогресс
            'dry_run' => false,            // Тестовый режим (не удалять)
            'include_completed' => false   // Включить завершенные очереди
        ];
        
        $options = array_merge($defaults, $options);
        
        $result = [
            'success' => true,
            'analyzed_queues' => 0,
            'eligible_for_cleanup' => [],
            'cleaned_queues' => [],
            'preserved_queues' => [],
            'errors' => [],
            'summary' => '',
            'dry_run' => $options['dry_run']
        ];
        
        error_log("=== НАЧАЛО ОЧИСТКИ ТАЙМАУТОВ ===");
        error_log("Параметры: " . json_encode($options));
        
        try {
            // Получаем все активные очереди
            $all_queues = self::get_all_active_queues();
            $result['analyzed_queues'] = count($all_queues['queues']);
            
            if (empty($all_queues['queues'])) {
                $result['summary'] = 'Активных очередей не найдено';
                return $result;
            }
            
            $cutoff_time = time() - ($options['older_than_hours'] * 3600);
            
            foreach ($all_queues['queues'] as $contest_id => $contest_queues) {
                foreach ($contest_queues as $queue_id => $queue_data) {
                    
                    // Анализ очереди для принятия решения
                    $analysis = self::analyze_queue_for_cleanup($queue_data, $options, $cutoff_time);
                    
                    if ($analysis['eligible']) {
                        $result['eligible_for_cleanup'][] = [
                            'queue_id' => $queue_id,
                            'contest_id' => $contest_id,
                            'reason' => $analysis['reason'],
                            'age_hours' => $analysis['age_hours'],
                            'progress' => $analysis['progress'],
                            'status' => $queue_data['timeout'] ? 'timeout' : ($queue_data['is_running'] ? 'running' : 'stopped')
                        ];
                        
                        if (!$options['dry_run']) {
                            // Выполняем фактическую очистку
                            $cleanup_result = self::cleanup_single_queue($contest_id, $queue_id, $queue_data);
                            
                            if ($cleanup_result['success']) {
                                $result['cleaned_queues'][] = $cleanup_result;
                                error_log("✅ Очередь {$queue_id} очищена: {$analysis['reason']}");
                            } else {
                                $result['errors'][] = "Ошибка очистки {$queue_id}: " . $cleanup_result['error'];
                                error_log("❌ Ошибка очистки {$queue_id}: " . $cleanup_result['error']);
                            }
                        }
                    } else {
                        $result['preserved_queues'][] = [
                            'queue_id' => $queue_id,
                            'contest_id' => $contest_id,
                            'reason' => $analysis['preserve_reason'],
                            'age_hours' => $analysis['age_hours'],
                            'progress' => $analysis['progress']
                        ];
                    }
                }
            }
            
            // Формируем итоговую сводку
            $eligible_count = count($result['eligible_for_cleanup']);
            $cleaned_count = count($result['cleaned_queues']);
            $preserved_count = count($result['preserved_queues']);
            $errors_count = count($result['errors']);
            
            if ($options['dry_run']) {
                $result['summary'] = sprintf(
                    'Тестовый режим: найдено %d очередей для очистки, %d будут сохранены',
                    $eligible_count,
                    $preserved_count
                );
            } else {
                $result['summary'] = sprintf(
                    'Очищено: %d, сохранено: %d, ошибок: %d',
                    $cleaned_count,
                    $preserved_count,
                    $errors_count
                );
            }
            
            error_log("ИТОГИ ОЧИСТКИ: " . $result['summary']);
            
        } catch (Exception $e) {
            $result['success'] = false;
            $result['errors'][] = 'Критическая ошибка: ' . $e->getMessage();
            error_log("КРИТИЧЕСКАЯ ОШИБКА ОЧИСТКИ: " . $e->getMessage());
        }
        
        error_log("=== КОНЕЦ ОЧИСТКИ ТАЙМАУТОВ ===");
        return $result;
    }
    
    /**
     * Анализирует очередь для принятия решения об очистке
     * 
     * @param array $queue_data Данные очереди
     * @param array $options Параметры очистки
     * @param int $cutoff_time Время отсечки
     * @return array Результат анализа
     */
    private static function analyze_queue_for_cleanup($queue_data, $options, $cutoff_time)
    {
        $analysis = [
            'eligible' => false,
            'reason' => '',
            'preserve_reason' => '',
            'age_hours' => 0,
            'progress' => 0
        ];
        
        // Определяем возраст очереди
        $start_time = isset($queue_data['start_time_from_list']) ? $queue_data['start_time_from_list'] : 0;
        $last_update = isset($queue_data['last_update']) ? $queue_data['last_update'] : 0;
        $age_seconds = time() - max($start_time, $last_update);
        $analysis['age_hours'] = round($age_seconds / 3600, 1);
        
        // Определяем прогресс
        if (isset($queue_data['total']) && $queue_data['total'] > 0) {
            $analysis['progress'] = round(($queue_data['completed'] / $queue_data['total']) * 100, 1);
        }
        
        // Критерии сохранения (НЕ удалять если)
        if ($queue_data['is_running']) {
            $analysis['preserve_reason'] = 'Очередь активна';
            return $analysis;
        }
        
        if ($age_seconds < ($options['older_than_hours'] * 3600)) {
            $analysis['preserve_reason'] = sprintf('Слишком новая (%.1f ч)', $analysis['age_hours']);
            return $analysis;
        }
        
        if ($analysis['progress'] < $options['min_progress'] || $analysis['progress'] > $options['max_progress']) {
            $analysis['preserve_reason'] = sprintf('Прогресс %.1f%% вне диапазона', $analysis['progress']);
            return $analysis;
        }
        
        if (!$options['include_completed'] && $analysis['progress'] >= 99) {
            $analysis['preserve_reason'] = 'Почти завершена';
            return $analysis;
        }
        
        // Критерии удаления
        $analysis['eligible'] = true;
        
        if (isset($queue_data['timeout']) && $queue_data['timeout']) {
            $analysis['reason'] = sprintf('Таймаут %.1f ч назад (%.1f%%)', $analysis['age_hours'], $analysis['progress']);
        } elseif ($analysis['progress'] == 0) {
            $analysis['reason'] = sprintf('Не начиналась %.1f ч', $analysis['age_hours']);
        } elseif ($analysis['progress'] < 10) {
            $analysis['reason'] = sprintf('Застряла в начале %.1f ч (%.1f%%)', $analysis['age_hours'], $analysis['progress']);
        } else {
            $analysis['reason'] = sprintf('Старая неактивная %.1f ч (%.1f%%)', $analysis['age_hours'], $analysis['progress']);
        }
        
        return $analysis;
    }
    
    /**
     * Очищает одну очередь из системы
     * 
     * @param int|null $contest_id ID конкурса
     * @param string $queue_id ID очереди
     * @param array $queue_data Данные очереди
     * @return array Результат очистки
     */
    private static function cleanup_single_queue($contest_id, $queue_id, $queue_data)
    {
        try {
            $contest_prefix = $contest_id ? $contest_id : 'global';
            $status_option = 'contest_accounts_update_status_' . $contest_prefix . '_' . $queue_id;
            $queue_option = 'contest_accounts_update_queue_' . $contest_prefix . '_' . $queue_id;
            
            // Удаляем опции очереди
            $status_deleted = delete_option($status_option);
            $queue_deleted = delete_option($queue_option);
            
            // Удаляем из списка активных очередей
            $contest_key = 'contest_active_queues_' . ($contest_id ? $contest_id : 'global');
            $active_queues = get_option($contest_key, []);
            
            $removed_from_list = false;
            if (isset($active_queues[$queue_id])) {
                unset($active_queues[$queue_id]);
                update_option($contest_key, $active_queues);
                $removed_from_list = true;
            }
            
            return [
                'success' => true,
                'queue_id' => $queue_id,
                'contest_id' => $contest_id,
                'status_deleted' => $status_deleted,
                'queue_deleted' => $queue_deleted,
                'removed_from_list' => $removed_from_list,
                'accounts_count' => isset($queue_data['total']) ? $queue_data['total'] : 0
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'queue_id' => $queue_id,
                'contest_id' => $contest_id,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Параллельная обработка пакета счетов с использованием curl_multi
     * 
     * @param array $account_batch Массив ID счетов для параллельной обработки
     * @param string $queue_batch_id ID очереди для логирования
     * @return array Результаты обработки каждого счета
     */
    private static function process_accounts_parallel($account_batch, $queue_batch_id = null)
    {
        global $wpdb;
        
        if (empty($account_batch)) {
            return [];
        }
        
        error_log("ПАРАЛЛЕЛЬНАЯ ОБРАБОТКА: Начинаем обработку " . count($account_batch) . " счетов одновременно");
        
        // Подготавливаем данные для всех счетов в пакете
        $account_requests = [];
        $curl_handles = [];
        $multi_handle = curl_multi_init();
        
        foreach ($account_batch as $account_id) {
            $account = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}contest_members WHERE id = %d",
                $account_id
            ));
            
            if (!$account) {
                error_log("ОШИБКА: Счет ID {$account_id} не найден в БД");
                continue;
            }
            
            // Подготавливаем параметры запроса как в process_trading_account
            require_once plugin_dir_path(__FILE__) . 'class-api-config.php';
            $api_url = FT_API_Config::get_api_url();
            
            $params = [
                'action' => 'get_data',
                'account_number' => $account->account_number,
                'password' => $account->password,
                'server' => $account->server,
                'terminal' => $account->terminal,
                'last_history_time' => $account->last_history_time
            ];
            
            if ($queue_batch_id) {
                $params['queue_batch_id'] = $queue_batch_id;
            }
            
            $url = $api_url . '?' . http_build_query($params);
            
            // Создаем curl handle для каждого счета
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 35, // Немного больше чем обычный таймаут
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_USERAGENT => 'WordPress/' . get_bloginfo('version') . '; ' . home_url()
            ]);
            
            curl_multi_add_handle($multi_handle, $ch);
            
            $curl_handles[$account_id] = $ch;
            $account_requests[$account_id] = [
                'account' => $account,
                'url' => $url,
                'start_time' => microtime(true)
            ];
        }
        
                 // Выполняем все запросы параллельно с отслеживанием времени завершения
         $running = null;
         $completed_requests = [];
         
         do {
             curl_multi_exec($multi_handle, $running);
             
             // Проверяем завершенные запросы и записываем их время завершения
             while (($info = curl_multi_info_read($multi_handle)) !== false) {
                 if ($info['result'] === CURLE_OK) {
                     // Находим account_id для этого handle
                     foreach ($curl_handles as $account_id => $handle) {
                         if ($handle === $info['handle']) {
                             $completed_requests[$account_id] = microtime(true);
                             break;
                         }
                     }
                 }
             }
             
             curl_multi_select($multi_handle);
         } while ($running > 0);
         
         // Собираем результаты
         $results = [];
         foreach ($account_requests as $account_id => $request_data) {
             $ch = $curl_handles[$account_id];
             $response_body = curl_multi_getcontent($ch);
             $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
             $total_time = curl_getinfo($ch, CURLINFO_TOTAL_TIME);
             
             // Используем реальное время завершения если доступно, иначе текущее время
             $request_end_time = isset($completed_requests[$account_id]) ? 
                               $completed_requests[$account_id] : microtime(true);
             $duration_ms = round(($request_end_time - $request_data['start_time']) * 1000, 2);
            
                         // Логируем HTTP запрос (аналогично process_trading_account)
             $http_log_path = plugin_dir_path(__FILE__) . 'logs/http_requests.log';
             $request_id = 'req_' . uniqid();
             
             // Конвертируем микросекунды в читаемое время
             $start_time_readable = date('Y-m-d H:i:s', (int)$request_data['start_time']) . 
                                   '.' . str_pad((int)(($request_data['start_time'] - (int)$request_data['start_time']) * 1000), 3, '0', STR_PAD_LEFT);
             $end_time_readable = date('Y-m-d H:i:s', $request_end_time) . 
                                '.' . str_pad((int)(($request_end_time - (int)$request_end_time) * 1000), 3, '0', STR_PAD_LEFT);
             
             $log_entry = "============================================================\n";
             $log_entry .= "🌐 HTTP REQUEST START (PARALLEL)\n";
             $log_entry .= "   ID: " . $request_id . "\n";
             $log_entry .= "   START_TIME: " . $start_time_readable . "\n";
             $log_entry .= "   ACCOUNT: " . $request_data['account']->account_number . "\n";
             $log_entry .= "   SERVER: " . $request_data['account']->server . "\n";
             $log_entry .= "   URL: " . $request_data['url'] . "\n";
             $log_entry .= "   QUEUE: " . ($queue_batch_id ?: 'unknown') . "\n";
             $log_entry .= "   ------------------------------------------------------------\n";
             $log_entry .= "✅ HTTP REQUEST END (PARALLEL)\n";
             $log_entry .= "   ID: " . $request_id . "\n";
             $log_entry .= "   END_TIME: " . $end_time_readable . "\n";
             $log_entry .= "   DURATION: " . $duration_ms . "ms\n";
             $log_entry .= "   HTTP_CODE: " . $http_code . "\n";
             $log_entry .= "   RESPONSE_SIZE: " . strlen($response_body) . " bytes\n";
             $log_entry .= "============================================================\n";
            
            file_put_contents($http_log_path, $log_entry, FILE_APPEND | LOCK_EX);
            
            // Обрабатываем ответ (аналогично process_trading_account)
            $account_result = self::process_api_response(
                $account_id, 
                $request_data['account'], 
                $response_body, 
                $http_code
            );
            
            $results[$account_id] = $account_result;
            
            error_log("ПАРАЛЛЕЛЬНАЯ ОБРАБОТКА: Счет {$account_id} обработан за {$duration_ms}ms, HTTP {$http_code}");
            
            // Очищаем curl handle
            curl_multi_remove_handle($multi_handle, $ch);
            curl_close($ch);
        }
        
        curl_multi_close($multi_handle);
        
        error_log("ПАРАЛЛЕЛЬНАЯ ОБРАБОТКА: Завершена обработка " . count($results) . " счетов");
        
        return $results;
    }
    
    /**
     * Обработка ответа API сервера (выделено из process_trading_account)
     * 
     * @param int $account_id ID счета
     * @param object $account Данные счета из БД
     * @param string $response_body Тело ответа HTTP
     * @param int $http_code HTTP код ответа
     * @return array Результат обработки
     */
    private static function process_api_response($account_id, $account, $response_body, $http_code)
    {
        global $wpdb;
        
        // Проверяем HTTP код
        if ($http_code !== 200) {
            if ($http_code == 500) {
                return [
                    'success' => false,
                    'message' => 'Сервер API временно недоступен. На сервере идет обновление.'
                ];
            } elseif ($http_code >= 400 && $http_code < 500) {
                return [
                    'success' => false,
                    'message' => "Ошибка запроса к API (код {$http_code}). Проверьте данные для входа."
                ];
            } else {
                return [
                    'success' => false,
                    'message' => "Ошибка HTTP {$http_code}"
                ];
            }
        }
        
        // Проверяем пустой ответ
        if (empty($response_body)) {
            return [
                'success' => false,
                'message' => 'Сервер API вернул пустой ответ'
            ];
        }
        
        // Декодируем JSON
        $data = json_decode($response_body, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return [
                'success' => false,
                'message' => 'Получен некорректный ответ от сервера API: ' . json_last_error_msg()
            ];
        }
        
        // Проверяем наличие данных счета
        if (!isset($data['acc'])) {
            return [
                'success' => false,
                'message' => 'Ошибка в ответе API: отсутствуют данные счета'
            ];
        }
        
        // Проверяем статус подключения
        if (isset($data['acc']['connection_status']) && $data['acc']['connection_status'] === 'disconnected') {
            $error_message = isset($data['acc']['error_description']) && !empty($data['acc']['error_description']) 
                ? $data['acc']['error_description'] 
                : 'Не удалось подключиться к счёту. Проверьте логин, пароль и сервер.';
            
            // Обновляем только статус подключения
            $wpdb->update(
                $wpdb->prefix . 'contest_members',
                [
                    'connection_status' => 'disconnected',
                    'error_description' => $error_message,
                    'last_update' => current_time('mysql')
                ],
                ['id' => $account_id]
            );
            
            return [
                'success' => false,
                'message' => $error_message
            ];
        }
        
        // Маппинг полей API -> БД (из process_trading_account)
        $fields_map = [
            'balance' => ['acc', 'i_bal'],
            'equity' => ['acc', 'i_equi'], 
            'margin' => ['acc', 'i_marg'],
            'profit' => ['acc', 'i_prof'],
            'leverage' => ['acc', 'i_level'], // исправлено: используем i_level
            'currency' => ['acc', 'i_curr'],
            'orders_total' => ['acc', 'i_ordtotal'],
            'orders_history_total' => ['statistics', 'ACCOUNT_ORDERS_HISTORY_TOTAL'],
            'orders_history_profit' => ['statistics', 'ACCOUNT_ORDERS_HISTORY_PROFIT']
        ];
        
        // Подготавливаем данные для БД
        $db_data = [
            'connection_status' => 'connected',
            'error_description' => '',
            'last_update' => current_time('mysql')
        ];
        
        // Сохраняем старые данные для истории изменений
        $old_data = [
            'balance' => $account->balance,
            'equity' => $account->equity,
            'margin' => $account->margin,
            'profit' => $account->profit,
            'leverage' => $account->leverage,
            'orders_total' => $account->orders_total,
            'orders_history_total' => $account->orders_history_total,
            'password' => $account->password
        ];
        
        $new_data_for_history = ['connection_status' => 'connected'];
        
        // Обрабатываем поля
        foreach ($fields_map as $db_key => $path) {
            $section = $path[0];
            $key = $path[1];
            
            if (isset($data[$section][$key]) && $data[$section][$key] !== '' && $data[$section][$key] !== null) {
                $value = floatval($data[$section][$key]);
                $db_data[$db_key] = $value;
                $new_data_for_history[$db_key] = $value;
            }
        }
        
        // Рассчитываем процент прибыли
        if (isset($db_data['balance']) && $db_data['balance'] > 0 && isset($db_data['profit'])) {
            $db_data['profit_percent'] = round(($db_data['profit'] / $db_data['balance']) * 100, 2);
        }
        
        // Обновляем данные в БД
        $result = $wpdb->update(
            $wpdb->prefix . 'contest_members',
            $db_data,
            ['id' => $account_id]
        );
        
        if ($result === false) {
            return [
                'success' => false,
                'message' => 'Ошибка базы данных: ' . $wpdb->last_error
            ];
        }
        
        // Записываем изменения в историю
        require_once 'class-account-history.php';
        $history = new Account_History();
        $history->track_changes($account_id, $old_data, $new_data_for_history);
        
        // Обрабатываем ордера если есть
        if (isset($data['open_orders']) && is_array($data['open_orders'])) {
            require_once 'class-orders.php';
            $orders = new Account_Orders();
            try {
                $orders->update_orders($account_id, $data['open_orders']);
            } catch (Exception $e) {
                error_log('Error updating orders for account ' . $account_id . ': ' . $e->getMessage());
            }
        }
        
        // Обрабатываем историю сделок
        if (isset($data['order_history']) && is_array($data['order_history'])) {
            require_once 'class-orders.php';
            $orders = new Account_Orders();
            $orders->update_order_history($account_id, $data['order_history']);
        }
        
        return [
            'success' => true,
            'message' => 'Данные счета успешно обновлены'
        ];
    }
}

// Регистрируем хук для обработки порции обновлений
// Число 10 - приоритет, 2 - количество передаваемых аргументов (contest_id, queue_id)
add_action('process_accounts_update_batch', ['Account_Updater', 'process_batch'], 10, 2);

// Регистрируем хук для автоматического обновления
add_action('contest_create_queues', ['Account_Updater', 'run_auto_update']);

// Регистрируем хук для обновления расписания при изменении настроек
add_action('update_option_fttrader_auto_update_settings', function($old_value, $new_value) {
    // Вызываем настройку расписания при изменении настроек
    Account_Updater::setup_auto_update_schedule();
}, 10, 2);

/**
 * AJAX обработчик для очистки всех зависших очередей
 */
function fttradingapi_clear_all_queues() {
    // Проверка nonce для безопасности
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'ft_trader_nonce')) {
        wp_send_json_error(['message' => 'Ошибка безопасности. Пожалуйста, обновите страницу.']);
        return;
    }
    
    // Проверка прав пользователя
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'У вас нет прав для выполнения этого действия.']);
        return;
    }
    
    // Вызываем метод очистки всех очередей
    $result = Account_Updater::clear_all_queues();
    
    // Формируем человекочитаемое сообщение
    $message = 'Очистка завершена. ';
    $message .= 'Очищено очередей: ' . count($result['cleared_queues']) . ', ';
    $message .= 'списков очередей: ' . count($result['cleared_lists']) . ', ';
    $message .= 'опций статусов: ' . count($result['cleared_status_options']) . ', ';
    $message .= 'опций данных: ' . count($result['cleared_queue_options']) . '.';
    
    wp_send_json_success([
        'message' => $message,
        'details' => $result
    ]);
}
add_action('wp_ajax_fttradingapi_clear_all_queues', 'fttradingapi_clear_all_queues');

/**
 * AJAX обработчик для восстановления расписания автообновления
 */
function fttradingapi_restore_auto_update_schedule() {
    // Проверка nonce для безопасности
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'ft_trader_nonce')) {
        wp_send_json_error(['message' => 'Ошибка безопасности. Пожалуйста, обновите страницу.']);
        return;
    }
    
    // Проверка прав пользователя
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'У вас нет прав для выполнения этого действия.']);
        return;
    }
    
    // Восстанавливаем расписание
    $result = Account_Updater::setup_auto_update_schedule();
    
    if ($result) {
        $next_run = wp_next_scheduled('contest_create_queues');
        $message = 'Расписание автообновления успешно восстановлено. ';
        $message .= 'Следующий запуск: ' . date('d.m.Y H:i:s', $next_run);
        
        wp_send_json_success([
            'message' => $message,
            'next_run' => $next_run,
            'next_run_formatted' => date('d.m.Y H:i:s', $next_run)
        ]);
    } else {
        wp_send_json_error([
            'message' => 'Не удалось восстановить расписание или автообновление отключено в настройках.'
        ]);
    }
}
add_action('wp_ajax_fttradingapi_restore_auto_update_schedule', 'fttradingapi_restore_auto_update_schedule');
