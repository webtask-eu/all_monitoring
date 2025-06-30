<?php
// Добавление метабокса "Добавление счета в конкурс"
function fttradingapi_add_account_metabox() {
    add_meta_box(
        'fttradingapi_contest_account',
        'Добавление счета в конкурс',
        'fttradingapi_contest_account_callback',
        'trader_contests',
        'normal',
        'default'
    );
}
add_action('add_meta_boxes', 'fttradingapi_add_account_metabox');

function fttradingapi_contest_account_callback($post) {
    ?>
    <table class="form-table">
        <tbody>
			<input type="hidden" id="contest_id" value="<?php echo esc_attr($post->ID); ?>">
            <tr>
                <th scope="row"><label for="account_number">Номер счета</label></th>
                <td><input type="text" id="account_number" class="regular-text"></td>
            </tr>
            <tr>
                <th scope="row"><label for="password">Пароль</label></th>
                <td><input type="text" id="password" class="regular-text"></td>
            </tr>
            <tr>
                <th scope="row"><label for="server">Сервер</label></th>
                <td><input type="text" id="server" value="MetaQuotes-Demo" class="regular-text"></td>
            </tr>
            <tr>
                <th scope="row"><label for="terminal">Терминал</label></th>
                <td><input type="text" id="terminal" value="metatrader4" class="regular-text"></td>
            </tr>
            <tr>
                <td colspan="2">
				<button type="button" id="register_account" class="button button-primary">
					Добавить счет
				</button>
				<span id="register_status" style="margin-left: 10px;"></span>

                </td>
            </tr>
        </tbody>
    </table>

    <script>
        jQuery(document).ready(function($) {
            $('#register_account').on('click', function() {
                var data = {
                    action: 'fttradingapi_register_account',
                    account_number: $('#account_number').val(),
                    password: $('#password').val(),
                    server: $('#server').val(),
                    terminal: $('#terminal').val(),
                    contest_id: <?php echo $post->ID; ?>
                };

                $.post(ajaxurl, data, function(response) {
                    if (response.success) {
                        $('#register_result').html('<p style="color: green;">' + response.data.message + '</p>');
                    } else {
                        $('#register_result').html('<p style="color: red;">' + response.data.message + '</p>');
                    }
                });
            });
        });
    </script>
    <?php
}
