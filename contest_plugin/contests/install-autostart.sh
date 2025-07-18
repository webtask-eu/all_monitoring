#!/bin/bash

# Скрипт установки автозагрузки демона очередей ForTraders
# Запускать с правами root

set -e

# Цвета для вывода
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Функция для вывода цветного текста
log() {
    echo -e "${GREEN}[INFO]${NC} $1"
}

warn() {
    echo -e "${YELLOW}[WARN]${NC} $1"
}

error() {
    echo -e "${RED}[ERROR]${NC} $1"
}

# Проверяем, что скрипт запущен с правами root
if [[ $EUID -ne 0 ]]; then
    error "Этот скрипт должен быть запущен с правами root"
    exit 1
fi

# Путь к плагину
PLUGIN_DIR="/var/www/vhosts/fortraders.org/httpdocs/wp-content/plugins/contests"
SERVICE_NAME="fortraders-queue-daemon"
SERVICE_FILE="${PLUGIN_DIR}/queue-daemon.service"
SYSTEMD_DIR="/etc/systemd/system"

log "Установка автозагрузки демона очередей ForTraders..."

# Проверяем существование service файла
if [[ ! -f "$SERVICE_FILE" ]]; then
    error "Service файл не найден: $SERVICE_FILE"
    exit 1
fi

# Копируем service файл в systemd
log "Копируем service файл в systemd..."
cp "$SERVICE_FILE" "$SYSTEMD_DIR/${SERVICE_NAME}.service"

# Устанавливаем правильные права
chmod 644 "$SYSTEMD_DIR/${SERVICE_NAME}.service"

# Перезагружаем systemd
log "Перезагружаем systemd..."
systemctl daemon-reload

# Включаем автозагрузку
log "Включаем автозагрузку сервиса..."
systemctl enable "${SERVICE_NAME}.service"

# Проверяем текущий статус демона
if pgrep -f "queue-daemon.php" > /dev/null; then
    warn "Демон уже запущен. Останавливаем старый процесс..."
    cd "$PLUGIN_DIR"
    ./daemon-control.sh stop || true
    sleep 2
fi

# Запускаем сервис
log "Запускаем сервис..."
systemctl start "${SERVICE_NAME}.service"

# Проверяем статус
sleep 2
if systemctl is-active --quiet "${SERVICE_NAME}.service"; then
    log "✅ Сервис успешно запущен и добавлен в автозагрузку"
    log "Статус сервиса:"
    systemctl status "${SERVICE_NAME}.service" --no-pager -l
else
    error "❌ Ошибка запуска сервиса"
    log "Логи сервиса:"
    journalctl -u "${SERVICE_NAME}.service" --no-pager -l -n 20
    exit 1
fi

log ""
log "🎉 Автозагрузка демона успешно настроена!"
log "Управление сервисом:"
log "  - Статус:     systemctl status ${SERVICE_NAME}"
log "  - Запуск:     systemctl start ${SERVICE_NAME}"
log "  - Остановка:  systemctl stop ${SERVICE_NAME}"
log "  - Перезапуск: systemctl restart ${SERVICE_NAME}"
log "  - Логи:       journalctl -u ${SERVICE_NAME} -f"
log ""
log "Демон будет автоматически запускаться при перезагрузке сервера." 