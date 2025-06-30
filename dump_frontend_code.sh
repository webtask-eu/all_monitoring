#!/bin/bash
OUTPUT_FILE="frontend_code_dump_CONTEST2.txt"

echo "# Код фронтенд-части плагина конкурсов трейдеров (без комментариев)" > "$OUTPUT_FILE"
echo "Дата создания: $(date)" >> "$OUTPUT_FILE"
echo "" >> "$OUTPUT_FILE"

# Массив с файлами для обработки
FILES=(
  "PLUGIN/wp-content/plugins/contests/includes/front-templates.php"
  #"PLUGIN/wp-content/plugins/contests/templates/archive-contests.php"
  #"PLUGIN/wp-content/plugins/contests/templates/single-contest.php"
  "PLUGIN/wp-content/plugins/contests/templates/single-account.php"
  #"PLUGIN/wp-content/plugins/contests/templates/parts/registration-form.php"
  "PLUGIN/wp-content/plugins/contests/frontend/css/frontend.css"
  "PLUGIN/wp-content/plugins/contests/public/js/contest-scripts.js"
  "PLUGIN/wp-content/plugins/contests/admin/js/account-chart.js"
  "PLUGIN/wp-content/plugins/contests/public/class-contest-ajax.php"
  "PLUGIN/wp-content/plugins/contests/public/class-contest-public.php"
  "PLUGIN/wp-content/plugins/contests/public/class-contest-ajax.php" 
  "PLUGIN/wp-content/plugins/contests/ft-trader-contest.php"
)

# Проходим по каждому файлу
for file in "${FILES[@]}"; do
  # Проверяем существование файла
  if [ -f "$file" ]; then
    # Определяем расширение файла для подсветки синтаксиса
    ext="${file##*.}"
    
    echo "" >> "$OUTPUT_FILE"
    echo "## FILE: $file" >> "$OUTPUT_FILE"
    echo '```'$ext >> "$OUTPUT_FILE"
    
    # Добавляем содержимое файла без комментариев в зависимости от расширения
    case "$ext" in
      "php"|"js")
        # Удаляем однострочные комментарии (//) и многострочные комментарии (/* */)
        sed -e 's|//.*$||g' -e 's|/\*.*\*/||g' -e '/\/\*/,/\*\//d' "$file" | grep -v '^[[:space:]]*$' >> "$OUTPUT_FILE"
        ;;
      "css")
        # Удаляем CSS комментарии (/* */)
        sed -e 's|/\*.*\*/||g' -e '/\/\*/,/\*\//d' "$file" | grep -v '^[[:space:]]*$' >> "$OUTPUT_FILE"
        ;;
      *)
        # Для других типов файлов просто копируем содержимое
        cat "$file" >> "$OUTPUT_FILE"
        ;;
    esac
    
    echo "" >> "$OUTPUT_FILE"
    echo '```' >> "$OUTPUT_FILE"
    
    echo "Обработан файл: $file (комментарии удалены)"
  else
    echo "ВНИМАНИЕ: Файл не найден: $file" >&2
  fi
done
