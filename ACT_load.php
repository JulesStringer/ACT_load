<?php
/**
 * Plugin Name: ACT Load
 * Plugin URI:  https://sites.stringerhj.co.uk/ACT/WP_plugins/ACT_load/html/ACT_load.html
 * Description: Loads content from JSON URLs, exiting pages/posts
 * Version: 1.0.0
 * Author: Julian Stringer
 * Author URI: // (Optional: URL to your website)
 */

// Include other files
require_once plugin_dir_path(__FILE__) . 'includes/jci-handlers.php'; // JCI functions
require_once plugin_dir_path(__FILE__) . 'includes/json-upload-callback.php';   // Admin page HTML and form
// ... any other includes

// Enqueue scripts (add this to your plugin file)
add_action('admin_enqueue_scripts', 'act_load_enqueue_scripts');

function act_load_enqueue_scripts() {
    wp_enqueue_script('act-load-script', plugins_url('js/act-load.js', __FILE__), array('jquery'), '1.0', true);
    wp_localize_script('act-load-script', 'act_load_params', array('ajaxurl' => admin_url('admin-ajax.php')));
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
}
function act_load_page() {
    // Top-level page content (can be empty or a welcome message)
    echo '<h2>ACT Load</h2>';

    // Display links to the sub-menu pages (optional)
    echo '<ul>';
        echo '<li><a href="' . admin_url( 'admin.php?page=act-load-single-json') .'">Load single JSON page</a></li>';
    echo '</ul>';
}

function act_load_single_json_page() { // Callback for the single JSON page
    include plugin_dir_path(__FILE__) . 'html/single-json-page.html'; 
}
add_action('admin_init',                   'act_load_single_json_page_handler'); // New handler function
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