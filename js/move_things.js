let move_media_nonce = move_things_params.move_media_nonce;
let move_image_nonce = move_things_params.move_image_nonce;
let ajax_url         = move_things_params.ajax_url;
    /**
     * Asynchronously calls the PHP function to update a media item.
     *
     * @param {string} href The URL of the media item on the remote site.
     * @returns {Promise<string>} A promise that resolves to the new URL on the current site.
     */
    async function update_media_item(href) {
        try {
            // Create form data to send to the AJAX endpoint.
            const formData = new FormData();
            formData.append('action', 'move_media_item');
            formData.append('href', href);

            // Retrieve the security nonce from the localized data.
            formData.append('security', move_media_nonce);

            // Send a POST request to the WordPress AJAX endpoint.
            const response = await fetch(ajax_url, {
                method: 'POST',
                body: formData,
            });

            // Check if the request was successful.
            if (!response.ok) {
                throw new Error(`HTTP error! Status: ${response.status}`);
            }

            const result = await response.json();

            // Check if the PHP function returned an error.
            console.log('result: ' + JSON.stringify(result));
            if (!result.success) {
                throw new Error(result.data || 'PHP function returned an error.');
            }
            // Return the new URL from the successful response data.
            console.log('new_url: ' + result.data.new_url);
            return result.data.new_url;

        } catch (error) {
            console.error('Error in update_media_item:', error);
            // Re-throw the error so the calling function can handle it.
            throw error;
        }
    }
    /**
     * Asynchronously calls the PHP function to update a media item.
     *
     * @param {string} href The URL of the media item on the remote site.
     * @returns {Promise<string>} A promise that resolves to the new URL on the current site.
     */
    async function update_image_item(src) {
        try {
            // Create form data to send to the AJAX endpoint.
            let post_type = $('#post_type').val();
            const formData = new FormData();
            formData.append('action', 'move_image_item');
            formData.append('src', src);

            // Retrieve the security nonce from the localized data.
            formData.append('security', move_image_nonce);
            formData.append('post_type', post_type);

            // Send a POST request to the WordPress AJAX endpoint.
            const response = await fetch(majax_url, {
                method: 'POST',
                body: formData,
            });

            // Check if the request was successful.
            if (!response.ok) {
                throw new Error(`HTTP error! Status: ${response.status}`);
            }

            const result = await response.json();

            // Check if the PHP function returned an error.
            console.log('result: ' + JSON.stringify(result));
            if (!result.success) {
                throw new Error(result || 'PHP function returned an error.');
            }
            // Return the new URL from the successful response data.
            console.log('new_url: ' + result.data.new_url);
            return result.data.new_url;

        } catch (error) {
            console.error('Error in update_image_item:', error);
            // Re-throw the error so the calling function can handle it.
            throw error;
        }
    }
// You must explicitly make the functions available globally.
// This is the most crucial part.
//window.update_media_item = update_media_item;
//window.update_image_item = update_image_item;