# Структура БД для системы конкурсов

## Основные таблицы

### Конкурсы
- **wp_posts** - сами конкурсы (post_type = 'trader_contests')
- **wp_postmeta** - настройки конкурса в `_fttradingapi_contest_data` (сериализованный массив)

### Справочники
- **wp_brokers** - брокеры (id, name, slug, status)
- **wp_trading_platforms** - платформы (id, name, slug - metatrader4/5, ctrader) 
- **wp_broker_servers** - серверы (id, broker_id, platform_id, name, server_address)

### Участники
- **wp_contest_members** - участники конкурсов (contest_id, account_number, server, balance, equity, etc.)
- **wp_contest_members_order_history** - история ордеров участников
- **wp_contest_members_orders** - активные ордера

## Связи данных
- Конкурс → broker_id/platform_id → справочники
- Конкурс → servers (строка адресов через \n) → wp_broker_servers.server_address
- Участник → contest_id → wp_posts.ID
- Мониторинг → account_number + server → участник

## Хранение серверов в конкурсе
В `_fttradingapi_contest_data['servers']` - строка с адресами через `\n`
Пример: "Tickmill-Demo\nTickmill-DemoUK" 