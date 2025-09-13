# Руководство по кнопкам в Telegram боте

## Типы кнопок в Telegram

### 1. Inline Keyboard (Кнопки в сообщениях)
- **Описание**: Кнопки, которые появляются под конкретным сообщением
- **Использование**: Для выбора опций, подтверждения действий
- **Пример**: Кнопки "Удалить", "Назад", выбор периодичности

```python
from telegram import InlineKeyboardButton, InlineKeyboardMarkup

keyboard = [
    [InlineKeyboardButton("1 час", callback_data="set_freq_1h")],
    [InlineKeyboardButton("4 часа", callback_data="set_freq_4h")],
    [InlineKeyboardButton("◀️ Назад", callback_data="back_to_main")]
]
reply_markup = InlineKeyboardMarkup(keyboard)
```

### 2. Reply Keyboard (Постоянная клавиатура)
- **Описание**: Кнопки, которые всегда видны внизу чата
- **Использование**: Для основного меню, быстрого доступа к функциям
- **Пример**: Главное меню с кнопками "Добавить раздел", "Статус"

```python
from telegram import ReplyKeyboardMarkup

keyboard = [
    ["➕ Добавить раздел", "⏰ Периодичность"],
    ["📊 Статус", "⚙️ Управление разделами"]
]
reply_markup = ReplyKeyboardMarkup(
    keyboard, 
    resize_keyboard=True, 
    one_time_keyboard=False
)
```

## Обработка кнопок

### Inline кнопки (CallbackQueryHandler)
```python
# Регистрация обработчика
self.application.add_handler(
    CallbackQueryHandler(self._button_callback, pattern="^button_name$")
)

# Обработчик
async def _button_callback(self, update: Update, context: ContextTypes.DEFAULT_TYPE):
    query = update.callback_query
    await query.answer()  # Обязательно!
    # Логика обработки
```

### Reply кнопки (MessageHandler)
```python
# Регистрация обработчика
self.application.add_handler(
    MessageHandler(filters.TEXT & ~filters.COMMAND, self._handle_text_message)
)

# Обработчик
async def _handle_text_message(self, update: Update, context: ContextTypes.DEFAULT_TYPE):
    text = update.message.text
    if text == "➕ Добавить раздел":
        await self._add_section_handler(update)
    elif text == "📊 Статус":
        await self._status_handler(update)
```

## Настройки клавиатуры

### ReplyKeyboardMarkup параметры:
- `resize_keyboard=True` - автоматически подгоняет размер кнопок
- `one_time_keyboard=False` - клавиатура остается видимой
- `selective=True` - показывать только определенным пользователям

### InlineKeyboardMarkup параметры:
- `inline_keyboard` - массив массивов кнопок
- `callback_data` - данные, передаваемые при нажатии (до 64 байт)

## Рекомендации

1. **Используйте Reply Keyboard** для основного меню
2. **Используйте Inline Keyboard** для действий с конкретными элементами
3. **Всегда отвечайте на callback_query** с помощью `query.answer()`
4. **Ограничивайте длину callback_data** (максимум 64 байта)
5. **Используйте эмодзи** для лучшего UX
6. **Группируйте связанные кнопки** в одном ряду

## Примеры из проекта

### Главное меню (Reply Keyboard)
```python
def _get_main_keyboard(self) -> ReplyKeyboardMarkup:
    keyboard = [
        ["➕ Добавить раздел", "⏰ Периодичность"],
        ["📊 Статус", "⚙️ Управление разделами"]
    ]
    return ReplyKeyboardMarkup(keyboard, resize_keyboard=True, one_time_keyboard=False)
```

### Управление разделами (Inline Keyboard)
```python
keyboard = []
for sub in subscriptions:
    keyboard.append([InlineKeyboardButton(
        f"🗑️ {sub.url[:50]}{'...' if len(sub.url) > 50 else ''}",
        callback_data=f"delete_section_{sub.id}"
    )])
keyboard.append([InlineKeyboardButton("◀️ Назад в меню", callback_data="back_to_main")])
```


