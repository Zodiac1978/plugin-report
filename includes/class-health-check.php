<?php
/**
 * Plugin Report Health Check
 *
 * Periodically checks installed plugins for health issues and sends
 * a summary email to the site admin when problems are found.
 *
 * @package PluginReport
 */

// If called without WordPress, exit.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}


/**
 * Fully static health check class for periodic plugin monitoring.
 */
class RT_Plugin_Report_Health_Check {

	const OPTION_NOTIFIED = 'plugin_report_notified';
	const CRON_HOOK       = 'plugin_report_health_check';
	const STALE_DAYS      = 730;

	/** @var RT_Plugin_Report|null Injected plugin report instance. */
	private static $plugin_report = null;


	/**
	 * Initializes the health check with the given plugin report instance
	 * and registers all hooks.
	 *
	 * @param RT_Plugin_Report $plugin_report Plugin report instance.
	 */
	public static function init( $plugin_report ) {
		self::$plugin_report = $plugin_report;

		// Cron hook — always registered, WP-Cron runs outside is_admin().
		add_action( self::CRON_HOOK, array( static::class, 'run' ) );

		// Activation/deactivation hooks.
		register_activation_hook( RT_PLUGIN_REPORT_FILE, array( static::class, 'schedule' ) );
		register_deactivation_hook( RT_PLUGIN_REPORT_FILE, array( static::class, 'unschedule' ) );

		// Flag reset on plugin changes — only relevant in admin.
		if ( is_admin() ) {
			add_action( 'activated_plugin', array( static::class, 'clear_notification_flag' ) );
			add_action( 'deactivated_plugin', array( static::class, 'clear_notification_flag' ) );
		}

		// Reset when plugin report cache is cleared (avoids circular dependency).
		add_action( 'plugin_report_cache_cleared', array( static::class, 'clear_notification_flag' ) );
	}


	/**
	 * Runs the health check. Entry point called by WP-Cron.
	 */
	public static function run() {
		if ( ! function_exists( 'plugins_api' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
		}

		if ( ! self::$plugin_report ) {
			return;
		}

		$warnings = self::collect_warnings( self::$plugin_report );

		if ( empty( $warnings['closed'] ) && empty( $warnings['stale'] ) && empty( $warnings['untested'] ) ) {
			delete_option( self::OPTION_NOTIFIED );
			return;
		}

		if ( self::already_notified( $warnings ) ) {
			return;
		}

		$email = self::build_email( $warnings );

		/**
		 * Filters the health check notification email.
		 *
		 * @since 2.3.0
		 *
		 * @param array $email {
		 *     Array of email arguments passed to wp_mail().
		 *
		 *     @type string $to      The email recipient.
		 *     @type string $subject The email subject.
		 *     @type string $body    The email body.
		 *     @type string $headers Email headers.
		 * }
		 * @param array $warnings {
		 *     @type array $closed   Plugin names closed on wp.org.
		 *     @type array $stale    Plugin names not updated in 2+ years.
		 *     @type array $untested Plugin names not tested with current WP.
		 * }
		 */
		$email = apply_filters( 'plugin_report_health_check_email', $email, $warnings );

		wp_mail( $email['to'], wp_specialchars_decode( $email['subject'] ), $email['body'], $email['headers'] );
		update_option( self::OPTION_NOTIFIED, self::hash_warnings( $warnings ), false );
	}


	/**
	 * Collects warnings from all installed plugins.
	 *
	 * @param RT_Plugin_Report $plugin_report Plugin report instance.
	 *
	 * @return array { closed: string[], stale: string[], untested: string[] }
	 */
	public static function collect_warnings( $plugin_report ) {
		$plugins   = get_plugins();
		$wp_latest = $plugin_report->check_core_updates();
		$warnings  = array(
			'closed'   => array(),
			'stale'    => array(),
			'untested' => array(),
		);

		foreach ( $plugins as $key => $plugin ) {
			$slug   = $plugin_report->get_plugin_slug( $key );
			$report = $plugin_report->assemble_plugin_report( $slug );

			if ( ! $report ) {
				continue;
			}

			$name = isset( $report['local_info']['Name'] ) ? $report['local_info']['Name'] : $slug;

			if ( self::is_closed( $report ) ) {
				$warnings['closed'][] = $name;
			}
			if ( self::is_stale( $report ) ) {
				$warnings['stale'][] = $name;
			}
			if ( self::is_untested( $report, $wp_latest ) ) {
				$warnings['untested'][] = $name;
			}
		}

		return $warnings;
	}


	/**
	 * Checks if a plugin report indicates a closed plugin.
	 *
	 * @param array $report Plugin report data.
	 *
	 * @return bool
	 */
	public static function is_closed( $report ) {
		return isset( $report['repo_error_code'] )
			&& 'plugins_api_failed' === $report['repo_error_code']
			&& isset( $report['exists_in_svn'] )
			&& true === $report['exists_in_svn'];
	}


	/**
	 * Checks if a plugin report indicates a stale plugin (no update in 2+ years).
	 *
	 * @param array $report Plugin report data.
	 *
	 * @return bool
	 */
	public static function is_stale( $report ) {
		if ( ! isset( $report['repo_info'] ) || ! isset( $report['repo_info']->last_updated ) ) {
			return false;
		}

		$time_update = new DateTime( $report['repo_info']->last_updated );
		$days_since  = ( current_time( 'timestamp' ) - $time_update->getTimestamp() ) / DAY_IN_SECONDS;

		return $days_since > self::STALE_DAYS;
	}


	/**
	 * Checks if a plugin report indicates an untested plugin.
	 *
	 * @param array  $report    Plugin report data.
	 * @param string $wp_latest Latest WP version string.
	 *
	 * @return bool
	 */
	public static function is_untested( $report, $wp_latest ) {
		if ( ! isset( $report['repo_info'] ) || ! isset( $report['repo_info']->tested ) || empty( $report['repo_info']->tested ) ) {
			return false;
		}

		return version_compare(
			self::get_major_version( $report['repo_info']->tested ),
			self::get_major_version( $wp_latest ),
			'<'
		);
	}


	/**
	 * Returns the major version (first two segments) of a version string.
	 *
	 * @param string $version Full version string.
	 *
	 * @return string Major version (e.g. "6.8" from "6.8.2").
	 */
	private static function get_major_version( $version ) {
		$parts = explode( '.', $version );
		array_splice( $parts, 2 );
		return implode( '.', $parts );
	}


	/**
	 * Checks if the current warning set was already notified.
	 *
	 * @param array $warnings Warning arrays.
	 *
	 * @return bool
	 */
	public static function already_notified( $warnings ) {
		return get_option( self::OPTION_NOTIFIED ) === self::hash_warnings( $warnings );
	}


	/**
	 * Generates a hash for a warning set.
	 *
	 * @param array $warnings Warning arrays.
	 *
	 * @return string MD5 hash.
	 */
	public static function hash_warnings( $warnings ) {
		return md5( wp_json_encode( $warnings ) );
	}


	/**
	 * Builds the email data array.
	 *
	 * @param array $warnings Warning arrays.
	 *
	 * @return array { to, subject, body, headers }
	 */
	public static function build_email( $warnings ) {
		$total = count( $warnings['closed'] ) + count( $warnings['stale'] ) + count( $warnings['untested'] );

		return array(
			'to'      => get_site_option( 'admin_email' ),
			'subject' => self::build_email_subject( $total ),
			'body'    => self::build_email_body( $warnings ),
			'headers' => '',
		);
	}


	/**
	 * Builds the email subject line.
	 *
	 * @param int $total Total number of warnings.
	 *
	 * @return string
	 */
	private static function build_email_subject( $total ) {
		$site_title = get_option( 'blogname' );
		if ( empty( $site_title ) ) {
			$site_title = wp_parse_url( home_url(), PHP_URL_HOST );
		} else {
			$site_title = wp_specialchars_decode( $site_title, ENT_QUOTES );
		}

		return sprintf(
			/* translators: 1: Site title, 2: Number of plugins with issues */
			__( '[%1$s] Plugin Report: %2$d plugin(s) need attention', 'plugin-report' ),
			$site_title,
			$total
		);
	}


	/**
	 * Builds the email body text.
	 *
	 * @param array $warnings Warning arrays.
	 *
	 * @return string
	 */
	private static function build_email_body( $warnings ) {
		$lines = array();

		$lines[] = __( 'Plugin Report has detected the following issues with your installed plugins:', 'plugin-report' );
		$lines[] = '';

		if ( ! empty( $warnings['closed'] ) ) {
			$lines[] = __( 'Closed on wordpress.org (no longer receiving updates):', 'plugin-report' );
			$lines   = array_merge( $lines, self::format_plugin_list( $warnings['closed'] ) );
			$lines[] = '';
		}

		if ( ! empty( $warnings['stale'] ) ) {
			$lines[] = __( 'Not updated in over 2 years:', 'plugin-report' );
			$lines   = array_merge( $lines, self::format_plugin_list( $warnings['stale'] ) );
			$lines[] = '';
		}

		if ( ! empty( $warnings['untested'] ) ) {
			$lines[] = __( 'Not tested with the current major WordPress version:', 'plugin-report' );
			$lines   = array_merge( $lines, self::format_plugin_list( $warnings['untested'] ) );
			$lines[] = '';
		}

		$lines[] = sprintf(
			/* translators: %s: URL to the Plugin Report page */
			__( 'View the full report: %s', 'plugin-report' ),
			self::get_report_url()
		);

		return implode( "\n", $lines );
	}


	/**
	 * Formats a list of plugin names as bullet points.
	 *
	 * @param array $names Plugin names.
	 *
	 * @return array Lines with "- " prefix.
	 */
	private static function format_plugin_list( $names ) {
		$lines = array();
		foreach ( $names as $name ) {
			$lines[] = '- ' . $name;
		}
		return $lines;
	}


	/**
	 * Gets the URL to the Plugin Report admin page.
	 *
	 * @return string
	 */
	private static function get_report_url() {
		if ( is_multisite() ) {
			return network_admin_url( 'plugins.php?page=plugin_report' );
		}
		return admin_url( 'plugins.php?page=plugin_report' );
	}


	/**
	 * Clears the notification flag so the next health check re-evaluates.
	 */
	public static function clear_notification_flag() {
		delete_option( self::OPTION_NOTIFIED );
	}


	/**
	 * Schedules the cron event.
	 */
	public static function schedule() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time(), 'daily', self::CRON_HOOK );
		}
	}


	/**
	 * Unschedules the cron event and cleans up.
	 */
	public static function unschedule() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
		delete_option( self::OPTION_NOTIFIED );
	}
}
