<?php

include_once( $_SERVER['DOCUMENT_ROOT'] . '/wordpress/wp-load.php' );

global $wpdb;
global $annotation_db;
$posts = $wpdb->posts;

if ( $_POST['function'] === 'add' ) {
	$post_title = stripslashes( rawurldecode( $_POST['title'] ) );
	
	$postids = $wpdb->get_col( $wpdb->prepare(
		"
		SELECT 	p.ID
		FROM 	$posts p
		WHERE 	p.post_title = %s
		AND 	p.post_type = 'post'
		"
	, $post_title ) );
	
	//check if no posts with specified title exist
	if ( empty( $postids ) ) {
		$post_id = -1;
	} else {
		$post_id = $postids[0];
	}
	
	$data = array(
		'name' => $_POST['name'],
		'type' => $_POST['type'],
		'post_id' => $post_id
	);
	
	$wpdb->insert( $annotation_db, $data );
	
} else if ( $_POST['function'] === 'delete' ) {
	
	$results = $wpdb->get_results( $wpdb->prepare(
		"
		SELECT 	p.post_content, p.ID
		FROM 	$annotation_db a, $posts p
		WHERE 	a.name = %s
		AND 	p.ID = a.post_id
		"
	, $_POST['name'] ) );

	foreach( $results as $result ) {
		//delete link from content
		$search = rawurlencode( $_POST['name'] );
		
		$content = preg_replace( "#<a href=\"localhost\/annotations\?search=$search\"[^>]+>.?<span.+?>(.+?)<\/span><\/a>#", '$1', $result->post_content );
		
		$update = array(
			'ID' => $result->ID,
			'post_content' => $content
		);
		
		wp_update_post( $update );
	}

	
	$wpdb->delete( $annotation_db, array( 'name' => $_POST['name'] ) );
	
}

?>