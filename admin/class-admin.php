<?php
/**
 * The admin-specific functionality of the plugin.
 *
 * @link: http://www.acato.nl
 * @since 2018.1
 *
 * @package    WP_Rest_Cache_Plugin
 * @subpackage WP_Rest_Cache_Plugin/Admin
 */

namespace WP_Rest_Cache_Plugin\Admin;

use WP_Rest_Cache_Plugin\Includes\Caching\Caching;

/**
 * The admin-specific functionality of the plugin.
 *
 * Defines the plugin name, version, and two examples hooks for how to
 * enqueue the admin-specific stylesheet and JavaScript.
 *
 * @package    WP_Rest_Cache_Plugin
 * @subpackage WP_Rest_Cache_Plugin/Admin
 * @author:    Richard Korthuis - Acato <richardkorthuis@acato.nl>
 */
class Admin {


	/**
	 * The ID of this plugin.
	 *
	 * @access private
	 * @var    string $plugin_name The ID of this plugin.
	 */
	private $plugin_name;

	/**
	 * The version of this plugin.
	 *
	 * @access private
	 * @var    string $version The current version of this plugin.
	 */
	private $version;

	/**
	 * Notices to be displayed in the wp-admin.
	 *
	 * @access private
	 * @var    array $notices An array of notices to be displayed in the wp-admin.
	 */
	private $notices;

	/**
	 * Initialize the class and set its properties.
	 *
	 * @param string $plugin_name The name of this plugin.
	 * @param string $version The version of this plugin.
	 */
	public function __construct( $plugin_name, $version ) {
		$this->plugin_name = $plugin_name;
		$this->version     = $version;
		$this->notices     = [];
	}

	/**
	 * Register the stylesheets for the admin area.
	 */
	public function enqueue_styles() {
		wp_enqueue_style( $this->plugin_name, plugin_dir_url( __FILE__ ) . 'css/wp-rest-cache-admin.css', [], $this->version, 'all' );
		if ( 'wp-rest-cache' === filter_input( INPUT_GET, 'page', FILTER_SANITIZE_STRING )
			&& 'clear-cache' === filter_input( INPUT_GET, 'sub', FILTER_SANITIZE_STRING ) ) {
			wp_enqueue_style( 'jquery-ui-progressbar', plugin_dir_url( __FILE__ ) . 'css/jquery-ui.css', [], $this->version, 'all' );
		}
	}

	/**
	 * Register the scripts for the admin area.
	 */
	public function enqueue_scripts() {
		if ( 'wp-rest-cache' === filter_input( INPUT_GET, 'page', FILTER_SANITIZE_STRING )
			&& 'clear-cache' === filter_input( INPUT_GET, 'sub', FILTER_SANITIZE_STRING ) ) {
			wp_enqueue_script( 'jquery-ui-progressbar' );
		}
	}

	/**
	 * Add a new menu item under Settings.
	 */
	public function create_menu() {
		$hook = add_submenu_page(
			'options-general.php',
			'WP REST Cache',
			'WP REST Cache',
			'administrator',
			'wp-rest-cache',
			[
				$this,
				'settings_page',
			]
		);

		add_action( "load-$hook", [ $this, 'add_screen_options' ] );
	}

	/**
	 * Add screen options to the WP Admin
	 */
	public function add_screen_options() {
		$args = [
			'label'   => __( 'Caches', 'wp-rest-cache' ),
			'default' => Includes\API_Caches_Table::ITEMS_PER_PAGE,
			'option'  => 'caches_per_page',
		];
		add_screen_option( 'per_page', $args );
	}

	/**
	 * Set the caches_per_pages screen option.
	 *
	 * @param bool|int $option_value Screen option value. Default false to skip.
	 * @param string   $option The option name.
	 * @param int      $value The number of rows to use.
	 *
	 * @return int
	 */
	public function set_screen_option( $option_value, $option, $value ) {
		if ( 'caches_per_page' === $option ) {
			return $value;
		}

		return $option_value;
	}

	/**
	 * Add a settings link to the plugin on the Plugin admin screen.
	 *
	 * @param array $links An array of plugin action links.
	 *
	 * @return array An array of plugin action links.
	 */
	public function add_plugin_settings_link( $links ) {
		$links[] = '<a href="' .
					admin_url( 'options-general.php?page=wp-rest-cache' ) . '">' .
					__( 'Settings', 'wp-rest-cache' ) . '</a>';

		return $links;
	}

	/**
	 * Register plugin specific settings.
	 */
	public function register_settings() {
		register_setting( 'wp-rest-cache-settings', 'wp_rest_cache_timeout' );
		register_setting( 'wp-rest-cache-settings', 'wp_rest_cache_timeout_interval' );
		register_setting( 'wp-rest-cache-settings', 'wp_rest_cache_regenerate' );
		register_setting( 'wp-rest-cache-settings', 'wp_rest_cache_regenerate_interval' );
		register_setting( 'wp-rest-cache-settings', 'wp_rest_cache_regenerate_number' );
		register_setting( 'wp-rest-cache-settings', 'wp_rest_cache_memcache_used' );
		register_setting( 'wp-rest-cache-settings', 'wp_rest_cache_global_cacheable_request_headers' );
	}

	/**
	 * Display the plugin settings page.
	 */
	public function settings_page() {
		$sub = filter_input( INPUT_GET, 'sub', FILTER_SANITIZE_STRING );
		if ( ! strlen( $sub ) ) {
			$sub = 'settings';
		}
		include_once __DIR__ . '/partials/header.php';
		include_once __DIR__ . '/partials/sub-' . $sub . '.php';
	}

	/**
	 * Add a 'Clear REST cache' button to the wp-admin top bar.
	 */
	public function admin_bar_item() {
		/**
		 * Show or hide the 'Clear REST cache' button in the wp-admin bar.
		 *
		 * Allows to hide (or show under conditions) the 'Clear REST cache button in the wp-admin bar.
		 *
		 * @since 2018.3.1
		 *
		 * @param boolean $show Boolean whether or not the 'Clear REST cache' button should be shown.
		 */
		$show = apply_filters( 'wp_rest_cache/display_clear_cache_button', true );
		if ( true === $show ) {
			global $wp_admin_bar;

			$args = [
				'id'    => 'wp-rest-cache-clear',
				'title' => '<span class="ab-icon"></span>' . __( 'Clear REST cache', 'wp-rest-cache' ),
				'href'  => self::empty_cache_url(),
			];

			$wp_admin_bar->add_menu( $args );
		}
	}

	/**
	 * Create the url to empty the cache.
	 *
	 * @return string The url to empty the cache.
	 */
	public static function empty_cache_url() {
		return wp_nonce_url( admin_url( 'options-general.php?page=wp-rest-cache&sub=clear-cache' ), 'wp_rest_cache_options', 'wp_rest_cache_nonce' );
	}

	/**
	 * Handle the correct actions. I.e. dismiss a notice.
	 */
	public function handle_actions() {
		if ( isset( $_GET['wp_rest_cache_dismiss'] )
			&& check_admin_referer( 'wp-rest-cache-dismiss-notice-' . filter_input( INPUT_GET, 'wp_rest_cache_dismiss' ) )
		) {
			$user_id           = get_current_user_id();
			$dismissed_notices = get_user_meta( $user_id, 'wp_rest_cache_dismissed_notices', true );
			if ( ! is_array( $dismissed_notices ) ) {
				$dismissed_notices = [];
			}
			if ( ! in_array( filter_input( INPUT_GET, 'wp_rest_cache_dismiss' ), $dismissed_notices, true ) ) {
				$dismissed_notices[] = filter_input( INPUT_GET, 'wp_rest_cache_dismiss' );
				update_user_meta( $user_id, 'wp_rest_cache_dismissed_notices', $dismissed_notices );
			}
		}
	}

	/**
	 * Add a notice to the wp-admin.
	 *
	 * @param string $type The type of message (error|warning|success|info).
	 * @param string $message The message to display.
	 * @param mixed  $dismissible Boolean, should the message be dismissible or 'permanent' for permanently dismissible notice.
	 */
	protected function add_notice( $type, $message, $dismissible = true ) {
		$this->notices[ $type ][] = [
			'message'     => $message,
			'dismissible' => $dismissible,
		];
	}

	/**
	 * Check if the MU plugin was created, if not display a warning.
	 */
	public function check_muplugin_existence() {
		if ( ! file_exists( WPMU_PLUGIN_DIR . '/wp-rest-cache.php' ) ) {
			\WP_Rest_Cache_Plugin\Includes\Activator::create_mu_plugin();
			if ( ! file_exists( WPMU_PLUGIN_DIR . '/wp-rest-cache.php' ) ) {

				$from = '<code>' . substr(
					plugin_dir_path( __DIR__ ) . 'sources/wp-rest-cache.php',
					strpos( plugin_dir_path( __DIR__ ), '/wp-content/' )
				) . '</code>';
				$to   = '<code>' . substr(
					WPMU_PLUGIN_DIR . '/wp-rest-cache.php',
					strpos( WPMU_PLUGIN_DIR, '/wp-content/' )
				) . '</code>';

				$this->add_notice(
					'warning',
					sprintf(
						/* translators: %1$s: source-directory, %2$s: target-directory */
						__( 'You are not getting the best caching result! <br/>Please copy %1$s to %2$s', 'wp-rest-cache' ),
						$from,
						$to
					),
					false
				);
			}
		}
	}

	/**
	 * Check if external object caching is being used, if so display a warning for needed Memcache(d) settings.
	 */
	public function check_memcache_ext_object_caching() {
		if ( wp_using_ext_object_cache()
			&& ( class_exists( 'Memcache' ) || class_exists( 'Memcached' ) )
			&& ! Caching::get_instance()->get_memcache_used() ) {
			$this->add_notice(
				'warning',
				__( 'We have detected you are using external object caching. If you are using Memcache(d) as external object cache, please make sure you visit this plugin\'s settings page and check the `Using Memcache(d)` checkbox.', 'wp-rest-cache' ),
				'permanent'
			);
		}
	}

	/**
	 * Display notices (if any) on the Admin dashboard
	 */
	public function display_notices() {
		if ( count( $this->notices ) ) {
			$user_id           = get_current_user_id();
			$dismissed_notices = get_user_meta( $user_id, 'wp_rest_cache_dismissed_notices', true );
			foreach ( $this->notices as $type => $messages ) {
				foreach ( $messages as $message ) {
					if ( ! is_array( $dismissed_notices ) || ! in_array( esc_attr( md5( $message['message'] ) ), $dismissed_notices, true ) ) {
						?>
						<div
							class="notice notice-<?php echo esc_attr( $type ); ?> <?php echo ( true === $message['dismissible'] ) ? 'is-dismissible' : ''; ?>">
							<p><strong>WP REST Cache:</strong> <?php echo esc_html( $message['message'] ); ?>
								<?php if ( 'permanent' === $message['dismissible'] ) : ?>
									<?php
									$url = wp_nonce_url(
										'?wp_rest_cache_dismiss=' . esc_attr( md5( $message['message'] ) ),
										'wp-rest-cache-dismiss-notice-' . esc_attr( md5( $message['message'] ) )
									);
									?>
									<a class="button"
										href="<?php echo esc_attr( $url ); ?>"><?php echo esc_html_e( 'Hide this message', 'wp-rest-cache' ); ?></a>
								<?php endif; ?></p>
						</div>
						<?php
					}
				}
			}
		}
	}

	/**
	 * Unschedule or schedule the cron based on the regenerate setting.
	 *
	 * @param mixed  $old_value The old option value.
	 * @param mixed  $value The new option value.
	 * @param string $option Option name.
	 */
	public function regenerate_updated( $old_value, $value, $option ) {
		if ( '1' === $value ) {
			$wp_rest_cache_regenerate_interval = Caching::get_instance()->get_regenerate_interval();
			wp_schedule_event( time(), $wp_rest_cache_regenerate_interval, 'wp_rest_cache_regenerate_cron' );
		} else {
			wp_clear_scheduled_hook( 'wp_rest_cache_regenerate_cron' );
		}
	}

	/**
	 * Update regenerate interval based on new setting.
	 *
	 * @param mixed  $old_value The old option value.
	 * @param mixed  $value The new option value.
	 * @param string $option Option name.
	 */
	public function regenerate_interval_updated( $old_value, $value, $option ) {
		if ( Caching::get_instance()->should_regenerate() ) {
			wp_clear_scheduled_hook( 'wp_rest_cache_regenerate_cron' );
			wp_schedule_event( time(), $value, 'wp_rest_cache_regenerate_cron' );
		}
	}

	/**
	 * Flush caches in batches. Used through an ajax call from the 'Clear REST Cache' button.
	 */
	public function flush_caches() {
		check_ajax_referer( 'wp_rest_cache_clear_cache_ajax', 'wp_rest_cache_nonce' );

		$caching          = Caching::get_instance();
		$number_of_caches = $caching->get_record_count( 'endpoint' );
		$page             = filter_input( INPUT_POST, 'page', FILTER_VALIDATE_INT );
		$per_page         = get_option( 'posts_per_page' );

		$caches = $caching->get_api_data( 'endpoint', $per_page, $page );
		foreach ( $caches as $cache ) {
			if ( ! $cache['is_active'] ) {
				continue;
			}
			$force = ( 'unknown' === $cache['object_type'] );
			$caching->delete_cache( $cache['cache_key'], $force );
		}

		$result = [
			'percentage' => min( floor( ( ( $page * $per_page ) / $number_of_caches ) * 100 ), 100 ),
		];

		echo wp_json_encode( $result );
		exit;
	}
}
