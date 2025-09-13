# Руководство по использованию SS.lv Monitor Bot

## Быстрый старт

### 1. Установка зависимостей
```bash
make install
```

### 2. Настройка конфигурации
Создайте файл `.env`:
```bash
cp env_example.txt .env
# Отредактируйте .env файл с вашими настройками
```

### 3. Запуск бота
```bash
make run
```

## Команды бота

### Основные команды
- `/start` - Начать работу с ботом
- `/help` - Показать справку
- `/status` - Статус системы
- `/scan` - Запустить сканирование вручную
- `/test` - Тест парсера

### Управление подписками
- `/subscriptions` - Показать мои подписки
- `/subscribe <категория> <город> [фильтры]` - Подписаться на уведомления
- `/unsubscribe <ID>` - Отписаться от уведомлений

### Примеры подписок
```bash
# Квартиры в Риге
/subscribe apartment riga

# Дома в Риге с фильтрами
/subscribe house riga min_price:100000 max_price:500000 min_area:100

# Квартиры в Юрмале
/subscribe apartment jurmala min_rooms:2 max_rooms:4
```

## Конфигурация

### Переменные окружения
```bash
# Telegram Bot
TELEGRAM_BOT_TOKEN=your_bot_token
TELEGRAM_ADMIN_ID=your_admin_id

# База данных
DATABASE_PATH=ss_monitor.db

# Парсинг
MAX_PAGES_PER_CATEGORY=3
REQUEST_TIMEOUT=30
REQUEST_DELAY=1.0

# Уведомления
ENABLE_NOTIFICATIONS=true
NOTIFICATION_COOLDOWN=300

# Логирование
LOG_LEVEL=INFO
LOG_FILE=ss_monitor.log
```

### Поддерживаемые категории
- `apartment` - Квартиры
- `house` - Дома

### Поддерживаемые города
- `riga` - Рига
- `jurmala` - Юрмала
- `liepaja` - Лиепая
- `daugavpils` - Даугавпилс

## Тестирование

### Запуск всех тестов
```bash
make test
```

### Запуск unit тестов
```bash
make test-unit
```

### Запуск integration тестов
```bash
make test-integration
```

## Разработка

### Форматирование кода
```bash
make format
```

### Линтинг
```bash
make lint
```

### Очистка
```bash
make clean
```

## Структура проекта

```
src/ss_monitor/
├── bot/           # Telegram бот
├── parser/        # Парсер SS.lv
├── database/      # Работа с базой данных
├── notifications/ # Система уведомлений
└── config.py      # Конфигурация

tests/
├── unit/          # Unit тесты
└── integration/   # Integration тесты
```

## Мониторинг

### Логи
Логи сохраняются в файл `ss_monitor.log` и выводятся в консоль.

### База данных
SQLite база данных сохраняется в файл `ss_monitor.db`.

### Статистика
Используйте команду `/status` для просмотра статистики.

## Устранение неполадок

### Бот не запускается
1. Проверьте токен бота в `.env`
2. Проверьте права доступа к файлам
3. Посмотрите логи в `ss_monitor.log`

### Парсер не работает
1. Проверьте интернет-соединение
2. Проверьте настройки `REQUEST_TIMEOUT` и `REQUEST_DELAY`
3. Запустите тест парсера командой `/test`

### Уведомления не приходят
1. Проверьте настройку `ENABLE_NOTIFICATIONS`
2. Убедитесь, что у вас есть активные подписки
3. Проверьте команду `/subscriptions`

## Поддержка

При возникновении проблем:
1. Проверьте логи
2. Запустите тесты
3. Проверьте конфигурацию
4. Создайте issue в репозитории
