<?php

// AJAX Handler
add_action('wp_ajax_act_load_json_upload', 'act_load_single_json_page_callback');

function act_load_single_json_page_callback() {
    check_ajax_referer('act_load_nonce', 'nonce');

    $base_url = sanitize_text_field($_POST['base_url']);
    $slug = sanitize_text_field($_POST['slug']);
    $suffix = sanitize_text_field($_POST['suffix']);
    $image_handling = sanitize_text_field($_POST['image_handling']);
    $link_handling = sanitize_text_field($_POST['link_handling']);

    update_option('act_load_base_url', $base_url);

    $json_url = $base_url . $slug . $suffix;

    $response = wp_remote_get($json_url);

    if (is_wp_error($response)) {
        wp_send_json_error(array('message' => 'Error fetching JSON: ' . $response->get_error_message()));
    }

    $json_data = wp_remote_retrieve_body($response);

    if (!$json_data) {
        wp_send_json_error(array('message' => 'Error retrieving JSON data.'));
    }

    $data = json_decode($json_data, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        wp_send_json_error(array('message' => 'Invalid JSON data.'));
    }

    // Get the type from the JSON data
    $type = isset($data['type']) ? $data['type'] : 'page'; // Default to 'page' if type is not set

    $post_id = jci_upload_content($data, $type, $image_handling, $link_handling);

    if ($post_id) {
        wp_send_json_success(array('message' => 'Content uploaded successfully!', 'postId' => $post_id));
    } else {
        wp_send_json_error(array('message' => 'Error uploading content.'));
    }
}

?>