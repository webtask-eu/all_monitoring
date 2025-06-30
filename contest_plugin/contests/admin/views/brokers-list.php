<?php if (!defined('ABSPATH')) exit; ?>

<div class="wrap">
    <h1>Брокеры</h1>
    
    <div class="notice notice-info">
        <p>Здесь вы можете управлять списком брокеров, которые будут доступны при регистрации счета.</p>
    </div>
    
    <div class="tablenav top">
        <div class="alignleft actions">
            <button type="button" class="button button-primary" id="add-broker-button">Добавить брокера</button>
        </div>
        <br class="clear">
    </div>
    
    <table class="wp-list-table widefat fixed striped brokers-table">
        <thead>
            <tr>
                <th class="column-id">ID</th>
                <th class="column-name">Название</th>
                <th class="column-slug">Slug</th>
                <th class="column-description">Описание</th>
                <th class="column-actions">Действия</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($brokers)): ?>
                <tr>
                    <td colspan="5">Брокеры не найдены. Добавьте первого брокера!</td>
                </tr>
            <?php else: ?>
                <?php foreach ($brokers as $broker): ?>
                    <tr data-id="<?php echo esc_attr($broker->id); ?>">
                        <td class="column-id"><?php echo esc_html($broker->id); ?></td>
                        <td class="column-name"><?php echo esc_html($broker->name); ?></td>
                        <td class="column-slug"><?php echo esc_html($broker->slug); ?></td>
                        <td class="column-description"><?php echo esc_html($broker->description); ?></td>
                        <td class="column-actions">
                            <button type="button" class="button edit-broker" data-id="<?php echo esc_attr($broker->id); ?>">Редактировать</button>
                            <button type="button" class="button delete-broker" data-id="<?php echo esc_attr($broker->id); ?>">Удалить</button>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- Модальное окно для добавления/редактирования брокера -->
<div id="broker-modal" class="modal" style="display: none;">
    <div class="modal-content">
        <span class="close">&times;</span>
        <h2 id="modal-title">Добавить брокера</h2>
        
        <form id="broker-form">
            <input type="hidden" id="broker-id" name="id" value="">
            <input type="hidden" id="broker-nonce" name="nonce" value="<?php echo wp_create_nonce('broker_platform_nonce'); ?>">
            
            <table class="form-table">
                <tr>
                    <th><label for="broker-name">Название</label></th>
                    <td><input type="text" id="broker-name" name="name" class="regular-text" required></td>
                </tr>
                <tr>
                    <th><label for="broker-slug">Slug</label></th>
                    <td>
                        <input type="text" id="broker-slug" name="slug" class="regular-text">
                        <p class="description">Оставьте пустым для автоматической генерации из названия.</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="broker-description">Описание</label></th>
                    <td><textarea id="broker-description" name="description" rows="4" class="regular-text"></textarea></td>
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
        $('#add-broker-button').on('click', function() {
            resetForm();
            $('#modal-title').text('Добавить брокера');
            $('#broker-modal').show();
        });
        
        // Открытие модального окна для редактирования
        $('.edit-broker').on('click', function() {
            const brokerId = $(this).data('id');
            const row = $('tr[data-id="' + brokerId + '"]');
            
            resetForm();
            
            $('#broker-id').val(brokerId);
            $('#broker-name').val(row.find('.column-name').text());
            $('#broker-slug').val(row.find('.column-slug').text());
            $('#broker-description').val(row.find('.column-description').text());
            
            $('#modal-title').text('Редактировать брокера');
            $('#broker-modal').show();
        });
        
        // Закрытие модального окна
        $('.close, .cancel-button').on('click', function() {
            $('#broker-modal').hide();
        });
        
        // Сброс формы
        function resetForm() {
            $('#broker-form')[0].reset();
            $('#broker-id').val('');
            $('#form-message').hide().removeClass('notice-success notice-error').empty();
        }
        
        // Отправка формы
        $('#broker-form').on('submit', function(e) {
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
                action: $('#broker-id').val() ? 'update_broker' : 'add_broker',
                id: $('#broker-id').val(),
                name: $('#broker-name').val(),
                slug: $('#broker-slug').val(),
                description: $('#broker-description').val(),
                nonce: $('#broker-nonce').val()
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
        
        // Удаление брокера
        $('.delete-broker').on('click', function() {
            if (!confirm('Вы уверены, что хотите удалить этого брокера?')) {
                return;
            }
            
            const brokerId = $(this).data('id');
            
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'delete_broker',
                    id: brokerId,
                    nonce: $('#broker-nonce').val()
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
    .brokers-table .column-id {
        width: 50px;
    }
    
    .brokers-table .column-name {
        width: 200px;
    }
    
    .brokers-table .column-slug {
        width: 150px;
    }
    
    .brokers-table .column-actions {
        width: 200px;
    }
</style> 