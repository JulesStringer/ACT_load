jQuery(document).ready(function($) {
    function onmethod(){
        let type = $('#content_type').val();
        if ( type === 'team'){
            $('.DATE_ENTRY').css({display:'none'});
            $('#method').val('all');
            $('#method').prop('disabled', true);
        } else {
            $('#method').val('all');
            $('#method').prop('disabled', false);
        }
        let val = $('#method').val();
        console.log('val: ' + val);
        switch(val){
            case 'all':
                console.log('all selected');
                $('.ID_entry').css({display:'none'});
                $('.URL_entry').css({display:'none'});
                if ( type != 'team'){
                    $('.DATE_ENTRY').css({display:'block'});
                }
                break;
            case 'slug':
                console.log('slug selected');
                $('.ID_entry').css({display:'none'});
                $('.URL_entry').css({display:'block'});
                $('.DATE_ENTRY').css({display:'none'});
                break;
        }
    }
    function ontype(){

    }
    console.log('In act-load-pages-posts.js');
    $('#method').on('change', function() {
        onmethod();
    });
    $('#content_type').on('change',function() {
        onmethod();
    });
    $('#url').on('change', function(){
        let val = $('#url').val();
        console.log('url: ' + val);
        let vsplit = val.split('/');
        let l = vsplit.length;
        console.log('l: ' + l);
        let slug = vsplit[l-2];
        console.log('slug: ' + slug);
        $('#slug').val(slug);
    });
    $('.body').on('load', function(){
        onmethod();
    });
});