<?php
/*
Plugin Name: Any Parent
Plugin URI: http://innerdvations.com/plugins/any-parent/
Description: Allows you to select any page as a parent page (for example, draft and scheduled pages).
Version: 0.2
Author: Ben Irvin
Author URI: http://innerdvations.com/
Tags: admin, advanced, parent, pages, posts, dropdown, draft, scheduled, future, private
Wordpress URI: http://wordpress.org/extend/plugins/any-parent/
License: GPLv3
Text Domain: anyparenttextdomain
*/

define( 'ANYPARENT_TEXTDOMAIN', 'aastextdomain' );

class AnyParentPlugin {
	private static $singleton;
	
	private $plugin_dir = 'any-parent';
	private $plugin_id = 'anyparent_plugin';
	private $plugin_stylesheet_id = 'anyparent_css';
	private $plugin_script_id = 'anyparent_js';
	private $base;
	
	// exclude these post types because they would make no sense as parent items
	private $exclude_types = array('nav_menu_item','revision');
	
	// exclude 'inherit' status because revisions as parents makes no sense
	private $exclude_statuses = array('inherit');
	
	function __construct( $base='' ) {
		if(self::$singleton) {
			return self::$singleton;
		}
		
		$this->init($base);
		self::$singleton = $this;
		return $this;
	}
	
	function init() {
		// get the base name of the plugin
		$this->base = plugin_basename(__FILE__);
		
		// add translation support
		load_plugin_textdomain(ANYPARENT_TEXTDOMAIN, PLUGINDIR . $this->plugin_dir . '/languages',  $this->plugin_dir . '/languages' );

		// cache our options before doing anything, since everything else depends on them
		$this->options = $this->get_all_options();
		
		// add the options page if this user can manage the options
		if($this->user_has_admin()) {
			add_action('admin_menu', array(&$this,'add_config_menu'));
			add_action('admin_init', array(&$this,'register_settings'));
		}
		
		// modify the links on the plugin page
		add_filter('plugin_action_links',array(&$this,'extra_plugin_links_primary'),10,2);
		add_filter('plugin_row_meta',array(&$this,'extra_plugin_links_secondary'),10,2);
		
		// add filters
		add_filter( 'quick_edit_dropdown_pages_args', array(&$this,'parent_dropdown_filter') );
		add_filter( 'page_attributes_dropdown_pages_args', array(&$this,'parent_dropdown_filter') );
	}
	
	private function user_has_admin() {
		return (is_super_admin() || current_user_can('manage_options'));
	}
	
	public function parent_dropdown_filter($args) {
		$default_statuses = $this->options['default_statuses'];
		$args['post_status'] = $default_statuses;
		$args['page_status'] = $default_statuses;
		$args['status'] = $default_statuses;
		return $args;
	}

	/////////
	//
	// OPTIONS PAGE SECTION
	//
	/////////
	// add the config page to the admin navigation under 'settings'
	public function add_config_menu() {
		$admin_page_title = esc_html__("Any Parent Configuration", ANYPARENT_TEXTDOMAIN);
		$admin_nav_title = esc_html__("Any Parent", ANYPARENT_TEXTDOMAIN);
		add_options_page(esc_html($admin_page_title), esc_html($admin_nav_title), 'manage_options', $this->plugin_id, array($this,'options_page'));
	}
	
	// register settings for options page
	function register_settings(){
		register_setting( $this->plugin_id, $this->plugin_id, array($this,'plugin_options_validate') );
		$section = 'plugin_defaults';
		add_settings_section($section, esc_html__('Defaults', ANYPARENT_TEXTDOMAIN), array($this,'anyparent_defaults'), $this->plugin_id);
		
		$cb_check = array($this,'input_checkbox');
		$cb_check_checked = array($this,'input_checkbox_checked');
		$cb_text = array($this,'input_textfield');
		
		$possible_statuses = $this->get_post_statuses();
		
		$default_statuses = $this->options['default_statuses'];
		
		// BEGIN "Defaults" section
			foreach($possible_statuses as $status=>$status_name) {
				$field_id = "status_{$status}";
				$check_to_use = $cb_check;
				if(in_array($status,$default_statuses)) {
					$check_to_use = $cb_check_checked;
				}
				add_settings_field($field_id, sprintf(esc_html__("Post status: %s", ANYPARENT_TEXTDOMAIN),$status_name), $check_to_use, $this->plugin_id, $section, array('id'=>$field_id));
			}
			// add_settings_field('default_own_post_type',__('Same post type', ANYPARENT_TEXTDOMAIN), $cb_check, $this->plugin_id, $section, array('id'=>'default_own_post_type'));
			$types = get_post_types();
			foreach($types as $type=>$type_obj) {
				if(!in_array($type,$this->exclude_types)) {
					// TODO: add post type options, so a post type can be included as a parent of a different post type
				}
			}
			// TODO: add "default parent id" text field
			// TODO: add 'include' and 'exclude' text fields, in case you just want a couple specific posts included or excluded
			// TODO: support 'post_type' on a per-post-type basis, so that one type of post can be parent of another type of post.  once that is done, add support for 'child_of'.
		// END "Defaults section"
		
		// TODO: take all of the above defaults section, and loop it for every post type, so settings can be set on a per-post-type basis
		
	}
	function input_checkbox_checked($args) {
		return $this->input_checkbox($args, true);
	}
	function input_checkbox($args, $default_checked=false) {
		$id = $args['id'];
		$name = $this->plugin_id;
		$checked = ' ';
		if(isset($this->options[$id])) {
			$checked = ($this->options[$id] ? " checked='checked' " : ' ');
		}
		else {
			$checked = ($default_checked ? " checked='checked' " : ' ');
		}
		
		// two checkboxes are created so that even if the checkbox is unchecked (and therefore not POSTed) we still get the hidden field with a value of 0.
		echo "<input id='{$name}_{$id}' name='{$name}[{$id}]' type='hidden' {$checked} value='0' />";
		echo "<input id='{$name}_{$id}' name='{$name}[{$id}]' type='checkbox' {$checked} value='1' />";
	}
	function input_textfield($args) {
		$id = $args['id'];
		$name = $this->plugin_id;
		$value = esc_html($this->options[$id]);
		echo "<input id='{$name}_{$id}' name='{$name}[{$id}]' size='40' type='text' value='{$value}' />";
	}
	
	public function anyparent_defaults() {
		// add warning for old versions without support for this
		$wp_version = get_bloginfo('version');
		if( version_compare($wp_version, '3.3.1', '<')) {
			echo "<p style='color:red;'>" . esc_html__('This plugin only works on WordPress 3.3.1 and higher, due to lack of hooks in previous versions.', ANYPARENT_TEXTDOMAIN) . "</p>";
		}
		
		echo "<p>" . esc_html__('Default items to include as possible parents in dropdown menu.', ANYPARENT_TEXTDOMAIN) . "</p>";		
	}
	
	function get_post_statuses() {
		$possible_statuses = array();
		// can't use get_post_statuses and get_page_statuses because those are hard-coded for some reason
		global $wp_post_statuses;
		if(!empty($wp_post_statuses)) {
			foreach($wp_post_statuses as $ps=>$obj) {
				$possible_statuses[$ps] = $obj->label;
			}
		}
		$possible_statuses = array_diff( $possible_statuses, $this->exclude_statuses );
		return $possible_statuses;
	}
	
	function plugin_options_validate($input) {
		$options = get_option($this->plugin_id);
		
		// rebuild the status fields into an array to store as default_statuses
		$possible_statuses = $this->get_post_statuses();
		
		$default_statuses = array();
		foreach($possible_statuses as $status=>$status_name) {
			$field_id = "status_{$status}";
			if(isset($input[$field_id]) && $input[$field_id] == "1") {
				$default_statuses[] = $status;
			}
		}
		$options['default_statuses'] = $default_statuses;

		if(is_array($input)) {
			foreach($input as $key=>$val) {
				$options[$key] = $val;
			}
		}
		
		return $options;
	}
	
	// return all options, but also, if there were any defaults that weren't
	// already found in the db, update the options to include those new entries.
	// That ensures that we don't have to constantly use isset when working with
	// options, and also allows brand new options added in new versions to have
	// defaults set.
	private function get_all_options() {
		$name = $this->plugin_id;
		$options = get_option($name);
		// set defaults
		$defaults = array(
			//'default_own_post_type' => true,
			'default_statuses' => array('publish', 'draft', 'pending', 'private', 'future'),
			//'default_statuses_post' => array('publish', 'draft', 'pending', 'private', 'future'),
			//'default_statuses_page' => array('publish', 'draft', 'pending', 'private', 'future'),
			//'default_posttypes_post' => array('post'),
			//'default_posttypes_page' => array('page'),
			'version'=>'0.1',
		);
		
		$changed = false;
		foreach($defaults as $name=>$value) {
			if( !isset($options[$name]) ) {
				$options[$name] = $value;
				$changed = true;
			}
		}
		if($changed) {
			update_option($name,$options);
		}
		return $options;
	}
	// admin options page
	function options_page() {
		if(!$this->user_has_admin()) {
			wp_die(esc_html__("You don't have permission to access this page.", ANYPARENT_TEXTDOMAIN));
		}
		$admin_page_title = esc_html__("Any Parent Configuration", ANYPARENT_TEXTDOMAIN);
		?>
		<div id='anyparent-settings'>
		<h2><?php echo $admin_page_title; ?></h2>
		<form action="options.php" method="post">
		<?php settings_fields( $this->plugin_id ); ?>
		<?php do_settings_sections($this->plugin_id ); ?>
		<input name="Submit" type="submit" value="<?php esc_attr_e('Save Changes', ANYPARENT_TEXTDOMAIN); ?>" />
		</form></div>
		<?php
	}
	/////////
	//
	// END OPTIONS PAGE SECTION
	// 
	/////////
	

	public function extra_plugin_links_primary($data, $page) {
		if ( $page == $this->base ) {
			$settings_url = "options-general.php?page=" . $this->plugin_id;
			$data = array_merge($data,array(
				sprintf('<a href="%s">%s</a>',$settings_url, esc_html__('Settings', ANYPARENT_TEXTDOMAIN)),
			));
		}
		return $data;
	}
	public function extra_plugin_links_secondary($data, $page) {
		if ( $page == $this->base ) {
			$settings_url = "options-general.php?page=" . $this->plugin_id;
			$flattr_url = "http://flattr.com/thing/376224/innerdvations-on-Flattr";
			$paypal_url = "https://www.paypal.com/cgi-bin/webscr?business=donate@innerdvations.com&cmd=_donations&currency_code=EUR&item_name=Donation%20for%20Any-Parent%20plugin";
			$data = array_merge($data,array(
				sprintf('<a href="%s" target="_blank">%s</a>',$flattr_url, esc_html__('Flattr', ANYPARENT_TEXTDOMAIN)),
				sprintf('<a href="%s" target="_blank">%s</a>',$paypal_url, esc_html__('Donate', ANYPARENT_TEXTDOMAIN)),
				sprintf('<a href="%s">%s</a>',$settings_url, esc_html__('Settings', ANYPARENT_TEXTDOMAIN)),
			));
		}
		return $data;
	}
}

$anyparent_plugin = null;
function anyparent_init_manager() {
	if(is_admin()) {
		global $anyparent_plugin;
		$anyparent_plugin = new AnyParentPlugin();
	}
}
add_action('init','anyparent_init_manager', 1);