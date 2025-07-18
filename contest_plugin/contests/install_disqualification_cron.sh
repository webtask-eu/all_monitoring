#!/bin/bash
#
# Скрипт установки крон-задачи для проверки дисквалификации счетов
# Запускает проверку каждый час
#
# @author IntellaraX
# @version 1.0

SCRIPT_DIR="/var/www/vhosts/fortraders.org/httpdocs/wp-content/plugins/contests"
SCRIPT_PATH="$SCRIPT_DIR/cron_disqualification_check.php"
PHP_PATH="/opt/plesk/php/8.1/bin/php"
LOG_PATH="/var/www/vhosts/fortraders.org/logs/disqualification_cron.log"

echo "=== УСТАНОВКА КРОН-ЗАДАЧИ ПРОВЕРКИ ДИСКВАЛИФИКАЦИИ ==="

# Проверяем существование скрипта
if [ ! -f "$SCRIPT_PATH" ]; then
    echo "ОШИБКА: Файл $SCRIPT_PATH не найден"
    exit 1
fi

# Делаем скрипт исполняемым
chmod +x "$SCRIPT_PATH"
echo "Права доступа к скрипту установлены"

# Создаем новую крон-задачу (каждый час в 15 минут)
CRON_COMMAND="15 * * * * cd $SCRIPT_DIR && $PHP_PATH $SCRIPT_PATH >> $LOG_PATH 2>&1"

# Получаем текущий crontab
crontab -l > /tmp/current_cron 2>/dev/null

# Проверяем, нет ли уже такой задачи
if grep -q "cron_disqualification_check.php" /tmp/current_cron 2>/dev/null; then
    echo "ПРЕДУПРЕЖДЕНИЕ: Крон-задача для проверки дисквалификации уже существует"
    echo "Существующие задачи:"
    grep "cron_disqualification_check.php" /tmp/current_cron
    
    read -p "Заменить существующую задачу? (y/N): " -n 1 -r
    echo
    if [[ ! $REPLY =~ ^[Yy]$ ]]; then
        echo "Установка отменена"
        rm -f /tmp/current_cron
        exit 0
    fi
    
    # Удаляем старые задачи
    grep -v "cron_disqualification_check.php" /tmp/current_cron > /tmp/new_cron
    mv /tmp/new_cron /tmp/current_cron
fi

# Добавляем новую задачу
echo "$CRON_COMMAND" >> /tmp/current_cron

# Устанавливаем новый crontab
crontab /tmp/current_cron

# Очищаем временный файл
rm -f /tmp/current_cron

echo "✅ Крон-задача успешно установлена!"
echo "Расписание: каждый час в 15 минут"
echo "Команда: $CRON_COMMAND"
echo "Логи: $LOG_PATH"
echo ""
echo "Текущие крон-задачи:"
crontab -l | grep -E "(wp-cron|disqualification)"

echo ""
echo "Для тестирования запустите:"
echo "cd $SCRIPT_DIR && $PHP_PATH $SCRIPT_PATH" 