<?php
/*
Plugin Name: Annotation Plugin
Description: Annotation service
Author: Patrick Schrempf
Version: 1.0
Text Domain: annotation-plugin
Domain Path: languages/
*/

defined( 'ABSPATH' ) or die( 'Plugin cannot be accessed correctly!' );
define( 'WPLANG', '' );

/**
 * Main class of the plugin.
 */
class Annotation_Plugin {

	/**
	 * Name of the options for the database used by the plugin.
	 * 
	 * @var string $option_name
	 */
	private $option_name = 'annotation-plugin-options';

	/**
	 * Adds and registers various hooks, actions and filters.
	 */
	function __construct() {
		register_activation_hook( __FILE__, array( $this, 'activate' ) );
		
		register_deactivation_hook( __FILE__, array( $this, 'deactivate') );
		
		register_uninstall_hook( __FILE__, array( $this, 'uninstall' ) );

		add_action( 'admin_init', array( $this, 'annotation_settings_init' ) );

		add_action( 'admin_menu', array( $this, 'add_pages' ) );

		add_action( 'init', array( $this, 'plugin_init' ) );
		
		add_action( 'plugins_loaded', array( $this, 'load_textdomain' ) );

		add_filter( 'tiny_mce_before_init', array( $this, 'override_mce_options' ) );
		
		add_action( 'delete_post', array( $this, 'delete_annotation_relations' ) );
		
		add_filter( 'template_include', array( $this, 'include_annotations_template' ) );
		
		add_filter( 'the_content', array( $this, 'add_annotation_list' ) );		
	}
	
	/**
	 * Complete all necessary tasks on plugin activation.
	 */
	function activate() {
		$this->create_plugin_database();
		$this->add_main_annotations_page();
		$this->add_other_annotation_pages();
	}

	/**
	 * Creates the necessary MySQL databases for the plugin annotations.
	 */
	function create_plugin_database() {
		global $wpdb;
		global $annotation_db;
		$annotation_db = $wpdb->prefix . 'annotations';
		$charset_collate = $wpdb->get_charset_collate();
		
		$sql = "CREATE TABLE IF NOT EXISTS $annotation_db (
					id VARCHAR(80) NOT NULL,
					name VARCHAR(80) NOT NULL,
					type VARCHAR(40) NOT NULL,
					image VARCHAR(255),
					url VARCHAR(255),
					description LONGTEXT,
					PRIMARY KEY  (id)
				) $charset_collate;";
		
		require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
		dbDelta( $sql );
		
		global $annotation_rel_db;
		$annotation_rel_db = $wpdb->prefix . 'annotation_relationships';
		$posts = $wpdb->posts;
		
		$sql = "CREATE TABLE IF NOT EXISTS $annotation_rel_db (
					anno_id VARCHAR(80) NOT NULL,
					post_id INT NOT NULL,
					PRIMARY KEY (anno_id, post_id)
				) $charset_collate;";

		require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
		dbDelta( $sql );		
	}

	/**
	 * Adds a WordPress page for annotations if it does not already exist.
	 */
	function add_main_annotations_page() {
		global $wpdb;
		
		$results_num = $wpdb->get_var(
			"
			SELECT COUNT(*) 
			FROM $wpdb->posts p 
			WHERE p.post_type = 'page' 
			AND p.post_name = 'annotations'
			"
		);
		if ( 0 == $results_num ) {
			$page = array(
				'post_name' => 'annotations',
				'post_title' => __( 'Annotations', 'annotation-plugin'),
				'post_content' => '<em>' . __( 'No annotations available', 'annotation-plugin' ) . '.</em>',
				'post_excerpt' => 'Annotations',
				'post_status' => 'publish',
				'post_type' => 'page'
			);
			wp_insert_post( $page );
		}
	}
	
	/**
	 * Creates all annotation pages for annotations in database.
	 */
	function add_other_annotation_pages() {
		global $wpdb;
		global $annotation_db;
		
		// get all annotations
		$annotations = $wpdb->get_results(
			"
			SELECT a.name, a.id 
			FROM $annotation_db a
			"
		);
		
		// find id of 'annotations' page for post_parent
		$annotationPageID = $wpdb->get_col( 
			"
			SELECT p.ID 
			FROM $wpdb->posts p 
			WHERE p.post_type = 'page' 
			AND p.post_name = 'annotations'
			" 
		)[0];
		
		foreach ( $annotations as $annotation ) {	
			// check if annotation page already exists
			$results_num = $wpdb->get_var( $wpdb->prepare(
				"
				SELECT COUNT(*) 
				FROM $wpdb->posts p 
				WHERE p.post_type = 'page' 
				AND p.post_excerpt = %s
				"
			, $annotation->id ) );
			
			if ( 0 == $results_num ) {	
				// add annotation page to database
				$annotation_page = array(
					'post_name' => urlencode( $annotation->name ),
					'post_title' => $annotation->name,
					'post_content' => '',
					'post_status' => 'publish',
					'post_type' => 'page',
					'post_excerpt' => $annotation->id,
					'post_parent' => $annotationPageID
				);
				wp_insert_post( $annotation_page );
			}
		}
	}
	
	/**
	 * Deletes all pages belonging to annotations on deactivation.
	 */
	function deactivate() {
		global $wpdb;
		global $annotation_db;
		
		// get all annotations
		$annotations = $wpdb->get_results(
			"
			SELECT a.id 
			FROM $annotation_db a
			"
		);
		
		// delete all specific annotation pages
		foreach ( $annotations as $annotation ) {
			$wpdb->delete( $wpdb->posts, array( 'post_excerpt' => $annotation->id ) );
		}
		
		// delete 'annotations' page
		$wpdb->delete( $wpdb->posts, array( 'post_name' => 'annotations' ) );
	}
	
	/**
	 * Clean up databases when plugin is uninstalled.
	 */
	function uninstall() {
		global $wpdb;
		global $annotation_db;
		global $annotation_rel_db;
		
		$wpdb->query( "DROP TABLE IF EXISTS $annotation_db" );
		$wpdb->query( "DROP TABLE IF EXISTS $annotation_rel_db" );		
	}

	/**
	 * Main initialising function that enqueues jQuery and initialises variables and the annotation service.
	 */
	function plugin_init() {
		wp_enqueue_script( 'jquery' );

		$this->init_global_variables();
		$this->init_annotation_service();		
	}
	
	/**
	 * Initialises all global variables and constants.
	 */
	function init_global_variables() {
		global $wpdb;
		global $annotation_db;
		$annotation_db = $wpdb->prefix . 'annotations';
		
		global $annotation_rel_db;
		$annotation_rel_db = $wpdb->prefix . 'annotation_relationships';
		
		// set all necessary constants
		global $plugin_constants;
		$plugin_constants = array(
			'site_url' => get_site_url(),
			'ps_annotate_url' => 'http://apapses5.apa.at:7070/fliptest_tmp/cgi-bin/ps_annotate',
			'annotate_db' => plugins_url( 'annotate-db.php', __FILE__ ),
			'selection_form' => plugins_url( 'templates/selection-form.html', __FILE__ ),
			'button_text' => __( 'Annotate', 'annotation-plugin' ),
			'button_tooltip' => __( 'Annotate', 'annotation-plugin' ),
			'no_text_alert' => __( 'Please enter text to be annotated!', 'annotation-plugin' ),
			'no_annotations_alert' => __( 'No annotations could be found', 'annotation-plugin' ),
			'results_title' => __( 'Annotation results', 'annotation-plugin' ),
			'results_name' => __( 'Name', 'annotation-plugin' ),
			'results_type' => __( 'Type', 'annotation-plugin' ),
			'delete_error' => __( 'No annotations selected', 'annotation-plugin' ),
			'delete_confirmation' => 
				__( 'Would you really like to delete this annotation/these annotations permanently?', 'annotation-plugin' ),
			'success' => __('Annotated successfully! Please make sure to save the post.', 'annotation-plugin')
		);
	}
	
	/**
	 * Loads the textdomain for different language packs.
	 */
	function load_textdomain() {
		load_plugin_textdomain( 'annotation-plugin', false, basename( dirname( __FILE__ ) ) . '/languages/' );
	}

	/**
	 * Initialises the annotation service/tinymce plugin.
	 */
	function init_annotation_service() {
		add_filter( 'mce_external_plugins', array( $this, 'add_annotate_plugin') );
		add_filter( 'mce_buttons', array( $this, 'register_annotate_button') );
	}
	
	/**
	 * Adds the annotation plugin to the tinymce editor and localizes SETTINGS and CONSTANTS.
	 * 
	 * @param array $plugin_array
	 * @return array $plugin_array Array of plugins containing the added plugin
	 */
	function add_annotate_plugin( $plugin_array ) {
		// enqueue script so that variables can be localized
		wp_enqueue_script( 'tinymce', plugins_url( 'js/tinymce-plugin.js', __FILE__ ), array( 'jquery' ) );		
		
		// localize SETTINGS
		$options = get_option( $this->option_name );
		wp_localize_script( 
			'tinymce', 
			'SETTINGS', 
			array(
				'annotate_url' => isset( $options['annotate_url'] ) ? true : false,
				'annotate_date' => isset( $options['annotate_date'] ) ? true : false,
				'annotate_email' => isset( $options['annotate_email'] ) ? true : false,
				'add_microdata' => isset( $options['add_microdata'] ) ? true : false,
				'add_links' => isset( $options['add_links'] ) ? true : false,				
				'lang' => $options['lang'],
				'skip' => $options['skip'],
			) 
		);
		
		// localize CONSTANTS
		global $plugin_constants;
		wp_localize_script( 'tinymce', 'CONSTANTS', $plugin_constants );
		
		// localize nonce for security
		wp_localize_script( 'tinymce', 'SECURITY', array( 'nonce' => wp_create_nonce( 'add' ) ) );
		
		// add annotation plugin to plugin array
		$plugin_array['annotate'] = plugins_url( 'js/tinymce-plugin.js', __FILE__ );		
		return $plugin_array;
	}
	
	/**
	 * Registers the 'Annotate' button in the tinymce editor.
	 * 
	 * @param array $buttons
	 * @return array $buttons Array of tinymce buttons with added 'Annotate' button
	 */
	function register_annotate_button( $buttons ) {
		array_push( $buttons, 'separator', 'annotate' );
		return $buttons;
	}

	/**
	 * Adds plugin pages.
	 */
	function add_pages() {
		// add plugin options page
		add_options_page(
			__( 'Annotation Plugin Options', 'annotation-plugin' ), 
			__( 'Annotation Plugin Options', 'annotation-plugin' ), 
			'manage_options', 
			$this->option_name, 
			array( $this, 'options_page' ) 
		);
		
		// add dashboard annotation page
		add_object_page( 
			__( 'Annotations', 'annotation-plugin' ), 
			__( 'Annotations', 'annotation-plugin' ), 
			'publish_posts', 
			'annotations', 
			array( $this, 'get_annotations' ), 
			plugins_url( 'img/apa_small.jpg', __FILE__ ) 
		);
	}
	
	/**
	 * Initialises the settings by registering an 'annotation-plugin-options' setting.
	 */
	function annotation_settings_init() {
		register_setting( $this->option_name, $this->option_name, array( $this, 'validate_input' ) );
	}
	
	/**
	 * Validates and sanitizes the settings text input field.
	 * 
	 * @param array $input
	 * @return array $input Sanitized input
	 */
	function validate_input( $input ) {
		if ( isset( $input['skip'] ) ) {
			$input['skip'] = sanitize_text_field( $input['skip'] );
		}
		return $input;
	}
		
	/**
	 * Adds microdata tags to tinymce editor's valid elements.
	 * 
	 * @param array $init_array
	 * @return array $init_array Array containing the added custom valid tags
	 */
	function override_mce_options( $init_array ) {
		$options = 'link[itemprop|href],a[href|itemprop|itemscope|itemtype],span[itemprop|itemscope|itemtype],' 
			. 'annotation[id|itemprop|itemscope|itemtype]';
		$init_array['extended_valid_elements'] = $options;
		return $init_array;
	}
	
	/**
	 * Use 'annotations-template.php' for annotations page.
	 * 
	 * @param string $template
	 * @return string Link to 'annotations-template.php' if on annotations page, else normal template
	 */
	function include_annotations_template( $template ) {
		if ( is_page( 'annotations' ) || $this->is_subpage( 'annotations' ) ) {
			$annotations_template = plugin_dir_path( __FILE__ ) . 'templates/annotations-template.php' ;
			if ( '' != $annotations_template ) {
				$template = $annotations_template;
			}
		}
		return $template;		
	}
	
	/**
	 * Checks if the current page is a subpage of the page with the provided slug.
	 * 
	 * @param string $slug Slug of the parent page to be checked against
	 * @return bool True if the current page is a subpage of the given page, false otherwise
	 */
	function is_subpage( $slug ) {
		global $post;
		
		if ( isset( $post ) ) {
			$page = get_page_by_path( $slug );
			$anc = get_post_ancestors( $post->ID );
			
			foreach ( $anc as $ancestor ) {
				if ( is_page() && $ancestor == $page->ID ) {
					return true;
				}
			}
		}
		
		return false;  // the page is not a subpage of $slug
	}
	
	/**
	 * Deletes all relations to annotations from a post.
	 * 
	 * @param int $post_id ID of the post to delete the relations of.
	 * @return int $post_id
	 */
	function delete_annotation_relations( $postid ) {
		global $wpdb;
		global $annotation_rel_db;
		$wpdb->delete( $annotation_rel_db, array( 'post_id' => $postid ) );
		return $postid;
	}
	
	/**
	 * Displays the options page for the plugin.
	 */
	function options_page() {
		if ( ! current_user_can( 'manage_options' ) )  {
			wp_die( __( 'You do not have sufficient permissions to access this page.', 'annotation-plugin' ) );
		}
		
		// get current options
		$options = get_option( $this->option_name );
		
		// display the options html
		
		echo '<div class="wrap">';
		echo '<h2>' . __( 'Annotation Plugin Settings', 'annotation-plugin' ) . '</h2>';
		echo '<form method="post" action="options.php">';
		
		settings_fields( $this->option_name );
		
		echo '<table class="form-table">';
				
		// [add_links]
		echo	'<tr valign="top">' . 
					'<th scope="row">' . __( 'Add links to posts?', 'annotation-plugin' ) . '</th>' . 
					'<td>' . 
						'<input type="checkbox" name="' . $this->option_name . "[add_links]" . '" value="yes" ';  
							if ( isset( $options['add_links'] ) ) { 
								checked( 'yes', $options['add_links'] ); 
							} 
		echo 			'>' . 
						__( 'Links to annotations will be added to all posts if this is selected.'
							, 'annotation-plugin' ); 
						
		echo 		'</td>' . 
				'</tr>';
		
		// [add_microdata]
		echo 	'<tr valign="top">' . 
					'<th scope="row">' . __( 'Add schema.org microdata?', 'annotation-plugin' ) . '</th>' . 
					'<td>' . 
						'<input type="checkbox" name="' . $this->option_name . '[add_microdata]' . '" value="yes" ';
							if ( isset( $options['add_microdata'] ) ) { 
								checked( 'yes', $options['add_microdata'] ); 
							} 
		echo			'>';
					_e( 'Microdata will be added to all annotations if this is selected.', 'annotation-plugin' ); 
		echo 		'</td>' . 
				'</tr>';
		
		// [display_table]
		echo 	'<tr valign="top">' . 
					'<th scope="row">' . __( 'Show list of annotations below posts?', 'annotation-plugin' ) . '</th>' . 
					'<td>' . 
						'<input type="checkbox" name="' . $this->option_name . '[display_annotations]' . '" value="yes"';
							if ( isset( $options['display_annotations'] ) ) { 
								checked( 'yes', $options['display_annotations'] ); 
							} 
		echo 			'>'; 
					_e( 'A brief list of the annotations in the post will be shown below each post.', 'annotation-plugin' ); 
		echo 		'</td>' .
				'</tr>';
				
		// [lang]
		echo 	'<tr valign="top">' . 
					'<th scope="row">' . __( 'Select an annotation language', 'annotation-plugin' ) . '</th>' . 
					'<td>' . 
						'<select name="' . $this->option_name . '[lang]' . '">' . 
							'<option value="GER" '; 
								if ( isset( $options['lang'] ) ) { 
									selected( $options['lang'], 'GER' ); 
								} 
		echo 				'>';
								_e( 'German', 'annotation-plugin' );
		echo 				'</option>';
		echo 				'<option value="FRA" ';
								if ( isset( $options['lang'] ) ) { 
									selected( $options['lang'], 'FRA' ); 
								} 
		echo 				'>';
								_e( 'French', 'annotation-plugin' );
		echo 				'</option>' . 
						'</select>' . 
					'</td>' . 
				'</tr>';
		
		// [skip]
		echo 	'<tr valign="top">' . 
					'<th scope="row">' . __( 'Enter annotations to skip', 'annotation-plugin' ) . '</th>' . 
					'<td>' . 
						'<input type="text" size="60" placeholder="' . __( 'e.g.', 'annotation-plugin' ) . 
							' GER:Austria_Presse_Agentur|GER:Deutsche_Presse_Agentur" ' . 
							'name="' . $this->option_name . '[skip]' .  '" value="';
								if ( isset ( $options['skip'] ) ) { 
									echo $options['skip']; 
								}
		echo			'"> ';
						_e( '(multiple entries should be separated by "|")', 'annotation-plugin' );						
		echo		'</td>' . 
				'</tr>' . 
			'</table>';
		
		submit_button();
		
		echo '</form>';
		echo '</div>';
	}
	
	/**
	 * Displays an annotations page according to the query.
	 */
	public function get_annotations() {
		// enqueue script and style for page
		wp_enqueue_script( 'annotation-script', plugins_url( 'js/annotations.js', __FILE__ ), array( 'jquery' ) );
		wp_enqueue_style( 'annotation-stylesheet', plugins_url( 'css/annotations.css', __FILE__ ) );
		
		// localize CONSTANTS
		global $plugin_constants;
		wp_localize_script( 'annotation-script', 'CONSTANTS', $plugin_constants );
		wp_localize_script( 'annotation-script', 'SECURITY', array( 'nonce' => wp_create_nonce( 'delete' ) ) );
		wp_localize_script( 'annotation-script', 'COLOR', array( 'background' => get_user_option('admin_color') ) );
		
		$allowed_orders = array(
			'name' => 'a.name',
			'type' => 'a.type',
		);
		
		// check for orderby GET attribute
		if ( isset( $_GET['orderby'] ) && isset( $allowed_orders[ $_GET['orderby'] ] ) ) {
			$order_by = $allowed_orders[ $_GET['orderby'] ];
		} else {
			$order_by = 'a.name';
		}
		
		// check for search GET attribute
		if ( isset( $_GET['search'] ) ) {
			$search_string = '%' . urldecode( $_GET['search'] ) . '%';
		} else {
			$search_string = '%';
		}
		
		// get all annotations from database
		global $wpdb;
		global $annotation_db;
		$annotations = $wpdb->get_results( $wpdb->prepare(
			"
			SELECT 	* 
			FROM 	$annotation_db a 
			WHERE 	a.name LIKE %s 
			ORDER BY $order_by
			"
		, $search_string ) );
		
		// set $url to correct link
		if ( is_page( 'annotations' ) || $this->is_subpage('annotations') ) {
			$url = 'annotations?';
		} else {
			$url = 'admin.php?page=annotations&';
		}
		
		// display page
		if ( is_page( 'annotations' ) || ( isset( $_GET['page'] ) && 'annotations' == $_GET['page'] ) ) {
			echo '<input type="text" id="anno_search" placeholder="' . __( 'Search', 'annotation-plugin' ) . '">';
		}
		
		echo '<h1 id="annotation_title" class="anno_padded">' . 
				'<a href="' . $url . '">' . __( 'Annotations', 'annotation-plugin' ) . '</a>' . 
			'</h1>';
		
		if ( empty( $annotations ) ) {
			if ( '%' === $search_string ) {
				echo '<p class="anno_error">' . __( 'Please add annotations to your posts.', 'annotation-plugin' ) . '</p>';
			} else {
				$this->get_search_annotation_page( $annotations, $url );
			}
		} else {
			if ( $this->is_subpage( 'annotations' ) ) {
				$this->get_specific_annotation_page( $annotations );
			} else if ( isset( $_GET['search'] ) ) {
				$this->get_search_annotation_page( $annotations, $url );
			} else if ( isset( $_GET['edit'] ) ) {
				$this->get_edit_annotation_page( $annotations );
			} else {
				$this->get_general_annotation_page( $annotations, $url );
			}
		}
	}
	
	/**
	 * Creates annotation page containing all annotations.
	 * 
	 * @param array $annotations
	 * @param string $url
	 */
	function get_general_annotation_page( $annotations, $url ) {
		// set image source for small triangles
		$img_src = plugins_url( 'img/triangle.jpg', __FILE__ );
		
		// display general annotations in a table
		echo '<div class="anno_padded">';
		echo '<table id="annotation_table">';
		
		echo '<tr class="anno_title">';
		
		// if current user can edit posts then show checkbox column
		if ( current_user_can( 'edit_posts' ) ) {
			echo '<th class="anno_input">' . 
					'<input type="checkbox" class="select-all">' . 
				'</th>';
		}
		
		// display table with annotations for all users
		echo '<th';
		if ( ! is_page( 'annotations' ) ) {
			echo ' class="anno_left"';
		}
		echo '>' . __( 'Annotation', 'annotation-plugin' ) . 
				'<a href="' . $url . ' orderby=name">' . 
					'<img src="' . $img_src . '" alt="sort" class="anno_triangle">' . 
				'</a>';
			'</th>';
		
		echo '<th';
		if ( ! is_page( 'annotations' ) ) {
			echo ' class="anno_left"';
		}
		echo '>' . __( 'Type', 'annotation-plugin' ) . 
				'<a href="' . $url . ' orderby=type">' . 
					'<img src="' . $img_src . '" alt="sort" class="anno_triangle">' . 
				'</a>' . 
			'</th>';
			
		echo '</tr>';
		
		$counter = 0;
		// display each annotation in table
		foreach ( $annotations as $result ) {
			$counter++;
			if ( 0 == $counter%2 ) {
				echo '<tr class="annotation anno_white">';
			} else {
				echo '<tr class="annotation anno_grey">';
			}
			if ( current_user_can( 'edit_posts' ) ) {
				echo '<td class="anno_input">' . 
						'<input type="checkbox" class="anno" value="' . $result->id  . '">' . 
					'</td>';
			}
			
			echo '<td>' . 
					'<a href="'; 
				if ( is_page( 'annotations' ) ) { 
					echo get_site_url() . '/annotations/' . urlencode( $result->name ); 
				} else {
					echo $url . 'edit=' . urlencode( $result->id );
				}
			echo 	'">' . 
						'<strong>' . stripslashes( $result->name ) . '</strong>' . 
					'</a>' . 
				'</td>';
		
			echo '<td>' . $result->type . '</td>';
			
			echo '</tr>';
		}
		
		echo '</table></div>';
		
		// if current user can edit post show delete button
		if ( current_user_can( 'edit_posts' ) ) {
			echo '<button id="anno_delete" class="anno_custom_button">' .  __( 'Delete', 'annotation-plugin' ) . '</button>';
		}
		
		echo '</div></article>';
	}
	
	/**
	 * Creates the information page for a specific annotation.
	 * 
	 * @param array $annotations
	 */
	function get_specific_annotation_page( $annotations ) {
		wp_enqueue_style( 'annotation-stylesheet', plugins_url( 'css/annotations.css', __FILE__ ) );
		
		// find correct annotation
		foreach ( $annotations as $result ) {
			if ( get_the_title() === $result->name ) {
				$annotation = $result;
				break;
			}
		}
		
		// add microdata for annotation
		$schema;
		if ( 'location' == $annotation->type ) {
			$schema = 'http://schema.org/Place';
		} else if ( 'person' == $annotation->type ) {
			$schema = 'http://schema.org/Person';
		} else if ( 'organization' == $annotation->type ) {
			$schema = 'http://schema.org/Organization';
		} else {
			$schema = 'http://schema.org/Thing';
		}
		echo '<div class="anno_padded anno_block anno_white" itemscope itemtype="' . $schema . '">';
		
		// display title with or without link
		if ( '' === $annotation->url ) {
			echo '<h2 class="anno_inline" itemprop="name">' . get_the_title() . '</h2>';			
		} else {
			echo '<a href="' . $annotation->url . '" itemprop="url"><h2 class="anno_inline" itemprop="name">' . get_the_title() 
				. '</h2></a>';
		}
		
		if ( current_user_can( 'edit_posts' ) ) {
			echo ' (<a href="' . get_site_url() . '/wp-admin/admin.php?page=annotations&edit=' 
				. urlencode( $annotation->id ) . '">' . __( 'Edit', 'annotation-plugin' ) . '</a>)';
		}
		
		// display image if available
		if ( '' !== $annotation->image ) { 
			echo '<img class="anno_img" src="' . $annotation->image 
				. '" alt="' . __( 'No picture available', 'annotation-plugin') . '">';
		}
		
		// display annotation information
		echo '<p>
				- ' . $annotation->type . '
			</p>
			<p itemprop="description">
				'. stripslashes( $annotation->description ) . '
			</p>
			<hr>
			<p>
				<strong>' . __( 'Posts', 'annotation-plugin' ) . ': </strong>
				<br>
				<ul class="anno_inner_list annotation_details">';
		
		// get all relations for this annotation
		global $wpdb;
		global $annotation_rel_db;
		$relations = $wpdb->get_results( $wpdb->prepare( 
			"
			SELECT post_id 
			FROM $annotation_rel_db 
			WHERE anno_id = %s
			"
		, $annotation->id ) );
		
		if ( empty( $relations ) ) {
			_e( 'No posts found with this annotation.', 'annotation-plugin' );
		}
		
		// get information from each relation
		foreach ( $relations as $result ) {
			$guid = '';
			
			// deal with database errors
			if ( -1 == $result->post_id ) {
				$post_title = '<p class="anno_error">[' . __('Post does not exist', 'annotation-plugin' ) . ']</p>';
			} else if ( 0 == $result->post_id ) {
				$post_title = '<p class="anno_error">[' . __( 'Error when reading from database', 'annotation-plugin' ) 
					. ']</p>';
			} else {
				$post = get_post( $result->post_id );
				$post_title = $post->post_title;
				$guid = $post->guid;
			}
			
			// add 'li' for each relation
			echo '<li itemscope itemtype="http://schema.org/Article">
					<a href="' . $guid . '" itemprop="url">
						<span itemprop="name">' . $post_title . '</span>
					</a></li>';
		}
		
		echo '</ul></p></div>';
	
	}
	
	/**
	 * Creates edit annotation page for a specific name.
	 * 
	 * @param array $annotations
	 */
	function get_edit_annotation_page( $annotations ) {
		wp_enqueue_style( 'annotation-stylesheet', plugins_url( 'css/annotations.css', __FILE__ ) );
		
		// make sure user has right to edit posts and annotations
		if ( !current_user_can( 'edit_posts' ) ) {
			wp_die( __( 'You do not have sufficient permissions to access this page.', 'annotation-plugin' ) );
		}
		
		foreach ( $annotations as $result ) {
			if ( urldecode( $_GET['edit'] ) === $result->id ) {
				$annotation = $result;
			}
		}
		
		if ( isset( $_GET['save'] ) && 'true' == $_GET['save'] ) {
			check_ajax_referer( 'save', 'save_nonce' );
			
			global $wpdb;
			global $annotation_db;
			
			$update_data = array(
				'type' => stripslashes( $_POST['type'] ),
				'image' => stripslashes( $_POST['image_url'] ),
				'url' => stripslashes( $_POST['url'] ),
				'description' => stripslashes( $_POST['description'] )
			);
			
			$wpdb->update( 
				$annotation_db, $update_data, array( 'id' => $_POST['id'] )
			);
			
			// echo meta to refresh page
			echo '<meta http-equiv="refresh" 
					content="0; url=' . str_replace( '&save=true', '&save=done', $_SERVER['REQUEST_URI'] ) . '">'; 
			
		} else {
			wp_enqueue_style( 'annotation-stylesheet', plugins_url( 'css/annotations.css', __FILE__ ) );
			
			// show message if annotation was saved
			if ( isset( $_GET['save'] ) && 'done' == $_GET['save'] ) {
				echo '<div class="updated"><p>' . __( 'Saved annotation', 'annotation-plugin' ) . '</p></div><br>';
			}
		
			// display heading and image (if available)
			echo '<h2 id="anno_edit">' . stripslashes( $annotation->name )  . '</h2>';
			
			if ( '' !== $annotation->image ) {
				echo '<img src="' . $annotation->image . '" alt="' 
					. __( 'No picture available', 'annotation-plugin' ) . '" class="anno_img">';
			}
			
			// display edit form
			echo '<form id="save" action="' . $_SERVER['REQUEST_URI'] . '&save=true" method ="post">';
			echo '<table class="annotation_details">';
			
			// [type]
			echo '<tr>' . 
					'<td>' . __( 'Type', 'annotation-plugin' ) . '</td>' . 
					'<td><input type="text" name="type" placeholder="' . __( 'Please add a type', 'annotation-plugin' ) . 
						'" value="' . $annotation->type . '"></td>' .
				'</tr>';
			
			// [URL]
			echo '<tr>' .
					'<td>' . __( 'URL', 'annotation-plugin' ) . '</td>' .
					'<td><input type="url" size="110" name="url" placeholder="' . __( 'Please add a URL', 'annotation-plugin' ) . 
						'" value="' . $annotation->url . '"></td>' . 
				'</tr>';
			
			// [image URL]
			echo '<tr>' . 
					'<td>' . __( 'Image URL', 'annotation-plugin' ) . '</td>' . 
					'<td><input type="url" size="110" name="image_url" 
						placeholder="' . __( 'Please add an image URL', 'annotation-plugin' ) . '"  
						value="' . $annotation->image . '"></td>' . 
				'</tr>';
			
			// [description]
			echo '<tr>' . 
					'<td class="anno_valign-middle">' . __( 'Description', 'annotation-plugin' ) . '</td>' . 
					'<td><textarea type="text" form="save" name="description" wrap="hard" rows="10" cols="110" 
						placeholder="' . __( 'Please add a description', 'annotation-plugin' ) . '">' .  
							$annotation->description . '</textarea></td>' . 
				'</tr>';
			
			// display list of posts
			echo '<tr>' .
					'<td>' . __( 'Posts', 'annotation-plugin' ) . '</td>' . 
					'<td><ul class="anno_inner_list">';
					
			global $wpdb;
			global $annotation_rel_db;
			$relations = $wpdb->get_results( $wpdb->prepare( 
				"
				SELECT post_id 
				FROM $annotation_rel_db 
				WHERE anno_id = %s
				"
			, $annotation->id ) );
			
			if ( empty( $relations ) ) {
				_e( 'No posts found with this annotation.', 'annotation-plugin' );
			}
			
			// add 'li' for each annotation
			foreach ( $relations as $result ) {
				$guid = '';
				
				// deal with database errors
				if ( -1 == $result->post_id ) {
					$post_title = '<p class="anno_error">[' . __('Post does not exist', 'annotation-plugin' ) . ']</p>';
				} else if ( 0 == $result->post_id ) {
					$post_title = '<p class="anno_error">[' . __( 'Error when reading from database', 'annotation-plugin' ) 
						. ']</p>';
				} else {
					$post = get_post( $result->post_id );
					$post_title = $post->post_title;
					$guid = $post->guid;
				}
				
				echo '<li><a href="' . $guid . '">' . $post_title . '</a></li>'; 
				
			}
			
			echo 	'</ul></td>' . 
				'</tr>' . 
			'</table>';
			
			// hidden input needed for form submission
			echo '<input hidden type="text" name="back" value="' . $_SERVER['REQUEST_URI'] . '">';
			echo '<input hidden type="text" name="id" value="' . $annotation->id . '">';
			wp_nonce_field( 'save', 'save_nonce' );
			
			// save button
			echo '<input class="anno_custom_button" type="submit" value="' . __( 'Save', 'annotation-plugin' ) . '" form="save">';
			
			echo '</form>';
			
			// show option to delete annotation
			echo '<form action="' . $_SERVER['REQUEST_URI'] . '">';
			echo '<input hidden type="text" name="page" value="annotations">';
			echo '<button id="anno_delete" class="anno_custom_button">' .  __( 'Delete', 'annotation-plugin' ) . '</button>';
			echo '<input type="checkbox" class="anno" value="' . $annotation->id . '" required="required">' 
				. __( 'Delete this annotation', 'annotation-plugin' ) . '?';
			echo '</form>';
		}
	}
	
	/**
	 * Displays the results for an annotation search.
	 * 
	 * @param array $annotations
	 * @param string $url
	 */
	function get_search_annotation_page( $annotations, $url ) {
		// display title
		echo '<h2>' . __( 'Search results for:', 'annotation-plugin' ) . ' ' . $_GET['search'] . '</h2>';
		echo '<ul>';
		
		if ( empty( $annotations ) ) {
			echo '<p>' . __( 'No annotations found!', 'annotation-plugin' ) . '</p>';
		} else {
			// display list of annotations
			foreach ( $annotations as $annotation ) {
				echo '<li><a href="'; 
					if ( is_page( 'annotations' ) ) { 
						echo get_site_url() . '/annotations/' . urlencode( $annotation->name ); 
					} else {
						echo $url . 'edit=' . urlencode( $annotation->id );
					} 
				echo '">' . $annotation->name . '</a></li>'; 				
			}
		}
		
		echo '</ul>';
		
	}
	
	/**
	 * Adds list of annotations to $content that is passed in.
	 * 
	 * @param string $content
	 * @return string $content String containing content with an annotation list added at the end.
	 */
	function add_annotation_list( $content ) {
		global $wpdb;
		global $annotation_db;
		global $annotation_rel_db;
		global $post;
		
		if ( isset( $post->ID ) ) {
			$post_id = $post->ID;
			
			// get all annotations that fit to the current post_id
			$annotations = $wpdb->get_results( $wpdb->prepare(
				"
				SELECT a.name 
				FROM $annotation_db a
				INNER JOIN $annotation_rel_db r 
				ON a.id = r.anno_id
				WHERE r.post_id = %d
				"
			, $post_id ) );
			
			$options = get_option( $this->option_name );
			
			// display list of annotations if the option is selected and annotations were found
			if ( ! empty( $annotations ) && isset( $options['display_annotations'] ) ) {
				wp_enqueue_style( 'annotation-stylesheet', plugins_url( 'css/annotations.css', __FILE__ ) );
				
				$content .= '<br>';
				$content .= '<h3>' . __( 'Annotations in this post', 'annotation-plugin' ) . '</h3>
					<ul>';
				
				foreach ( $annotations as $annotation ) { 
					$content .= '<li><a href="' . get_site_url() . '/annotations/' . urlencode( $annotation->name ) 
						. '">' . $annotation->name . '</a></li>';
				}
				
				$content .= '</ul>';
				
			}
		}
		return $content;
	}
}

// Initialize plugin
$Annotation_Plugin = new Annotation_Plugin();

?>