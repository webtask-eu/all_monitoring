#!/bin/bash

MAX_LINES=1000
CURRENT_FILE_INDEX=1
CURRENT_LINE_COUNT=0
BASE_OUTPUT_FILE="project_code_dump"
CURRENT_OUTPUT_FILE="${BASE_OUTPUT_FILE}_${CURRENT_FILE_INDEX}.txt"

# Инициализируем первый файл
echo "# Минимизированный код проекта (часть ${CURRENT_FILE_INDEX})" > "$CURRENT_OUTPUT_FILE"
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
        echo "# Минимизированный код проекта (часть ${CURRENT_FILE_INDEX})" > "$CURRENT_OUTPUT_FILE"
        CURRENT_LINE_COUNT=1
    fi
    
    # Добавляем строку в текущий файл
    echo "$line" >> "$CURRENT_OUTPUT_FILE"
    CURRENT_LINE_COUNT=$((CURRENT_LINE_COUNT + 1))
}

# Количество обработанных файлов
TOTAL_FILES=0
# Общее количество строк кода (без комментариев)
TOTAL_CODE_LINES=0

find PLUGIN/wp-content/plugins/contests -type f -not -path "*/\.*" -not -name ".DS_Store" \
  -not -name "*.jpg" -not -name "*.jpeg" -not -name "*.png" -not -name "*.gif" \
| sort \
| while read file; do
    
    # Увеличиваем счетчик файлов
    TOTAL_FILES=$((TOTAL_FILES + 1))
    
    # Добавляем заголовок файла
    append_line ""
    append_line "## FILE: $file"
    append_line '```'
    
    # Определяем расширение файла
    ext="${file##*.}"
    
    # Временный файл для отфильтрованного кода
    TEMP_FILE=$(mktemp)
    
    # Фильтруем в зависимости от типа файла
    case "$ext" in
        php)
            # PHP файлы
            grep -v "^\s*\/\/" "$file" | grep -v "^\s*\*" | grep -v "^\s*\/\*" | grep -v "^\s*$" > "$TEMP_FILE"
            ;;
        js)
            # JavaScript файлы
            grep -v "^\s*\/\/" "$file" | grep -v "^\s*\*" | grep -v "^\s*\/\*" | grep -v "^\s*$" > "$TEMP_FILE"
            ;;
        css)
            # CSS файлы
            grep -v "^\s*\/\*" "$file" | grep -v "^\s*\*" | grep -v "^\s*$" > "$TEMP_FILE"
            ;;
        *)
            # Все остальные файлы - просто удаляем пустые строки
            grep -v "^\s*$" "$file" > "$TEMP_FILE"
            ;;
    esac
    
    # Количество строк в отфильтрованном файле
    FILE_LINES=$(wc -l < "$TEMP_FILE")
    TOTAL_CODE_LINES=$((TOTAL_CODE_LINES + FILE_LINES))
    
    # Добавляем содержимое файла построчно
    while read -r line; do
        append_line "$line"
    done < "$TEMP_FILE"
    
    # Удаляем временный файл
    rm "$TEMP_FILE"
    
    # Закрываем блок кода
    append_line ""
    append_line '```'
done

# Добавляем статистику в последний файл
append_line ""
append_line "# Статистика"
append_line "Всего файлов: $TOTAL_FILES"
append_line "Всего строк кода (без комментариев и пустых строк): $TOTAL_CODE_LINES"
append_line "Разбито на $CURRENT_FILE_INDEX частей"

echo "Обработка завершена. Создано $CURRENT_FILE_INDEX файлов."
echo "Всего обработано файлов: $TOTAL_FILES"
echo "Всего строк кода (без комментариев): $TOTAL_CODE_LINES"
