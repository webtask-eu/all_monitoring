<?php
/**
 * Внешний cron для обработки задач WordPress и очередей конкурсов
 * Запускается каждую минуту системным cron
 * V3.1 - защита от дублирования по статусу счетов
 * 
 * @package ITX_Contest_Plugin
 * @author IntellaraX
 * @version 3.1
 */

// Загружаем WordPress (путь от плагина к корню)
require_once dirname(dirname(dirname(dirname(__FILE__)))) . "/wp-load.php";

// Принудительно включаем WP Cron для этого запуска
define("DOING_CRON", true);

// Логируем запуск
error_log("=== ВНЕШНИЙ CRON V3.1 ЗАПУСК ===");
error_log("Время: " . date("Y-m-d H:i:s") . " UTC");

// Получаем все запланированные задачи
$crons = _get_cron_array();
$current_time = time();
$executed_tasks = 0;

if (!empty($crons)) {
    foreach ($crons as $timestamp => $hooks) {
        // Обрабатываем только просроченные задачи
        if ($timestamp <= $current_time) {
            foreach ($hooks as $hook => $scheduled) {
                // Обрабатываем задачи конкурсов
                if (in_array($hook, [
                    "contest_create_queues", 
                    "process_accounts_update_batch"
                ])) {
                    foreach ($scheduled as $md5 => $event) {
                        $args = $event["args"];
                        error_log("Выполнение просроченной задачи: $hook с аргументами: " . json_encode($args));
                        
                        // Выполняем задачу
                        do_action_ref_array($hook, $args);
                        $executed_tasks++;
                        
                        // Удаляем выполненную задачу
                        wp_unschedule_event($timestamp, $hook, $args);
                    }
                }
            }
        }
    }
}

error_log("Выполнено задач: $executed_tasks");

// Если WP Cron не отключен, запускаем стандартный spawn_cron
if (!defined("DISABLE_WP_CRON") || !DISABLE_WP_CRON) {
    error_log("Запуск spawn_cron()...");
    spawn_cron();
    error_log("spawn_cron() завершен");
}

error_log("=== КОНЕЦ ВНЕШНЕГО CRON V3.1 ==="); 