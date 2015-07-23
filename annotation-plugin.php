<?php
/*
Plugin Name: Annotation Plugin
Plugin URI: http://www.apa-it.at
Description: Annotations service
Author: Patrick Schrempf
Version: Alpha
Text Domain: APA-IT-projekt
Domain Path: languages/
*/

defined( 'ABSPATH' ) or wp_die( __( 'Plugin cannot be accessed correctly!', 'APA-IT-projekt' ) );
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
				'post_content' => '<em>' + __( 'No annotations available', 'APA-IT-projekt' ) + '.</em>',
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
		global $plugin_constants;
		$plugin_constants = array(
				'ps_annotate_url' => 'http://apapses5.apa.at:7070/fliptest_tmp/cgi-bin/ps_annotate',
				'annotate_db' => plugins_url( 'annotate_db.php', __FILE__ ),
				'selection_form' => plugins_url( 'selection_form.html', __FILE__ ),
				'button_text' => __( 'Annotate', 'APA-IT-projekt' ),
				'button_tooltip' => __( 'Annotate', 'APA-IT-projekt' ),
				'no_text_alert' => __( 'Please enter text to be annotated!', 'APA-IT-projekt' ),
				'no_annotations_alert' => __( 'No annotations could be found', 'APA-IT-projekt' ),
				'results_title' => __( 'Annotation results', 'APA-IT-projekt' ),
				'results_name' => __( 'Name', 'APA-IT-projekt' ),
				'results_type' => __( 'Type', 'APA-IT-projekt' ),
				'delete_error' => __( 'Please select annotations to be deleted', 'APA-IT-projekt' ),
				'delete_confirmation' => __( 'Would you really like to delete these annotations permanently?', 'APA-IT-projekt' )
		);
	}
	
	function load_textdomain() {
		load_plugin_textdomain( 'APA-IT-projekt', false, basename( dirname( __FILE__ ) ) . '/languages/' );
	}

	/*---------------------- Adding annnotation service to editor ----------------------*/

	function init_annotation_service() {
		add_filter( 'mce_external_plugins', array( $this, 'add_annotate_button') );
		add_filter( 'mce_buttons', array( $this, 'register_annotate_button') );
	}
	
	function add_annotate_button( $plugin_array ) {
		wp_enqueue_script( 'tinymce', plugins_url( 'js/tinymce-plugin.js', __FILE__ ), array( 'jquery' ) );		
		$options = get_option( $this->option_name );
		wp_localize_script( 
			'tinymce', 
			'SETTINGS', 
			array(
				'annotate_url' => isset( $options['annotate_url'] ) ? true : false,
				'annotate_date' => isset( $options['annotate_date'] ) ? true : false,
				'annotate_email' => isset( $options['annotate_email'] ) ? true : false,
				'add_microdata' => isset( $options['add_microdata'] ) ? true : false,
				'lang' => $options['lang'],
				'skip' => $options['skip']
			) 
		);
		global $plugin_constants;
		wp_localize_script(
			'tinymce',
			'CONSTANTS',
			$plugin_constants
		);
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
		add_options_page(
			__( 'Annotation Plugin Options', 'APA-IT-projekt'), 
			__( 'Annotation Plugin Options', 'APA-IT-projekt'), 
			'manage_options', 
			$this->option_name, 
			array( $this, 'options_page' ) 
		);
		
		add_object_page( 
			__( 'Annotations', 'APA-IT-projekt'), 
			__( 'Annotations', 'APA-IT-projekt'), 
			'publish_posts', 
			'annotations', 
			array( $this, 'annotations_object_page' ), 
			plugins_url( 'img/apa_small.jpg', __FILE__ ) 
		);
	}
	
	function annotation_settings_init() {
		register_setting( $this->option_name, $this->option_name, array( $this, 'validate_input' ) );
	}
	
	function validate_input( $input ) {
		return $input;
	}
	
	/**
	 * The options page for the plugin.
	 */
	function options_page() {
		if ( ! current_user_can( 'manage_options' ) )  {
			wp_die( __( 'You do not have sufficient permissions to access this page.', 'APA-IT-projekt' ) );
		}
		
		$options = array( 
			'annotate_url' => FALSE,
			'annotate_date' => FALSE,
			'annotate_email' => FALSE,
			'add_microdata' => FALSE 
		);
		
		$options = get_option( $this->option_name );
		?>
		<div class="wrap">
		<h2><?php _e( 'Annotation Plugin Settings', 'APA-IT-projekt' ) ?></h2>
		<form method="post" action="options.php">
			<?php settings_fields( $this->option_name ); ?>
			<table class="form-table">
				<tr valign="top">
					<th scope="row"><?php _e( 'Annotate URLs?', 'APA-IT-projekt' ) ?></th>
					<td>
						<input type='checkbox' name='<?php echo $this->option_name . "[annotate_url]" ?>' value='yes' 
							<?php if( isset( $options['annotate_url'] ) ) { checked( 'yes', $options['annotate_url'] ); } ?> >
						<?php _e( 'URLs will be suggested when annotating.', 'APA-IT-projekt' ); ?>
					</td>
				</tr>
				
				<tr valign="top">
					<th scope="row"><?php _e( 'Annotate dates?', 'APA-IT-projekt' ) ?></th>
					<td>
						<input type='checkbox' name='<?php echo $this->option_name . "[annotate_date]" ?>' value='yes' 
							<?php if( isset( $options['annotate_date'] ) ) { checked( 'yes', $options['annotate_date'] ); } ?> >
						<?php _e( 'Dates will be suggested when annotating.', 'APA-IT-projekt' ); ?>
					</td>
				</tr>
				
				<tr valign="top">
					<th scope="row"><?php _e( 'Annotate email addresses?', 'APA-IT-projekt' ) ?></th>
					<td>
						<input type='checkbox' name='<?php echo $this->option_name . "[annotate_email]" ?>' value='yes' 
							<?php if( isset( $options['annotate_email'] ) ) { checked( 'yes', $options['annotate_email'] ); } ?> >
						<?php _e( 'Email addresses will be suggested when annotating.', 'APA-IT-projekt' ); ?>
					</td>
				</tr>
				
				<tr valign="top">
					<th scope="row"><?php _e( 'Add schema.org microdata?', 'APA-IT-projekt' ) ?></th>
					<td>
						<input type='checkbox' name='<?php echo $this->option_name . "[add_microdata]" ?>' value='yes' 
							<?php if( isset( $options['add_microdata'] ) ) { checked( 'yes', $options['add_microdata'] ); } ?> >
						<?php _e( 'Microdata will be added to all annotations if this is selected.', 'APA-IT-projekt' ); ?>
					</td>
				</tr>
				
				<tr valign="top">
					<th scope="row"><?php _e( 'Select an annotation language', 'APA-IT-projekt' ) ?></th>
					<td>
						<select name='<?php echo $this->option_name . "[lang]" ?>'>
							<option value='GER' <?php if( isset( $options['lang'] ) ) { selected( $options['lang'], 'GER' ); } ?>><?php _e( 'German', 'APA-IT-projekt' ); ?></option>
							<option value='FRA' <?php if( isset( $options['lang'] ) ) { selected( $options['lang'], 'FRA' ); } ?>><?php _e( 'French', 'APA-IT-projekt' ); ?></option>
						</select>
					</td>
				</tr>
				
				<tr valign="top">
					<th scope="row"><?php _e( 'Enter annotations to skip', 'APA-IT-projekt' ) ?></th>
					<td>
						<input type="text" size='75' placeholder='<?php _e( 'e.g.', 'APA-IT-projekt' ); ?> GER:Austria_Presse_Agentur|GER:Deutsche_Presse_Agentur' name='<?php echo $this->option_name . "[skip]" ?>' value='<?php if( isset ( $options['skip'] ) ) { echo $options['skip']; } ?>'>
						<?php _e( '(multiple entries should be separated by "|")', 'APA-IT-projekt' ); ?>						
					</td>
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
			wp_die( __( 'You do not have sufficient permissions to access this page.', 'APA-IT-projekt' ) );
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
			<a href='<?php echo $url ?>'><?php _e( 'Annotations', 'APA-IT-projekt' ) ?></a>
		</h1>
		<br>
		
		<?php
		if ( empty( $annotations ) ) {
			if ( '%' === $search_string ) {
				?>
				<p class="error"><?php _e( 'Please add annotations to your posts.', 'APA-IT-projekt' ) ?></p>
				<?php
			} else {
				?>
				<h2><?php echo $search_string ?></h2>
				<p class='error'><?php _e( 'No annotations found.', 'APA-IT-projekt' ) ?></p>
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
				<?php _e( 'Annotation', 'APA-IT-projekt' ) ?>
				<a href='<?php echo $url ?> orderby=name'>
					<img src='<?php echo $img_src ?>' alt='sort' class='triangle'>
				</a>
			</th>
		
			<th>
				<?php _e( 'Type', 'APA-IT-projekt' ) ?>
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
			<button id="delete"><?php _e( 'Delete', 'APA-IT-projekt' ) ?></button>
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
				$post_title = '<p class="error">[' + __('Post does not exist', 'APA-IT-projekt' ) + ']</p>';
			} else if ( $result->post_id == 0 ) {
				$post_title = '<p class="error">[' + __( 'Error when reading from database', 'APA-IT-projekt' ) + ']</p>';
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