jQuery(document).ready(function($) {

    // Логируем инициализацию скрипта для отладки
    console.log('Admin.js загружен - начало инициализации');

    // Обработчик для загрузки логотипа спонсора
    $('#upload_sponsor_logo_button').on('click', function(e) {
        e.preventDefault();
        
        // Объявляем переменную для медиа-фрейма в глобальной области видимости
        var sponsorLogoFrame;
        
        // Если медиа-фрейм уже существует, открываем его
        if (sponsorLogoFrame) {
            sponsorLogoFrame.open();
            return;
        }
        
        // Создаем медиа-фрейм
        sponsorLogoFrame = wp.media({
            title: 'Выберите или загрузите логотип спонсора',
            button: {
                text: 'Использовать это изображение'
            },
            multiple: false
        });
        
        // Обработчик выбора изображения
        sponsorLogoFrame.on('select', function() {
            var attachment = sponsorLogoFrame.state().get('selection').first().toJSON();
            
            // Обновляем поле ввода URL изображения
            $('#sponsor_logo').val(attachment.url);
            
            // Обновляем предпросмотр изображения
            var $previewContainer = $('.sponsor-logo-preview');
            if ($previewContainer.find('img').length > 0) {
                $previewContainer.find('img').attr('src', attachment.url);
            } else {
                $previewContainer.html('<img src="' + attachment.url + '" style="max-width: 100%; height: auto;" />');
            }
            
            // Если есть скрытое поле для ID изображения, обновляем его
            if ($('#sponsor_logo_id').length) {
                $('#sponsor_logo_id').val(attachment.id);
            }
            
            console.log('Выбрано изображение:', attachment);
        });
        
        // Открываем медиа-фрейм
        sponsorLogoFrame.open();
    });

    // Обработчик выделения всех чекбоксов в таблице счетов
    if ($('.wp-list-table').length) {
        // Логирование начальной информации
        console.log('[DEBUG] Таблица найдена. ID:', $('.wp-list-table').attr('id'));
        console.log('[DEBUG] Чекбоксы в заголовке:', $('.wp-list-table thead .check-column input:checkbox, #cb-select-all-1').length);
        console.log('[DEBUG] Чекбоксы в строках:', $('.wp-list-table tbody .column-cb input:checkbox, .wp-list-table tbody .cb input:checkbox').length);
        
        // Точный селектор для чекбоксов в строках
        var rowCheckboxesSelector = '.wp-list-table tbody .column-cb input[type="checkbox"], .wp-list-table tbody .cb input[type="checkbox"]';
        var headerCheckboxSelector = '.wp-list-table thead .check-column input[type="checkbox"], #cb-select-all-1';
        
        // Проверяем элементы в строках с более точным селектором
        var $rowCheckboxes = $(rowCheckboxesSelector);
        console.log('[DEBUG] Найдено чекбоксов с точным селектором:', $rowCheckboxes.length);
        
        // Прямой обработчик клика на главный чекбокс - используем делегирование событий
        $(document).on('click', headerCheckboxSelector, function(e) {
            var isChecked = $(this).prop('checked');
            console.log('[DEBUG] Клик по чекбоксу. ID:', $(this).attr('id'), 'Состояние:', isChecked);
            
            // Получаем все чекбоксы в строках в момент клика
            var checkboxes = $(rowCheckboxesSelector);
            console.log('[DEBUG] Найдено чекбоксов в строках (при клике):', checkboxes.length);
            
            // Устанавливаем состояние всех чекбоксов
            checkboxes.prop('checked', isChecked);
            console.log('[DEBUG] После установки. Отмечено:', $(rowCheckboxesSelector + ':checked').length);
            
            // Если есть jQuery UI, добавляем визуальный эффект
            if (typeof $.fn.effect !== 'undefined') {
                $('.wp-list-table tbody tr').effect('highlight', {}, 1000);
            }
        });
    }

    initStatusTooltips();

    // Каскадные списки брокеров/платформ/серверов в метабоксе конкурса
    $('#broker').on('change', function() {
        const brokerId = $(this).val();
        const $platform = $('#platform');
        const $serversContainer = $('#servers-container');

        $platform.prop('disabled', true).empty().append('<option value="">Загрузка...</option>');
        $serversContainer.html('<div class="servers-placeholder">Сначала выберите платформу</div>');
        $serversContainer.attr('data-disabled', 'true');
        $('.servers-selected-count').hide();

        if (!brokerId) return;

        $.post(ftTraderAdmin.ajax_url, {
            action: 'get_broker_platforms',
            nonce: ftTraderAdmin.contestNonce,
            broker_id: brokerId
        }, function(res) {
            $platform.empty().append('<option value="">Выберите платформу</option>');
            if (res.success) {
                $.each(res.data, function(i, p) {
                    $platform.append($('<option></option>').val(p.id).text(p.name));
                });
                $platform.prop('disabled', false);
                const selected = $platform.data('selected');
                if (selected) {
                    $platform.val(selected).trigger('change');
                }
            }
        });
    });

    $('#platform').on('change', function() {
        const platformId = $(this).val();
        const brokerId = $('#broker').val();
        const $serversContainer = $('#servers-container');

        // Показываем состояние загрузки
        $serversContainer.html('<div class="servers-loading">Загрузка серверов...</div>');
        $serversContainer.attr('data-disabled', 'true');

        if (!platformId || !brokerId) {
            $serversContainer.html('<div class="servers-placeholder">Сначала выберите платформу</div>');
            return;
        }

        $.post(ftTraderAdmin.ajax_url, {
            action: 'get_broker_servers',
            nonce: ftTraderAdmin.contestNonce,
            broker_id: brokerId,
            platform_id: platformId
        }, function(res) {
            if (res.success && res.data.length > 0) {
                // Создаем HTML с кнопками управления и списком чекбоксов
                let serversHtml = `
                    <div class="servers-controls">
                        <a href="#" class="select-all-servers">Выбрать все</a> | 
                        <a href="#" class="deselect-all-servers">Снять выбор</a>
                    </div>
                    <div class="servers-list">`;
                
                $.each(res.data, function(i, s) {
                    serversHtml += `
                        <label class="server-checkbox-item">
                            <input type="checkbox" 
                                   name="fttradingapi_contest_data[servers][]" 
                                   value="${s.server_address}">
                            <span class="server-name">${s.name}</span>
                            <span class="server-address">${s.server_address}</span>
                        </label>`;
                });
                serversHtml += '</div>';
                
                $serversContainer.html(serversHtml);
                $serversContainer.removeAttr('data-disabled');
                
                // Восстанавливаем выбранные серверы
                let selectedSrv = $serversContainer.data('selected');
                if (selectedSrv) {
                    if (typeof selectedSrv === 'string') {
                        selectedSrv = selectedSrv.split(',');
                    }
                    selectedSrv.forEach(function(serverAddress) {
                        $serversContainer.find(`input[value="${serverAddress}"]`).prop('checked', true);
                    });
                }
                
                // Обновляем счетчик
                updateServersCount();
            } else {
                $serversContainer.html('<div class="servers-placeholder">Нет доступных серверов</div>');
                $serversContainer.attr('data-disabled', 'true');
            }
        }).fail(function() {
            $serversContainer.html('<div class="servers-placeholder">Ошибка загрузки серверов</div>');
            $serversContainer.attr('data-disabled', 'true');
        });
    });

    // Функция для обновления счетчика выбранных серверов
    function updateServersCount() {
        const checkedCount = $('#servers-container input[type="checkbox"]:checked').length;
        const $counter = $('.servers-selected-count');
        
        if (checkedCount > 0) {
            $counter.find('.count').text(checkedCount);
            $counter.show();
        } else {
            $counter.hide();
        }
    }

    // Обработчик изменения чекбоксов серверов
    $(document).on('change', '#servers-container input[type="checkbox"]', function() {
        updateServersCount();
    });

    // Функция "Выбрать все серверы"
    $(document).on('click', '.select-all-servers', function(e) {
        e.preventDefault();
        $('#servers-container input[type="checkbox"]').prop('checked', true);
        updateServersCount();
    });

    // Функция "Снять выбор"
    $(document).on('click', '.deselect-all-servers', function(e) {
        e.preventDefault();
        $('#servers-container input[type="checkbox"]').prop('checked', false);
        updateServersCount();
    });

    // Инициализация при наличии сохраненных значений
    if ($('#broker').val()) {
        $('#broker').trigger('change');
    }
    
    // Инициализация счетчика при загрузке страницы
    setTimeout(function() {
        updateServersCount();
    }, 100);
    
    // Улучшенные тултипы для статусов подключения
    function initStatusTooltips() {
        $('.status-indicator.disconnected').each(function() {
            var $status = $(this);
            
            // Если нет атрибута title или он пустой, пропускаем
            if (!$status.attr('title')) return;
            
            // Заменяем стандартный тултип на кастомный
            var tooltipText = $status.attr('title');
            $status.removeAttr('title'); // Удаляем стандартный тултип
            
            // Добавляем класс и данные для кастомного тултипа
            $status.addClass('custom-tooltip').attr('data-tooltip', tooltipText);
        });
    }

    // Добавляем пространство имен для события, чтобы можно было его потом очистить
    // Добавляем пространство имен для события
    $('select[name="action"], select[name="action2"]').on('change', function() {
        var $form = $(this).closest('form');
        
        // Удаляем все предыдущие обработчики этого события
        $form.off('submit.bulkUpdate');
        
        // Если выбрано действие "update", добавляем новый обработчик
        if ($(this).val() === 'update') {
            $form.on('submit.bulkUpdate', function(e) {
                e.preventDefault(); // Предотвращаем стандартную отправку формы
                
                var checkedCount = $('input[name="account_id[]"]:checked').length;
                
                if (checkedCount === 0) {
                    alert('Пожалуйста, выберите хотя бы один счет для обновления.');
                    return false;
                }
                
                // Получаем ID выбранных счетов
                var accountIds = [];
                $('input[name="account_id[]"]:checked').each(function() {
                    accountIds.push($(this).val());
                });
                
                // Запускаем серверное обновление вместо клиентского
                initServerUpdateQueue(accountIds);
                
                return false;
            });
        }
    });

    // Проверяем, есть ли активный процесс обновления при загрузке страницы
    $(document).ready(function() {
        // Определяем contest_id из URL
        var urlParams = new URLSearchParams(window.location.search);
        var pageContestId = urlParams.get('contest_id') ? parseInt(urlParams.get('contest_id')) : null;
        
        console.log('Проверка наличия активных процессов обновления при загрузке страницы, ID конкурса из URL:', pageContestId);
        
        var checkData = {
            action: 'fttradingapi_get_update_status',
            nonce: ftTraderAdmin.nonce
        };
        
        // Добавляем contest_id только если он есть
        if (pageContestId !== null) {
            checkData.contest_id = pageContestId;
        }
        
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: checkData,
            success: function(response) {
                console.log('Ответ на проверку активных процессов:', response);
                
                if (response.success && response.data && response.data.is_running) {
                    // Активный процесс найден, показываем статус и запускаем проверку
                    console.log('Найден активный процесс обновления:', response.data);
                    
                    // Если есть несколько очередей, показываем общее состояние
                    if (response.data.queues && Object.keys(response.data.queues).length > 0) {
                        var $notice = $('<div class="notice notice-info bulk-update-notice">' +
                          '<p>Обнаружено ' + response.data.queues_count + ' активных процессов обновления. ' +
                          'Обработано <span id="update-progress">' + response.data.completed + '</span> из ' + 
                          response.data.total + ' счетов</p>' +
                          '<div class="progress-bar-container"><div class="progress-bar" style="width: 0%"></div></div>' +
                          '</div>');
                        $notice.insertBefore('.wp-list-table');
                        
                        // Обновляем прогресс-бар
                        var percent = response.data.total > 0 ? 
                                     Math.round((response.data.completed / response.data.total) * 100) : 0;
                        $notice.find('.progress-bar').css('width', percent + '%');
                        
                        // Запускаем проверку статуса с ID конкурса из ответа
                        var activeContestId = response.data.contest_id || pageContestId;
                        checkUpdateStatus(activeContestId, null, $notice);
                    } else {
                        // Если одиночная очередь или старый формат
                        var $notice = $('<div class="notice notice-info bulk-update-notice">' +
                          '<p>Обнаружен активный процесс обновления. Обработано <span id="update-progress">' + 
                          response.data.completed + '</span> из ' + response.data.total + ' счетов</p>' +
                          '<div class="progress-bar-container"><div class="progress-bar" style="width: 0%"></div></div>' +
                          '</div>');
                        $notice.insertBefore('.wp-list-table');
                        
                        // Обновляем прогресс-бар
                        var percent = response.data.total > 0 ? 
                                     Math.round((response.data.completed / response.data.total) * 100) : 0;
                        $notice.find('.progress-bar').css('width', percent + '%');
                        
                        // Запускаем проверку статуса с ID конкурса из ответа и queue_id, если есть
                        var activeContestId = response.data.contest_id || pageContestId;
                        var queueId = response.data.queue_id || null;
                        checkUpdateStatus(activeContestId, queueId, $notice);
                    }
                } else {
                    console.log('Активных процессов обновления не найдено');
                }
            },
            error: function(xhr, status, error) {
                console.error('Ошибка при проверке активных процессов:', status, error);
            }
        });
    });

    // Функция форматирования времени
    function formatTimeAgo(minutes) {
        if (minutes < 1) {
            return 'только что';
        } else if (minutes < 60) {
            return minutes + ' мин. назад';
        } else if (minutes < 1440) {
            var hours = Math.floor(minutes / 60);
            var mins = minutes % 60;
            return hours + ' ч. ' + mins + ' мин. назад';
        } else {
                var days = Math.floor(minutes / 1440);
                return days + ' д. назад';
            }
        }

    // Удаление счета - оставляем как есть, функционал не меняется
    $('#delete_account').on('click', function() {
        if (confirm('Вы уверены что хотите удалить этот счет?')) {
            var accountId = $('#account_id').val();
            
            $.post(ajaxurl, {
                action: 'fttradingapi_delete_account',
                id: accountId
            }, function(response) {
                if (response.success) {
                    window.location.href = 'edit.php?post_type=trader_contests&page=trader_contests_accounts';
                }
            });
        }
    });
    
    // Обновленный обработчик для регистрации счета
    $('#register_account').on('click', function() {
        var button = $(this);
        var statusElement = $('#register_status');
        var contest_id = $('#contest_id').val();
        var contest_name = $('#contest_id option:selected').text();

        var data = {
            action: 'fttradingapi_register_account',
            account_number: $('#account_number').val(),
            password: $('#password').val(),
            server: $('#server').val(),
            terminal: $('#terminal').val(),
            contest_id: contest_id
        };

        button.prop('disabled', true).text('Обработка...');
        statusElement.html('<span style="color: gray;">Запрос отправлен...</span>');

        $.post(ajaxurl, data, function(response) {
            if (response.success) {
                // Информация о счете теперь находится в response.data.account_data
                var accountData = response.data.account_data || {};
                
                statusElement.html('<span style="color: green;">' + response.data.message + '</span>');
                
                var rowNumber = $('.wp-list-table tbody tr').length + 1;
                var formattedBalance = parseFloat(accountData.balance || 0).toLocaleString('en-US', 
                    { minimumFractionDigits: 2, maximumFractionDigits: 2 }).replace(/,/g, ' ');
                
                // Формируем имя пользователя
                var userName = 'Гость';
                if (accountData.user_id && accountData.user_id > 0) {
                    userName = accountData.user_name + ' (' + accountData.user_login + ')';
                }
                
                // Формируем статус подключения
                var statusHtml = '';
                if (accountData.connection_status === 'connected') {
                    statusHtml = '<span class="status-indicator connected">Подключен</span>';
                } else {
                    statusHtml = '<span class="status-indicator disconnected">Ошибка подключения</span>';
                }
                
                // Формируем отображение страны
                var countryHtml = '—';
                if (accountData.country_code) {
                    countryHtml = '<img src="https://flagcdn.com/16x12/' + accountData.country_code + '.png" ' +
                        'alt="' + accountData.user_country + '" ' +
                        'title="' + accountData.user_country + '" ' +
                        'width="16" height="12" style="margin-right: 5px; vertical-align: middle;" />' +
                        accountData.user_country;
                }
                
                // Создаем строку таблицы со всеми необходимыми столбцами в правильном порядке
                var newRow = '<tr class="highlight-new">' +
                    '<td class="check-column"><input type="checkbox" name="account_id[]" value="' + accountData.id + '"></td>' + 
                    '<td>' + rowNumber + '</td>' + 
                    '<td>' + accountData.id + '</td>' + 
                    '<td>' + accountData.contest_name + '</td>' + 
                    '<td>' + userName + '</td>' + // Имя пользователя 
                    '<td>' + accountData.account_number + '</td>' + 
                    '<td>' + (accountData.user_ip || '—') + '</td>' + // IP адрес
                    '<td>' + countryHtml + '</td>' + // Страна
                    '<td>' + formattedBalance + ' $</td>' + // Баланс 
                    '<td>' + accountData.server + '</td>' + // Сервер
                    '<td>' + accountData.terminal + '</td>' + // Терминал
                    '<td>' + statusHtml + '</td>' + // Статус подключения
                    '<td><span class="recent">только что</span></td>' + // Время обновления
                    '<td>' + accountData.registration_date + '</td>' + // Дата регистрации
                    '<td>' + // Действия
                        '<a href="' + ajaxurl.replace('admin-ajax.php', '') + 'edit.php?post_type=trader_contests&page=trader_contests_accounts_edit&id=' + accountData.id + '" class="button button-small"><span class="dashicons dashicons-edit"></span></a> ' +
                        '<a href="' + ajaxurl.replace('admin-ajax.php', '') + 'edit.php?post_type=trader_contests&page=trader_contests_accounts_view&id=' + accountData.id + '" class="button button-small"><span class="dashicons dashicons-visibility"></span></a>' +
                    '</td>' +
                    '</tr>';
                
                $('.wp-list-table tbody').prepend(newRow);
                
                // Очищаем форму
                $('#account_number').val('');
                $('#password').val('');
                
            } else {
                statusElement.html('<span style="color: red;">Ошибка: ' + response.data.message + '</span>');
            }
            button.prop('disabled', false).text('Добавить счет');
        }).fail(function() {
            statusElement.html('<span style="color: red;">Ошибка: Не удалось отправить запрос</span>');
            button.prop('disabled', false).text('Добавить счет');
        });
    });

    // Добавляем перехватчик AJAX-запросов для отслеживания queue_batch_id
    (function() {
        // Сохраняем оригинальный метод $.ajax для последующего использования
        var originalAjax = $.ajax;
        
        // Переопределяем $.ajax для мониторинга запросов
        $.ajax = function(options) {
            // Перехватываем только запросы на обновление счетов
            if (options.data && 
                ((typeof options.data === 'string' && options.data.indexOf('action=fttradingapi_update_account_data') !== -1) ||
                 (typeof options.data === 'object' && options.data.action === 'fttradingapi_update_account_data'))) {
                
                console.log('%c🔍 Отправка запроса на обновление счета', 'background:#3498db;color:white;padding:4px 8px;border-radius:3px;');
                console.log('📤 Данные запроса:', options.data);
                
                // Сохраняем оригинальный success callback
                var originalSuccess = options.success;
                
                // Перехватываем успешный ответ
                options.success = function(response) {
                    console.log('%c✅ Получен ответ от сервера', 'background:#2ecc71;color:white;padding:4px 8px;border-radius:3px;');
                    console.log('📥 Данные ответа:', response);
                    
                    // Отслеживаем queue_batch_id в HTTP-заголовках
                    if (this.xhr && this.xhr.getResponseHeader) {
                        var allHeaders = this.xhr.getAllResponseHeaders();
                        console.log('🔖 Все заголовки ответа:', allHeaders);
                        
                        var queueBatchId = this.xhr.getResponseHeader('X-Queue-Batch-ID');
                        if (queueBatchId) {
                            console.log('%c🆔 Queue Batch ID: ' + queueBatchId, 
                                'background:#9b59b6;color:white;padding:4px 8px;border-radius:3px;font-weight:bold;');
                        } else {
                            console.log('⚠️ Queue Batch ID не найден в заголовках ответа');
                        }
                    }
                    
                    // Вызываем оригинальный callback, если он был
                    if (originalSuccess) {
                        originalSuccess.apply(this, arguments);
                    }
                };
                
                // Сохраняем объект XHR для доступа к заголовкам
                var originalBeforeSend = options.beforeSend;
                options.beforeSend = function(xhr) {
                    this.xhr = xhr;
                    if (originalBeforeSend) {
                        originalBeforeSend.apply(this, arguments);
                    }
                };
            }
            
            // Вызываем оригинальный метод $.ajax
            return originalAjax.apply($, arguments);
        };
    })();

    // Добавляем инструментарий для отслеживания запросов в XHR
    (function() {
        // Сохраняем оригинальные методы XMLHttpRequest
        var originalOpen = XMLHttpRequest.prototype.open;
        var originalSend = XMLHttpRequest.prototype.send;
        
        // Переопределяем метод open для мониторинга URL и метода
        XMLHttpRequest.prototype.open = function(method, url) {
            this._method = method;
            this._url = url;
            return originalOpen.apply(this, arguments);
        };
        
        // Переопределяем метод send для мониторинга данных
        XMLHttpRequest.prototype.send = function(data) {
            // Проверяем, является ли запрос запросом на обновление счета
            if (this._url && this._url.indexOf('admin-ajax.php') !== -1 && data) {
                // Обрабатываем данные запроса
                var requestData = data;
                try {
                    if (typeof data === 'string') {
                        // Пытаемся извлечь action из строки запроса
                        if (data.indexOf('action=fttradingapi_update_account_data') !== -1) {
                            console.log('%c🔄 Обнаружен запрос на обновление счета (XHR)', 
                                'background:#f39c12;color:white;padding:4px 8px;border-radius:3px;');
                            console.log('📊 Данные запроса:', data);
                            
                            // Извлекаем account_id из запроса
                            var accountIdMatch = data.match(/account_id=(\d+)/);
                            if (accountIdMatch && accountIdMatch[1]) {
                                console.log('🔢 ID счета:', accountIdMatch[1]);
                            }
                            
                            // Устанавливаем обработчик загрузки для перехвата ответа сервера
                            this.addEventListener('load', function() {
                                if (this.status >= 200 && this.status < 300) {
                                    try {
                                        var response = JSON.parse(this.responseText);
                                        console.log('%c✅ Ответ сервера на обновление счета:', 
                                            'background:#27ae60;color:white;padding:4px 8px;border-radius:3px;');
                                        console.log('📥 Данные ответа:', response);
                                        
                                        // Проверяем наличие queue_batch_id в ответе
                                        var queueBatchId = this.getResponseHeader('X-Queue-Batch-ID');
                                        if (queueBatchId) {
                                            console.log('%c🆔 Queue Batch ID из заголовка: ' + queueBatchId, 
                                                'background:#8e44ad;color:white;padding:4px 8px;border-radius:3px;font-weight:bold;');
                                        } else {
                                            // Ищем queue_batch_id в теле ответа
                                            if (response && response.data && response.data.queue_batch_id) {
                                                console.log('%c🆔 Queue Batch ID из тела ответа: ' + response.data.queue_batch_id, 
                                                    'background:#8e44ad;color:white;padding:4px 8px;border-radius:3px;font-weight:bold;');
                                            } else {
                                                console.log('⚠️ Queue Batch ID не найден в ответе');
                                            }
                                        }
                                    } catch (e) {
                                        console.log('❌ Ошибка при обработке ответа:', e);
                                    }
                                }
                            });
                        }
                    }
                } catch (e) {
                    console.log('❌ Ошибка при анализе данных запроса:', e);
                }
            }
            
            // Вызываем оригинальный метод
            return originalSend.apply(this, arguments);
        };
    })();

    // Модифицируем обработчик обновления данных счета
    $('#update_account_data').on('click', function() {
        var button = $(this);
        var statusElement = $('#update_status');
        var accountId = button.data('account-id');
        
        // Выводим информацию в консоль перед отправкой запроса
        console.log('%c🆔 Отправка запроса на обновление счета', 'background:#e74c3c;color:white;padding:4px 8px;border-radius:3px;font-weight:bold;');
        console.log('📄 Обновляем счет ID:', accountId);
        
        // Проверка наличия account-id
        if (!accountId) {
            statusElement.html('<span style="color: red;">Ошибка: ID счета не найден</span>');
            return;
        }
    
        // Защита от двойных кликов - проверяем, не запущено ли уже обновление
        if (button.prop('disabled') || button.hasClass('updating')) {
            console.log('Обновление уже выполняется, дублирующий запрос заблокирован');
            return;
        }
    
        // Блокируем кнопку и ставим флаг обновления
        button.prop('disabled', true);
        button.addClass('updating');
        button.find('.dashicons').addClass('spin');
        statusElement.html('<span style="color: gray;">Обновление данных...</span>');
    
        // Подготавливаем данные для AJAX-запроса
        var requestData = {
                action: 'fttradingapi_update_account_data',
                account_id: accountId,
                nonce: ftTraderAdmin.nonce
        };
        
        // Выводим данные запроса в консоль
        console.log('📤 Данные запроса:', requestData);
    
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: requestData,
            success: function(response) {
                // Выводим ответ в консоль
                console.log('%c✅ Получен ответ от сервера', 'background:#27ae60;color:white;padding:4px 8px;border-radius:3px;');
                console.log('📥 Ответ:', response);
                
                // Проверяем наличие queue_batch_id в ответе
                if (response && response.data && response.data.queue_batch_id) {
                    console.log('%c🆔 Queue Batch ID: ' + response.data.queue_batch_id, 
                        'background:#8e44ad;color:white;padding:4px 8px;border-radius:3px;font-weight:bold;');
                }
                
                if (response.success) {
                    statusElement.html('<span style="color: green;">Данные обновлены</span>');
                    
                    // Асинхронное обновление данных без перезагрузки страницы
                    if (response.data && response.data.account_data) {
                        var accountData = response.data.account_data;
                        
                        // Обновляем отображаемые финансовые данные
                        $('#account-balance').text(parseFloat(accountData.balance).toLocaleString('en-US', 
                            { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' ' + (accountData.currency || 'USD'));
                        
                        $('#account-equity').text(parseFloat(accountData.equity).toLocaleString('en-US', 
                            { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' ' + (accountData.currency || 'USD'));
                        
                        $('#account-margin').text(parseFloat(accountData.margin).toLocaleString('en-US', 
                            { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' ' + (accountData.currency || 'USD'));
                        
                        $('#account-profit').text(parseFloat(accountData.profit).toLocaleString('en-US', 
                            { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' ' + (accountData.currency || 'USD'));
                        
                        // Обновляем статус подключения
                        var $statusCell = $('#account-connection-status');
                        $statusCell.removeClass('connected disconnected disqualified');
                        
                        if (accountData.connection_status === 'connected') {
                            $statusCell.addClass('connected').html('<span class="status-indicator connected">Подключен</span>');
                        } else if (accountData.connection_status === 'disconnected') {
                            $statusCell.addClass('disconnected').html('<span class="status-indicator disconnected">Отключен</span>');
                            
                            // Обновляем описание ошибки, если оно есть
                            if (accountData.error_description) {
                                $('#account-error-description').html(accountData.error_description);
                            }
                        } else if (accountData.connection_status === 'disqualified') {
                            $statusCell.addClass('disqualified').html('<span class="status-indicator disqualified">Дисквалифицирован</span>');
                        }
                        
                        // Обновляем время последнего обновления
                        var now = new Date();
                        var timeText = 'только что';
                        $('#account-last-update').text(timeText).removeClass('moderate stale').addClass('recent');
                    }
                    
                    // Перезагружаем страницу через уменьшенный интервал
                    setTimeout(function() {
                        window.location.reload();
                    }, 500); // Уменьшено с 1000 до 500 мс
                } else {
                    statusElement.html('<span style="color: red;">Ошибка: ' + (response.data ? response.data.message : 'Неизвестная ошибка') + '</span>');
                }
            },
            error: function() {
                statusElement.html('<span style="color: red;">Ошибка соединения</span>');
            },
            complete: function() {
                // Разблокируем кнопку и удаляем флаг обновления
                button.prop('disabled', false);
                button.removeClass('updating');
                button.find('.dashicons').removeClass('spin');
            }
        });
    });
    
    // Обработчик сохранения изменений счета
    $('#save_account').on('click', function() {
        var button = $(this);
        var statusElement = $('#edit_status');
        var accountId = $('#account_id').val();
        
        // Проверка наличия account-id
        if (!accountId) {
            statusElement.html('<span style="color: red;">Ошибка: ID счета не найден</span>');
            return;
        }

        button.prop('disabled', true).text('Сохранение...');
        statusElement.html('<span style="color: gray;">Сохранение данных...</span>');

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'fttradingapi_edit_account',
                id: accountId,
                password: $('#edit_password').val(),
                server: $('#edit_server').val(),
                terminal: $('#edit_terminal').val(),
                contest_id: $('#edit_contest_id').val() // Добавляем ID конкурса
            },
            success: function(response) {
                if (response.success) {
                    statusElement.html('<span style="color: green;">Данные успешно обновлены</span>');
                    setTimeout(function() {
                        window.location.reload();
                    }, 1000);
                } else {
                    statusElement.html('<span style="color: red;">Ошибка: ' + (response.data ? response.data.message : 'Неизвестная ошибка') + '</span>');
                }
            },
            error: function() {
                statusElement.html('<span style="color: red;">Ошибка соединения</span>');
            },
            complete: function() {
                button.prop('disabled', false).text('Сохранить изменения');
            }
        });
    });

    // Загрузка истории счета при инициализации
    loadAccountHistory();

    // Улучшенная функция загрузки истории аккаунта
    function loadAccountHistory() {
        // Получаем ID счета
        var accountId = 0;
        var accountIdElement = $('#update_account_data');
        
        if (accountIdElement.length > 0) {
            accountId = accountIdElement.data('account-id');
        } else {
            // Проверка на скрытый input
            var hiddenAccountId = $('#account_id');
            if (hiddenAccountId.length > 0) {
                accountId = hiddenAccountId.val();
            }
        }
        
        // Получаем nonce
        var nonceValue = '';
        if (typeof ftTraderAdmin !== 'undefined' && ftTraderAdmin.accountHistoryNonce) {
            nonceValue = ftTraderAdmin.accountHistoryNonce;
        }
        
        // Подготавливаем данные для запроса
        var data = {
            'action': 'load_account_history',
            'account_id': accountId,
            'field': $('#field_filter').val() || '',
            'period': $('#period_filter').val() || 'day',
            'sort': $('#sort_date').data('sort') || 'desc',
            'nonce': nonceValue
        };
        
        // Добавляем индикатор загрузки
        $('#history_table_wrapper').addClass('loading');
        
        // Отправляем запрос
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: data,
            success: function(response) {
                $('#history_table_wrapper').html(response).removeClass('loading');
            },
            error: function() {
                $('#history_table_wrapper').html('<p class="error">Ошибка при загрузке истории</p>').removeClass('loading');
            }
        });
    }

    // Обработчики событий для истории аккаунта
    $('.history-filter').on('change', loadAccountHistory);
    
    $('#sort_date').on('click', function() {
        var $btn = $(this);
        var currentSort = $btn.data('sort');
        var newSort = currentSort === 'desc' ? 'asc' : 'desc';
        
        $btn.data('sort', newSort);
        $btn.find('.dashicons')
            .toggleClass('dashicons-arrow-down-alt2 dashicons-arrow-up-alt2');
        
        loadAccountHistory();
    });

    // Начальная загрузка истории, если есть необходимые элементы на странице
    if ($('#history_table_wrapper').length > 0) {
        loadAccountHistory();
    }
    
    // Серверное обновление счетов 
    function initServerUpdateQueue(accountIds) {
        // Добавлено логирование вызова функции и переданных accountIds
        console.log('%c🔄 Инициировано серверное обновление счетов', 'background:#f39c12;color:white;padding:4px 8px;border-radius:3px;');
        console.log('📋 ID счетов для обновления:', accountIds);
        if (!accountIds || accountIds.length === 0) {
            console.error('Не выбраны счета для обновления');
            return;
        }
        
        // Удаляем существующие уведомления
        $('.bulk-update-notice').remove();
        
        // Показываем начальное сообщение
        var $notice = $('<div class="notice notice-info bulk-update-notice">' +
          '<p>Инициализация очереди обновления...</p>' +
          '</div>');
        $notice.insertBefore('.wp-list-table');
        
        // Определяем contest_id из URL или из первого выбранного счета
        var contestId = null;
        
        // Пытаемся получить contest_id из URL
        var urlParams = new URLSearchParams(window.location.search);
        var contestParam = urlParams.get('contest_id');
        if (contestParam) {
            contestId = parseInt(contestParam);
            console.log('ID конкурса получен из URL:', contestId);
        } else {
            // Пытаемся получить из строки таблицы (используем attr вместо data)
            if (accountIds.length > 0) {
                var $firstRow = $('input[name="account_id[]"][value="' + accountIds[0] + '"]').closest('tr');
                
                // Пробуем сначала через attr, потом через data
                var dataContestId = $firstRow.attr('data-contest-id');
                if (!dataContestId) {
                    dataContestId = $firstRow.data('contest-id');
                }
                
                if (dataContestId) {
                    contestId = parseInt(dataContestId);
                    console.log('ID конкурса получен из строки таблицы:', contestId);
                } else {
                    // В крайнем случае, попробуем найти упоминание конкурса в тексте строки
                    var contestCell = $firstRow.find('td:nth-child(4)').text(); // Колонка с названием конкурса
                    console.log('Текст ячейки конкурса:', contestCell);
                }
            }
        }
        
        console.log('Инициализация очереди обновления счетов для конкурса:', contestId);
        console.log('Выбранные счета:', accountIds);
        
        // Отправляем запрос на создание очереди
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'fttradingapi_create_update_queue',
                account_ids: accountIds,
                contest_id: contestId,
                nonce: ftTraderAdmin.nonce
            },
            success: function(response) {
                console.log('Ответ сервера на создание очереди:', response);
                
                if (response.success) {
                    // Обновляем уведомление
                    $notice.html('<p>Запущено обновление ' + response.data.total + 
                        ' счетов. <span id="update-progress">0</span> из ' + 
                        response.data.total + ' обработано</p>' +
                        '<div class="progress-bar-container"><div class="progress-bar" style="width: 0%"></div></div>');
                    
                    // Запускаем периодическую проверку статуса с передачей contest_id и queue_id
                    var queueContestId = response.data.contest_id || contestId;
                    var queueId = response.data.queue_id || null;
                    
                    // Сохраняем ID очереди как атрибут в уведомлении для последующего отслеживания
                    $notice.attr('data-queue-id', queueId);
                    
                    console.log('Запускаем проверку статуса для очереди:', queueId);
                    checkUpdateStatus(queueContestId, queueId, $notice);
                } else {
                    $notice.addClass('notice-error').removeClass('notice-info')
                        .html('<p>Ошибка: ' + (response.data.message || 'Не удалось создать очередь обновления') + '</p>');
                    console.error('Ошибка при создании очереди:', response.data);
                }
            },
            error: function(xhr, status, error) {
                $notice.addClass('notice-error').removeClass('notice-info')
                    .html('<p>Ошибка соединения при создании очереди обновления</p>');
                console.error('Ошибка AJAX:', status, error, xhr.responseText);
            }
        });
    }

    // Функция для проверки статуса обновления
    function checkUpdateStatus(contestId, queueId, $noticeElement) {
        console.log('Проверка статуса обновления для конкурса:', contestId, 'очереди:', queueId);
        
        // Создаем объект с данными запроса
        var requestData = {
            action: 'fttradingapi_get_update_status',
            nonce: ftTraderAdmin.nonce
        };
        
        // Добавляем contest_id только если он не пустой
        if (contestId !== null && contestId !== undefined) {
            requestData.contest_id = contestId;
        }
        
        // Добавляем queue_id только если он не пустой
        if (queueId !== null && queueId !== undefined) {
            requestData.queue_id = queueId;
        }
        
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: requestData,
            success: function(response) {
                console.log('Ответ на запрос статуса:', response);
                
                if (response.success && response.data) {
                    // Если запрашивали конкретную очередь
                    if (queueId) {
                        console.log('Получен статус для очереди', queueId, 'обновление в процессе:', response.data.is_running);
                        
                        // Если указан элемент уведомления, обновляем его
                        if ($noticeElement && $noticeElement.length > 0) {
                            updateQueueStatusDisplay(response.data, $noticeElement);
                            
                            // Если процесс еще идет, планируем следующую проверку
                            if (response.data.is_running) {
                                setTimeout(function() {
                                    checkUpdateStatus(contestId, queueId, $noticeElement);
                                }, 3000);
                            } else {
                                console.log('Обновление очереди', queueId, 'завершено');
                                
                                // Обновляем строки в таблице
                                updateAccountRows(response.data.accounts);
                            }
                        }
                    } else {
                        // Если запрашивали общий статус для конкурса
                        console.log('Получен общий статус для конкурса', contestId, 
                            'обновление в процессе:', response.data.is_running, 
                            'активных очередей:', response.data.queues_count);
                        
                        // Обновляем общий статусный блок
                        if ($noticeElement && $noticeElement.length > 0) {
                            updateGeneralStatusDisplay(response.data, $noticeElement);
                        } else {
                            // Если элемент уведомления не указан, но есть активные очереди, создаем новое уведомление
                            if (response.data.is_running && !$('.bulk-update-notice').length) {
                                var $newNotice = $('<div class="notice notice-info bulk-update-notice">' +
                                    '<p>Обнаружен активный процесс обновления. Обработано <span id="update-progress">' + 
                                    response.data.completed + '</span> из ' + response.data.total + ' счетов</p>' +
                                    '<div class="progress-bar-container"><div class="progress-bar" style="width: 0%"></div></div>' +
                                    '</div>');
                                $newNotice.insertBefore('.wp-list-table');
                                
                                updateGeneralStatusDisplay(response.data, $newNotice);
                            }
                        }
                        
                        // Если процесс еще идет, планируем следующую проверку
                        if (response.data.is_running) {
                            setTimeout(function() {
                                checkUpdateStatus(contestId, null, $noticeElement);
                            }, 3000);
                        } else {
                            console.log('Все процессы обновления завершены');
                        }
                    }
                } else {
                    console.error('Неверный ответ от сервера:', response);
                    
                    // Если был указан элемент уведомления, показываем ошибку в нем
                    if ($noticeElement && $noticeElement.length > 0) {
                        $noticeElement.addClass('notice-error').removeClass('notice-info')
                            .html('<p>Ошибка при получении статуса обновления</p>');
                    }
                    
                    setTimeout(function() {
                        checkUpdateStatus(contestId, queueId, $noticeElement);
                    }, 5000);
                }
            },
            error: function(xhr, status, error) {
                console.error('Ошибка при проверке статуса:', status, error, xhr.responseText);
                
                // При ошибке все равно планируем следующую проверку
                setTimeout(function() {
                    checkUpdateStatus(contestId, queueId, $noticeElement);
                }, 5000);
            }
        });
    }

    // Функция для обновления отображения статуса конкретной очереди
    function updateQueueStatusDisplay(queueData, $noticeElement) {
        if (!$noticeElement || !$noticeElement.length) return;
        
        // Вычисляем процент выполнения
        var percent = queueData.total > 0 ? Math.round((queueData.completed / queueData.total) * 100) : 0;
        
        // Обновляем текст прогресса
        $noticeElement.find('#update-progress').text(queueData.completed);
        
        // Обновляем прогресс-бар
        $noticeElement.find('.progress-bar').css('width', percent + '%');
        
        // Если процесс завершен, меняем уведомление
        if (!queueData.is_running) {
            $noticeElement.removeClass('notice-info').addClass('notice-success')
                .html('<p>Обновление завершено. Обработано ' + queueData.completed + 
                    ' из ' + queueData.total + ' счетов</p>');
        }
        
        // Обновляем информацию о счетах в таблице
        updateAccountRows(queueData.accounts);
    }

    // Функция для обновления отображения общего статуса
    function updateGeneralStatusDisplay(statusData, $noticeElement) {
        if (!$noticeElement || !$noticeElement.length) return;
        
        // Вычисляем процент выполнения
        var percent = statusData.total > 0 ? Math.round((statusData.completed / statusData.total) * 100) : 0;
        
        // Обновляем текст прогресса
        $noticeElement.find('#update-progress').text(statusData.completed);
        
        // Обновляем прогресс-бар
        $noticeElement.find('.progress-bar').css('width', percent + '%');
        
        // Если все процессы завершены, меняем уведомление
        if (!statusData.is_running) {
            $noticeElement.removeClass('notice-info').addClass('notice-success')
                .html('<p>Все обновления завершены. Обработано ' + statusData.completed + 
                    ' из ' + statusData.total + ' счетов</p>');
        }
        
        // Обрабатываем счета из всех очередей
        if (statusData.queues && Object.keys(statusData.queues).length > 0) {
            for (var queueId in statusData.queues) {
                var queueData = statusData.queues[queueId];
                if (queueData.accounts) {
                    updateAccountRows(queueData.accounts);
                }
            }
        }
    }

    // Функция для обновления строк счетов в таблице
    function updateAccountRows(accounts) {
        if (!accounts) return;
        
        // Для каждого счета в полученных данных
        for (var accountId in accounts) {
            var accountStatus = accounts[accountId];
            var $row = $('input[name="account_id[]"][value="' + accountId + '"]').closest('tr');
            if (!$row.length) continue;
            
            var $statusCell = $row.find('td:nth-child(12)') || $row.find('.column-status');
            
            // Обновляем статус в зависимости от состояния счета
            if (accountStatus.status === 'processing') {
                $row.addClass('updating-row');
                $statusCell.html('<span class="status-indicator updating"><span class="dashicons dashicons-update spin"></span> Обновление...</span>');
            } else if (accountStatus.status === 'success') {
                $row.removeClass('updating-row');
                
                // Проверяем фактический статус подключения
                if (accountStatus.connection_status === 'connected') {
                    $statusCell.html('<span class="status-indicator connected">Подключен</span>');
                } else {
                    // Если есть описание ошибки, добавляем его как всплывающую подсказку
                    var errorTitle = accountStatus.error_description ? 
                                ' title="' + accountStatus.error_description.replace(/"/g, '&quot;') + '"' : 
                                '';
                    $statusCell.html('<span class="status-indicator disconnected"' + errorTitle + '>Ошибка подключения</span>');
                }
                
                // Добавляем подсветку для привлечения внимания
                $row.addClass('highlight-new');
                setTimeout(function() {
                    $row.removeClass('highlight-new');
                }, 3000);
            } else if (accountStatus.status === 'failed') {
                $row.removeClass('updating-row');
                $statusCell.html('<span class="status-indicator disconnected">Ошибка</span>');
            }
        }
    }
   
    // Добавляем обработчик для кнопки очистки истории
    $('#clear_history').on('click', function(e) {
        e.preventDefault();
        
        var accountId = $(this).data('account-id');
        
        if (!accountId) {
            alert('ID счета не найден');
            return;
        }
        
        // Запрашиваем подтверждение
        if (!confirm('Вы уверены, что хотите очистить всю историю изменений этого счета? Это действие нельзя отменить.')) {
            return;
        }
        
        // Показываем индикатор загрузки
        $('#history_table_wrapper').addClass('loading');
        
        // Отправляем AJAX-запрос
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'clear_account_history',
                account_id: accountId,
                nonce: ftTraderAdmin.nonce
            },
            success: function(response) {
                if (response.success) {
                    // Обновляем таблицу истории (пустая)
                    $('#history_table_wrapper').html('<div class="notice notice-success"><p>' + response.data.message + '</p></div>');
                    
                    // Опционально: можно загрузить пустую таблицу
                    setTimeout(function() {
                        loadAccountHistory();
                    }, 2000);
                } else {
                    $('#history_table_wrapper').html('<div class="notice notice-error"><p>Ошибка: ' + 
                        (response.data ? response.data.message : 'Не удалось очистить историю') + '</p></div>');
                }
            },
            error: function() {
                $('#history_table_wrapper').html('<div class="notice notice-error"><p>Ошибка соединения при очистке истории</p></div>');
            },
            complete: function() {
                $('#history_table_wrapper').removeClass('loading');
            }
        });
    });

    // Повторно вызываем после Ajax-запросов
    $(document).ajaxComplete(function() {
        initStatusTooltips();
    });
    
    // Добавляем обработчик для кнопки очистки истории сделок
    $('#clear_order_history').on('click', function(e) {
        e.preventDefault();
        
        var accountId = $(this).data('account-id');
        var $status = $('#update_status');
        
        if (!accountId) {
            alert('ID счета не найден');
            return;
        }
        
        // Запрашиваем подтверждение
        if (!confirm('ВНИМАНИЕ! Вы собираетесь удалить все сделки (открытые позиции и историю) этого счета. После удаления данные будут восстановлены при следующем обновлении данных счета. Продолжить?')) {
            return;
        }
        
        // Блокируем кнопку
        var $button = $(this);
        $button.prop('disabled', true).text('Удаление...');
        
        // Отображаем статус
        $status.text('Удаление сделок...').removeClass('error success');
        
        // Отправляем AJAX-запрос
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'clear_order_history',
                account_id: accountId,
                nonce: ftTraderAdmin.nonce
            },
            success: function(response) {
                if (response.success) {
                    $status.text(response.data.message).addClass('success');
                    // Обновляем страницу, чтобы отобразить пустые таблицы
                    setTimeout(function() {
                        location.reload();
                    }, 1000);
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
    
    // Функционал для управления призовыми местами
    if ($('#prizes-table').length > 0) {
        // Установим флаг инициализации
        if (!window.prizesInterfaceInitialized) {
            // Инициализация интерфейса призовых мест
            initPrizesInterface();
            
            // Обработчик кнопки добавления призового места
            $('#add-prize-button').on('click', function() {
                addPrizeRow();
            });
            
            // Делегирование событий для кнопок удаления (для динамически добавленных элементов)
            $('#prizes-list').on('click', '.remove-prize-button', function() {
                $(this).closest('tr').remove();
                updatePrizesData();
                updatePlaceNumbers();
            });
            
            // Делегирование событий для полей ввода (обновление данных при изменении)
            $('#prizes-list').on('change', 'input, textarea', function() {
                updatePrizesData();
            });
            
            // Отмечаем, что интерфейс уже инициализирован
            window.prizesInterfaceInitialized = true;
        }
    }

    // Функция инициализации интерфейса призовых мест
    function initPrizesInterface() {
        // Получаем данные из скрытого поля
        var prizesData = $('#prizes-data').val();
        
        // Очищаем существующие строки перед инициализацией
        $('#prizes-list').empty();
        
        try {
            // Пытаемся распарсить JSON
            var prizes = JSON.parse(prizesData);
            
            // Если есть данные, добавляем строки для каждого призового места
            if (Array.isArray(prizes) && prizes.length > 0) {
                prizes.forEach(function(prize) {
                    addPrizeRow(prize);
                });
            } else {
                // Если данных нет, добавляем одну пустую строку
                addPrizeRow();
            }
        } catch (e) {
            // В случае ошибки парсинга, добавляем одну пустую строку
            console.error('Ошибка при парсинге данных призовых мест:', e);
            addPrizeRow();
        }
    }

    // Функция добавления строки призового места
    function addPrizeRow(prizeData) {
        // Определяем следующий номер места
        var nextPlace = $('#prizes-list tr').length + 1;
        
        // Используем данные, если они переданы, или создаем пустые значения
        var place = prizeData && prizeData.place ? parseInt(prizeData.place) : nextPlace;
        var amount = prizeData ? prizeData.amount : '';
        var description = prizeData ? prizeData.description : '';
        
        // Проверяем, нет ли уже места с таким номером
        var isDuplicate = false;
        $('#prizes-list tr').each(function() {
            var existingPlace = parseInt($(this).find('.prize-place').text());
            if (existingPlace === place) {
                isDuplicate = true;
                return false; // выходим из цикла each
            }
        });
        
        // Если это дубликат, используем следующий доступный номер
        if (isDuplicate) {
            console.log('Обнаружен дубликат места #' + place + ', используем место #' + nextPlace);
            place = nextPlace;
        }
        
        // Генерируем уникальные ID для полей формы
        var rowId = 'prize-row-' + place;
        var amountId = 'prize-amount-' + place;
        var descId = 'prize-desc-' + place;
        var buttonId = 'remove-prize-' + place;
        
        // Создаем HTML для новой строки с улучшенной доступностью
        var rowHtml = '<tr class="prize-row" id="' + rowId + '" role="row">' +
            '<td class="prize-place" role="cell">' + place + '</td>' +
            '<td role="cell"><label for="' + amountId + '" class="screen-reader-text">Сумма приза для места ' + place + '</label>' +
            '<input type="text" id="' + amountId + '" class="prize-amount-input" value="' + (amount || '') + '" placeholder="Например: $1000" aria-describedby="amount-desc" /></td>' +
            '<td role="cell"><label for="' + descId + '" class="screen-reader-text">Описание приза для места ' + place + '</label>' +
            '<textarea id="' + descId + '" class="prize-description-input" placeholder="Описание приза (опционально)" aria-describedby="desc-desc">' + (description || '') + '</textarea></td>' +
            '<td role="cell"><button type="button" id="' + buttonId + '" class="button button-secondary remove-prize-button" aria-label="Удалить призовое место ' + place + '">' +
            '<span class="dashicons dashicons-trash" aria-hidden="true"></span></button></td>' +
            '</tr>';
        
        // Добавляем строку в таблицу
        $('#prizes-list').append(rowHtml);
        
        // Добавляем невидимые описания для скринридеров
        if ($('#amount-desc').length === 0) {
            $('<span id="amount-desc" class="screen-reader-text">Введите сумму приза, например $1000 или 10000 рублей</span>').appendTo('body');
            $('<span id="desc-desc" class="screen-reader-text">Введите описание приза, если требуется</span>').appendTo('body');
        }
        
        // Обновляем данные в скрытом поле
        updatePrizesData();
    }

    // Функция обновления номеров мест
    function updatePlaceNumbers() {
        $('#prizes-list tr').each(function(index) {
            $(this).find('.prize-place').text(index + 1);
        });
    }

    // Функция обновления данных в скрытом поле
    function updatePrizesData() {
        var prizes = [];
        var processedPlaces = {};
        
        // Собираем данные из всех строк
        $('#prizes-list tr').each(function(index) {
            var $row = $(this);
            var place = index + 1; // Используем индекс для определения места
            var amount = $row.find('.prize-amount-input').val();
            var description = $row.find('.prize-description-input').val();
            
            // Проверяем, не обрабатывали ли мы уже это место
            if (!processedPlaces[place]) {
                processedPlaces[place] = true;
                prizes.push({
                    place: place,
                    amount: amount,
                    description: description
                });
            }
        });
        
        // Обновляем значение скрытого поля
        $('#prizes-data').val(JSON.stringify(prizes));
        
        // Сообщаем об изменениях для пользователей со скринридерами
        var prizesCount = prizes.length;
        var ariaMessage = 'В таблице ' + prizesCount + ' призовых мест';
        
        // Обновляем или добавляем элемент для объявлений
        if ($('#prizes-status').length === 0) {
            $('<div id="prizes-status" role="status" class="screen-reader-text" aria-live="polite"></div>').appendTo('.contest-prizes-container');
        }
        $('#prizes-status').text(ariaMessage);
    }

    /**
     * Функция для периодического обновления информации об активных очередях
     */
    function initActiveQueuesRefresh() {
        // Проверяем, что мы на странице со списком счетов
        if ($('.active-queues-table').length > 0) {
            // Первичное обновление через 5 секунд
            setTimeout(refreshActiveQueues, 5000);
        }
    }

    /**
     * Обновляет информацию об активных очередях через AJAX
     */
    function refreshActiveQueues() {
        $.ajax({
            url: ftTraderAdmin.ajax_url,
            type: 'POST',
            data: {
                action: 'get_active_update_queues',
                nonce: ftTraderAdmin.nonce
            },
            success: function(response) {
                if (response.success) {
                    // Обновляем HTML с информацией об очередях
                    $('.active-queues-container').html(response.data.html);
                    
                    // Если есть активные очереди, продолжаем обновление
                    if (response.data.has_active_queues) {
                        setTimeout(refreshActiveQueues, 5000);
                    } else {
                        // Если нет активных очередей, проверяем раз в 15 секунд
                        setTimeout(refreshActiveQueues, 15000);
                    }
                }
            },
            error: function() {
                // При ошибке пробуем снова через 10 секунд
                setTimeout(refreshActiveQueues, 10000);
            }
        });
    }

    // Инициализация при загрузке страницы
    $(document).ready(function() {
        // ... existing code ...
        
        // Инициализация обновления информации об очередях
        initActiveQueuesRefresh();
        
        // ... existing code ...
    });

});
