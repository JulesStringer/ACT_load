jQuery(document).ready(function($) {
    // initialise
    console.log('Initialising document');
    let rest_url = check_pages_data.rest_url;
    let home_url = check_pages_data.home_url;
    let home_url_un = home_url.replace('https','http');
    let nonce    = check_pages_data.nonce;
    let move_media_nonce = check_pages_data.move_media_nonce;
    let move_image_nonce = check_pages_data.move_image_nonce;
    let recipients_csv = check_pages_data.recipients_csv;
    let url_check_nonce = check_pages_data.url_check_nonce;
    let ajax_url = check_pages_data.ajax_url;
    console.log('rest_url: ' + rest_url);
    $('#home_url').text(home_url);
    $('#home_url_un').text(home_url_un);
    console.log('nonce: ' + nonce);
    console.log('move_media_nonce: ' + move_media_nonce);
    console.log('move_image_nonce: ' + move_image_nonce);
    console.log('recipients_csv: ' + recipients_csv);
    console.log('url_check_nonce:' + url_check_nonce);
    function parse_recipients_csv(csvdata) {  // Function name lowercase
        const lines = csvdata.split('\n'); // Variable name lowercase
        const headers = lines[0].split(',');
        const data = [];
        for (let i = 1; i < lines.length; i++) {
            let line = lines[i];
            const values = [];
            let field = '';
            for(j=0; j < line.length; j++){
                if ( line[j] === '"' && field.length == 0){
                    j++;
                    while(line[j] !== '"' && j < line.length){
                        field += line[j];
                        j++;
                    }
                } else if ( line[j] === ',' ){
                    values.push(field);
                    field = '';
                } else if ( line[j] >= ' ' ){
                    field += line[j];
                }
            }
            if ( field.length > 0){
                values.push(field);
            }
            if ( values.length > 0){
                let ok = false;
                for(let value of values){
                    if ( value.length > 0){
                        ok = true;
                    }
                }
                if ( ok ){
                    const row = {
                        name: values[0],
                        email: values[1]
                    };
                    data.push(row);
                }
            }
        }
        return data;
    }
    let recipients = parse_recipients_csv(recipients_csv);
    console.log(JSON.stringify(recipients));
    function lookup_email(name){
        let result = null;
        if ( name === 'paulwynter')result = 'Act with the Arts';
        if ( name === 'peta')result = 'Carbon Cutters';
        if ( name === 'flavio')result = 'Wildlife Warden Scheme';
        if ( name === 'fuad')result = 'Energy and Built Environment';
        if ( name === 'scott') result = 'Carbon Cutters';
        if ( name === 'technical') result = 'Website';
        if ( name === 'rob') result = 'Carbon Cutters';
        if ( name === 'paulbloch' ) result = 'Carbon Cutters';
        if ( name === 'dpo') result = 'Other';
        if ( result === null){
            for(const recipient of recipients){
                let parts = recipient.email.split(';');
                for( const part of parts){
                    if ( name === part.split('@')[0] ){
                        result = recipient.name;
                        break;
                    }
                }
            }
        }
        return result;
    }
    let prompts = 0;
    let actions = {};
    let emails = {};
    let newsite = {};
    let pdfs = {};
    let docs = {};
    let images = {};
    let maps = {};
    let other = {};
    let media_moves = {};
    let image_moves = {};
    $('#method').val('all');
    on_method();
    function on_method(){
        let m = $('#method').val();
        console.log('method selected ' + m);
        if ( m === 'all'){
            $('.URL_entry').css({display:'none'});
            $('.DATE_ENTRY').css({display:'block'});
        }else{
            $('.URL_entry').css({display:'block'});
            $('.DATE_ENTRY').css({display:'none'});
        }
    }
    $('#method').on('change', on_method);

    function get_params(){
        // Get parameters
        let params = {};
        params.post_type = $('#post_type').val();
        params.method = $('#method').val();
        if ( params.method === 'all'){
            let from = $('#from_date').val();
            let to = $('#to_date').val();
            if ( from && from.length > 0){
                params.from_date = new Date(from);
            }
            if ( to && to.length > 0){
                params.to_date = new Date(to);
            }
        } else {
            params.post_url = $('#url').val();
            params.post_name = $('#slug').val();
        }
        if ( params.method === 'all'){
            params.build_index = async function(){
                //let url = rest_url + params.post_type + '/';
                //url += '?_fields=id,date,post_name&per_page=100&page=${page}`;'
                let page = 1;
                let totalPages = 1; // Initialize total pages
                postindex = [];
                let from = null;
                let to = null;
                if ( params.from_date && params.to_date){
                    from = params.from_date.getTime();
                    to = params.to_date.getTime();
                }
                do {
                    const api_url = `${rest_url}${params.post_type}?_fields=id,date,slug&per_page=100&page=${page}`;
                    console.log('Building index from:', api_url);
                    try {
                        const response = await fetch(api_url);
                        if (!response.ok) {
                            throw new Error('Network response was not ok');
                        }
                        // Get total page count from response headers
                        totalPages = response.headers.get('X-WP-TotalPages');
                        //console.log('totalPages:', totalPages);
                        const posts = await response.json();
                        if (posts.length === 0) {
                            break; // No more posts to fetch
                        }
                        //console.log('Fetched posts:', posts.length);
                        for(let p of posts){
                            if ( from && to ){
                                let d = new Date(p.date);
                                let t = d.getTime();
                                if ( from <= t && to > t){
                                    postindex.push(p);
                                }
                            } else {
                                postindex.push(p);
                            }
                        }
                        page++;
                    } catch (error) {
                        console.log(error.toString());
                        noResultsMessage.textContent = 'Error building index. Please try again later.';
                        break;
                    }
                } while(page <= totalPages);
                console.log('Index built successfully');
                return postindex;
            }
        }
        return params;
    }
    async function getpost_by_id(post_type, id){
        let url = rest_url + post_type + '/' + id + '?context=edit';
        console.log('url: ' + url);
        const response = await fetch(url, {
            method: 'GET',
            headers: {
                'X-WP-Nonce': nonce
            }
        });
        if (!response.ok) {
            throw new Error('Network response was not ok');
        }
        const posts = await response.json();
        //console.log('post count: ' + posts.length);
        return posts;
    }
    /**
     * Checks if a given URL is accessible by making an AJAX call to a PHP function.
     * @param {string} url The URL to check.
     * @returns {Promise<boolean>} A promise that resolves to true if the URL is accessible, otherwise false.
     */
    async function check_url(url) {
        try {
            const formData = new FormData();
            formData.append('action', 'check_url_accessibility');
            formData.append('url', url);
            formData.append('security', url_check_nonce);
            
            const response = await fetch(ajax_url, {
                method: 'POST',
                body: formData,
            });

            const result = await response.json();
            console.log('check_url: ' + JSON.stringify(result));
            return result.success;
        } catch (error) {
            console.error('Error in check_url:', error);
            return false;
        }
    }
    let auto_update = false;
    posts_to_update = {};
    async function on_go_check(){
        // Get parameters
        let params = get_params();
        console.log(JSON.stringify(params));
        $('#results').html('');
        $('#actions').html('');
        $('#newsites').html('');
        $('#versioned_media').html();
        $('#media').html();
        $('#images').html();
        $('#others').html();
        $('#media_moves').html();
        $('#image_moves').html();
        // Check if the checkbox with id 'auto_update' is checked
        if ($('#auto_update').is(':checked')) {
            // Checkbox is checked
            // Add your logic here if needed
            auto_update = true;
        }
        // Request list of posts id, slug, date to process
        let postindex = await params.build_index();
        console.log('Got ' + postindex.length + ' posts');
        $('#count').text(postindex.length);
        let toupdate = 0;
        let updated = 0;
        prompts = 0;
        actions = {};
        posts_to_update = {};
        media_moves = {};
        image_moves = {};
        // for each post
        for (let ipost = 0; ipost < postindex.length; ipost++) {
            $('#current').text(ipost + 1);
            let posti = postindex[ipost];
            //console.log(JSON.stringify(posti));
            $('#post_ID').text(posti.id);
            $('#post_name').text(posti.slug);
            $('#post_date').text(posti.date);
            // get single post and update UI
            let post = await getpost_by_id(params.post_type, posti.id);
            if (post.content) {
                if (post.content.raw) {
                    let res = await process_content(post.content.raw, true);
                    if (res.updates() > 0) {

                        body = '<div id="post_report_' + posti.id + '">';
                        body += '<h1>' + posti.slug + '</h1>';
                        body += '<a href="/' + posti.slug + '" target="_blank" >' + posti.slug + '</a>';
                        body += '<table>';
                        body += '<tr><th>ID:</td><td>' + posti.id + '</td></tr>';
                        body += '<tr><td>Post type:</td><td>' + params.post_type + '</td></tr>';
                        body += '<tr><th>date</td><td>' + posti.date + '</td></tr>';
                        body += '<tr><th>slug</td><td>' + posti.slug + '</td></tr>';
                        body += '</table>';
                        body += '<div id="post_updates_"' + posti.id + '>';
                        body += res.report();
                        body += '</div>';
                        body += '<button type="button" class="update-button" data-post-id="' + posti.id + '">Update</button>';
                        body += '</div>';
                        $('#results').append(body);

                        //$('#post_report_' + posti.id).append(updated_html);
                        posts_to_update[posti.id] = post;
                        toupdate++;
                        $('#toupdate').text(toupdate);
                        // Record media and images to move and references to them
                        let moved = {
                            href:{count: 0, moves: media_moves, id: '#media_moves'},
                            src:{ count: 0, moves: image_moves, id: '#image_moves'}
                        };
                        for(let element of res.elements){
                            if ( element.move ) {
                                // First try to move it
                                let parts = element.link.split('#');
                                let link = parts[0];
                                if ( auto_update ){
                                    let new_href = null;
                                    if ( element.type === 'href'){
                                        new_href = await update_media_item(link)
                                    } else if ( element.type === 'src' ){
                                        new_href = await update_image_item(link);
                                    }
                                    if ( new_href ){
                                        if ( parts.length > 1 ){
                                            new_href += '#' + parts[1];
                                        }
                                        element.move = false;
                                        res.set_newlink(element.link, new_href.replace(home_url,''));
                                        console.log('new link set to: ' + element.newlink );
                                    }
                                }
                                // If not moved record it
                                if ( element.move ){
                                    moved[element.type].count++;
                                    let moves = moved[element.type].moves;
                                    if ( !moves[link]){
                                        moves[link] = [];
                                    }
                                    moves[link].push({
                                        id: posti.id
                                    });
                                }
                            }
                        }
                        for(let mkey in moved){
                            if ( moved[mkey].count > 0){
                                let t = '<table><tr><th>ref</th><th>count</th></tr>';
                                for(const key in moved[mkey].moves){
                                    t += '<tr>' ;
                                    t += '<td><a href="' + key + '" target="_blank">' + key + '</td>';
                                    t += '<td>' + moved[mkey].moves[key].length + '</td>';
                                    t += '<td>' + form_move_button(mkey, key) + '</td>';
                                    t += '</tr>';
                                }
                                t += '</table>';
                                $(moved[mkey].id).html(t);
                            }
                        }
                        // update the links and show the result
                        post.content.raw = res.get_updated_content();
                        if (post.content.raw) {
                        //    body += '<h2>After update</h2>';
                        //    let res2 = process_content(post.content.raw, false);
                        //    body += res2.report();
                        } else {
                            console.log('updated content was null');
                        }
                        // do updates
                        if ( auto_update && res.auto_updates() > 0){
                            let new_content = await update_post(posti.id, post.content.raw);
                            let res2 = await process_content(new_content, false);
                            $('#post_updates_' + posti.id).html(res2.report());
                            updated++;
                            $('#updated').text(updated);
                        }
                    }
                } else {
                    console.log('No post.content.raw post.content has ' + Object.keys(post.content));
                }
            }
        }
    }
    $('#go').on('click', on_go_check);
    function accumulate(action_object, prefix, element, id){
        let link = element.link;
        let key = link.replace(prefix,'');
        console.log(id + ' : prefix: ' + prefix + ' link: ' + link + ' key: ' + key);
        if ( !action_object[key] ){
            action_object[key] = {
                count:0,
            }
        }
        action_object[key].count++;
        if ( element.newlink ){
            action_object[key].url = element.newlink;
        }
        if ( id ){
            let t = '<table>';
            t += '<tr><th>action</th><th>count</th><th>url</th></tr>';
            for(const a in action_object){
                t += '<tr><td>' + a + '</td><td>' + action_object[a].count + '</td><td>'
                if ( action_object[a].url ){
                    t += '<a href="' + action_object[a].url + '" target="_blank">';
                    t += action_object[a].url + '</a>';
                }
                t += '</td></tr>';
            }
            t += '</table>';
            $('#' + id).html(t);
        }
    }
    function form_move_button(type, link){
        let body = '<button type="button" class="move-' + type + '-button"';
        body +=  ' data-url="' + link + '">'
        body += 'MOVE</button>';
        return body;
    }
    async function process_content(content, total){
        //console.log(content);
        const tempdiv = document.createElement('div');
        tempdiv.innerHTML = content;
        const urlElements = tempdiv.querySelectorAll('[href], [src]');

        const elements = [];

        urlElements.forEach(element => {
            // Use getAttribute to retrieve the original string value
            if (element.hasAttribute('href')) {
                elements.push({
                    type: 'href',
                    link: element.getAttribute('href'), // The fix is here
                    element: element
                });
            } else if (element.hasAttribute('src')) {
                elements.push({
                    type: 'src',
                    link: element.getAttribute('src'), // And here
                    element: element
                });
            }
        });
        //console.log('There were ' + urlElements.length + ' urlElements');
        //console.log('There were ' + elements.length + ' Elements');
        for(let element of elements){
            let action = null;
            if ( element.link.startsWith('https://actionclimateteignbridge.org/lookup_document.php')){
            } else if ( element.link.startsWith('#')){
            } else if ( element.link.startsWith('https://festival.actionclimateteignbridge.org')){
            } else if ( element.link.startsWith('https://actionclimateteignbridge.org/mapping/')){
            } else if ( element.link.startsWith('https://actionclimateteignbridge.org/lookup_document.php/')){
            } else if ( element.link.indexOf('plugins/ACT_maps') > 0 ) {
            } else if ( element.link.startsWith(home_url)){
                element.newlink = element.link.replace(home_url,'');
                element.auto = true;
            } else if ( element.link.startsWith(home_url_un)){
                element.newlink = element.link.replace(home_url_un,'');
                element.auto = true;
            } else if ( element.link.indexOf('actionclimateteignbridge.org') > 0){
                action = 'other';
                //
                //  Handle email addresses 
                //
                if ( element.link.startsWith('mail')) action = 'mailto';
                if ( element.link.endsWith('@actionclimateteignbridge.org')) action = 'mailto';
                if ( action === 'mailto'){
                    // look for a suitable email
                    let decoded_string = decodeURIComponent(element.link);
                    decoded_string = decoded_string.replace('http://','');
                    decoded_string = decoded_string.replace('https://','');
                    if ( decoded_string.indexOf(':') >= 0){
                        decoded_string = decoded_string.split(':')[1];
                    }
                    let email = decoded_string.split('@')[0].trim();
                    //if ( email.indexOf('vicky') >= 0)element.newlink = '/contact-us/?recipients=Wildlife%20Warden%20Scheme';
                    //if ( email.indexOf('fuad') >= 0)element.newlink = '/contact-us/?recipients=Energy%20and%20Built%20Environment';
                    let target = lookup_email(email);
                    if ( target ){
                        element.newlink = '/contact-us/?recipients=' + encodeURIComponent(target);
                        element.auto = true;
                    }
                    let e = emails[email];
                    if ( !e ){
                        emails[email] = {
                            count: 0,
                            url: element.newlink
                        };
                    }
                    emails[email].count++;
                    let t = '<table>';
                    t += '<tr><th>email</th><th>count</th><th>url</th></tr>';
                    for(const e in emails){
                        t += '<tr><td>' + e + '</td><td>' + emails[e].count + '</td><td>';
                        t += '<a href="' + emails[e].url + '" target="_blank">' + emails[e].url + '</a></td></tr>';
                    }
                    t += '</table>';
                    $('#emails').html(t);
                }
                //
                //  Handle links to newsite
                //
                if ( element.link.startsWith('https://actionclimateteignbridge.org/newsite')){
                    let base = element.link.replace('https://actionclimateteignbridge.org/newsite/','');
                    if ( base.startsWith('page.php/')){       
                        action = 'newsite';
                        let key = base.replace('page.php/','');
                        element.newlink = newsite_lookup[key];
                        element.auto = true;
                    } else {
                        if ( base.indexOf('events.html') >= 0){
                            element.newlink = '/activities/events/';
                            action = 'events';
                            element.auto = true;
                        }
                        if ( base.indexOf('map.html') >= 0){
                            action = 'maps';
                            element.prompt = true;
                        }
                    }
                } else {
                    let link_parts = element.link.split('#');
                    let rawlink = link_parts[0]; 
                    //let pagelink = link_parts[1];
                    //
                    // Images in need of moving
                    //
                    if ( rawlink.endsWith('.png') || rawlink.endsWith('jpg') || rawlink.endsWith('jpeg') || rawlink.endsWith('webp')){
                        action = 'images';
                        const parts = element.link.split('/');
                        const filename = parts[parts.length - 1];
                        //element.newlink = '/wp-content/uploads/' + filename;
                        element.move = true;
                    //
                    // media in need of moving
                    //
                    } else if ( rawlink.endsWith('.pdf') || rawlink.endsWith('docx') ){
                        const parts = element.link.split('/');
                        const filename = parts[parts.length - 1];
                        //element.newlink = '/wp-content/uploads/' + filename;
                        action = 'media';
                        element.move = true;
                    }
                }
                if ( action != 'mailto'){
                    if ( !await check_url(element.link)){
                        element.move = false;
                        element.deleted = true;
                        element.newlink = false;
                        action = 'deleted';
                        element.action = 'deleted';
                        element.prompt = true;
                    }
                }
                //
                //  First time round increment totals
                //
                element.action = action;
                if ( total ){
                    if ( element.prompt ) {
                        prompts++;
                        $('#prompts').text(prompts);
                    } 
                    let act = actions[action];
                    if (!act){
                        actions[action] = 0;
                    }
                    actions[action]++;
                    let t = '<table>';
                    t += '<tr><th>action</th><th>count</th></tr>';
                    for(const a in actions){
                        t += '<tr><td>' + a + '</td><td>' + actions[a] + '</td></tr>';
                    }
                    t += '</table>';
                    $('#actions').html(t);
                    switch(action){
                        case 'newsite':
                            accumulate(newsite, 'https://actionclimateteignbridge.org/newsite/page.php/', element, 'newsites');
                            break;
                        case 'versioned_media':
                            $('#versioned_media').text('There are ' + actions['versioned_media'] + ' of these');
                            break;
                        case 'media':
                            //accumulate(docs, '', element, 'docs');
                            $('#media').text('There are ' + actions['media'] + ' of these');
                            break;
                        case 'images':
                            $('#images').text('There are ' + actions['images'] + ' of these');
                            //accumulate(images, '', element, 'images')
                            break;
                        case 'other':
                            accumulate(other, '', element, 'others');
                            break;
                        case 'maps':
                            //accumulate(maps, 'https://actionclimateteignbridge.org/newsite/maps.html/', element, 'maps');
                            break;
                    }
                }
            }
        }
        let result = {
            tempdiv: tempdiv,
            elements: elements,
            report: function(){
                let body = '';
                body += '<table>';
                body += '<tr><th>type</th><th>Found link</th><th>Suggested replacement</th></tr>';
                for(let element of result.elements){
                    if ( element.newlink || element.prompt || element.move ){
                        body += '<tr>'
                        body += '<td>' + element.type + '</td>';
                        body += '<td><a href="' + element.link +    '" target="_blank">' + element.link    + '</td>';
                        body += '<td>';
                        if ( element.newlink ){
                            if ( element.auto ){
                                body += '<a href="' + element.newlink + '" target="_blank">' + element.newlink + '</a>';
                            } else {
                                body += element.newlink;
                            }
                        } else if ( element.prompt ){
                            body += element.action + ' needs manual update';
                        }
                        body += '</td>';
                        body += '<tr>'
                    }
                }
                body += '</table>';
                return body;
            },
            updates: function(){
                let count = 0;
                for(let element of result.elements){
                    if ( element.newlink || element.prompt || element.move){
                        count++;
                    }
                }
                return count;
            },
            auto_updates: function() {
                let count = 0;
                for(let element of result.elements){
                    if ( element.newlink && element.auto ){
                        count++;
                    }
                }
                return count;
            },
            get_updated_content: function(){
                for(let element of result.elements){
                    if ( element.newlink && element.auto){
                        element.element.setAttribute(element.type, element.newlink);
                    }
                }
                return result.tempdiv.innerHTML;
            },
            set_newlink: function(oldLink, newLink) {
                let ct = 0;
                for (let element of elements) {
                    let parts = element.link.split('#');
                    if (parts[0] === oldLink) {
                        element.newlink = newLink;
                        if ( parts.length > 1){
                            element.newlink += '#' + parts[1];
                        }
                        // Set auto to true so get_updated_content will update it
                        element.auto = true;
                        element.move = false;
                        ct++;
                    }
                }
                return ct;
            }

        }
        return result;
    }
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
    async function update_references(href, new_href, x_refs_table){
        let post_type = $('#post_type').val();
        let x_refs = x_refs_table[href];
        for(let x_ref of x_refs){
            let post = await getpost_by_id(post_type, x_ref.id);
            let res = await process_content(post.content.raw, false);
            let updates = res.set_newlink(href, new_href);
            if ( updates > 0){
                console.log(res.report());
                let content = res.get_updated_content();
                let new_post = await update_post(x_ref.id, content);
                let res2 = await process_content(new_post.content.raw, false);
                console.log(res2.report());
                $('#post_updates_' + x_ref.id).html(res2.report());
            }
        }
    }
    async function update_post(postId, newContent){
        try {
            let post_type = $('#post_type').val();
            const url = `${rest_url}${post_type}/${postId}`;
            console.log('url: ' + url);
            const response = await fetch(url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    // Include the nonce in the X-WP-Nonce header
                    'X-WP-Nonce': nonce
                },
                body: JSON.stringify({
                    content: newContent
                })
            });

            if (!response.ok) {
                const errorData = await response.json();
                throw new Error(`HTTP error! Status: ${response.status}, Code: ${errorData.code}`);
            }

            const updatedPost = await response.json();
            console.log('Post updated successfully:', updatedPost);
            return updatedPost;
        } catch (error) {
            console.error('Failed to update post:', error);
            throw error;
        }
    };
    $('#results').on('click', '.update-button', async function() {
        // 'this' refers to the button that was clicked.
        const id = $(this).data('post-id'); // Get the ID from the data attribute.
//alert('Updating ' + id);
        // Your 'on_update' logic, but now it has access to the scoped variables.
        let post = posts_to_update[id];
        if (post) {
            try {
                let updated = await update_post(id, post.content.raw); // Assuming updatePost expects ID and content
                // Now re-read update post_report_id if needed
                console.log(`Post ${id} updated successfully.`);
                //console.log(JSON.stringify(updated));
                //let res = process_content(updated.content.raw, false);
                //console.log(res.report());
            } catch (error) {
                console.error(`Error updating post ${id}:`, error);
            }
        }
    });
    async function move_src(src){
        $('#moving').text(src);
        let new_src = await update_image_item(src)
        new_src =  new_src.replace(home_url, '');
        update_references(src, new_src, image_moves);
    }
    async function move_href(href){
        $('#moving').text(href);
        let new_href = await update_media_item(href);
        console.log('new_href: ' + new_href);
        new_href=  new_href.replace(home_url, '');
        update_references(href, new_href, media_moves);
        //return new_href;
    }
    $('#media_moves').on('click', '.move-href-button', async function() {
        let href = $(this).data('url');
 //alert('Moving ' + href);
        await move_href(href);
    });
    $('#image_moves').on('click', '.move-src-button', async function() {
        let href = $(this).data('url');
 //alert('Moving ' + href);
        await move_src(href);
    });
    // ... the rest of your report generation and append logic ...
});
