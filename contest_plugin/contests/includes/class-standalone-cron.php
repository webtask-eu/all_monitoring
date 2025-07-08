<?php
/**
 * Полностью автономный класс для управления cron без зависимостей
 * Заменяет функциональность из fortrader-settings.php
 * 
 * @version 1.0.0
 * @author IntellaraX
 */

class FT_Standalone_Cron {
    
    private static $instance = null;
    
    private function __construct() {
        // Регистрируем хук для автоматического обновления
        add_action('contest_create_queues', array($this, 'handle_auto_update'));
        
        // Регистрируем кастомные интервалы cron
        add_filter('cron_schedules', array($this, 'add_cron_intervals'));
        
        // Инициализируем при загрузке плагина
        add_action('plugins_loaded', array($this, 'init_auto_update'));
    }
    
    /**
     * Singleton instance
     */
    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Добавляет кастомные интервалы cron
     */
    public function add_cron_intervals($schedules) {
        $schedules['contest_auto_update'] = array(
            'interval' => 300, // 5 минут
            'display' => 'Каждые 5 минут (FT Contests)'
        );
        
        $schedules['contest_auto_update_1min'] = array(
            'interval' => 60,  // 1 минута
            'display' => 'Каждую минуту (FT Contests)'
        );
        
        $schedules['contest_auto_update_10min'] = array(
            'interval' => 600, // 10 минут
            'display' => 'Каждые 10 минут (FT Contests)'
        );
        
        return $schedules;
    }
    
    /**
     * Инициализирует автоматическое обновление
     */
    public function init_auto_update() {
        // Проверяем, активно ли автоматическое обновление
        $settings = get_option('fttrader_auto_update_settings', array());
        $enabled = isset($settings['fttrader_auto_update_enabled']) ? $settings['fttrader_auto_update_enabled'] : false;
        
        if (!$enabled) {
            return;
        }
        
        // Проверяем, есть ли расписание
        if (!wp_next_scheduled('contest_create_queues')) {
            // Создаем расписание если его нет
            wp_schedule_event(time(), 'contest_auto_update', 'contest_create_queues');
        }
    }
    
    /**
     * Обрабатывает автоматическое обновление счетов
     */
    public function handle_auto_update() {
        // Проверяем, загружен ли класс Account_Updater
        if (!class_exists('Account_Updater')) {
            error_log('FT_Standalone_Cron: Account_Updater не найден');
            return;
        }
        
        // Удалено преждевременное обновление времени последнего запуска (дублировало логику в Account_Updater::run_auto_update)
        
        try {
            // Вызываем автоматическое обновление
            Account_Updater::run_auto_update();
        } catch (Exception $e) {
            error_log('FT_Standalone_Cron: Ошибка автоматического обновления: ' . $e->getMessage());
        }
    }
    
    /**
     * Получает статус автоматического обновления
     */
    public function get_auto_update_status() {
        $settings = get_option('fttrader_auto_update_settings', array());
        $enabled = isset($settings['fttrader_auto_update_enabled']) ? $settings['fttrader_auto_update_enabled'] : false;
        $last_run = get_option('contest_create_queues_last_run', 0);
        $next_run = wp_next_scheduled('contest_create_queues');
        
        return array(
            'enabled' => $enabled,
            'last_run' => $last_run,
            'next_run' => $next_run,
            'last_run_formatted' => $last_run ? date('d.m.Y H:i:s', $last_run) : 'Никогда',
            'next_run_formatted' => $next_run ? date('d.m.Y H:i:s', $next_run) : 'Не запланирован'
        );
    }
    
    /**
     * Принудительно запускает автоматическое обновление
     */
    public function force_run_auto_update() {
        do_action('contest_create_queues');
    }
    
    /**
     * Восстанавливает расписание автоматического обновления
     */
    public function restore_schedule() {
        // Удаляем существующее расписание
        $timestamp = wp_next_scheduled('contest_create_queues');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'contest_create_queues');
        }
        
        // Создаем новое расписание
        wp_schedule_event(time(), 'contest_auto_update', 'contest_create_queues');
        
        return wp_next_scheduled('contest_create_queues');
    }
}

// Инициализируем автономный cron
FT_Standalone_Cron::get_instance(); 