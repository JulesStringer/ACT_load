<?php
function moveupload($href) {
    // --- Configuration ---
    // No need for $new_upload_directory_base anymore

    // --- Extract filename from the href ---
    $filename = basename($href);

    // --- Check if an attachment with the same filename already exists ---
    $existing_attachments = get_posts(array(
        'post_type' => 'attachment',
        'name' => sanitize_file_name(pathinfo($filename, PATHINFO_FILENAME)), // Search by slug (filename without extension)
        'posts_per_page' => 1,
        'fields' => 'ids',
    ));

    $result = array();
    if ($existing_attachments) {
        $existing_attachment_id = $existing_attachments[0];
        $new_href = wp_get_attachment_url($existing_attachment_id);
        error_log("File with filename '{$filename}' already exists in the media library (ID: {$existing_attachment_id}). 
                    Using existing URL: '{$new_href}'");
        return [
            'status' => 200,
            'message' => 'File already exists in media library.',
            'original_url' => $href,
            'new_url' => $new_href,
        ];
    }

    // --- Get the file content from the URL ---
    $file_content = wp_remote_get($href);

    if (is_wp_error($file_content)) {
        error_log("Error: Could not retrieve content from '{$href}'. " . $file_content->get_error_message());
        return [
            'status' => 500,
            'message' => "Could not retrieve content: " . $error_message,
            'original_url' => $href,
            'new_url' => null,
        ];
    }

    $response_code = wp_remote_retrieve_response_code($file_content);
    if ($response_code !== 200) {
        $error_message = "Error: Received HTTP status code {$response_code} when trying to access '{$href}'";
        return [
            'status' => $response_code,
            'message' => $error_message,
            'original_url' => $href,
            'new_url' => null,
        ];
    }

    $file_content_body = wp_remote_retrieve_body($file_content);
    if ($file_content_body === '') {
        $error_message = "Error: Retrieved empty content from '{$href}'";
        return [
            'status' => 500,
            'message' => $error_message,
            'original_url' => $href,
            'new_url' => null,
        ];
    }

    // --- Prepare file array for wp_upload_bits ---
    $upload = wp_upload_bits($filename, null, $file_content_body);
    if ($upload['error']) {
        $error_message = $upload['error'];
        error_log( "Error: Could not upload file to WordPress: " . $error_message);
        return [
            'status' => 500,
            'message' => $error_message,
            'original_url' => $href,
            'new_url' => null,
        ];
    }

    // --- Prepare attachment array for wp_insert_attachment ---
    $wp_filetype = wp_check_filetype( basename( $upload['file'] ), null );
    $attachment = array(
        'post_mime_type' => $wp_filetype['type'],
        'post_title'    => preg_replace( '/\.[^.]+$/', '', basename( $upload['file'] ) ),
        'post_status'   => 'inherit',
    );

    // --- Insert attachment ---
    $attach_id = wp_insert_attachment( $attachment, $upload['file'] );

    if ( is_wp_error( $attach_id ) ) {
        $error_message = $attach_id->get_error_message();
        return [
            'status' => 500,
            'message' => $error_message,
            'original_url' => $href,
            'new_url' => null,
        ];
    }

    // --- Generate attachment metadata ---
    require_once( ABSPATH . 'wp-admin/includes/image.php' );
    $attach_data = wp_generate_attachment_metadata( $attach_id, $upload['file'] );
    wp_update_attachment_metadata( $attach_id, $attach_data );

    // --- Add media category
    set_media_category($attach_id, 'Documents', $report);

    // --- Get the attachment URL ---
    $new_href = wp_get_attachment_url( $attach_id );

    error_log( "Moved '{$href}' (fetched from URL) to media library. New URL: '{$new_href}'");
    return [
        'status' => 200,
        'message' => 'File moved successfully.',
        'original_url' => $href,
        'new_url' => $new_href,
    ];
}

/**
 * Registers and handles the AJAX endpoint for the media update function.
 * This file should be included in your plugin's main admin file.
 */

// This hook listens for AJAX requests from logged-in users with the 'move_media_item' action.
add_action('wp_ajax_move_media_item', 'handle_move_media_item');

/**
 * Handles the AJAX request to move and update a media item.
 *
 * This function is the bridge between the JavaScript call and your
 * existing PHP function `moveupdate()`.
 */
function handle_move_media_item() {
    // Security check: Verify the nonce to ensure the request is legitimate.
    check_ajax_referer('move_media_nonce', 'security');

    // Ensure the required 'href' parameter is present in the request.
    if (!isset($_POST['href'])) {
        wp_send_json_error([
            'status' => 400,
            'message' => 'Missing required parameter: href',
            'original_url' => null,
            'new_url' => null,
        ]);
    }

    $href = sanitize_url($_POST['href']);

    // Call your existing PHP function. Assuming it's in a separate file
    // that is already included in your plugin.
    // Replace 'moveupdate' with the actual function name if it's different.
    $result = moveupload($href);

    // Send a JSON response back to the JavaScript function.
    // The 'data' key will contain the new URL.
    if ($result['status'] === 200) {
        wp_send_json_success($result);
    } else {
        wp_send_json_error($result);
    }
}
