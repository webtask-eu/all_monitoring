<?php
/**
 * Шаблон формы регистрации/редактирования счета в конкурсе
 * 
 * Этот файл подключается через шорткод [contest_registration_form]
 * или напрямую для редактирования существующего счета
 */

// Запрещаем прямой доступ к файлу
if (!defined('ABSPATH')) {
    exit;
}

// Определяем режим работы формы (регистрация или редактирование)
$is_edit_mode = isset($account) && !empty($account);
$form_title = $is_edit_mode ? 'Редактирование счета' : 'Регистрация счета в конкурсе';
$submit_button_text = $is_edit_mode ? 'Сохранить изменения' : 'Зарегистрировать счет';
$form_action = $is_edit_mode ? 'update_contest_account_data' : 'register_contest_account';

// Если это режим редактирования, но счет не передан, выходим
if ($is_edit_mode && empty($account)) {
    echo '<div class="error-message">Счет не найден.</div>';
    return;
}

// Для режима регистрации проверяем конкурс
if (!$is_edit_mode) {
    // Получаем информацию о конкурсе
    $contest = get_post($contest_id);
    if (!$contest || $contest->post_type !== 'trader_contests') {
        echo '<div class="error-message">Конкурс не найден.</div>';
        return;
    }

    // Проверяем, активен ли конкурс
    $start_date = get_post_meta($contest_id, '_contest_start_date', true);
    $end_date = get_post_meta($contest_id, '_contest_end_date', true);
    $current_time = current_time('timestamp');

    if ($end_date && strtotime($end_date) < $current_time) {
        echo '<div class="notice-message">Конкурс завершен. Регистрация новых счетов недоступна.</div>';
        return;
    }
    
    // Проверяем, открыта ли регистрация
    $contest_data = get_post_meta($contest_id, '_fttradingapi_contest_data', true);
    $registration_status = isset($contest_data['registration']) ? $contest_data['registration'] : 'open';
    
    if ($registration_status === 'closed') {
        echo '<div class="notice-message">Регистрация в конкурсе завершена. Регистрация новых счетов недоступна.</div>';
        return;
    }

    // Проверяем, не зарегистрирован ли уже счет пользователя
    $current_user_id = get_current_user_id();
    global $wpdb;
    $table_name = $wpdb->prefix . 'contest_members';
    $existing_account = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $table_name WHERE user_id = %d AND contest_id = %d",
        $current_user_id,
        $contest_id
    ));

    if ($existing_account) {
        echo '<div class="notice-message">
            Вы уже зарегистрировали счет в этом конкурсе. 
            <a href="' . add_query_arg(['contest_account' => $existing_account->id, 'contest_id' => $contest_id], get_permalink($contest_id)) . '">
                Посмотреть ваш счет
            </a>
        </div>';
        return;
    }
}
?>

<div class="contest-registration-form">
    <h3><?php echo esc_html($form_title); ?><?php if (!$is_edit_mode): ?>
            "<?php echo esc_html($contest->post_title); ?>"<?php endif; ?></h3>

    <form id="contest-account-form">
        <?php if ($is_edit_mode): ?>
            <input type="hidden" id="account_id" name="account_id" value="<?php echo esc_attr($account->id); ?>">
        <?php endif; ?>

        <input type="hidden" id="contest_id" name="contest_id"
            value="<?php echo esc_attr($is_edit_mode ? $account->contest_id : $contest_id); ?>">

        <div class="form-group">
            <label for="account_number">Номер счета</label>
            <input type="text" id="account_number" name="account_number"
                value="<?php echo $is_edit_mode ? esc_attr($account->account_number) : ''; ?>" <?php echo $is_edit_mode ? 'readonly' : 'required'; ?>>
            <div class="field-hint">
                <?php echo $is_edit_mode ? 'Номер счета изменить нельзя' : 'Введите номер вашего торгового счета'; ?>
            </div>
        </div>

        <div class="form-group">
            <label for="password">Пароль счета</label>
            <input type="text" id="password" name="password"
                value="<?php echo $is_edit_mode ? str_replace('"', '&quot;', $account->password) : ''; ?>" <?php echo $is_edit_mode ? '' : 'required'; ?>>
            <?php if ($is_edit_mode): ?>
                <div class="field-hint">Оставьте пустым, если не хотите менять пароль</div>
            <?php endif; ?>
        </div>

        <!-- Выбор сервера -->
        <div class="form-group">
            <label for="server">Сервер</label>
            <select id="server" name="server" required>
                <option value="">Загрузка серверов...</option>
                <?php
                // В режиме редактирования предзагружаем список серверов
                if ($is_edit_mode && !empty($account->server)) {
                    echo '<option value="' . esc_attr($account->server) . '" selected>' . esc_html($account->server) . '</option>';
                }
                ?>
            </select>
        </div>

        <!-- Скрытое поле для совместимости -->
        <input type="hidden" id="terminal" name="terminal" value="<?php echo $is_edit_mode ? esc_attr($account->terminal) : ''; ?>">

        <div class="form-actions">
            <button type="submit" class="form-submit"><?php echo esc_html($submit_button_text); ?></button>
            <?php if ($is_edit_mode): ?>
                <button type="button" id="cancel-edit-account" class="form-cancel">Отмена</button>
            <?php endif; ?>
        </div>

        <div id="form-message" class="form-message" style="display: none;"></div>
    </form>

    <?php if (!$is_edit_mode): ?>
        <div class="registration-notes">
            <p><strong>Важно:</strong> Для участия в конкурсе необходимо предоставить корректную информацию о счете.
                Инвесторский пароль будет использоваться только для получения данных о вашей торговле и не дает доступа к
                управлению счетом.</p>

            <p>После регистрации счета вы сможете отслеживать свой прогресс в конкурсе.</p>
        </div>
    <?php endif; ?>
</div>

<!-- Аварийный JavaScript для обеспечения работы выпадающих списков -->
<script type="text/javascript">
    // Резервное определение переменной ftContestData, если она не определена
    if (typeof ftContestData === 'undefined') {
        console.warn('[ВАЖНО] Переменная ftContestData не определена. Создаю резервную копию.');
        window.ftContestData = {
            ajax_url: '<?php echo admin_url('admin-ajax.php'); ?>',
            nonce: '<?php echo wp_create_nonce('ft_contest_nonce'); ?>'
        };
    } else {
        console.log('[DEBUG] ftContestData уже определена:', ftContestData);
    }

    jQuery(document).ready(function($) {
        console.log('[DEBUG] JavaScript для упрощенной формы загружен');
        
        // Функция для загрузки серверов конкурса
        function loadContestServers() {
            console.log('[DEBUG] Проверка элементов формы:', {
                'Форма найдена': $('#contest-account-form').length > 0,
                'Сервер найден': $('#server').length > 0,
                'Contest ID': $('#contest_id').val()
            });
            
            var contestId = $('#contest_id').val();
            var serverSelect = $('#server');
            
            if (!contestId) {
                console.error('[ERROR] Contest ID не найден');
                serverSelect.empty().append('<option value="">Ошибка: ID конкурса не определен</option>');
                return;
            }
            
            // Проверяем, не в режиме ли редактирования
            var isEditMode = $('#account_id').length > 0;
            if (isEditMode && serverSelect.find('option:selected').val()) {
                console.log('[DEBUG] Режим редактирования, сервер уже выбран');
                return;
            }
            
            // Загружаем серверы для конкурса
            $.ajax({
                url: '<?php echo admin_url('admin-ajax.php'); ?>',
                type: 'POST',
                data: {
                    action: 'get_contest_servers',
                    nonce: '<?php echo wp_create_nonce('ft_contest_nonce'); ?>',
                    contest_id: contestId
                },
                success: function(response) {
                    console.log('[DEBUG] Ответ серверы конкурса:', response);
                    
                    // Сохраняем выбранное значение если было
                    var selectedValue = serverSelect.val();
                    
                    serverSelect.empty().append('<option value="">Выберите сервер</option>');
                    
                    if (response.success && response.data.length > 0) {
                        $.each(response.data, function(i, server) {
                            serverSelect.append($('<option></option>')
                                .val(server.server_address)
                                .text(server.name)
                            );
                        });
                        
                        // Восстанавливаем выбранное значение
                        if (selectedValue) {
                            serverSelect.val(selectedValue);
                        }
                        
                        // Устанавливаем значение terminal на основе настроек конкурса
                        if (!$('#terminal').val()) {
                            // Получаем platform_id из настроек конкурса через AJAX
                            $.ajax({
                                url: '<?php echo admin_url('admin-ajax.php'); ?>',
                                type: 'POST',
                                async: false, // Синхронный запрос для получения платформы
                                data: {
                                    action: 'get_contest_platform',
                                    nonce: '<?php echo wp_create_nonce('ft_contest_nonce'); ?>',
                                    contest_id: contestId
                                },
                                success: function(response) {
                                    console.log('[DEBUG] Ответ платформы конкурса:', response);
                                    if (response.success && response.data.platform_slug) {
                                        var terminalValue = 'metatrader4'; // По умолчанию
                                        
                                        if (response.data.platform_slug === 'metatrader5' || response.data.platform_slug === 'mt5') {
                                            terminalValue = 'metatrader5';
                                        } else if (response.data.platform_slug === 'metatrader4' || response.data.platform_slug === 'mt4') {
                                            terminalValue = 'metatrader4';
                                        } else if (response.data.platform_slug === 'ctrader') {
                                            terminalValue = 'ctrader';
                                        }
                                        
                                        $('#terminal').val(terminalValue);
                                        console.log('[DEBUG] Установлен terminal на основе конкурса:', terminalValue);
                                    } else {
                                        $('#terminal').val('metatrader4'); // Резервное значение
                                        console.log('[DEBUG] Использован резервный terminal: metatrader4');
                                    }
                                },
                                error: function() {
                                    $('#terminal').val('metatrader4'); // Резервное значение при ошибке
                                    console.log('[DEBUG] Ошибка получения платформы, установлен metatrader4');
                                }
                            });
                        }
                        
                        console.log('[DEBUG] Серверы конкурса загружены успешно');
                    } else {
                        serverSelect.append('<option value="" disabled>Нет доступных серверов для этого конкурса</option>');
                        console.warn('[WARNING] Для конкурса не настроены серверы');
                    }
                },
                error: function(xhr, status, error) {
                    console.error('[ERROR] Ошибка загрузки серверов конкурса:', error);
                    serverSelect.empty().append('<option value="">Ошибка загрузки серверов</option>');
                }
            });
        }
        
        // Загружаем серверы сразу при загрузке страницы
        loadContestServers();
        
        // Также загружаем через короткое время на случай медленной загрузки
        setTimeout(function() {
            console.log('[DEBUG] Повторная загрузка серверов');
            loadContestServers();
        }, 500);
    });
</script>

<script type="text/javascript">
    (function ($) {
        $(document).ready(function () {
            // Обработчик отправки формы
            $('#contest-account-form').on('submit', function (e) {
                e.preventDefault();

                const $form = $(this);
                const $message = $('#form-message');
                const $submitButton = $form.find('button[type="submit"]');

                // Скрываем предыдущие сообщения и сбрасываем классы
                $message.hide().removeClass('error success info loading');

                // Определяем режим работы формы
                const isEditMode = $('#account_id').length > 0;
                const action = isEditMode ? 'update_contest_account_data' : 'register_contest_account';

                // Убедимся, что terminal установлен правильно перед отправкой
                if (!$('#terminal').val()) {
                    // Если терминал не установлен, попробуем установить на основе конкурса
                    const contestId = $('#contest_id').val();
                    
                    $.ajax({
                        url: ftContestData.ajax_url,
                        type: 'POST',
                        async: false, // Синхронный запрос
                        data: {
                            action: 'get_contest_platform',
                            nonce: ftContestData.nonce,
                            contest_id: contestId
                        },
                        success: function(response) {
                            if (response.success && response.data.platform_slug) {
                                var terminalValue = 'metatrader4'; // По умолчанию
                                
                                if (response.data.platform_slug === 'metatrader5' || response.data.platform_slug === 'mt5') {
                                    terminalValue = 'metatrader5';
                                } else if (response.data.platform_slug === 'metatrader4' || response.data.platform_slug === 'mt4') {
                                    terminalValue = 'metatrader4';
                                } else if (response.data.platform_slug === 'ctrader') {
                                    terminalValue = 'ctrader';
                                }
                                
                                $('#terminal').val(terminalValue);
                                console.log('[DEBUG] Установлен terminal перед отправкой формы:', terminalValue);
                            } else {
                                $('#terminal').val('metatrader4');
                                console.log('[DEBUG] Использован резервный terminal перед отправкой: metatrader4');
                            }
                        },
                        error: function() {
                            $('#terminal').val('metatrader4');
                            console.log('[DEBUG] Ошибка получения платформы перед отправкой, установлен metatrader4');
                        }
                    });
                }

                // Получаем данные формы
                const formData = {
                    action: action,
                    nonce: ftContestData.nonce,
                    contest_id: $('#contest_id').val(),
                    account_number: $('#account_number').val(),
                    password: $('#password').val(),
                    server: $('#server').val(),
                    terminal: $('#terminal').val()
                };

                // Выводим отладочную информацию о запросе
                console.log('[DEBUG] Отправка формы регистрации счета:', {
                    url: ftContestData.ajax_url,
                    action: action,
                    data: formData
                });

                // Если это режим редактирования, добавляем ID счета
                if (isEditMode) {
                    formData.account_id = $('#account_id').val();
                }

                // Блокируем кнопку и показываем сообщение о загрузке
                $submitButton.prop('disabled', true).text(isEditMode ? 'Сохранение...' : 'Проверка счета...');

                // Создаем сообщение с анимацией загрузки
                $message.empty()
                    .append(isEditMode ? 'Сохранение данных... ' : 'Пожалуйста, подождите. Проверка может занять до минуты... ')
                    .append(
                        $('<span>').addClass('progress-indicator')
                            .append($('<span>').addClass('wait-time').text('0'))
                            .append(' сек. ')
                            .append($('<span>').addClass('progress-dots'))
                    )
                    .addClass('info loading')
                    .show();

                // Добавляем таймер для обновления времени ожидания
                let waitTime = 0;
                const loadingInterval = setInterval(function () {
                    waitTime += 1;
                    $message.find('.wait-time').text(waitTime);
                }, 1000);

                // Отправляем AJAX запрос
                $.ajax({
                    url: ftContestData.ajax_url,
                    type: 'POST',
                    data: formData,
                    success: function (response) {
                        clearInterval(loadingInterval);
                        console.log('[DEBUG] Ответ от сервера:', response);

                        if (response.success) {
                            $submitButton.text(isEditMode ? 'Сохранено!' : 'Успешно!');
                            $message.empty()
                                .text(response.data.message)
                                .removeClass('info loading error')
                                .addClass('success')
                                .show();

                            if (isEditMode) {
                                // Для режима редактирования: скрываем форму и обновляем данные на странице
                                setTimeout(function () {
                                    $('#edit-account-form-container').slideUp(300);
                                    // Обновляем отображаемые данные счета без перезагрузки страницы
                                    if (response.data.server) {
                                        $('.account-server-value').text(response.data.server);
                                    }
                                    if (response.data.terminal) {
                                        $('.account-terminal-value').text(response.data.terminal);
                                    }
                                }, 2000);
                            } else {
                                // Для режима регистрации: перенаправляем на страницу счета
                                setTimeout(function () {
                                    window.location.href = response.data.redirect;
                                }, 2000);
                            }
                        } else {
                            $submitButton.prop('disabled', false).text(isEditMode ? 'Сохранить изменения' : 'Зарегистрировать счет');

                            // Обработка различных форматов ошибок
                            let errorMessage = isEditMode ? 'Произошла ошибка при сохранении данных' : 'Произошла ошибка при регистрации счета';

                            if (response.data && response.data.message) {
                                // Стандартный формат ошибки WordPress
                                errorMessage = response.data.message;
                            } else if (typeof response.data === 'string') {
                                // Простая строка ошибки
                                errorMessage = response.data;
                            }

                            // Специальная обработка сообщений об ошибках подключения
                            if (errorMessage.includes('Ошибка подключения:')) {
                                const errorDetails = errorMessage.replace('Ошибка подключения: ', '');
                                
                                // Создаем более подробное сообщение с рекомендациями
                                errorMessage = `
                                    <div class="error-details">
                                        <p><strong>Ошибка подключения:</strong> ${errorDetails}</p>
                                        <div class="error-help">
                                            <p><strong>Рекомендации:</strong></p>
                                            <ul>
                                                <li>Проверьте правильность введенного номера счета</li>
                                                <li>Проверьте правильность введенного пароля (для конкурса нужен инвесторский пароль)</li>
                                                <li>Убедитесь, что выбран правильный сервер</li>
                                                <li>Закройте терминал на вашем компьютере, если он подключен к этому счету</li>
                                                <li>Подождите несколько минут и попробуйте снова</li>
                                            </ul>
                                        </div>
                                    </div>
                                `;
                            }

                            $message.empty()
                                .html(errorMessage)
                                .removeClass('info loading success')
                                .addClass('error')
                                .show();

                            // Логируем ошибку для отладки
                            console.error('Ошибка ' + (isEditMode ? 'сохранения' : 'регистрации') + ' счета:', response);
                        }
                    },
                    error: function (xhr, status, error) {
                        clearInterval(loadingInterval);
                        $submitButton.prop('disabled', false).text(isEditMode ? 'Сохранить изменения' : 'Зарегистрировать счет');
                        
                        // Расширенная диагностика ошибки
                        console.error('[DEBUG] Детали AJAX ошибки:', {
                            status: status,
                            error: error,
                            statusText: xhr.statusText,
                            responseText: xhr.responseText,
                            readyState: xhr.readyState,
                            statusCode: xhr.status
                        });
                        
                        // Пытаемся распарсить ответ, если он в формате JSON
                        let detailedError = error || 'Неизвестная ошибка';
                        try {
                            if (xhr.responseText) {
                                const jsonResponse = JSON.parse(xhr.responseText);
                                if (jsonResponse.message) {
                                    detailedError = jsonResponse.message;
                                } else if (jsonResponse.data && jsonResponse.data.message) {
                                    detailedError = jsonResponse.data.message;
                                }
                            }
                        } catch (e) {
                            console.log('[DEBUG] Ответ не в формате JSON:', xhr.responseText);
                        }
                        
                        $message.empty()
                            .html('Произошла ошибка при отправке запроса: <strong>' + status + '</strong><br>' + 
                                 'Детали: ' + detailedError + '<br>' +
                                 'Статус: ' + xhr.status + ' ' + xhr.statusText)
                            .removeClass('info loading success')
                            .addClass('error')
                            .show();
                    },
                    timeout: 120000 // Увеличиваем таймаут до 2 минут
                });
            });

            // Обработчик кнопки отмены (только для режима редактирования)
            $('#cancel-edit-account').on('click', function () {
                $('#edit-account-form-container').slideUp(300);
            });

            // Добавьте этот код в скрипт, в блок $(document).ready
            // Функция для сброса состояния формы
            function resetFormState() {
                $('#contest-account-form .form-submit').text('Сохранить изменения').prop('disabled', false);
                $('#form-message').hide();
            }

            // Сброс при открытии формы
            $('#edit-account-button').on('click', function () {
                resetFormState();
            });

            // Сброс при изменении полей формы
            $('#contest-account-form input, #contest-account-form select').on('change keyup', function () {
                const $submitButton = $('#contest-account-form .form-submit');
                if ($submitButton.text() === 'Сохранено!') {
                    resetFormState();
                }
            });
        });
    })(jQuery);
</script>

<!-- Диагностика JavaScript состояния -->
<script type="text/javascript">
    console.log('[DIAGNOSTICS] Форма регистрации инициализируется...');
    console.log('[DIAGNOSTICS] jQuery загружен: ', typeof jQuery !== 'undefined');
    console.log('[DIAGNOSTICS] ftContestData доступен: ', typeof ftContestData !== 'undefined');
    console.log('[DIAGNOSTICS] document.readyState: ', document.readyState);
    
    document.addEventListener('DOMContentLoaded', function() {
        console.log('[DIAGNOSTICS] DOMContentLoaded событие сработало');
        console.log('[DIAGNOSTICS] Форма найдена: ', document.getElementById('contest-account-form') !== null);
        console.log('[DIAGNOSTICS] Список брокеров найден: ', document.getElementById('broker') !== null);
    });
    
    window.addEventListener('load', function() {
        console.log('[DIAGNOSTICS] window.load событие сработало');
        if (typeof ftContestData !== 'undefined') {
            console.log('[DIAGNOSTICS] ftContestData.ajax_url: ', ftContestData.ajax_url);
            console.log('[DIAGNOSTICS] ftContestData.nonce: ', ftContestData.nonce ? 'Установлен' : 'Не установлен');
        }
    });
</script>

.