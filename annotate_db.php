<?php

$path = $_SERVER['DOCUMENT_ROOT'] . "/" . explode('wp-content' , $_SERVER['REQUEST_URI'])[0];

//needed to enable the use of the $wpdb connection
include_once $path . '/wp-config.php';
include_once $path . '/wp-includes/wp-db.php';
include_once $path . '/wp-includes/pluggable.php';

global $wpdb;
global $annotation_db;
global $annotation_rel_db;
$posts = $wpdb->posts;

//add entry to database
if ( $_POST['function'] === 'add' ) {
	$elements = $_POST['elements'];
	foreach ( $elements as $element ) {
		$hash = $element['hash'];
		
		//clean post title for SQL query
		$post_title = stripslashes( rawurldecode( $element['title'] ) );
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
		
		//check that a post with specified title exists
		if ( empty( $postids ) ) {
			//'-1' represents an inconsistency in the database, this should not occur
			$post_id = -1;
		} else {
			$post_id = $postids[0];
		}
	
		$name = stripslashes( $element['name'] );
	
		//create entry for annotation database
		$annotation_data = array(
			'id' => $hash,
			'name' => $name,
			'type' => $element['type'],
			'image' => $element['image'],
			'url' => $element['link'],
			'description' => $element['description']
		);
	
		$wpdb->insert( $annotation_db, $annotation_data );
		
		//create entry for annotation relationship database
		$relationship_data = array(
			'anno_id' => $hash,
			'post_id' => $post_id
		);
		
		$wpdb->insert( $annotation_rel_db, $relationship_data, array( '%s', '%d' ) );
		
		addAnnotationPage( $name, $hash );
	}
		
//delete entry from database
} else if ( $_POST['function'] === 'delete' ) {
	$elements = $_POST['elements'];
	
	foreach ( $elements as $element ) {
		$hash = $element['hash'];
		
		//get content and ID of all posts connected to the annotation
		$results = $wpdb->get_results( $wpdb->prepare(
			"
			SELECT 		p.post_content, p.ID
			FROM 		$posts p 
			INNER JOIN 	$annotation_rel_db a 
			ON 			a.post_id = p.ID 
			WHERE 		a.anno_id = %s
			"
		, $hash ) );
	
		$id = preg_quote( $hash );
	
		//delete links from content of each of these posts
		foreach( $results as $result ) {
			
			//Regexp to replace the annotation
			$content = preg_replace( "#<annotation id=\"$id\".*?>([\p{L}\d].+?)<.*?\/annotation>#", '$1', $result->post_content );
			
			$update = array(
				'ID' => $result->ID,
				'post_content' => $content
			);
			
			wp_update_post( $update );
		}
		
		//delete relationships
		$wpdb->delete( $annotation_rel_db, array( 'anno_id' => $hash ) );
		
		//delete annotation from database
		$wpdb->delete( $annotation_db, array( 'id' => $hash ) );
		
		//delete annotation page
		$wpdb->delete( $wpdb->posts, array( 'post_excerpt' => $hash, 'post_type' => 'page' ) );
	}
}

function addAnnotationPage( $name, $hash ) {
	global $wpdb;
	
	$results = $wpdb->query( $wpdb->prepare(
		"
		SELECT * 
		FROM $wpdb->posts p 
		WHERE p.post_type = 'page' 
		AND p.post_excerpt = %s
		"
	, $hash ) );
	if ( empty( $results ) ) {
		$annotationPageID = $wpdb->get_col( 
			"
			SELECT p.ID 
			FROM $wpdb->posts p 
			WHERE p.post_type = 'page' 
			AND p.post_name = 'annotations'
			" 
		)[0];
	
		$annotation_page = array(
			'post_name' => urlencode( $name ),
			'post_title' => $name,
			'post_content' => '',
			'post_status' => 'publish',
			'post_type' => 'page',
			'post_excerpt' => $hash,
			'post_parent' => $annotationPageID
		);
		wp_insert_post( $annotation_page );
	}
}

?>