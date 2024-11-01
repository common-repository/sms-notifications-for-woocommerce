<?php
/*
Plugin Name: SMS Notifications for WooCommerce
Version: 2.0.2
Plugin URI: http://mtalkz.com/
Description: Sends SMS notifications to your clients for order status changes. You can also receive an SMS message when a new order is received.
Author URI: http://skilsup.in/
Author: mTalkz, SkillsUp
Requires at least: 3.8
Tested up to: 5.8
*/

//Exit if accessed directly
if (!defined('ABSPATH')) {
    exit();
}

global $wpdb, $suwcsms_db_version, $suwcsms_db_table;
$suwcsms_db_version = 1.73;
$suwcsms_db_table = $wpdb->prefix . 'suwcsms_cart_notifications';

//Define text domain
$suwcsms_plugin_name = 'WooCommerce SMS Notification';
$suwcsms_plugin_file = plugin_basename(__FILE__);
load_plugin_textdomain('suwcsms', false, dirname($suwcsms_plugin_file) . '/languages');

//Add links to plugin listing
add_filter("plugin_action_links_$suwcsms_plugin_file", 'suwcsms_add_action_links');
function suwcsms_add_action_links($links)
{
    
    $links[] = '<a href="' . esc_url(admin_url("admin.php?page=suwcsms")) . '">Settings</a>';
    $links[] = '<a href="http://mtalkz.com/woocommerce-plugin/" target="_blank">Plugin Documentation</a>';
    return $links;
}

//Add links to plugin settings page
add_filter('plugin_row_meta', "suwcsms_plugin_row_meta", 10, 2);
function suwcsms_plugin_row_meta($links, $file)
{
    global $suwcsms_plugin_file;
    if (strpos($file, $suwcsms_plugin_file) !== false) {
        $links[] = '<a href="http://mtalkz.com/product/woocommerce-sms-plugin/" target="_blank">Get Credentials</a>';
        $links[] = '<a href="http://mtalkz.com/woocommerce-plugin/" target="_blank">Plugin Documentation</a>';
    }
    return $links;
}

//WooCommerce is required for the plugin to work
if (in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
    include('plugin-core.php');
} else {
    add_action('admin_notices', 'suwcsms_require_wc');
    function suwcsms_require_wc()
    {
        global $suwcsms_plugin_name;
        echo '<div class="error fade" id="message"><h3>' . esc_html($suwcsms_plugin_name) . '</h3><h4>' . __("This plugin requires WooCommerce", 'suwcsms') . '</h4></div>';
        deactivate_plugins($suwcsms_plugin_file);
    }
}

//Handle uninstallation
register_uninstall_hook(__FILE__, 'suwcsms_uninstaller');
function suwcsms_uninstaller()
{
    delete_option('suwcsms_settings');
}

//Create table on activation / update
register_activation_hook(__FILE__, 'suwcsms_db_install');
function suwcsms_db_install()
{
    global $wpdb, $suwcsms_db_version, $suwcsms_db_table;
    if ($suwcsms_db_version == get_option('suwcsms_db_version')) {
        return;
    }

    //Use WP built-in functionality to create tables
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    $charset_collate = $wpdb->get_charset_collate();

    //Create required tables
    $sql = "CREATE TABLE $suwcsms_db_table (
        billing_phone varchar(15) NOT NULL,
        first_name varchar(255) NOT NULL,
        order_id bigint(20) unsigned NOT NULL DEFAULT 0,
        register_ts datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        msg_sent tinyint(1) unsigned NOT NULL DEFAULT 0,
        reminder_1_sent tinyint(1) unsigned NOT NULL DEFAULT 0,
        reminder_2_sent tinyint(1) unsigned NOT NULL DEFAULT 0,
        reminder_3_sent tinyint(1) unsigned NOT NULL DEFAULT 0,
        reminder_4_sent tinyint(1) unsigned NOT NULL DEFAULT 0,
        reminder_5_sent tinyint(1) unsigned NOT NULL DEFAULT 0,
        reminder_6_sent tinyint(1) unsigned NOT NULL DEFAULT 0,
        reminder_7_sent tinyint(1) unsigned NOT NULL DEFAULT 0,
        reminder_8_sent tinyint(1) unsigned NOT NULL DEFAULT 0,
        reminder_9_sent tinyint(1) unsigned NOT NULL DEFAULT 0,
        reminder_10_sent tinyint(1) unsigned NOT NULL DEFAULT 0,
		PRIMARY KEY  (billing_phone),
        KEY resiter_ts_key (register_ts)
	) $charset_collate;";
    dbDelta($sql);

    //Update the db version
    update_option('suwcsms_db_version', $suwcsms_db_version);
}

//Create a WP-Cron interval of 5 minutes
add_filter( 'cron_schedules', 'suwcsms_add_cron_interval' );
function suwcsms_add_cron_interval( $schedules ) { 
    if ( empty( $schedules['five_minutes'] ) ) {
        $schedules['five_minutes'] = array(
            'interval' => 5 * 60,
            'display'  => esc_html__( 'Every Five Minutes' ),
        );
    }
    return $schedules;
}

//Schedule the hook
register_activation_hook(__FILE__, 'suwcsms_schedule_cron');
function suwcsms_schedule_cron() {
    if ( ! wp_next_scheduled( 'suwcsms_cron_hook' ) ) {
        $res = wp_schedule_event( time(), 'five_minutes', 'suwcsms_cron_hook' );
        if ( ! $res ) wp_die( 'Failed to schedule suwcsms_cron_hook' );
    }
}

//Disable cron on plugin deactivation
register_deactivation_hook( __FILE__, 'suwcsms_cron_deactivate' ); 
function suwcsms_cron_deactivate() {
    if ( wp_next_scheduled( 'suwcsms_cron_hook' ) ) {
        $timestamp = wp_next_scheduled( 'suwcsms_cron_hook' );
        wp_unschedule_event( $timestamp, 'suwcsms_cron_hook' );
    }
}

//Apply activation functions for upgrades as well
add_action( 'upgrader_process_complete', 'suwcsms_post_upgrade', 10, 2 );
function suwcsms_post_upgrade( $_, $options ) {
    global $suwcsms_plugin_file;
    if ( 'update' == $options['action'] && 'plugin' == $options['type'] ) {
        foreach ( $options['plugins'] as $plugin ) {
            if ( $plugin == $suwcsms_plugin_file ) {
                suwcsms_db_install();
                suwcsms_schedule_cron();
            }
        }        
    }
}
