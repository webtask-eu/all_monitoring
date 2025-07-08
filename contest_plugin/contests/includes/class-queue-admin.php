<?php
/**
 * Админ-панель для мониторинга очередей обновления счетов
 * 
 * @package ITX_Contest_Plugin
 * @author IntellaraX
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class ITX_Queue_Admin {
    
    public function __construct() {
        
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('wp_ajax_manual_queue_cleanup', array($this, 'manual_queue_cleanup'));
        add_action('wp_ajax_emergency_clear_all', array($this, 'emergency_clear_all'));
        add_action('wp_ajax_get_queue_details', array($this, 'get_queue_details'));
        add_action('wp_ajax_delete_single_queue', array($this, 'delete_single_queue'));
        add_action('wp_ajax_cleanup_orphaned_queues', array($this, 'cleanup_orphaned_queues'));
        add_action('admin_enqueue_scripts', array($this, 'admin_scripts'));
        add_action('wp_ajax_run_manual_queue', array($this, 'run_manual_queue'));
        add_action('wp_ajax_get_next_cron_time', array($this, 'get_next_cron_time'));
        add_action('wp_ajax_trigger_wp_cron', array($this, 'ajax_trigger_wp_cron'));
    }
    
    /**
     * Добавляет пункт меню в админке
     */
    public function add_admin_menu() {
        add_submenu_page(
            'edit.php?post_type=trader_contests',
            'Мониторинг очередей',
            'Очереди счетов',
            'manage_options',
            'queue-monitor',
            array($this, 'admin_page')
        );
    }
    
    /**
     * Подключает скрипты для админки
     */
    public function admin_scripts($hook) {
        if (strpos($hook, 'queue-monitor') !== false) {
            wp_enqueue_script('jquery');
        }
    }
    
    /**
     * Страница админки
     */
    public function admin_page() {
        if (!current_user_can('manage_options')) {
            wp_die('Доступ запрещен');
        }
        
        // Получаем данные о состоянии всех очередей
        $all_queues_status = $this->get_all_queues_status();
        $cleanup_stats = $this->get_cleanup_stats();
        
        // === Информация о расписании и таймауте ===
        $auto_update_settings = get_option('fttrader_auto_update_settings', []);
        $timeout_minutes = isset($auto_update_settings['fttrader_auto_update_timeout']) ? intval($auto_update_settings['fttrader_auto_update_timeout']) : 30;
        $timeout_seconds = $timeout_minutes * 60;
        
        $next_queue_ts = wp_next_scheduled('contest_create_queues');
        $next_queue_str = $next_queue_ts ? date('d.m.Y H:i:s', $next_queue_ts) : 'не запланировано';
        $next_queue_diff = $next_queue_ts ? max(0, $next_queue_ts - time()) : 0;
        
        // Добавляем серверное время для синхронизации с JavaScript
        $server_time = time();
        
        // Определяем ближайший таймаут активной очереди
        $timeout_ts = null;
        if (!empty($all_queues_status['active_queue'])) {
            $active_q = $all_queues_status['active_queue'];
            $base_time = isset($active_q['last_update']) && $active_q['last_update'] ? $active_q['last_update'] : (isset($active_q['start_time']) ? $active_q['start_time'] : 0);
            if ($base_time) {
                $timeout_ts = $base_time + $timeout_seconds;
            }
        }
        $timeout_str = $timeout_ts ? date('d.m.Y H:i:s', $timeout_ts) : '—';
        $timeout_diff = $timeout_ts ? max(0, $timeout_ts - time()) : 0;
        
        ?>
        <div class="wrap">
            <h1>Мониторинг очередей обновления счетов</h1>
            
            <div class="notice notice-info">
                <p><strong>Мониторинг очередей обновления счетов</strong></p>
                <p>Отображает текущее состояние очередей обновления без автоматического вмешательства</p>
            </div>
            
            <!-- Текущее состояние -->
            <div class="card">
                <h2>Текущее состояние системы</h2>
                <table class="widefat">
                    <tbody>
                        <tr>
                            <td><strong>Активных очередей</strong></td>
                            <td>
                                <?php if ($all_queues_status['active_queues_count'] > 0): ?>
                                    <span style="color: orange;">⚠️ <?php echo $all_queues_status['active_queues_count']; ?></span>
                                <?php else: ?>
                                    <span style="color: green;">✅ Нет</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <td><strong>Остановленных очередей</strong></td>
                            <td>
                                <?php if ($all_queues_status['inactive_queues_count'] > 0): ?>
                                    <span style="color: gray;">⏸️ <?php echo $all_queues_status['inactive_queues_count']; ?></span>
                                <?php else: ?>
                                    <span style="color: green;">✅ Нет</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            
            <!-- Информация -->
            <div class="card">
                <h2>Информация</h2>
                <table class="widefat">
                    <tbody>
                        <tr>
                            <td><strong>Следующее создание очередей</strong></td>
                            <td>
                                <?php if ($next_queue_ts): ?>
                                    <span class="js-local-datetime" data-timestamp="<?php echo esc_attr($next_queue_ts); ?>" data-server-time="<?php echo esc_attr(time()); ?>"></span>
                                    (через <span class="js-countdown" data-timestamp="<?php echo esc_attr($next_queue_ts); ?>" data-server-time="<?php echo esc_attr(time()); ?>"></span>)
                                <?php else: ?>
                                    Не запланировано
                                <?php endif; ?>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            
            <!-- Все очереди -->
            <?php if (!empty($all_queues_status['all_queues'])): ?>
            <div class="card">
                <h2>Все очереди (активные и остановленные)</h2>
                <table class="widefat striped" id="queues-table">
                    <thead>
                        <tr>
                            <th>ID очереди</th>
                            <th>Статус</th>
                            <th>Время работы</th>
                            <th>Последнее обновление</th>
                            <th>Таймаут</th>
                            <th>Прогресс</th>
                            <th>Сообщение</th>
                            <th>Детали</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($all_queues_status['all_queues'] as $queue_info): ?>
                        <?php 
                        $queue_data = $queue_info['data'];
                        $start_time = isset($queue_data['start_time']) ? $queue_data['start_time'] : time();
                        $last_update = isset($queue_data['last_update']) ? $queue_data['last_update'] : null;
                        $runtime = time() - $start_time;
                        $progress = isset($queue_data['completed'], $queue_data['total']) ? 
                            $queue_data['completed'] . '/' . $queue_data['total'] : 'н/д';
                        
                        // Определяем цвет статуса в зависимости от is_running
                        $is_running = isset($queue_data['is_running']) ? $queue_data['is_running'] : false;
                        if ($is_running) {
                            // Для активных очередей
                            $status_color = 'green';
                            $status_text = 'Запущена';
                        } else {
                            // Для остановленных очередей
                            $status_color = 'gray';
                            if (isset($queue_data['timeout']) && $queue_data['timeout']) {
                                $status_text = 'Остановлена (таймаут)';
                            } else {
                                $status_text = 'Остановлена';
                            }
                        }
                        
                        // Расчёт времени до таймаута
                        $auto_update_settings = get_option('fttrader_auto_update_settings', []);
                        $timeout_minutes = isset($auto_update_settings['fttrader_auto_update_timeout']) ? 
                            intval($auto_update_settings['fttrader_auto_update_timeout']) : 30;
                        $timeout_seconds = $timeout_minutes * 60;
                        
                        $timeout_display = '—';
                        if ($is_running) {
                            $timeout_timestamp = $start_time + $timeout_seconds;
                            $time_left = $timeout_timestamp - time();
                            if ($time_left > 0) {
                                $timeout_display = '<span class="js-countdown" data-timestamp="' . esc_attr($timeout_timestamp) . '"></span>';
                            } else {
                                $timeout_display = '<span style="color: red;">Просрочен</span>';
                            }
                        } elseif (isset($queue_data['timeout']) && $queue_data['timeout']) {
                            $timeout_display = '<span style="color: red;">Таймаут</span>';
                        }
                        
                        $message = isset($queue_data['message']) ? $queue_data['message'] : '';
                        
                        // Формируем имена опций для кнопки деталей
                        $queue_option_name = str_replace('contest_accounts_update_status_', 'contest_accounts_update_queue_', $queue_info['option_name']);
                        ?>
                        <tr class="queue-row" data-queue-id="<?php echo esc_attr(isset($queue_data['queue_id']) ? $queue_data['queue_id'] : ''); ?>">
                            <td><?php echo esc_html(isset($queue_data['queue_id']) ? $queue_data['queue_id'] : 'н/д'); ?></td>
                            <td>
                                <span style="color: <?php echo $status_color; ?>;">
                                    <?php echo $status_text; ?>
                                </span>
                            </td>
                            <td><?php echo gmdate('H:i:s', $runtime); ?></td>
                            <td>
                                <?php if ($last_update): ?>
                                    <?php echo date('d.m.Y H:i:s', $last_update); ?>
                                    (<?php echo gmdate('i:s', time() - $last_update); ?> назад)
                                <?php else: ?>
                                    Никогда
                                <?php endif; ?>
                            </td>
                            <td><?php echo $timeout_display; ?></td>
                            <td><?php echo esc_html($progress); ?></td>
                            <td><?php echo esc_html($message); ?></td>
                            <td>
                                <button type="button" class="button button-small show-queue-details" 
                                        data-queue-option="<?php echo esc_attr($queue_option_name); ?>"
                                        data-status-option="<?php echo esc_attr($queue_info['option_name']); ?>">
                                    📊 Показать пакеты
                                </button>
                                <button type="button" class="button button-small delete-queue" 
                                        data-queue-id="<?php echo esc_attr(isset($queue_data['queue_id']) ? $queue_data['queue_id'] : ''); ?>"
                                        data-status-option="<?php echo esc_attr($queue_info['option_name']); ?>"
                                        data-queue-option="<?php echo esc_attr($queue_option_name); ?>"
                                        style="margin-left: 5px; background-color: #dc3545; border-color: #dc3545; color: white;"
                                        title="Удалить очередь полностью">
                                    🗑️ Удалить
                                </button>
                            </td>
                        </tr>
                        <tr class="queue-details-row" data-queue-id="<?php echo esc_attr(isset($queue_data['queue_id']) ? $queue_data['queue_id'] : ''); ?>" style="display: none;">
                            <td colspan="8">
                                <div class="queue-details-content">
                                    <div class="loading-spinner">Загрузка...</div>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
            <div class="card">
                <h2>Очереди</h2>
                <p><em>Нет очередей в системе</em></p>
            </div>
            <?php endif; ?>
            
            <!-- Действия -->
            <div class="card">
                <h2>Действия</h2>
                <div class="action-buttons">
                    <button type="button" id="run-manual-queue" class="button button-secondary">
                        🚀 Запустить очередь внепланово
                    </button>
                    <button type="button" id="trigger-wp-cron" class="button button-secondary">
                        ⏰ Дернуть wp-cron.php
                    </button>
                    <button type="button" id="emergency-clear" class="button button-primary emergency-button">
                        🗑️ Удалить все очереди
                    </button>
                </div>
                <p class="description">
                    <strong>Экстренная очистка</strong> удаляет все активные очереди принудительно.<br>
                    Используйте только если система полностью заблокирована зависшими очередями!
                </p>
            </div>
            
            <!-- Статистика автоочистки -->
            <?php if (!empty($cleanup_stats)): ?>
            <div class="card">
                <h2>История автоочистки (последние записи)</h2>
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th>Время очистки</th>
                            <th>ID очереди</th>
                            <th>Время работы</th>
                            <th>Прогресс</th>
                            <th>Последнее обновление</th>
                            <th>Причина</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach (array_reverse($cleanup_stats) as $stat): ?>
                        <tr>
                            <td><?php echo date('d.m.Y H:i:s', $stat['cleanup_time']); ?></td>
                            <td><?php echo esc_html($stat['queue_id']); ?></td>
                            <td><?php echo gmdate('H:i:s', $stat['queue_runtime']); ?></td>
                            <td><?php echo $stat['progress'] . '/' . $stat['total']; ?></td>
                            <td>
                                <?php if ($stat['last_update']): ?>
                                    <?php echo date('d.m.Y H:i:s', $stat['last_update']); ?>
                                <?php else: ?>
                                    Никогда
                                <?php endif; ?>
                            </td>
                            <td><?php echo esc_html(isset($stat['cleanup_reason']) ? $stat['cleanup_reason'] : 'н/д'); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
            
            <!-- Карточка «Настройки защиты» удалена (v2025-07) -->
        </div>
        
        <script type="text/javascript">
        jQuery(document).ready(function($) {
            // Обработчик показа деталей очереди
            $('.show-queue-details').click(function() {
                var button = $(this);
                var queueId = button.closest('.queue-row').data('queue-id');
                var detailsRow = $('.queue-details-row[data-queue-id="' + queueId + '"]');
                var detailsContent = detailsRow.find('.queue-details-content');
                
                if (detailsRow.is(':visible')) {
                    // Скрываем детали
                    detailsRow.hide();
                    button.text('📊 Показать пакеты');
                    return;
                }
                
                // Показываем загрузку
                detailsRow.show();
                detailsContent.html('<div class="loading-spinner">⏳ Загрузка пакетов...</div>');
                button.text('📊 Скрыть пакеты').prop('disabled', true);
                
                // Запрос деталей через AJAX
                $.post(ajaxurl, {
                    action: 'get_queue_details',
                    queue_option: button.data('queue-option'),
                    status_option: button.data('status-option'),
                    _wpnonce: '<?php echo wp_create_nonce('get_queue_details'); ?>'
                }, function(response) {
                    button.prop('disabled', false);
                    
                    if (response.success) {
                        detailsContent.html(renderQueueDetails(response.data));
                    } else {
                        detailsContent.html('<div class="error">Ошибка загрузки: ' + (response.data ? response.data.error : 'Неизвестная ошибка') + '</div>');
                    }
                });
            });
            
            // Обработчик удаления очереди
            $('.delete-queue').click(function() {
                var button = $(this);
                var queueId = button.data('queue-id');
                var statusOption = button.data('status-option');
                var queueOption = button.data('queue-option');
                
                if (!confirm('Вы уверены, что хотите удалить очередь "' + queueId + '"?\n\nЭто действие удалит:\n- Все данные о статусе очереди\n- Все данные о пакетах\n- Записи из списка активных очередей\n\nДействие необратимо!')) {
                    return;
                }
                
                button.prop('disabled', true).text('⏳ Удаление...');
                
                $.post(ajaxurl, {
                    action: 'delete_single_queue',
                    queue_id: queueId,
                    status_option: statusOption,
                    queue_option: queueOption,
                    _wpnonce: '<?php echo wp_create_nonce('delete_queue_nonce'); ?>'
                }, function(response) {
                    if (response.success) {
                        // Удаляем строку из таблицы
                        var queueRow = button.closest('.queue-row');
                        var detailsRow = $('.queue-details-row[data-queue-id="' + queueId + '"]');
                        queueRow.fadeOut(300, function() { queueRow.remove(); });
                        detailsRow.fadeOut(300, function() { detailsRow.remove(); });
                        
                        // Показываем уведомление об успехе
                        var successMessage = '<div class="notice notice-success is-dismissible"><p><strong>Очередь успешно удалена!</strong> ' + response.data.message + '</p></div>';
                        $('.wrap h1').after(successMessage);
                        
                        // Скрываем уведомление через 5 секунд
                        setTimeout(function() {
                            $('.notice-success').fadeOut();
                        }, 5000);
                    } else {
                        button.prop('disabled', false).text('🗑️ Удалить');
                        alert('Ошибка удаления: ' + (response.data ? response.data.error : 'Неизвестная ошибка'));
                    }
                }).fail(function() {
                    button.prop('disabled', false).text('🗑️ Удалить');
                    alert('Ошибка соединения с сервером');
                });
            });
            
            // Обработчики для удалённых кнопок убраны (v2025-07)
            
            // Функция отрисовки деталей пакетной обработки очереди
            function renderQueueDetails(details) {
                var html = '<div class="queue-details-wrapper">';
                
                // Общая информация о очереди
                html += '<div class="queue-summary">';
                html += '<h4>📊 Общая информация об очереди</h4>';
                html += '<div class="summary-grid">';
                html += '<div class="summary-item"><strong>Всего счетов:</strong> ' + details.summary.total_accounts + '</div>';
                html += '<div class="summary-item"><strong>Всего пакетов:</strong> ' + details.summary.total_batches + '</div>';
                html += '<div class="summary-item"><strong>Текущий пакет:</strong> #' + details.summary.current_batch + '</div>';
                html += '<div class="summary-item"><strong>Обработано:</strong> ' + details.summary.completed + '</div>';
                html += '<div class="summary-item"><strong>Успешно:</strong> ' + details.summary.success + '</div>';
                html += '<div class="summary-item"><strong>Ошибок:</strong> ' + details.summary.failed + '</div>';
                
                // Добавляем временную информацию
                if (details.summary.queue_start_time) {
                    var startTime = new Date(details.summary.queue_start_time * 1000);
                    html += '<div class="summary-item"><strong>Начало обработки:</strong> ' + startTime.toLocaleString() + '</div>';
                }
                if (details.summary.queue_last_update) {
                    var lastUpdate = new Date(details.summary.queue_last_update * 1000);
                    html += '<div class="summary-item"><strong>Последнее обновление:</strong> ' + lastUpdate.toLocaleString() + '</div>';
                }
                html += '<div class="summary-item"><strong>Статус очереди:</strong> ' + 
                       (details.summary.is_running ? '🟢 Активна' : '🔴 Остановлена') + '</div>';
                html += '</div></div>';
                
                // Пакеты с деталями обработки
                if (details.batches && details.batches.length > 0) {
                    html += '<div class="batches-container">';
                    html += '<h4>📦 Детальный анализ пакетной обработки</h4>';
                    
                    details.batches.forEach(function(batch) {
                        // Определяем иконку и статус пакета
                        var statusIcon, statusText, statusClass;
                        switch(batch.status) {
                            case 'completed':
                                statusIcon = '✅';
                                statusText = 'Завершен';
                                statusClass = 'completed';
                                break;
                            case 'processing':
                                statusIcon = '🔄';
                                statusText = 'Зависла в обработке';
                                statusClass = 'processing';
                                break;
                            case 'partial':
                                statusIcon = '⚠️';
                                statusText = 'Частично обработан';
                                statusClass = 'partial';
                                break;
                            default:
                                statusIcon = '⏳';
                                statusText = 'Ожидает обработки';
                                statusClass = 'pending';
                        }
                        
                        html += '<div class="batch-container batch-' + statusClass + '">';
                        html += '<div class="batch-header" data-batch="' + batch.batch_number + '">';
                        html += '<span class="batch-toggle">▶</span> ';
                        html += statusIcon + ' <strong>Пакет #' + batch.batch_number + '</strong> ';
                        html += '(' + batch.total_accounts + ' счетов) - ' + statusText;
                        
                        // Статистика пакета
                        if (batch.completed_count > 0) {
                            html += ' [✅' + batch.success_count + ' успешно, ❌' + batch.failed_count + ' ошибок';
                            if (batch.processing_count > 0) {
                                html += ', 🔄' + batch.processing_count + ' зависло';
                            }
                            html += ']';
                        }
                        html += '</div>';
                        
                        html += '<div class="batch-accounts" style="display: none;">';
                        html += '<table class="widefat accounts-table">';
                        html += '<thead><tr>';
                        html += '<th>ID</th><th>Номер счета</th><th>Брокер</th><th>Платформа</th>';
                        html += '<th>Статус обработки</th><th>Время обработки</th><th>Результат</th>';
                        html += '</tr></thead><tbody>';
                        
                        batch.accounts.forEach(function(account) {
                            // Определяем цвет и иконку статуса обработки
                            var statusIcon, statusColor, statusText;
                            switch(account.processing_status) {
                                case 'success':
                                    statusIcon = '✅';
                                    statusColor = 'green';
                                    statusText = 'Успешно';
                                    break;
                                case 'failed':
                                    statusIcon = '❌';
                                    statusColor = 'red';
                                    statusText = 'Ошибка';
                                    break;
                                case 'processing':
                                    statusIcon = '🔄';
                                    statusColor = 'orange';
                                    statusText = 'Зависла';
                                    break;
                                default:
                                    statusIcon = '⏳';
                                    statusColor = 'gray';
                                    statusText = 'Ожидает';
                            }
                            
                            // Форматируем время обработки
                            var processingTime = '';
                            if (account.processing_start_time && account.processing_end_time) {
                                var duration = account.processing_duration;
                                processingTime = Math.floor(duration / 60) + 'мин ' + (duration % 60) + 'сек';
                            } else if (account.processing_start_time && account.processing_status === 'processing') {
                                var duration = account.processing_duration;
                                processingTime = 'Зависла: ' + Math.floor(duration / 60) + 'мин ' + (duration % 60) + 'сек назад';
                            } else if (account.processing_start_time) {
                                var startTime = new Date(account.processing_start_time * 1000);
                                processingTime = 'Начало: ' + startTime.toLocaleTimeString();
                            }
                            
                            html += '<tr class="account-row account-' + account.processing_status + '">';
                            html += '<td>' + account.id + '</td>';
                            html += '<td>' + account.account_number + '</td>';
                            html += '<td>' + account.broker_name + '</td>';
                            html += '<td>' + account.platform_name + '</td>';
                            html += '<td><span style="color: ' + statusColor + ';">' + statusIcon + ' ' + statusText + '</span></td>';
                            html += '<td>' + processingTime + '</td>';
                            
                            // Результат обработки
                            var resultText = account.processing_message || '';
                            if (account.processing_status === 'failed' && account.error_description) {
                                resultText = account.error_description.substring(0, 100) + '...';
                            }
                            html += '<td><small>' + resultText + '</small></td>';
                            html += '</tr>';
                        });
                        
                        html += '</tbody></table></div></div>';
                    });
                    
                    html += '</div>';
                } else {
                    html += '<div class="no-batches">❌ Пакеты не найдены или очередь пуста</div>';
                }
                
                html += '</div>';
                
                return html;
            }
            
            // Обработчик раскрытия/сворачивания пакетов
            $(document).on('click', '.batch-header', function() {
                var accountsDiv = $(this).next('.batch-accounts');
                var toggle = $(this).find('.batch-toggle');
                
                if (accountsDiv.is(':visible')) {
                    accountsDiv.hide();
                    toggle.text('▶');
                } else {
                    accountsDiv.show();
                    toggle.text('▼');
                }
            });
            
            // Обработчик экстренной очистки
            $('#emergency-clear').click(function() {
                if (!confirm('⚠️ ВНИМАНИЕ!\n\nЭто действие принудительно удалит ВСЕ активные очереди.\nИспользуйте только если система полностью заблокирована!\n\nПродолжить?')) {
                    return;
                }
                
                if (!confirm('🚨 ПОСЛЕДНЕЕ ПРЕДУПРЕЖДЕНИЕ!\n\nВы уверены что хотите удалить ВСЕ очереди?\nЭто может прервать текущие обновления счетов!')) {
                    return;
                }
                
                var button = $(this);
                button.prop('disabled', true).text('Экстренная очистка...');
                
                $.post(ajaxurl, {
                    action: 'emergency_clear_all',
                    _wpnonce: '<?php echo wp_create_nonce('emergency_clear_all'); ?>'
                }, function(response) {
                    if (response.success) {
                        alert('🚨 Экстренная очистка завершена!\nОчищено очередей: ' + response.data.cleared + '\n\nСистема разблокирована.');
                        location.reload();
                    } else {
                        alert('Ошибка: ' + response.data.error);
                    }
                    button.prop('disabled', false).text('🚨 Экстренная очистка ВСЕХ очередей');
                });
            });

            // Обработчик внепланового запуска очередей
            $('#run-manual-queue').click(function() {
                if (!confirm('Создать внеплановые очереди для всех активных конкурсов?')) {
                    return;
                }
                var button = $(this);
                button.prop('disabled', true).text('🚀 Запуск...');
                
                $.post(ajaxurl, {
                    action: 'run_manual_queue',
                    _wpnonce: '<?php echo wp_create_nonce('run_manual_queue'); ?>'
                }, function(response) {
                    if (response.success) {
                        alert('✅ ' + response.data.message);
                        location.reload();
                    } else {
                        alert('Ошибка: ' + (response.data ? response.data.error : 'Неизвестная ошибка'));
                    }
                    button.prop('disabled', false).text('🚀 Запустить очередь внепланово');
                }).fail(function() {
                    button.prop('disabled', false).text('🚀 Запустить очередь внепланово');
                    alert('Ошибка соединения с сервером');
                });
            });

            // Обработчик для запуска wp-cron.php
            $('#trigger-wp-cron').click(function() {
                if (!confirm('Вы уверены, что хотите запустить wp-cron.php? Это может привести к загрузке сервера.')) {
                    return;
                }
                var button = $(this);
                button.prop('disabled', true).text('⏳ Запуск...');
                
                $.post(ajaxurl, {
                    action: 'trigger_wp_cron',
                    _wpnonce: '<?php echo wp_create_nonce('trigger_wp_cron'); ?>'
                }, function(response) {
                    if (response.success) {
                        alert('✅ wp-cron.php запущен.');
                        location.reload();
                    } else {
                        alert('Ошибка: ' + (response.data ? response.data.error : 'Неизвестная ошибка'));
                    }
                    button.prop('disabled', false).text('⏰ Дернуть wp-cron.php');
                }).fail(function() {
                    button.prop('disabled', false).text('⏰ Дернуть wp-cron.php');
                    alert('Ошибка соединения с сервером');
                });
            });


            // ===== Клиентское форматирование дат =====
            var serverTimeOffset = 0; // Разность между серверным и клиентским временем
            
            function pad(n){return n<10?'0'+n:n;}
            function formatDate(ts){
                var d=new Date(ts*1000);
                return pad(d.getDate())+'.'+pad(d.getMonth()+1)+'.'+d.getFullYear()+' '+pad(d.getHours())+':'+pad(d.getMinutes())+':'+pad(d.getSeconds());
            }
            function formatCountdown(ts, serverTime){
                var currentTime;
                if (serverTime) {
                    // Используем серверное время для первого расчета
                    if (serverTimeOffset === 0) {
                        serverTimeOffset = serverTime - Math.floor(Date.now() / 1000);
                    }
                    currentTime = Math.floor(Date.now() / 1000) + serverTimeOffset;
                } else {
                    currentTime = Math.floor(Date.now() / 1000) + serverTimeOffset;
                }
                
                var diff = Math.max(0, ts - currentTime);
                var m = Math.floor(diff / 60);
                var s = diff % 60;
                return pad(m) + ':' + pad(s);
            }
            function refreshTimes(){
                $('.js-local-datetime').each(function(){
                    var ts=$(this).data('timestamp');
                    $(this).text(formatDate(ts));
                });
                $('.js-countdown').each(function(){
                    var ts=$(this).data('timestamp');
                    var serverTime=$(this).data('server-time');
                    var countdown = formatCountdown(ts, serverTime);
                    $(this).text(countdown);
                    
                    // Если обратный отсчёт достиг 00:00 или время в прошлом, обновляем данные
                    var currentTime = Math.floor(Date.now() / 1000) + serverTimeOffset;
                    if ((countdown === '00:00' || ts <= currentTime) && !$(this).hasClass('refreshing')) {
                        $(this).addClass('refreshing').text('обновление...');
                        var $row = $(this).closest('tr');
                        var $datetime = $row.find('.js-local-datetime');
                        
                        $.post(ajaxurl, {
                            action: 'get_next_cron_time',
                            _wpnonce: '<?php echo wp_create_nonce('get_next_cron_time'); ?>'
                        }, function(response) {
                            if (response.success && response.data.timestamp) {
                                // Обновляем timestamp и текст
                                $datetime.data('timestamp', response.data.timestamp).text(formatDate(response.data.timestamp));
                                $row.find('.js-countdown').data('timestamp', response.data.timestamp).data('server-time', response.data.debug.current_time).removeClass('refreshing');
                                // Обновляем смещение времени
                                serverTimeOffset = response.data.debug.current_time - Math.floor(Date.now() / 1000);
                            } else {
                                // Если нет следующего события, показываем это
                                $datetime.text('не запланировано');
                                $row.find('.js-countdown').text('—').removeClass('refreshing');
                            }
                        }).fail(function() {
                            // При ошибке показываем статус
                            $row.find('.js-countdown').text('ошибка').removeClass('refreshing');
                        });
                    }
                });
            }
            refreshTimes();
            setInterval(refreshTimes,1000);
        });
        </script>
        
        <style>
        .card {
            background: white;
            border: 1px solid #ccd0d4;
            box-shadow: 0 1px 1px rgba(0,0,0,.04);
            margin: 20px 0;
            padding: 20px;
        }
        .card h2 {
            margin-top: 0;
        }
        
        /* Стили для кнопок действий */
        .action-buttons {
            display: flex;
            gap: 12px;
            margin-bottom: 15px;
            flex-wrap: wrap;
        }
        
        .action-buttons .button {
            padding: 8px 16px;
            font-size: 14px;
            min-width: 180px;
            text-align: center;
        }
        
        .emergency-button {
            background-color: #dc3545 !important;
            border-color: #dc3545 !important;
            color: white !important;
        }
        
        .emergency-button:hover {
            background-color: #c82333 !important;
            border-color: #bd2130 !important;
        }
        
        .description {
            font-size: 13px;
            color: #666;
            margin: 0;
            padding: 12px;
            background: #f8f9fa;
            border-left: 4px solid #007cba;
            border-radius: 0 4px 4px 0;
        }
        
        #emergency-clear {
            margin-left: 10px;
        }
        
        /* Стили для кнопки удаления очереди */
        .delete-queue {
            background-color: #dc3545 !important;
            border-color: #dc3545 !important;
            color: white !important;
            margin-left: 5px;
        }
        
        .delete-queue:hover {
            background-color: #c82333 !important;
            border-color: #bd2130 !important;
            color: white !important;
        }
        
        .delete-queue:disabled {
            background-color: #6c757d !important;
            border-color: #6c757d !important;
            color: white !important;
            cursor: not-allowed;
        }
        
        /* Стили для деталей очереди */
        .queue-details-row {
            background-color: #f9f9f9;
            border-top: 1px solid #ddd;
        }
        
        .queue-details-wrapper {
            padding: 15px;
            background: white;
            border: 1px solid #ddd;
            border-radius: 4px;
            margin: 10px 0;
        }
        
        .queue-summary {
            margin-bottom: 20px;
            padding: 15px;
            background: #e8f4fd;
            border-radius: 4px;
            border-left: 4px solid #0073aa;
        }
        
        .summary-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 10px;
            margin-top: 10px;
        }
        
        .summary-item {
            padding: 8px;
            background: white;
            border-radius: 3px;
            font-size: 13px;
        }
        
        .batches-container {
            margin-top: 20px;
        }
        
        .batch-container {
            margin-bottom: 15px;
            border: 1px solid #ddd;
            border-radius: 4px;
            overflow: hidden;
        }
        
        .batch-container.batch-completed {
            border-color: #46b450;
        }
        
        .batch-container.batch-processing {
            border-color: #ffb900;
        }
        
        .batch-container.batch-pending {
            border-color: #72aee6;
        }
        
        .batch-header {
            padding: 12px 15px;
            background: #f7f7f7;
            border-bottom: 1px solid #ddd;
            cursor: pointer;
            font-weight: 500;
            user-select: none;
        }
        
        .batch-header:hover {
            background: #f0f0f0;
        }
        
        .batch-completed .batch-header {
            background: #e8f5e8;
        }
        
        .batch-processing .batch-header {
            background: #fff8e1;
        }
        
        .batch-pending .batch-header {
            background: #e3f2fd;
        }
        
        .batch-toggle {
            display: inline-block;
            width: 12px;
            transition: transform 0.2s;
        }
        
        .batch-accounts {
            border-top: 1px solid #ddd;
        }
        
        .accounts-table {
            margin: 0;
            border: none;
        }
        
        .accounts-table th,
        .accounts-table td {
            padding: 8px 12px;
            border-bottom: 1px solid #f0f0f0;
            font-size: 12px;
        }
        
        .accounts-table th {
            background: #f9f9f9;
            font-weight: 600;
        }
        
        .accounts-table tbody tr:hover {
            background: #f5f5f5;
        }
        
        .loading-spinner {
            text-align: center;
            padding: 20px;
            color: #666;
            font-style: italic;
        }
        
        .error {
            padding: 15px;
            background: #fee;
            border: 1px solid #fcc;
            color: #c33;
            border-radius: 4px;
        }
        
        .no-batches {
            text-align: center;
            padding: 20px;
            color: #666;
            font-style: italic;
        }
        
        .show-queue-details {
            white-space: nowrap;
        }
        
        /* Стили для статусов пакетной обработки */
        .batch-container.batch-completed {
            border-left: 4px solid #46b450;
        }
        
        .batch-container.batch-processing {
            border-left: 4px solid #ffb900;
            background-color: #fff3cd;
        }
        
        .batch-container.batch-partial {
            border-left: 4px solid #f0ad4e;
            background-color: #fcf8e3;
        }
        
        .batch-container.batch-pending {
            border-left: 4px solid #6c757d;
        }
        
        /* Стили для строк счетов */
        .account-row.account-success {
            background-color: #d4edda;
        }
        
        .account-row.account-failed {
            background-color: #f8d7da;
        }
        
        .account-row.account-processing {
            background-color: #fff3cd;
            animation: pulse 2s infinite;
        }
        
        .account-row.account-pending {
            background-color: #e2e3e5;
        }
        
        @keyframes pulse {
            0% { opacity: 1; }
            50% { opacity: 0.7; }
            100% { opacity: 1; }
        }
        
        .accounts-table th {
            background-color: #f1f1f1;
            font-weight: bold;
        }
        
        .accounts-table td {
            padding: 8px;
            border-bottom: 1px solid #ddd;
        }
        
        .accounts-table tr:hover {
            background-color: #f5f5f5;
        }
        
        @media (max-width: 768px) {
            .summary-grid {
                grid-template-columns: 1fr;
            }
            
            .accounts-table {
                font-size: 12px;
            }
            
            .accounts-table th,
            .accounts-table td {
                padding: 4px;
            }
        }
        </style>
        
        <?php
    }
    
    /**
     * Ручная очистка очередей через AJAX (упрощенная версия)
     */
    public function manual_queue_cleanup() {
        check_ajax_referer('manual_queue_cleanup');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('error' => 'Недостаточно прав'));
        }
        
        wp_send_json_success(array(
            'cleaned' => 0,
            'message' => 'Автоматическая защита очередей отключена. Используйте встроенные таймауты.'
        ));
    }
    
    /**
     * Экстренная очистка всех очередей через AJAX (упрощенная версия)
     */
    public function emergency_clear_all() {
        check_ajax_referer('emergency_clear_all');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('error' => 'Недостаточно прав'));
        }
        
        global $wpdb;
        
        // Список префиксов служебных опций, связанных с очередями
        $prefixes = array(
            'contest_accounts_update_queue_',
            'contest_accounts_update_status_',
            'contest_active_queues_'
        );
        
        // Формируем SQL-условие вида "option_name LIKE 'prefix_%' OR ..."
        $like_clauses = array();
        foreach ($prefixes as $pref) {
            $like_clauses[] = $wpdb->prepare('option_name LIKE %s', $pref . '%');
        }
        $where_sql = implode(' OR ', $like_clauses);
        
        // Получаем все совпадающие опции
        $options_to_delete = $wpdb->get_col("SELECT option_name FROM {$wpdb->options} WHERE {$where_sql}");
        $cleared = 0;
        
        if ($options_to_delete) {
            foreach ($options_to_delete as $option_name) {
                if (delete_option($option_name)) {
                    $cleared++;
                }
            }
        }
        
        // Сбрасываем отметку времени последнего запуска создания очередей, чтобы немедленно разрешить новые очереди
        delete_option('contest_create_queues_last_run');
        
        wp_send_json_success(array(
            'cleared' => $cleared,
            'message' => 'Очереди очищены успешно'
        ));
    }
     
     /**
      * Получает детальную информацию о пакетах и счетах очереди через AJAX
      */
     public function get_queue_details() {
         check_ajax_referer('get_queue_details');
         
         if (!current_user_can('manage_options')) {
             wp_send_json_error(array('error' => 'Недостаточно прав'));
         }
         
         $queue_option = sanitize_text_field($_POST['queue_option']);
         $status_option = sanitize_text_field($_POST['status_option']);
         
         // Получаем данные очереди и статуса
         $queue_raw_data = get_option($queue_option, array());
         $status_data = get_option($status_option, array());
         
         // Извлекаем ID участников правильно в зависимости от структуры данных
         $account_ids = array();
         
         if (is_array($queue_raw_data)) {
             // Если queue_raw_data содержит поле 'accounts', используем его
             if (isset($queue_raw_data['accounts']) && is_array($queue_raw_data['accounts'])) {
                 $account_ids = array_values($queue_raw_data['accounts']);
             } 
             // Если queue_raw_data это простой массив ID (как в qUIJg)
             elseif (is_numeric(array_keys($queue_raw_data)[0] ?? false)) {
                 $account_ids = array_values($queue_raw_data);
             }
         }
         
         // Если не получили ID из данных очереди, попробуем извлечь из статуса
         if (empty($account_ids) && isset($status_data['accounts']) && is_array($status_data['accounts'])) {
             $account_ids = array_keys($status_data['accounts']);
         }
         
         $details = $this->get_queue_batch_details($account_ids, $status_data, $queue_option);
         
         wp_send_json_success($details);
     }
     
     /**
      * Получает информацию о пакетах и счетах в очереди
      * 
      * @param array $account_ids Массив ID участников
      * @param array $status_data Данные статуса очереди с результатами обработки
      * @param string $queue_option Имя опции очереди
      * @return array Детальная информация о пакетах
      */
     private function get_queue_batch_details($account_ids, $status_data, $queue_option) {
         global $wpdb;
         
         $details = array(
             'batches' => array(),
             'summary' => array(
                 'total_accounts' => 0,
                 'total_batches' => 0,
                 'current_batch' => isset($status_data['current_batch']) ? $status_data['current_batch'] : 0,
                 'completed' => isset($status_data['completed']) ? $status_data['completed'] : 0,
                 'success' => isset($status_data['success']) ? $status_data['success'] : 0,
                 'failed' => isset($status_data['failed']) ? $status_data['failed'] : 0,
                 'queue_start_time' => isset($status_data['start_time']) ? $status_data['start_time'] : 0,
                 'queue_last_update' => isset($status_data['last_update']) ? $status_data['last_update'] : 0,
                 'is_running' => isset($status_data['is_running']) ? $status_data['is_running'] : false
             )
         );
         
         // Если нет ID участников, возвращаем пустой результат
         if (empty($account_ids) || !is_array($account_ids)) {
             return $details;
         }
         
         // Получаем настройки размера пакета
         $auto_update_settings = get_option('fttrader_auto_update_settings', array());
         $batch_size = isset($auto_update_settings['fttrader_batch_size']) ? 
             intval($auto_update_settings['fttrader_batch_size']) : 2;
         
         $details['summary']['total_accounts'] = count($account_ids);
         $details['summary']['total_batches'] = ceil(count($account_ids) / $batch_size);
         
         // Получаем данные об обработке счетов из статуса очереди
         $accounts_processing_data = isset($status_data['accounts']) ? $status_data['accounts'] : array();
         
         // Разбиваем ID участников на пакеты
         $account_chunks = array_chunk($account_ids, $batch_size, true);
         
         foreach ($account_chunks as $batch_index => $batch_accounts) {
             $batch_info = array(
                 'batch_number' => $batch_index,
                 'total_accounts' => count($batch_accounts),
                 'accounts' => array(),
                 'status' => 'pending', // pending, processing, completed, error
                 'completed_count' => 0,
                 'success_count' => 0,
                 'failed_count' => 0,
                 'processing_count' => 0
             );
             
             // Анализируем каждый счет в пакете
             foreach ($batch_accounts as $account_id) {
                 $account_info = $this->get_account_processing_info($account_id, $accounts_processing_data);
                 $batch_info['accounts'][] = $account_info;
                 
                 // Подсчитываем статистику пакета
                 switch ($account_info['processing_status']) {
                     case 'success':
                         $batch_info['success_count']++;
                         $batch_info['completed_count']++;
                         break;
                     case 'failed':
                         $batch_info['failed_count']++;
                         $batch_info['completed_count']++;
                         break;
                     case 'processing':
                         $batch_info['processing_count']++;
                         break;
                 }
             }
             
             // Определяем общий статус пакета
             $total_accounts_in_batch = count($batch_accounts);
             if ($batch_info['processing_count'] > 0) {
                 $batch_info['status'] = 'processing';
             } elseif ($batch_info['completed_count'] == $total_accounts_in_batch) {
                 $batch_info['status'] = 'completed';
             } elseif ($batch_info['completed_count'] > 0) {
                 $batch_info['status'] = 'partial';
             } else {
                 $batch_info['status'] = 'pending';
             }
             
             $details['batches'][] = $batch_info;
         }
         
         return $details;
     }
     
     /**
      * Получает информацию о статусе обработки счета в очереди
      * 
      * @param int $account_id ID счета
      * @param array $accounts_processing_data Данные об обработке счетов из статуса очереди
      * @return array Информация о статусе обработки счета
      */
     private function get_account_processing_info($account_id, $accounts_processing_data) {
         global $wpdb;
         
         // Получаем базовую информацию о счете из базы данных
         $table_name = $wpdb->prefix . 'contest_members';
         $account = $wpdb->get_row($wpdb->prepare(
             "SELECT id, account_number, broker, platform 
              FROM $table_name 
              WHERE id = %d",
             $account_id
         ), ARRAY_A);
         
         $account_info = array(
             'id' => $account_id,
             'account_number' => $account ? $account['account_number'] : 'Не найден',
             'broker_name' => $account ? ($account['broker'] ?: 'Не указан') : '',
             'platform_name' => $account ? ($account['platform'] ?: 'Не указана') : '',
             'processing_status' => 'pending',
             'processing_message' => 'Ожидает обработки',
             'processing_start_time' => null,
             'processing_end_time' => null,
             'processing_duration' => 0,
             'error_description' => ''
         );
         
         // Если есть данные об обработке этого счета в очереди
         if (isset($accounts_processing_data[$account_id])) {
             $processing_data = $accounts_processing_data[$account_id];
             
             $account_info['processing_status'] = isset($processing_data['status']) ? $processing_data['status'] : 'pending';
             $account_info['processing_message'] = isset($processing_data['message']) ? $processing_data['message'] : '';
             $account_info['processing_start_time'] = isset($processing_data['start_time']) ? $processing_data['start_time'] : null;
             $account_info['processing_end_time'] = isset($processing_data['end_time']) ? $processing_data['end_time'] : null;
             $account_info['error_description'] = isset($processing_data['error_description']) ? $processing_data['error_description'] : '';
             
             // Вычисляем длительность обработки
             if ($account_info['processing_start_time'] && $account_info['processing_end_time']) {
                 $account_info['processing_duration'] = $account_info['processing_end_time'] - $account_info['processing_start_time'];
             } elseif ($account_info['processing_start_time'] && $account_info['processing_status'] == 'processing') {
                 $account_info['processing_duration'] = time() - $account_info['processing_start_time'];
                 $account_info['processing_message'] = 'Зависла в обработке';
             }
         }
         
         return $account_info;
     }
     
     /**
      * Удаляет одну конкретную очередь через AJAX
      */
     public function delete_single_queue() {
         check_ajax_referer('delete_queue_nonce');
         
         if (!current_user_can('manage_options')) {
             wp_send_json_error(array('error' => 'Недостаточно прав'));
         }
         
         $queue_id = sanitize_text_field($_POST['queue_id']);
         $status_option = sanitize_text_field($_POST['status_option']);
         $queue_option = sanitize_text_field($_POST['queue_option']);
         
         if (empty($queue_id) || empty($status_option)) {
             wp_send_json_error(array('error' => 'Недостаточно данных для удаления'));
         }
         
         global $wpdb;
         $deleted_items = array();
         
         try {
             // 1. Удаляем основную опцию статуса очереди
             if (delete_option($status_option)) {
                 $deleted_items[] = "Статус очереди ({$status_option})";
             }
             
             // 2. Удаляем опцию данных очереди
             if (!empty($queue_option) && delete_option($queue_option)) {
                 $deleted_items[] = "Данные очереди ({$queue_option})";
             }
             
             // 3. Удаляем записи из списков активных очередей
             // Извлекаем contest_id из названия опции
             if (preg_match('/contest_accounts_update_status_(\d+)_/', $status_option, $matches)) {
                 $contest_id = $matches[1];
                 $active_queues_option = "contest_active_queues_{$contest_id}";
                 
                 // Получаем список активных очередей
                 $active_queues = get_option($active_queues_option, array());
                 if (is_array($active_queues)) {
                     // Удаляем запись о нашей очереди
                     if (isset($active_queues[$queue_id])) {
                         unset($active_queues[$queue_id]);
                         $deleted_items[] = "Запись из активных очередей конкурса {$contest_id}";
                     }
                     
                     // Если список активных очередей стал пустым, удаляем всю опцию
                     if (empty($active_queues)) {
                         delete_option($active_queues_option);
                         $deleted_items[] = "Пустая опция активных очередей ({$active_queues_option})";
                     } else {
                         update_option($active_queues_option, $active_queues);
                     }
                 }
             }
             
             // 4. Ищем и удаляем любые другие опции, связанные с этой очередью
             $related_options = $wpdb->get_results($wpdb->prepare("
                 SELECT option_name 
                 FROM {$wpdb->options} 
                 WHERE option_name LIKE %s
             ", '%' . $wpdb->esc_like($queue_id) . '%'));
             
             foreach ($related_options as $option) {
                 if ($option->option_name !== $status_option && $option->option_name !== $queue_option) {
                     if (delete_option($option->option_name)) {
                         $deleted_items[] = "Связанная опция ({$option->option_name})";
                     }
                 }
             }
             
             // 5. Очищаем из cron задач, если есть
             $scheduled_events = _get_cron_array();
             $updated_cron = false;
             
             foreach ($scheduled_events as $timestamp => $cron) {
                 foreach ($cron as $hook => $events) {
                     if (strpos($hook, 'process_accounts_update_batch') !== false) {
                         foreach ($events as $key => $event) {
                             if (isset($event['args']) && is_array($event['args']) && 
                                 in_array($queue_id, $event['args'], true)) {
                                 unset($scheduled_events[$timestamp][$hook][$key]);
                                 $updated_cron = true;
                                 $deleted_items[] = "Запланированная задача cron";
                             }
                         }
                         
                         // Если не осталось событий для этого хука, удаляем хук
                         if (empty($scheduled_events[$timestamp][$hook])) {
                             unset($scheduled_events[$timestamp][$hook]);
                         }
                     }
                 }
                 
                 // Если не осталось хуков для этого времени, удаляем временную метку
                 if (empty($scheduled_events[$timestamp])) {
                     unset($scheduled_events[$timestamp]);
                 }
             }
             
             if ($updated_cron) {
                 _set_cron_array($scheduled_events);
             }
             
             // Логируем действие
             $user = wp_get_current_user();
             $deleted_summary = implode(', ', $deleted_items);
             error_log("УДАЛЕНИЕ ОЧЕРЕДИ: Администратор {$user->user_login} удалил очередь {$queue_id}. Удалены: {$deleted_summary}");
             
             wp_send_json_success(array(
                 'message' => "Очередь {$queue_id} полностью удалена. Удалено элементов: " . count($deleted_items),
                 'deleted_items' => $deleted_items,
                 'queue_id' => $queue_id
             ));
             
         } catch (Exception $e) {
             error_log("ОШИБКА УДАЛЕНИЯ ОЧЕРЕДИ: " . $e->getMessage());
             wp_send_json_error(array('error' => 'Ошибка при удалении: ' . $e->getMessage()));
         }
     }
     
     /**
      * Очищает сиротские опции очередей через AJAX
      */
     public function cleanup_orphaned_queues() {
         check_ajax_referer('cleanup_orphaned_nonce');
         
         if (!current_user_can('manage_options')) {
             wp_send_json_error(array('error' => 'Недостаточно прав'));
         }
         
         global $wpdb;
         $cleaned_items = array();
         $cleaned_count = 0;
         
         try {
             // 1. Находим все служебные опции contest_active_queues_*
             $active_queue_options = $wpdb->get_results($wpdb->prepare("
                 SELECT option_name, option_value 
                 FROM {$wpdb->options} 
                 WHERE option_name LIKE %s
             ", 'contest_active_queues_%'));
             
             foreach ($active_queue_options as $option) {
                 $active_queues_data = maybe_unserialize($option->option_value);
                 
                 if (is_array($active_queues_data)) {
                     $has_valid_queues = false;
                     $cleaned_queues = array();
                     
                     // Проверяем каждую очередь в списке
                     foreach ($active_queues_data as $queue_id => $queue_info) {
                         if (isset($queue_info['status_option'])) {
                             // Проверяем, существует ли статус очереди
                             $status_exists = get_option($queue_info['status_option'], false);
                             if ($status_exists !== false) {
                                 $cleaned_queues[$queue_id] = $queue_info;
                                 $has_valid_queues = true;
                             } else {
                                 $cleaned_items[] = "Удалена сиротская ссылка на очередь {$queue_id} из {$option->option_name}";
                                 $cleaned_count++;
                             }
                         }
                     }
                     
                     // Обновляем или удаляем опцию
                     if (!$has_valid_queues) {
                         // Если не осталось валидных очередей, удаляем всю опцию
                         delete_option($option->option_name);
                         $cleaned_items[] = "Удалена пустая опция {$option->option_name}";
                         $cleaned_count++;
                     } else if (count($cleaned_queues) < count($active_queues_data)) {
                         // Если удалили некоторые очереди, обновляем опцию
                         update_option($option->option_name, $cleaned_queues);
                     }
                 } else {
                     // Опция содержит некорректные данные
                     delete_option($option->option_name);
                     $cleaned_items[] = "Удалена поврежденная опция {$option->option_name}";
                     $cleaned_count++;
                 }
             }
             
             // 2. Находим и удаляем опции очередей без статуса
             $queue_data_options = $wpdb->get_results($wpdb->prepare("
                 SELECT option_name 
                 FROM {$wpdb->options} 
                 WHERE option_name LIKE %s
             ", 'contest_accounts_update_queue_%'));
             
             foreach ($queue_data_options as $option) {
                 // Извлекаем соответствующее имя статуса
                 $status_option_name = str_replace('contest_accounts_update_queue_', 'contest_accounts_update_status_', $option->option_name);
                 
                 // Проверяем, существует ли статус
                 $status_exists = get_option($status_option_name, false);
                 if ($status_exists === false) {
                     delete_option($option->option_name);
                     $cleaned_items[] = "Удалена сиротская опция данных очереди {$option->option_name}";
                     $cleaned_count++;
                 }
             }
             
             // 3. Очищаем старые cron задачи без соответствующих очередей
             $scheduled_events = _get_cron_array();
             $updated_cron = false;
             $cron_cleaned = 0;
             
             foreach ($scheduled_events as $timestamp => $cron) {
                 foreach ($cron as $hook => $events) {
                     if (strpos($hook, 'process_accounts_update_batch') !== false) {
                         foreach ($events as $key => $event) {
                             if (isset($event['args']) && is_array($event['args']) && count($event['args']) >= 2) {
                                 $contest_id = $event['args'][0];
                                 $queue_id = $event['args'][1];
                                 $status_option = "contest_accounts_update_status_{$contest_id}_{$queue_id}";
                                 
                                 // Проверяем, существует ли очередь
                                 $queue_exists = get_option($status_option, false);
                                 if ($queue_exists === false) {
                                     unset($scheduled_events[$timestamp][$hook][$key]);
                                     $updated_cron = true;
                                     $cron_cleaned++;
                                 }
                             }
                         }
                         
                         // Если не осталось событий для этого хука, удаляем хук
                         if (empty($scheduled_events[$timestamp][$hook])) {
                             unset($scheduled_events[$timestamp][$hook]);
                         }
                     }
                 }
                 
                 // Если не осталось хуков для этого времени, удаляем временную метку
                 if (empty($scheduled_events[$timestamp])) {
                     unset($scheduled_events[$timestamp]);
                 }
             }
             
             if ($updated_cron) {
                 _set_cron_array($scheduled_events);
                 $cleaned_items[] = "Очищено {$cron_cleaned} сиротских cron задач";
                 $cleaned_count += $cron_cleaned;
             }
             
             // Логируем действие
             $user = wp_get_current_user();
             error_log("ОЧИСТКА СИРОТСКИХ ОПЦИЙ: Администратор {$user->user_login} очистил {$cleaned_count} сиротских опций очередей");
             
             wp_send_json_success(array(
                 'cleaned_count' => $cleaned_count,
                 'details' => $cleaned_items,
                 'message' => "Очистка завершена. Удалено {$cleaned_count} сиротских элементов."
             ));
             
         } catch (Exception $e) {
             error_log("ОШИБКА ОЧИСТКИ СИРОТСКИХ ОПЦИЙ: " . $e->getMessage());
             wp_send_json_error(array('error' => 'Ошибка при очистке: ' . $e->getMessage()));
         }
     }
     
     /**
      * Получает статус всех очередей (упрощенная версия без защиты)
      */
     public function get_all_queues_status() {
         global $wpdb;
         
         // Ищем все опции очередей
         $queue_options = $wpdb->get_results("
             SELECT option_name, option_value 
             FROM {$wpdb->options} 
             WHERE option_name LIKE 'contest_accounts_update_status_%'
         ");
         
         $active_queues = array();
         $inactive_queues = array();
         $all_queues = array();
         
         foreach ($queue_options as $option) {
             $queue_data = maybe_unserialize($option->option_value);
             
             if (!is_array($queue_data)) {
                 continue;
             }
             
             $queue_info = array(
                 'option_name' => $option->option_name,
                 'data' => $queue_data
             );
             
             $all_queues[] = $queue_info;
             
             if (isset($queue_data['is_running']) && $queue_data['is_running']) {
                 $active_queues[] = $queue_info;
             } else {
                 $inactive_queues[] = $queue_info;
             }
         }
         
         return array(
             'active_queue' => !empty($active_queues) ? $active_queues[0]['data'] : null,
             'active_queues_count' => count($active_queues),
             'inactive_queues_count' => count($inactive_queues),
             'all_active_queues' => $active_queues,
             'all_inactive_queues' => $inactive_queues,
             'all_queues' => $all_queues,
             'cleanup_count' => 0,
             'last_cleanup' => null
         );
     }
     
     /**
      * Получает статистику очистки (упрощенная версия)
      */
     public function get_cleanup_stats() {
         return array();
     }

    /**
     * Запускает создание очередей вне расписания (внепланово)
     */
    public function run_manual_queue() {
        check_ajax_referer('run_manual_queue');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('error' => 'Недостаточно прав'));
        }
        
        // Получаем количество активных очередей до запуска
        if (class_exists('Account_Updater')) {
            $before = Account_Updater::get_all_active_queues();
            $before_count = isset($before['total_running']) ? intval($before['total_running']) : 0;
            
            // Устанавливаем флаг принудительного запуска
            $GLOBALS['force_auto_update_flag'] = true;
            Account_Updater::run_auto_update();
            
            // Обновляем время последнего запуска очереди
            $current_time = time();
            update_option('contest_create_queues_last_run', $current_time);
            
            // Принудительно обновляем cron-расписание
            $auto_update_settings = get_option('fttrader_auto_update_settings', []);
            $interval_minutes = isset($auto_update_settings['fttrader_auto_update_interval']) ? 
                               intval($auto_update_settings['fttrader_auto_update_interval']) : 65;
            $next_run = $current_time + ($interval_minutes * 60);
            
            // Очищаем старое расписание и создаём новое
            wp_clear_scheduled_hook('contest_create_queues');
            wp_schedule_single_event($next_run, 'contest_create_queues');
            
            // Считаем активные очереди после запуска
            $after = Account_Updater::get_all_active_queues();
            $after_count = isset($after['total_running']) ? intval($after['total_running']) : 0;
            
            $new_queues = max(0, $after_count - $before_count);
            
            wp_send_json_success(array(
                'message' => sprintf('Создано очередей: %d (было %d, стало %d). Следующий запуск: %s', 
                    $new_queues, $before_count, $after_count, date('d.m.Y H:i:s', $next_run))
            ));
        } else {
            wp_send_json_error(array('error' => 'Класс Account_Updater не найден'));
        }
    }

    /**
     * AJAX обработчик для получения времени следующего cron-события
     */
    public function get_next_cron_time() {
        check_ajax_referer('get_next_cron_time');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('error' => 'Недостаточно прав'));
        }
        
        $next_queue_ts = wp_next_scheduled('contest_create_queues');
        
        // Получаем настройки и время последнего запуска
        $auto_update_settings = get_option('fttrader_auto_update_settings', []);
        $interval_minutes = isset($auto_update_settings['fttrader_auto_update_interval']) ? 
                           intval($auto_update_settings['fttrader_auto_update_interval']) : 65;
        $last_run = get_option('contest_create_queues_last_run', 0);
        
        // «Идеальное» время — строго last_run + interval
        if ($last_run > 0) {
            $calculated_next = $last_run + ($interval_minutes * 60);
            while ($calculated_next <= time()) {
                $calculated_next += ($interval_minutes * 60);
            }
        } else {
            $calculated_next = time() + ($interval_minutes * 60);
        }
        
        // Если cron отсутствует или запланирован НЕ на calculated_next, перенастраиваем
        if (!$next_queue_ts || $next_queue_ts !== $calculated_next) {
             if (class_exists('Account_Updater')) {
                 // Сначала очищаем старое расписание
                 wp_clear_scheduled_hook('contest_create_queues');
                 // Создаём новое на правильное время
                 wp_schedule_single_event($calculated_next, 'contest_create_queues');
                 $next_queue_ts = $calculated_next;
                 error_log("Queue Admin: Cron rescheduled to calculated_next: " . date('Y-m-d H:i:s', $calculated_next));
             }
         }
        
        wp_send_json_success(array(
            'timestamp' => $next_queue_ts ? $next_queue_ts : null,
            'formatted' => $next_queue_ts ? date('d.m.Y H:i:s', $next_queue_ts) : 'не запланировано',
            'debug' => array(
                'current_time' => time(),
                'scheduled_time' => $next_queue_ts,
                'calculated_next' => $calculated_next,
                'diff' => $next_queue_ts ? ($next_queue_ts - time()) : 0,
                'last_run' => get_option('contest_create_queues_last_run', 0),
                'interval_minutes' => isset($auto_update_settings['fttrader_auto_update_interval']) ? 
                                     intval($auto_update_settings['fttrader_auto_update_interval']) : 65
            )
        ));
    }

    /**
     * AJAX: Принудительный запуск WP Cron
     */
    public function ajax_trigger_wp_cron() {
        check_ajax_referer('trigger_wp_cron');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('error' => 'Недостаточно прав'));
        }

        // Запускаем cron
        spawn_cron();

        // Для наглядности возвращаем количество задач конкурсов
        $crons = _get_cron_array();
        $contest_tasks = 0;
        foreach ($crons as $timestamp => $hooks) {
            foreach ($hooks as $hook => $events) {
                if (in_array($hook, array('contest_create_queues', 'process_accounts_update_batch'))) {
                    $contest_tasks += count($events);
                }
            }
        }
        wp_send_json_success(array('message' => 'wp-cron.php запущен', 'tasks' => $contest_tasks));
    }
} 