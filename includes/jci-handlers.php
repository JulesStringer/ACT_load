<?php
// Functions for reading JSON, uploading content, handling images, etc.
// In includes/jci-handlers.php (or a separate utility file if you prefer):

function jci_find_author($data) {
    $author_id = null;

    // 1. Try matching by display name
    if (isset($data['uagb_author_info']['display_name'])) {
        $display_name = $data['uagb_author_info']['display_name'];
        $wp_user = get_user_by('login', $display_name); // Check for exact username match first

        if (!$wp_user) {
            $wp_user = get_user_by('email', $display_name); // Then email match
        }

        if (!$wp_user) {
            $wp_user = get_user_by('name', $display_name); // Finally display name match
        }

        if ($wp_user) {
            $author_id = $wp_user->ID;
        }
    }

    // 2. If no match by display name, try matching by URL component
    if (!$author_id && isset($data['uagb_author_info']['author_link'])) {
        $author_link = $data['uagb_author_info']['author_link'];
        $url_parts = explode('/', $author_link);
        $username_part = end($url_parts); // Get the last part of the URL

        $wp_user = get_user_by('login', $username_part); // Try username match

        if (!$wp_user) {
            $wp_user = get_user_by('email', $username_part); // Try email match
        }

        if ($wp_user) {
            $author_id = $wp_user->ID;
        }
    }

    // 3. If still no match, try partial username matching (iterative)
    if (!$author_id && isset($data['uagb_author_info']['display_name'])) {
        $display_name = $data['uagb_author_info']['display_name'];
        $username_parts = explode(' ', $display_name); // Split into words

        foreach ($username_parts as $part) {
            $part = strtolower(trim($part)); // Normalize case and remove whitespace
            if (strlen($part) >= 3) { // Only try partial matches of 3 or more characters.
                $users = get_users(array('search' => '*' . $part . '*')); // Search for partial username match

                if (!empty($users)) {
                    foreach ($users as $user) {
                        if (strtolower($user->user_login) === $part) { // Full username match
                            $author_id = $user->ID;
                            break 2; // Break out of both loops
                        } else if (strpos(strtolower($user->user_login), $part) !== false) { // Partial username match
                            $author_id = $user->ID;
                            break 2; // Break out of both loops
                        }
                    }
                }
            }
        }
    }

    // 4. Fallback: If no match is found, assign to the current user
    if (!$author_id) {
        $author_id = get_current_user_id();
        error_log("Author not found. Assigning to current user.");
    }

    return $author_id;
}
function remove_metaslider_html($html) {
    // Regular expression to find the metaslider HTML (adapt as needed)
    $pattern = '/<div.*?class=".*?metaslider.*?".*?>(.*?)<\/div>/s'; // Matches the entire metaslider div

    $replacement = '<div class="notice notice-warning"><p>MetaSlider content was removed during import. Please add a new MetaSlider shortcode here.</p></div>';

    return preg_replace($pattern, $replacement, $html);
}
/*
function replace_newsite_links($content) {
    $new_site_url = home_url();

    return preg_replace_callback(
        '/(https?:\/\/actionclimateteignbridge\.org\/(newsite)\/(page\.php\/)?(page|post)\/([^\s\|]+))/i', // Corrected and simplified regex
        function ($matches) use ($new_site_url) {
            $slug = urldecode($matches[5]); // Get the slug (always $matches[5] now)
            return $new_site_url . '/index.php/' . $slug;
        },
        $content
    );
}
*/
function replace_newsite_links($content) {
    $new_site_url = home_url();
    $target_url = $new_site_url . '/index.php/';
   // https://actionclimateteignbridge.org/newsite/page.php/page/cc|about-the-cc-project
    $replacements = array(
        'https://actionclimateteignbridge.org/newsite/page.php/page/' => $target_url,
        'https://actionclimateteignbridge.org/newsite/page.php/post/' => $target_url,
        'href="https://cc.actionclimateteignbridge.org/wordpress' => 'href=' . $target_url,
        'cc%7C' => '', // Remove the encoded characters
        'cc|' => '', // Remove the unencoded characters
    );

    $content = str_replace(array_keys($replacements), array_values($replacements), $content);

    return $content;
}
function process_images($content) {
    $new_site_url = home_url();
    $dom = new DOMDocument();

    libxml_use_internal_errors(true); // Enable internal error handling

    $dom->loadHTML('<?xml encoding="UTF-8"?>' . $content, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);

    $errors = libxml_get_errors();
    if (!empty($errors)) {
        $error_message = "<div class='notice notice-error'><h4>HTML Parsing Errors:</h4><ul>";
        foreach ($errors as $error) {
            $error_message .= "<li>" . $error->message . "</li>";
        }
        $error_message .= "</ul></div>";

        // Prepend the error messages to the content
        $content = $error_message . $content; // Or append, depending on preference
    }

    libxml_clear_errors(); // Clear the errors
    libxml_use_internal_errors(false); // Disable internal error handling

    $images = $dom->getElementsByTagName('img');
    foreach ($images as $image) {
        $src = $image->getAttribute('src');

        if (strpos($src, $new_site_url) === false) {
            $new_src = convert_image($src, $dom, $image);
        }
    }

    return $dom->saveHTML();
}
function convert_image($src, $dom, $image) {
    $new_site_url = home_url(); // For checking if image is already local

    // 1. Fetch the image
    $response = wp_remote_get($src);

    if (is_wp_error($response)) {
        $error_message = "Error fetching image: " . $response->get_error_message();
        display_error_in_content($dom, $image, $error_message, $src); // Pass $src
        return false;
    }

    $image_data = wp_remote_retrieve_body($response);
    $filename = basename($src);
    $upload = wp_upload_bits($filename, null, $image_data);

    if ($upload['error']) {
        $error_message = "Error uploading image: " . $upload['error'];
        display_error_in_content($dom, $image, $error_message, $src); // Pass $src
        return false;
    }

    if ($upload['file'] === null || empty($upload['file'])) { // Check for null or empty file path
        $error_message = "Error: Uploaded file path is empty.";
        display_error_in_content($dom, $image, $error_message, $src); // Pass $src
        return false;
    }

    $image_info = @getimagesize($upload['file']); // Suppress the warning

    if ($image_info === false) {
        $error_message = "Error getting image information.  <a href='" . esc_url($src) . "' target='_blank'>Original Image</a>"; // Add link
        display_error_in_content($dom, $image, $error_message, $src); // Pass $src
        return false;
    }

    $mime_type = $image_info['mime']; // Get MIME type from getimagesize()
    $width = $image_info[0];
    $height = $image_info[1];

    // Check image size (placeholder for resizing)
    if ($image_info['bits'] * $width * $height / 8 > 500 * 1024 * 1024) { //Check size in bytes
        $upload['file'] = resize_image($upload['file'], $width, $height); // Placeholder function (see below)
        if ($upload['file'] === false) {
            $error_message = "Error resizing image.";
            display_error_in_content($dom, $image, $error_message, $src); // Pass $src
            return false;
        }

        $image_info = getimagesize($upload['file']); // Get image size again after resize
        $mime_type = $image_info['mime']; // Update mime type
    }
    // Insert into media library (Corrected MIME type handling)
    $attachment = array(
        'post_mime_type' => $mime_type, // Use MIME type from getimagesize()
        'post_title' => sanitize_text_field(basename($filename, "." . pathinfo($filename, PATHINFO_EXTENSION))),
        'post_content' => '',
        'post_status' => 'inherit'
    );
    $attach_id = wp_insert_attachment( $attachment, $upload['file'] );
    require_once(ABSPATH . 'wp-admin/includes/image.php');
    wp_generate_attachment_metadata( $attach_id, $upload['file'] );

    return wp_get_attachment_url($attach_id);
}

// Placeholder resize_image function (as before)
function resize_image($file, $width, $height) {
    return $file; // Just return the original file for now
}
function display_error_in_content($dom, $image, $error_message, $original_src) {
    $error_element = $dom->createElement('div');
    $error_element->setAttribute('class', 'notice notice-error');

    // Make the error message more user-friendly
    $error_message_html = "<p>" . $error_message . "</p>";

    // Create a new document fragment
    $fragment = $dom->createDocumentFragment();

    // Load the HTML into the fragment
    $fragment->appendXML($error_message_html);

    // Append the fragment to the error element
    $error_element->appendChild($fragment);

    $image->parentNode->replaceChild($error_element, $image);
}
function jci_upload_content($data, $type = 'page', $image_handling = 'none', $link_handling = 'none') {
    
    $slug = sanitize_title(isset($data['title']['rendered']) ? $data['title']['rendered'] : (isset($data['title']) ? $data['title'] : 'No Title')); // Sanitize the title to get the slug

    // Check for duplicate slug
    // 1. Check for duplicate slug using WP_Query
    $args = array(
        'name'        => $slug, // Check by slug
        'post_type'   => $type, // Check for the specific post type
        'post_status' => 'any', // Include all post statuses (publish, draft, etc.)
        'posts_per_page' => 1, // Only need to find one
    );
    $query = new WP_Query($args);

    if ($query->have_posts()) {
        $existing_post = $query->posts[0]; // Get the existing post object

        // 2. Prompt user about overwriting (using JavaScript confirm)
        echo '<script>
            var overwrite = confirm("A ' . $type . ' with the slug \'' . $slug . '\' already exists. Do you want to overwrite it?");
            if (overwrite) {
                // Set a hidden input field to indicate overwrite
                jQuery("<input type=\'hidden\' name=\'jci_overwrite\' value=\'' . $existing_post->ID . '\'>").appendTo("form");
            } else {
                return false; // Stop the import if user cancels
            }
        </script>';

        // If the user confirmed, the 'jci_overwrite' hidden input will be present in $_POST

    }
    $content = $data['content']['rendered'] ?? $data['content'] ?? '';
    $content = remove_metaslider_html( $content );
    $content = replace_newsite_links( $content );
    $content = process_images( $content );
    $author_id = jci_find_author($data);

    $post_data = array(
        'post_title'    => isset($data['title']['rendered']) ? $data['title']['rendered'] : (isset($data['title']) ? $data['title'] : 'No Title'),
        'post_content' => $content,
        'post_content_filtered' => $content,
        'post_author' => $author_id, // Include the author here
        'post_excerpt' => isset($data['excerpt']['rendered']) ? $data['excerpt']['rendered'] : (isset($data['excerpt']) ? $data['excerpt'] : ''),
        'post_name' => isset($data['slug']) ? $data['slug'] : sanitize_title($data['title']['rendered'] ?? $data['title'] ?? 'no-title'), 
        'post_status'   => isset($data['status']) ? $data['status'] : 'publish',
        'post_type' => $type,
        'post_date' => isset($data['date']) ? $data['date'] : null, // Set date
        'post_date_gmt' => isset($data['date_gmt']) ? $data['date_gmt'] : null,
        'post_modified' => isset($data['modified']) ? $data['modified'] : null,
        'post_modified_gmt' => isset($data['modified_gmt']) ? $data['modified_gmt'] : null,
        'comment_status' => isset($data['comment_status']) ? $data['comment_status'] : null,
        'ping_status' => isset($data['ping_status']) ? $data['ping_status'] : null,
    );

    if (isset($_POST['jci_overwrite'])) { // Check if we are overwriting
        $post_data['ID'] = $_POST['jci_overwrite']; // Set the ID for wp_update_post
        $post_id = wp_update_post($post_data);
    } else {
        $post_id = wp_insert_post($post_data);
    }

    if ($post_id) {
        //switch_to_block_editor($post_id); // Convert the post to use the block editor

        // Optionally, you can set the content format to 'block'
        //wp_update_post(array(
        //    'ID' => $post_id,
        //    'post_content_filtered' => $data['content'], // Update the post content to ensure it is available for blocks
        //    'post_content' => $data['content'] // Update the post content to ensure it is available for blocks
        //));
        // Handle featured media
        if (isset($data['featured_media']) && $data['featured_media'] !== 0) { // Check if featured media ID is present
            $media_id = jci_handle_image($data['featured_media'], $image_handling); // See function below
            if ($media_id) {
                set_post_thumbnail($post_id, $media_id);
            }
        }

        // Handle meta data
        if (isset($data['meta'])) {
            foreach ($data['meta'] as $key => $value) {
                update_post_meta($post_id, $key, $value);
            }
        }

        // Handle custom fields (ACF or others)
        if (isset($data['acf'])) {
            foreach ($data['acf'] as $key => $value) {
                update_field($key, $value, $post_id); // Use ACF's update_field function.  Adjust for your custom fields.
            }
        }

        // Handle author (if uagb_author_info is available)
        if (isset($data['uagb_author_info']) && isset($data['uagb_author_info']['user_id'])) {
            $author_id = $data['uagb_author_info']['user_id'];
            wp_update_post(array('ID' => $post_id, 'post_author' => $author_id));
        } else if (isset($data['author'])) {
            wp_update_post(array('ID' => $post_id, 'post_author' => $data['author']));
        }


        // ... (link handling - see below)

        return $post_id;
    } else {
        return false;
    }
}

//function jci_upload_content($data, $type = 'page', $image_handling = 'none', $link_handling = 'none') {
    // ... (Your existing function)
//}

function jci_handle_image($attachment_id, $image_handling) {
    if ($image_handling === 'none') {
        return $attachment_id; // Just use the original ID (if it exists on the new site)
    }

    // Get the image URL from the source site (you'll need a way to do this)
    $source_image_url = get_the_guid($attachment_id); // Example: Get URL from GUID

    if (!$source_image_url) {
        return false; // Or handle the error as you see fit
    }

    $filename = basename($source_image_url);
    $upload = wp_upload_bits($filename, null, file_get_contents($source_image_url));

    if ($upload['error']) {
        return false; // Handle error
    }

    $attachment = array(
        'post_mime_type' => 'image/jpeg', // Adjust if needed
        'post_title' => preg_replace('/\.[^.]+$/', '', $filename),
        'post_content' => '',
        'post_status' => 'inherit'
    );

    $attach_id = wp_insert_attachment( $attachment, $upload['file'] );
    require_once(ABSPATH . 'wp-admin/includes/image.php');
    $attach_data = wp_generate_attachment_metadata( $attach_id, $upload['file'] );
    wp_update_attachment_metadata( $attach_id, $attach_data );

    return $attach_id;
}


function act_convert_upload_callback() { // The AJAX callback
    check_ajax_referer('act_convert_nonce', 'nonce');

    // ... (All the code from your previous act_load_upload_callback function goes here)
}

// ... (Other JCI-related functions)
function jci_handle_upload_ajax() {
    // ...
    $image_handling = $_POST['image_handling']; // Get image handling option
    $link_handling = $_POST['link_handling']; // Get link handling option
    $post_id = jci_upload_content($data, $type, $image_handling, $link_handling);
    // ...
}
/*
function jci_download_content_by_slug($slug, $type = 'page') {
    // ... (previous code)
    $data = array(
        'date' => get_the_date('c', $post), // ISO 8601 format
        'date_gmt' => get_gmt_from_date(get_the_date('Y-m-d H:i:s', $post)),
        'modified' => get_the_modified_date('c', $post),
        'modified_gmt' => get_gmt_from_date(get_the_modified_date('Y-m-d H:i:s', $post)),
        'slug' => $post->post_name,
        'status' => $post->post_status,
        'type' => $post->post_type,
        'title' => get_the_title($post),
        'content' => $post->post_content,
        'excerpt' => get_the_excerpt($post),
        'comment_status' => $post->comment_status,
        'ping_status' => $post->ping_status,
        'author' => $post->post_author,
        'featured_media' => get_post_thumbnail_id($post), // Get featured media ID
        'meta' => get_post_meta($post->ID), // Get all meta data
    // ... other fields
    );

    // ... (rest of the function)
}
*/
?>
