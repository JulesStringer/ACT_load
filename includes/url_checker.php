<?php
/**
 * Checks if a given URL is accessible.
 *
 * This function uses cURL to make a lightweight request to the URL. It only
 * checks the headers and does not download the full content, making it
 * very efficient.
 *
 * @param string $url The URL to check.
 * @return array An array containing a 'status' boolean and the HTTP status code.
 * Returns an error status if cURL fails.
 */
function is_url_accessible($url) {
    if (empty($url) || !filter_var($url, FILTER_VALIDATE_URL)) {
        return [
            'status' => false,
            'code'   => 0
        ];
    }

    $ch = curl_init($url);
    
    // Set cURL options for an efficient HEAD request
    curl_setopt($ch, CURLOPT_NOBODY, true); // Do not download the body
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); // Return the transfer as a string
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true); // Follow redirects
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10); // Timeout for connection in seconds
    curl_setopt($ch, CURLOPT_TIMEOUT, 15); // Total timeout for transfer in seconds
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // Recommended for local dev, but be cautious in production

    $result = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    // Check for cURL errors
    if (curl_errno($ch)) {
        return [
            'status' => false,
            'code'   => curl_errno($ch)
        ];
    }
    
    curl_close($ch);

    // Consider 200 (OK) and 3xx (redirects) as accessible.
    $is_accessible = ($http_code >= 200 && $http_code < 400);

    return [
        'status' => $is_accessible,
        'code'   => $http_code
    ];
}
// This hook listens for AJAX requests from logged-in users.
add_action('wp_ajax_check_url_accessibility', 'handle_url_accessibility_check');

/**
 * Handles the AJAX request to check a URL's accessibility.
 */
function handle_url_accessibility_check() {
    // Security check: Verify the nonce to ensure the request is legitimate.
    if ( !isset($_POST['security']) || !wp_verify_nonce($_POST['security'], 'url_check_nonce') ) {
        wp_send_json_error('Security check failed.');
    }

    // Ensure the required 'url' parameter is present.
    if (!isset($_POST['url']) || empty($_POST['url'])) {
        wp_send_json_error('Missing required parameter: url');
    }

    $url = sanitize_url($_POST['url']);

    // Call the PHP function to check the URL.
    $check_result = is_url_accessible($url);

    // Send the JSON response back to the JavaScript function.
    if ($check_result['status']) {
        wp_send_json_success($check_result);
    } else {
        wp_send_json_error($check_result);
    }
}
?>
