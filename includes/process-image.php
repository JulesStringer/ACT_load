<?php
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
function set_media_category($attach_id, $category_name){
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
            error_log(sprintf("Image %d categorized successfully as %s",$attach_id, $category_name));
        } else {
            error_log(sprintf("Faiied to assign %d a category", $attach_id));
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

    // Check for base file
    $filepath = $upload_dir.'/'.$name.'.'.$ext;
    if (file_exists($filepath)) {
        error_log($name.' existed so deleted');
        unlink($filepath);
    } else {
        error_log('File does NOT EXIST '.$filepath);
    }
    // Glob all variants with WxH suffix
    $pattern = sprintf('%s/%s-*x*.%s', $upload_dir, $name, $ext);
    $files   = glob( $pattern );

    foreach ( $files as $file ) {
        // Match only "exact-name-WxH.ext"
        $regex = sprintf('/^%s-\d+x\d+\.%s$/i', preg_quote($name, '/'), preg_quote($ext, '/'));
        $basename = basename($file);
        if (preg_match($regex, $basename)) {
            error_log("Deleting orphaned file: $file");
            @unlink( $file );
        }
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
function transform_and_upload_image($src, $content_type, $site_url){
    //error_log('transform_and_upload_image $content_type '.$content_type. ' site_url: '.$site_url);
    // Download image
    $attach_id = 0;
    $a = explode('?', $src);
    $src = $a[0];

    if ($site_url != null && !str_starts_with($src, $site_url)){
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
    // This gets round wp_unique_filename suffixing any name containing scaled or rotated with -1
    $search_filename = str_replace('scaled','scaaled',$search_filename);
    $search_filename = str_replace('rotated','rotaated',$search_filename);
    // test if image has already been uploaded by WordPress's default slug
    $result = search_for( $search_filename );
    $pattern = '/-\d+x\d+(?=-[a-z0-9]+$)/';
    if ( $result === null && preg_match($pattern, $search_filename) > 0){
        $search_filename = preg_replace($pattern, '', $search_filename);
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
            error_log("Error downloading image: " . esc_html($image_url));
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
                error_log(('$upload_file '.var_export($upload_file, true)));
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
                        if ( $content_type != null && $content_type === 'team' ){
                            set_media_category($attach_id, 'Team images');
                        } else {
                            set_media_category($attach_id, 'Post images');
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
function transform_image($img, $content_type, $site_url){
    //error_log('transform_image $content_type '.$content_type. ' site_url: '.$site_url);
    $src = $img->getAttribute('src');
    $alt = $img->getAttribute('alt');
    // List image
    error_log("Image found: src=" . esc_html($src) . ", alt=" . esc_html($alt));

    // Download image
    $upload = transform_and_upload_image($src, $content_type, $site_url);
    if ( !isset($upload['attach_id'])){
        error_log(sprintf("Error uploading image %s", $src));
        //var_dump($upload);
    } else {
        $attach_id = $upload['attach_id'];
    }
    if ( isset($upload['src'])){
        $img->setAttribute('src', $upload['src']);
    }
}
/**
 * Handles the AJAX request to move and update a media item.
 *
 * This function is the bridge between the JavaScript call and your
 * existing PHP function `moveupdate()`.
 */
function handle_move_image_item() {
    // Security check: Verify the nonce to ensure the request is legitimate.
    check_ajax_referer('move_image_nonce', 'security');

    // Ensure the required 'href' parameter is present in the request.
    if (!isset($_POST['src'])) {
        wp_send_json_error([
            'status' => 400,
            'message' => 'Missing required parameter: href',
            'original_url' => null,
            'new_url' => null,
        ]);
    }

    $src = sanitize_url($_POST['src']);

    // Call your existing PHP function. Assuming it's in a separate file
    // that is already included in your plugin.
    // Replace 'moveupdate' with the actual function name if it's different.
    $result = [
        'status' => 501, 
        'message' => 'transform image call not yet implemented',
        'original_url' => $src,
    ];
    $upload = transform_and_upload_image($src, null, null);
    if ( !isset($upload['attach_id'])){
        $result['status'] = 500;
        $result['message'] = 'Unable to get attachment id.';
    } else {
        $attach_id = $upload['attach_id'];
    }
    if ( isset($upload['src'])){
        $result['status'] = 200;
        $result['message'] = 'Transformed and acquired image.';
        $result['new_url'] =  $upload['src'];
    }

    // Send a JSON response back to the JavaScript function.
    // The 'data' key will contain the new URL.
    if ($result['status'] === 200) {
        wp_send_json_success($result);
    } else {
        wp_send_json_error($result);
    }
}
// This hook listens for AJAX requests from logged-in users with the 'move_media_item' action.
add_action('wp_ajax_move_image_item', 'handle_move_image_item');
?>
