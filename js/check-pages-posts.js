jQuery(document).ready(function($) {
    // initialise
    console.log('Initialising document');
    let rest_url = check_pages_data.rest_url;
    let home_url = check_pages_data.home_url;
    let nonce    = check_pages_data.nonce;
    let recipients_csv = check_pages_data.recipients_csv;
    console.log('rest_url: ' + rest_url);
    console.log('home_url: ' + home_url);
    console.log('nonce: ' + nonce);
    console.log('recipients_csv: ' + recipients_csv);
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
        if ( name === 'fuad')result = 'Built Environment and Energy';
        if ( name === 'scott') result = 'Carbon Cutters';
        if ( name === 'technical') result = 'Website';
        if ( name === 'rob') result = 'Carbon Cutters';
        if ( name === 'paulbloch' ) result = 'Carbon Cutters';
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
        let url = rest_url + post_type + '?include=' + id + '&context=edit';
        //console.log('url: ' + url);
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
        return posts[0];
    }
    posts_to_update = {};
    async function on_go_check(){
        // Get parameters
        let params = get_params();
        console.log(JSON.stringify(params));
        $('#results').html('');
        // Request list of posts id, slug, date to process
        if ( params.method === 'all' ){
            let postindex = await params.build_index();
            console.log('Got ' + postindex.length + ' posts');
            $('#count').text(postindex.length);
            let toupdate = 0;
            prompts = 0;
            actions = {};
            posts_to_update = {};
            // for each post
            for(let ipost = 0; ipost < postindex.length; ipost++){
                $('#current').text(ipost+1);
                let posti = postindex[ipost];
                //console.log(JSON.stringify(posti));
                $('#post_ID').text(posti.id);
                $('#post_name').text(posti.slug);
                $('#post_date').text(posti.date);
                // get single post and update UI
                let post = await getpost_by_id(params.post_type, posti.id);
                if ( post.content ){
                    if ( post.content.raw ){
                        let res = process_content(post.content.raw, posti , params.post_type );
                        if ( res.updates() > 0){
                            body = '<div id="post_report_' + posti.id + '">';
                            body += '<h1>' + posti.slug + '</h1>';
                            body += '<a href="/' + posti.slug + '" target="_blank" >' + posti.slug + '</a>';
                            body += '<table>';
                            body += '<tr><th>ID:</td><td>' + posti.id + '</td></tr>';
                            body += '<tr><td>Post type:</td><td>' + params.post_type + '</td></tr>';
                            body += '<tr><th>date</td><td>' + posti.date + '</td></tr>';
                            body += '<tr><th>slug</td><td>' + posti.slug + '</td></tr>';
                            body += '</table>';
                            body += res.report();
                            body += '<button type="button" class="update-button" data-post-id="' + posti.id + '">Update</button>';

                            // update the links and show the result
                            post.content.raw = res.get_updated_content();
                            let res2 = process_content(post.content.raw, posti, params.post_type);
                            body += '<h2>After update</h2>';
                            body += res2.report();
                            body += '</div>';
                            $('#results').append(body);
                            //$('#post_report_' + posti.id).append(updated_html);
                            posts_to_update[posti.id] = post;
                            toupdate++;
                            $('#toupdate').text(toupdate);
                        }
                    } else {
                        console.log('No post.content.raw post.content has ' + Object.keys(post.content));
                    }
                }
            }
        } else {

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
                    t += action_object[a].url;
                }
                t += '</td></tr>';
            }
            t += '</table>';
            $('#' + id).html(t);
        }
    }
    function process_content(content, posti, post_type){
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
            if ( element.link.startsWith('https://actionclimateteignbridge.org/lookup_document.php')){
                // do nothing for lookup_document
//            } else if ( element.link.indexOf('plugins/ACT_maps') > 0 ){
                // do nothing maps as false reporting
            } else if ( element.link.startsWith(home_url)){
                element.newlink = element.link.replace(home_url,'');
            } else if ( element.link.indexOf('actionclimateteignbridge.org') > 0){
                let action = 'other';
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
                        t += '<tr><td>' + e + '</td><td>' + emails[e].count + '</td><td>' + emails[e].url + '</td></tr>';
                    }
                    t += '</table>';
                    $('#emails').html(t);
                }
                if ( element.link.startsWith('https://actionclimateteignbridge.org/newsite/page.php')) action = 'newsite';
//                if ( element.link.startsWith('https://ww.actionclimateteignbridge.org')) action = 'ww';
//                if ( element.link.startsWith('https://cc.actionclimateteignbridge.org')) action = 'cc';
//                if ( element.link.startsWith('https://actionclimateteignbridge.org/oldsite')) action = 'oldsite';
                let rawlink = element.link.split('#')[0]; 
                let pagelink = element.link.split('#')[1];
                if ( rawlink.endsWith('.pdf')){
                    action = 'pdfs';
                    const parts = element.link.split('/');
                    const filename = parts[parts.length - 1];
                    const extractedName = filename.split('-v')[0];
                    element.newlink = 'https://actionclimateteignbridge.org/lookup_document.php/ACT/' + extractedName;
                }
                if ( rawlink.endsWith('.docx')){
                    action = 'docs';
                    const parts = element.link.split('/');
                    const filename = parts[parts.length - 1];
                    const extractedName = filename.split('-v')[0];
                    element.newlink = 'https://actionclimateteignbridge.org/lookup_document.php/ACT/' + extractedName;
                }
                if ( rawlink.endsWith('.png') || rawlink.endsWith('jpg') || rawlink.endsWith('jpeg') || rawlink.endsWith('webp')){
                    action = 'images';
                    const parts = element.link.split('/');
                    const filename = parts[parts.length - 1];
                    element.newlink = '/wp-content/uploads/' + filename;
                }
                if ( action === 'newsite'){
                    let key = element.link.replace('https://actionclimateteignbridge.org/newsite/page.php/','');
                    element.newlink = newsite_lookup[key];
                }
                if ( element.link.indexOf('plugins/ACT_maps') > 0 ) action = 'ACT_maps';
                if ( element.link.startsWith('https://actionclimateteignbridge.org/newsite/') && action != 'newsite'){
                    let base = element.link.replace('https://actionclimateteignbridge.org/newsite/','');
                    if ( base.indexOf('events.html') >= 0){
                        element.newlink = '/activities/events/';
                        action = 'events';
                    }
                    if ( base.indexOf('map.html') >= 0){
                        action = 'map';
                    }
                }
                if ( !element.newlink ){
                    element.prompt = true;
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
                element.action = action;
                $('#actions').html(t);
                switch(action){
                    case 'newsite':
                        accumulate(newsite, 'https://actionclimateteignbridge.org/newsite/page.php/', element, 'newsites');
                        break;
                    case 'pdfs':
                        accumulate(pdfs, '', element, 'pdfs');
                        break;
                    case 'docs':
                        accumulate(docs, '', element, 'docs');
                        break;
                    case 'images':
                        accumulate(images, '', element, 'images')
                        break;
                    case 'other':
                        accumulate(other, '', element, 'others');
                        break;
                    case 'maps':
                        accumulate(maps, 'https://actionclimateteignbridge.org/newsite/maps.html/', element, 'maps');
                        break;
                }
            }
        }
        let result = {
            tempdiv: tempdiv,
            elements: elements,
            post_type: post_type,
            index: posti,
            divid: post_type + '_content_' + posti.id,
            report: function(){
                let body = '';
                body += '<table>';
                body += '<tr><th>type</th><th>Found link</th><th>Suggested replacement</th></tr>';
                for(let element of result.elements){
                    if ( element.newlink ){
                        body += '<tr><td>' + element.type + '</td>'
                            + '<td><a href="' + element.link +    '" target="_blank">' + element.link    + '</td>'
                            + '<td><a href="' + element.newlink + '" target="_blank">' + element.newlink + '</td></tr>';
                    } else if ( element.prompt ){
                        body += '<tr><td>' + element.type + '</td>'
                            + '<td><a href="' + element.link +    '" target="_blank">' + element.link    + '</td>'
                            + '<td>' + element.action + ' needs manual update</td></tr>';
                    }

                }
                body += '</table>';
                return body;
            },
            updates: function(){
                let count = 0;
                for(let element of result.elements){
                    if ( element.newlink || element.prompt){
                        count++;
                    }
                }
                return count;
            },
            get_updated_content: function(){
                for(let element of result.elements){
                    if ( element.newlink ){
                        element.element.setAttribute(element.type, element.newlink);
                    }
                }
                return result.tempdiv.innerHTML;
            }
        }
        return result;
    }
    async function updatePost(postId, newContent){
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
alert('Updating ' + id);
        // Your 'on_update' logic, but now it has access to the scoped variables.
        let post = posts_to_update[id];
        if (post) {
            try {
                await updatePost(id, post.updatedContent); // Assuming updatePost expects ID and content
                // Now re-read update post_report_id if needed
                console.log(`Post ${id} updated successfully.`);
            } catch (error) {
                console.error(`Error updating post ${id}:`, error);
            }
        }
    });

    // ... the rest of your report generation and append logic ...
});
