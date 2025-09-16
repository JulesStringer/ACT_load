<?php
// Helper function to form headers for REST API requests
function formrestheaders($credentials){
    $api_user = $credentials['username'];
    $app_password = $credentials['password']; // Paste exactly what was generated
    $user_password = $api_user.':'.$app_password;
    error_log('user_password: '.$user_password);
    $auth_header = 'Basic ' . base64_encode($user_password);

    $args = [
        'headers' => [
            'Authorization' => $auth_header,
        ],
    ];
    return $args;
}
// Fetch a single post or page by slug from a WordPress REST API endpoint
// Usage: act_load_pages_posts_fetch_single_wp_rest_by_slug($credentials, $slug, $post_type = 'post')
// $credentials = array with keys: site_url, username, password
// $slug = the slug of the post or page to fetch
// $post_type = 'post' or 'page' (default is 'post')
// returns: array of post/page data or error message
//          for success each element of the array is an associative array containing
//          the post/page data requested in raw form.
//          for failure returns an empty array or error message string
//
function act_load_pages_posts_fetch_single_wp_rest_by_slug($site_code, $slug, $post_type = 'post') {
    // Decode the JSON string to get the credentials array
    $all_credentials = json_decode(REMOTE_CREDENTIALS, true);
    
    // Check if the site code exists in the credentials array
    if (!isset($all_credentials[$site_code])) {
        // Return an error or handle the case where the site code is not found
        error_log('Error: Invalid site code provided.');
        return false;
    }
    $credentials = $all_credentials[$site_code];
    error_log('Credentials for site code ' . $site_code . ': ' . var_export($credentials, true));
    $base_url = $credentials['site_url'];
    $endpoint = rtrim($base_url, '/') . '/wp-json/wp/v2/' . $post_type . 's/?slug=' . $slug. '&context=edit';
    error_log('$endpoint: '.$endpoint);
    $args = formrestheaders($credentials);
    $response = wp_remote_get($endpoint, $args);

    if (is_wp_error($response)) {
        return 'Error: ' . $response->get_error_message();
    }

    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body, true);

    if (empty($data)) {
        error_log ( 'Error: Empty or invalid response.');
        return array();
    }

    if (isset($data['code'])) {
        error_log('API Error: ' . $data['message']); // Handle WordPress REST API errors
        return array();
    }
    //error_log('Data received: ' . var_export($data, true));
    return $data;
}
// Register the AJAX handler for both logged-in and logged-out users
add_action('wp_ajax_get_remote_page_content', 'act_handle_get_remote_page_content');
add_action('wp_ajax_nopriv_get_remote_page_content', 'act_handle_get_remote_page_content');

function act_handle_get_remote_page_content() {
    error_log('AJAX request received for get_remote_page_content');
    if ( ! check_ajax_referer( 'get-remote-page', 'nonce', false ) ) {
        wp_send_json_error( 'Nonce verification failed.' );
    }
    // Basic security check: ensure the request is a POST
    if ( 'POST' !== $_SERVER['REQUEST_METHOD'] ) {
        wp_send_json_error( 'Invalid request method.' );
    }

    // Get the site code, slug, and post_type from the AJAX request
    $site_code = isset($_POST['site_code']) ? sanitize_text_field($_POST['site_code']) : '';
    $slug = isset($_POST['slug']) ? sanitize_text_field($_POST['slug']) : '';
    $post_type = isset($_POST['post_type']) ? sanitize_text_field($_POST['post_type']) : 'post';

    if (empty($site_code) || empty($slug)) {
        wp_send_json_error('Site code and slug are required.');
    }

    // Call your existing function to fetch the content
    $page_data = act_load_pages_posts_fetch_single_wp_rest_by_slug($site_code, $slug, $post_type);

    // Check the result and return it as JSON
    if (is_string($page_data) && !empty($page_data)) {
        wp_send_json_error($page_data); // Pass the error message back to the client
    } else {
        wp_send_json_success($page_data);
    }

    // Always exit after sending JSON
    wp_die();
}
 
?>