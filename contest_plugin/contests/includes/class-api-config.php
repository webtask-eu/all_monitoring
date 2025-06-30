<?php
/**
 * Класс для управления настройками API-соединения
 */
class FT_API_Config {
    /**
     * Получает URL API в зависимости от текущих настроек
     *
     * @return string URL для запросов к API
     */
    public static function get_api_url() {
        // Проверяем настройку режима API
        $api_mode = get_option('ft_api_mode', 'proxy'); // proxy или direct
        
        if ($api_mode === 'direct') {
            // Прямой режим подключения к SERVERAPI (без WebAPI)
            $server_ip = get_option('ft_server_api_ip', 'localhost');
            $server_port = get_option('ft_server_api_port', '80');
            return "http://{$server_ip}:{$server_port}/api_json_wp.php";
        } else {
            // Стандартный режим через WebAPI (128.140.100.35)
            return "http://128.140.100.35/api_json_wp.php";
        }
    }
    
    /**
     * Проверяет доступность SERVERAPI при использовании прямого режима
     *
     * @return array Результат проверки с сообщением и статусом
     */
    public static function check_direct_connection() {
        $server_ip = get_option('ft_server_api_ip', 'localhost');
        $server_port = get_option('ft_server_api_port', '80');
        $url = "http://{$server_ip}:{$server_port}/";
        
        $response = wp_remote_get($url, ['timeout' => 5]);
        
        if (is_wp_error($response)) {
            return [
                'success' => false,
                'message' => 'Ошибка соединения: ' . $response->get_error_message()
            ];
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        
        if ($status_code === 200) {
            $data = json_decode($body, true);
            if (isset($data['status']) && $data['status'] === 'online') {
                return [
                    'success' => true,
                    'message' => 'Соединение с SERVERAPI установлено успешно'
                ];
            }
        }
        
        return [
            'success' => false,
            'message' => "Не удалось установить соединение. Код ответа: {$status_code}"
        ];
    }
}

/**
 * Функция для добавления страницы настроек API в админ-панель WordPress
 */
function ft_api_settings_page() {
    add_options_page(
        'Настройки API',
        'Настройки API конкурсов',
        'manage_options',
        'ft_api_settings',
        'ft_api_settings_page_html'
    );
}
add_action('admin_menu', 'ft_api_settings_page');

/**
 * Callback-функция для отображения страницы настроек
 */
function ft_api_settings_page_html() {
    // Проверка прав доступа
    if (!current_user_can('manage_options')) {
        return;
    }
    
    // Обработка формы
    if (isset($_POST['ft_api_settings_submit'])) {
        check_admin_referer('ft_api_settings_nonce');
        
        $api_mode = sanitize_text_field($_POST['ft_api_mode']);
        $server_ip = sanitize_text_field($_POST['ft_server_api_ip']);
        $server_port = sanitize_text_field($_POST['ft_server_api_port']);
        
        update_option('ft_api_mode', $api_mode);
        update_option('ft_server_api_ip', $server_ip);
        update_option('ft_server_api_port', $server_port);
        
        echo '<div class="notice notice-success is-dismissible"><p>Настройки сохранены.</p></div>';
    }
    
    // Получение текущих настроек
    $api_mode = get_option('ft_api_mode', 'proxy');
    $server_ip = get_option('ft_server_api_ip', 'localhost');
    $server_port = get_option('ft_server_api_port', '80');
    
    // Проверка соединения при нажатии на кнопку
    if (isset($_POST['ft_check_connection'])) {
        check_admin_referer('ft_api_settings_nonce');
        $result = FT_API_Config::check_direct_connection();
        
        if ($result['success']) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($result['message']) . '</p></div>';
        } else {
            echo '<div class="notice notice-error is-dismissible"><p>' . esc_html($result['message']) . '</p></div>';
        }
    }
    
    ?>
    <div class="wrap">
        <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
        <form method="post" action="">
            <?php wp_nonce_field('ft_api_settings_nonce'); ?>
            <table class="form-table">
                <tr>
                    <th scope="row">Режим API</th>
                    <td>
                        <select name="ft_api_mode" id="ft_api_mode">
                            <option value="proxy" <?php selected($api_mode, 'proxy'); ?>>Через WebAPI (128.140.100.35)</option>
                            <option value="direct" <?php selected($api_mode, 'direct'); ?>>Прямое подключение к SERVERAPI</option>
                        </select>
                        <p class="description">Способ подключения к серверу MT4</p>
                    </td>
                </tr>
                <tr id="server_settings" style="<?php echo $api_mode === 'direct' ? '' : 'display: none;'; ?>">
                    <th scope="row">Настройки SERVERAPI</th>
                    <td>
                        <label for="ft_server_api_ip">IP-адрес сервера:</label>
                        <input type="text" id="ft_server_api_ip" name="ft_server_api_ip" value="<?php echo esc_attr($server_ip); ?>" class="regular-text">
                        <br>
                        <label for="ft_server_api_port">Порт:</label>
                        <input type="text" id="ft_server_api_port" name="ft_server_api_port" value="<?php echo esc_attr($server_port); ?>" class="small-text">
                        <p class="description">IP-адрес и порт сервера SERVERAPI для прямого подключения</p>
                    </td>
                </tr>
            </table>
            <p class="submit">
                <input type="submit" name="ft_api_settings_submit" class="button-primary" value="Сохранить настройки">
                <?php if ($api_mode === 'direct'): ?>
                <input type="submit" name="ft_check_connection" class="button-secondary" value="Проверить соединение">
                <?php endif; ?>
            </p>
        </form>
    </div>
    <script>
    jQuery(document).ready(function($) {
        $('#ft_api_mode').on('change', function() {
            if ($(this).val() === 'direct') {
                $('#server_settings').show();
            } else {
                $('#server_settings').hide();
            }
        });
    });
    </script>
    <?php
} 