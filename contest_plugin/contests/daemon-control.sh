#!/bin/bash
# Скрипт управления демоном очередей конкурсов
# Usage: ./daemon-control.sh {start|stop|restart|status}

DAEMON_DIR="/var/www/vhosts/fortraders.org/httpdocs/wp-content/plugins/contests"
DAEMON_SCRIPT="$DAEMON_DIR/queue-daemon.php"
PID_FILE="/tmp/contest_queue_daemon.pid"
LOG_FILE="$DAEMON_DIR/includes/logs/queue_daemon.log"
PHP_BIN="/opt/plesk/php/8.1/bin/php"

# Цвета для вывода
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

function echo_color() {
    echo -e "${1}${2}${NC}"
}

function is_running() {
    if [ -f "$PID_FILE" ]; then
        local pid=$(cat "$PID_FILE")
        if ps -p "$pid" > /dev/null 2>&1; then
            return 0
        else
            # PID файл существует, но процесс не запущен - удаляем файл
            rm -f "$PID_FILE"
            return 1
        fi
    fi
    return 1
}

function start_daemon() {
    if is_running; then
        local pid=$(cat "$PID_FILE")
        echo_color $YELLOW "Демон уже запущен (PID: $pid)"
        return 1
    fi
    
    echo_color $GREEN "Запуск демона очередей..."
    
    # Создаем директорию для логов если не существует
    mkdir -p "$DAEMON_DIR/includes/logs"
    
    # Запускаем демон в фоне
    cd "$DAEMON_DIR"
    nohup "$PHP_BIN" "$DAEMON_SCRIPT" > /dev/null 2>&1 &
    
    # Ждем создания PID файла
    sleep 2
    
    if is_running; then
        local pid=$(cat "$PID_FILE")
        echo_color $GREEN "Демон запущен успешно (PID: $pid)"
        echo_color $GREEN "Лог: $LOG_FILE"
        return 0
    else
        echo_color $RED "Ошибка запуска демона"
        return 1
    fi
}

function stop_daemon() {
    if ! is_running; then
        echo_color $YELLOW "Демон не запущен"
        return 1
    fi
    
    local pid=$(cat "$PID_FILE")
    echo_color $YELLOW "Остановка демона (PID: $pid)..."
    
    # Отправляем сигнал завершения
    kill -TERM "$pid" 2>/dev/null
    
    # Ждем завершения
    local count=0
    while [ $count -lt 10 ] && is_running; do
        sleep 1
        count=$((count + 1))
    done
    
    if is_running; then
        # Принудительная остановка
        echo_color $YELLOW "Принудительная остановка..."
        kill -KILL "$pid" 2>/dev/null
        rm -f "$PID_FILE"
    fi
    
    if ! is_running; then
        echo_color $GREEN "Демон остановлен"
        return 0
    else
        echo_color $RED "Ошибка остановки демона"
        return 1
    fi
}

function status_daemon() {
    if is_running; then
        local pid=$(cat "$PID_FILE")
        echo_color $GREEN "Демон запущен (PID: $pid)"
        
        # Показываем информацию о процессе
        echo "Информация о процессе:"
        ps -p "$pid" -o pid,ppid,user,start,time,command
        
        # Показываем последние строки лога
        if [ -f "$LOG_FILE" ]; then
            echo ""
            echo "Последние записи в логе:"
            tail -5 "$LOG_FILE"
        fi
        
        return 0
    else
        echo_color $RED "Демон не запущен"
        return 1
    fi
}

function restart_daemon() {
    echo_color $YELLOW "Перезапуск демона..."
    stop_daemon
    sleep 2
    start_daemon
}

function show_usage() {
    echo "Использование: $0 {start|stop|restart|status|log}"
    echo ""
    echo "Команды:"
    echo "  start   - Запустить демон"
    echo "  stop    - Остановить демон"
    echo "  restart - Перезапустить демон"
    echo "  status  - Показать статус демона"
    echo "  log     - Показать лог демона"
    echo ""
}

function show_log() {
    if [ -f "$LOG_FILE" ]; then
        echo_color $GREEN "Содержимое лога ($LOG_FILE):"
        echo "----------------------------------------"
        tail -50 "$LOG_FILE"
    else
        echo_color $YELLOW "Лог файл не найден: $LOG_FILE"
    fi
}

case "$1" in
    start)
        start_daemon
        ;;
    stop)
        stop_daemon
        ;;
    restart)
        restart_daemon
        ;;
    status)
        status_daemon
        ;;
    log)
        show_log
        ;;
    *)
        show_usage
        exit 1
        ;;
esac

exit $? 