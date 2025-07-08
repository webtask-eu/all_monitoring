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
                        'i_ordtotal' => 'Количество открытых ордеров',
                        'h_count' => 'Количество записей в истории (Trade History Count)',
                        'connection_status' => 'Статус подключения',
                        'active_orders_volume' => 'Суммарный объем открытых сделок',
                        'leverage' => 'Кредитное плечо'
                    ];
                    echo isset($field_names[$change->field_name]) ? 
                          $field_names[$change->field_name] : 
                          $change->field_name;
                    ?>
                </td>
                <td>
                    <?php 
                    if ($change->field_name === 'leverage') {
                        echo '1:' . intval($change->old_value);
                    } elseif ($change->field_name === 'pass') {
                        echo str_repeat('*', min(strlen($change->old_value), 8));
                    } else {
                        echo esc_html($change->old_value);
                    }
                    ?>
                </td>
                <td>
                    <?php 
                    // Специальное форматирование для разных типов полей
                    if ($change->field_name === 'connection_status') {
                        if ($change->new_value === 'connected') {
                            echo '<span style="color: green;">Подключен</span>';
                        } else {
                            $error_info = isset($change->error_description) && !empty($change->error_description) 
                                ? ': ' . esc_html($change->error_description) 
                                : '';
                            echo '<span style="color: red;">Отключен' . $error_info . '</span>';
                        }
                    } elseif ($change->field_name === 'leverage') {
                        echo '1:' . intval($change->new_value);
                    } elseif ($change->field_name === 'pass') {
                        echo str_repeat('*', min(strlen($change->new_value), 8));
                    } else {
                        echo esc_html($change->new_value);
                    }
                    ?>
                </td>
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
// Добавляем пагинацию если есть данные о пагинации и более одной страницы
if (isset($pagination) && $pagination['total_pages'] > 1): 
?>
<div class="history-pagination" style="margin-top: 15px; text-align: center;">
    <div class="pagination-info" style="margin-bottom: 10px;">
        Показано <?php echo (($pagination['current_page'] - 1) * $pagination['per_page'] + 1); ?>-<?php echo min($pagination['current_page'] * $pagination['per_page'], $pagination['total_items']); ?> из <?php echo $pagination['total_items']; ?> записей
    </div>
    
    <div class="pagination-controls">
        <?php if ($pagination['current_page'] > 1): ?>
            <button type="button" class="button history-page-btn" data-page="1">« Первая</button>
            <button type="button" class="button history-page-btn" data-page="<?php echo $pagination['current_page'] - 1; ?>">‹ Назад</button>
        <?php endif; ?>
        
        <?php 
        // Показываем номера страниц (максимум 5)
        $start_page = max(1, $pagination['current_page'] - 2);
        $end_page = min($pagination['total_pages'], $pagination['current_page'] + 2);
        
        for ($i = $start_page; $i <= $end_page; $i++): 
        ?>
            <button type="button" class="button <?php echo $i == $pagination['current_page'] ? 'button-primary' : 'history-page-btn'; ?>" 
                    data-page="<?php echo $i; ?>" <?php echo $i == $pagination['current_page'] ? 'disabled' : ''; ?>>
                <?php echo $i; ?>
            </button>
        <?php endfor; ?>
        
        <?php if ($pagination['current_page'] < $pagination['total_pages']): ?>
            <button type="button" class="button history-page-btn" data-page="<?php echo $pagination['current_page'] + 1; ?>">Вперед ›</button>
            <button type="button" class="button history-page-btn" data-page="<?php echo $pagination['total_pages']; ?>">Последняя »</button>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>
