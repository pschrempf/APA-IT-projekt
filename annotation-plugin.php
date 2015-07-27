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
		
		add_action( 'delete_post', array( $this, 'deleteAnnotation' ) );
		
		add_filter( 'template_include', array( $this, 'include_annotations_template' ) );
	}
		
	/**
	 * Complete all necessary installation tasks on plugin activation.
	 */
	function install() {
		$this->createPluginDatabase();
		$this->addAnnotationsPage();
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
		
		//set all necessary constants
		global $plugin_constants;
		$plugin_constants = array(
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
				'delete_confirmation' => __( 'Would you really like to delete these annotations permanently?', 'annotation-plugin' ),
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
			__( 'Annotation Plugin Options', 'annotation-plugin'), 
			__( 'Annotation Plugin Options', 'annotation-plugin'), 
			'manage_options', 
			$this->option_name, 
			array( $this, 'options_page' ) 
		);
		
		//add dashboard annotation page
		add_object_page( 
			__( 'Annotations', 'annotation-plugin'), 
			__( 'Annotations', 'annotation-plugin'), 
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
				<tr valign="top">
					<th scope="row"><?php _e( 'Annotate URLs?', 'annotation-plugin' ) ?></th>
					<td>
						<input type='checkbox' name='<?php echo $this->option_name . "[annotate_url]" ?>' value='yes' 
							<?php if( isset( $options['annotate_url'] ) ) { checked( 'yes', $options['annotate_url'] ); } ?> >
						<?php _e( 'URLs will be suggested when annotating.', 'annotation-plugin' ); ?>
					</td>
				</tr>
				
				<tr valign="top">
					<th scope="row"><?php _e( 'Annotate dates?', 'annotation-plugin' ) ?></th>
					<td>
						<input type='checkbox' name='<?php echo $this->option_name . "[annotate_date]" ?>' value='yes' 
							<?php if( isset( $options['annotate_date'] ) ) { checked( 'yes', $options['annotate_date'] ); } ?> >
						<?php _e( 'Dates will be suggested when annotating.', 'annotation-plugin' ); ?>
					</td>
				</tr>
				
				<tr valign="top">
					<th scope="row"><?php _e( 'Annotate email addresses?', 'annotation-plugin' ) ?></th>
					<td>
						<input type='checkbox' name='<?php echo $this->option_name . "[annotate_email]" ?>' value='yes' 
							<?php if( isset( $options['annotate_email'] ) ) { checked( 'yes', $options['annotate_email'] ); } ?> >
						<?php _e( 'Email addresses will be suggested when annotating.', 'annotation-plugin' ); ?>
					</td>
				</tr>
				
				<tr valign="top">
					<th scope="row"><?php _e( 'Add schema.org microdata?', 'annotation-plugin' ) ?></th>
					<td>
						<input type='checkbox' name='<?php echo $this->option_name . "[add_microdata]" ?>' value='yes' 
							<?php if( isset( $options['add_microdata'] ) ) { checked( 'yes', $options['add_microdata'] ); } ?> >
						<?php _e( 'Microdata will be added to all annotations if this is selected.', 'annotation-plugin' ); ?>
					</td>
				</tr>
				
				<tr valign="top">
					<th scope="row"><?php _e( 'Select an annotation language', 'annotation-plugin' ) ?></th>
					<td>
						<select name='<?php echo $this->option_name . "[lang]" ?>'>
							<option value='GER' <?php if( isset( $options['lang'] ) ) { selected( $options['lang'], 'GER' ); } ?>><?php _e( 'German', 'annotation-plugin' ); ?></option>
							<option value='FRA' <?php if( isset( $options['lang'] ) ) { selected( $options['lang'], 'FRA' ); } ?>><?php _e( 'French', 'annotation-plugin' ); ?></option>
						</select>
					</td>
				</tr>
				
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
		$options = 'div[itemprop|itemscope|itemtype],a[href|itemprop|itemscope|itemtype],span[itemprop|itemscope|itemtype],annotation[id]';
		$initArray['extended_valid_elements'] = $options;
		return $initArray;
	}
	
	/**
	 * Use 'annotations-template.php' for annotations page.
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
		
		//set $url to correct link
		if ( is_page( 'annotations' ) ) {
			$url = 'annotations?';
		} else {
			$url = 'admin.php?page=annotations&';
		}
		
		//display page html
		?>
		<input type='text' id='search' placeholder='Search'>
		<h1 id='title' class='padded'>
			<a href='<?php echo $url ?>'><?php _e( 'Annotations', 'annotation-plugin' ) ?></a>
		</h1>
		<br>
		
		<?php
		if ( empty( $annotations ) ) {
			if ( '%' === $search_string ) {
				?>
				<p class="error"><?php _e( 'Please add annotations to your posts.', 'annotation-plugin' ) ?></p>
				<?php
			} else {
				?>
				<h2><?php echo $search_string ?></h2>
				<p class='error'><?php _e( 'No annotations found.', 'annotation-plugin' ) ?></p>
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
		
		//only display each annotation once in table (no duplicate entries)
		$names = array();
		foreach ( $annotations as $result ) {
			if ( !in_array( $result->name, $names ) ) {
				?>
				<tr class='annotation'>
				<?php
				if ( current_user_can( 'edit_posts' ) ) {
					?>
					<td class='input'>
						<input type='checkbox' class='anno' value="<?php echo $result->name ?>">
					</td>
					<?php
				}
				?>
					<td>
						<a href='<?php echo $url ?> search=<?php echo rawurlencode( $result->name ); ?>'>
							<strong><?php echo stripslashes( $result->name ) ?></strong>
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
		
		//if current user can edit post show delete button
		if ( current_user_can( 'edit_posts' ) ) {
			?>
			<button id="delete"><?php _e( 'Delete', 'annotation-plugin' ) ?></button>
			<?php
		}
	}
	
	/**
	 * Creates annotation page for a specific search string.
	 */
	function getSpecificAnnotationPage( $annotations, $search_string, $url ) {
		?>
		<h2><?php echo stripslashes( $annotations[0]->name ) ?></h2>
		<ul class='annotation-details'>
			<li><?php _e( 'Type', 'annotation-plugin' ); ?>: <?php echo $annotations[0]->type ?></li>
			<li><?php _e( 'Posts', 'annotation-plugin' ); ?>: 
				<ul class='inner-list annotation-details'>
		<?php
		
		//add 'li' for each annotation
		foreach ( $annotations as $result ) {
			$guid = '';
			
			//deal with database errors
			if ( $result->post_id == -1 ) {
				$post_title = '<p class="error">[' + __('Post does not exist', 'annotation-plugin' ) + ']</p>';
			} else if ( $result->post_id == 0 ) {
				$post_title = '<p class="error">[' + __( 'Error when reading from database', 'annotation-plugin' ) + ']</p>';
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