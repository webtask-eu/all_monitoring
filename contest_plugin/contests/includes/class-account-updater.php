<?php
/**
 * Класс для управления процессом обновления счетов на сервере
 */
class Account_Updater
{
    // Ключи для хранения данных в опциях WordPress
    const QUEUE_OPTION_PREFIX = 'contest_accounts_update_queue_';
    const STATUS_OPTION_PREFIX = 'contest_accounts_update_status_';
    const AUTO_UPDATE_LAST_RUN = 'contest_accounts_auto_update_last_run';
    const BATCH_SIZE = 2; // Размер пакета по умолчанию для одного запуска - уменьшено до 2, в соответствии с ограничениями API сервера V2023.11.21

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

        // Создаем уникальный ID для этой очереди
        $letters = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $random_letters = '';
        for ($i = 0; $i < 4; $i++) {
            $random_letters .= $letters[rand(0, strlen($letters) - 1)];
        }
        $queue_id = 'q' . $random_letters;
        
        // ДОБАВЛЕНО: Логируем созданный ID очереди
        error_log("Created queue_id: " . $queue_id);
        
        // Выводим информацию в консоль через wp_add_inline_script
        $script = "console.log('%c🆔 Создан Queue ID: " . $queue_id . "', 'background:#3498db;color:white;padding:4px 8px;border-radius:3px;font-weight:bold;');";
        wp_add_inline_script('jquery', $script);
        
        // Используем contest_id + queue_id для формирования уникальных ключей опций
        // Это позволит запускать несколько параллельных обновлений внутри одного конкурса
        $contest_prefix = $contest_id ? $contest_id : 'global';
        $queue_option = self::QUEUE_OPTION_PREFIX . $contest_prefix . '_' . $queue_id;
        $status_option = self::STATUS_OPTION_PREFIX . $contest_prefix . '_' . $queue_id;

        if (empty($account_ids)) {
            return ['success' => false, 'message' => 'Не выбрано ни одного счета', 'contest_id' => $contest_id, 'queue_id' => $queue_id];
        }

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

        // Запускаем обработку через WP Cron с передачей queue_id и contest_id
        $scheduled = wp_schedule_single_event(time(), 'process_accounts_update_batch', [$contest_id, $queue_id]);
        
        // Принудительный запуск задач WP Cron сразу после планирования
        if ($scheduled) {
            // Удалены повторный spawn_cron() и прямой вызов process_batch
        } else {
            // Если планирование не удалось, обрабатываем первую порцию напрямую
            // Оставим прямое выполнение только как запасной вариант при ошибке планирования
            $direct_process_result = self::process_batch($contest_id, $queue_id);
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

        // Если queue_id не передан, пытаемся найти активную очередь для конкурса (для обратной совместимости)
        if (empty($queue_id)) {
            $status_option = self::STATUS_OPTION_PREFIX . ($contest_id ? $contest_id : 'global');
            $queue_option = self::QUEUE_OPTION_PREFIX . ($contest_id ? $contest_id : 'global');
        } else {
            // Используем переданный queue_id
            $contest_prefix = $contest_id ? $contest_id : 'global';
            $status_option = self::STATUS_OPTION_PREFIX . $contest_prefix . '_' . $queue_id;
            $queue_option = self::QUEUE_OPTION_PREFIX . $contest_prefix . '_' . $queue_id;
        }
        
        // Получаем текущий статус
        $status = get_option($status_option, []);
        $queue = get_option($queue_option, []);

        // Проверяем, что очередь существует и процесс запущен
        if (empty($status) || empty($queue) || !$status['is_running']) {
            return false;
        }

        // Проверяем соответствие contest_id в статусе, если он был передан
        if ($contest_id !== null && isset($status['contest_id']) && $status['contest_id'] !== $contest_id) {
            return false;
        }

        // Всегда получаем размер пакета из настроек плагина, независимо от типа обновления
        $auto_update_settings = get_option('fttrader_auto_update_settings', []);
        $batch_size = isset($auto_update_settings['fttrader_batch_size']) ?
            intval($auto_update_settings['fttrader_batch_size']) : self::BATCH_SIZE;

        // Получаем список счетов для текущей порции
        $batch_start = $status['current_batch'] * $batch_size;
        $current_batch = array_slice($queue, $batch_start, $batch_size);

        // Если порция пуста, завершаем процесс
        if (empty($current_batch)) {
            self::complete_queue($contest_id, $queue_id, $status_option, $queue_option);
            return true;
        }

        // Проверяем доступность функции process_trading_account
        if (!function_exists('process_trading_account')) {
            // Проверим, загружен ли файл с API-обработчиком
            $api_handler_file = plugin_dir_path(__FILE__) . 'class-api-handler.php';
            if (file_exists($api_handler_file)) {
                require_once $api_handler_file;
                
                if (!function_exists('process_trading_account')) {
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
                        wp_schedule_single_event(time() + 5, 'process_accounts_update_batch', [$contest_id, $queue_id]);
                    } else {
                        self::complete_queue($contest_id, $queue_id, $status_option, $queue_option);
                    }
                    
                    return false;
                }
            } else {
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
                
                return false;
            }
        }

        // Обновляем счета в порции
        foreach ($current_batch as $account_id) {
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
                $result = process_trading_account([], $account_id, null, $queue_batch_id);

                // Получаем актуальный статус подключения из базы
                $account_data = $wpdb->get_row($wpdb->prepare(
                    "SELECT connection_status, error_description, balance, equity, margin, leverage FROM {$wpdb->prefix}contest_members WHERE id = %d",
                    $account_id
                ), ARRAY_A);

                // Обновляем статус счета
                $status['accounts'][$account_id]['status'] = $result['success'] ? 'success' : 'failed';
                $status['accounts'][$account_id]['connection_status'] = $account_data['connection_status'] ?? 'disconnected';
                $status['accounts'][$account_id]['error_description'] = $account_data['error_description'] ?? '';
                $status['accounts'][$account_id]['message'] = $result['message'];
                $status['accounts'][$account_id]['end_time'] = time();

                // Обновляем общую статистику
                $status['completed']++;
                if ($result['success']) {
                    $status['success']++;
                } else {
                    $status['failed']++;
                }
            } catch (Exception $e) {
                // Обрабатываем исключение
                $status['accounts'][$account_id]['status'] = 'failed';
                $status['accounts'][$account_id]['message'] = 'Исключение: ' . $e->getMessage();
                $status['accounts'][$account_id]['end_time'] = time();
                $status['completed']++;
                $status['failed']++;
            }

            $status['last_update'] = time();
            update_option($status_option, $status);
        }

        // Увеличиваем номер порции
        $status['current_batch']++;
        update_option($status_option, $status);

        // Планируем следующую порцию, если есть еще счета
        if ($status['completed'] < $status['total']) {
            $scheduled = wp_schedule_single_event(time() + 5, 'process_accounts_update_batch', [$contest_id, $queue_id]);
            
            // Если планирование не удалось, обрабатываем следующую порцию немедленно
            if (!$scheduled) {
                // Логируем ошибку планирования и не запускаем рекурсивно
                error_log(sprintf('WP-Cron scheduling failed for queue %s (contest %s). Remaining accounts will not be processed automatically.', $queue_id, $contest_id));
                // Возможно, пометить оставшиеся счета как failed или error_scheduling
                // ... добавить логику пометки счетов, если необходимо
                // return self::process_batch($contest_id, $queue_id);
            } else {
                // Явный вызов spawn_cron для запуска WP Cron
                spawn_cron();
            }
        } else {
            // Все счета обработаны, завершаем процесс
            self::complete_queue($contest_id, $queue_id, $status_option, $queue_option);
        }
        
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
            $timeout = 5 * 60; // 5 минут тайм-аут
            if ($status['is_running'] && (time() - $status['last_update']) > $timeout) {
                $status['is_running'] = false;
                $status['message'] = 'Процесс был прерван из-за тайм-аута';
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
                $timeout = 5 * 60; // 5 минут тайм-аут
                if ((time() - $old_status['last_update']) > $timeout) {
                    $old_status['is_running'] = false;
                    $old_status['message'] = 'Процесс был прерван из-за тайм-аута';
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
            $timeout = 5 * 60; // 5 минут тайм-аут
            if ($queue_status['is_running'] && (time() - $queue_status['last_update']) > $timeout) {
                $queue_status['is_running'] = false;
                $queue_status['message'] = 'Процесс был прерван из-за тайм-аута';
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
            // Получаем активные счета данного конкурса
            $contest_accounts = $wpdb->get_col($wpdb->prepare(
                "SELECT id FROM $table_name WHERE contest_id = %d AND connection_status != 'disqualified'",
                $contest_id
            ));

            // Также получаем дисквалифицированные счета, которые не обновлялись более суток
            $stale_disqualified = $wpdb->get_col($wpdb->prepare(
                "SELECT id FROM $table_name WHERE contest_id = %d AND connection_status = 'disqualified' AND (last_update_time IS NULL OR last_update_time < %d)",
                $contest_id,
                $now - DAY_IN_SECONDS
            ));

            $all_accounts = array_merge($contest_accounts, $stale_disqualified);

            if (!empty($all_accounts)) {
                // Инициализируем очередь обновления с явно установленным флагом is_auto_update
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
        $timestamp = wp_next_scheduled('contest_accounts_auto_update');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'contest_accounts_auto_update');
        }
        
        // Если автообновление отключено, выходим
        if (!$enabled) {
            return false;
        }
        
        // Получаем интервал
        $interval = isset($settings['fttrader_auto_update_interval']) ? 
            intval($settings['fttrader_auto_update_interval']) : 60; // По умолчанию 60 минут
        
        // Проверяем/регистрируем кастомный интервал
        if (!wp_get_schedule('contest_accounts_auto_update')) {
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
            $scheduled = wp_schedule_event(time(), $schedule, 'contest_accounts_auto_update');
            
            // Принудительно запускаем WP Cron
            spawn_cron();
            
            return $scheduled !== false;
        }
        
        return true;
    }
}

// Регистрируем хук для обработки порции обновлений
// Число 10 - приоритет, 2 - количество передаваемых аргументов (contest_id, queue_id)
add_action('process_accounts_update_batch', ['Account_Updater', 'process_batch'], 10, 2);

// Регистрируем хук для автоматического обновления
add_action('contest_accounts_auto_update', ['Account_Updater', 'run_auto_update']);

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
        $next_run = wp_next_scheduled('contest_accounts_auto_update');
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
