<?php
/**
 * Plugin Name:       FilterPress
 * Description:       SVG filter goodies for the block editor: grainy gradients, animated squiggle text, and press-to-deform button animations.
 * Version:           0.1.0
 * Requires at least: 6.3
 * Requires PHP:      7.4
 * Author:            FilterPress
 * License:           GPL-2.0-or-later
 * Text Domain:       filterpress
 *
 * @package FilterPress
 */

defined( 'ABSPATH' ) || exit;

define( 'FILTERPRESS_VERSION', '0.9.23' );
define( 'FILTERPRESS_PATH', plugin_dir_path( __FILE__ ) );
define( 'FILTERPRESS_URL', plugin_dir_url( __FILE__ ) );

require_once FILTERPRESS_PATH . 'includes/class-filterpress.php';

add_action( 'init', array( 'FilterPress\\FilterPress', 'init' ) );
