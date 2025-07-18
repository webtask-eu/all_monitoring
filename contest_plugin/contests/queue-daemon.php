<?php
/**
 * Специализированный демон для обработки очередей конкурсов
 * Работает независимо от WordPress cron
 * Выполняет задачи с точностью до секунды
 * 
 * @package ITX_Contest_Plugin
 * @author IntellaraX
 * @version 1.0
 */

// Проверка запуска из CLI
if (php_sapi_name() !== 'cli') {
    die('Этот скрипт может быть запущен только из командной строки');
}

// Загружаем WordPress
require_once __DIR__ . '/../../../wp-load.php';

// Загружаем Account_Updater для регистрации hooks
require_once __DIR__ . '/includes/class-account-updater.php';

// Настройки демона
$daemon_config = [
    'check_interval' => 1, // Проверка каждую секунду
    'max_execution_time' => 3600, // Максимум 1 час работы
    'memory_limit' => '256M',
    'log_file' => __DIR__ . '/includes/logs/queue_daemon.log',
    'pid_file' => '/tmp/contest_queue_daemon.pid'
];

// Настройки PHP
ini_set('memory_limit', $daemon_config['memory_limit']);
set_time_limit(0); // Без ограничения времени

class QueueDaemon {
    private $config;
    private $start_time;
    private $running = true;
    
    public function __construct($config) {
        $this->config = $config;
        $this->start_time = time();
        $this->setupSignalHandlers();
    }
    
    /**
     * Настройка обработчиков сигналов для корректного завершения
     */
    private function setupSignalHandlers() {
        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGTERM, [$this, 'shutdown']);
            pcntl_signal(SIGINT, [$this, 'shutdown']);
        }
    }
    
    /**
     * Корректное завершение демона
     */
    public function shutdown() {
        $this->running = false;
        $this->log("Получен сигнал завершения. Останавливаем демон...");
        if (file_exists($this->config['pid_file'])) {
            unlink($this->config['pid_file']);
        }
    }
    
    /**
     * Логирование
     */
    private function log($message) {
        $timestamp = date('Y-m-d H:i:s');
        $log_message = "[$timestamp] [QUEUE-DAEMON] $message\n";
        
        // В файл
        file_put_contents($this->config['log_file'], $log_message, FILE_APPEND | LOCK_EX);
        
        // В консоль
        echo $log_message;
    }
    
    /**
     * Проверка PID файла для предотвращения дублирования
     */
    private function checkPidFile() {
        if (file_exists($this->config['pid_file'])) {
            $pid = file_get_contents($this->config['pid_file']);
            if ($pid && posix_kill($pid, 0)) {
                die("Демон уже запущен с PID: $pid\n");
            }
        }
        
        // Создаем PID файл
        file_put_contents($this->config['pid_file'], getmypid());
    }
    
    /**
     * Получение задач для выполнения
     */
    private function getPendingTasks() {
        $crons = _get_cron_array();
        $current_time = time();
        $pending_tasks = [];
        
        if (!empty($crons)) {
            foreach ($crons as $timestamp => $hooks) {
                // Обрабатываем только просроченные/текущие задачи
                if ($timestamp <= $current_time) {
                    foreach ($hooks as $hook => $scheduled) {
                        // Только задачи обработки очередей
                        if ($hook === 'process_accounts_update_batch') {
                            foreach ($scheduled as $md5 => $event) {
                                $pending_tasks[] = [
                                    'timestamp' => $timestamp,
                                    'hook' => $hook,
                                    'args' => $event['args'],
                                    'md5' => $md5
                                ];
                            }
                        }
                    }
                }
            }
        }
        
        return $pending_tasks;
    }
    
    /**
     * Выполнение задачи
     */
    private function executeTask($task) {
        $this->log("Выполняется задача: {$task['hook']} с аргументами: " . json_encode($task['args']));
        
        try {
            // Выполняем задачу
            do_action_ref_array($task['hook'], $task['args']);
            
            // Удаляем выполненную задачу
            wp_unschedule_event($task['timestamp'], $task['hook'], $task['args']);
            
            $this->log("Задача выполнена успешно");
            return true;
        } catch (Exception $e) {
            $this->log("ОШИБКА при выполнении задачи: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Основной цикл демона
     */
    public function run() {
        $this->checkPidFile();
        $this->log("=== ЗАПУСК QUEUE DAEMON V1.0 ===");
        $this->log("PID: " . getmypid());
        $this->log("Интервал проверки: {$this->config['check_interval']} сек");
        $this->log("Максимальное время работы: {$this->config['max_execution_time']} сек");
        
        $last_stats = time();
        $tasks_executed = 0;
        
        while ($this->running) {
            // Проверка сигналов (если доступно)
            if (function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }
            
            // Проверка максимального времени работы
            if (time() - $this->start_time > $this->config['max_execution_time']) {
                $this->log("Достигнуто максимальное время работы. Завершение.");
                break;
            }
            
            // Получаем задачи для выполнения
            $tasks = $this->getPendingTasks();
            
            if (!empty($tasks)) {
                $this->log("Найдено " . count($tasks) . " задач для выполнения");
                
                foreach ($tasks as $task) {
                    if ($this->executeTask($task)) {
                        $tasks_executed++;
                    }
                }
            }
            
            // Статистика каждые 60 секунд
            if (time() - $last_stats >= 60) {
                $uptime = time() - $this->start_time;
                $this->log("Статистика: выполнено задач: $tasks_executed, время работы: {$uptime} сек");
                $last_stats = time();
            }
            
            // Ожидание следующей проверки
            sleep($this->config['check_interval']);
        }
        
        $this->log("=== ЗАВЕРШЕНИЕ QUEUE DAEMON ===");
        $this->log("Всего выполнено задач: $tasks_executed");
        
        // Удаляем PID файл
        if (file_exists($this->config['pid_file'])) {
            unlink($this->config['pid_file']);
        }
    }
}

// Запуск демона
$daemon = new QueueDaemon($daemon_config);
$daemon->run(); 