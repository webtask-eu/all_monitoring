<?php
define('WP_USE_THEMES', false);
require_once('wp-config.php');
require_once('wp-content/plugins/contest_plugin/contests/includes/class-account-updater.php');

echo "=== Debug queue creation ===\n";

// Проверяем настройки
$settings = get_option('fttrader_auto_update_settings', []);
echo "Auto update enabled: " . ($settings['enabled'] ? 'Yes' : 'No') . "\n";

// Проверяем конкретно конкурс 468990
$contest_id = 468990;
$contest_data = get_post_meta($contest_id, '_fttradingapi_contest_data', true);

if (!empty($contest_data) && is_array($contest_data)) {
    echo "Contest 468990 status: " . $contest_data['contest_status'] . "\n";
    
    if ($contest_data['contest_status'] === 'active') {
        echo "Contest is active\n";
        
        // Проверяем счета
        global $wpdb;
        $table_name = $wpdb->prefix . 'contest_members';
        
        $contest_accounts = $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM $table_name WHERE contest_id = %d AND connection_status != 'disqualified'",
            $contest_id
        ));
        
        echo "Active accounts: " . count($contest_accounts) . "\n";
        
        if (count($contest_accounts) > 0) {
            echo "First 5 account IDs: " . implode(', ', array_slice($contest_accounts, 0, 5)) . "\n";
            
            echo "Creating queue...\n";
            $result = Account_Updater::init_queue($contest_accounts, true, $contest_id);
            echo "Queue creation result:\n";
            echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
        } else {
            echo "No active accounts found\n";
        }
    } else {
        echo "Contest is not active\n";
    }
} else {
    echo "Contest data not found or invalid\n";
}

echo "Done\n";
?> 