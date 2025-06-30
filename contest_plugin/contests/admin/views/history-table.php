<table class="widefat">
    <thead>
        <tr>
            <th>Дата</th>
            <th>Параметр</th>
            <th>Старое значение</th>
            <th>Новое значение</th>
            <th>Изменение</th>
        </tr>
    </thead>
    <tbody>
    <?php if (!empty($changes)): ?>
        <?php foreach ($changes as $change): ?>
            <tr>
                <td><?php echo date('Y.m.d H:i:s', strtotime($change->change_date)); ?></td>
                <td>
                    <?php 
                    $field_names = [
                        'i_bal' => 'Баланс',
                        'i_equi' => 'Средства',
                        'i_marg' => 'Уровень маржи',
                        'i_prof' => 'Плавающая прибыль/убыток',
                        'pass' => 'Пароль',
                        'srvMt4' => 'Сервер MT4',
                        'i_firma' => 'Брокер',
                        'i_fio' => 'Имя',
                        'i_ordtotal' => 'Общее количество открытых ордеров',
                        'h_count' => 'Количество записей в истории (Trade History Count)',
                        'connection_status' => 'Статус подключения' // Добавляем новое поле
                    ];
                    echo isset($field_names[$change->field_name]) ? 
                          $field_names[$change->field_name] : 
                          $change->field_name;
                    ?>
                </td>
                <td><?php echo esc_html($change->old_value); ?></td>
                <td>
                    <?php 
                    // Специальное форматирование для статуса подключения
                    if ($change->field_name === 'connection_status') {
                        if ($change->new_value === 'connected') {
                            echo '<span style="color: green;">Подключен</span>';
                        } else {
                            $error_info = isset($change->error_description) && !empty($change->error_description) 
                                ? ': ' . esc_html($change->error_description) 
                                : '';
                            echo '<span style="color: red;">Отключен' . $error_info . '</span>';
                        }
                    } else {
                        echo esc_html($change->new_value);
                    }
                    ?>
                </td>
                <td><?php echo esc_html($change->new_value); ?></td>
                <td>
                    <?php if ($change->change_percent !== null): ?>
                        <span class="change-percent <?php echo $change->change_percent > 0 ? 'positive' : 'negative'; ?>">
                            <?php echo number_format($change->change_percent, 2); ?>%
                        </span>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
    <?php else: ?>
        <tr>
            <td colspan="5">Нет данных для отображения</td>
        </tr>
    <?php endif; ?>
    </tbody>
</table>

<?php 
// Добавляем выпадающий список с номерами страниц если есть данные и более одной страницы
if (!empty($history_data['results']) && $history_data['total_pages'] > 1): 
?>
<div class="page-navigation">
    <form method="get" action="">
        <?php 
        // Сохраняем все существующие GET параметры
        foreach ($_GET as $key => $value) {
            if ($key !== 'page_num') {
                echo '<input type="hidden" name="' . esc_attr($key) . '" value="' . esc_attr($value) . '">';
            }
        }
        ?>
        <label for="page-select">Страница:</label>
        <select name="page_num" id="page-select" onchange="this.form.submit()">
            <?php for ($i = 1; $i <= $history_data['total_pages']; $i++): ?>
                <option value="<?php echo $i; ?>" <?php selected($history_data['current_page'], $i); ?>>
                    <?php echo $i; ?> из <?php echo $history_data['total_pages']; ?>
                </option>
            <?php endfor; ?>
        </select>
    </form>
</div>
<?php endif; ?>
