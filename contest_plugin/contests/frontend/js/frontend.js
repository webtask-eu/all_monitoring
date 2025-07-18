/**
 * Frontend JavaScript для плагина конкурсов трейдеров
 * Version: 2.1.0 (Updated: 2025-01-08) - Исправлено кеширование AJAX запросов
 */

jQuery(document).ready(function($) {
    console.log('%c🔄 Frontend Scripts v2.1.0 загружены (08.01.2025)', 'background:#27ae60;color:white;padding:4px 8px;border-radius:3px;');
    // Отладочная информация о загрузке скрипта
    console.log('[DEBUG] frontend.js загружен');
    
    // Проверка наличия переменной ftContestData
    if (typeof ftContestData === 'undefined') {
        console.error('[КРИТИЧЕСКАЯ ОШИБКА] Переменная ftContestData не определена. Каскадные выпадающие списки не будут работать.');
        // Создаем временную переменную для избежания ошибок
        window.ftContestData = {
            ajax_url: '/wp-admin/admin-ajax.php',
            nonce: ''
        };
    } else {
        console.log('[DEBUG] ftContestData загружен:', ftContestData);
    }

    // Обработка всех форм регистрации счетов
    $('#contest-account-form, #edit-account-form').on('submit', function(e) {
        // Удаляем пробелы из полей ввода
        $(this).find('input[type="text"], input[type="password"]').each(function() {
            $(this).val($.trim($(this).val()));
        });
        
        // Особо важные поля обрабатываем отдельно для уверенности
        $('#account_number, #password, #server, #terminal').each(function() {
            if ($(this).length) {
                $(this).val($.trim($(this).val()));
            }
        });
    });

    // Обработчик клика по кнопке обновления
    $('.update-account-button').on('click', function() {
        var $button = $(this);
        var $statusElement = $('.update-status');
        
        $button.prop('disabled', true);
        $statusElement.text('Обновление данных...');
        
        $.ajax({
            url: ftAccountData.ajaxurl,
            type: 'POST',
            data: {
                action: 'update_account_frontend',
                account_id: ftAccountData.account_id,
                nonce: ftAccountData.nonce  // Важно! Правильный nonce
            },
            success: function(response) {
                if (response.success) {
                    $statusElement.text('Данные успешно обновлены!');
                    setTimeout(function() {
                        location.reload();
                    }, 1000);
                } else {
                    $statusElement.text('Ошибка: ' + response.data.message);
                    $button.prop('disabled', false);
                }
            },
            error: function() {
                $statusElement.text('Произошла ошибка при обновлении данных.');
                $button.prop('disabled', false);
            }
        });
    });

    if ($('.contests-archive-container').length > 0) {
        // Анимация счетчиков
        function animateCounters() {
            $('.animated-counter').each(function() {
                var $this = $(this);
                var finalValue = parseInt($this.data('value'));
                
                // Если это денежное значение, добавляем символ валюты
                var isCurrency = $this.hasClass('prize');
                
                // Определяем длительность анимации в зависимости от величины числа
                var duration = Math.min(Math.max(finalValue / 100, 1), 3) * 1000;
                
                // Используем jQuery animate для плавного увеличения числа
                $({ Counter: 0 }).animate({
                    Counter: finalValue
                }, {
                    duration: duration,
                    easing: 'swing',
                    step: function() {
                        var currentValue = Math.ceil(this.Counter);
                        $this.text(isCurrency ? '$' + currentValue.toLocaleString() : currentValue.toLocaleString());
                    },
                    complete: function() {
                        // Убедимся, что в конце отображается точное значение
                        $this.text(isCurrency ? '$' + finalValue.toLocaleString() : finalValue.toLocaleString());
                    }
                });
            });
        }
        
        // Запускаем анимацию счетчиков при загрузке страницы
        animateCounters();
        
        // Обновление счетчика времени для активных конкурсов
        function updateTimeCounters() {
            $('.contest-card.active').each(function() {
                const $card = $(this);
                const $days = $card.find('.time-block:eq(0) .time-value');
                const $hours = $card.find('.time-block:eq(1) .time-value');
                const $minutes = $card.find('.time-block:eq(2) .time-value');
                
                // Получаем текущие значения
                let days = parseInt($days.text());
                let hours = parseInt($hours.text());
                let minutes = parseInt($minutes.text());
                
                // Уменьшаем минуты
                minutes--;
                
                // Обрабатываем переносы
                if (minutes < 0) {
                    minutes = 59;
                    hours--;
                    
                    if (hours < 0) {
                        hours = 23;
                        days--;
                        
                        if (days < 0) {
                            // Конкурс завершен, обновляем страницу
                            location.reload();
                            return;
                        }
                    }
                }
                
                // Обновляем значения на странице
                $days.text(days);
                $hours.text(hours);
                $minutes.text(minutes);
            });
        }
        
        // Запускаем обновление счетчика каждую минуту
        if ($('.contest-card.active').length > 0) {
            setInterval(updateTimeCounters, 60000);
        }
        
        // Функция для периодического обновления данных через AJAX
        function updateContestData() {
            $.ajax({
                url: ftContestData.ajax_url,
                type: 'POST',
                data: {
                    action: 'update_contests_data',
                    nonce: ftContestData.nonce
                },
                success: function(response) {
                    if (response.success) {
                        // Добавляем отладочный вывод в консоль
                        console.log('Данные с сервера:', response.data);
                        
                        // Обновляем общую статистику
                        if (response.data.total_prize_fund) {
                            console.log('Сумма призового фонда до форматирования:', response.data.total_prize_fund);
                            // Используем parseFloat вместо parseInt для корректной обработки десятичных чисел
                            var prizeFund = parseFloat(response.data.total_prize_fund);
                            console.log('Сумма призового фонда после parseFloat:', prizeFund);
                            $('#total-prize-fund').text('$' + prizeFund.toLocaleString());
                        }
                        
                        if (response.data.total_participants) {
                            // Используем parseFloat для согласованности
                            var participants = parseFloat(response.data.total_participants);
                            $('#total-participants').text(participants.toLocaleString());
                        }
                        
                        if (response.data.active_contests) {
                            // Используем parseFloat для согласованности
                            var activeContests = parseFloat(response.data.active_contests);
                            $('#active-contests').text(activeContests.toLocaleString());
                        }
                        
                        // Обновляем количество участников для каждого конкурса
                        if (response.data.contests) {
                            $.each(response.data.contests, function(contest_id, participants) {
                                $('[data-contest-id="' + contest_id + '"]').text(participants);
                            });
                        }
                        
                        // Обновляем лидеров, если они изменились
                        if (response.data.top_leaders) {
                            var $leadersContainer = $('.top-leaders');
                            $leadersContainer.find('.leader-item').remove();
                            
                            $.each(response.data.top_leaders, function(index, leader) {
                                var profitClass = parseFloat(leader.profit_percent) >= 0 ? 'positive' : 'negative';
                                var rankClass = 'top-' + (index + 1);
                                
                                var leaderHtml = '<div class="leader-item">' +
                                    '<div class="leader-rank ' + rankClass + '">' + (index + 1) + '</div>' +
                                    '<div class="leader-info">' +
                                    '<div class="leader-name">' + leader.display_name + '</div>' +
                                    '<div class="leader-contest">' + leader.contest_title + '</div>' +
                                    '</div>' +
                                    '<div class="leader-profit ' + profitClass + '">' + leader.profit_percent + '%</div>' +
                                    '</div>';
                                
                                $leadersContainer.append(leaderHtml);
                            });
                        }
                    }
                }
            });
        }
        
        // Обновляем данные каждые 5 минут
        setInterval(updateContestData, 300000);
    }
    
    // Обработчик для кнопки обновления данных счета на странице счета
    $('#refresh-account-data').on('click', function() {
        const $button = $(this);
        const $status = $('#refresh-status');
        const accountId = $button.data('account-id');
        
        // Выводим в консоль параметры запроса
        console.log('%c🔍 Запрос на обновление счета', 'background:#2980b9;color:white;padding:4px 8px;border-radius:3px;font-weight:bold;');
        console.log('📄 Параметры запроса:', {
            url: ftContestData.ajax_url,
            action: 'update_contest_account_data',
            nonce: ftContestData.nonce,
            account_id: accountId
        });
        
        // Блокируем кнопку и показываем сообщение
        $button.prop('disabled', true).text('Обновление...');
        $status.text('Получение данных счета...').removeClass('error success');
        
        // Отладочное сообщение перед отправкой запроса
        console.log('[DEBUG] Начинаем обновление данных счета ' + accountId + ' с сервера');
        
        // Сохраняем XHR объект для возможности доступа к нему из обоих обработчиков
        let xhrObject;
        
        // Отправляем запрос на обновление данных счета
        $.ajax({
            url: ftContestData.ajax_url,
            type: 'POST',
            data: {
                action: 'update_contest_account_data', // Используем существующий метод для обновления счета
                nonce: ftContestData.nonce,
                account_id: accountId
            },
            beforeSend: function(xhr) {
                console.log('[DEBUG] Отправка AJAX запроса на сервер...');
                // Сохраняем объект XHR для глобального доступа
                xhrObject = xhr;
            },
            success: function(response) {
                console.log('%c✅ Получен ответ от сервера', 'background:#27ae60;color:white;padding:4px 8px;border-radius:3px;');
                console.log('📥 Полный ответ сервера:', response);
                
                // Проверяем наличие queue_batch_id в ответе
                if (response && response.data && response.data.queue_batch_id) {
                    console.log('%c🆔 Queue Batch ID: ' + response.data.queue_batch_id, 
                        'background:#8e44ad;color:white;padding:4px 8px;border-radius:3px;font-weight:bold;');
                }
                
                // Проверяем наличие queue_batch_id в заголовках
                if (xhrObject && typeof xhrObject.getResponseHeader === 'function') {
                    try {
                        const queueBatchId = xhrObject.getResponseHeader('X-Queue-Batch-ID');
                        if (queueBatchId) {
                            console.log('%c🆔 Queue Batch ID из заголовка: ' + queueBatchId, 
                                'background:#8e44ad;color:white;padding:4px 8px;border-radius:3px;font-weight:bold;');
                        }
                    } catch (e) {
                        console.log('Не удалось получить заголовок X-Queue-Batch-ID:', e);
                    }
                }
                
                if (response && response.success) {
                    console.log('[DEBUG] Успешное обновление данных счета');
                    $status.text('Данные успешно обновлены!').addClass('success');
                    
                    // Асинхронное обновление ключевых данных без перезагрузки страницы
                    if (response.data && response.data.account_data) {
                        var accountData = response.data.account_data;
                        
                        // Обновляем отображаемые данные счета
                        if (accountData.balance) {
                            $('.account-balance-value').text(parseFloat(accountData.balance).toFixed(2) + ' ' + (accountData.currency || 'USD'));
                        }
                        
                        if (accountData.equity) {
                            $('.account-equity-value').text(parseFloat(accountData.equity).toFixed(2) + ' ' + (accountData.currency || 'USD'));
                        }
                        
                        if (accountData.margin) {
                            $('.account-margin-value').text(parseFloat(accountData.margin).toFixed(2) + ' ' + (accountData.currency || 'USD'));
                        }
                        
                        if (accountData.profit) {
                            $('.account-profit-value').text(parseFloat(accountData.profit).toFixed(2) + ' ' + (accountData.currency || 'USD'));
                        }
                        
                        // Обновляем дату последнего обновления
                        var now = new Date();
                        $('.account-updated').text('Обновлено: только что')
                            .removeClass('moderate stale')
                            .addClass('recent')
                            .data('timestamp', now.getTime());
                        
                        // Обновляем статус подключения
                        if (accountData.connection_status) {
                            var $statusIndicator = $('.account-status-indicator');
                            $statusIndicator.removeClass('connected disconnected disqualified');
                            
                            if (accountData.connection_status === 'connected') {
                                $statusIndicator.addClass('connected').text('Подключен');
                            } else if (accountData.connection_status === 'disconnected') {
                                $statusIndicator.addClass('disconnected').text('Отключен');
                                
                                // Показываем ошибку, если она есть
                                if (accountData.error_description) {
                                    var $errorBox = $('.account-connection-error');
                                    if ($errorBox.length === 0) {
                                        $errorBox = $('<div class="account-connection-error"></div>').insertAfter($statusIndicator);
                                    }
                                    $errorBox.html('<strong>Информация об ошибке:</strong><br>' + accountData.error_description);
                                }
                            } else if (accountData.connection_status === 'disqualified') {
                                $statusIndicator.addClass('disqualified').text('Дисквалифицирован');
                            }
                        }
                    }
                    
                    // Перезагружаем страницу с уменьшенной задержкой для обновления остальных данных
                    setTimeout(function() {
                        console.log('[DEBUG] Перезагрузка страницы для отображения новых данных');
                        window.location.reload();
                    }, 500); // Уменьшаем с 1500мс до 500мс для более быстрого обновления
                } else {
                    $button.prop('disabled', false).text('Обновить данные счета');
                    
                    // Отладочная информация об ошибке
                    console.log('%c❌ Ошибка обновления данных:', 'background:#e74c3c;color:white;padding:4px 8px;border-radius:3px;');
                    console.log('📄 Детали ошибки:', response);
                    
                    // Проверяем наличие queue_batch_id в ответе с ошибкой
                    if (response && response.data && response.data.queue_batch_id) {
                        console.log('%c🆔 Queue Batch ID: ' + response.data.queue_batch_id, 
                            'background:#8e44ad;color:white;padding:4px 8px;border-radius:3px;font-weight:bold;');
                    }
                    
                    // Безопасная обработка ответа
                    let errorText = 'Сервер вернул пустой ответ. Проверьте логи сервера.';
                    
                    if (response && response.data) {
                        if (typeof response.data === 'string') {
                            errorText = response.data;
                        } else if (response.data.message) {
                            errorText = response.data.message;
                            
                            // Особая обработка для дисквалифицированных счетов
                            if (response.data.disqualified === true) {
                                console.log('%c⚠️ Счет дисквалифицирован', 'background:red;color:white;padding:4px 8px;border-radius:3px;font-weight:bold;');
                                
                                let disqualificationReason = response.data.error_description;
                                if (disqualificationReason) {
                                    console.log('Причина дисквалификации:', disqualificationReason);
                                    errorText = 'Счет дисквалифицирован. Причина: ' + disqualificationReason;
                                } else {
                                    errorText = 'Счет дисквалифицирован. Причина не указана.';
                                }
                            }
                            
                            // Заменяем сложные технические сообщения на более дружественные
                            if (errorText.includes('API сервер недоступен') || 
                                errorText.includes('HTTP 500') ||
                                errorText.includes('внутренняя ошибка сервера')) {
                                errorText = 'Сервер временно недоступен. Идет обновление системы. Пожалуйста, попробуйте снова через 5-10 минут.';
                            }
                            
                            // Добавляем расширенную отладочную информацию в консоль
                            if (response.data.debug_info) {
                                console.log('%c🔍 Отладочная информация:', 'background:#3498db;color:white;padding:4px 8px;border-radius:3px;');
                                console.log(response.data.debug_info);
                            }
                            
                            // Добавляем информацию о статусе аккаунта, если она доступна
                            if (response.data.account_status) {
                                console.log('%c👤 Статус аккаунта:', 'background:#9b59b6;color:white;padding:4px 8px;border-radius:3px;');
                                console.log(response.data.account_status);
                            }
                        } else {
                            // Если нет сообщения, но есть структура данных
                            errorText = JSON.stringify(response.data);
                        }
                    }
                    
                    // Добавляем дополнительную проверку соединения с API сервером
                    if (errorText.includes('пустой ответ') || errorText.includes('некорректный ответ')) {
                        // Проверка доступности API сервера
                        console.log('%c⚠️ Возможная проблема соединения с API-сервером', 'background:#f39c12;color:white;padding:4px 8px;border-radius:3px;font-weight:bold;');
                        
                        // Показываем расширенное сообщение пользователю с советами по устранению
                        errorText += '. Возможно, API-сервер недоступен. Рекомендации: 1) Попробуйте повторить запрос позже; 2) Проверьте подключение к интернету; 3) Обратитесь к администратору сайта.';
                    }
                    
                    $status.text('Ошибка: ' + errorText).addClass('error');
                }
            },
            error: function(xhr, status, error) {
                console.error('%c❌ AJAX ошибка:', 'background:#e74c3c;color:white;padding:4px 8px;border-radius:3px;');
                console.error('📄 Детали:', {
                    status: status,
                    error: error,
                    response: xhr.responseText
                });
                
                // Проверяем наличие queue_batch_id в заголовках даже при ошибке
                if (xhr && typeof xhr.getResponseHeader === 'function') {
                    try {
                        const queueBatchId = xhr.getResponseHeader('X-Queue-Batch-ID');
                        if (queueBatchId) {
                            console.log('%c🆔 Queue Batch ID из заголовка: ' + queueBatchId, 
                                'background:#8e44ad;color:white;padding:4px 8px;border-radius:3px;font-weight:bold;');
                        }
                    } catch (e) {
                        console.log('Не удалось получить заголовок X-Queue-Batch-ID:', e);
                    }
                }
                
                $button.prop('disabled', false).text('Обновить данные счета');
                $status.html('Ошибка соединения: ' + status).addClass('error');
            }
        });
    });
    
    // Обработчики для страницы отдельного счета
    if ($('.account-single-container').length > 0) {
        // Переменная для хранения текущей страницы пагинации
        var currentHistoryPage = 1;

        // Улучшенная функция загрузки истории изменений счета с фильтрами и пагинацией
        function loadAccountHistory(page) {
            if (!$('#account-history-wrapper').length) return;

            if (page) {
                currentHistoryPage = page;
            }

            // Получаем ID счета
            var accountIdValue = accountId || $('#account_id').val() || 0;
            
            // Подготавливаем данные для запроса с фильтрами и пагинацией
            var data = {
                action: 'load_account_history',
                account_id: accountIdValue,
                field: $('#field_filter').val() || '',
                period: $('#period_filter').val() || 'day',
                sort: $('#sort_date').data('sort') || 'desc',
                page: currentHistoryPage,
                per_page: 10,
                _timestamp: Date.now() // Принудительная очистка кеша
            };
            
            // Добавляем индикатор загрузки
            $('#account-history-wrapper').addClass('loading');

            $.ajax({
                url: ftContestData.ajax_url,
                type: 'POST',
                data: data,
                success: function(response) {
                    $('#account-history-wrapper').html(response).removeClass('loading');
                },
                error: function() {
                    $('#account-history-wrapper').html('<p class="error">Ошибка при загрузке истории</p>').removeClass('loading');
                }
            });
        }

        // Обработчики событий для фильтров истории (сбрасывают страницу на первую)
        $('.history-filter').on('change', function() {
            currentHistoryPage = 1;
            loadAccountHistory();
        });
        
        $('#sort_date').on('click', function() {
            var $btn = $(this);
            var currentSort = $btn.data('sort') || 'desc';
            var newSort = currentSort === 'desc' ? 'asc' : 'desc';
            
            $btn.data('sort', newSort);
            $btn.find('.dashicons')
                .toggleClass('dashicons-arrow-down-alt2 dashicons-arrow-up-alt2');
            
            currentHistoryPage = 1;
            loadAccountHistory();
        });

        // Обработчик для кнопок пагинации (используем делегирование события)
        $(document).on('click', '.history-page-btn', function(e) {
            e.preventDefault();
            var page = parseInt($(this).data('page'));
            if (page && page !== currentHistoryPage) {
                loadAccountHistory(page);
            }
        });

        // Начальная загрузка истории
        loadAccountHistory();

        // Обработчик удаления счета
        if (!$._data(document.getElementById('delete-account-data'), 'events')) {
            $('#delete-account-data').on('click', function() {
                if (!confirm('Вы действительно хотите удалить этот счет? Это действие нельзя отменить.')) {
                    return;
                }
                
                const $button = $(this);
                const $status = $('#delete-status');
                const accountId = $button.data('account-id');
                const contestId = $button.data('contest-id');
                
                // Блокируем кнопку и показываем сообщение
                $button.prop('disabled', true).text('Удаление...');
                $status.text('Удаление счета...').removeClass('error success');
                
                // Отправляем запрос на удаление
                $.ajax({
                    url: ftContestData.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'fttradingapi_delete_account',
                        id: accountId,
                        nonce: ftContestData.nonce
                    },
                    success: function(response) {
                        if (response.success) {
                            $status.text('Счет успешно удален!').addClass('success');
                            
                            // Перенаправляем на страницу конкурса
                            setTimeout(function() {
                                window.location.href = response.data.redirect || window.location.href.split('?')[0];
                            }, 1500);
                        } else {
                            $button.prop('disabled', false).text('Удалить счет');
                            $status.text('Ошибка: ' + (response.data || 'Неизвестная ошибка')).addClass('error');
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error('[DEBUG] AJAX ошибка:', {
                            status: status,
                            error: error,
                            response: xhr.responseText
                        });
                        
                        $button.prop('disabled', false).text('Удалить счет');
                        $status.text('Ошибка соединения: ' + status).addClass('error');
                    }
                });
            });
        }
        
        // Обработчик очистки истории сделок
        if (!$._data(document.getElementById('clear-order-history'), 'events')) {
            $('#clear-order-history').on('click', function() {
                if (!confirm('ВНИМАНИЕ! Вы собираетесь удалить все сделки (открытые позиции и историю) этого счета. После удаления данные будут восстановлены при следующем обновлении данных счета. Продолжить?')) {
                    return;
                }
                
                const $button = $(this);
                const $status = $('#clear-order-status');
                const accountId = $button.data('account-id');
                
                // Блокируем кнопку и показываем сообщение
                $button.prop('disabled', true).text('Удаление...');
                $status.text('Удаление сделок...').removeClass('error success');
                
                // Отправляем запрос на удаление истории сделок
                $.ajax({
                    url: ftContestData.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'clear_order_history',
                        account_id: accountId,
                        nonce: ftContestData.nonce
                    },
                    success: function(response) {
                        if (response.success) {
                            $status.text(response.data.message).addClass('success');
                            // Очищаем таблицы сделок
                            $('.account-open-orders tbody').html('');
                            $('.account-order-history tbody').html('');
                            // Показываем сообщение о пустых таблицах
                            if ($('.account-open-orders .no-orders').length === 0) {
                                $('.account-open-orders table').after('<p class="no-orders">Нет открытых позиций.</p>');
                            }
                            if ($('.account-order-history .no-orders').length === 0) {
                                $('.account-order-history table').after('<p class="no-orders">История сделок пуста.</p>');
                            }
                            // Скрываем таблицы
                            $('.orders-table-container').hide();
                        } else {
                            $status.text('Ошибка: ' + (response.data ? response.data.message : 'Не удалось очистить сделки')).addClass('error');
                        }
                    },
                    error: function() {
                        $status.text('Ошибка соединения при удалении сделок').addClass('error');
                    },
                    complete: function() {
                        $button.prop('disabled', false).text('Удалить сделки');
                    }
                });
            });
        }
        
        // Обработчик показа формы редактирования счета
        $('#show-edit-account-form').on('click', function() {
            console.log('[DEBUG] Кнопка редактирования нажата');
            // Показываем форму
            $('#edit-account-form-container').slideDown(300);
            
            // Ждем немного, чтобы форма успела показаться
            setTimeout(function() {
                var $formContainer = $('#edit-account-form-container');
                // Проверяем, что элемент существует и видим
                if ($formContainer.length && $formContainer.is(':visible')) {
                    $('html, body').animate({
                        scrollTop: $formContainer.offset().top - 50
                    }, 500);
                }
            }, 350);
        });
        
        // Обработчик отмены редактирования
        $('#cancel-edit-account').on('click', function(e) {
            e.preventDefault();
            $('#edit-account-form-container').slideUp(300);
        });
        
        // Обработчик отправки формы редактирования счета
        $('#edit-account-form').on('submit', function(e) {
            e.preventDefault();
            
            var form = $(this);
            var messageDiv = $('#edit-form-message');
            
            // Показываем сообщение о загрузке
            messageDiv.html('Отправка данных...').removeClass('error success').addClass('info').show();
            
            // Собираем данные формы
            var formData = {
                action: 'update_contest_account_data',
                nonce: form.find('[name="nonce"]').val(),
                account_id: form.find('[name="account_id"]').val(),
                contest_id: form.find('[name="contest_id"]').val(),
                password: form.find('[name="password"]').val(),
                server: form.find('[name="server"]').val(),
                terminal: form.find('[name="terminal"]').val()
            };
            
            console.log('[DEBUG] Отправка формы редактирования счета с данными:', formData);
            
            // Отправляем AJAX запрос
            $.ajax({
                url: ftContestData.ajax_url,
                type: 'POST',
                data: formData,
                success: function(response) {
                    console.log('[DEBUG] Получен ответ:', response);
                    
                    if (response.success) {
                        messageDiv.html(response.data.message).removeClass('error info').addClass('success');
                        
                        // Перезагружаем страницу через 2 секунды для обновления данных
                        setTimeout(function() {
                            window.location.reload();
                        }, 2000);
                    } else {
                        messageDiv.html(response.data || 'Произошла ошибка').removeClass('success info').addClass('error');
                    }
                },
                error: function(xhr, status, error) {
                    console.error('[DEBUG] AJAX ошибка:', xhr.responseText);
                    messageDiv.html('Произошла ошибка при отправке данных. Пожалуйста, попробуйте еще раз.').removeClass('success info').addClass('error');
                }
            });
        });
    }

    // Инициализация графика счета
    if ($('#accountChart').length > 0) {
        // Явно установим ID счета из параметра URL, если он не был установлен
        if (!$('#accountChart').attr('data-account-id') && typeof accountId !== 'undefined') {
            $('#accountChart').attr('data-account-id', accountId);
        }
        
        // Проверяем наличие объекта AccountChart
        if (typeof AccountChart !== 'undefined') {
            // Используем функции AccountChart для инициализации и управления графиком
            console.log('[DEBUG] AccountChart найден, инициализация...');
            
            // Прямой вызов инициализации, если она еще не выполнена
            if (!AccountChart.initialized) {
                AccountChart.init();
            }
            
            // Добавляем хук для расчета просадки
            // Версия v2 - используем уже встроенную функцию calculateDrawdown в AccountChart
            console.log('[DEBUG] Настройка хука для расчета просадки');
            
            // Оригинальный метод renderChart
            var originalRenderChart = AccountChart.renderChart;
            
            // Проверяем, был ли уже заменен метод
            if (!AccountChart._drawdownHooked) {
                // Заменяем оригинальный метод renderChart, чтобы выполнять расчет просадки
                AccountChart.renderChart = function(data) {
                    // Сначала вызываем оригинальный метод, чтобы отрисовать график
                    originalRenderChart.call(AccountChart, data);
                    
                    // Затем рассчитываем просадку
                    console.log('[DEBUG] Вызываем AccountChart.calculateDrawdown из хука renderChart');
                    AccountChart.calculateDrawdown(data);
                };
                
                // Отмечаем, что метод был заменен
                AccountChart._drawdownHooked = true;
                console.log('[DEBUG] Хук для расчета просадки установлен');
            }
        }
    }
    
    // Обработчик кнопки для ручного расчета просадки
    $('#calculate-drawdown-manually').on('click', function() {
        $('#drawdown-debug-info').text('Запуск расчета...');
        
        if (window.testDrawdown) {
            try {
                window.testDrawdown();
                $('#drawdown-debug-info').text('Расчет выполнен, проверьте консоль (F12)');
            } catch (e) {
                console.error('[ERROR] Ошибка при расчете просадки:', e);
                $('#drawdown-debug-info').text('Ошибка: ' + e.message);
            }
        } else {
            $('#drawdown-debug-info').text('Функция testDrawdown не найдена');
        }
    });
    
    // Обработчик для пагинации в истории сделок
    $('.pagination select').on('change', function() {
        $(this).closest('form').submit();
    });
    
    // Подсветка строк таблицы при наведении
    $('.orders-table tbody tr').hover(
        function() {
            $(this).addClass('hover');
        },
        function() {
            $(this).removeClass('hover');
        }
    );
    
    // Автоматическое обновление времени "обновлено X минут назад"
    function updateTimestamps() {
        const now = new Date().getTime();
        $('.account-updated').each(function() {
            const $timeElement = $(this);
            const updateTime = $timeElement.data('timestamp');
            if (updateTime) {
                const diffMinutes = Math.floor((now - updateTime) / 60000);
                let timeText = '';
                let timeClass = '';
                // Логика цветов: до 3ч зеленый, 3-6ч оранжевый, 6ч+ красный
                if (diffMinutes < 1) {
                    timeText = 'только что';
                    timeClass = 'recent';
                } else if (diffMinutes < 60) {
                    timeText = diffMinutes + ' мин. назад';
                    timeClass = diffMinutes < 180 ? 'recent' : 'moderate'; // До 3 часов
                } else if (diffMinutes < 1440) {
                    const hours = Math.floor(diffMinutes / 60);
                    const minutes = diffMinutes % 60;
                    timeText = hours + ' ч. ' + minutes + ' мин. назад';
                    // До 3ч зеленый, 3-6ч оранжевый, 6ч+ красный
                    if (diffMinutes < 180) {
                        timeClass = 'recent';
                    } else if (diffMinutes < 360) {
                        timeClass = 'moderate';
                    } else {
                        timeClass = 'stale';
                    }
                } else {
                    const days = Math.floor(diffMinutes / 1440);
                    timeText = days + ' д. назад';
                    timeClass = 'stale';
                }
                $timeElement.text('Обновлено: ' + timeText)
                    .removeClass('recent moderate stale')
                    .addClass(timeClass);
            }
        });
    }
    
    // Инициализируем временные метки
    $('.account-updated').each(function() {
        const $timeElement = $(this);
        const updateTimeText = $timeElement.text().replace('Обновлено: ', '');
        // Преобразуем текст в timestamp
        let minutes = 0;
        if (updateTimeText.includes('мин.')) {
            minutes = parseInt(updateTimeText);
        } else if (updateTimeText.includes('ч.')) {
            const parts = updateTimeText.split('ч.');
            minutes = parseInt(parts[0].trim()) * 60;
            if (parts[1].includes('мин.')) {
                minutes += parseInt(parts[1].trim());
            }
        } else if (updateTimeText.includes('д.')) {
            minutes = parseInt(updateTimeText) * 1440;
        }
        const timestamp = new Date().getTime() - (minutes * 60000);
        $timeElement.data('timestamp', timestamp);
    });
    
    // Обновляем время каждую минуту
    setInterval(updateTimestamps, 60000);

    // Обновляем необходимые DOM-элементы после AJAX-запроса
    function updateDomWithAccountData(accountData) {
        // ... existing code ...
    }
    
    // Обработчик для формы регистрации счета
    if ($('#contest-account-form').length > 0) {
        console.log('[DEBUG] Форма регистрации счета найдена, инициализация обработчиков...');
        
        // Проверка наличия важных элементов
        console.log('[DEBUG] Элементы формы:',  {
            'Форма найдена': $('#contest-account-form').length > 0,
            'Брокер существует': $('#broker').length > 0,
            'Платформа существует': $('#platform').length > 0,
            'Сервер существует': $('#server').length > 0,
            'Терминал существует': $('#terminal').length > 0
        });
        
        // Прямая привязка обработчика без делегирования
        const brokerSelect = document.getElementById('broker');
        if (brokerSelect) {
            console.log('[DEBUG] Прямая привязка обработчика изменения к элементу #broker');
            brokerSelect.addEventListener('change', function(e) {
                console.log('[DEBUG] Прямой обработчик: Выбран брокер:', this.value);
                const brokerId = this.value;
                const $platformSelect = $('#platform');
                const $serverSelect = $('#server');
                
                // Сбрасываем зависимые списки
                $platformSelect.empty().append('<option value="">Выберите платформу</option>').prop('disabled', true);
                $serverSelect.empty().append('<option value="">Сначала выберите платформу</option>').prop('disabled', true);
                
                if (!brokerId) {
                    console.log('[DEBUG] Прямой обработчик: Брокер не выбран, прерываем');
                    return;
                }
                
                // Показываем индикатор загрузки
                $platformSelect.append('<option value="" disabled>Загрузка...</option>');
                
                console.log('[DEBUG] Прямой обработчик: Отправляем AJAX запрос для получения платформ');
                
                // Запрашиваем список платформ для выбранного брокера
                $.ajax({
                    url: (typeof ftContestData !== 'undefined' && ftContestData.ajax_url) ? 
                         ftContestData.ajax_url : '/wp-admin/admin-ajax.php',
                    type: 'POST',
                    data: {
                        action: 'get_broker_platforms',
                        nonce: (typeof ftContestData !== 'undefined' && ftContestData.nonce) ? 
                               ftContestData.nonce : '',
                        broker_id: brokerId
                    },
                    success: function(response) {
                        console.log('[DEBUG] Прямой обработчик: Получен ответ с платформами:', response);
                        $platformSelect.empty().append('<option value="">Выберите платформу</option>');
                        
                        if (response.success && response.data.length > 0) {
                            // Добавляем платформы в выпадающий список
                            $.each(response.data, function(index, platform) {
                                $platformSelect.append(
                                    $('<option></option>').val(platform.id).text(platform.name)
                                );
                            });
                            
                            // Активируем выпадающий список платформ
                            $platformSelect.prop('disabled', false);
                            console.log('[DEBUG] Прямой обработчик: Платформы загружены, список разблокирован');
                        } else {
                            console.log('[DEBUG] Прямой обработчик: Нет доступных платформ для брокера');
                            $platformSelect.append('<option value="" disabled>Нет доступных платформ</option>');
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error('[ERROR] Прямой обработчик: Ошибка загрузки платформ:', error);
                        $platformSelect.empty()
                            .append('<option value="">Ошибка загрузки платформ</option>')
                            .prop('disabled', true);
                    }
                });
            });
        }
        
        // Проверка значения брокера
        if ($('#broker').val()) {
            console.log('[DEBUG] Брокер уже выбран:', $('#broker').val());
        }
        
        // Проверка доступности AJAX URL
        if (typeof ftContestData !== 'undefined' && ftContestData.ajax_url) {
            console.log('[DEBUG] Попытка тестового запроса к AJAX URL');
            // Делаем простой тестовый запрос для проверки соединения
            $.ajax({
                url: ftContestData.ajax_url,
                type: 'POST',
                data: {
                    action: 'heartbeat' // Используем heartbeat как безопасный тестовый запрос
                },
                success: function(response) {
                    console.log('[DEBUG] Тестовый запрос успешен:', response);
                },
                error: function(xhr, status, error) {
                    console.error('[ERROR] Тестовый запрос не удался:', error);
                }
            });
        }
        
        // Аварийная инициализация брокера (если элемент уже имеет значение)
        if ($('#broker').val() && $('#platform').prop('disabled')) {
            console.log('[DEBUG] Аварийная инициализация брокера', $('#broker').val());
            // Если брокер выбран, но платформа отключена, попробуем вручную запросить данные
            const brokerId = $('#broker').val();
            const $platformSelect = $('#platform');
            
            $platformSelect.empty().append('<option value="">Загрузка платформ...</option>');
            
            // Ручной запрос к серверу
            $.ajax({
                url: ftContestData.ajax_url || '/wp-admin/admin-ajax.php',
                type: 'POST',
                data: {
                    action: 'get_broker_platforms',
                    nonce: ftContestData.nonce || '',
                    broker_id: brokerId
                },
                success: function(response) {
                    console.log('[DEBUG] Ручной запрос платформ:', response);
                    if (response.success && response.data.length > 0) {
                        $platformSelect.empty().append('<option value="">Выберите платформу</option>');
                        $.each(response.data, function(index, platform) {
                            $platformSelect.append(
                                $('<option></option>').val(platform.id).text(platform.name)
                            );
                        });
                        $platformSelect.prop('disabled', false);
                    } else {
                        $platformSelect.empty().append('<option value="">Ошибка загрузки платформ</option>');
                    }
                },
                error: function(xhr, status, error) {
                    console.error('[ERROR] Ручной запрос платформ не удался:', error);
                    $platformSelect.empty().append('<option value="">Ошибка соединения</option>');
                }
            });
        }
        
        // Обработчик отправки формы
        $('#contest-account-form').on('submit', function() {
            console.log('[DEBUG] Отправка формы, устанавливаем терминал...');
            // Убедимся, что поле терминала установлено правильно
            const $platform = $('#platform');
            if ($platform.length && $platform.val()) {
                const platformText = $platform.find('option:selected').text();
                
                if (platformText.includes('MetaTrader 4') || platformText.includes('MT4')) {
                    $('#terminal').val('metatrader4');
                } else if (platformText.includes('MetaTrader 5') || platformText.includes('MT5')) {
                    $('#terminal').val('metatrader5');
                } else {
                    $('#terminal').val(platformText.toLowerCase().replace(/\s+/g, ''));
                }
                console.log('[DEBUG] Установлен терминал:', $('#terminal').val());
            }
        });
        
        // Обработчик изменения брокера
        $('#broker').on('change', function() {
            console.log('[DEBUG] Выбран брокер:', $(this).val());
            const brokerId = $(this).val();
            const $platformSelect = $('#platform');
            const $serverSelect = $('#server');
            const platformText = $(this).find('option:selected').text();
            
            // Устанавливаем значение терминала на основе выбранной платформы
            if (platformText.includes('MetaTrader 4') || platformText.includes('MT4')) {
                $('#terminal').val('metatrader4');
            } else if (platformText.includes('MetaTrader 5') || platformText.includes('MT5')) {
                $('#terminal').val('metatrader5');
            } else {
                // По умолчанию используем текст выбранной платформы
                $('#terminal').val(platformText.toLowerCase().replace(/\s+/g, ''));
            }
            
            // Сбрасываем зависимые списки
            $platformSelect.empty().append('<option value="">Выберите платформу</option>').prop('disabled', true);
            $serverSelect.empty().append('<option value="">Сначала выберите платформу</option>').prop('disabled', true);
            
            if (!brokerId) {
                console.log('[DEBUG] Брокер не выбран, прерываем');
                return;
            }
            
            // Показываем индикатор загрузки
            $platformSelect.append('<option value="" disabled>Загрузка...</option>');
            
            console.log('[DEBUG] Отправляем AJAX запрос для получения платформ:', {
                url: ftContestData.ajax_url,
                broker_id: brokerId,
                nonce: ftContestData.nonce
            });
            
            // Запрашиваем список платформ для выбранного брокера
            $.ajax({
                url: ftContestData.ajax_url,
                type: 'POST',
                data: {
                    action: 'get_broker_platforms',
                    nonce: ftContestData.nonce,
                    broker_id: brokerId
                },
                success: function(response) {
                    console.log('[DEBUG] Получен ответ с платформами:', response);
                    $platformSelect.empty().append('<option value="">Выберите платформу</option>');
                    
                    if (response.success && response.data.length > 0) {
                        // Добавляем платформы в выпадающий список
                        $.each(response.data, function(index, platform) {
                            $platformSelect.append(
                                $('<option></option>').val(platform.id).text(platform.name)
                            );
                        });
                        
                        // Активируем выпадающий список платформ
                        $platformSelect.prop('disabled', false);
                        
                        // Если редактируем существующий счет, выбираем соответствующую платформу
                        const isEditMode = $('#account_id').length > 0;
                        if (isEditMode) {
                            // Здесь логика для автоматического выбора платформы (при необходимости)
                        }
                        
                        console.log('[DEBUG] Платформы загружены, список разблокирован');
                    } else {
                        console.log('[DEBUG] Нет доступных платформ для брокера');
                        $platformSelect.append('<option value="" disabled>Нет доступных платформ</option>');
                    }
                },
                error: function(xhr, status, error) {
                    console.error('[ERROR] Ошибка загрузки платформ:', error);
                    $platformSelect.empty()
                        .append('<option value="">Ошибка загрузки платформ</option>')
                        .prop('disabled', true);
                }
            });
        });
        
        // Прямая привязка обработчика для платформы
        const platformSelect = document.getElementById('platform');
        if (platformSelect) {
            console.log('[DEBUG] Прямая привязка обработчика изменения к элементу #platform');
            platformSelect.addEventListener('change', function(e) {
                console.log('[DEBUG] Прямой обработчик: Выбрана платформа:', this.value);
                const platformId = this.value;
                const brokerId = $('#broker').val();
                const $serverSelect = $('#server');
                const platformText = $(this).find('option:selected').text();
                
                // Устанавливаем значение терминала на основе выбранной платформы
                if (platformText.includes('MetaTrader 4') || platformText.includes('MT4')) {
                    $('#terminal').val('metatrader4');
                } else if (platformText.includes('MetaTrader 5') || platformText.includes('MT5')) {
                    $('#terminal').val('metatrader5');
                } else {
                    // По умолчанию используем текст выбранной платформы
                    $('#terminal').val(platformText.toLowerCase().replace(/\s+/g, ''));
                }
                
                // Сбрасываем список серверов
                $serverSelect.empty().append('<option value="">Выберите сервер</option>').prop('disabled', true);
                
                if (!platformId || !brokerId) {
                    console.log('[DEBUG] Прямой обработчик: Не выбрана платформа или брокер, прерываем');
                    return;
                }
                
                // Показываем индикатор загрузки
                $serverSelect.append('<option value="" disabled>Загрузка...</option>');
                
                console.log('[DEBUG] Прямой обработчик: Отправляем AJAX запрос для получения серверов');
                
                // Запрашиваем список серверов для выбранных брокера и платформы
                $.ajax({
                    url: (typeof ftContestData !== 'undefined' && ftContestData.ajax_url) ? 
                         ftContestData.ajax_url : '/wp-admin/admin-ajax.php',
                    type: 'POST',
                    data: {
                        action: 'get_broker_servers',
                        nonce: (typeof ftContestData !== 'undefined' && ftContestData.nonce) ? 
                               ftContestData.nonce : '',
                        broker_id: brokerId,
                        platform_id: platformId,
                        contest_id: $('#contest_id').length ? $('#contest_id').val() : 0 // Добавляем contest_id если доступен
                    },
                    success: function(response) {
                        console.log('[DEBUG] Прямой обработчик: Получен ответ с серверами:', response);
                        $serverSelect.empty().append('<option value="">Выберите сервер</option>');
                        
                        if (response.success && response.data.length > 0) {
                            // Добавляем серверы в выпадающий список
                            $.each(response.data, function(index, server) {
                                $serverSelect.append(
                                    $('<option></option>').val(server.server_address).text(server.name)
                                );
                            });
                            
                            // Активируем выпадающий список серверов
                            $serverSelect.prop('disabled', false);
                            console.log('[DEBUG] Прямой обработчик: Серверы загружены, список разблокирован');
                            
                            // Если редактируем существующий счет, выбираем соответствующий сервер
                            const isEditMode = $('#account_id').length > 0;
                            if (isEditMode && $('#account_server').length > 0) {
                                const savedServer = $('#account_server').val();
                                if (savedServer) {
                                    $serverSelect.val(savedServer);
                                    console.log('[DEBUG] Прямой обработчик: Выбран сохраненный сервер:', savedServer);
                                }
                            }
                        } else {
                            console.log('[DEBUG] Прямой обработчик: Нет доступных серверов для выбранной платформы');
                            $serverSelect.append('<option value="" disabled>Нет доступных серверов</option>');
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error('[ERROR] Прямой обработчик: Ошибка загрузки серверов:', error);
                        $serverSelect.empty()
                            .append('<option value="">Ошибка загрузки серверов</option>')
                            .prop('disabled', true);
                    }
                });
            });
        }
        
        // Функция инициализации выпадающих списков
        function initDropdowns() {
            console.log('[DEBUG] Инициализация каскадных выпадающих списков');
            
            // Принудительно активируем обработчики элементов
            const brokerElement = document.getElementById('broker');
            const platformElement = document.getElementById('platform');
            
            // Если элементы найдены, имитируем события изменения
            if (brokerElement && brokerElement.value) {
                console.log('[DEBUG] Активация обработчика брокера');
                // Используем прямой вызов addEventListener, который мы добавили ранее
                const changeEvent = new Event('change');
                brokerElement.dispatchEvent(changeEvent);
                
                // После загрузки платформ, с задержкой активируем платформу
                if (platformElement && platformElement.value) {
                    setTimeout(function() {
                        console.log('[DEBUG] Активация обработчика платформы после задержки');
                        const platformChangeEvent = new Event('change');
                        platformElement.dispatchEvent(platformChangeEvent);
                    }, 500);
                }
            } else {
                console.log('[DEBUG] Брокер не найден или не выбран');
            }
        }
        
        // Безусловно вызываем инициализацию выпадающих списков
        console.log('[DEBUG] Безусловная инициализация выпадающих списков');
        initDropdowns();
        
        // Также добавляем инициализацию для случаев, когда форма может стать видимой позже
        $('#contest-account-form').on('show', function() {
            console.log('[DEBUG] Событие show на форме, повторная инициализация');
            initDropdowns();
        });
        
        // Проверяем родительский контейнер
        const $parentContainer = $('#contest-account-form').parent();
        if ($parentContainer.length) {
            $parentContainer.on('show', function() {
                console.log('[DEBUG] Родительский контейнер стал видимым, повторная инициализация');
                initDropdowns();
            });
        }
    }

    // Обработчик кнопки проверки на дисквалификацию
    $('#check-disqualification').on('click', function() {
        var button = $(this);
        var statusElement = $('#disqualification-status');
        var accountId = button.data('account-id');
        
        // Блокируем кнопку во избежание множественных запросов
        if (button.prop('disabled')) {
            return;
        }
        
        // Обновляем UI
        button.prop('disabled', true);
        button.text('Проверка...');
        statusElement.html('Проверка условий дисквалификации...');
        
        // Отправляем AJAX-запрос
        $.ajax({
            url: ftContestData.ajax_url,
            type: 'POST',
            data: {
                action: 'check_account_disqualification',
                nonce: ftContestData.nonce,
                account_id: accountId,
                auto_remove: true // Добавляем параметр для автоматического снятия дисквалификации
            },
            success: function(response) {
                console.log('[DEBUG] Ответ от сервера:', response);
                
                if (response.success) {
                    // Счет соответствует условиям
                    statusElement.html('<span style="color: green;">' + response.data.message + '</span>');
                    
                    // Если дисквалификация была снята, перезагружаем страницу
                    if (response.data.disqualification_removed) {
                        setTimeout(function() {
                            window.location.reload();
                        }, 2000);
                    }
                } else {
                    // Счет дисквалифицирован
                    var message = '<div class="disqualification-message">';
                    message += '<div class="disqualification-title" style="color: red; font-weight: bold; margin-bottom: 5px;">' 
                              + response.data.message + '</div>';
                    
                    // Проверяем, есть ли HTML-разметка для причин
                    if (response.data.reasons_html) {
                        message += '<div class="disqualification-reasons">' + response.data.reasons_html + '</div>';
                    } else if (response.data.reasons && response.data.reasons.length > 0) {
                        // Если нет HTML, но есть массив причин, формируем список
                        if (response.data.reasons.length > 1) {
                            message += '<ul>';
                            response.data.reasons.forEach(function(reason) {
                                message += '<li style="margin-bottom: 8px; line-height: 1.4;">' + reason + '</li>';
                            });
                            message += '</ul>';
                        } else {
                            message += '<p style="white-space: pre-wrap; line-height: 1.4;">' + response.data.reasons[0] + '</p>';
                        }
                    } else {
                        // Если нет ни HTML, ни массива причин, просто выводим сообщение
                        message += '<p>' + response.data.message + '</p>';
                    }
                    
                    message += '</div>';
                    statusElement.html(message);
                    
                    // Если произошла дисквалификация, перезагружаем страницу через 5 секунд
                    if (response.data.disqualified) {
                        setTimeout(function() {
                            window.location.reload();
                        }, 5000);
                    }
                }
            },
            error: function(xhr, status, error) {
                console.error('[ERROR] Ошибка при проверке дисквалификации:', error);
                statusElement.html('<span style="color: red;">Ошибка при проверке. Пожалуйста, попробуйте еще раз.</span>');
            },
            complete: function() {
                // Разблокируем кнопку и восстанавливаем текст
                button.prop('disabled', false);
                button.text('Проверить на дисквалификацию');
            }
        });
    });
});
