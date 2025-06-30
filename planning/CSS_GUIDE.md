# Руководство по CSS (CSS_GUIDE.md)

Это руководство определяет стандарты и лучшие практики для написания CSS в проекте CONTEST2.

## Основные принципы

1.  **Методология**: Предпочтительно использовать BEM (Block, Element, Modifier) или SMACSS.
    *   Пример BEM: `.block__element--modifier`
2.  **Препроцессоры**: Если используется (например, SASS/SCSS), указать конфигурацию и структуру.
3.  **Читаемость**: Код должен быть хорошо отформатирован и прокомментирован (только неочевидные моменты).

## Структура файлов

-   `base/`: Базовые стили (reset, typography, utilities).
-   `components/`: Стили для отдельных компонентов UI.
-   `layouts/`: Стили для основных структур страниц (header, footer, sidebar).
-   `pages/`: Стили, специфичные для отдельных страниц.
-   `themes/`: Стили для различных тем (если применимо).
-   `main.css` (или `style.css`): Основной файл, импортирующий остальные.

## Наименование

-   Использовать строчные буквы и дефисы для имен классов (kebab-case), если не применяется BEM.
-   Избегать использования ID для стилизации.

## Вложенность

-   Минимизировать глубину вложенности селекторов (не более 3 уровней).

## Медиа-запросы

-   Группировать медиа-запросы в конце файла или компонента.
-   Использовать стандартизированные точки прерывания (breakpoints).

## Производительность

-   Избегать использования `!important`, кроме крайних случаев.
-   Не использовать избыточные селекторы.

## Комментарии

-   Использовать комментарии для разделения крупных секций CSS.
-   Комментировать неочевидные решения или хаки.

---

## Принципы организации CSS

### Методология BEM

В проекте используется методология BEM (Block, Element, Modifier) для именования классов CSS:

```css
/* Блок */
.contest-card { ... }

/* Элемент */
.contest-card__title { ... }
.contest-card__image { ... }

/* Модификатор */
.contest-card--active { ... }
.contest-card--past { ... }
```

### Структура селекторов

- Использовать классы вместо идентификаторов для стилизации
- Ограничить вложенность селекторов до 3-х уровней
- Группировать селекторы с одинаковыми стилями
- Стараться избегать использования `!important`

## Организация файлов CSS

### Структура директорий

```
PLUGIN/contests/
├── admin/
│   └── css/
│       ├── admin.css         # Основные стили админки
│       └── chart-styles.css  # Стили для графиков
├── public/
│   └── css/
│       ├── frontend.css      # Основные стили фронтенда
│       ├── tables.css        # Стили таблиц
│       └── forms.css         # Стили форм
└── frontend/
    └── css/
        └── contest-cards.css # Стили карточек конкурса
```

### Импорты

Для объединения файлов используем @import:

```css
/* В главном файле admin.css */
@import "chart-styles.css";
@import "datepicker.css";
```

## Переменные и темы

### Цветовая схема

```css
:root {
  /* Основные цвета */
  --color-primary: #3498db;
  --color-secondary: #2ecc71; 
  --color-tertiary: #f1c40f;
  
  /* Нейтральные цвета */
  --color-background: #ffffff;
  --color-background-alt: #f5f7fa;
  --color-text: #333333;
  --color-text-light: #777777;
  
  /* Цвета состояний */
  --color-success: #2ecc71;
  --color-warning: #f39c12;
  --color-danger: #e74c3c;
  --color-info: #3498db;
  
  /* Цвета для графиков */
  --color-chart-balance: #3498db;
  --color-chart-equity: #2ecc71;
  --color-chart-grid: #ecf0f1;
  --color-chart-profit: #27ae60;
  --color-chart-loss: #e74c3c;
}
```

### Типографика

```css
:root {
  /* Шрифты */
  --font-primary: "Roboto", -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
  --font-secondary: "Open Sans", sans-serif;
  --font-monospace: "Roboto Mono", monospace;
  
  /* Размеры шрифтов */
  --font-size-xs: 0.75rem;   /* 12px */
  --font-size-sm: 0.875rem;  /* 14px */
  --font-size-md: 1rem;      /* 16px */
  --font-size-lg: 1.125rem;  /* 18px */
  --font-size-xl: 1.25rem;   /* 20px */
  --font-size-xxl: 1.5rem;   /* 24px */
  
  /* Вес шрифта */
  --font-weight-light: 300;
  --font-weight-regular: 400;
  --font-weight-medium: 500;
  --font-weight-bold: 700;
  
  /* Высота строки */
  --line-height-tight: 1.25;
  --line-height-normal: 1.5;
  --line-height-loose: 1.75;
}
```

### Размеры и отступы

```css
:root {
  /* Отступы */
  --spacing-xs: 0.25rem;  /* 4px */
  --spacing-sm: 0.5rem;   /* 8px */
  --spacing-md: 1rem;     /* 16px */
  --spacing-lg: 1.5rem;   /* 24px */
  --spacing-xl: 2rem;     /* 32px */
  --spacing-xxl: 3rem;    /* 48px */
  
  /* Размеры компонентов */
  --border-radius-sm: 0.25rem;  /* 4px */
  --border-radius-md: 0.375rem; /* 6px */
  --border-radius-lg: 0.5rem;   /* 8px */
  --border-radius-pill: 9999px;
  
  /* Тени */
  --shadow-sm: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
  --shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
  --shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
}
```

## Общие компоненты

### Кнопки

```css
.ft-button {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  padding: var(--spacing-sm) var(--spacing-md);
  font-family: var(--font-primary);
  font-size: var(--font-size-md);
  font-weight: var(--font-weight-medium);
  line-height: var(--line-height-tight);
  text-align: center;
  text-decoration: none;
  border-radius: var(--border-radius-md);
  transition: all 0.2s ease;
  cursor: pointer;
}

.ft-button--primary {
  color: white;
  background-color: var(--color-primary);
  border: 1px solid var(--color-primary);
}

.ft-button--secondary {
  color: var(--color-primary);
  background-color: transparent;
  border: 1px solid var(--color-primary);
}

.ft-button--small {
  padding: var(--spacing-xs) var(--spacing-sm);
  font-size: var(--font-size-sm);
}

.ft-button--large {
  padding: var(--spacing-md) var(--spacing-lg);
  font-size: var(--font-size-lg);
}
```

### Таблицы

```css
.ft-table {
  width: 100%;
  border-collapse: collapse;
  font-family: var(--font-primary);
  border: 1px solid var(--color-background-alt);
}

.ft-table th {
  padding: var(--spacing-sm) var(--spacing-md);
  background-color: var(--color-background-alt);
  font-weight: var(--font-weight-medium);
  text-align: left;
  border-bottom: 2px solid var(--color-background);
}

.ft-table td {
  padding: var(--spacing-sm) var(--spacing-md);
  border-bottom: 1px solid var(--color-background-alt);
}

.ft-table--striped tr:nth-child(even) {
  background-color: var(--color-background-alt);
}

.ft-table--hoverable tr:hover {
  background-color: rgba(var(--color-primary-rgb), 0.05);
}
```

### Формы

```css
.ft-form-control {
  display: block;
  width: 100%;
  padding: var(--spacing-sm) var(--spacing-md);
  font-family: var(--font-primary);
  font-size: var(--font-size-md);
  line-height: var(--line-height-normal);
  color: var(--color-text);
  background-color: var(--color-background);
  border: 1px solid var(--color-text-light);
  border-radius: var(--border-radius-md);
  transition: border-color 0.2s ease;
}

.ft-form-control:focus {
  border-color: var(--color-primary);
  outline: 0;
  box-shadow: 0 0 0 3px rgba(var(--color-primary-rgb), 0.25);
}

.ft-form-label {
  display: block;
  margin-bottom: var(--spacing-xs);
  font-family: var(--font-primary);
  font-size: var(--font-size-sm);
  font-weight: var(--font-weight-medium);
  color: var(--color-text);
}

.ft-form-group {
  margin-bottom: var(--spacing-md);
}
```

## Утилиты

### Отступы и поля

```css
.ft-m-0 { margin: 0; }
.ft-m-xs { margin: var(--spacing-xs); }
.ft-m-sm { margin: var(--spacing-sm); }
.ft-m-md { margin: var(--spacing-md); }
.ft-m-lg { margin: var(--spacing-lg); }
.ft-m-xl { margin: var(--spacing-xl); }

.ft-mt-0 { margin-top: 0; }
.ft-mt-xs { margin-top: var(--spacing-xs); }
.ft-mt-sm { margin-top: var(--spacing-sm); }
.ft-mt-md { margin-top: var(--spacing-md); }
.ft-mt-lg { margin-top: var(--spacing-lg); }
.ft-mt-xl { margin-top: var(--spacing-xl); }

/* Аналогично для padding */
.ft-p-0 { padding: 0; }
.ft-p-xs { padding: var(--spacing-xs); }
/* и т.д. */
```

### Текст и типографика

```css
.ft-text-xs { font-size: var(--font-size-xs); }
.ft-text-sm { font-size: var(--font-size-sm); }
.ft-text-md { font-size: var(--font-size-md); }
.ft-text-lg { font-size: var(--font-size-lg); }
.ft-text-xl { font-size: var(--font-size-xl); }

.ft-text-light { font-weight: var(--font-weight-light); }
.ft-text-regular { font-weight: var(--font-weight-regular); }
.ft-text-medium { font-weight: var(--font-weight-medium); }
.ft-text-bold { font-weight: var(--font-weight-bold); }

.ft-text-left { text-align: left; }
.ft-text-center { text-align: center; }
.ft-text-right { text-align: right; }

.ft-text-primary { color: var(--color-primary); }
.ft-text-secondary { color: var(--color-secondary); }
.ft-text-success { color: var(--color-success); }
.ft-text-warning { color: var(--color-warning); }
.ft-text-danger { color: var(--color-danger); }
.ft-text-info { color: var(--color-info); }
```

## Адаптивный дизайн

### Медиа-запросы

```css
/* Переменные для медиа-запросов */
:root {
  --breakpoint-sm: 576px;   /* Смартфоны */
  --breakpoint-md: 768px;   /* Планшеты */
  --breakpoint-lg: 992px;   /* Небольшие десктопы */
  --breakpoint-xl: 1200px;  /* Большие десктопы */
}

/* Примеры медиа-запросов */
@media (min-width: 576px) {
  /* Стили для экранов шириной ≥ 576px */
}

@media (min-width: 768px) {
  /* Стили для экранов шириной ≥ 768px */
}

@media (min-width: 992px) {
  /* Стили для экранов шириной ≥ 992px */
}

@media (min-width: 1200px) {
  /* Стили для экранов шириной ≥ 1200px */
}
```

### Адаптивная сетка

```css
.ft-container {
  width: 100%;
  padding-right: var(--spacing-md);
  padding-left: var(--spacing-md);
  margin-right: auto;
  margin-left: auto;
}

@media (min-width: 576px) {
  .ft-container {
    max-width: 540px;
  }
}

@media (min-width: 768px) {
  .ft-container {
    max-width: 720px;
  }
}

@media (min-width: 992px) {
  .ft-container {
    max-width: 960px;
  }
}

@media (min-width: 1200px) {
  .ft-container {
    max-width: 1140px;
  }
}

.ft-row {
  display: flex;
  flex-wrap: wrap;
  margin-right: -15px;
  margin-left: -15px;
}

.ft-col {
  position: relative;
  width: 100%;
  padding-right: 15px;
  padding-left: 15px;
}

/* Колонки для разных размеров экрана */
@media (min-width: 576px) {
  .ft-col-sm-6 {
    flex: 0 0 50%;
    max-width: 50%;
  }
  
  .ft-col-sm-4 {
    flex: 0 0 33.333333%;
    max-width: 33.333333%;
  }
  
  /* и т.д. */
}
```

## Принципы работы с CSS

1. **Избегайте дублирования** — используйте переменные CSS и общие утилиты
2. **Следуйте методологии BEM** для имен классов
3. **Оптимизируйте селекторы** — избегайте излишней вложенности
4. **Используйте утилитарные классы** для мелких стилей
5. **Следите за производительностью** — избегайте сложных анимаций и эффектов
6. **Следуйте адаптивному дизайну** — начинайте с мобильной версии
7. **Комментируйте сложные части** стилей 