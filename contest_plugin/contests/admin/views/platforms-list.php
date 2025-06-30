<?php if (!defined('ABSPATH')) exit; ?>

<div class="wrap">
    <h1>Торговые платформы</h1>
    
    <div class="notice notice-info">
        <p>Здесь вы можете управлять списком торговых платформ, которые будут доступны при регистрации счета.</p>
    </div>
    
    <div class="tablenav top">
        <div class="alignleft actions">
            <button type="button" class="button button-primary" id="add-platform-button">Добавить платформу</button>
        </div>
        <br class="clear">
    </div>
    
    <table class="wp-list-table widefat fixed striped platforms-table">
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
            <?php if (empty($platforms)): ?>
                <tr>
                    <td colspan="5">Платформы не найдены. Добавьте первую платформу!</td>
                </tr>
            <?php else: ?>
                <?php foreach ($platforms as $platform): ?>
                    <tr data-id="<?php echo esc_attr($platform->id); ?>">
                        <td class="column-id"><?php echo esc_html($platform->id); ?></td>
                        <td class="column-name"><?php echo esc_html($platform->name); ?></td>
                        <td class="column-slug"><?php echo esc_html($platform->slug); ?></td>
                        <td class="column-description"><?php echo esc_html($platform->description); ?></td>
                        <td class="column-actions">
                            <button type="button" class="button edit-platform" data-id="<?php echo esc_attr($platform->id); ?>">Редактировать</button>
                            <button type="button" class="button delete-platform" data-id="<?php echo esc_attr($platform->id); ?>">Удалить</button>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- Модальное окно для добавления/редактирования платформы -->
<div id="platform-modal" class="modal" style="display: none;">
    <div class="modal-content">
        <span class="close">&times;</span>
        <h2 id="modal-title">Добавить платформу</h2>
        
        <form id="platform-form">
            <input type="hidden" id="platform-id" name="id" value="">
            <input type="hidden" id="platform-nonce" name="nonce" value="<?php echo wp_create_nonce('broker_platform_nonce'); ?>">
            
            <table class="form-table">
                <tr>
                    <th><label for="platform-name">Название</label></th>
                    <td><input type="text" id="platform-name" name="name" class="regular-text" required></td>
                </tr>
                <tr>
                    <th><label for="platform-slug">Slug</label></th>
                    <td>
                        <input type="text" id="platform-slug" name="slug" class="regular-text">
                        <p class="description">Оставьте пустым для автоматической генерации из названия.</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="platform-description">Описание</label></th>
                    <td><textarea id="platform-description" name="description" rows="4" class="regular-text"></textarea></td>
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
        $('#add-platform-button').on('click', function() {
            resetForm();
            $('#modal-title').text('Добавить платформу');
            $('#platform-modal').show();
        });
        
        // Открытие модального окна для редактирования
        $('.edit-platform').on('click', function() {
            const platformId = $(this).data('id');
            const row = $('tr[data-id="' + platformId + '"]');
            
            resetForm();
            
            $('#platform-id').val(platformId);
            $('#platform-name').val(row.find('.column-name').text());
            $('#platform-slug').val(row.find('.column-slug').text());
            $('#platform-description').val(row.find('.column-description').text());
            
            $('#modal-title').text('Редактировать платформу');
            $('#platform-modal').show();
        });
        
        // Закрытие модального окна
        $('.close, .cancel-button').on('click', function() {
            $('#platform-modal').hide();
        });
        
        // Сброс формы
        function resetForm() {
            $('#platform-form')[0].reset();
            $('#platform-id').val('');
            $('#form-message').hide().removeClass('notice-success notice-error').empty();
        }
        
        // Отправка формы
        $('#platform-form').on('submit', function(e) {
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
                action: $('#platform-id').val() ? 'update_platform' : 'add_platform',
                id: $('#platform-id').val(),
                name: $('#platform-name').val(),
                slug: $('#platform-slug').val(),
                description: $('#platform-description').val(),
                nonce: $('#platform-nonce').val()
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
        
        // Удаление платформы
        $('.delete-platform').on('click', function() {
            if (!confirm('Вы уверены, что хотите удалить эту платформу?')) {
                return;
            }
            
            const platformId = $(this).data('id');
            
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'delete_platform',
                    id: platformId,
                    nonce: $('#platform-nonce').val()
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
    .platforms-table .column-id {
        width: 50px;
    }
    
    .platforms-table .column-name {
        width: 200px;
    }
    
    .platforms-table .column-slug {
        width: 150px;
    }
    
    .platforms-table .column-actions {
        width: 200px;
    }
</style> 