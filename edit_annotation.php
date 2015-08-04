<?php

include_once( $_SERVER['DOCUMENT_ROOT'] . '/wordpress/wp-load.php' );

global $wpdb;
global $annotation_db;

$update_data = array(
	'type' => $_POST['type'],
	'image' => $_POST['image_url'],
	'url' => $_POST['url'],
	'description' => $_POST['description']
);

$wpdb->update( 
	$annotation_db, $update_data, array( 'id' => $_POST['id'] )
);

?>

<meta http-equiv="refresh" content="0; url=<?php echo $_POST['back']?>">