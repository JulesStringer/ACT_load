jQuery(document).ready(function($) {
    $('#act-load-form').submit(function(event) {
        jQuery(document).ready(function($) {
            $('#act-load-form').submit(function(event) {
                event.preventDefault();
                $('#act-load-results').html('Uploading...');

                const baseUrl = $('#base_url').val();
                const slug = $('#slug').val();
                const suffix = $('#suffix').val();
                const imageHandling = $('#image_handling').val();
                const linkHandling = $('#link_handling').val();

                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'act_load_upload',
                        base_url: baseUrl,
                        slug: slug,
                        suffix: suffix,
                        image_handling: imageHandling,
                        link_handling: linkHandling,
                        nonce: $('#act_load_nonce').val()
                    },
                    success: function(response) {
                        $('#act-load-results').html(response.data.message);
                        if (response.success && response.data.postId) {
                            console.log("Post ID:", response.data.postId);
                        }
                    },
                    error: function(error) {
                        console.error("AJAX Error:", error);
                        $('#act-load-results').html("An error occurred during upload.");
                    }
                });
            });
        });
    });
});