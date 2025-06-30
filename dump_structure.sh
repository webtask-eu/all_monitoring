#!/bin/bash

MAX_LINES=1000
CURRENT_FILE_INDEX=1
CURRENT_LINE_COUNT=0
BASE_OUTPUT_FILE="project_structure_dump"
CURRENT_OUTPUT_FILE="${BASE_OUTPUT_FILE}_${CURRENT_FILE_INDEX}.txt"

# Инициализируем первый файл
echo "# Структура кода проекта (часть ${CURRENT_FILE_INDEX})" > "$CURRENT_OUTPUT_FILE"
CURRENT_LINE_COUNT=$((CURRENT_LINE_COUNT + 1))

# Функция для добавления строки в текущий файл
append_line() {
    local line="$1"
    # Проверяем, не превышен ли лимит строк
    if [ $CURRENT_LINE_COUNT -ge $MAX_LINES ]; then
        # Добавляем информацию о следующей части
        echo "" >> "$CURRENT_OUTPUT_FILE"
        echo "# Продолжение в файле ${BASE_OUTPUT_FILE}_$((CURRENT_FILE_INDEX + 1)).txt" >> "$CURRENT_OUTPUT_FILE"
        # Создаем новый файл
        CURRENT_FILE_INDEX=$((CURRENT_FILE_INDEX + 1))
        CURRENT_OUTPUT_FILE="${BASE_OUTPUT_FILE}_${CURRENT_FILE_INDEX}.txt"
        # Инициализируем новый файл
        echo "# Структура кода проекта (часть ${CURRENT_FILE_INDEX})" > "$CURRENT_OUTPUT_FILE"
        CURRENT_LINE_COUNT=1
    fi
    # Добавляем строку в текущий файл
    echo "$line" >> "$CURRENT_OUTPUT_FILE"
    CURRENT_LINE_COUNT=$((CURRENT_LINE_COUNT + 1))
}

# Функция для извлечения функций/методов из PHP файла
extract_php_functions() {
    local file="$1"
    local temp_file=$(mktemp)
    
    # Заголовок файла
    append_line "## Файл: $file"
    append_line ""
    
    # Извлечение DocBlock комментариев для файла
    grep -A 1 "\/\*\*" "$file" | grep -v "^\s*\*\/" | grep -v "^\s*\/\*\*" | grep -v -- "--" | sed 's/^\s*\* //' > "$temp_file"
    if [ -s "$temp_file" ]; then
        append_line "### Описание файла:"
        while read -r line; do
            [ -n "$line" ] && append_line "* $line"
        done < "$temp_file"
        append_line ""
    fi
    
    # Извлечение пространств имен
    grep -E "^namespace\s+[^;]+" "$file" | while read -r line; do
        append_line "**Пространство имён:** \`$line\`"
    done
    
    # Извлечение использованых классов (use statements)
    grep -E "^use\s+[^;]+" "$file" | while read -r line; do
        append_line "**Использует:** \`$line\`"
    done
    
    if grep -q "^use " "$file"; then
        append_line ""
    fi
    
    # Извлечение классов
    grep -E "^(abstract\s+|final\s+)?class\s+[a-zA-Z0-9_]+(\s+extends\s+[a-zA-Z0-9_\\\\]+)?(\s+implements\s+[a-zA-Z0-9_\\\\, ]+)?" "$file" | while read -r line; do
        class_name=$(echo "$line" | sed -E 's/^(abstract\s+|final\s+)?class\s+([a-zA-Z0-9_]+).*/\2/')
        append_line "### Класс: \`$class_name\`"
        append_line '```php'
        append_line "$line"
        append_line '```'
        append_line ""
        
        # Извлечение методов класса
        grep -E "^\s+(public|private|protected)(\s+static)?\s+function\s+[a-zA-Z0-9_]+\s*\(" "$file" | while read -r method; do
            method_name=$(echo "$method" | sed -E 's/^\s+(public|private|protected)(\s+static)?\s+function\s+([a-zA-Z0-9_]+).*/\3/')
            access=$(echo "$method" | sed -E 's/^\s+(public|private|protected).*/\1/')
            
            append_line "#### Метод: \`$method_name\` ($access)"
            # Извлечение PHPDoc для метода
            method_pattern=$(echo "$method" | sed 's/[\/&]/\\&/g')
            grep -B 5 -E "$method_pattern" "$file" | grep -E "^\s+\*" | sed 's/^\s*\* //' > "$temp_file"
            if [ -s "$temp_file" ]; then
                append_line "*Описание:*"
                while read -r doc_line; do
                    [ -n "$doc_line" ] && append_line "* $doc_line"
                done < "$temp_file"
            fi
            append_line ""
        done
    done
    
    # Извлечение глобальных функций
    grep -E "^function\s+[a-zA-Z0-9_]+\s*\(" "$file" | while read -r func; do
        func_name=$(echo "$func" | sed -E 's/^function\s+([a-zA-Z0-9_]+).*/\1/')
        append_line "### Функция: \`$func_name\`"
        # Извлечение PHPDoc для функции
        func_pattern=$(echo "$func" | sed 's/[\/&]/\\&/g')
        grep -B 5 -E "$func_pattern" "$file" | grep -E "^\s*\*" | sed 's/^\s*\* //' > "$temp_file"
        if [ -s "$temp_file" ]; then
            append_line "*Описание:*"
            while read -r doc_line; do
                [ -n "$doc_line" ] && append_line "* $doc_line"
            done < "$temp_file"
        fi
        append_line ""
    done
    
    # Извлечение хуков WordPress
    grep -E "add_(action|filter)" "$file" | while read -r hook; do
        hook_type=$(echo "$hook" | grep -oE "add_(action|filter)")
        hook_name=$(echo "$hook" | sed -E "s/.*add_(action|filter)\s*\(\s*['\"](.*)['\"].*/\2/")
        append_line "### ${hook_type}: \`$hook_name\`"
        append_line '```php'
        append_line "$hook"
        append_line '```'
        append_line ""
    done
    
    rm "$temp_file"
}

# Функция для извлечения функций из JS файла
extract_js_functions() {
    local file="$1"
    
    append_line "## Файл: $file"
    append_line ""
    
    # Извлечение объектов и классов
    grep -E "var\s+[a-zA-Z0-9_]+\s*=\s*\{" "$file" | while read -r obj; do
        obj_name=$(echo "$obj" | sed -E 's/var\s+([a-zA-Z0-9_]+).*/\1/')
        append_line "### Объект: \`$obj_name\`"
        append_line ""
    done
    
    # ES6 Классы
    grep -E "class\s+[a-zA-Z0-9_]+" "$file" | while read -r class; do
        class_name=$(echo "$class" | sed -E 's/class\s+([a-zA-Z0-9_]+).*/\1/')
        append_line "### Класс: \`$class_name\`"
        append_line ""
    done
    
    # Извлечение функций
    grep -E "(function\s+[a-zA-Z0-9_]+\s*\(|^\s+[a-zA-Z0-9_]+:\s*function\s*\()" "$file" | while read -r func; do
        if [[ $func =~ function[[:space:]]+([a-zA-Z0-9_]+) ]]; then
            func_name="${BASH_REMATCH[1]}"
            append_line "### Функция: \`$func_name\`"
        elif [[ $func =~ ([a-zA-Z0-9_]+)[[:space:]]*:[[:space:]]*function ]]; then
            func_name="${BASH_REMATCH[1]}"
            append_line "### Метод: \`$func_name\`"
        fi
        append_line ""
    done
    
    # Извлечение jQuery обработчиков
    grep -E "\$\(.*\)\.on\(" "$file" | while read -r handler; do
        event=$(echo "$handler" | sed -E "s/.*\.on\s*\(\s*['\"](.*)['\"].*/\1/")
        append_line "### jQuery обработчик события: \`$event\`"
        append_line '```javascript'
        append_line "$handler"
        append_line '```'
        append_line ""
    done
}

# Функция для извлечения правил из CSS файла
extract_css_rules() {
    local file="$1"
    
    append_line "## Файл: $file"
    append_line ""
    
    # Извлечение основных селекторов
    grep -E "^[a-zA-Z#\.\[\*].+\{" "$file" | sed 's/ *{.*//' | while read -r selector; do
        append_line "### Селектор: \`$selector\`"
        append_line ""
    done
    
    # Извлечение медиа-запросов
    grep -E "@media" "$file" | while read -r media; do
        append_line "### Медиа-запрос: \`$media\`"
        append_line ""
    done
}

# Временная переменная для подсчета файлов и функций
TOTAL_FILES=0
TOTAL_FUNCTIONS=0

# Обработка файлов
find PLUGIN/contests -type f -not -path "*/\.*" -not -name ".DS_Store" \
  -not -name "*.jpg" -not -name "*.jpeg" -not -name "*.png" -not -name "*.gif" \
| sort \
| while read -r file; do
    # Увеличиваем счетчик файлов
    TOTAL_FILES=$((TOTAL_FILES + 1))
    
    # Определяем расширение файла
    ext="${file##*.}"
    
    # Обрабатываем в зависимости от типа файла
    case "$ext" in
        php)
            # PHP файлы
            extract_php_functions "$file"
            # Подсчитываем количество функций/методов
            func_count=$(grep -E "(function\s+[a-zA-Z0-9_]+|class\s+[a-zA-Z0-9_]+)" "$file" | wc -l)
            TOTAL_FUNCTIONS=$((TOTAL_FUNCTIONS + func_count))
            ;;
        js)
            # JavaScript файлы
            extract_js_functions "$file"
            # Подсчитываем количество функций
            func_count=$(grep -E "(function\s+[a-zA-Z0-9_]+|\s+[a-zA-Z0-9_]+:\s*function)" "$file" | wc -l)
            TOTAL_FUNCTIONS=$((TOTAL_FUNCTIONS + func_count))
            ;;
        css)
            # CSS файлы
            extract_css_rules "$file"
            # Подсчитываем количество селекторов
            sel_count=$(grep -E "^[a-zA-Z#\.\[\*].+\{" "$file" | wc -l)
            TOTAL_FUNCTIONS=$((TOTAL_FUNCTIONS + sel_count))
            ;;
        *)
            # Все остальные файлы - просто название
            append_line "## Файл: $file"
            append_line "*Неподдерживаемый тип файла для анализа структуры*"
            append_line ""
            ;;
    esac
    
    append_line "---"
    append_line ""
done

# Сохраняем переменные в файл, чтобы использовать их после цикла
echo "TOTAL_FILES=$TOTAL_FILES" > /tmp/dump_stats.tmp
echo "TOTAL_FUNCTIONS=$TOTAL_FUNCTIONS" >> /tmp/dump_stats.tmp
echo "CURRENT_FILE_INDEX=$CURRENT_FILE_INDEX" >> /tmp/dump_stats.tmp

# Загружаем сохраненные значения
source /tmp/dump_stats.tmp

# Добавляем статистику в последний файл
append_line "# Статистика"
append_line "Всего файлов: $TOTAL_FILES"
append_line "Всего функций/методов/классов/селекторов: $TOTAL_FUNCTIONS"
append_line "Разбито на $CURRENT_FILE_INDEX частей"

echo "Обработка завершена. Создано $CURRENT_FILE_INDEX файлов."
echo "Всего обработано файлов: $TOTAL_FILES"
echo "Всего функций/методов/классов: $TOTAL_FUNCTIONS"

# Удаляем временный файл
rm -f /tmp/dump_stats.tmp
