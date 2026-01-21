<?php

/**
 * Plugin Name: HyperFields
 * Plugin URI: https://github.com/estebanforge/hyperfields
 * Description: A powerful custom field system for WordPress, providing metaboxes, options pages, and conditional logic.
 * Version: 1.0.8
 * Author: Esteban Cuevas
 * Author URI: https://actitud.xyz
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: hyperfields
 * Requires at least: 5.0
 * Tested up to: 6.8
 * Requires PHP: 8.1
 * Network: false
 */

// Exit if accessed directly.
defined('ABSPATH') || exit;

// Load the bootstrap file.
require_once __DIR__ . '/bootstrap.php';

// Ensure the initialization hook is registered.
// This handles the case where bootstrap.php was loaded early (e.g., via Composer)
// and couldn't register the hook because WordPress wasn't ready.
if (function_exists('hyperfields_select_and_load_latest') && !has_action('after_setup_theme', 'hyperfields_select_and_load_latest')) {
    add_action('after_setup_theme', 'hyperfields_select_and_load_latest', 0);
}
