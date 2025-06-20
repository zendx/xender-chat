<?php
/*
Plugin Name: Xender Chat
Description: Real-time live chat with AJAX, file uploads, auto-delete, and admin dashboard.
Version: 1.2
Author: Henry Shedrach
*/

if (!defined('ABSPATH')) exit;

define('XENDER_CHAT_DIR', plugin_dir_path(__FILE__));
define('XENDER_CHAT_URL', plugin_dir_url(__FILE__));
define('XENDER_CHAT_UPLOADS', XENDER_CHAT_DIR . 'uploads/');

// On activation
register_activation_hook(__FILE__, function () {
    if (!file_exists(XENDER_CHAT_UPLOADS)) {
        mkdir(XENDER_CHAT_UPLOADS, 0755, true);
    }
    add_option('xender_chat_autodelete_time', 300); // Default 5 min
    add_option('xender_chat_allowed_roles', ['administrator']);
});

// Admin menu
add_action('admin_menu', function () {
    add_menu_page('Xender Chat', 'Xender Chat', 'read', 'xender-chat', 'xender_chat_admin_page');
    add_submenu_page('xender-chat', 'Settings', 'Settings', 'manage_options', 'xender-chat-settings', 'xender_chat_settings_page');
});

// Settings page
function xender_chat_settings_page() {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && check_admin_referer('xender_settings')) {
        update_option('xender_chat_autodelete_time', intval($_POST['autodelete_time']));
        update_option('xender_chat_allowed_roles', array_map('sanitize_text_field', $_POST['allowed_roles'] ?? []));
        echo '<div class="updated"><p>Settings saved.</p></div>';
    }

    $roles = wp_roles()->roles;
    $allowed = get_option('xender_chat_allowed_roles', []);
    $time = get_option('xender_chat_autodelete_time', 300);

    echo '<div class="wrap"><h2>Xender Chat Settings</h2>
        <form method="POST">';
    wp_nonce_field('xender_settings');
    echo '<label>Auto-delete after (seconds):</label><br>
        <input type="number" name="autodelete_time" value="' . esc_attr($time) . '"><br><br>
        <label>Allowed Roles:</label><br>';
    foreach ($roles as $key => $role) {
        echo '<label><input type="checkbox" name="allowed_roles[]" value="' . $key . '" ' . (in_array($key, $allowed) ? 'checked' : '') . '> ' . $role['name'] . '</label><br>';
    }
    echo '<br><button class="button button-primary" type="submit">Save</button></form></div>';
}

// Admin inbox
function xender_chat_admin_page() {
    $current_user = wp_get_current_user();
    $allowed = get_option('xender_chat_allowed_roles', []);
    if (!array_intersect($current_user->roles, $allowed)) wp_die('Access denied');

    echo '<div class="wrap"><h2>Xender Chat Inbox</h2>';

    // Purge form
    echo '<form method="post" action="' . admin_url('admin-post.php') . '">';
    echo '<input type="hidden" name="action" value="xender_purge">';
    echo wp_nonce_field('xender_purge', '_wpnonce', true, false);
    echo '<button class="button">Purge All Chats</button>';
    echo '</form>';

    // List chat JSON files
    $files = glob(XENDER_CHAT_UPLOADS . '*.json');
   $active_chats = [];

foreach ($files as $file) {
    $chat_data = json_decode(file_get_contents($file), true);
    if (!$chat_data || empty($chat_data[0]['email'])) continue;

    $email = $chat_data[0]['email'];
    $last_msg = end($chat_data);
    $unread = ($last_msg['from'] === 'client'); // Last message from client = needs reply
    $active_chats[] = ['email' => $email, 'unread' => $unread];
}

// Chat dropdown
echo '<label for="xender-chat-select"><strong>Select Chat:</strong></label><br>';
echo '<select id="xender-chat-select" style="min-width: 200px;">';
foreach ($active_chats as $chat) {
    $label = esc_html($chat['email']);
    $bubble = $chat['unread'] ? ' 🔴' : '';
    echo '<option value="' . esc_attr($chat['email']) . '">' . $label . $bubble . '</option>';
}
echo '</select><br><br>';

// Hidden field and dynamic log/reply area
echo '<input type="hidden" id="xender-current-client" value="">';
echo '<div id="xender-admin-chat-log" style="max-height: 300px; overflow-y: auto; border:1px solid #ccc; padding:5px; margin-bottom:10px;"></div>';
echo '<div id="xender-reply-form-container"></div>';


    echo '</div>';
}


// Purge handler
add_action('admin_post_xender_purge', function () {
    check_admin_referer('xender_purge');
    foreach (glob(XENDER_CHAT_UPLOADS . '*.json') as $f) @unlink($f);
    wp_redirect(admin_url('admin.php?page=xender-chat'));
    exit;
});

// Reply handler
add_action('admin_post_xender_send_reply', function () {
    check_admin_referer('xender_reply');

    $email = sanitize_email($_POST['email']);
    $message = sanitize_textarea_field($_POST['reply_message']);
    $file_url = '';

    if (!empty($_FILES['attachment']['name'])) {
        $upload = wp_handle_upload($_FILES['attachment'], ['test_form' => false]);
        if (!isset($upload['error'])) $file_url = $upload['url'];
    }

    $entry = [
        'email' => $email,
        'name' => 'Admin',
        'message' => $message,
        'file' => $file_url,
        'time' => time(),
        'from' => 'admin'
    ];

    $file_path = XENDER_CHAT_UPLOADS . 'chat_' . md5($email) . '.json';
    $chat = file_exists($file_path) ? json_decode(file_get_contents($file_path), true) : [];
    $chat[] = $entry;
    file_put_contents($file_path, json_encode($chat));

    wp_redirect(admin_url('admin.php?page=xender-chat'));
    exit;
});

// Enqueue scripts
add_action('wp_enqueue_scripts', function () {
    wp_enqueue_style('xender-chat-css', XENDER_CHAT_URL . 'assets/css/chat.css');
    wp_enqueue_script('xender-chat-js', XENDER_CHAT_URL . 'assets/js/chat.js', ['jquery'], null, true);
    wp_localize_script('xender-chat-js', 'xenderChat', [
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('xender_chat_nonce')
    ]);
});

add_action('admin_enqueue_scripts', function ($hook) {
    if ($hook !== 'toplevel_page_xender-chat') return;

    wp_enqueue_script('xender-admin-chat', XENDER_CHAT_URL . 'assets/js/admin-chat.js', ['jquery'], null, true);
    wp_localize_script('xender-admin-chat', 'xenderChat', [
        'ajax_url'        => admin_url('admin-ajax.php'),
        'admin_post_url'  => admin_url('admin-post.php'),
        'nonce'           => wp_create_nonce('xender_chat_nonce'),
        'reply_nonce'     => wp_create_nonce('xender_reply'), // this is critical
    ]);
});



// Frontend shortcode (or set to auto-inject if desired)
add_shortcode('xender_chat', function () {
    ob_start(); ?>
    <div id="xender-chat-icon">💬</div>
    <div id="xender-chat-box" style="display:none; position: fixed; bottom: 60px; right: 20px; width: 300px; background: #fff; border: 1px solid #ccc; box-shadow: 0 0 10px rgba(0,0,0,0.2); z-index: 9999;">
    <div id="xender-chat-header" style="background: #0073aa; color: #fff; padding: 10px; display: flex; justify-content: space-between; align-items: center;">
    <span>Live Chat</span>
    <span>
        <button id="xender-close-btn" style="background: none; color: #fff; border: none; font-size: 16px; cursor: pointer;">Close Chat</button>
    </span>
</div>

        <div id="xender-chat-start">
            <input type="email" id="xender-email" placeholder="Your Email" required><br>
            <input type="text" id="xender-name" placeholder="Nickname (optional)"><br>
            <button id="xender-start-btn">Start Chat</button>
        </div>
        <div id="xender-chat-session" style="display:none;">
            <div id="xender-chat-log" style="max-height: 200px; overflow-y: auto; border:1px solid #ccc; padding:5px;"></div>
            <form id="xender-chat-form" enctype="multipart/form-data">
                <textarea name="message" placeholder="Type your message..." required></textarea><br>
                <input type="file" name="attachment" accept="image/*,.pdf"><br>
                <input type="hidden" name="email">
                <input type="hidden" name="name">
                <input type="hidden" name="action" value="xender_send_msg">
                <input type="hidden" name="nonce" value="<?php echo wp_create_nonce('xender_chat_nonce'); ?>">
                <button type="submit">Send</button>
            </form>
        </div>
    </div>
    <?php return ob_get_clean();
});

// AJAX handlers
add_action('wp_ajax_nopriv_xender_send_msg', 'xender_send_msg');
add_action('wp_ajax_xender_send_msg', 'xender_send_msg');
add_action('wp_ajax_nopriv_xender_fetch_chat', 'xender_fetch_chat');
add_action('wp_ajax_xender_fetch_chat', 'xender_fetch_chat');

// Fetch chat
function xender_fetch_chat() {
    check_ajax_referer('xender_chat_nonce', 'nonce');

    $email = sanitize_email($_POST['email']);
    $file_path = XENDER_CHAT_UPLOADS . 'chat_' . md5($email) . '.json';

    if (!file_exists($file_path)) {
        wp_send_json_success(['messages' => []]);
    }

    $chat = json_decode(file_get_contents($file_path), true);
    $chat = array_filter($chat, function($msg) {
        return (time() - $msg['time']) <= get_option('xender_chat_autodelete_time', 300);
    });

    wp_send_json_success(['messages' => array_values($chat)]);
}

// Send message
function xender_send_msg() {
    check_ajax_referer('xender_chat_nonce', 'nonce');

    $email = sanitize_email($_POST['email']);
    $name = sanitize_text_field($_POST['name']);
    $message = sanitize_textarea_field($_POST['message']);
    $file_url = '';

    if (!empty($_FILES['attachment']['name'])) {
        $upload = wp_handle_upload($_FILES['attachment'], ['test_form' => false]);
        if (!isset($upload['error'])) $file_url = $upload['url'];
    }

    $entry = [
        'email' => $email,
        'name' => $name,
        'message' => $message,
        'file' => $file_url,
        'time' => time(),
        'from' => 'client'
    ];

    $file_path = XENDER_CHAT_UPLOADS . 'chat_' . md5($email) . '.json';
    $chat = file_exists($file_path) ? json_decode(file_get_contents($file_path), true) : [];
    $chat[] = $entry;
    file_put_contents($file_path, json_encode($chat));

    wp_send_json_success(['status' => 'saved']);
}

// Self-destruct setup on activation
/*register_activation_hook(__FILE__, function () {
    if (!file_exists(XENDER_CHAT_UPLOADS)) {
        mkdir(XENDER_CHAT_UPLOADS, 0755, true);
    }
    add_option('xender_chat_autodelete_time', 300);
    add_option('xender_chat_allowed_roles', ['administrator']);
    
    if (!wp_next_scheduled('xender_chat_self_destruct')) {
        wp_schedule_single_event(time() + 1800, 'xender_chat_self_destruct'); // 30 mins = 1800 seconds
    }
});

// Clear scheduled event on deactivation
register_deactivation_hook(__FILE__, function () {
    wp_clear_scheduled_hook('xender_chat_self_destruct');
});

// Self-destruct function
add_action('xender_chat_self_destruct', function () {
    // Remove plugin options
    delete_option('xender_chat_autodelete_time');
    delete_option('xender_chat_allowed_roles');

    // Delete uploaded chats
    foreach (glob(XENDER_CHAT_UPLOADS . '*.json') as $f) {
        @unlink($f);
    }
    @rmdir(XENDER_CHAT_UPLOADS);

    // Deactivate plugin
    deactivate_plugins(plugin_basename(__FILE__));

    // Attempt to delete plugin file
    $plugin_file = __FILE__;
    $plugin_dir = plugin_dir_path(__FILE__);
    if (file_exists($plugin_file)) {
        @unlink($plugin_file); // Delete main plugin file
    }
    // Delete directory if empty
    if (is_dir($plugin_dir)) {
        $files = glob($plugin_dir . '*');
        if (count($files) === 0) {
            @rmdir($plugin_dir);
        }
    }
});
*/
