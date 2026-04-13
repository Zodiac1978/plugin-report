<?php
/**
 * Plugin Name:       Plugin Report
 * Plugin URI:        https://wordpress.org/plugins/plugin-report/
 * Description:       Provides detailed information about currently installed plugins
 * Version:           2.2.2
 * Requires at least: 4.6
 * Requires PHP:      5.6
 * Author:            Torsten Landsiedel
 * Author URI:        https://torstenlandsiedel.de
 * License:           GPLv3
 * Network:           true
 */

// If called without WordPress, exit.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}


if ( is_admin() && ! class_exists( 'RT_Plugin_Report' ) ) {

	/**
	 * Plugin Report main class.
	 */
	class RT_Plugin_Report {

		// CSS class constants.
		const CSS_CLASS_LOW  = 'pr-risk-low';
		const CSS_CLASS_MED  = 'pr-risk-medium';
		const CSS_CLASS_HIGH = 'pr-risk-high';

		// Other class constants.
		const PLUGIN_VERSION        = '2.2.2';
		const COLS_PER_ROW          = 9;
		const CACHE_LIFETIME        = DAY_IN_SECONDS;
		const CACHE_LIFETIME_NOREPO = WEEK_IN_SECONDS;

		/**
		 * Constructor
		 */
		public function __construct() {
			// Intentionally left blank.
		}


		/**
		 * Set up things like hooks and such
		 */
		public function init() {
			// Hook for the admin page.
			if ( is_multisite() ) {
				add_action( 'network_admin_menu', array( $this, 'register_settings_page' ) );
			} else {
				add_action( 'admin_menu', array( $this, 'register_settings_page' ) );
			}
			// Hook for the admin js.
			add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
			// Add the AJAX hook.
			add_action( 'wp_ajax_rt_get_plugin_info', array( $this, 'get_plugin_info' ) );
			// Hook into the WP Upgrader to selectively delete cache items.
			add_action( 'upgrader_process_complete', array( $this, 'upgrade_delete_cache_items' ), 10, 2 );
			// Enrich the native plugin list table with extra columns and warnings.
			add_filter( 'manage_plugins_columns', array( $this, 'add_plugin_list_columns' ) );
			add_action( 'manage_plugins_custom_column', array( $this, 'render_plugin_list_column' ), 10, 3 );
			add_action( 'after_plugin_row', array( $this, 'render_plugin_row_warning' ), 10, 3 );
			if ( is_multisite() ) {
				add_filter( 'manage_plugins-network_columns', array( $this, 'add_plugin_list_columns' ) );
				add_action( 'manage_plugins-network_custom_column', array( $this, 'render_plugin_list_column' ), 10, 3 );
			}
			// Populate cache on the native plugins page so columns have data.
			add_action( 'load-plugins.php', array( $this, 'populate_cache_on_plugins_page' ) );
			// Add a cache-clear link to the native plugins page.
			add_filter( 'views_plugins', array( $this, 'add_plugins_page_cache_link' ) );
			add_filter( 'views_plugins-network', array( $this, 'add_plugins_page_cache_link' ) );
		}


		/**
		 * Add a new options page to the network admin
		 */
		public function register_settings_page() {
			add_plugins_page(
				esc_html_x( 'Plugin Report', 'Page and menu title', 'plugin-report' ),
				esc_html_x( 'Plugin Report', 'Page and menu title', 'plugin-report' ),
				is_multisite() ? 'manage_sites' : 'manage_options',
				'plugin_report',
				array( $this, 'settings_page' )
			);
		}


		/**
		 * Render the options page
		 */
		public function settings_page() {
			// Check user capabilities, just to be sure.
			if ( ! current_user_can( is_multisite() ? 'manage_sites' : 'manage_options' ) ) {
				wp_die();
			}
			// Assemble information we'll need.
			global $wp_version;
			$plugins = get_plugins();

			// Check wether a core update is available.
			$wp_latest = $this->check_core_updates();

			// Refresh the cache, but only if nonce is valid and this is a fresh timestamp (not if the page has been refreshed with the timestamp still in the URL).
			if ( isset( $_GET['clear_cache'] ) && isset( $_GET['_wpnonce'] ) && wp_verify_nonce( sanitize_key( $_GET['_wpnonce'] ), 'plugin_report_clear_cache' ) ) {
				$new_timestamp  = intval( $_GET['clear_cache'] );
				$last_timestamp = intval( get_site_transient( 'plugin_report_cache_cleared' ) );
				if ( ! $last_timestamp || $new_timestamp > $last_timestamp ) {
					$this->clear_cache();
					set_site_transient( 'plugin_report_cache_cleared', $new_timestamp, self::CACHE_LIFETIME );
				}
			}

			// Start the page's output.
			echo '<div class="wrap">';
			echo '<h1>' . esc_html_x( 'Plugin Report', 'Page and menu title', 'plugin-report' ) . '</h1>';
			echo '<p>';
			$version_temp = '<span class="' . $this->get_version_risk_classname( $wp_version, $wp_latest ) . '">' . $wp_version . '</span>';
			/* translators: %1$s: Current WordPress version number, %2$s: Current PHP version number */
			echo sprintf( esc_html__( 'Currently running WordPress version %1$s and PHP version %2$s.', 'plugin-report' ), $version_temp, phpversion() );
			if ( version_compare( $wp_version, $wp_latest, '<' ) ) {
				/* translators: %s = Available new version number */
				echo sprintf( ' (' . esc_html__( 'An upgrade to %s is available', 'plugin-report' ) . ')', $wp_latest );
			}
			echo '</p>';
			echo '<p>';
			// Clear cache and reload.
			if ( is_multisite() ) {
				$page_url = 'network/plugins.php?page=plugin_report';
			} else {
				$page_url = 'plugins.php?page=plugin_report';
			}
			echo '<a href="' . esc_url( wp_nonce_url( admin_url( $page_url . '&clear_cache=' . current_time( 'timestamp' ) ), 'plugin_report_clear_cache' ) ) . '">' . esc_html__( 'Clear cached plugin data and reload', 'plugin-report' ) . '</a>';
			echo '</p>';
			echo '<h2>' . esc_html__( 'Currently installed plugins', 'plugin-report' ) . '</h2>';
			echo '<p id="plugin-report-progress"></p>';

			// The report's main table.
			echo '<table id="plugin-report-table" class="wp-list-table widefat fixed striped">';
			echo '<thead>';
			echo '<tr>';
			echo '<th data-sort-default>' . esc_html__( 'Name', 'plugin-report' ) . '</th>';
			echo '<th>' . esc_html__( 'Author', 'plugin-report' ) . '</th>';
			echo '<th>' . esc_html__( 'Repository', 'plugin-report' ) . '</th>';
			echo '<th>' . esc_html__( 'Activated', 'plugin-report' ) . '</th>';
			echo '<th data-sort-method="none" class="no-sort">' . esc_html__( 'Installed version', 'plugin-report' ) . '</th>';
			echo '<th>' . esc_html__( 'Auto-update', 'plugin-report' ) . '</th>';
			echo '<th>' . esc_html__( 'Last update', 'plugin-report' ) . '</th>';
			echo '<th data-sort-method="dotsep">' . esc_html__( 'Tested up to WP version', 'plugin-report' ) . '</th>';
			echo '<th data-sort-method="number">' . esc_html__( 'Rating', 'plugin-report' ) . '</th>';
			echo '</tr>';
			echo '</thead>';
			echo '<tbody>';

			foreach ( $plugins as $key => $plugin ) {
				$slug      = $this->get_plugin_slug( $key );
				$cache_key = $this->create_cache_key( $slug );
				$cache     = get_site_transient( $cache_key );
				if ( $cache ) {
					// Use the cached report to create a table row.
					echo $this->render_table_row( $cache );
				} else {
					// Render a special table row that's used as a signal to the front-end js that new data is needed.
					echo '<tr class="plugin-report-row-temp-' . esc_attr( $slug ) . '"><td colspan="' . (int) self::COLS_PER_ROW . '">' . esc_html__( 'Loading...', 'plugin-report' ) . '</td></tr>';
				}
			}

			echo '</tbody>';
			echo '</table>';

			echo '<p id="plugin-report-buttons"></p>';

			// Wrap up.
			echo '</div>';
		}


		/**
		 * Enqueue admin javascript
		 *
		 * @param string $hook  Screen hook.
		 */
		public function enqueue_assets( $hook ) {
			// Load CSS on the native plugin list page too.
			if ( 'plugins.php' === $hook ) {
				wp_enqueue_style( 'plugin-report-css', plugin_dir_url( __FILE__ ) . 'css/plugin-report.css', array(), self::PLUGIN_VERSION );
				return;
			}
			// Check if we're on the right screen.
			if ( 'plugins_page_plugin_report' !== $hook ) {
				return;
			}
			// Register the plugin's admin js, and require jquery.
			wp_enqueue_script( 'plugin-report-tablesort-js', plugins_url( '/js/tablesort.min.js', __FILE__ ), array(), '5.7.0', true );
			wp_enqueue_script( 'plugin-report-tablesort-number-js', plugins_url( '/js/tablesort.number.min.js', __FILE__ ), array( 'plugin-report-tablesort-js' ), '5.7.0', true );
			wp_enqueue_script( 'plugin-report-tablesort-dotsep-js', plugins_url( '/js/tablesort.dotsep.min.js', __FILE__ ), array( 'plugin-report-tablesort-js' ), '5.7.0', true );
			wp_enqueue_script( 'plugin-report-js', plugins_url( '/js/plugin-report.js', __FILE__ ), array( 'jquery', 'plugin-report-tablesort-js', 'plugin-report-tablesort-number-js', 'plugin-report-tablesort-dotsep-js' ), self::PLUGIN_VERSION, true );
			// Add some variables to the page, to be used by the javascript.
			$slugs     = $this->get_plugin_slugs();
			$slugs_str = implode( ',', $slugs );
			$vars      = array(
				'plugin_slugs'      => $slugs_str,
				'ajax_nonce'        => wp_create_nonce( 'plugin_report_nonce' ),
				'export_btn'        => __( 'Export .csv file', 'plugin-report' ),
				'plugin_url_header' => __( 'Plugin URL', 'plugin-report' ),
				'author_url_header' => __( 'Author URL', 'plugin-report' ),
			);
			wp_localize_script( 'plugin-report-js', 'plugin_report_vars', $vars );
			// Enqueue admin CSS file.
			wp_enqueue_style( 'plugin-report-css', plugin_dir_url( __FILE__ ) . 'css/plugin-report.css', array(), self::PLUGIN_VERSION );
		}


		/**
		 * Ensure all plugins have cached reports when viewing the native plugins page.
		 * Also handles the cache-clear action for this page.
		 */
		public function populate_cache_on_plugins_page() {
			// Check if get_plugins() function exists.
			if ( ! function_exists( 'plugins_api' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
			}

			// Handle cache clear request.
			if ( isset( $_GET['pr_clear_cache'] ) && isset( $_GET['_wpnonce'] ) ) {
				if ( wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'pr_clear_cache' ) ) {
					$this->clear_cache();
				}
			}

			// Populate cache for any plugins that don't have it yet.
			$plugins = get_plugins();
			foreach ( $plugins as $key => $plugin ) {
				$slug = $this->get_plugin_slug( $key );
				$this->assemble_plugin_report( $slug );
			}
		}


		/**
		 * Add a "Refresh report data" link to the plugin list views.
		 *
		 * @param array $views Existing view links.
		 *
		 * @return array Modified view links.
		 */
		public function add_plugins_page_cache_link( $views ) {
			$url = wp_nonce_url( add_query_arg( 'pr_clear_cache', '1' ), 'pr_clear_cache' );
			$views['pr_refresh'] = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Refresh report data', 'plugin-report' ) . '</a>';
			return $views;
		}


		/**
		 * Get the slugs for all currently installed plugins
		 */
		private function get_plugin_slugs() {
			$plugins = get_plugins();
			$slugs   = array();
			foreach ( $plugins as $key => $plugin ) {
				$slugs[] = $this->get_plugin_slug( $key );
			}
			return $slugs;
		}


		/**
		 * Convert a plugin's file path into its slug.
		 *
		 * @param string $file  Plugin file path.
		 */
		private function get_plugin_slug( $file ) {
			if ( strpos( $file, '/' ) !== false ) {
				$parts = explode( '/', $file );
			} else {
				$parts = explode( '.', $file );
			}
			return sanitize_title( $parts[0] );
		}


		/**
		 * AJAX handler
		 * Returns a full html table row with the plugin's data
		 */
		public function get_plugin_info() {

			// Check the ajax nonce, display an error if the check fails.
			if ( ! check_ajax_referer( 'plugin_report_nonce', 'nonce', false ) ) {
				wp_die();
			}

			// Check user capabilites, just to be sure.
			if ( ! current_user_can( is_multisite() ? 'manage_sites' : 'manage_options' ) ) {
				wp_die();
			}

			// Check if get_plugins() function exists.
			if ( ! function_exists( 'plugins_api' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
			}

			if ( isset( $_POST['slug'] ) ) {
				$slug = sanitize_title( wp_unslash( $_POST['slug'] ) );
			} else {
				$slug = ''; // Set value to an empty string.
			}

			$report = $this->assemble_plugin_report( $slug );

			if ( $report ) {
				$table_row = $this->render_table_row( $report );
			} else {
				$table_row = $this->render_error_row( esc_html__( 'No plugin data available.', 'plugin-report' ) );
			}

			// Formulate a response.
			$response = array(
				'html'    => $table_row,
				'message' => 'Success!',
			);
			// Return the response.
			echo wp_json_encode( $response );

			wp_die();
		}


		/**
		 * Gather all the info we can get about a plugin.
		 * Uses transient caching to avoid doing repo API calls on every page visit.
		 *
		 * @param string $slug   Plugin slug.
		 */
		private function assemble_plugin_report( $slug ) {
			if ( ! empty( $slug ) ) {
				$report       = array();
				$cache_key    = $this->create_cache_key( $slug );
				$cache        = get_site_transient( $cache_key );
				$plugins      = get_plugins();
				$auto_updates = (array) get_site_option( 'auto_update_plugins', array() );

				if ( empty( $cache ) ) {

					// Add the plugin's slug to the report.
					$report['slug'] = $slug;

					// Get the locally available info, and add it to the report.
					foreach ( $plugins as $key => $plugin ) {
						if ( $this->get_plugin_slug( $key ) === $slug ) {

							// Translate plugin data.
							$textdomain = $plugin['TextDomain'];
							if ( $textdomain ) {
								if ( ! is_textdomain_loaded( $textdomain ) ) {
									if ( $plugin['DomainPath'] ) {
										load_plugin_textdomain( $textdomain, false, dirname( $key ) . $plugin['DomainPath'] );
									} else {
										load_plugin_textdomain( $textdomain, false, dirname( $key ) );
									}
								}
							} elseif ( 'hello.php' === basename( $key ) ) {
								$textdomain = 'default';
							}
							if ( $textdomain ) {
								foreach ( array( 'Name', 'PluginURI', 'Description', 'Author', 'AuthorURI', 'Version' ) as $field ) {
									// phpcs:ignore WordPress.WP.I18n.LowLevelTranslationFunction,WordPress.WP.I18n.NonSingularStringLiteralText,WordPress.WP.I18n.NonSingularStringLiteralDomain
									$plugin[ $field ] = translate( $plugin[ $field ], $textdomain );
								}
							}

							$report['local_info']  = $plugin;
							$report['file_path']   = $key;
							$report['auto-update'] = in_array( $key, $auto_updates, true );

							// Change any whitespace to default space.
							$report['local_info']['Name'] = preg_replace( '/\s+/u', ' ', $report['local_info']['Name'] );

							break;
						}
					}

					// Use the wordpress.org repository API to get detailed information.
					$args = array(
						'slug'   => $slug,
						'fields' => array(
							'description'   => false,
							'sections'      => false,
							'tags'          => false,
							'version'       => true,
							'tested'        => true,
							'requires'      => true,
							'requires_php'  => true,
							'compatibility' => true,
							'author'        => true,
						),
					);

					// Check wordpress.org only if "Update URI" plugin header is not set or set to wordpress.org.
					$parsed_repo_url = wp_parse_url( $report['local_info']['UpdateURI'] );
					$repo_host       = isset( $parsed_repo_url['host'] ) ? $parsed_repo_url['host'] : null;
					if ( empty( $repo_host ) || strtolower( $repo_host ) === 'w.org' || strtolower( $repo_host ) === 'wordpress.org' ) {
						$returned_object = plugins_api( 'plugin_information', $args );
					}

					// Add the repo info to the report.
					if ( isset( $returned_object ) ) {
						if ( ! is_wp_error( $returned_object ) ) {
							$report['repo_info'] = $returned_object;
							// Cache the report.
							set_site_transient( $cache_key, $report, self::CACHE_LIFETIME );
						} else {
							// Store the error code and message in the report.
							$report['repo_error_code']    = $returned_object->get_error_code();
							$report['repo_error_message'] = $returned_object->get_error_message();
							// Because the plugin is not found in the wordpress.org repo, check if it exists in SVN.
							$report['exists_in_svn'] = $this->check_exists_in_svn( $slug );
							// Cache for an extra long time when the plugin is not in the repo.
							set_site_transient( $cache_key, $report, self::CACHE_LIFETIME_NOREPO );
						}
					}
				} else {
					$report = $cache;
				}

				return $report;

			} else {
				return null;
			}

		}


		/**
		 * Check if the plugin is present in WordPress's SVN repository.
		 *
		 * Function adapted from the 'Enhanced Plugin Admin' plugin by Marios Alexandrou.
		 * See: https://plugins.trac.wordpress.org/browser/enhanced-plugin-admin/trunk/enhanced-plugin-admin.php
		 *
		 * @param string $slug The plugin's slug.
		 *
		 * @return boolean True if found, false if not.
		 */
		private function check_exists_in_svn( $slug ) {
			// Attempt to load the plugin's SVN repo page.
			$response = wp_remote_get( 'https://plugins.svn.wordpress.org/' . rawurlencode( $slug ) . '/' );
			// If the return value was a WP_Error, assume the answer is no.
			if ( is_wp_error( $response ) ) {
				return false;
			} else {
				// If the returned HTTP code is 200, the page was found, so return true.
				$response_code = wp_remote_retrieve_response_code( $response );
				if ( 200 === $response_code ) {
					return true;
				}
			}
			// In all other cases, assume the plugin was not found.
			return false;
		}


		/**
		 * Add custom columns to the native plugin list table.
		 *
		 * @param array $columns Existing columns.
		 *
		 * @return array Modified columns.
		 */
		public function add_plugin_list_columns( $columns ) {
			$columns['pr_repo_status']  = __( 'Repository', 'plugin-report' );
			$columns['pr_last_updated'] = __( 'Last Update', 'plugin-report' );
			$columns['pr_tested']       = __( 'Tested up to', 'plugin-report' );
			return $columns;
		}


		/**
		 * Render custom column content in the native plugin list table.
		 *
		 * @param string $column_name Column identifier.
		 * @param string $plugin_file Plugin file path relative to plugins dir.
		 * @param array  $plugin_data Plugin header data.
		 */
		public function render_plugin_list_column( $column_name, $plugin_file, $plugin_data ) {
			$slug  = $this->get_plugin_slug( $plugin_file );
			$cache = get_site_transient( $this->create_cache_key( $slug ) );

			if ( empty( $cache ) ) {
				echo '—';
				return;
			}

			global $wp_version;
			$wp_latest = $this->check_core_updates();

			switch ( $column_name ) {
				case 'pr_repo_status':
					if ( isset( $cache['repo_error_code'] ) && 'plugins_api_failed' === $cache['repo_error_code'] ) {
						if ( isset( $cache['exists_in_svn'] ) && true === $cache['exists_in_svn'] ) {
							echo '<span class="' . self::CSS_CLASS_HIGH . '">' . esc_html__( 'Closed', 'plugin-report' ) . '</span>';
						} else {
							echo '<span class="' . self::CSS_CLASS_HIGH . '">' . esc_html__( 'Not found', 'plugin-report' ) . '</span>';
						}
					} elseif ( isset( $cache['repo_info'] ) ) {
						echo '<span class="' . self::CSS_CLASS_LOW . '">wordpress.org</span>';
					} else {
						$parsed = wp_parse_url( isset( $cache['local_info']['UpdateURI'] ) ? $cache['local_info']['UpdateURI'] : '' );
						if ( isset( $parsed['host'] ) && ! empty( $parsed['host'] ) ) {
							echo '<span class="' . self::CSS_CLASS_MED . '">' . esc_html( $parsed['host'] ) . '</span>';
						} else {
							echo '—';
						}
					}
					break;

				case 'pr_last_updated':
					if ( isset( $cache['repo_info'] ) && isset( $cache['repo_info']->last_updated ) ) {
						$time_update = new DateTime( $cache['repo_info']->last_updated );
						$time_diff   = human_time_diff( $time_update->getTimestamp(), current_time( 'timestamp' ) );
						$css_class   = $this->get_timediff_risk_classname( current_time( 'timestamp' ) - $time_update->getTimestamp() );
						echo '<span class="' . $css_class . '">' . esc_html( $time_diff ) . '</span>';
					} else {
						echo '—';
					}
					break;

				case 'pr_tested':
					if ( isset( $cache['repo_info'] ) && isset( $cache['repo_info']->tested ) && ! empty( $cache['repo_info']->tested ) ) {
						$css_class = $this->get_version_risk_classname( $cache['repo_info']->tested, $wp_latest, true );
						echo '<span class="' . $css_class . '">' . esc_html( $cache['repo_info']->tested ) . '</span>';
					} else {
						echo '—';
					}
					break;
			}
		}


		/**
		 * Show inline warning rows beneath problematic plugins in the native list.
		 *
		 * @param string $plugin_file Plugin file path relative to plugins dir.
		 * @param array  $plugin_data Plugin header data.
		 * @param string $status      Plugin status (active, inactive, etc.).
		 */
		public function render_plugin_row_warning( $plugin_file, $plugin_data, $status ) {
			$slug  = $this->get_plugin_slug( $plugin_file );
			$cache = get_site_transient( $this->create_cache_key( $slug ) );

			if ( empty( $cache ) ) {
				return;
			}

			$warnings = array();

			// Closed on wp.org.
			if ( isset( $cache['repo_error_code'] ) && 'plugins_api_failed' === $cache['repo_error_code'] && isset( $cache['exists_in_svn'] ) && true === $cache['exists_in_svn'] ) {
				$warnings[] = array(
					'class'   => self::CSS_CLASS_HIGH,
					'message' => __( 'This plugin has been closed on wordpress.org and will no longer receive updates.', 'plugin-report' ),
				);
			}

			// Not updated in over 2 years.
			if ( isset( $cache['repo_info'] ) && isset( $cache['repo_info']->last_updated ) ) {
				$time_update = new DateTime( $cache['repo_info']->last_updated );
				$days_since  = ( current_time( 'timestamp' ) - $time_update->getTimestamp() ) / DAY_IN_SECONDS;
				if ( $days_since > 730 ) {
					$warnings[] = array(
						'class'   => self::CSS_CLASS_MED,
						'message' => __( 'This plugin has not been updated in over 2 years.', 'plugin-report' ),
					);
				}
			}

			// Not tested with current WP major version.
			if ( isset( $cache['repo_info'] ) && isset( $cache['repo_info']->tested ) && ! empty( $cache['repo_info']->tested ) ) {
				$wp_latest = $this->check_core_updates();
				if ( version_compare( $this->get_major_version( $cache['repo_info']->tested ), $this->get_major_version( $wp_latest ), '<' ) ) {
					$warnings[] = array(
						'class'   => self::CSS_CLASS_MED,
						/* translators: %s: WordPress version number */
						'message' => sprintf( __( 'This plugin has not been tested with the current major version of WordPress (%s).', 'plugin-report' ), $this->get_major_version( $wp_latest ) ),
					);
				}
			}

			if ( empty( $warnings ) ) {
				return;
			}

			// Count visible columns to span the full width.
			$screen  = get_current_screen();
			$columns = get_column_headers( $screen );
			$colspan = count( $columns ) + 1; // +1 for the checkbox column.

			foreach ( $warnings as $warning ) {
				echo '<tr class="plugin-update-tr pr-warning-row">';
				echo '<td colspan="' . (int) $colspan . '" class="plugin-update colspanchange">';
				echo '<div class="update-message notice inline notice-warning notice-alt"><p>';
				echo '<span class="' . esc_attr( $warning['class'] ) . '">' . esc_html( $warning['message'] ) . '</span>';
				echo '</p></div>';
				echo '</td>';
				echo '</tr>';
			}
		}


		/**
		 * From a report, generate an HTML table row with relevant data for the plugin.
		 *
		 * @param array|false $report Report of plugin.
		 */
		private function render_table_row( $report ) {
			// Get the current WP version number.
			global $wp_version;
			// Get the latest WP release version number.
			$wp_latest = $this->check_core_updates();
			// Check if the report is valid.
			if ( false === $report ) {
				$html = $this->render_error_row( esc_html__( 'No plugin data available.', 'plugin-report' ) );
			} else {
				// Start the new table row.
				$html = '<tr class="plugin-report-row-' . esc_attr( $report['slug'] ) . '">';

				// Name.
				if ( isset( $report['local_info']['PluginURI'] ) && ! empty( $report['local_info']['PluginURI'] ) ) {
					$html .= '<td><a href="' . esc_url( $report['local_info']['PluginURI'] ) . '"><strong>' . esc_html( $report['local_info']['Name'] ) . '</strong></a></td>';
				} else {
					$html .= '<td><strong>' . esc_html( $report['local_info']['Name'] ) . '</strong></td>';
				}

				// Author.
				if ( isset( $report['local_info']['AuthorURI'] ) && ! empty( $report['local_info']['AuthorURI'] ) ) {
					$html .= '<td><a href="' . esc_url( $report['local_info']['AuthorURI'] ) . '">' . esc_html( $report['local_info']['Author'] ) . '</a></td>';
				} else {
					$html .= '<td>' . esc_html( $report['local_info']['Author'] ) . '</td>';
				}

				// Repository.
				if ( isset( $report['local_info']['UpdateURI'] ) ) {
					// Parse the UpdateURI's value to get the host.
					$parsed_repo_url = wp_parse_url( $report['local_info']['UpdateURI'] );
					// If the URI is valid, extract the host, otherwise we'll use the header value.
					$repo_host = isset( $parsed_repo_url['host'] ) ? $parsed_repo_url['host'] : $report['local_info']['UpdateURI'];
					// Check if the plugin is supposed to be hosted on wp.org.
					if ( empty( $repo_host ) || strtolower( $repo_host ) === 'w.org' || strtolower( $repo_host ) === 'wordpress.org' ) {
						// Plugin should be available on wp.org, check if we got a 'not found' error.
						if ( isset( $report['repo_error_code'] ) && $report['repo_error_code'] === 'plugins_api_failed' ) {
							// Plugin is not available in the wp.org repo.
							if ( isset( $report['exists_in_svn'] ) && $report['exists_in_svn'] === true ) {
								$html .= '<td class="' . self::CSS_CLASS_HIGH . '">' . __( 'wordpress.org, plugin closed', 'plugin-report' ) . '</td>';
							} else {
								$html .= '<td class="' . self::CSS_CLASS_HIGH . '">' . __( 'wordpress.org, plugin not found', 'plugin-report' ) . '</td>';
							}
						} else {
							// Plugin is available on wp.org.
							$html .= '<td class="' . self::CSS_CLASS_LOW . '">wordpress.org</td>';
						}
					} else {
						if ( $parsed_repo_url && isset( $parsed_repo_url['host'] ) ) {
							// Update URI is a valid URL, display the host.
							$html .= '<td class="' . self::CSS_CLASS_MED . '">' . esc_html( $repo_host ) . '</td>';
						} else {
							// Some other value (like 'false'), so assume updates are disabled.
							$html .= '<td class="' . self::CSS_CLASS_MED . '">' . __( 'Updates disabled', 'plugin-report' ) . '</td>';
						}
					}
				} elseif ( version_compare( $wp_version, '5.8', '<' ) ) {
					$html .= $this->render_error_cell( esc_html__( 'Only available in WP 5.8+', 'plugin-report' ) );
				} else {
					$html .= $this->render_error_cell();
				}

				// Activated.
				$active    = __( 'Please clear cache to update', 'plugin-report' );
				$css_class = self::CSS_CLASS_MED;
				if ( is_multisite() ) {
					$activation_status = $this->get_multisite_activation( $report['file_path'] );
					if ( true === $activation_status['network'] ) {
						$css_class = self::CSS_CLASS_LOW;
						$html     .= '<td class="' . $css_class . '">' . __( 'Network activated', 'plugin-report' ) . '</td>';
					} else {
						$css_class = ( $activation_status['active'] > 0 ) ? self::CSS_CLASS_LOW : self::CSS_CLASS_HIGH;
						$html     .= '<td class="' . $css_class . '">' . $activation_status['active'] . '/' . $activation_status['sites'] . '</td>';
					}
				} else {
					if ( isset( $report['file_path'] ) ) {
						$active    = is_plugin_active( $report['file_path'] ) ? __( 'Yes', 'plugin-report' ) : __( 'No', 'plugin-report' );
						$css_class = is_plugin_active( $report['file_path'] ) ? self::CSS_CLASS_LOW : self::CSS_CLASS_HIGH;
					}
					$html .= '<td class="' . $css_class . '">' . $active . '</td>';
				}

				// Installed / available version.
				if ( isset( $report['repo_info'] ) ) {
					$css_class = $this->get_version_risk_classname( $report['local_info']['Version'], $report['repo_info']->version );
					$html     .= '<td class="' . $css_class . '">';
					$html     .= esc_html( $report['local_info']['Version'] );
					if ( $report['local_info']['Version'] !== $report['repo_info']->version ) {
						// Any platform upgrades needed?
						$needs_php_upgrade = isset( $report['repo_info']->requires_php ) ? version_compare( phpversion(), $report['repo_info']->requires_php, '<' ) : false;
						$needs_wp_upgrade  = isset( $report['repo_info']->requires ) ? version_compare( $wp_version, $report['repo_info']->requires, '<' ) : false;
						// Create the additional message.
						if ( $needs_wp_upgrade && $needs_php_upgrade ) {
							/* translators: %1$s: Plugin version number, %2$s: WP version number, %3$s: PHP version number */
							$html .= ' <span class="pr-additional-info">' . sprintf( esc_html__( '(%1$s available, requires WP %2$s and PHP %3$s)', 'plugin-report' ), esc_html( $report['repo_info']->version ), esc_html( $report['repo_info']->requires ), esc_html( $report['repo_info']->requires_php ) ) . '</span>';
						} elseif ( $needs_wp_upgrade ) {
							/* translators: %1$s: Plugin version number, %2$s: WP version number. */
							$html .= ' <span class="pr-additional-info">' . sprintf( esc_html__( '(%1$s available, requires WP %2$s)', 'plugin-report' ), esc_html( $report['repo_info']->version ), esc_html( $report['repo_info']->requires ) ) . '</span>';
						} elseif ( $needs_php_upgrade ) {
							/* translators: %1$s: Plugin version number, %2$s: PHP version number. */
							$html .= ' <span class="pr-additional-info">' . sprintf( esc_html__( '(%1$s available, requires PHP %2$s)', 'plugin-report' ), esc_html( $report['repo_info']->version ), esc_html( $report['repo_info']->requires_php ) ) . '</span>';
						} else {
							/* translators: %s: Plugin version number. */
							$html .= ' <span class="pr-additional-info">' . sprintf( esc_html__( '(%s available)', 'plugin-report' ), esc_html( $report['repo_info']->version ) ) . '</span>';
						}
					}
					$html .= '</td>';
				} else {
					$html .= '<td>' . esc_html( $report['local_info']['Version'] ) . '</td>';
				}

				// Auto-update.
				if ( version_compare( $wp_version, '5.5', '<' ) ) {
					$html .= '<td>' . __( 'Requires WordPress 5.5 or higher', 'plugin-report' ) . '</td>';
				} else {
					if ( isset( $report['auto-update'] ) && $report['auto-update'] ) {
						$html .= '<td class="' . self::CSS_CLASS_LOW . '">' . __( 'Enabled', 'plugin-report' ) . '</td>';
					} else {
						$html .= '<td>' . __( 'Not enabled', 'plugin-report' ) . '</td>';
					}
				}

				// Last updates.
				if ( isset( $report['repo_info'] ) && isset( $report['repo_info']->last_updated ) ) {
					$time_update = new DateTime( $report['repo_info']->last_updated );
					$time_diff   = human_time_diff( $time_update->getTimestamp(), current_time( 'timestamp' ) );
					$css_class   = $this->get_timediff_risk_classname( current_time( 'timestamp' ) - $time_update->getTimestamp() );
					$html       .= '<td class="' . $css_class . '" data-sort="' . esc_attr( (string) $time_update->getTimestamp() ) . '">' . esc_html( $time_diff ) . '</td>';
				} else {
					$html .= $this->render_error_cell();
				}

				// Tested up to.
				if ( isset( $report['repo_info'] ) && isset( $report['repo_info']->tested ) && ! empty( $report['repo_info']->tested ) ) {
					$css_class = $this->get_version_risk_classname( $report['repo_info']->tested, $wp_latest, true );
					$html     .= '<td class="' . $css_class . '">' . esc_html( $report['repo_info']->tested ) . '</td>';
				} else {
					$html .= $this->render_error_cell();
				}

				// Overall user rating.
				if ( isset( $report['repo_info'] ) && isset( $report['repo_info']->num_ratings ) && isset( $report['repo_info']->rating ) ) {
					$css_class  = ( intval( $report['repo_info']->num_ratings ) > 0 ) ? $this->get_percentage_risk_classname( intval( $report['repo_info']->rating ) ) : '';
					$value_text = ( ( intval( $report['repo_info']->num_ratings ) > 0 ) ? esc_html( $report['repo_info']->rating ) . '%' : esc_html__( 'No data available', 'plugin-report' ) );
					$html      .= '<td class="' . $css_class . '">' . $value_text . '</td>';
				} else {
					$html .= $this->render_error_cell();
				}

				// Close the new table row.
				$html .= '</tr>';
			}
			return $html;
		}


		/**
		 * Format an error message as a table row, so we can return it to javascript.
		 *
		 * @param string $message   Message to be shown.
		 */
		private function render_error_row( $message ) {
			return '<tr class="pluginreport-row-error"><td colspan="' . self::COLS_PER_ROW . '">' . $message . '</td></tr>';
		}


		/**
		 * Format an error message as a table cell, so we can return it to javascript.
		 *
		 * @param string $message   Message to be shown.
		 */
		private function render_error_cell( $message = null ) {
			if ( ! $message ) {
				$message = esc_html__( 'No data available', 'plugin-report' );
			}
			return '<td class="pluginreport-cell-error" data-sort="0">' . $message . '</td>';
		}


		/**
		 * Return the version string with all elements beyond the second removed ("5.5.1" -> "5.5").
		 *
		 * @param string $version_string   Complete version number.
		 */
		private function get_major_version( $version_string ) {
			$parts = explode( '.', $version_string );
			array_splice( $parts, 2 );
			return implode( '.', $parts );
		}


		/**
		 * Figure out what CSS class to use based on current and optimal version numbers.
		 *
		 * @param string $available    Available version.
		 * @param string $optimal      Optimal version.
		 * @param bool   $major_only   True to compare only major versions, false otherwise.
		 */
		private function get_version_risk_classname( $available, $optimal, $major_only = false ) {
			// Use only the first two elements of the version number if $major_only is set to true.
			// This is used for WP version numbers, where point releases are not considered a risk.
			if ( $major_only ) {
				$available = $this->get_major_version( $available );
				$optimal   = $this->get_major_version( $optimal );
			}
			// If the version is equal or higher, indicate low risk.
			if ( version_compare( $available, $optimal, '>=' ) ) {
				return self::CSS_CLASS_LOW;
			}
			// Else, indicate high risk.
			return self::CSS_CLASS_HIGH;
		}


		/**
		 * Assess the risk associated with low ratings or poor compatibility feedback, return corresponding CSS class.
		 *
		 * @param int $perc   Rating percentage.
		 */
		private function get_percentage_risk_classname( $perc ) {
			if ( $perc < 70 ) {
				return self::CSS_CLASS_HIGH;
			}
			if ( $perc < 90 ) {
				return self::CSS_CLASS_MED;
			}
			return self::CSS_CLASS_LOW;
		}


		/**
		 * Assess the risk associated with low ratings or poor compatibility feedback, return corresponding CSS class.
		 *
		 * @param int $time_diff   Time difference in seconds.
		 */
		private function get_timediff_risk_classname( $time_diff ) {
			$days = $time_diff / ( DAY_IN_SECONDS );
			if ( $days > 365 ) {
				return self::CSS_CLASS_HIGH;
			}
			if ( $days > 90 ) {
				return self::CSS_CLASS_MED;
			}
			return self::CSS_CLASS_LOW;
		}


		/**
		 * Get the latest available WordPress version using WP core functions
		 * This way, we don't need to do any API calls. WP check this periodically anyway.
		 */
		private function check_core_updates() {
			global $wp_version;
			$update = get_preferred_from_update_core();
			// Bail out of no valid response, or false.
			if ( false === $update ) {
				return $wp_version;
			}
			// If latest, return current version number.
			if ( is_object( $update ) && 'latest' === $update->response ) {
				return $wp_version;
			}
			// Return the preferred update's version number.
			return is_object( $update ) ? $update->version : $update['version'];
		}


		/**
		 * Gather statistics about a plugin's activation on a multisite install.
		 *
		 * @param string $path   Plugin path.
		 */
		private function get_multisite_activation( $path ) {
			// Create an array to contain the return values.
			$activation_status = array(
				'network' => false,
				'active'  => 0,
				'sites'   => 1,
			);
			// Check if the plugin is network activated.
			$network_plugins = get_site_option( 'active_sitewide_plugins', null );
			if ( array_key_exists( $path, $network_plugins ) ) {
				$activation_status['network'] = true;
			} else {
				// Get a list of all sites in the multisite install.
				$args  = array(
					'number' => 9999,
					'fields' => 'ids',
				);
				$sites = get_sites( $args );
				// Add the total number of sites to the return array.
				$activation_status['sites'] = count( $sites );
				// Loop through the sites to find where the plugin is active.
				foreach ( $sites as $site_id ) {
					$plugins = get_blog_option( $site_id, 'active_plugins', null );
					if ( $plugins ) {
						foreach ( $plugins as $plugin_path ) {
							if ( $plugin_path === $path ) {
								$activation_status['active']++;
							}
						}
					}
				}
			}
			// Return the data we gathered.
			return $activation_status;
		}


		/**
		 * Create a cache key that is unique to the provided plugin slug.
		 *
		 * @param string $slug   Plugin slug.
		 */
		private function create_cache_key( $slug ) {
			// Create a hash for the plugin slug.
			$slug_hash = hash( 'sha256', $slug );
			// Prefix and limit the string to 40 characters to avoid issues with long keys.
			$cache_key = 'rtpr_' . substr( $slug_hash, 0, 35 );
			// Return the key.
			return $cache_key;
		}


		/**
		 * Clear all cached plugin info
		 */
		private function clear_cache() {
			// Request a list of all plugins.
			$plugins = get_plugins();
			// Loop through the plugins array, and delete cache items.
			foreach ( $plugins as $key => $plugin ) {
				$slug = $this->get_plugin_slug( $key );
				$this->clear_cache_item( $slug );
			}
		}


		/**
		 * Remove the cache item for a single plugin.
		 *
		 * @param string $slug   Plugin slug.
		 */
		private function clear_cache_item( $slug ) {
			$cache_key = $this->create_cache_key( $slug );
			delete_site_transient( $cache_key );
		}


		/**
		 * Selectively delete cache for plugins that have been updated.
		 */
		public function upgrade_delete_cache_items( $upgrader, $data ) {
			// Check if plugins have been upgraded by WP.
			if ( isset( $data ) && isset( $data['plugins'] ) && is_array( $data['plugins'] ) ) {
				// Loop through the plugins, and delete the associated cache items.
				foreach ( $data['plugins'] as $key => $value ) {
					$slug = $this->get_plugin_slug( $value );
					$this->clear_cache_item( $slug );
				}
			}
		}

	}

	// Instantiate the class.
	$plugin_report_instance = new RT_Plugin_Report();
	$plugin_report_instance->init();

}
