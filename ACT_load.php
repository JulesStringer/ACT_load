<?php
/**
 * Plugin Name: ACT Load
 * Plugin URI:  https://sites.stringerhj.co.uk/ACT/WP_plugins/ACT_load/html/ACT_load.html
 * Description: Loads content from JSON URLs, exis  ting pages/posts
 * Version: 1.1.0
 * Author: Julian Stringer
 * Author URI: // (Optional: URL to your website)
 */

// Include other files
require_once plugin_dir_path(__FILE__) . 'includes/categories.php';
require_once plugin_dir_path(__FILE__) . 'includes/jci-handlers.php'; // JCI functions
require_once plugin_dir_path(__FILE__) . 'includes/json-upload-callback.php';   // Admin page HTML and form
require_once plugin_dir_path(__FILE__) . 'includes/act-load-pages-posts.php';
require_once plugin_dir_path(__FILE__) . 'includes/process-image.php';
require_once plugin_dir_path(__FILE__) . 'includes/load-recipients.php';
require_once plugin_dir_path(__FILE__) . 'includes/movemedia.php';
require_once plugin_dir_path(__FILE__) . 'includes/url_checker.php';
// ... any other includes

// Enqueue scripts (add this to your plugin file)
add_action('admin_enqueue_scripts', 'act_load_enqueue_scripts');

function act_load_enqueue_scripts( $hook_suffix ) {
    // Helper to get file modification time for versioning
    function act_get_script_version($relative_path) {
        $file = plugin_dir_path(__FILE__) . $relative_path;
        return file_exists($file) ? filemtime($file) : false;
    }
    // move-things.js
    $move_things_js = 'js/move_things.js';
    wp_enqueue_script('move-things-script', plugins_url($move_things_js, __FILE__),
        array(), act_get_script_version($move_things_js), true);
    $move_media_nonce = wp_create_nonce('move_media_nonce');
    $move_image_nonce = wp_create_nonce('move_image_nonce');
    $ajax_url         = admin_url('admin-ajax.php');
    wp_localize_script('move-things-script', 'move_things_params', array(
        'ajax_url'          => $ajax_url,
        'move_media_nonce' => $move_media_nonce,
        'move_image_nonce' => $move_image_nonce,
    ));
    // act-load.js
    $act_load_js = 'js/act-load.js';
    wp_enqueue_script('act-load-script', plugins_url($act_load_js, __FILE__),
        array('jquery'),act_get_script_version($act_load_js),true);
    wp_localize_script('act-load-script', 'act_load_params', 
                    array('ajaxurl' => $ajax_url));

    // site_lookups.js
    $site_lookups_js = 'js/site_lookups.js';
    wp_enqueue_script('act-load-site-lookups', plugins_url($site_lookups_js, __FILE__),
        array(), act_get_script_version($site_lookups_js), true);

    if ( $hook_suffix === 'act-load_page_act-load-check-pages-posts' ){
        // check-pages-posts.js
        $check_pages_posts_js = 'js/check-pages-posts.js';
        wp_enqueue_script('check-pages-posts', plugins_url($check_pages_posts_js, __FILE__), 
            array('jquery','act-load-site-lookups', 'move-things-script'), act_get_script_version($check_pages_posts_js), true);
        $localized_data = array(
            'rest_url'           => get_rest_url() . 'wp/v2/',
            'home_url'           => home_url(),
            'nonce'              => wp_create_nonce( 'wp_rest' ),
            'ajax_url'           => $ajax_url,
            'url_check_nonce'    => wp_create_nonce( 'url_check_nonce'), 
            'recipients_csv'     => load_recipients()
        );
        wp_localize_script('check-pages-posts','check_pages_data', $localized_data);
    }
    if ($hook_suffix === 'act-load_page_act-load-pages-posts') {
        // act-load-pages-posts.js
        $pages_posts_js = 'js/act-load-pages-posts.js';
        wp_enqueue_script('act-load-pages-posts-script', plugins_url($pages_posts_js, __FILE__),
            array('jquery'), act_get_script_version($pages_posts_js), true);
    }
    if ($hook_suffix === 'act-load_page_act-load-migrate-vm') {
        // vm-migrate.js
        $vm_migrate_js = 'js/act_vm_migrate.js';
        wp_enqueue_script('vm-migrate-script', plugins_url($vm_migrate_js, __FILE__),
            array('jquery', 'move-things-script'), act_get_script_version($vm_migrate_js), true);
        $remote_credentials = [
            'site_url' => 'https://actionclimateteignbridge.org/oldsite',
            'username' => 'apimigrator',
            'password' => 'YKbx f4mI AcY4 uBKW qFMl Fqgj',
        ];
        $vm_migrate_data = array(
            'remote_credentials' => $remote_credentials,
            'rest_url_base'      => get_rest_url(),
            'home_url'           => home_url(),
            'wp_rest_nonce'      => wp_create_nonce( 'wp_rest' ),
            'ajax_url'           => $ajax_url,
        );
        wp_localize_script('vm-migrate-script','vm_migrate_data', $vm_migrate_data);
    }
}
// AJAX Handler (just the action registration here)
add_action('wp_ajax_act_load_upload', 'act_convert_upload_callback');

// Admin Menu
add_action( 'admin_menu', 'act_load_menu' );

function act_load_menu() {
    if ( current_user_can('edit_posts') ) {
        error_log("Current user has edit_posts capability."); // Check your PHP error log
    } else {
        error_log("Current user DOES NOT have edit_posts capability."); // Check your PHP error log
    }
    add_menu_page( 'ACT Load', 'ACT Load', 'read', 'act-load', 'act_load_page', 'dashicons-list-view' ); // Top-level menu
    add_submenu_page('act-load', 'Single JSON page/post', 'Single JSON page/post', 'edit_posts', 'act-load-single-json','act_load_single_json_page');
    add_submenu_page('act-load', 'Load Posts and Pages', 'Load Posts and Pages', 'edit_posts', 'act-load-pages-posts',  'act_load_pages_posts');
    add_submenu_page('act-load', 'Check Posts and Pages', 'Check Posts and Pages', 'edit_posts', 'act-load-check-pages-posts','act_load_check_pages_posts');
    add_submenu_page('act-load', 'Migrate versioned documents', 'Migrate versioneddocuments', 'edit_posts', 'act-load-migrate-vm','act_migrate_vm_page');

}
function act_load_page() {
    // Top-level page content (can be empty or a welcome message)
    echo '<h2>ACT Load</h2>';

    // Display links to the sub-menu pages (optional)
    echo '<ul>';
        echo '<li><a href="' . admin_url( 'admin.php?page=act-load-single-json') .'">Load single JSON page</a></li>';
        echo '<li><a href="' . admin_url( 'admin.php?page=act-load-pages-posts') .'">Load Pages and Posts</a></li>';
        echo '<li><a href="' . admin_url( 'admin.php?page=act-load-check-pages-posts').'">Check Posts and Pages</a></li>';
        echo '<li><a href="' . admin_url( 'admin.php?page=act-load-migrate-vm').'">Migrate versioned documents</a></li>';
    echo '</ul>';
}

function act_load_single_json_page() { // Callback for the single JSON page
    include plugin_dir_path(__FILE__) . 'html/single-json-page.html'; 
}
function act_load_check_pages_posts() {
    include plugin_dir_path(__FILE__). 'html/check-pages-posts.html';
}
function act_migrate_vm_page() {
    include plugin_dir_path(__FILE__) . 'html/act-load-vm-migrate.html';
}
function act_load_pages_posts() {
    // Callback for the Load Pages and Posts page
    include plugin_dir_path(__FILE__) . 'html/act-load-pages-posts.html';
?>
<div class="wrap">
    <h2>Load Pages and Posts</h2>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <input type="hidden" name="action" value="act_load_pages_posts_process">
        <?php // ... rest of your form ... ?>
    </form>
</div>
<?php
}
add_action('admin_init', 'act_load_single_json_page_handler'); // New handler function
function act_load_single_json_page_handler() {
    if (isset($_POST['submit_button']) && current_user_can('manage_options')) {
        if (current_user_can('manage_options')) {
            // 1. Handle File Upload
            if (isset($_FILES['json_file']) && $_FILES['json_file']['error'] === UPLOAD_ERR_OK) {
                $file_path = $_FILES['json_file']['tmp_name']; // Temporary file path

                // 2. Read JSON from the uploaded file
                $json_data = file_get_contents($file_path);

                if ($json_data === false) {
                    echo '<div class="notice notice-error"><p>Error reading JSON file.</p></div>';
                    return;
                }

                $data = json_decode($json_data, true);

                if (json_last_error() !== JSON_ERROR_NONE) {
                    echo '<div class="notice notice-error"><p>Invalid JSON data.</p></div>';
                    return;
                }

                // 3. Get other form data
                $image_handling = sanitize_text_field($_POST['image_handling']);
                $link_handling = sanitize_text_field($_POST['link_handling']);

                // 4. Import Content
                $type = isset($data['type']) ? $data['type'] : 'page';
                $post_id = jci_upload_content($data, $type, $image_handling, $link_handling);

                if ($post_id) {
                    echo '<div class="notice notice-success"><p>Content uploaded successfully!</p></div>';
                } else {
                    echo '<div class="notice notice-error"><p>Error uploading content.</p></div>';
                }
            } else {
                echo '<div class="notice notice-error"><p>Error uploading file.</p></div>';
            }
        } else {
            echo '<div class="notice notice-error"><p>You do not have permission to upload.</p></div>';
        }
    }
}

?>