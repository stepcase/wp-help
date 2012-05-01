<?php
/*
Plugin Name: WP Help
Description: Administrators can create detailed, hierarchical documentation for the site's authors and editors, viewable in the WordPress admin.
Version: 0.4-beta-1
License: GPL
Plugin URI: http://txfx.net/wordpress-plugins/wp-help/
Author: Mark Jaquith
Author URI: http://coveredwebservices.com/
Text Domain: wp-help

==========================================================================

Copyright 2011-2012  Mark Jaquith

This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation; either version 2 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program; if not, write to the Free Software
Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
*/

class CWS_WP_Help_Plugin {
	public static $instance;
	private $options;
	private $admin_base = '';
	const default_doc = 'cws_wp_help_default_doc';
	const OPTION = 'cws_wp_help';
	const MENU_SLUG = 'wp-help-documents';

	public function __construct() {
		self::$instance = $this;
		add_action( 'init', array( $this, 'init' ) );
	}

	public function init() {
		// Options
		$this->options = get_option( self::OPTION );
		if ( !$this->options ) {
			add_option( self::OPTION, array(
				'h2' => _x( 'Publishing Help', 'h2 default title', 'wp-help' ),
				'h3' => _x( 'Help Topics', 'h3 default title', 'wp-help' ),
				'key' => md5( wp_generate_password( 128, true, true ) ),
			) );
		}

		// Translations
		load_plugin_textdomain( 'wp-help', false, basename( dirname( __FILE__ ) ) . '/languages' );

		// Actions and filters
		add_action( 'admin_menu', array( $this, 'admin_menu' ) );
		add_action( 'do_meta_boxes', array( $this, 'do_meta_boxes' ), 20, 2 );
		add_action( 'save_post', array( $this, 'save_post' ) );
		add_filter( 'post_type_link', array( $this, 'page_link' ), 10, 2 );
		add_filter( 'post_updated_messages', array( $this, 'post_updated_messages' ) );
		add_action( 'admin_init', array( $this, 'ajax_listener' ) );
		add_action( 'wp_ajax_cws_wp_help_settings', array( $this, 'ajax_settings' ) );
		if ( 'dashboard-submenu' != $this->get_option( 'menu_location' ) ) {
			$this->admin_base = 'admin.php';
			if ( 'bottom' != $this->get_option( 'menu_location' ) ) {
				add_filter( 'custom_menu_order', '__return_true' );
				add_filter( 'menu_order', array( $this, 'menu_order' ) );
			}
		} else {
			$this->admin_base = 'index.php';
		}

		// Register the wp-help post type
		register_post_type( 'wp-help',
			array(
				'label' => _x( 'Publishing Help', 'post type label', 'wp-help' ),
				'public' => false,
				'show_ui' => true,
				'show_in_menu' => false,
				'hierarchical' => true,
				'supports' => array( 'title', 'editor', 'revisions', 'page-attributes' ),
				'capabilities' => array(
					'publish_posts' => 'manage_options',
					'edit_posts' => 'manage_options',
					'edit_others_posts' => 'manage_options',
					'delete_posts' => 'manage_options',
					'read_private_posts' => 'manage_options',
					'edit_post' => 'manage_options',
					'delete_post' => 'manage_options',
					'read_post' => 'read'
				),
				'labels' => array (
					'name' => __( 'Help Documents', 'wp-help' ),
					'singular_name' => __( 'Help Document', 'wp-help' ),
					'add_new' => _x( 'Add New', 'i.e. Add new Help Document', 'wp-help' ),
					'add_new_item' => __( 'Add New Help Document', 'wp-help' ),
					'edit' => _x( 'Edit', 'i.e. Edit Help Document', 'wp-help' ),
					'edit_item' => __( 'Edit Help Document', 'wp-help' ),
					'new_item' => __( 'New Help Document', 'wp-help' ),
					'view' => _x( 'View', 'i.e. View Help Document', 'wp-help' ),
					'view_item' => __( 'View Help Document', 'wp-help' ),
					'search_items' => __( 'Search Documents', 'wp-help' ),
					'not_found' => __( 'No Help Documents Found', 'wp-help' ),
					'not_found_in_trash' => __( 'No Help Documents found in Trash', 'wp-help' ),
					'parent' => __( 'Parent Help Document', 'wp-help' )
				)
			)
		);

		// Check for API requests
		if ( isset( $_REQUEST['wp-help-key'] ) && $this->get_option( 'key' ) == $_REQUEST['wp-help-key'] )
			$this->api_request();
	}

	private function api_slurp(){
		// WORK IN PROGRESS
		$result = wp_remote_get( $this->get_option( 'slurp_url' ) );
		if ( $result['response']['code'] == 200 ) {
			// Yay
			// To do: process the result
			// To do: ask only for things that have changed (maybe?)
			// get_post_by_guid() for helping to stitch post_parents together
		}
	}

	private function api_request() {
		die( json_encode( $this->get_topics_for_api() ) );
	}

	private function get_post_by_guid( $guid ) {
		global $wpdb;
		return $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM $wpdb->posts WHERE guid = %s LIMIT 1;", $guid ) );
	}

	private function get_topics_for_api() {
		// To do: convert local doc links into GUID shortcodes that the slurping site can process
		$topics = new WP_Query( array( 'post_type' => 'wp-help', 'posts_per_page' => -1, 'post_status' => 'publish' ) );
		$id_to_guid = array();
		foreach ( $topics->posts as $k => $post ) {
			$id_to_guid[$post->ID] = $post->guid;
		}
		foreach ( $topics->posts as $k => $post ) {
			$c =& $topics->posts[$k];
			if ( $post->post_parent )
				$c->post_parent_guid = $id_to_guid[$post->post_parent];
			unset( $c->post_parent, $c->post_author, $c->comment_count, $c->post_mime_type, $c->post_status, $c->post_type, $c->pinged, $c->to_ping, $c->menu_order, $c->filter, $c->ping_status, $c->comment_status, $c->post_password );
		}
		return $topics->posts;
	}

	public function menu_order( $menu ) {
		$custom_order = array();
		foreach ( $menu as $index => $item ) {
			if ( 'index.php' == $item ) {
				if ( 'below-dashboard' == $this->get_option( 'menu_location' ) )
					$custom_order[] = 'index.php';
				$custom_order[] = self::MENU_SLUG;
				if ( 'above-dashboard' == $this->get_option( 'menu_location' ) )
					$custom_order[] = 'index.php';
			} elseif ( self::MENU_SLUG != $item ) {
				$custom_order[] = $item;
			}
		}
		return $custom_order;
	}

	private function get_option( $key ) {
		if ( 'menu_location' == $key && isset( $_GET['wp-help-preview-menu-location'] ) )
			return $_GET['wp-help-preview-menu-location'];
		return isset( $this->options[$key] ) ? $this->options[$key] : false;
	}

	private function update_options( $options ) {
		$this->options = $options;
		update_option( self::OPTION, $options );
	}

	private function api_url() {
		return home_url( '/?wp-help-key=' . $this->get_option( 'key' ) );
	}

	public function ajax_settings() {
		if ( isset( $_POST['nonce'] ) && wp_verify_nonce( $_POST['nonce'], 'cws-wp-help-settings' ) ) {
			$error = false;
			$old_menu_location = $this->options['menu_location'];
			$this->options['h2'] = stripslashes( $_POST['h2'] );
			$this->options['h3'] = stripslashes( $_POST['h3'] );
			$slurp_url = stripslashes( $_POST['slurp_url'] );
			if ( $slurp_url === $this->api_url() )
				$error = __( 'What are you doing? You&#8217;re going to create an infinite loop!' );
			elseif ( empty( $slurp_url ) )
				$this->options['slurp_url'] = '';
			elseif ( strpos( $slurp_url, '?wp-help-key=' ) === false )
				$error = __( 'That is not a WP Help URL. Make sure you copied it correctly.' );
			else
				$this->options['slurp_url'] = esc_url_raw( $slurp_url );
			if ( !$error )
				$this->options['menu_location'] = stripslashes( $_POST['menu_location'] );
			$this->update_options( $this->options );
			$result = array( 
				'slurp_url' => $this->options['slurp_url'],
				'error' => $error
			);
			die( json_encode( $result ) );
		} else {
			die( '-1' );
		}
	}

	public function ajax_listener() {
		if ( !defined( 'DOING_AJAX' ) || !DOING_AJAX || !isset( $_POST['action'] ) || 'wp-link-ajax' != $_POST['action'] )
			return;
		// It's the right kind of request
		// Now to see if it originated from our post type
		$qs = parse_url( $_SERVER['HTTP_REFERER'], PHP_URL_QUERY );
		wp_parse_str( $qs, $vars );
		if ( isset( $vars['post_type'] ) ) {
			$post_type = $vars['post_type'];
		} elseif ( isset( $vars['post'] ) ) {
			$post = get_post( $vars['post'] );
			$post_type = $post->post_type;
		} else {
			// Can't determine post type. Bail.
			return;
		}
		if ( 'wp-help' == $post_type ) {
			// Nice! This originated from our post type
			// Now we make our post type public, and initiate a query filter
			// There really should be a better way to do this. :-\
			add_filter( 'pre_get_posts', array( $this, 'only_query_help_docs' ) );
			global $wp_post_types;
			$wp_post_types['wp-help']->publicly_queryable = $wp_post_types['wp-help']->public = true;
		}
	}

	public function only_query_help_docs( $q ) {
		$q->set( 'post_type', 'wp-help' );
	}

	public function admin_menu() {
		if ( 'dashboard-submenu' != $this->get_option( 'menu_location' ) )
			$hook = add_menu_page( $this->get_option( 'h2' ), $this->get_option( 'h2' ), 'publish_posts', self::MENU_SLUG, array( $this, 'render_listing_page' ), plugin_dir_url( __FILE__ ) . '/images/icon-16.png' );
		else
			$hook = add_dashboard_page( $this->get_option( 'h2' ), $this->get_option( 'h2' ), 'publish_posts', self::MENU_SLUG, array( $this, 'render_listing_page' ) );
		add_action( "load-{$hook}", array( $this, 'enqueue' ) );
	}

	public function do_meta_boxes( $page, $context ) {
		if ( 'wp-help' == $page && 'side' == $context )
			add_meta_box( 'cws-wp-help-meta', _x( 'WP Help Options', 'meta box title', 'wp-help' ), array( $this, 'meta_box' ), $page, 'side' );
	}

	public function meta_box() {
		global $post;
		wp_nonce_field( 'cws-wp-help-save', '_cws_wp_help_nonce', false, true ); ?>
		<p><input type="checkbox" name="cws_wp_help_make_default_doc" id="cws_wp_help_make_default_doc" <?php checked( $post->ID == get_option( self::default_doc ) ); ?> /> <label for="cws_wp_help_make_default_doc"><?php _e( 'Make this the default help document', 'wp-help' ); ?></label></p>
		<?php
	}

	public function save_post( $post_id ) {
		if ( isset( $_POST['_cws_wp_help_nonce'] ) && wp_verify_nonce( $_POST['_cws_wp_help_nonce'], 'cws-wp-help-save' ) ) {
			if ( isset( $_POST['cws_wp_help_make_default_doc'] ) ) {
				// Make it the default_doc
				update_option( self::default_doc, absint( $post_id ) );
			} elseif ( $post_id == get_option( self::default_doc ) ) {
				// Unset
				update_option( self::default_doc, 0 );
			}
		}
		return $post_id;
	}

	public function post_updated_messages( $messages ) {
		global $post_ID, $post;
		$permalink = get_permalink( $post_ID );
		$messages['wp-help'] = array(
			 0 => '', // Unused. Messages start at index 1.
			 1 => sprintf( __( 'Document updated. <a href="%s">View document</a>', 'wp-help' ), esc_url( $permalink ) ),
			 2 => __( 'Custom field updated.', 'wp-help' ),
			 3 => __( 'Custom field deleted.', 'wp-help' ),
			 4 => __( 'Document updated.', 'wp-help' ),
			 5 => isset( $_GET['revision'] ) ? sprintf( __( 'Document restored to revision from %s', 'wp-help' ), wp_post_revision_title( (int) $_GET['revision'], false ) ) : false,
			 6 => sprintf( __( 'Document published. <a href="%s">View document</a>', 'wp-help' ), esc_url( $permalink ) ),
			 7 => __( 'Document saved.', 'wp-help' ),
			 8 => sprintf( __( 'Document submitted. <a target="_blank" href="%s">Preview document</a>', 'wp-help' ), esc_url( add_query_arg( 'preview', 'true', $permalink ) ) ),
			 9 => sprintf( __( 'Document scheduled for: <strong>%1$s</strong>. <a target="_blank" href="%2$s">Preview document</a>', 'wp-help' ), date_i18n( __( 'M j, Y @ G:i', 'wp-help' ), strtotime( $post->post_date ) ), esc_url( $permalink ) ),
			10 => sprintf( __('Document draft updated. <a target="_blank" href="%s">Preview document</a>', 'wp-help' ), esc_url( add_query_arg( 'preview', 'true', $permalink ) ) ),
		);
		return $messages;
	}

	public function enqueue() {
		$suffix = defined ('SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '.dev' : '';
		wp_enqueue_style( 'cws-wp-help', plugins_url( "css/wp-help$suffix.css", __FILE__ ), array(), '20120428b' );
		wp_enqueue_script( 'cws-wp-help', plugins_url( "js/wp-help$suffix.js", __FILE__ ), array( 'jquery' ), '20120430b' );
		do_action( 'cws_wp_help_load' ); // Use this to enqueue your own styles for things like shortcodes.
	}

	public function maybe_just_menu() {
		if ( isset( $_GET['wp-help-preview-menu-location'] ) )
			add_action( 'in_admin_header', array( $this, 'kill_it' ), 0 );
	}

	public function kill_it() {
		exit();
	}

	public function page_link( $link, $post ) {
		$post = get_post( $post );
		if ( 'wp-help' == $post->post_type )
			return admin_url( $this->admin_base . '?page=' . self::MENU_SLUG . '&document=' . absint( $post->ID ) );
		else
			return $link;
	}

	private function get_help_topics_html() {
		return wp_list_pages( array( 'post_type' => 'wp-help', 'hierarchical' => true, 'echo' => false, 'title_li' => '' ) );
	}

	public function render_listing_page() {
		$document_id = absint( isset( $_GET['document'] ) ? $_GET['document'] : get_option( self::default_doc ) );
		if ( $document_id ) : ?>
			<style>
			div#cws-wp-help-listing .page-item-<?php echo $document_id; ?> > a {
				font-weight: bold;
			}
			</style>
		<?php endif; ?>
<div class="wrap">
	<?php screen_icon('wp-help'); ?><div id="cws-wp-help-h2-label-wrap"><input type="text" id="cws-wp-help-h2-label" value="<?php echo esc_attr( $this->get_option( 'h2' ) ); ?>" /></div><h2><?php echo esc_html( $this->get_option( 'h2' ) ); ?></h2>
	<?php include( dirname( __FILE__ ) . '/templates/list-documents.php' ); ?>
</div>
<?php
	}
}

new CWS_WP_Help_Plugin;
