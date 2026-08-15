<?php
/**
 * Plugin Name:       Plugin Report
 * Plugin URI:        https://wordpress.org/plugins/plugin-report/
 * Description:       Provides detailed information about currently installed plugins
 * Version:           2.2.4
 * Requires at least: 4.6
 * Requires PHP:      5.6
 * Author:            Torsten Landsiedel
 * Author URI:        https://torstenlandsiedel.de
 * License:           GPLv3
 * Network:           true
 *
 * @package Plugin_Report
 */

// If called without WordPress, exit.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( is_admin() && ! class_exists( 'Plugin_Report' ) ) {
	require_once __DIR__ . '/class-plugin-report.php';

	$plugin_report_instance = new Plugin_Report();
	$plugin_report_instance->init();
}
