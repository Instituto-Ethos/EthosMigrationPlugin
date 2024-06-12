<?php
/**
 * Plugin Name:       Ethos Migration Plugin
 * Description:       Plugin to migrate post types, taxonomies and metadata on Ethos site.
 * Version:           0.0.8
 * Requires at least: 5.2
 * Requires PHP:      7.4
 * Author:            Hacklab
 * Author URI:        https://hacklab.com.br/
 * Text Domain:       ethos-migration-plugin
 * Domain Path:       /languages
 */

function ethos_plugin_activate() {
    flush_rewrite_rules();
}

register_activation_hook( __FILE__, 'ethos_plugin_activate' );

define( 'ETHOS_MIGRATION_VERSION', '0.0.6' );
define( 'ETHOS_MIGRATION_PATH', plugins_url( '/', __FILE__ ) );

require_once( 'includes/functions.php' );