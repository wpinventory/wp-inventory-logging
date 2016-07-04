<?php

// No direct access allowed.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

Class WPIMLogging extends WPIMCore {

	private static $config_key_name_field = 'enable_logging';
	private static $config_key_name_screen_field = 'enable_logging_to_screen';

	private static $logging_enabled = 0;
	private static $log_to_screen_enabled = 0;

	private static $file_name = 'wpinventory_debug_log.txt';

	private static $wpim_filters = array();
	private static $wpim_actions = array();

	private static $wpim_filter_values = array();

	/**
	 * First call.  Continues only if Inventory Manager is Installed
	 */
	public static function start() {
		// Only initializes this class if WP Inventory Manager is running / loaded
		add_action( 'wpim_core_loaded', array( __CLASS__, 'initialize' ) );
		add_filter( 'wpim_default_config', array( __CLASS__, 'wpim_default_config' ) );
	}

	/**
	 * Initialize the plugin to hook into the relevant actions / filters
	 */
	public static function initialize() {
		add_action( 'wpim_edit_settings_general', array( __CLASS__, 'wpim_edit_settings' ) );
		add_action( 'wpim_admin_menu', array( __CLASS__, 'wpim_admin_menu' ) );

		self::$file_name             = dirname( __FILE__ ) . '/' . self::$file_name;
		self::$logging_enabled       = wpinventory_get_config( self::$config_key_name_field );
		self::$log_to_screen_enabled = wpinventory_get_config( self::$config_key_name_screen_field );

		if ((int)self::$logging_enabled || (int)self::$log_to_screen_enabled) {
			self::parse_actions();
		}
	}

	/**
	 * Logging attempts to watch all actions, and outputs data if it's a WP Inventory action
	 *
	 * @param $value
	 */
	private static function parse_actions() {
		global $wp_filter, $wp_actions;

		foreach ( $wp_filter AS $key => $data ) {
			if ( self::is_wpim_action( $key ) && ! in_array( $key, self::$wpim_filters ) ) {
				self::$wpim_filters[] = $key;
				// Try and run BEFORE and AFTER
				add_filter( $key, array( __CLASS__, 'watch_filter' ), -9999);
				add_filter( $key, array( __CLASS__, 'watch_filter' ), 9999 );
			}
		}

		foreach ( $wp_actions AS $key => $data ) {
			if ( self::is_wpim_action( $key ) && ! in_array( $key, self::$wpim_actions ) ) {
				self::$wpim_actions[] = $key;
				add_action( $key, array( __CLASS__, 'watch_action' ), 9999);
			}
		}
	}

	/**
	 * Detects if the action / filter key is a WP Inventory filter / action
	 *
	 * @param string $key
	 *
	 * @return bool
	 */
	private static function is_wpim_action( $key ) {
		return ( stripos( $key, 'wpim_' ) === 0 || stripos( $key, 'wpinventory_' ) === 0 );
	}

	/**
	 * The function that is invoked for WP Inventory filters.
	 * Observes the filter, and if it's WP Inventory, and outputs the before and after values.
	 *
	 * @param $value
	 *
	 * @return mixed
	 */
	public static function watch_filter( $value ) {
		global $wp_filter, $wp_current_filter;
		$filters = array();
		foreach ( (array) $wp_current_filter AS $filter ) {
			if ( self::is_wpim_action( $filter ) ) {
				$filters[] = $filter;
			}
		}

		foreach ( $filters AS $filter ) {
			if ( ! empty( $wp_filter[ $filter ] ) && count( $wp_filter[ $filter ] ) > 2 ) {
				if ( ! isset(self::$wpim_filter_values[$filter])) {
					self::$wpim_filter_values[$filter] = $value;
				} else {
					$where = debug_backtrace();
					$where = $where[3];
					self::log( array( 'Filter' => $filter, 'Value Before' => self::$wpim_filter_values[$filter], 'Value After' => $value ), $where );
				}
			}
		}

		// We have to return the value for operation to continue
		return $value;
	}

	/**
	 * Function invoked for each action.  Outputs the value when the action is called.
	 *
	 * @param $value
	 */
	public static function watch_action( $value ) {
		global $wp_current_filter;

		$actions = array();
		foreach ( (array) $wp_current_filter AS $action ) {
			if ( self::is_wpim_action( $action ) ) {
				$actions[] = $action;
			}
		}

		foreach ( $actions AS $action ) {
			$where = debug_backtrace();
			$where = $where[3];
			self::log( array( 'Action' => $action, 'Value' => $value ), $where );
		}
	}

	/**
	 * WordPress init action.
	 * Hooks into the breadcrumb actions / filters, if they exist.
	 */
	public static function init() {
	}

	/**
	 * Adds the breadcrumb name field to the default config
	 *
	 * @param array $default
	 *
	 * @return array
	 */
	public static function wpim_default_config( $default ) {
		$default[ self::$config_key_name_field ]        = 0;
		$default[ self::$config_key_name_screen_field ] = 0;

		return $default;
	}

	public static function wpim_admin_menu() {
		$title = self::__( 'Logging' );
		$role  = 'manage_options';
		$slug  = 'wpim_logging';
		add_submenu_page( self::MENU, $title, $title, $role, $slug, array( __CLASS__, 'admin_' . $slug ) );
	}

	public static function admin_wpim_logging() {
		if (self::request('clear_log')) {
			self::empty_log();
		}
		echo '<h3>' . WPIMCore::__('WP Inventory Logging') . '</h3>';
		echo '<a class="button" href="' . admin_url('admin.php?page=' . $_GET['page']) . '&clear_log=true">' . WPIMCore::__('Clear Log') . '</a>';
		echo '<h4>' . WPIMCore::__('Log File') . '</h4>';
		echo '<div id="wpim_log" style="border: 2px solid #888; background: white; padding: 10px; margin: 20px; font-family: monospace; max-height: 400px; overflow-y: scroll;">';
		$log = file_get_contents(self::$file_name);
		echo $log;
		echo '</div>';

		global $wp_version, $wpdb;
		echo '<h4>' . WPIMCore::__('Server Info') . '</h4>';
		echo '<ul>';
		echo '<li>PHP Version ' . phpversion() . '</li>';
		echo '<li>MySQL Version ' .  $wpdb->dbh->server_info . '</li>';
		echo '<li>WP Version ' . $wp_version . '</li>';
		echo '</ul>';

		echo '<h4>' . WPIMCore::__('Active Plugins') . '</h4>';
		echo '<ol>';
		$plugins = get_plugins();
		foreach($plugins AS $file => $plugin) {
			if (is_plugin_active($file)) {
				$url = ( ! empty($plugin['PluginURI']))
					? ' (' . $plugin['PluginURI'] . ')'
					: '';

				echo '<li>' . $plugin['Name'] . ' - ' . $plugin['Version'] . $url . '</li>';
			}
		}
		echo '</ol>';
	}

	/**
	 * Displays the WPIM Admin Settings
	 */
	public static function wpim_edit_settings() {
		echo '<tr><th>' . WPIMCore::__( 'Enable Logging' ) . '</th>';
		echo '<td>';
		echo WPIMAdmin::dropdown_yesno( self::$config_key_name_field, self::$logging_enabled );
		echo '</td>';
		echo '</tr>';
		echo '<tr><th>' . WPIMCore::__( 'Log to Screen' ) . '</th>';
		echo '<td>';
		echo WPIMAdmin::dropdown_yesno( self::$config_key_name_screen_field, self::$log_to_screen_enabled );
		echo '<p class="description">' . WPIMCore::__( 'WARNING: This will be very "noisy" to the screen, and may interfere with some functionality' ) . '</p>';
		echo '</td>';
		echo '</tr>';
	}

	/**
	 * Write the event to the log.
	 *
	 * @param $string
	 * @param null $caller
	 */
	public static function log( $string, $caller = NULL ) {
		if ( ! (int) self::$logging_enabled && ! (int) self::$log_to_screen_enabled ) {
			return;
		}

		self::parse_actions();

		$caller = self::parse_caller( $caller );
		$date   = date( 'Y-m-d H:g' );

		if ( self::$log_to_screen_enabled ) {
			self::log_to_screen( $string, $caller );
		}
		if ( self::$logging_enabled ) {
			self::write_log( $date, $caller, $string );
		}
	}

	private static function parse_caller( $caller ) {
		$called_by = ( ! empty( $caller['class'] ) ) ? $caller['class'] . '::' : '';
		$called_by .= $caller['function'];
		$called_by .= ( ! empty( $caller['line'] ) ) ? " ({$caller['line']}) " : '';

		return $called_by;
	}

	private static function log_to_screen( $string, $caller ) {
		echo '<pre style="background:#ffc; border: 3px solid #080; padding: 5px 10px; margin: 5px; color: black; font-family: monospace !important;">';
		echo PHP_EOL . 'Caller: ' . $caller;
		echo PHP_EOL;
		if ( is_object( $string ) || is_array( $string ) ) {
			var_dump( $string );
		} else {
			echo $string;
		}
		echo '</pre>';
	}

	private static function write_log( $date, $caller, $string ) {
		if ( is_object( $string ) || is_array( $string ) ) {
			ob_start();
			var_dump( $string );
			$string = ob_get_clean();
		}
		file_put_contents( self::$file_name, $date . ' - ' . $caller . ' - ' . $string, FILE_APPEND );
		file_put_contents( self::$file_name, str_repeat('-', 30) . '<br>', FILE_APPEND);
	}

	private static function empty_log() {
		file_put_contents( self::$file_name, '' );
	}
}

WPIMLogging::start();