<?php
/*
Plugin Name: Custom CSS Outsourcer
Plugin URI:  https://github.com/custom-css-outsourcer
Description: Loads the additional Customizer CSS from external files instead of printing it directly to the page.
Version:     1.0.0
Author:      Felix Arntz
Author URI:  https://leaves-and-love.net
License:     GNU General Public License v3
License URI: http://www.gnu.org/licenses/gpl-3.0.html
Text Domain: custom-css-outsourcer
Tags:        custom css, customizer, cleanup
*/

defined( 'ABSPATH' ) || exit;

/**
 * Plugin main class.
 *
 * @since 1.0.0
 */
class Custom_CSS_Outsourcer {
	/**
	 * Name of the query var to use.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	const QUERY_VAR = 'custom_css_file';

	/**
	 * Name of the virtual stylesheet file.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	const FILE_NAME = 'custom.css';

	/**
	 * Handle to use for the virtual stylesheet file.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	const FILE_HANDLE = 'wp-custom';

	/**
	 * The main instance of the class.
	 *
	 * @since 1.0.0
	 * @access private
	 * @static
	 * @var Custom_CSS_Outsourcer|null
	 */
	private static $instance = null;

	/**
	 * Adds the necessary hooks for the plugin functionality.
	 *
	 * This method must only be called once.
	 * It will not execute on WordPress versions below 4.7.
	 *
	 * @since 1.0.0
	 * @access public
	 *
	 * @global string $wp_version
	 */
	public function add_hooks() {
		global $wp_version;

		// Bail if we're below 4.7.
		if ( version_compare( $wp_version, '4.7-beta', '<' ) ) {
			return;
		}

		add_action( 'template_redirect', array( $this, 'maybe_outsource_css' ), 1, 0 );
		add_action( 'pre_get_posts', array( $this, 'maybe_print_stylesheet' ), 1, 1 );

		add_action( 'after_setup_theme', array( $this, 'maybe_reduce_query_load' ), 99, 0 );
		add_action( 'init', array( $this, 'add_query_var' ), 1, 0 );
		add_action( 'init', array( $this, 'add_rewrite_rule' ), 1, 0 );

		add_filter( 'redirect_canonical', array( $this, 'fix_canonical' ), 10, 1 );
	}

	/**
	 * Adds hooks to actually outsource CSS if necessary.
	 *
	 * When not in a customizer preview, the Core function to print the styles is unhooked,
	 * and the method to enqueue the virtual stylesheet file is hooked in instead.
	 *
	 * @since 1.0.0
	 * @access public
	 */
	public function maybe_outsource_css() {
		if ( is_customize_preview() ) {
			return;
		}

		remove_action( 'wp_head', 'wp_custom_css_cb', 11 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_stylesheet' ), 11 );
	}

	/**
	 * Enqueues the virtual stylesheet file.
	 *
	 * @since 1.0.0
	 * @access public
	 *
	 * @global WP_Rewrite $wp_rewrite
	 */
	public function enqueue_stylesheet() {
		global $wp_rewrite;

		$url = home_url( ( $wp_rewrite->using_index_permalinks() ? 'index.php/' : '/' ) . self::FILE_NAME );

		wp_enqueue_style( self::FILE_HANDLE, $url );
	}

	/**
	 * Prints the virtual stylesheet file if necessary.
	 *
	 * @since 1.0.0
	 * @access public
	 *
	 * @param WP_Query $query A WordPress query object.
	 */
	public function maybe_print_stylesheet( $query ) {
		if ( ! $query->is_main_query() ) {
			return;
		}

		$active = get_query_var( self::QUERY_VAR );
		if ( ! $active ) {
			return;
		}

		$this->print_stylesheet();
	}

	/**
	 * Prints the virtual stylesheet.
	 *
	 * Its contents are cached using ETag and Last-Modified headers,
	 * based on the current theme and the time the custom CSS was last
	 * modified.
	 *
	 * @since 1.0.0
	 * @access public
	 */
	public function print_stylesheet() {
		$stylesheet = get_stylesheet();

		if ( ! headers_sent() ) {
			// Set content-specific headers.
			header( 'X-Robots-Tag: noindex, follow', true );
			header( 'Content-Type: text/css' );
			header( 'Content-Disposition: inline; filename="' . self::FILE_NAME . '"' );

			// Set cache headers.
			$last_modified = $this->get_last_modified( $stylesheet );
			$gmt = gmdate( 'r', $last_modified );
			$etag = md5( $last_modified . $stylesheet );
			header( 'Cache-Control: public' );
			header( 'ETag: "' . $etag . '"' );
			header( 'Last-Modified: ' . $gmt );

			// Set HTTP status.
			$server_protocol = ( isset( $_SERVER['SERVER_PROTOCOL'] ) && '' !== $_SERVER['SERVER_PROTOCOL'] ) ? sanitize_text_field( $_SERVER['SERVER_PROTOCOL'] ) : 'HTTP/1.1';
			if ( isset( $_SERVER['HTTP_IF_MODIFIED_SINCE'] ) && $_SERVER['HTTP_IF_MODIFIED_SINCE'] == $gmt || isset( $_SERVER['HTTP_IF_NONE_MATCH'] ) && str_replace( '"', '', stripslashes( $_SERVER['HTTP_IF_NONE_MATCH'] ) ) == $etag ) {
				header( $server_protocol . ' 304 Not Modified', true, 304 );
				die();
			} else {
				header( $server_protocol . ' 200 OK', true, 200 );
			}
		}

		$styles = wp_get_custom_css( $stylesheet );
		if ( $styles ) {
			echo strip_tags( $styles );
		}

		remove_all_actions( 'wp_footer' );
		die();
	}

	/**
	 * Clears some actions in case the virtual stylesheet is being requested.
	 *
	 * The goal of this function is to reduce unnecessary overhead.
	 *
	 * @since 1.0.0
	 * @access public
	 */
	public function maybe_reduce_query_load() {
		if ( ! isset( $_SERVER['REQUEST_URI'] ) ) {
			return;
		}

		if ( false === stripos( $_SERVER['REQUEST_URI'], self::FILE_NAME ) ) {
			return;
		}

		remove_all_actions( 'widgets_init' );
	}

	/**
	 * Adds the query var for the virtual stylesheet.
	 *
	 * @since 1.0.0
	 * @access public
	 *
	 * @global WP $wp
	 */
	public function add_query_var() {
		global $wp;

		if ( ! is_object( $wp ) ) {
			return;
		}

		$wp->add_query_var( self::QUERY_VAR );
	}

	/**
	 * Adds the rewrite rule for the virtual stylesheet.
	 *
	 * @since 1.0.0
	 * @access public
	 */
	public function add_rewrite_rule() {
		add_rewrite_rule( str_replace( '.', '\.', self::FILE_NAME ) . '$', 'index.php?' . self::QUERY_VAR . '=1', 'top' );
	}

	/**
	 * Prevents a canonical redirect in case the virtual stylesheet is being requested.
	 *
	 * @since 1.0.0
	 * @access public
	 *
	 * @param string|bool $redirect Canonical to redirect to.
	 * @return string|bool Unmodified canonical, or false to prevent a redirect.
	 */
	public function fix_canonical( $redirect ) {
		$active = get_query_var( self::QUERY_VAR );

		if ( ! $active ) {
			return $redirect;
		}

		return false;
	}

	/**
	 * Returns the last modified GMT timestamp for a given theme.
	 *
	 * @since 1.0.0
	 * @access private
	 *
	 * @param string $stylesheet A theme slug.
	 * @return int The last modified GMT timestamp.
	 */
	private function get_last_modified( $stylesheet ) {
		$post = null;

		if ( function_exists( 'wp_get_custom_css_post' ) ) {
			$post = wp_get_custom_css_post( $stylesheet );
		} else {
			$custom_css_query_vars = array(
				'post_type'              => 'custom_css',
				'post_status'            => get_post_stati(),
				'name'                   => sanitize_title( $stylesheet ),
				'number'                 => 1,
				'no_found_rows'          => true,
				'cache_results'          => true,
				'update_post_meta_cache' => false,
				'update_term_meta_cache' => false,
			);

			if ( get_stylesheet() === $stylesheet ) {
				$post_id = get_theme_mod( 'custom_css_post_id' );
				if ( ! $post_id ) {
					$query = new WP_Query( $custom_css_query_vars );
					$post = $query->post;

					set_theme_mod( 'custom_css_post_id', $post ? $post->ID : -1 );
				} elseif ( $post_id > 0 ) {
					$post = get_post( $post_id );
				}
			} else {
				$query = new WP_Query( $custom_css_query_vars );
				$post = $query->post;
			}
		}

		// If no post exists, return an ancient timestamp.
		if ( ! $post ) {
			return mktime( 0, 0, 0, 1, 1, 2000 );
		}

		return strtotime( $post->post_modified_gmt );
	}

	/**
	 * Returns the main instance of the class.
	 *
	 * It will be instantiated if it does not exist yet.
	 * In case of instantiation, the add_hooks() method will be called.
	 *
	 * @since 1.0.0
	 * @access public
	 * @static
	 *
	 * @return Custom_CSS_Outsourcer The main instance.
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->add_hooks();
		}

		return self::$instance;
	}

	/**
	 * Activates the plugin.
	 *
	 * @since 1.0.0
	 * @access public
	 * @static
	 */
	public static function activate() {
		$plugin = new self();
		$plugin->add_rewrite_rule();

		flush_rewrite_rules();
	}

	/**
	 * Deactivates the plugin.
	 *
	 * @since 1.0.0
	 * @access public
	 * @static
	 */
	public static function deactivate() {
		flush_rewrite_rules();
	}
}
add_action( 'plugins_loaded', array( 'Custom_CSS_Outsourcer', 'instance' ) );

register_activation_hook( __FILE__, array( 'Custom_CSS_Outsourcer', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Custom_CSS_Outsourcer', 'deactivate' ) );
