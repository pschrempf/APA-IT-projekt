<?php

include_once( $_SERVER['DOCUMENT_ROOT'] . '/wordpress/wp-load.php' );

global $wpdb;
global $annotation_db;
global $annotation_rel_db;
$posts = $wpdb->posts;

//add entry to database
if ( $_POST['function'] === 'add' ) {
	$hash = $_POST['lang'] . '_' . $_POST['hash'];
	
	//clean post title for SQL query
	$post_title = stripslashes( rawurldecode( $_POST['title'] ) );
	$post_type = '%post%';
	
	//try and get the ID of the post from database
	$postids = $wpdb->get_col( $wpdb->prepare(
		"
		SELECT 	p.ID
		FROM 	$posts p
		WHERE 	p.post_title = %s
		AND 	p.post_type LIKE %s
		"
	, $post_title, $post_type ) );
	
	//check that a post with specified title exist
	if ( empty( $postids ) ) {
		//'-1' represents an inconsistency in the database, this should not occur
		$post_id = -1;
	} else {
		$post_id = $postids[0];
	}

	$name = stripslashes( $_POST['name'] );

	//create entry for annotation database
	$annotation_data = array(
		'id' => $hash,
		'name' => $name,
		'type' => $_POST['type'],
		'image' => $_POST['image'],
		'url' => $_POST['link'],
		'description' => $_POST['description']
	);

	$wpdb->insert( $annotation_db, $annotation_data );
	
	//create entry for annotation relationship database
	$relationship_data = array(
		'anno_id' => $hash,
		'post_id' => $post_id
	);
	
	$wpdb->insert( $annotation_rel_db, $relationship_data );
	
		
//delete entry from database
} else if ( $_POST['function'] === 'delete' ) {
	
	$name = stripslashes( $_POST['name'] );
	
	//get content and ID of all posts connected to the annotation
	$results = $wpdb->get_results( $wpdb->prepare(
		"
		SELECT 	p.post_content, p.ID
		FROM 	$annotation_db a, $posts p
		WHERE 	a.name = %s
		AND 	p.ID = a.post_id
		"
	, $name ) );

	$id = preg_quote( $name );

	//delete links from content of each of these posts
	foreach( $results as $result ) {
		$search = rawurlencode( $name );
		
		//Regexp to replace the link
		$content = preg_replace( "#<annotation id=\"$id\">.*?<span.*?>(.+?)<\/span><\/a><\/annotation>#", '$1', $result->post_content );
		
		$update = array(
			'ID' => $result->ID,
			'post_content' => $content
		);
		
		wp_update_post( $update );
	}
	
	//delete annotation from database
	$wpdb->delete( $annotation_db, array( 'name' => $name ) );
	
	echo $name;
}

?>