<?php
/**
 * @package Hello_World
 * @version Alpha
 */
/*
Plugin Name: Annotation Plugin
Plugin URI: http://www.apa-it.at
Description: Annotations service
Author: Patrick Schrempf
Version: Alpha
*/

defined( 'ABSPATH' ) or wp_die( 'Plugin cannot be accessed correctly!' );  /*not sure if needed*/

class Annotation_Plugin {

	/**
	 * Add and register various hooks, actions and filters.
	 */
	function __construct() {
		register_activation_hook( __FILE__, array( $this, 'install' ) );
		
		add_action( 'init', array( $this, 'plugin_init' ) );
		

		add_action( 'admin_menu', array( $this, 'add_pages' ) );
	
		add_filter( 'tiny_mce_before_init', array( $this, 'override_mce_options' ) );
		
		add_action( 'delete_post', array( $this, 'deleteAnnotation' ) );
		
		add_filter( 'template_include', array( $this, 'include_annotations_template' ) );
	}
		
	
	/*---------------------- Installing plugin ----------------------*/
	
	/**
	 * Complete all necessary installation tasks on plugin activation.
	 */
	function install() {
		$this->createPluginDatabase();
		$this->addAnnotationsPage();
	}

	/**
	 * Creates a database for the plugin.
	 */
	function createPluginDatabase() {
		global $wpdb;
		global $annotation_db;
		$annotation_db = $wpdb->prefix . 'annotations';
		
		$charset_collate = $wpdb->get_charset_collate();
		
		$sql = "CREATE TABLE IF NOT EXISTS $annotation_db (
					name VARCHAR(80) NOT NULL,
					post_id INT NOT NULL,
					type VARCHAR(40) NOT NULL,
					PRIMARY KEY  (name, post_id)
				) $charset_collate;";
		
		require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
		dbDelta( $sql );
	}

	/**
	 * Adds a WordPress page for annotations if it does not already exist.
	 */
	function addAnnotationsPage() {
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
				'post_title' => 'Annotations',
				'post_content' => '<em>No annotations available.</em>',
				'post_excerpt' => 'Annotations',				
				'post_status' => 'publish',
				'post_type' => 'page'
			);
			wp_insert_post( $page );
		}
	}


	/*---------------------- Initialising plugin ----------------------*/

	function plugin_init() {
		wp_enqueue_script( 'jquery' );

		$this->init_global_variables();
		$this->init_annotation_service();
	}
	
	function init_global_variables() {
		global $wpdb;
		global $annotation_db;
		$annotation_db = $wpdb->prefix . 'annotations';
	}

	/*---------------------- Adding annnotation service to editor ----------------------*/

	function init_annotation_service() {
		add_filter( 'mce_external_plugins', array( $this, 'add_annotate_button') );
		add_filter( 'mce_buttons', array( $this, 'register_annotate_button') );
	}
	
	function add_annotate_button( $plugin_array ) {
		$plugin_array['annotate'] = plugins_url( 'js/tinymce-plugin.js', __FILE__ );
		return $plugin_array;
	}
	
	function register_annotate_button( $buttons ) {
		array_push( $buttons, 'separator', 'annotate' );
		return $buttons;
	}


	/*---------------------- Adding pages ----------------------*/
	
	/**
	 * Adds various pages to UI.
	 */
	function add_pages() {
		add_options_page( 'Annotation Plugin Options', 'Annotation Plugin Options', 
			'manage_options', 'annotation-plugin-options', array( $this, 'options_page' ) );
		add_action( 'admin-init', array( $this, 'register_annotation_settings' ) );
		
		add_object_page( 'Annotations', 'Annotations', 'publish_posts', 'annotations', 
			array( $this, 'annotations_object_page' ), plugins_url( 'img/apa_small.jpg', __FILE__ ) );
	}
	
	function register_annotation_settings() {
		register_setting( 'annotation_options', 'annotate_url' );
		register_setting( 'annotation_options', 'annotate_date' );
		register_setting( 'annotation_options', 'annotate_email' );
		register_setting( 'annotation_options', 'microdata' );
	}
	
	/**
	 * The options page for the plugin.
	 */
	function options_page() {
		if ( ! current_user_can( 'manage_options' ) )  {
			wp_die( 'You do not have sufficient permissions to access this page.' );
		}
		?>
		<div class="wrap">
		<h2>Annotation Plugin Settings</h2>
		<p>Please select which annotations should be shown.</p>
		<form method="POST" action="options.php">
			<?php settings_fields( 'annotation_options' ); ?>
			<?php do_settings_sections( 'annotation_options' ); ?>
			<table class="form-table">
				<tr valign="top">
				<th scope="row">Annotate URLs?</th>
				<td><input type="checkbox" name="annotate_url" value="1" <?php checked( '1', get_option('annotate_url') ) ?> /></td>
				</tr>
				
				<tr valign="top">
				<th scope="row">Annotate dates?</th>
				<td><input type="checkbox" name="annotate_date" value="2" <?php checked( '2', get_option('annotate_date') ) ?> /></td>
				</tr>
				
				<tr valign="top">
				<th scope="row">Annotate e-mail addresses?</th>
				<td><input type="checkbox" name="annotate_email" value="3" <?php checked( '3', get_option('annotate_email') ) ?> /></td>
				</tr>
				
				<tr valign="top">
				<th scope="row">Add schema.org microdata to annotations?</th>
				<td><input type="checkbox" name="microdata" value="4" <?php checked( '4', get_option('microdata') ) ?> /></td>
				</tr>
			</table>
		    
			<?php submit_button(); ?>
		
		</form>
		</div>
		<?php 
	}
	
	/**
	 * The annotations summary page shown in the dashboard.
	 */
	function annotations_object_page() {
		if ( ! current_user_can( 'publish_posts' ) ) {
			wp_die( 'You do not have sufficient permissions to access this page.' );
		}
		$this->getAnnotations();
	}
		
	/**
	 * Adds microdata tags to tinyMCE editor's valid elements.
	 */
	function override_mce_options( $initArray ) {
		$options = 'div[itemprop|itemscope|itemtype],a[href|itemprop|itemscope|itemtype],span[itemprop|itemscope|itemtype]';
		$initArray['extended_valid_elements'] = $options;
		return $initArray;
	}
	
	/**
	 * Use annotations-template.php for 
	 */
	function include_annotations_template( $template ) {
		if ( is_page( 'Annotations' ) ) {
			$annotations_template = plugin_dir_path( __FILE__ ) . 'templates/annotations-template.php' ;
			if ( '' != $annotations_template ) {
				return $annotations_template;
			}
		}
		return $template;		
	}
	
	/**
	 * Deletes all annotations linked to a post id.
	 */
	function deleteAnnotation( $postid ) {
		global $wpdb;
		global $annotation_db;
		$wpdb->delete( $annotation_db, array( 'post_id' => $postid ) );
		return $postid;
	}
	
	/**
	 * Echos an annotations page according to the query.
	 */
	public function getAnnotations() {
		wp_enqueue_script( 'annotation-script', plugins_url( 'js/annotations.js', __FILE__ ), array( 'jquery' ) );
		wp_enqueue_style( 'annotation-stylesheet', plugins_url( 'css/annotations.css', __FILE__ ) );
		wp_localize_script( 'annotation-script', 'WORDPRESS', array( 'annotate_db' => plugins_url( 'annotate_db.php', __FILE__ ) ) );
		
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
			$search_string = urldecode( $_GET['search'] );
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
		
		if ( is_page( 'annotations' ) ) {
			$url = 'annotations?';
		} else {
			$url = 'admin.php?page=annotations&';
		}
		
		//build page content
		?>
		
		<input type='text' id='search' placeholder='Search'>
		<h1 id='title' class='padded'>
			<a href='<?php echo $url ?>'>Annotations</a>
		</h1>
		<br>
		
		<?php
		if ( empty( $annotations ) ) {
			if ( '%' === $search_string ) {
				?>
				<p class="error">Please add annotations to your posts.</p>
				<?php
			} else {
				?>
				<h2><?php echo $search_string ?></h2>
				<p class='error'>No annotations found.</p>
				<?php
			}
		} else {
			if ( '%' !== $search_string ) {
				$this->getSpecificAnnotationPage( $annotations, $search_string, $url );
			} else {
				$this->getGeneralAnnotationPage( $annotations, $url );
			}
		}
		?>
		<hr>
		<?php
	}
	
	/**
	 * Creates annotation page containing all annotations.
	 */
	function getGeneralAnnotationPage( $annotations, $url ) {
		$img_src = plugins_url( 'img/triangle.jpg', __FILE__ );
		?>
		<div class='padded'>
		<table id='annotation-table'>
			<tr class='title'>
		<?php
		if ( current_user_can( 'edit_posts' ) ) {
			?>
			<th class='input'>
				<input type='checkbox' class='select-all'>
			</th>
			<?php
		}
		?>
			<th>
				Annotation
				<a href='<?php echo $url ?> orderby=name'>
					<img src='<?php echo $img_src ?>' alt='sort' class='triangle'>
				</a>
			</th>
		
			<th>
				Type
				<a href='<?php echo $url ?> orderby=type'>
					<img src='<?php echo $img_src ?>' alt='sort' class='triangle'>
				</a>
			</th>
			</tr>
		<?php
		$names = array();
		foreach ( $annotations as $result ) {
			if ( !in_array( $result->name, $names ) ) {
				?>
				<tr class='annotation'>
				<?php
				if ( current_user_can( 'edit_posts' ) ) {
					?>
					<td class='input'>
						<input type='checkbox' class='anno' value='<?php echo $result->name ?>'>
					</td>
					<?php
				}
				?>
					<td>
						<a href='<?php echo $url ?> search=<?php echo $result->name ?>'>
							<strong><?php echo $result->name ?></strong>
						</a>
					</td>
			
					<td><?php echo $result->type ?></td>
				</tr>
				<?php
				array_push( $names, $result->name );
			}
		}
		?>
		</table></div></div></article>
		<?php
		if ( current_user_can( 'edit_posts' ) ) {
			?>
			<button id="delete">Delete</button>
			<?php
		}
	}
	
	/**
	 * Creates annotation page for a specific search string.
	 */
	function getSpecificAnnotationPage( $annotations, $search_string, $url ) {
		?>
		<h2><?php echo $annotations[0]->name ?></h2>
		<ul class='annotation-details'>
			<li>Type: <?php echo $annotations[0]->type ?></li>
			<li>Posts: 
				<ul class='inner-list annotation-details'>
		
		<?php
		if ( is_page( 'annotations' ) ) {
			$url = 'annotations?';
		} else {
			$url = 'admin.php?page=annotations&';
		}
		
		foreach ( $annotations as $result ) {
			//deal with database errors
			$guid = '';
			if ( $result->post_id == -1 ) {
				$post_title = '<p class="error">[Post does not exist]</p>';
			} else if ( $result->post_id == 0 ) {
				$post_title = '<p class="error">[Error when reading from database]</p>';
			} else {
				$post = get_post($result->post_id);
				$post_title = $post->post_title;
				$guid = $post->guid;
			}
			?>
			<li> <a href='<?php echo $guid ?>'> <?php echo $post_title ?> </a> </li> 
			<?php
		}
		?>
		</ul></li></ul>
		<?php
	}
}

//Initialize plugin
$Annotation_Plugin = new Annotation_Plugin();

?>