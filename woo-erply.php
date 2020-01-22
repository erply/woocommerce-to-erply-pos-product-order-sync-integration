<?php
/*
Plugin Name: Erply Order management and POS for Woocommerce
Description: Erply Order management and POS for Woocommerce is a plugin that allows to sync data from WooCommerce store to Erply.
Version: 1.0.0
Author: Erply
Author URI: https://erply.com/
License: GPL
*/

$params = [
    "textdomain" => "wordpress",
];

/**
 * Require WooCommerce plugin
 */
add_action( 'admin_init', 'child_plugin_has_parent_plugin' );
function child_plugin_has_parent_plugin() {
    if ( is_admin() && current_user_can( 'activate_plugins' ) && !is_plugin_active( 'woocommerce/woocommerce.php' ) ) {
        add_action( 'admin_notices', 'require_plugins_notice' );

        deactivate_plugins( plugin_basename( __FILE__ ) );

        if ( isset( $_GET['activate'] ) ) {
            unset( $_GET['activate'] );
        }
    }
}

/**
 * Notice to output when some of required plugins are not active
 */
function require_plugins_notice(){
    echo '<div class="error"><p>' . __( "Woo-Erply Integration plugin requires WooCommerce plugin to be installed and active", "wordpress" ) . '</p></div>';
}

// load classes
spl_autoload_register( function ( $class_name ) {
    $classes_dir = plugin_dir_path( __FILE__ ) . '/classes/class-';
    $file = $classes_dir . strtolower( str_replace( '_', '-', $class_name ) ) . '.php';
    if( file_exists( $file ) ) require_once( $file );
} );

new Woo_Erply_Flow( $params );

register_activation_hook( __FILE__, function(){
    flush_rewrite_rules();
    update_option( "sync_orders_immediately", 1 );
	update_option( "allw_unsync_products", 1 );
} );
register_deactivation_hook( __FILE__, function(){ flush_rewrite_rules(); } );
