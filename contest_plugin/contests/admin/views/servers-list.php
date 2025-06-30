<?php if (!defined('ABSPATH')) exit; ?>

<div class="wrap">
    <h1>Серверы брокеров</h1>
    
    <div class="notice notice-info">
        <p>Здесь вы можете управлять списком серверов брокеров, которые будут доступны при регистрации счета.</p>
    </div>
    
    <div class="tablenav top">
        <div class="alignleft actions">
            <button type="button" class="button button-primary" id="add-server-button">Добавить сервер</button>
        </div>
        <br class="clear">
    </div>
    
    <table class="wp-list-table widefat fixed striped servers-table">
        <thead>
            <tr>
                <th class="column-id">ID</th>
                <th class="column-broker">Брокер</th>
                <th class="column-platform">Платформа</th>
                <th class="column-name">Название</th>
                <th class="column-address">Адрес сервера</th>
                <th class="column-description">Описание</th>
                <th class="column-actions">Действия</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($servers)): ?>
                <tr>
                    <td colspan="7">Серверы не найдены. Добавьте первый сервер!</td>
                </tr>
            <?php else: ?>
                <?php foreach ($servers as $server): ?>
                    <tr data-id="<?php echo esc_attr($server->id); ?>">
                        <td class="column-id"><?php echo esc_html($server->id); ?></td>
                        <td class="column-broker" data-broker-id="<?php echo esc_attr($server->broker_id); ?>">
                            <?php echo esc_html($server->broker_name); ?>
                        </td>
                        <td class="column-platform" data-platform-id="<?php echo esc_attr($server->platform_id); ?>">
                            <?php echo esc_html($server->platform_name); ?>
                        </td>
                        <td class="column-name"><?php echo esc_html($server->name); ?></td>
                        <td class="column-address"><?php echo esc_html($server->server_address); ?></td>
                        <td class="column-description"><?php echo esc_html($server->description); ?></td>
                        <td class="column-actions">
                            <button type="button" class="button edit-server" data-id="<?php echo esc_attr($server->id); ?>">Редактировать</button>
                            <button type="button" class="button delete-server" data-id="<?php echo esc_attr($server->id); ?>">Удалить</button>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- Модальное окно для добавления/редактирования сервера -->
<div id="server-modal" class="modal" style="display: none;">
    <div class="modal-content">
        <span class="close">&times;</span>
        <h2 id="modal-title">Добавить сервер</h2>
        
        <form id="server-form">
            <input type="hidden" id="server-id" name="id" value="">
            <input type="hidden" id="server-nonce" name="nonce" value="<?php echo wp_create_nonce('broker_platform_nonce'); ?>">
            
            <table class="form-table">
                <tr>
                    <th><label for="broker-id">Брокер</label></th>
                    <td>
                        <select id="broker-id" name="broker_id" class="regular-text" required>
                            <option value="">Выберите брокера</option>
                            <?php foreach ($brokers as $broker): ?>
                                <option value="<?php echo esc_attr($broker->id); ?>"><?php echo esc_html($broker->name); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th><label for="platform-id">Платформа</label></th>
                    <td>
                        <select id="platform-id" name="platform_id" class="regular-text" required>
                            <option value="">Выберите платформу</option>
                            <?php foreach ($platforms as $platform): ?>
                                <option value="<?php echo esc_attr($platform->id); ?>"><?php echo esc_html($platform->name); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th><label for="server-name">Название</label></th>
                    <td><input type="text" id="server-name" name="name" class="regular-text" required></td>
                </tr>
                <tr>
                    <th><label for="server-address">Адрес сервера</label></th>
                    <td><input type="text" id="server-address" name="server_address" class="regular-text" required></td>
                </tr>
                <tr>
                    <th><label for="server-description">Описание</label></th>
                    <td><textarea id="server-description" name="description" rows="4" class="regular-text"></textarea></td>
                </tr>
            </table>
            
            <div class="submit-container">
                <button type="submit" class="button button-primary">Сохранить</button>
                <button type="button" class="button cancel-button">Отмена</button>
                <span class="spinner" style="float: none; display: none;"></span>
            </div>
            
            <div id="form-message" class="notice" style="display: none;"></div>
        </form>
    </div>
</div>

<script type="text/javascript">
    jQuery(document).ready(function($) {
        // Открытие модального окна для добавления
        $('#add-server-button').on('click', function() {
            resetForm();
            $('#modal-title').text('Добавить сервер');
            $('#server-modal').show();
        });
        
        // Открытие модального окна для редактирования
        $('.edit-server').on('click', function() {
            const serverId = $(this).data('id');
            const row = $('tr[data-id="' + serverId + '"]');
            
            resetForm();
            
            $('#server-id').val(serverId);
            $('#broker-id').val(row.find('.column-broker').data('broker-id'));
            $('#platform-id').val(row.find('.column-platform').data('platform-id'));
            $('#server-name').val(row.find('.column-name').text().trim());
            $('#server-address').val(row.find('.column-address').text().trim());
            $('#server-description').val(row.find('.column-description').text().trim());
            
            $('#modal-title').text('Редактировать сервер');
            $('#server-modal').show();
        });
        
        // Закрытие модального окна
        $('.close, .cancel-button').on('click', function() {
            $('#server-modal').hide();
        });
        
        // Сброс формы
        function resetForm() {
            $('#server-form')[0].reset();
            $('#server-id').val('');
            $('#form-message').hide().removeClass('notice-success notice-error').empty();
        }
        
        // Отправка формы
        $('#server-form').on('submit', function(e) {
            e.preventDefault();
            
            const $form = $(this);
            const $spinner = $form.find('.spinner');
            const $message = $('#form-message');
            const $submitButton = $form.find('button[type="submit"]');
            
            // Показываем индикатор загрузки
            $spinner.css('display', 'inline-block');
            $submitButton.prop('disabled', true);
            
            // Подготавливаем данные
            const data = {
                action: $('#server-id').val() ? 'update_broker_server' : 'add_broker_server',
                id: $('#server-id').val(),
                broker_id: $('#broker-id').val(),
                platform_id: $('#platform-id').val(),
                name: $('#server-name').val(),
                server_address: $('#server-address').val(),
                description: $('#server-description').val(),
                nonce: $('#server-nonce').val()
            };
            
            // Отправляем AJAX запрос
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: data,
                success: function(response) {
                    $spinner.hide();
                    $submitButton.prop('disabled', false);
                    
                    if (response.success) {
                        $message.removeClass('notice-error').addClass('notice-success').html('<p>' + response.data.message + '</p>').show();
                        
                        // Перезагружаем страницу через 1 секунду
                        setTimeout(function() {
                            location.reload();
                        }, 1000);
                    } else {
                        $message.removeClass('notice-success').addClass('notice-error').html('<p>' + response.data.message + '</p>').show();
                    }
                },
                error: function() {
                    $spinner.hide();
                    $submitButton.prop('disabled', false);
                    $message.removeClass('notice-success').addClass('notice-error').html('<p>Произошла ошибка при отправке запроса. Пожалуйста, попробуйте позже.</p>').show();
                }
            });
        });
        
        // Удаление сервера
        $('.delete-server').on('click', function() {
            if (!confirm('Вы уверены, что хотите удалить этот сервер?')) {
                return;
            }
            
            const serverId = $(this).data('id');
            
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'delete_broker_server',
                    id: serverId,
                    nonce: $('#server-nonce').val()
                },
                success: function(response) {
                    if (response.success) {
                        alert(response.data.message);
                        location.reload();
                    } else {
                        alert(response.data.message);
                    }
                },
                error: function() {
                    alert('Произошла ошибка при отправке запроса. Пожалуйста, попробуйте позже.');
                }
            });
        });
    });
</script>

<style>
    /* Стили для модального окна */
    .modal {
        display: none;
        position: fixed;
        z-index: 1000;
        left: 0;
        top: 0;
        width: 100%;
        height: 100%;
        overflow: auto;
        background-color: rgba(0,0,0,0.4);
    }
    
    .modal-content {
        background-color: #fefefe;
        margin: 5% auto;
        padding: 20px;
        border: 1px solid #888;
        width: 50%;
        max-width: 700px;
        box-shadow: 0 4px 8px 0 rgba(0,0,0,0.2);
    }
    
    .close {
        color: #aaa;
        float: right;
        font-size: 28px;
        font-weight: bold;
        cursor: pointer;
    }
    
    .close:hover,
    .close:focus {
        color: black;
        text-decoration: none;
    }
    
    .submit-container {
        margin-top: 20px;
        padding: 10px 0;
    }
    
    /* Стили для таблицы */
    .servers-table .column-id {
        width: 50px;
    }
    
    .servers-table .column-broker {
        width: 150px;
    }
    
    .servers-table .column-platform {
        width: 150px;
    }
    
    .servers-table .column-name {
        width: 150px;
    }
    
    .servers-table .column-address {
        width: 200px;
    }
    
    .servers-table .column-actions {
        width: 200px;
    }
</style> 