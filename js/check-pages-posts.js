jQuery(document).ready(function($) {
    // initialise
    console.log('Initialising document');
    let rest_url = check_pages_data.rest_url;
    let home_url = check_pages_data.home_url;
    let nonce    = check_pages_data.nonce;
    console.log('rest_url: ' + rest_url);
    console.log('home_url: ' + home_url);
    console.log('nonce: ' + nonce);
    let prompts = 0;
    let actions = {};
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
        let url = rest_url + post_type + '?include=' + id;
        //console.log('url: ' + url);
        const response = await fetch(url);
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
                    if ( post.content.rendered ){
                        let res = process_content(post.content.rendered, posti , params.post_type );
                        if ( res.updates() > 0){
                            let html = res.report();
                            $('#results').append(html);
                            toupdate++;
                            $('#toupdate').text(toupdate);
                            post.content.rendered = res.get_updated_content();
                            posts_to_update[posti.id] = post;
                        }
                    } else {
                        console.log('No post.content.rendered post.content has ' + Object.keys(post.content));
                    }
                }
            }
        } else {

        }
    }
    $('#go').on('click', on_go_check);
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
            } else if ( element.link.indexOf('plugins/ACT_maps') > 0 ){
                // do nothing maps as false reporting
            } else if ( element.link.startsWith(home_url)){
                element.newlink = element.link.replace(home_url,'');
            } else if ( element.link.indexOf('actionclimateteignbridge.org') > 0){
                element.prompt = true;
                prompts++;
                $('#prompts').text(prompts);
                let action = 'other';
                if ( element.link.startsWith('mail')) action = 'mailto';
                if ( element.link.startsWith('https://ww.actionclimateteignbridge.org')) action = 'ww';
                if ( element.link.startsWith('https://cc.actionclimateteignbridge.org')) action = 'cc';
                if ( element.link.startsWith('https://actionclimateteignbridge.org/oldsite')) action = 'oldsite';
                if ( element.link.startsWith('https://actionclimateteignbridge.org/newsite')) action = 'newsite';
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
                body = '<div>';
                body += '<h1>' + result.index.slug + '</h1>';
                body += '<a href="/' + result.index.slug + '" target="_blank" >' + result.index.slug + '</a>';
                body += '<table>';
                body += '<tr><th>ID:</td><td>' + result.index.id + '</td></tr>';
                body += '<tr><td>Post type:</td><td>' + result.post_type + '</td></tr>';
                body += '<tr><th>date</td><td>' + result.index.date + '</td></tr>';
                body += '<tr><th>slug</td><td>' + result.index.slug + '</td></tr>';
                body += '</table>';
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
                body += '<button type="button" onclick="on_update(' + result.index.id + ');" >Update</button>';
                body += '</div>';
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
});
async function updatePost(postId, newContent){
    try {
        const response = await fetch(`${rest_url}${post_type}/${postId}`, {
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
async function on_update(id){
    alert('Clicked update ' + id);
    console.log('clicked update ' + id );
    let post = posts_to_update[id];
    console.log(JSON.stringify(post));
}
