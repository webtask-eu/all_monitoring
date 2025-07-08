<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Класс для AJAX-обработчиков, связанных со счетами
 */
class Account_AJAX_Handlers {

    /**
     * Конструктор класса
     */
    public function __construct() {
        // Существующие обработчики
        add_action('wp_ajax_get_account_chart_data', [$this, 'get_account_chart_data']);
        add_action('wp_ajax_nopriv_get_account_chart_data', [$this, 'get_account_chart_data']);
        
        // Новые обработчики для графика лидеров
        add_action('wp_ajax_get_leaders_chart_data', [$this, 'get_leaders_chart_data']);
        add_action('wp_ajax_nopriv_get_leaders_chart_data', [$this, 'get_leaders_chart_data']);
    }

    /**
     * AJAX-обработчик для получения данных графика лидеров
     */
    public function get_leaders_chart_data() {
        // Проверяем nonce для безопасности

        $contest_id = isset($_POST['contest_id']) ? intval($_POST['contest_id']) : 0;
        $top_count = isset($_POST['top_count']) ? intval($_POST['top_count']) : 3;
        $period = isset($_POST['period']) ? sanitize_text_field($_POST['period']) : 'all';
        
        if (!$contest_id) {
            wp_send_json_error(['message' => 'ID конкурса не указан']);
        }
        
        // Получаем данные графика лидеров
        $chart_data = new Account_Chart_Data();
        $data = $chart_data->get_leaders_chart_data($contest_id, $top_count, $period);
        
        wp_send_json_success($data);
    }
    /**
     * AJAX-обработчик для получения данных графика
     */
    public function get_account_chart_data() {
        // Проверяем nonce для безопасности

        
        $account_id = isset($_POST['account_id']) ? intval($_POST['account_id']) : 0;
        $period = isset($_POST['period']) ? sanitize_text_field($_POST['period']) : 'all';
        
        if (!$account_id) {
            wp_send_json_error(['message' => 'ID счета не указан']);
        }
        
        // Получаем данные графика
        $chart_data = new Account_Chart_Data();
        $data = $chart_data->get_chart_data($account_id, $period);
        
        wp_send_json_success($data);
    }
}

// Инициализируем класс
new Account_AJAX_Handlers();

/**
 * AJAX обработчик для получения информации об активных очередях обновления
 */
function ajax_get_active_update_queues() {
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
    
    // Получаем HTML с информацией об активных очередях
    $html = get_active_update_queues_html();
    
    // Проверяем, есть ли активные очереди
    $has_active_queues = (strpos($html, 'notice-info') === false);
    
    // Отправляем ответ
    wp_send_json_success([
        'html' => $html,
        'has_active_queues' => $has_active_queues
    ]);
}

// Регистрируем обработчик AJAX
add_action('wp_ajax_get_active_update_queues', 'ajax_get_active_update_queues');
