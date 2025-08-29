<?php
// act-load-pages-posts-process.php

function logit($message){
    set_transient('act_load_migration_progress', $message, 60);
    error_log($message);
}
function report_li(&$report, $msg){
    $report[] = "<li>". esc_html($msg). "</li>";
}
function guess_image_format_from_string(string $image_content): ?string {
    // Check for PNG signature
    if (substr($image_content, 0, 8) === "\x89PNG\r\n\x1a\n") {
        return 'png';
    }

    // If not PNG, make an assumption (risky!)
    return 'jpg';
}
function file_from_image( $image, $suffix){
    if ( $suffix === 'png'){
        $result_file = tempnam(sys_get_temp_dir(), 'png');
        imagepng($image, $result_file);
    } else {
        $result_file = tempnam(sys_get_temp_dir(), 'jpg');
        imagejpeg($image, $result_file);
        $suffix = 'jpg';
    }
    return $result_file;
}
function act_load_pages_posts_resize_image($image_content) {
    try {
        $image = imagecreatefromstring($image_content);
        if (!$image) {
            return false;
        }
        $suffix = guess_image_format_from_string($image_content);
        $width = imagesx($image);
        $height = imagesy($image);
        $new_width = $width;
        $new_height = $height;
        // Resize to 300x300

        $max_size = 200 * 1024; // 200KB
        $result_file = file_from_image( $image, $suffix);
        $file_size = filesize($result_file);
        $factor = $max_size / $file_size;
        while($file_size > $max_size && $width >= 300 && $height >= 300){
            $new_width = floor($width * $factor);
            $new_height = floor($height * $factor);
            $resized_image = imagecreatetruecolor($new_width, $new_height);

            imagecopyresampled($resized_image, $image, 0, 0, 0, 0, $new_width, $new_height, $width, $height);
            unlink($result_file);
            $result_file = file_from_image( $resized_image, $suffix);
            $file_size = filesize($result_file);
            $factor *= 0.9;
        }
        imagedestroy($image);
        if ( isset($resized_image)){
            imagedestroy($resized_image);
        }
        $result = array(
            'file' => $result_file,
            'suffix' => $suffix,
            'filesize' => $file_size,
            'dimensions' => array('width' => $new_width, 'height' => $new_height)
        );
        return $result;
    } catch (Exception $e) {
        return false;
    }
}
function set_media_category($attach_id, $category_name, &$report){
    if ( $attach_id ){
        // Get the term ID if it exists, or create it if it doesn't
        $term = term_exists($category_name, 'attachment_category'); // Replace 'media_category' with your actual taxonomy slug

        if ($term !== 0 && $term !== null) {
            $category_id = $term['term_id'];
        } else {
            $new_term = wp_insert_term($category_name, 'attachment_category'); // Replace 'media_category'
            if (!is_wp_error($new_term)) {
                $category_id = $new_term['term_id'];
            } else {
                // Handle error creating term
                error_log("Error creating attachment category: " . $new_term->get_error_message());
                $category_id = 0; // Or some other error indicator
            }
        }

        if ($category_id) {
            wp_set_post_terms($attach_id, array($category_id), 'attachment_category'); // Replace 'media_category'
            report_li( $report, sprintf("Image %d categorized successfully as %s",$attach_id, $category_name));
        } else {
            report_li( $report, sprintf("Faiied to assign %d a category", $attach_id));
        }
    }
}
function remove_orphaned_resized_images( $base_filename, $upload_dir = null ) {
    // Get upload dir
    if ( !$upload_dir ) {
        $upload_dir = wp_get_upload_dir()['basedir'];
    }

    // Remove extension, just work with base
    $info = pathinfo( $base_filename );
    $name = $info['filename']; // e.g. Wood-burner
    $ext  = isset($info['extension']) ? $info['extension'] : 'jpg';

    // Glob all variants with WxH suffix
    $pattern = sprintf('%s/%s-*x*.%s', $upload_dir, $name, $ext);
    $files   = glob( $pattern );

    foreach ( $files as $file ) {
        error_log("Deleting orphaned file: $file");
        @unlink( $file );
    }
}
function search_for( $search_filename ){
    error_log('Looking for ' . $search_filename);
    $existing_attachments = get_posts(array(
        'post_type'      => 'attachment',
        'name'     => $search_filename, // Search by slug (filename without extension)
        'posts_per_page' => 1,
        'fields'         => 'ids',
        'meta_query'     => array( // Ensure it's an image attachment, not another post type with same slug
            array(
                'key'     => '_wp_attachment_metadata',
                'compare' => 'EXISTS',
            ),
        ),
    ));
    if ( ! empty($existing_attachments) ){
        $result = array();
        $result['attach_id'] = $existing_attachments[0];
        $result['src'] = wp_get_attachment_url($result['attach_id']);
        error_log(sprintf("Image already uploaded as attachment %d %s", $result['attach_id'], $result['src']));
        return $result;
    }
    return null;
}
function reform_original_image_url($src){
// in some cases get image paths like which needs WxH at end removing
//https://actionclimateteignbridge.org/oldsite/wp-content/uploads/2021/09/Screenshot-2021-09-14-at-01.26.24-288x300.png
// Image not found, proceed with new upload
    $fileinfo = pathinfo($src);
    $filename = $fileinfo['filename'];
    error_log('raw filename : '.$filename);
    $filename = preg_replace('/-\d+x\d+$/', '', $filename);
    error_log('Tidy filename: '.$filename);
    $image_url = $fileinfo['dirname'].'/'.$filename.'.'.$fileinfo['extension'];
    return $image_url;
}
function transform_and_upload_image($src, &$report, $content_type, $site_url){
    //error_log('transform_and_upload_image $content_type '.$content_type. ' site_url: '.$site_url);
    // Download image
    $attach_id = 0;
    $a = explode('?', $src);
    $src = $a[0];

    if ( !str_starts_with($src, $site_url)){
        error_log('Url not on source so not moving '.$src );
        $result = array();
        $result['attach_id'] = 0;
        $result['src'] = $src;
        return $result;
    }

    $fileinfo = pathinfo($src);
    // Extract year and date from directory
    $directory = $fileinfo['dirname'];
    $parts = explode('/',$directory);
    $dirlength = count($parts);
    $month = $parts[$dirlength - 1];
    $year = $parts[$dirlength - 2];
    $prefix = $year .'_'.$month.'_';
    error_log('Extracted prefix '.$prefix);
    // form filename
    $filename = $prefix . $fileinfo['filename']; // . '-' . $fileinfo['extension'];
    $suffix = $fileinfo['extension'];
    $search_filename = strtolower(sanitize_file_name($filename)).'-'.$suffix;
    // test if image has already been uploaded by WordPress's default slug
    $result = search_for( $search_filename );
    if ( $result === null ){
        $search_filename = preg_replace('/-\d+x\d+(?=-[a-z0-9]+$)/', '', $search_filename);
        $result = search_for($search_filename );
    }
    if ( $result === null ){
        if ( $suffix === 'jpeg'){
            $search_filename = str_replace('jpeg','jpg', $search_filename);
            $result = search_for($search_filename );
            $suffix = 'jpg';
        }
    }
    if ( $result === null ){
        $result = array(
            'attach_id' => 0
        );
        // form name of image to get from $src as input less WxH
        error_log('Original $src:          '.$src);
        $image_url = reform_original_image_url($src);
        error_log('Getting image data for: '.$image_url);
        $image_data = wp_remote_get($image_url);
        if (is_wp_error($image_data)) {
            report_li($report, "Error downloading image: " . esc_html($image_url));
        } else {
            $image_content = wp_remote_retrieve_body($image_data);

            // Convert resize image
            $resized = act_load_pages_posts_resize_image($image_content);
            if ($resized) {
                // Upload to media library
                $suffix = $resized['suffix'];
                $resized_file = $resized['file'];
                // Now form sanitized name from searched for name
                $fullfilename = str_replace('-'.$suffix, '.'.$suffix, $search_filename);
                error_log('Removing orphaned resized '.$fullfilename);
                remove_orphaned_resized_images( $fullfilename );
                // Use wp_upload_bits to save the file
                $upload_file = wp_upload_bits($fullfilename, null, file_get_contents($resized_file));

                if ($upload_file['error']) {
                    error_log("Error uploading image: " . esc_html($src) . " - " . $upload_file['error']);
                } else {
                    // --- CRITICAL: Determine the correct 'file' path for metadata ---
                    // This path needs to be relative to the uploads base directory.
                    // Since "Organize uploads..." is unchecked, subdir should be empty.
                    // $upload_file['file'] contains the absolute path like '/var/www/.../uploads/my-image.jpg'
                    // We need 'my-image.jpg' for metadata.
                    $upload_dir_info = wp_upload_dir(); // Get current upload configuration
                    $relative_file_path_for_metadata = ltrim( str_replace( $upload_dir_info['basedir'], '', $upload_file['file'] ), '/' );

                    $attachment_array = array(
                        'guid'           => $upload_file['url'], // Full URL to the original file
                        'post_mime_type' => 'image/'.$suffix,
                        'post_title'     => basename($upload_file['file']), // Title from filename
                        'post_content'   => '',
                        'post_status'    => 'inherit',
                        'file'           => $relative_file_path_for_metadata, // THIS IS THE FIX: crucial for get_attached_file()
                    );

                    error_log('Attachment details for wp_insert_attachment: '. var_export($attachment_array, true));

                    // Insert the attachment post into the database
                    // Second argument to wp_insert_attachment is the absolute path to the file
                    $attach_id = wp_insert_attachment($attachment_array, $upload_file['file']);

                    if ($attach_id) {
                        $result['attach_id'] = $attach_id;

                        // Include necessary files for image processing
                        require_once(ABSPATH . 'wp-admin/includes/image.php');
                        require_once(ABSPATH . 'wp-admin/includes/file.php');
                        require_once(ABSPATH . 'wp-admin/includes/media.php');

                        // Generate/update metadata (including 'sizes' array)
                        wp_update_attachment_metadata($attach_id, wp_generate_attachment_metadata($attach_id, $upload_file['file']));

                        // --- Debug Path Checks (as provided by you) ---
                        $main_image_path = get_attached_file($attach_id);
                        error_log('--- Path Checks for ID ' . $attach_id . ' ---');
                        error_log('Main Image Server Path (from get_attached_file): ' . $main_image_path);
                        error_log('Main Image file_exists(): ' . (file_exists($main_image_path) ? 'Yes' : 'No'));
                        error_log('Main Image is_readable(): ' . (is_readable($main_image_path) ? 'Yes' : 'No'));

                        $metadata = wp_get_attachment_metadata($attach_id);
                        if (isset($metadata['sizes']['thumbnail']['file'])) {
                            $thumbnail_relative_filename = $metadata['sizes']['thumbnail']['file'];
                            // Construct full server path to thumbnail
                            $thumbnail_server_path = dirname($main_image_path) . '/' . $thumbnail_relative_filename;

                            error_log('Thumbnail Server Path: ' . $thumbnail_server_path);
                            error_log('Thumbnail file_exists(): ' . (file_exists($thumbnail_server_path) ? 'Yes' : 'No'));
                            error_log('Thumbnail is_readable(): ' . (is_readable($thumbnail_server_path) ? 'Yes' : 'No'));
                        } else {
                            error_log('Thumbnail size not found in metadata (should not happen if generation succeeded).');
                        }
                        error_log('--- End Path Checks ---');
                        // --- End Debug Path Checks ---

                        // Set Alt Text (using the post title as fallback)
                        $alt_text_to_set = get_the_title($attach_id);
                        $alt_text_to_set = sanitize_text_field($alt_text_to_set);
                        if (empty($alt_text_to_set)) {
                            $alt_text_to_set = 'Image: ' . basename($upload_file['file']);
                        }
                        update_post_meta($attach_id, '_wp_attachment_image_alt', $alt_text_to_set);
                        error_log(sprintf("Set alt text for attachment %d: \"%s\"", $attach_id, esc_html($alt_text_to_set)));

                        // Get the final URL of the newly created attachment
                        $new_src = wp_get_attachment_url($attach_id);
                        $result['src'] = $new_src;
                        error_log("Image transformed and uploaded: " . esc_html($src) . " to: " . esc_html($new_src));
                        
                        // Set media category (your custom function)
                        if ( $content_type === 'team' ){
                            set_media_category($attach_id, 'Team images', $report);
                        } else {
                            set_media_category($attach_id, 'Post images', $report);
                        }

                    } else {
                        error_log("Error inserting attachment: " . esc_html($src) . " - wp_insert_attachment failed.");
                    }

                }
                // Clean up the temporary resized file
                unlink($resized_file);
            } else {
                error_log("Error resizing image: " . esc_html($src));
            }
        }
    }
    error_log('transform_and_upload_image $result'.var_export($result, true));
    return $result;
}
function transform_image($img, &$report, &$processed_images, $content_type, $site_url){
    //error_log('transform_image $content_type '.$content_type. ' site_url: '.$site_url);
    $src = $img->getAttribute('src');
    $alt = $img->getAttribute('alt');

    // List image
    report_li($report, "Image found: src=" . esc_html($src) . ", alt=" . esc_html($alt));

    // Check if image has already been processed
    if (in_array($src, $processed_images)) {
        return; // Skip if already processed
    }

    // Download image
    $upload = transform_and_upload_image($src, $report, $content_type, $site_url);
    if ( !isset($upload['attach_id'])){
        report_li($report, sprintf("Error uploading image %s", $src));
        //var_dump($upload);
    } else {
        $attach_id = $upload['attach_id'];
    }
    if ( isset($upload['src'])){
        $img->setAttribute('src', $upload['src']);
    }
    $processed_images[] = $src; // Add to processed images

}
function get_remote_media_item( $id, $base_url ) {
    $url = trailingslashit( $base_url ) . 'wp-json/wp/v2/media/' . $id;
    $response = wp_remote_get( $url );

    if ( is_wp_error( $response ) ) {
        $error_message = $response->get_error_message();
        error_log( "Error fetching media item {$id} from {$url}: {$error_message}" );
        return false; // Or throw an exception
    }

    $http_code = wp_remote_retrieve_response_code( $response );
    $body = wp_remote_retrieve_body( $response );

    if ( 200 !== $http_code ) {
        error_log( "Invalid status code {$http_code} for media item {$id} from {$url}. Response body: {$body}" );
        return false; // Or throw an exception
    }

    $item = json_decode( $body, true ); // Decode the JSON response into an associative array

    return $item;
}
function get_remote_media_url( $media, $base_url){
    if ( isset($media['guid']) && isset($media['guid']['rendered'])){
        return $media['guid']['rendered'];
    }
    return null;
}
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
function act_load_pages_posts_fetch_all_wp_rest($credentials, $post_type = 'post', $from_date = null, $to_date = null) {
    $all_posts = array();
    $page = 1;
    $local_post_type = $post_type;
    if ( $post_type !== 'team'){
        $local_post_type .= 's';
    }
    $base_url = $credentials['site_url'];
    $endpoint = rtrim($base_url, '/') . '/wp-json/wp/v2/' . $local_post_type . '?page=' . $page . '&context=edit';
    $args = formrestheaders($credentials);
    error_log('args: '.var_export($args, true));
    do {
        $response = wp_remote_get($endpoint, $args);
        error_log('endpoint: '.$endpoint);
        if (is_wp_error($response)) {
            error_log('Error; '.$response->get_error_message());
            return 'Error: ' . $response->get_error_message();
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (empty($data)) {
            break; // No more data
        }

        if (isset($data['code'])) {
            error_log('API Error: '. $data['message']);
            return 'API Error: ' . $data['message']; // Handle WordPress REST API errors
        }

        $all_posts = array_merge($all_posts, $data);

        $headers = wp_remote_retrieve_headers($response);
        if (isset($headers['x-wp-totalpages'])) {
            $total_pages = intval($headers['x-wp-totalpages']);
            $page++;
            $endpoint = rtrim($base_url, '/') . '/wp-json/wp/v2/' . $local_post_type . '?page=' . $page .'&context=edit';
        } else {
            break; // No pagination headers
        }
    } while ($page <= $total_pages);
    error_log(sprintf("%d posts read ", count($all_posts)));
    if ( $from_date !== null || $to_date !== null){
        error_log(sprintf(
             "from_date was %s to_date was %s",
                    $from_date ? $from_date->format('Y-m-d H:i:s') : 'null',
                    $to_date ? $to_date->format('Y-m-d H:i:s') : 'null'
                ));
        $result = [];
        foreach($all_posts as $post){
            $post_date = new DateTime($post['date']); // Directly use $post['date']

            $is_within_range = true;

            if ($from_date !== null) {
                if ($post_date < $from_date) {
                    $is_within_range = false;
                }
            }

            if ($to_date !== null) {
                if ($post_date > $to_date) {
                    $is_within_range = false;
                }
            }

            if ($is_within_range) {
                $result[] = $post;
            }
        }
        $all_posts = $result;
    }
    error_log(sprintf("at return from act_load_pages_posts_fetch_all_wp_rest %d posts read ", count($all_posts)));
    return $all_posts;
}

function act_load_pages_posts_fetch_single_wp_rest($credentials, $post_id, $post_type = 'post') {
    $base_url = $credentials['site_url'];
    $endpoint = rtrim($base_url, '/') . '/wp-json/wp/v2/' . $post_type . 's/' . $post_id . '?context=edit';
    $args = formrestheaders($credentials);
    $response = wp_remote_get($endpoint, $args);

    if (is_wp_error($response)) {
        return 'Error: ' . $response->get_error_message();
    }

    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body, true);

    if (empty($data)) {
        return 'Error: Empty or invalid response.';
    }

    if (isset($data['code'])) {
        return 'API Error: ' . $data['message']; // Handle WordPress REST API errors
    }

//    return array(
//        'title' => $data[0]['title']['rendered'],
//        'content' => $data[0]['content']['rendered'],
//        'meta' => $data[0]['meta'], //example of how to pull meta data
//    );
    return $data;
}
function act_load_pages_posts_fetch_single_wp_rest_by_slug($credentials, $slug, $post_type = 'post') {
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
    error_log('Data received: ' . print_r($data, true));
    return array($data);
}
function act_load_pages_posts_generate_report($report) {
    //Logic for generating a report.
    echo "<div class='wrap'><h2>Migration Report</h2>";
    if (empty($report)) {
        echo "<p>No issues found.</p>";
    } else {
        //echo "<ul>";
        foreach ($report as $message) {
            //echo "<li>" . esc_html($message) . "</li>";
            echo $message;
        }
        //echo "</ul>";
    }
    echo "</div>";
}
function act_load_pages_posts_get_author($source, $content, &$report) {
    if ($source === 'WW') {
        // need to check this works
        $user = get_user_by('login', 'Vicky');
        if ($user) {
            return $user->ID;
        } else {
            report_li($report,"Author 'Vicky' not found. Defaulting to current user.");
            return get_current_user_id();
        }
    } elseif ($source === 'ACT') {
        if($content['type'] === "page"){
            return get_current_user_id();
        } else {
            //$user = get_user_by('id', $content['author']);
            $user_id = $content['author'];
            logit(sprintf("Original author: %d", $user_id));
            $new_id = get_current_user_id();
            switch( $user_id){
                case 1:// jules
                    $new_id = 1;
                    break;
                case 3:// kate
                    $new_id = 11;
                    break;
                case 4:// pauline
                    $new_id = 4;
                    break;
                case 7:// audrey
                    // look up id of user audrey
                    $user = get_user_by('login', 'audrey');
                    if ($user) {
                        $new_id = $user->ID;
                    } else {
                        report_li($report, "Author 'audrey' not found. Defaulting to current user.");
                        $new_id = get_current_user_id();
                    }
                    break;
                case 9:// paul-scholes to paul-scholes
                    $user = get_user_by('login', 'paulscholes');
                    if ($user) {
                        $new_id = $user->ID;
                    } else {
                        report_li($report, "Author 'paulscholes' not found. Defaulting to current user.");
                        $new_id = get_current_user_id();
                    }
                    break;
                case 83:// flavio to vicky
                    $new_id = 5;
                    break;
                case 85:// fuad
                    $new_id = 2;
                    break;
                case 86:// peta to scott
                    $new_id = 10;
                    break;
                case 88:// betina
                    $new_id = 8;
                    break;
                default:
                    logit('Unknown post userid: %d assigning current user ',$user_id);
                    break;
            }
            return $new_id;
        }
    }
    return get_current_user_id(); // Default to current user
}
function moveupload($href, &$report) {
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

    if ($existing_attachments) {
        $existing_attachment_id = $existing_attachments[0];
        $new_href = wp_get_attachment_url($existing_attachment_id);
        report_li($report, "File with filename '{$filename}' already exists in the media library (ID: {$existing_attachment_id}). 
                    Using existing URL: '{$new_href}'");
        return $new_href;
    }

    // --- Get the file content from the URL ---
    $file_content = wp_remote_get($href);

    if (is_wp_error($file_content)) {
        report_li($report, "Error: Could not retrieve content from '{$href}'. " . $file_content->get_error_message());
        return $href;
    }

    $response_code = wp_remote_retrieve_response_code($file_content);
    if ($response_code !== 200) {
        report_li($report, "Error: Received HTTP status code {$response_code} when trying to access '{$href}'");
        return $href;
    }

    $file_content_body = wp_remote_retrieve_body($file_content);
    if ($file_content_body === '') {
        report_li( $report, "Error: Retrieved empty content from '{$href}'");
        return $href;
    }

    // --- Prepare file array for wp_upload_bits ---
    $upload = wp_upload_bits($filename, null, $file_content_body);
    if ($upload['error']) {
        report_li($report, "Error: Could not upload file to WordPress: " . $upload['error']);
        return $href;
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
        report_li( $report, 'Error: Failed to insert attachment into media library: ' . $attach_id->get_error_message());
        return $href;
    }

    // --- Generate attachment metadata ---
    require_once( ABSPATH . 'wp-admin/includes/image.php' );
    $attach_data = wp_generate_attachment_metadata( $attach_id, $upload['file'] );
    wp_update_attachment_metadata( $attach_id, $attach_data );

    // --- Add media category
    set_media_category($attach_id, 'Documents', $report);

    // --- Get the attachment URL ---
    $new_href = wp_get_attachment_url( $attach_id );

    report_li( $report, "Moved '{$href}' (fetched from URL) to media library. New URL: '{$new_href}'");
    return $new_href;
}
function transform_link($link,&$report){
    $href = $link->getAttribute('href');
    logit(sprintf("processing link href: %s", $href));
    if (preg_match('/^(http|https):\/\//', $href)) { // External link
        $domain = parse_url($href, PHP_URL_HOST);
        logit(sprintf("domain: %s", $domain));
        if (strpos($domain, 'actionclimateteignbridge.org') === false && !preg_match('/\.actionclimateteignbridge\.org$/', $domain)) {
            // External link: Check and Report
            $full_url = $href;
        } else { // Internal link: Transform and Report
            // this needs to consider:
            // source WW: prepending WW to href
            // source CC: prepending CC to href
            // source /newsite/page.php/*/ just slug
            // source /oldsite/wp-content/uploads move file to attachments
            $path = parse_url($href, PHP_URL_PATH);
            if ( $path === null || $path === false ){
                $full_url = $href;
                // domain only specified
            } else {
                logit(sprintf("path: %s", $path));
                $query_params_string = parse_url($href, PHP_URL_QUERY);
                $query_params = [];
                if ( !empty($query_params_string)){
                    parse_str($query_params_string, $query_params);
                }
                if ( strpos($path,'/newsite/page.php/post/') !== false){
                    $path = str_replace('/newsite/page.php/post/', '/index.php/', $path);
                } else if ( str_starts_with($path,'/newsite/page.php/page/') ){
                    $path = 'https://actionclimateteignbridge.org' . $path;
                    error_log(sprintf("/newsite/page.php/post/ detected pth set to %s", $path));
                } else if ( str_starts_with($path,'/lookup_document.php') ){
                    $path = 'https://actionclimateteignbridge.org'. $path;
                    error_log(sprintf("/lookup_document.php detected pth set to %s", $path));
                } else if ( strpos($path,'/newsite/page.php/post/') !== false){
                    // This is a post lookup, we need to transform it
                    $path = str_replace('/newsite/page.php/post/', '/index.php/', $path);
                    if ( isset($query_params['slug'])){
                        $slug = $query_params['slug'];
                        logit(sprintf('slug: %s', $slug));
                        $path .= '/' . $slug;
                    }
                } else if ( strpos($path,'/newsite/post.html') !== false){
                    if ( isset($query_params['slug'])){
                        $slug = $query_params['slug'];
                        logit(sprintf('slug: %s', $slug));
                        $path = '/index.php/' . $slug;
                    }
                } else if ( strpos( $path, '/wp-content/uploads/') !== false){
                    $path = moveupload($href, $report);
                } else {
                    // leave path intact
                }
                report_li( $report, "Transformed internal link: " . esc_html($href) . " to: " . esc_html($path));
                $href = $path;
                $link->setAttribute('href', $href);
                logit(sprintf('Transformed link: %s ', esc_html($href)));
                $full_url = $href;
                if ( strpos($href,'https://') === false && strpos($href, 'http://') === false && strpos($href, 'mailto:') === false){
                    $full_url = home_url($href);
                }
                error_log(sprintf("full_url was %s",$full_url));
                // If text starts with https:// then set it to $full_url
                if ( strpos($link->textContent, 'https://') === 0 || strpos($link->textContent, 'http://') === 0 ){
                    $link->textContent = $full_url;
                }
            }
        }
    } else { // Relative link
        // No transformation needed, report
        report_li($report, "Internal link: " . esc_html($href));
        $full_url = home_url($href);
    }
    // 
    //
    // always test links for accessibility
    //
    $response = wp_remote_head($full_url);
    //logit("response to wp_remote_head test: ". var_export($response, true));
    if ( is_wp_error($response)){
        report_li($report, "Link in error ". esc_url($full_url));
        logit(sprintf("Link in error %s", $full_url));
    } else {
        $rcode = wp_remote_retrieve_response_code($response);
        if ( $rcode !== 200) {
            report_li($report, "Link inaccessible: " . esc_html($full_url). " response code: ". $rcode);
        }
        logit(sprintf("Response code %d url: %s", $rcode, $full_url));
    }

}
function act_load_pages_posts_transform_links($content, &$report) {
    libxml_use_internal_errors(true); // suppress DOM warnings

    $content = preg_replace_callback('/<a\s[^>]*>.*?<\/a>/is', function ($matches) use (&$report) {
        $a_html = $matches[0];

        $doc = new DOMDocument();
        $doc->loadHTML('<?xml encoding="utf-8" ?>' . $a_html);
        $link = $doc->getElementsByTagName('a')->item(0);

        if ($link) {
            transform_link($link, $report);
            return $doc->saveHTML($link);
        }

        return $a_html; // fallback
    }, $content);

    return $content;
}

function act_load_pages_posts_transform_images($content, &$report, &$processed_images, $content_type, $site_url) {
    libxml_use_internal_errors(true); // suppress DOM warnings

    // NOTE that arguments must be passed through the following function, otherwise they will not be visible within.
    $content = preg_replace_callback('/<img[^>]*>/i', function ($matches) use (&$report, &$processed_images, $content_type, $site_url) {
        $img_html = $matches[0];

        $doc = new DOMDocument();
        $doc->loadHTML('<?xml encoding="utf-8" ?>' . $img_html); // ensure UTF-8
        $img = $doc->getElementsByTagName('img')->item(0);

        if ($img) {
            transform_image($img, $report, $processed_images, $content_type, $site_url);
            return $doc->saveHTML($img); // return modified <img>
        }

        return $img_html; // fallback
    }, $content);

    return $content;
}

/**
 * Fetches comments for a given old post ID from the remote WordPress REST API
 * and prepares them for insertion into the new site.
 *
 * @param int $old_post_id The ID of the post on the old (remote) site.
 * @param string $base_url The base URL of the old WordPress site (e.g., 'https://actionclimateteignbridge.org/oldsite').
 * @param array $report Reference to the report array for logging.
 * @return array|false An array of comment data, or false on failure.
 * Each comment array is structured for easy mapping to wp_insert_comment.
 */
function process_comments($old_post_id, $credentials, &$report) { // Renamed parameter to $base_url
    $base_url = $credentials['site_url'];
    $comments_url_base = esc_url_raw($base_url . '/wp-json/wp/v2/comments?post=' . $old_post_id . '&per_page=100');
    $comments_url_base .= '&orderby=id&order=asc&context=edit'; // Crucial for reliable parent/child processing order

    $all_remote_comments = [];
    $page = 1;
    $has_more_pages = true;

    $args = formrestheaders($credentials);
    $args['timeout'] = 45; // Increased timeout for potentially large responses

    // Add API authentication if defined (still using constants for credentials as they are less dynamic)
  //  if (defined('OLD_API_USERNAME') && defined('OLD_API_PASSWORD')) {
  //      $args['headers']['Authorization'] = 'Basic ' . base64_encode(OLD_API_USERNAME . ':' . OLD_API_PASSWORD);
  //  }

    report_li($report, sprintf("Starting comment fetch for old post ID %d from %s...", $old_post_id, $base_url)); // Using $base_url in log

    while ($has_more_pages) {
        $comments_url = $comments_url_base . '&page=' . $page;
        report_li($report, "Fetching comments from API: " . $comments_url);
        $response = wp_remote_get($comments_url, $args);

        if (is_wp_error($response)) {
            report_li($report, "API Error fetching comments for old post ID " . $old_post_id . " (page " . $page . "): " . $response->get_error_message());
            return false;
        }

        $body = wp_remote_retrieve_body($response);
        $current_page_comments = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            report_li($report, "JSON decode error for comments API (page " . $page . "): " . json_last_error_msg());
            return false;
        }

        if (empty($current_page_comments)) {
            $has_more_pages = false;
        } else {
            $all_remote_comments = array_merge($all_remote_comments, $current_page_comments);
            $total_pages = (int) wp_remote_retrieve_header($response, 'X-WP-TotalPages');

            if ($page >= $total_pages) {
                $has_more_pages = false;
            } else {
                $page++;
            }
        }
    }

    if (empty($all_remote_comments)) {
        report_li($report, sprintf("No comments found for old post ID %d.", $old_post_id));
        return [];
    }

    report_li($report, sprintf("Successfully retrieved %d comments for old post ID %d from API.", count($all_remote_comments), $old_post_id));

    $prepared_comments = [];
    foreach ($all_remote_comments as $old_comment_arr) {
        $prepared_comments[] = array(
            'old_comment_id'       => (int)$old_comment_arr['id'],
            'old_post_id'          => (int)$old_comment_arr['post'],
            'old_parent_id'        => isset($old_comment_arr['parent']) ? (int)$old_comment_arr['parent'] : 0,

            'comment_author'       => $old_comment_arr['author_name'],
            'comment_author_email' => isset($old_comment_arr['author_email']) ? $old_comment_arr['author_email'] : '',
            'comment_author_url'   => $old_comment_arr['author_url'],
            'comment_content' => $old_comment_arr['content']['raw'] ?? $old_comment_arr['content']['rendered'],
            'comment_type'         => $old_comment_arr['type'],
            'user_id'              => (int)$old_comment_arr['author'],
            'comment_author_IP'    => isset($old_comment_arr['author_ip']) ? $old_comment_arr['author_ip'] : '',
            'comment_agent'        => isset($old_comment_arr['user_agent']) ? $old_comment_arr['user_agent'] : '',
            'comment_date'         => $old_comment_arr['date'],
            'comment_date_gmt'     => $old_comment_arr['date_gmt'],
            'comment_approved'     => ($old_comment_arr['status'] === 'approved' ? '1' : ($old_comment_arr['status'] === 'hold' ? '0' : 'spam')),
            'old_meta'             => isset($old_comment_arr['meta']) ? $old_comment_arr['meta'] : [],
        );
    }

    usort($prepared_comments, function($a, $b) {
        return $a['old_comment_id'] - $b['old_comment_id'];
    });

    return $prepared_comments;
}
/**
 * Deletes all comments associated with a given post ID.
 *
 * @param int   $post_id The ID of the post whose comments are to be deleted.
 * @param array $report  Reference to the report array for logging.
 * @return bool True if successful, false otherwise.
 */
function delete_post_comments($post_id, &$report) {
    if (!$post_id) {
        report_li($report, "Error: No post ID provided to delete_post_comments.");
        return false;
    }

    error_log(sprintf("Attempting to delete all existing comments for new post ID %d...", $post_id));

    $comments = get_comments(array(
        'post_id' => $post_id,
        'fields'  => 'ids', // Only retrieve comment IDs for efficiency
    ));

    if (empty($comments)) {
        error_log(sprintf("No existing comments found for post ID %d. Nothing to delete.", $post_id));
        return true; // No comments found, so technically successful
    }
    // --- ADD THIS DEBUGGING LINE ---
    error_log(sprintf("DEBUG: Contents of \$comments array for post ID %d:", $post_id));
    error_log(print_r($comments, true)); // Using print_r with true to return string for error_log
    // --- END ADDED DEBUGGING LINE ---

    error_log(sprintf("Found %d comments to delete for post ID %d.", count($comments), $post_id));
    $deleted_count = 0;
    $error_count = 0;

    foreach ($comments as $comment_id) {
        // wp_delete_comment returns true on success, false on failure (for normal deletion)
        // or a WP_Error object if the comment does not exist or could not be deleted
        $result = wp_delete_comment($comment_id, true); // true = bypass trash, delete permanently

        if (is_wp_error($result)) {
            error_log(sprintf("ERROR deleting comment %d for post ID %d: %s", $comment_id, $post_id, $result->get_error_message()));
            $error_count++;
        } elseif ($result === false) {
             error_log(sprintf("FAILED to delete comment %d for post ID %d (unknown error).", $comment_id, $post_id));
             $error_count++;
        } else {
            $deleted_count++;
        }
    }

    if ($error_count > 0) {
        error_log(sprintf("Finished deleting comments for post ID %d. Deleted: %d, Failed: %d.", $post_id, $deleted_count, $error_count));
        return false; // Indicate partial or full failure
    } else {
        error_log(sprintf("Successfully deleted %d comments for post ID %d.", $deleted_count, $post_id));
        return true;
    }
}
function act_load_pages_posts_process_content($content, $credentials, &$report, $source, &$processed_images, $content_type) {
    $base_url = $credentials['site_url'];
    // Grab bits needed at end for reassembly
    $title = $content['title']['rendered'];
    $author = $content['author'];
    $type = $content['type'];
    $slug = $content['slug'];
    error_log('Processing content with slug: ' . $slug.' =========================================================');

    $featured_media = $content['featured_media'];
    if ( isset($content['categories'])){
        $categories = $content['categories'];
        error_log('Categories was ' .var_export($categories, true));
    } else {
        error_log('Categories not set ');
    }
    //var_dump($content);
    if ( isset($content['comment_status'])){
        $comment_status = $content['comment_status'];
    }
    $date = $content['date'];
    $date_gmt = $content['date_gmt'];

    if ( isset($content['excerpt']) && isset($content['excerpt']['rendered'])){
        $excerpt = $content['excerpt'];
    } else {
        $excerpt = null;
    }
    report_li($report, $slug);
    $report[] = '<ul>';
    //$dom = new DOMDocument();
    // Suppress warnings from malformed HTML
    libxml_use_internal_errors(true);

    //@$dom->loadHTML(mb_convert_encoding($content['content']['raw'],  'HTML-ENTITIES', 'UTF-8'));
    $content_raw = $content['content']['raw'];
    //error_log('Initial content_raw: '. $content_raw);
    $content_raw = act_load_pages_posts_transform_images($content_raw, $report, $processed_images, $content_type, $credentials['site_url']);
    //error_log('after images content_raw: '. $content_raw);
    $content_raw = act_load_pages_posts_transform_links($content_raw, $report);
    //error_log('after links content_raw: '. $content_raw);
    if ( $featured_media != 0 ){
        error_log(sprintf("Featured media %d", $featured_media));
        // get original featured media
        $media = get_remote_media_item( $featured_media, $base_url );
        if ( isset($media['guid']) && isset($media['guid']['rendered'])){
            $media_url = $media['guid']['rendered'];
            error_log(sprintf("Media URL %s", $media_url));
            // $featured_media = new image id     
            $featured_media = transform_and_upload_image($media_url, $report, $content_type,  $credentials['site_url']);
            error_log(sprintf("Transformed media %s", var_export($featured_media, true)));
            //report_li( $report, var_export($media, true));
            if ( isset($media['caption']) && !empty($media['caption'])){
                //report_li( $report, sprintf("caption: %s", implode(', ', array_keys($media['caption']))));
                report_li( $report, sprintf("caption: %s", $media['caption']['rendered']));
            }
            report_li( $report, sprintf("slug %s", $media['slug']));
            report_li( $report, sprintf("alt_text %s",$media['alt_text'])); 
            report_li( $report, sprintf("media_type %s", $media['media_type']));
            if ( isset( $media['description']) && count($media['description'])> 0 ){
                //report_li( $report, sprintf("Description %s", implode(', ', array_keys($media['description']))));
                report_li( $report, sprintf("description: %s", $media['description']['rendered']));
            }
        } else {
            $featured_media = 0;
        }
    }
    if ( isset($categories)){
        error_log('Transforming categories '.var_export($categories, true));
        $categories = get_new_category_ids_from_old( $categories , $source);
        error_log('Transformed ' . var_export($categories, true));
    }
    // process comments

    $report[] = '</ul>';
    $comments = process_comments($content['id'], $credentials, $report);
    $post_date = date('Y-m-d H:i:s', strtotime($date));
    $post_date_gmt = date('Y-m-d H:i:s', strtotime($date_gmt));

    error_log(sprintf("End of act_load_pages_process_content featured_media: %s", var_export($featured_media, true)));
    // Get just the body inner HTML
    //$body = $dom->getElementsByTagName('body')->item(0);
    //$innerHTML = '';
    //foreach ($body->childNodes as $child) {
    //    $innerHTML .= $dom->saveHTML($child);
    //}
    //$content = $innerHTML;
    $result = array(
        'title' => $title,
        'content' => $content_raw,
        'author' => $author,
        'type' => $type,
        'slug' => $slug,
        'excerpt' => $excerpt,
        'featured_media' => $featured_media,
        'date' => $post_date,
        'date_gmt' => $post_date_gmt,
        'comments' => $comments,
    );
    if ( isset($comment_status)){
        $result['comment_status'] = $comment_status;
    }
    if ( isset($categories)){
        $result['categories'] = $categories;
    }
    return $result;
}
function act_load_pages_posts_process() {
    // Sanitize and validate input
    $source = sanitize_text_field($_POST['source']);
    $content_type = sanitize_text_field($_POST['content_type']);
    if ( $content_type === 'team'){
        $method = 'all';
    } else {
        $method = sanitize_text_field($_POST['method']);
    }
    //$ids_list = sanitize_textarea_field($_POST['ids_list']);
    $slug = sanitize_text_field($_POST['slug']);
    //$images = sanitize_text_field($_POST['images']);
    //$links_internal = sanitize_text_field($_POST['links_internal']);
    //$links_external = sanitize_text_field($_POST['links_external']);
    $insert_posts = isset($_POST['insert_posts']) ? true : false;
    $generate_report = isset($_POST['generate_report']) ? true : false;
    $from_date = null;
    $to_date = null;
    $fromDateString = null;
    $toDateString = null;
    error_log('============================================================= starting run =======================================================');
    error_log('$content_type: '.$content_type);
    error_log('$source:       '.$source);
    error_log('slug:          '.$slug);
    if ( $content_type !== 'team'){
        if ( isset($_POST['from_date'])) {
            $fromDateString = filter_input(INPUT_POST, 'from_date', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
            try {
                error_log('from_date:     '.$fromDateString);
                $from_date = new DateTime($fromDateString);
                logit(sprintf("From_date: ", $fromDateString));
            } catch (Exception $e) {
                // Handle the case where the from_date is not a valid date format
                echo "Error: Invalid 'from date' format.";
                // Optionally, you could set $fromDate to a default value or log the error
            }
        }
        if ( isset($_POST['to_date'])) {
            $toDateString = filter_input(INPUT_POST, 'to_date', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
            try {
                error_log('to_date:     '.$toDateString);
                $to_date = new DateTime($toDateString);
                logit(sprintf("to_date: ", $toDateString));
            } catch (Exception $e) {
                // Handle the case where the from_date is not a valid date format
                echo "Error: Invalid 'to date' format.";
                // Optionally, you could set $fromDate to a default value or log the error
            }
        }
    }
    //$options = array();
    //$options['images'] = $images;
    //$options['links_internal'] = $links_internal;
    //$options['links_external'] = $links_external;

    // Set credentials
    $act_credentials = [
        'site_url' => 'https://actionclimateteignbridge.org/oldsite',
        'username' => 'apimigrator',
        'password' => 'YKbx f4mI AcY4 uBKW qFMl Fqgj',
    ];
    $ww_credentials = [
        'site_url' => 'https://ww.actionclimateteignbridge.org',
        'username' => 'apimigrator',
        'password' => 'nf1J pd8d HBHE nymU 0Z3f Nn6Z',
    ];
    $cc_credentials = [
        'site_url' => 'https://cc.actionclimateteignbridge.org',
        'username' => 'apimigrator',
        'password' => 'qpYm Nv6S 3Ti0 xXQO UBDO xB61',
    ];

//    $credentials = ($source === 'ACT') ? $act_credentials : $ww_credentials;
    if ( $source === 'ACT'){
        $credentials = $act_credentials;
    } else if ( $source === 'WW'){
        $credentials = $ww_credentials;
    } else if ( $source === 'CC'){
        $credentials = $cc_credentials;
    } else {
        $credentials = null;
    }
    error_log('site_url:     '.$credentials['site_url']);
    init_category_conversion( $credentials['site_url'] );

    $processed_content = array(); // Array to store processed content
    $report = array(); // Array to store report messages
    $processed_images = array(); // Array to track processed images

    logit(sprintf('Processing %s method: %s...', $content_type, $method));
//    set_transient('act_load_migration_progress', , 'Migration started...', 60); // Expires in 60 seconds
//    error_log('Started migration');
    $processed_count = 0;
    $total_items = 0;
    // Process based on input
    if ($method === 'all') {
        // Process all posts/pages
        $all_content = act_load_pages_posts_fetch_all_wp_rest($credentials, $content_type, $from_date, $to_date);
        $total_items = count($all_content);
    } elseif ($method === 'slug') {
        // ... fetch by slug ...
        $total_items = 1;
        logit(sprintf('Loading %s with slug: %s...', $content_type, $slug));
        $all_content = act_load_pages_posts_fetch_single_wp_rest_by_slug($credentials, $slug, $content_type);
        if ( count($all_content) == 0 ) {
            error_log(sprintf('Could not find %s with slug: %s', $content_type, $slug));
            report_li($report, sprintf('Could not find %s with slug: %s', $content_type, $slug));
        }
    }
    // 
    $total_items = count($all_content);
    error_log('total_items: '. $total_items);
    foreach ($all_content as $content) {
        logit(sprintf('Processing %s %d of %d...', $content_type, ++$processed_count, $total_items));
        $processed_content[] = act_load_pages_posts_process_content($content, $credentials, $report, $source, $processed_images, $content_type);
    }
    error_log(' ========================================================== insertion phase =========================================================');
    // Insert posts/pages (if enabled)
    if ($insert_posts) {
        foreach ($processed_content as $content) {
            $author_id = act_load_pages_posts_get_author($source, $content, $report);
            logit(sprintf('author: %s ', $author_id));
            error_log('Transformed content for: ' . $content['slug']);
            $new_post = array(
                'post_title' => $content['title'],
                'post_content' => $content['content'],
                'post_status' => 'publish',
                'post_type' => $content_type,
                'post_author' => $author_id,
                'post_date' => $content['date'],
                'post_date_gmt' => $content['date_gmt'],
                'post_name' => $content['slug']
            );
            error_log(sprintf("Insert_posts loop slug %s Featured image %s", $content['slug'], print_r($content['featured_media'], true)));
            if ( isset($content['excerpt'])){
                if ( is_array($content['excerpt'])){
                    $new_post['post_excerpt'] = $content['excerpt']['rendered'];
                } else {
                    $new_post['post_excerpt'] = $content['excerpt'];
                }
            }
            if ( isset($content['comment_status'])){
                $new_post['comment_status'] = $content['comment_status'];
            }
            // test for duplicate slug
            error_log(sprintf("DEBUG: Checking for existing post with slug %s content_type %s", $content['slug'],$content_type));
            $existing_posts = get_posts(array(
                'name' => $content['slug'],
                'post_type' => $content_type,
                'posts_per_page' => 1,
                'fields' => 'ids',
            ));
            //error_log(sprintf("DEBUG: existing posts: %s", print_r($existing_posts, true)));
            if ($existing_posts) {
                $post_id = $existing_posts[0];
                error_log(sprintf("Post with slug %s already exists (ID: %d). updating.", $content['slug'], $post_id));
                // update post
                $new_post['ID'] = $post_id;
                $post_id = wp_update_post($new_post);
                error_log(sprintf("Updated id: %d", $post_id));
            } else {
                $post_id = wp_insert_post($new_post);
                error_log(sprintf("Inserted id: %d", $post_id));
            }
            if($source === "ACT" && $content_type === "post"){
                add_post_meta($post_id, 'original_author_id', $content['author']);
            }
            //'categories' => $content['categories'], // should use wp_set_post_categories
            if (isset($content['categories'])) {
                $categories = $content['categories'];
                error_log(sprintf("DEBUG: Setting new category IDs %s for post %d", print_r($categories, true), $post_id));
                $set_result = wp_set_post_categories($post_id, $categories, false); // `false` replaces any existing categories

                if (is_wp_error($set_result)) {
                    error_log(sprintf("ERROR: Failed to set categories for post %d: %s", $post_id, $set_result->get_error_message()));
                } elseif ($set_result === false) {
                    error_log(sprintf("ERROR: wp_set_post_categories returned FALSE for post %d. Check if taxonomy is supported or post ID is valid.", $post_id));
                } else {
                    error_log(sprintf("DEBUG: Categories successfully set for post %d. Result: %s", $post_id, print_r($set_result, true)));
                }
            } else {
                error_log(sprintf("DEBUG: No valid new category IDs found for post %d (old IDs: %s).", $post_id, print_r($content['categories'], true)));
            }
            if ( isset($content['featured_media'])){
                error_log(sprintf("DEBUG: featured_media %s ", print_r($content['featured_media'], true)));
                if ( is_array($content['featured_media'])){
                    $attach_id = $content['featured_media']['attach_id'];
                } else {
                    $attach_id = $content['featured_media'];
                }
                if ( $attach_id > 0){
                    $upload_info = wp_upload_dir();
                    error_log('*** DEBUG: wp_upload_dir() output:');
                    error_log(var_export($upload_info, true));
                    $ret = set_post_thumbnail($post_id, $attach_id);
                    if ( $ret === false){
                        error_log(sprintf("ERROR: Failed to set featured image for post %d with attachment ID %d", $post_id, $attach_id));
                        if ( get_post( $attach_id ) ) {
                            if ( wp_get_attachment_image( $attach_id, 'thumbnail' ) ) {
                                error_log(sprintf("DEBUG: Set featured image %d for post %d", $attach_id, $post_id));
                            } else {
                                error_log(sprintf("ERROR: Attachment ID %d is not a valid image.", $attach_id));
                                $image = wp_get_attachment_image_src( $attach_id, 'thumbnail', false );
                                if ( $image ) {
                                    error_log(sprintf("DEBUG: Attachment ID %d has a valid image URL: %s", $attach_id, $image[0]));
                                } else {
                                    error_log(sprintf("ERROR: Attachment ID %d does not have a valid image URL.", $attach_id));
                                }
                                $image_html = wp_get_attachment_image($attach_id, 'full');
                                if (empty($image_html)) {
                                    echo "wp_get_attachment_image failed for ID " . $attach_id;
                                } else {
                                    echo "wp_get_attachment_image succeeded: " . $image_html;
                                }
                            }
                        } else {
                            error_log(sprintf("ERROR: Attachment ID %d does not exist.", $attach_id));
                        }
                    } else {
                        error_log(sprintf("set_post_thumbnail returned %s", print_r($ret, true)));
                        error_log(sprintf("DEBUG: Set featured image %d for post %d", $attach_id, $post_id));
                    }
                } else {
                    error_log(sprintf("DEBUG: No valid attachment ID found for featured media of post %d.", $post_id));
                }
            } else {
                error_log(sprintf("DEBUG: No featured media to set for post %d.", $post_id));
            }
            // update comments
            delete_post_comments( $post_id, $report ); // Delete existing comments for the post before inserting new ones
            $comments = $content['comments'];
            $comment_id_map = array(); // Map to track old comment IDs to new comment IDs
            foreach($comments as $comment){
                $comment_data = array(
                    'comment_post_ID' => $post_id,
                    'comment_author' => $comment['comment_author'],
                    'comment_author_email' => $comment['comment_author_email'],
                    'comment_author_url' => $comment['comment_author_url'],
                    'comment_content' => $comment['comment_content'],
                    'user_id' => $comment['user_id'],
                    'comment_date' => $comment['comment_date'],
                    'comment_date_gmt' => $comment['comment_date_gmt'],
                    'comment_approved' => $comment['comment_approved'],
                    'comment_type' => $comment['comment_type'],
                );
                // Insert the comment
                if ( !isset($comment['old_parent_id']) || $comment['old_parent_id'] == 0 ){
                    $comment_id = wp_insert_comment($comment_data);
                    $comment_id_map[$comment['old_comment_id']] = $comment_id; // Map old comment ID to new comment ID
                } else {
                    // If the comment has a parent, we need to find the new parent ID
                    $parent_id = isset($comment_id_map[$comment['old_parent_id']]) ? $comment_id_map[$comment['old_parent_id']] : 0;
                    $comment_data['comment_parent'] = $parent_id;
                    $comment_id = wp_insert_comment($comment_data);
                    $comment_id_map[$comment['old_comment_id']] = $comment_id; // Map old comment ID to new comment ID
                }
            }
        }
    }
    // ... transformations and insertions ...

    set_transient('act_load_migration_progress', sprintf('Migration completed! %d pages processed', $processed_count), 60);
    // Redirect back to the admin page with a message
    wp_redirect(admin_url('admin.php?page=act-load-pages-posts&message=migration_completed'));
    // Generate report (if enabled)
    act_load_pages_posts_generate_report($report);
    exit;
}
add_action('admin_post_act_load_pages_posts_process', 'act_load_pages_posts_process');

?>