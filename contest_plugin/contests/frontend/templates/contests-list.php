<div class="contests-list-wrapper">
    <div class="contests-header">
        <h1 class="contests-title">Конкурсы форекс трейдеров <span class="contests-count"><?php echo $contests_count; ?></span></h1>
        <?php if (current_user_can('manage_options')): ?>
            <a href="<?php echo admin_url('post-new.php?post_type=trader_contests'); ?>" class="button button-primary">Провести конкурс</a>
        <?php endif; ?>
    </div>

    <div class="contests-table-wrapper">
        <table class="contests-table">
            <thead>
                <tr>
                    <th class="column-name">Название</th>
                    <th class="column-date">Начало</th>
                    <th class="column-participants">Участники</th>
                    <th class="column-prize">Призовой фонд</th>
                    <th class="column-status">Статус</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($contests as $contest): 
                    $meta = get_post_meta($contest->ID, '_fttradingapi_contest_data', true);
                    $participants_count = $wpdb->get_var($wpdb->prepare(
                        "SELECT COUNT(*) FROM {$wpdb->prefix}contest_members WHERE contest_id = %d",
                        $contest->ID
                    ));
                    $status_class = $meta['contest_status'] === 'active' ? 'status-active' : 'status-finished';
                ?>
                <tr>
                    <td class="column-name">
                        <?php if (!empty($meta['image_url'])): ?>
                            <img src="<?php echo esc_url($meta['image_url']); ?>" alt="" class="contest-thumbnail">
                        <?php endif; ?>
                        <a href="<?php echo get_permalink($contest->ID); ?>"><?php echo esc_html($contest->post_title); ?></a>
                    </td>
                    <td class="column-date"><?php echo date_i18n('j F Y', strtotime($meta['date_start'])); ?></td>
                    <td class="column-participants"><?php echo $participants_count; ?></td>
                    <td class="column-prize"><?php echo esc_html($meta['prize_places']); ?></td>
                    <td class="column-status">
                        <span class="contest-status <?php echo $status_class; ?>">
                            <?php echo $meta['contest_status'] === 'active' ? 'Активен' : 'Завершен'; ?>
                        </span>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php echo paginate_links([
        'total' => $total_pages,
        'current' => $current_page,
        'prev_text' => '«',
        'next_text' => '»'
    ]); ?>
</div>
