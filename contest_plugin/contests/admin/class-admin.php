/**
 * Регистрация скриптов и стилей для админки
 */
public function enqueue_admin_scripts() {
    $screen = get_current_screen();
    
    // Проверяем, находимся ли мы на странице нашего плагина
    if (strpos($screen->id, 'trader_contests') !== false) {
        wp_enqueue_style('contest-admin-style', plugin_dir_url(__FILE__) . 'css/admin.css', array(), $this->version);
        wp_enqueue_script('contest-admin-script', plugin_dir_url(__FILE__) . 'js/admin.js', array('jquery'), $this->version, true);
        
        // Добавляем локализацию для скриптов
        wp_localize_script('contest-admin-script', 'contest_admin_vars', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('contest-admin-nonce')
        ));
        
        // На странице списка счетов добавляем основные админские скрипты WordPress для работы с чекбоксами
        if ($screen->id === 'trader-contests_page_trader_contests_accounts') {
            wp_enqueue_script('common');
            wp_enqueue_script('wp-lists');
            wp_enqueue_script('postbox');
        }
        
        // Подключаем скрипты и стили только для страницы создания/редактирования конкурса
        if (($screen->id === 'trader_contests' && $screen->base === 'post') || 
            ($screen->id === 'trader-contests_page_trader_contests_accounts_edit')) {
            
            // Подключаем датапикер jQuery UI
            wp_enqueue_script('jquery-ui-datepicker');
            wp_enqueue_style('jquery-ui-style', 'https://code.jquery.com/ui/1.12.1/themes/base/jquery-ui.css');
            
            // Подключаем медиа-загрузчик WordPress
            wp_enqueue_media();
            
            // Дополнительная локализация для jQuery датапикера
            wp_localize_script('contest-admin-script', 'datepicker_vars', array(
                'dateFormat' => 'dd.mm.yy',
                'dayNames' => array(
                    'Воскресенье', 'Понедельник', 'Вторник', 'Среда',
                    'Четверг', 'Пятница', 'Суббота'
                ),
                'dayNamesMin' => array('Вс', 'Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб'),
                'monthNames' => array(
                    'Январь', 'Февраль', 'Март', 'Апрель', 'Май', 'Июнь',
                    'Июль', 'Август', 'Сентябрь', 'Октябрь', 'Ноябрь', 'Декабрь'
                ),
                'firstDay' => 1
            ));
        }
    }
} 