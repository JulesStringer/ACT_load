jQuery(document).ready(function($) {
    // inittialise variables
    let migrate_button = $('#migrate-button');
    let status_div = $('#migration-status');
    // get localised varibles from PHP
    console.log('vm_migrate_data: ', vm_migrate_data);
    let rest_url_base =      vm_migrate_data.rest_url_base;
    console.log|('rest_url_base: ' + rest_url_base);
    let wp_rest_nonce =      vm_migrate_data.wp_rest_nonce;
    let remote_credentials = vm_migrate_data.remote_credentials;
    console.log('Remote credentials:', remote_credentials);
    let is_migrating = false;
    $('#migrate-button').on('click', async function() {
        if (is_migrating) {
            return; // Prevent multiple clicks
        }
        is_migrating = true;
        migrate_button.prop('disabled', true);
        status_div.html('<p>Migration started...</p>');
        // Start migration process
        // get act-documents-list from remote site specified in remote_credentials rather than rest_base_url
        // Use fetch to get the page with slug 'act-document-list' and context=edit for content.raw
        // Check the act-document-list is not yet present
        // Main migration function
        let list_page = await get_old_list_page();
        if (!list_page ){
            status_div.append('<p>No page found with slug act-document-list</p>');
            is_migrating = false;
            migrate_button.prop('disabled', false);
            return;
        }
        if ( list_page.content.raw){
            let div = document.createElement('div');
            div.innerHTML = list_page.content.raw;
            let docs = div.getElementsByTagName('li');
            // process each link under li
            for (let i = 0; i < docs.length; i++) {
                let a = docs[i].getElementsByTagName('a')[0];
                if (a && a.href) {
                    let t = '<table>';
                    t += '<tr><td>Document:</td><td>' + a.text + '</td></tr>';
                    t += '<tr><td>Link:</td><td>' + a.href + '</td></tr>';
                    t += '</table>';
                    status_div.append(t);
                    // Move the media item and get new URL
                    let new_url = await update_media_item(a.href);
                    status_div.append('<p>New URL: ' + new_url + '</p>');
                    // change href to new_url
                    a.setAttribute('href', new_url); // store href
                    // This is sufficient: modifying the DOM updates the element's href.
                    // Later, when you use div.innerHTML, it will include the updated hrefs.
                }
            }
            // Now div.innerHTML contains the updated content with new URLs   
            let new_content = div.innerHTML;
            let newpage = {
                title: list_page.title.raw,
                content: new_content,
                status: 'publish',
                slug: list_page.slug
            };
            console.log('New page data to insert/update:', newpage);
            await insert_page(newpage);    
            is_migrating = false;

            // If all ok update content.raw
            // Insert list_page in local site if not already there
            // otherwise update it. 
        } else {
            status_div.append('<p>No content.raw found.</p>');
        }
    });
    async function get_old_list_page() {
        let url = `${remote_credentials.site_url}/wp-json/wp/v2/pages?slug=act-document-list&context=edit`;
        console.log('Fetching from URL:', url);
        try {
            let response = await fetch(url, {
                method: 'GET',
                headers: {
                    'Authorization': 'Basic ' + btoa(remote_credentials.username + ':' + remote_credentials.password),
                    'Content-Type': 'application/json'
                }
            });
            if (!response.ok) {
                throw new Error('Network response was not ok');
            }
            let json = await response.json();
            console.log('Response JSON:', json);
            // The API returns an array of pages, so return the first one if present
            return json.length > 0 ? json[0] : null;
        } catch (error) {
            status_div.append('<p>Error fetching page: ' + error.message + '</p>');
            return null;
        } finally {
            migrate_button.prop('disabled', false);
        }
    }
    async function insert_page(newpage){
        try{
            console.log('rest_url_base: ', rest_url_base);
            let url = rest_url_base + 'wp/v2/pages';
            console.log('Inserting page at URL:', url);
            const response = await fetch(url, {
                method: 'POST', 
                headers: {
                    'Content-Type': 'application/json',
                    // Include the nonce in the X-WP-Nonce header
                    'X-WP-Nonce': wp_rest_nonce
                },
                body: JSON.stringify(newpage)
            });
            if (!response.ok) {
                const errorData = await response.json();
                throw new Error(`HTTP error! Status: ${response.status}, Code: ${errorData.code}`);
            }
            const insertedPage = await response.json();
            console.log('Page inserted successfully:', insertedPage);
            status_div.append('<p>Page "' + insertedPage.title.rendered + '" inserted successfully with ID ' + insertedPage.id + '.</p>');
            return insertedPage;
        }catch(error){
            console.error('Error inserting page:', error);
        }
    }
});