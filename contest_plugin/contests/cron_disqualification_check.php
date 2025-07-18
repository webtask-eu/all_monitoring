#!/usr/bin/env php
<?php
/**
 * Крон-скрипт для проверки дисквалификации счетов
 * Запускается раз в час для проверки всех активных счетов в активных конкурсах
 * Проверяет только конкурсы с contest_status='active' в метаданных
 * 
 * @package ITX_Contest_Plugin
 * @author IntellaraX
 * @version 1.1
 */

// Загружаем WordPress
require_once dirname(dirname(dirname(dirname(__FILE__)))) . "/wp-load.php";

// Подключаем класс дисквалификации
require_once dirname(__FILE__) . '/includes/class-disqualification-checker.php';

// Функция для вывода в консоль с временной меткой
function console_log($message, $type = 'INFO') {
    $timestamp = date('Y-m-d H:i:s');
    echo "[{$timestamp}] [{$type}] {$message}" . PHP_EOL;
    
    // Дублируем в error_log для отладки
    error_log("[DISQUALIFICATION-CRON] [{$type}] {$message}");
}

console_log("=== ЗАПУСК ПРОВЕРКИ ДИСКВАЛИФИКАЦИИ ===");

// Проверяем доступность класса проверки дисквалификации
$disqualification_checker_file = dirname(__FILE__) . '/includes/class-disqualification-checker.php';
if (!file_exists($disqualification_checker_file)) {
    console_log("ОШИБКА: Файл class-disqualification-checker.php не найден", 'ERROR');
    exit(1);
}

require_once $disqualification_checker_file;

// Инициализируем счетчики
$total_contests = 0;
$total_accounts = 0;
$disqualified_count = 0;
$checked_count = 0;
$errors_count = 0;

try {
    global $wpdb;
    
    // Получаем все активные конкурсы
    console_log("Получение списка активных конкурсов...");
    
    // Сначала получаем все опубликованные конкурсы
    $published_contests = $wpdb->get_results(
        "SELECT ID, post_title FROM $wpdb->posts 
         WHERE post_type = 'trader_contests' 
         AND post_status = 'publish'
         ORDER BY ID"
    );
    
    if (empty($published_contests)) {
        console_log("Опубликованные конкурсы не найдены", 'WARN');
        exit(0);
    }
    
    console_log("Найдено опубликованных конкурсов: " . count($published_contests));
    
    // Теперь фильтруем только активные (contest_status = 'active')
    $active_contests = [];
    foreach ($published_contests as $contest) {
        $contest_data = get_post_meta($contest->ID, '_fttradingapi_contest_data', true);
        
        // Проверяем статус конкурса в метаданных
        if (!empty($contest_data) && is_array($contest_data) && 
            isset($contest_data['contest_status']) && $contest_data['contest_status'] === 'active') {
            $active_contests[] = $contest;
        }
    }
    
    if (empty($active_contests)) {
        console_log("Активные конкурсы не найдены (все имеют статус draft или finished)", 'WARN');
        exit(0);
    }
    
    $total_contests = count($active_contests);
    console_log("Найдено активных конкурсов (contest_status='active'): {$total_contests}");
    
    // Инициализируем класс проверки дисквалификации
    $checker = new Contest_Disqualification_Checker();
    
    // Проходим по каждому конкурсу
    foreach ($active_contests as $contest) {
        console_log("Обработка конкурса ID: {$contest->ID} ({$contest->post_title})");
        
        // Получаем все активные счета этого конкурса
        $table_name = $wpdb->prefix . 'contest_members';
        $contest_accounts = $wpdb->get_results($wpdb->prepare(
            "SELECT id, account_number, connection_status, error_description 
             FROM $table_name 
             WHERE contest_id = %d 
             AND connection_status != 'disqualified' 
             ORDER BY id",
            $contest->ID
        ));
        
        if (empty($contest_accounts)) {
            console_log("  Активные счета не найдены");
            continue;
        }
        
        $contest_accounts_count = count($contest_accounts);
        console_log("  Найдено активных счетов: {$contest_accounts_count}");
        $total_accounts += $contest_accounts_count;
        
        // Проверяем каждый счет на дисквалификацию
        $contest_disqualified = 0;
        foreach ($contest_accounts as $account) {
            $checked_count++;
            
            try {
                // Выполняем проверку дисквалификации
                $result = $checker->check_account_disqualification($account->id);
                
                if ($result['is_disqualified']) {
                    // Дисквалифицируем счет
                    $success = $checker->disqualify_account($account->id, $result['reasons']);
                    
                    if ($success) {
                        $disqualified_count++;
                        $contest_disqualified++;
                        
                        // Формируем краткое описание причин для лога
                        $reasons_summary = is_array($result['reasons']) ? 
                            implode('; ', array_slice($result['reasons'], 0, 2)) : 
                            $result['reasons'];
                        
                        console_log("    ДИСКВАЛИФИЦИРОВАН: Счет #{$account->account_number} (ID: {$account->id}) - {$reasons_summary}", 'WARN');
                    } else {
                        console_log("    ОШИБКА: Не удалось дисквалифицировать счет #{$account->account_number} (ID: {$account->id})", 'ERROR');
                        $errors_count++;
                    }
                } else {
                    // Счет соответствует условиям - тихо пропускаем
                    if ($checked_count % 100 == 0) {
                        console_log("    Проверено счетов: {$checked_count}");
                    }
                }
                
            } catch (Exception $e) {
                console_log("    ИСКЛЮЧЕНИЕ при проверке счета #{$account->account_number} (ID: {$account->id}): " . $e->getMessage(), 'ERROR');
                $errors_count++;
            }
        }
        
        console_log("  Конкурс завершен. Дисквалифицировано: {$contest_disqualified} из {$contest_accounts_count}");
    }
    
} catch (Exception $e) {
    console_log("КРИТИЧЕСКАЯ ОШИБКА: " . $e->getMessage(), 'ERROR');
    exit(1);
}

// Выводим финальную статистику
console_log("=== ПРОВЕРКА ЗАВЕРШЕНА ===");
console_log("Конкурсов обработано: {$total_contests}");
console_log("Счетов проверено: {$checked_count} из {$total_accounts} активных");
console_log("Дисквалифицировано: {$disqualified_count}");
console_log("Ошибок: {$errors_count}");

// Записываем статистику в отдельный лог для мониторинга
$stats_log = "DISQUALIFICATION_CRON_STATS: contests={$total_contests}, checked={$checked_count}, disqualified={$disqualified_count}, errors={$errors_count}";
error_log($stats_log);

console_log("=== КОНЕЦ СКРИПТА ===");

// Код завершения: 0 - успех, 1 - критическая ошибка
exit($errors_count > 10 ? 1 : 0); 