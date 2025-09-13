# Инструкция по развертыванию SS.lv Monitor Bot

## Подготовка к запуску

### 1. Создание Telegram бота

1. Откройте [@BotFather](https://t.me/BotFather) в Telegram
2. Отправьте команду `/newbot`
3. Введите имя бота (например: "SS.lv Monitor")
4. Введите username бота (например: "ss_lv_monitor_bot")
5. Сохраните полученный токен

### 2. Получение Telegram ID

1. Откройте [@userinfobot](https://t.me/userinfobot) в Telegram
2. Отправьте любое сообщение
3. Сохраните ваш User ID

### 3. Настройка окружения

Создайте файл `.env`:

```bash
TELEGRAM_BOT_TOKEN=1234567890:ABCdefGHIjklMNOpqrsTUVwxyz
TELEGRAM_ADMIN_ID=123456789
```

## Локальный запуск

### 1. Установка зависимостей

```bash
pip install -r requirements.txt
```

### 2. Тестирование системы

```bash
python test_system.py
```

### 3. Запуск бота

```bash
python main.py
```

## Развертывание на сервере

### 1. Подготовка сервера

```bash
# Обновление системы
sudo apt update && sudo apt upgrade -y

# Установка Python и pip
sudo apt install python3 python3-pip python3-venv -y

# Создание пользователя для бота
sudo useradd -m -s /bin/bash ssmonitor
sudo su - ssmonitor
```

### 2. Клонирование и настройка

```bash
# Клонирование репозитория
git clone <repository-url>
cd SSLVSCANER

# Создание виртуального окружения
python3 -m venv venv
source venv/bin/activate

# Установка зависимостей
pip install -r requirements.txt

# Создание .env файла
cp env_example.txt .env
nano .env  # Отредактируйте файл
```

### 3. Создание systemd сервиса

Создайте файл `/etc/systemd/system/ss-monitor.service`:

```ini
[Unit]
Description=SS.lv Monitor Bot
After=network.target

[Service]
Type=simple
User=ssmonitor
WorkingDirectory=/home/ssmonitor/SSLVSCANER
Environment=PATH=/home/ssmonitor/SSLVSCANER/venv/bin
ExecStart=/home/ssmonitor/SSLVSCANER/venv/bin/python main.py
Restart=always
RestartSec=10

[Install]
WantedBy=multi-user.target
```

### 4. Запуск сервиса

```bash
# Перезагрузка systemd
sudo systemctl daemon-reload

# Включение автозапуска
sudo systemctl enable ss-monitor

# Запуск сервиса
sudo systemctl start ss-monitor

# Проверка статуса
sudo systemctl status ss-monitor

# Просмотр логов
sudo journalctl -u ss-monitor -f
```

## Мониторинг и обслуживание

### Просмотр логов

```bash
# Логи systemd
sudo journalctl -u ss-monitor -f

# Логи приложения
tail -f /home/ssmonitor/SSLVSCANER/ss_monitor.log
```

### Управление сервисом

```bash
# Остановка
sudo systemctl stop ss-monitor

# Перезапуск
sudo systemctl restart ss-monitor

# Статус
sudo systemctl status ss-monitor
```

### Обновление

```bash
# Переход в директорию
cd /home/ssmonitor/SSLVSCANER

# Остановка сервиса
sudo systemctl stop ss-monitor

# Обновление кода
git pull origin main

# Активация виртуального окружения
source venv/bin/activate

# Обновление зависимостей
pip install -r requirements.txt

# Запуск сервиса
sudo systemctl start ss-monitor
```

## Резервное копирование

### База данных

```bash
# Создание бэкапа
cp /home/ssmonitor/SSLVSCANER/ss_monitor.db /backup/ss_monitor_$(date +%Y%m%d_%H%M%S).db

# Автоматический бэкап (добавить в crontab)
0 2 * * * cp /home/ssmonitor/SSLVSCANER/ss_monitor.db /backup/ss_monitor_$(date +\%Y\%m\%d_\%H\%M\%S).db
```

### Конфигурация

```bash
# Бэкап конфигурации
cp /home/ssmonitor/SSLVSCANER/.env /backup/env_$(date +%Y%m%d_%H%M%S).env
```

## Устранение неполадок

### Проблемы с подключением

1. Проверьте интернет-соединение
2. Убедитесь в доступности SS.lv
3. Проверьте настройки брандмауэра

### Проблемы с ботом

1. Проверьте токен бота в `.env`
2. Убедитесь в правильности Telegram ID
3. Проверьте логи на ошибки

### Проблемы с базой данных

1. Проверьте права доступа к файлу базы данных
2. Убедитесь в наличии свободного места на диске
3. Проверьте целостность базы данных

### Проблемы с парсингом

1. Проверьте доступность SS.lv
2. Убедитесь в актуальности селекторов в парсере
3. Проверьте логи на ошибки парсинга

## Безопасность

### Рекомендации

1. Используйте отдельного пользователя для запуска бота
2. Ограничьте права доступа к файлам конфигурации
3. Регулярно обновляйте зависимости
4. Мониторьте логи на подозрительную активность

### Настройка брандмауэра

```bash
# Разрешить только исходящие соединения
sudo ufw default deny incoming
sudo ufw default allow outgoing
sudo ufw enable
```

## Масштабирование

### Для больших нагрузок

1. Используйте PostgreSQL вместо SQLite
2. Настройте Redis для кэширования
3. Добавьте балансировщик нагрузки
4. Используйте Docker для контейнеризации

### Мониторинг производительности

1. Настройте мониторинг ресурсов сервера
2. Добавьте метрики в приложение
3. Настройте алерты при превышении лимитов

