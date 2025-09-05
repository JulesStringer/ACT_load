<?php
require_once(ABSPATH . 'wp-admin/includes/file.php'); // For file uploads if needed.
if ( ! defined( 'LISTS_DIR' ) ){
    define( 'LISTS_DIR', '/home/customer/www/actionclimateteignbridge.org/jobs/');
}
function load_recipients(){
    $data = file_get_contents(LISTS_DIR.'recipients.csv');
    if ( $data === false){
        $data = 'unable to read '.LISTS_DIR.'recipients.csv';
    }
    return $data;
}

?>