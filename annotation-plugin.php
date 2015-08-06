<?php
/*
Plugin Name: Annotation Plugin
Plugin URI: http://www.apa-it.at
Description: Annotations service
Author: Patrick Schrempf
Version: Alpha
Text Domain: annotation-plugin
Domain Path: languages/
*/

defined( 'ABSPATH' ) or wp_die( __( 'Plugin cannot be accessed correctly!', 'annotation-plugin' ) );
define( 'WPLANG', '' );

class Annotation_Plugin {

	private $option_name = 'annotation-plugin-options';

	/**
	 * Add and register various hooks, actions and filters.
	 */
	function __construct() {
		add_action( 'admin_init', array( $this, 'annotation_settings_init' ) );
	
		add_action( 'admin_menu', array( $this, 'add_pages' ) );

		register_activation_hook( __FILE__, array( $this, 'install' ) );

		add_action( 'init', array( $this, 'plugin_init' ) );
		
		add_action( 'plugins_loaded', array( $this, 'load_textdomain' ) );

		add_filter( 'tiny_mce_before_init', array( $this, 'override_mce_options' ) );
		
		add_action( 'delete_post', array( $this, 'deleteAnnotationRelations' ) );
		
		add_filter( 'template_include', array( $this, 'include_annotations_template' ) );
		
		add_filter( 'the_content', array( $this, 'add_annotation_list' ) );
	}
		
	/**
	 * Complete all necessary installation tasks on plugin activation.
	 */
	function install() {
		$this->createPluginDatabase();
		$this->addMainAnnotationsPage();
	}

	/**
	 * Creates a MYSQL database for the plugin annotations.
	 */
	function createPluginDatabase() {
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
	function addMainAnnotationsPage() {
		global $wpdb;
		
		$results = $wpdb->query(
			"
			SELECT * 
			FROM $wpdb->posts p 
			WHERE p.post_type = 'page' 
			AND p.post_name = 'annotations'
			"
		);
		if ( empty( $results ) ) {
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
		
		//set all necessary constants
		global $plugin_constants;
		$plugin_constants = array(
			'site_url' => get_site_url(),
			'ps_annotate_url' => 'http://apapses5.apa.at:7070/fliptest_tmp/cgi-bin/ps_annotate',
			'annotate_db' => plugins_url( 'annotate_db.php', __FILE__ ),
			'selection_form' => plugins_url( 'templates/selection_form.html', __FILE__ ),
			'button_text' => __( 'Annotate', 'annotation-plugin' ),
			'button_tooltip' => __( 'Annotate', 'annotation-plugin' ),
			'no_text_alert' => __( 'Please enter text to be annotated!', 'annotation-plugin' ),
			'no_annotations_alert' => __( 'No annotations could be found', 'annotation-plugin' ),
			'results_title' => __( 'Annotation results', 'annotation-plugin' ),
			'results_name' => __( 'Name', 'annotation-plugin' ),
			'results_type' => __( 'Type', 'annotation-plugin' ),
			'delete_error' => __( 'Please select annotations to be deleted', 'annotation-plugin' ),
			'delete_confirmation' => __( 'Would you really like to delete these annotations permanently? (This can take a few minutes...)', 'annotation-plugin' ),
			'success' => __('Annotated successfully! Please make sure to save the post.', 'annotation-plugin')
		);
	}
	
	/**
	 * Loads the textdomain for different languagepacks.
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
	 */
	function add_annotate_plugin( $plugin_array ) {
		//enqueue so that variables can be localized
		wp_enqueue_script( 'tinymce', plugins_url( 'js/tinymce-plugin.js', __FILE__ ), array( 'jquery' ) );		
		
		//localize SETTINGS
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
				'skip' => $options['skip']
			) 
		);
		
		//localize CONSTANTS
		global $plugin_constants;
		wp_localize_script(
			'tinymce',
			'CONSTANTS',
			$plugin_constants
		);
		
		//add annotation plugin to plugin array
		$plugin_array['annotate'] = plugins_url( 'js/tinymce-plugin.js', __FILE__ );		
		return $plugin_array;
	}
	
	/**
	 * Registers the 'Annotate' button in the tinymce editor
	 */
	function register_annotate_button( $buttons ) {
		array_push( $buttons, 'separator', 'annotate' );
		return $buttons;
	}

	/**
	 * Adds plugin pages to UI.
	 */
	function add_pages() {
		//add plugin options page
		add_options_page(
			__( 'Annotation Plugin Options', 'annotation-plugin' ), 
			__( 'Annotation Plugin Options', 'annotation-plugin' ), 
			'manage_options', 
			$this->option_name, 
			array( $this, 'options_page' ) 
		);
		
		//add dashboard annotation page
		add_object_page( 
			__( 'Annotations', 'annotation-plugin' ), 
			__( 'Annotations', 'annotation-plugin' ), 
			'publish_posts', 
			'annotations', 
			array( $this, 'annotations_object_page' ), 
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
	 */
	function validate_input( $input ) {
		if( isset( $input['skip'] ) ) {
			$input['skip'] = sanitize_text_field( $input['skip'] );
		}
		return $input;
	}
	
	/**
	 * Displays the options page for the plugin.
	 */
	function options_page() {
		if ( ! current_user_can( 'manage_options' ) )  {
			wp_die( __( 'You do not have sufficient permissions to access this page.', 'annotation-plugin' ) );
		}
		
		//get current options
		$options = get_option( $this->option_name );
		
		//display the options html
		?>
		<div class="wrap">
		<h2><?php _e( 'Annotation Plugin Settings', 'annotation-plugin' ) ?></h2>
		<form method="post" action="options.php">
			<?php settings_fields( $this->option_name ); ?>
			<table class="form-table">
				<!-- [add_links] -->
				<tr valign="top">
					<th scope="row"><?php _e( 'Add links to posts?', 'annotation-plugin' ) ?></th>
					<td>
						<input type='checkbox' name='<?php echo $this->option_name . "[add_links]" ?>' value='yes' 
							<?php if( isset( $options['add_links'] ) ) { checked( 'yes', $options['add_links'] ); } ?> >
						<?php _e( 'Links to annotations will be added to all posts if this is selected.', 'annotation-plugin' ); ?>
					</td>
				</tr>
				
				<!-- [add_microdata] -->
				<tr valign="top">
					<th scope="row"><?php _e( 'Add schema.org microdata?', 'annotation-plugin' ) ?></th>
					<td>
						<input type='checkbox' name='<?php echo $this->option_name . "[add_microdata]" ?>' value='yes' 
							<?php if( isset( $options['add_microdata'] ) ) { checked( 'yes', $options['add_microdata'] ); } ?> >
						<?php _e( 'Microdata will be added to all annotations if this is selected.', 'annotation-plugin' ); ?>
					</td>
				</tr>
				
				<!-- [display_table] -->
				<tr valign="top">
					<th scope="row"><?php _e( 'Show list of annotations below posts?', 'annotation-plugin' ) ?></th>
					<td>
						<input type='checkbox' name='<?php echo $this->option_name . "[display_annotations]" ?>' value='yes' 
							<?php if( isset( $options['display_annotations'] ) ) { checked( 'yes', $options['display_annotations'] ); } ?> >
						<?php _e( 'A brief list of the annotations in the post will be shown below each post.', 'annotation-plugin' ); ?>
					</td>
				</tr>
				
				<!-- [lang] -->
				<tr valign="top">
					<th scope="row"><?php _e( 'Select an annotation language', 'annotation-plugin' ) ?></th>
					<td>
						<select name='<?php echo $this->option_name . "[lang]" ?>'>
							<option value='GER' <?php if( isset( $options['lang'] ) ) { selected( $options['lang'], 'GER' ); } ?>><?php _e( 'German', 'annotation-plugin' ); ?></option>
							<option value='FRA' <?php if( isset( $options['lang'] ) ) { selected( $options['lang'], 'FRA' ); } ?>><?php _e( 'French', 'annotation-plugin' ); ?></option>
						</select>
					</td>
				</tr>
				
				<!-- [skip] -->
				<tr valign="top">
					<th scope="row"><?php _e( 'Enter annotations to skip', 'annotation-plugin' ) ?></th>
					<td>
						<input type="text" size="60" placeholder='<?php _e( 'e.g.', 'annotation-plugin' ); ?> GER:Austria_Presse_Agentur|GER:Deutsche_Presse_Agentur' name='<?php echo $this->option_name . "[skip]" ?>' value='<?php if( isset ( $options['skip'] ) ) { echo $options['skip']; } ?>'>
						<?php _e( '(multiple entries should be separated by "|")', 'annotation-plugin' ); ?>						
					</td>
				</tr>
			</table>
			<?php submit_button(); ?>
		</form>
		</div>
		<?php
	}
	
	/**
	 * Displays the annotations summary page shown in the dashboard.
	 */
	function annotations_object_page() {
		if ( ! current_user_can( 'publish_posts' ) ) {
			wp_die( __( 'You do not have sufficient permissions to access this page.', 'annotation-plugin' ) );
		}
		$this->getAnnotations();
	}
		
	/**
	 * Adds microdata tags to tinymce editor's valid elements.
	 */
	function override_mce_options( $initArray ) {
		$options = 'link[itemprop|href],a[href|itemprop|itemscope|itemtype],span[itemprop|itemscope|itemtype],annotation[id|itemprop|itemscope|itemtype]';
		$initArray['extended_valid_elements'] = $options;
		return $initArray;
	}
	
	/**
	 * Use 'annotations-template.php' for annotations page.
	 */
	function include_annotations_template( $template ) {
		if ( is_page( 'Annotations' ) || $this->is_subpage( 'annotations' ) ) {
			$annotations_template = plugin_dir_path( __FILE__ ) . 'templates/annotations-template.php' ;
			if ( '' != $annotations_template ) {
				$template = $annotations_template;
			}
		}
		return $template;		
	}
	
	/**
	 * Checks if the current page is a subpage of the page with the provided slug.
	 */
	function is_subpage( $slug ) {
		global $post;
		
		if ( isset( $post ) ) {
			$page = get_page_by_path( $slug );
			$anc = get_post_ancestors( $post->ID );
			
			foreach ( $anc as $ancestor ) {
				if( is_page() && $ancestor == $page->ID ) {
					return true;
				}
			}
		}
		
		return false;  // the page is not a subpage of $slug
	}
	
	/**
	 * Deletes all relations to annotations from a post.
	 */
	function deleteAnnotationRelations( $postid ) {
		global $wpdb;
		global $annotation_rel_db;
		$wpdb->delete( $annotation_rel_db, array( 'post_id' => $postid ) );
		return $postid;
	}
	
	/**
	 * Echos an annotations page according to the query.
	 */
	public function getAnnotations() {
		//enqueue script and style for page
		wp_enqueue_script( 'annotation-script', plugins_url( 'js/annotations.js', __FILE__ ), array( 'jquery' ) );
		wp_enqueue_style( 'annotation-stylesheet', plugins_url( 'css/annotations.css', __FILE__ ) );
		
		//localize CONSTANTS
		global $plugin_constants;
		wp_localize_script( 'annotation-script', 'CONSTANTS', $plugin_constants );
		
		$allowed_orders = array(
			'name' => 'a.name',
			'type' => 'a.type',
		);
		
		//check for orderby GET attribute
		if ( isset( $_GET['orderby'] ) && isset( $allowed_orders[ $_GET['orderby'] ] ) ) {
			$order_by = $allowed_orders[ $_GET['orderby'] ];
		} else {
			$order_by = 'a.name';
		}
		
		//check for search GET attribute
		if ( isset( $_GET['search'] ) ) {
			$search_string = '%' . urldecode( $_GET['search'] ) . '%';
		} else {
			$search_string = '%';
		}
		
		//get all annotations from database
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
		
		//set $url to correct link
		if ( is_page( 'annotations' ) || $this->is_subpage('annotations') ) {
			$url = 'annotations?';
		} else {
			$url = 'admin.php?page=annotations&';
		}
		
		//display page
		if ( is_page( 'annotations' ) || ( isset( $_GET['page'] ) && $_GET['page'] == 'annotations' ) ) {
		?>
		<input type='text' id='search' placeholder='<?php _e( 'Search', 'annotation-plugin' ); ?>'>
		<?php } ?>
		<h1 id='title' class='padded'>
			<a href='<?php echo $url ?>'><?php _e( 'Annotations', 'annotation-plugin' ) ?></a>
		</h1>
		<!-- <br> -->
		
		<?php
		if ( empty( $annotations ) ) {
			if ( '%' === $search_string ) {
				?>
				<p class="error"><?php _e( 'Please add annotations to your posts.', 'annotation-plugin' ) ?></p>
				<?php
			} else {
				$this->getSearchAnnotationPage( $annotations, $url );
			}
		} else {
			if ( $this->is_subpage( 'annotations' ) ) {
				$this->getSpecificAnnotationPage( $annotations );
			} else if ( isset( $_GET['search'] ) ) {
				$this->getSearchAnnotationPage( $annotations, $url );
			} else if ( isset( $_GET['edit'] ) ) {
				$this->getEditAnnotationPage( $annotations );
			} else {
				$this->getGeneralAnnotationPage( $annotations, $url );
			}
		}
		?>
		<!-- <hr> -->
		<?php
	}
	
	/**
	 * Creates annotation page containing all annotations.
	 */
	function getGeneralAnnotationPage( $annotations, $url ) {
		//set image source for small triangles
		$img_src = plugins_url( 'img/triangle.jpg', __FILE__ );
		
		//display general annotations in a table
		?>
		<div class='padded'>
		<table id='annotation-table'>
			<tr class='title'>
		<?php
		
		//if current user can edit posts then show checkbox column
		if ( current_user_can( 'edit_posts' ) ) {
			?>
			<th class='input'>
				<input type='checkbox' class='select-all'>
			</th>
			<?php
		}
		
		//display table with annotations for all users
		?>
			<th>
				<?php _e( 'Annotation', 'annotation-plugin' ) ?>
				<a href='<?php echo $url ?> orderby=name'>
					<img src='<?php echo $img_src ?>' alt='sort' class='triangle'>
				</a>
			</th>
		
			<th>
				<?php _e( 'Type', 'annotation-plugin' ) ?>
				<a href='<?php echo $url ?> orderby=type'>
					<img src='<?php echo $img_src ?>' alt='sort' class='triangle'>
				</a>
			</th>
			</tr>
		<?php
		
		//display each annotation in table
		foreach ( $annotations as $result ) {
			?>
			<tr class='annotation'>
			<?php
			if ( current_user_can( 'edit_posts' ) ) {
				?>
				<td class='input'>
					<input type='checkbox' class='anno' value="<?php echo $result->id ?>">
				</td>
				<?php
			}
			?>
				<td>
					<a href='<?php 
						if( is_page( 'annotations' ) ) { 
							echo get_site_url() . '/annotations/' . urlencode( $result->name ); 
						} else {
							echo $url . 'edit=' . urlencode( $result->name );
						}
					?>'>
						<strong><?php echo stripslashes( $result->name ) ?></strong>
					</a>
				</td>
		
				<td><?php echo $result->type ?></td>
			</tr>
			<?php
		}
		?>
		</table></div></div></article>
		<?php
		
		//if current user can edit post show delete button
		if ( current_user_can( 'edit_posts' ) ) {
			?>
			<button id="delete" class="custom_button"><?php _e( 'Delete', 'annotation-plugin' ) ?></button>
			<?php
		}
	}
	
	/**
	 * Creates the information page for a specific annotation.
	 */
	function getSpecificAnnotationPage( $annotations ) {
		//find correct annotation
		foreach ( $annotations as $result ) {
			if( get_the_title() === $result->name ) {
				$annotation = $result;
			}
		}
		
		//display title with or without link
		if ( '' === $annotation->url ) {
			echo '<h2>' . get_the_title() . '</h2>';			
		} else {
			echo '<a href="' . $annotation->url . '"><h2>' . get_the_title() . '</h2></a>';
		}
		
		if ( current_user_can( 'edit_posts' ) ) {
			echo ' (<a href="' . get_site_url() . '/wp-admin/admin.php?page=annotations&edit=' . urlencode( $annotation->name ) . '">Edit</a>)';
		}
		
		//display image if available
		if ( '' !== $annotation->image ) { 
			echo '<img class="anno_img" src="' . $annotation->image 
				. '" alt="' . __( 'No picture available', 'annotation-plugin') . '">';
		}
		
		//display annotation information
		echo '<p>
				- ' . $annotation->type . '
			</p>
			<p>
				'. stripslashes( $annotation->description ) . '
			</p>
			<hr>
			<p>
				<strong>' . __( 'Posts', 'annotation-plugin' ) . ': </strong>
				<br>
				<ul class="inner-list annotation-details">';
		
		//get all relations for this annotation
		global $wpdb;
		global $annotation_rel_db;
		$relations = $wpdb->get_results( $wpdb->prepare( 
			"
			SELECT post_id 
			FROM $annotation_rel_db 
			WHERE anno_id = %s
			"
		, $annotation->id ) );
		
		//get information from each relation
		foreach ( $relations as $result ) {
			$guid = '';
			
			//deal with database errors
			if ( $result->post_id == -1 ) {
				$post_title = '<p class="error">[' + __('Post does not exist', 'annotation-plugin' ) + ']</p>';
			} else if ( $result->post_id == 0 ) {
				$post_title = '<p class="error">[' + __( 'Error when reading from database', 'annotation-plugin' ) + ']</p>';
			} else {
				$post = get_post( $result->post_id );
				$post_title = $post->post_title;
				$guid = $post->guid;
			}
			
			//add 'li' for each relation
			echo '<li><a href="' . $guid . '">' . $post_title . '</a></li>';
		}
		
		echo '</ul></p>';
	
	}
	
	/**
	 * Creates edit annotation page for a specific name.
	 */
	function getEditAnnotationPage( $annotations ) {
		wp_enqueue_style( 'annotation-stylesheet', plugins_url( 'css/annotations.css', __FILE__ ) );
		
		//make sure user has right to edit posts and annotations
		if( !current_user_can( 'edit_posts' ) ) {
			wp_die( __( 'You do not have sufficient permissions to access this page.', 'annotation-plugin' ) );
		}
		
		foreach ( $annotations as $result ) {
			if( urldecode( $_GET['edit'] ) === $result->name ) {
				$annotation = $result;
			}
		}
		
		if( isset( $_GET['save'] ) && 'true' == $_GET['save'] ) {
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
			?> <meta http-equiv="refresh" content="0; url=<?php echo str_replace( '&save=true', '&save=done', $_SERVER['REQUEST_URI'] ); ?>"> <?php
			
		} else {
			if ( isset( $_GET['save'] ) && 'done' == $_GET['save'] ) {
				echo '<div class="updated"><p>' . __( 'Saved annotation', 'annotation-plugin' ) . '</p></div><br>';
			}
		?>
		
		<h2><?php echo stripslashes( $annotation->name ) ?></h2>
		<br><br>
		<?php if( '' !== $annotation->image ) { ?>
		<img src='<?php echo $annotation->image ?>' alt='<?php _e( 'No picture available', 'annotation-plugin' ); ?>' class="anno_img">
		<?php }	?>
		<form id="save" action="<?php echo $_SERVER['REQUEST_URI'] . '&save=true' ?>" method ="post">
		<table class='annotation-details'>
			<tr>
				<td><?php _e( 'Type', 'annotation-plugin' ); ?></td>
				<td><input type="text" name="type" placeholder='<?php _e( 'Please add a type', 'annotation-plugin' ); ?>' value="<?php echo $annotation->type ?>"></td>
			</tr>
			<tr>
				<td><?php _e( 'URL', 'annotation-plugin' ); ?></td>
				<td><input type="url" size="100" name="url" placeholder='<?php _e( 'Please add a URL', 'annotation-plugin' ); ?>' value="<?php echo $annotation->url ?>"></td>
			</tr>
			<tr>
				<td><?php _e( 'Image URL', 'annotation-plugin' ); ?></td>
				<td><input type="url" size="100" name="image_url" placeholder='<?php _e( 'Please add an image URL', 'annotation-plugin' ); ?>' value="<?php echo $annotation->image ?>"></td>
			</tr>
			<tr>
				</tr>
			<tr>
				<td style="vertical-align: middle"><?php _e( 'Description', 'annotation-plugin' ); ?></td>
				<td><textarea type="text" form="save" name="description" wrap="hard" rows="10" cols="100" placeholder="<?php _e( 'Please add a description', 'annotation-plugin' ); ?>"><?php echo $annotation->description ?></textarea></td>
			</tr>
			<tr>
				<td><?php _e( 'Posts', 'annotation-plugin' ); ?></td> 
				<td><ul class='inner-list annotation-details'>
		<?php
		
		global $wpdb;
		global $annotation_rel_db;
		$relations = $wpdb->get_results( $wpdb->prepare( 
			"
			SELECT post_id 
			FROM $annotation_rel_db 
			WHERE anno_id = %s
			"
		, $annotation->id ) );
		
		//add 'li' for each annotation
		foreach ( $relations as $result ) {
			$guid = '';
			
			//deal with database errors
			if ( $result->post_id == -1 ) {
				$post_title = '<p class="error">[' + __('Post does not exist', 'annotation-plugin' ) + ']</p>';
			} else if ( $result->post_id == 0 ) {
				$post_title = '<p class="error">[' + __( 'Error when reading from database', 'annotation-plugin' ) + ']</p>';
			} else {
				$post = get_post( $result->post_id );
				$post_title = $post->post_title;
				$guid = $post->guid;
			}
			?>
				<li><a href='<?php echo $guid ?>'><?php echo $post_title ?></a></li> 
			<?php
		}
		?>
				</ul></td>
			</tr>
		</table>
		<br>
		<input hidden type="text" name="back" value="<?php echo $_SERVER['REQUEST_URI'] ?>">
		<input hidden type="text" name="id" value="<?php echo $annotation->id ?>">
		<input class="custom_button" type="submit" value="<?php _e( 'Save', 'annotation-plugin' ); ?>" form="save">
		</form>
		
		<?php
		}
	}
	
	function getSearchAnnotationPage( $annotations, $url ) {
		?>
		
		<h2><?php echo __( 'Search results for:', 'annotation-plugin' ) . ' ' . str_replace( '%', '', $_GET['search'] ); ?></h2>
		<ul>
		<?php
		if( empty( $annotations ) ) {
			?>
			<p><?php _e( 'No annotations found!', 'annotation-plugin' ); ?></p>
			<?php
		} else {
			foreach ( $annotations as $annotation ) {
				?>
				<li><a href='<?php 
					if( is_page( 'annotations' ) ) { 
						echo get_site_url() . '/annotations/' . urlencode( $annotation->name ); 
					} else {
						echo $url . 'edit=' . urlencode( $annotation->name );
					} 
				?>'><?php echo $annotation->name ?></a>
				</li> 				
				<?php
			}
		}
		?>
		</ul>
		
		<?php
	}
	
	function display_annotation_list( $content ) {
		global $wpdb;
		global $annotation_db;
		global $annotation_rel_db;
		global $post;
		
		if( isset( $post->ID ) ) {
			$post_id = $post->ID;
			
			$annotations = $wpdb->get_results( $wpdb->prepare(
				"
				SELECT * 
				FROM $annotation_db a
				INNER JOIN $annotation_rel_db r 
				ON a.id = r.anno_id
				WHERE r.post_id = %d
				"
			, $post_id ) );
			
			$options = get_option( $this->option_name );
			if( ! empty( $annotations ) && isset( $options['display_annotations'] ) ) {
				wp_enqueue_style( 'annotation-stylesheet', plugins_url( 'css/annotations.css', __FILE__ ) );
				
				echo '<br>';
				echo '<h2>' . __( 'Annotations in this post', 'annotation-plugin' ) . '</h2><ul>';
				
				foreach ( $annotations as $annotation ) { 
					echo '<li><a href="' . get_site_url() . '/annotations/' . urlencode( $annotation->name ) . '">' . $annotation->name . '</a></li>';
				}
				
				echo '</ul>';
				
			}
		}
		return $content;
	}
	
	/**
	 * Adds list of annotations to $content that is passed in.
	 */
	function add_annotation_list( $content ) {
		global $wpdb;
		global $annotation_db;
		global $annotation_rel_db;
		global $post;
		
		if( isset( $post->ID ) ) {
			$post_id = $post->ID;
			
			$annotations = $wpdb->get_results( $wpdb->prepare(
				"
				SELECT * 
				FROM $annotation_db a
				INNER JOIN $annotation_rel_db r 
				ON a.id = r.anno_id
				WHERE r.post_id = %d
				"
			, $post_id ) );
			
			$options = get_option( $this->option_name );
			if( ! empty( $annotations ) && isset( $options['display_annotations'] ) ) {
				wp_enqueue_style( 'annotation-stylesheet', plugins_url( 'css/annotations.css', __FILE__ ) );
				
				$content .= '<br>';
				$content .= '<h2>' . __( 'Annotations in this post', 'annotation-plugin' ) . '</h2>
					<ul>';
				
				foreach ( $annotations as $annotation ) { 
					$content .= '<li><a href="' . get_site_url() . '/annotations/' . urlencode( $annotation->name ) . '">' . $annotation->name . '</a></li>';
				}
				
				$content .= '</ul>';
				
			}
		}
		return $content;
	}
}

//Initialize plugin
$Annotation_Plugin = new Annotation_Plugin();

?>