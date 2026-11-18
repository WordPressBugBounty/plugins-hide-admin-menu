<?php
/*
Plugin Name: Hide Admin Menu
Plugin URI: https://wordpress.org/plugins/hide-admin-menu/
Description: Hide sidebar and top admin bar menu items for each user role.
Version: 1.1.5
Author: Bhavin Thummar & Maulik Patel (BM Studio)
Author URI: https://www.linkedin.com/in/bhavin-thummar
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Text Domain: hide-admin-menu
Requires at least: 4.6
Requires PHP: 7.4
*/

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'BHM_VERSION', '1.1.4' );

/**
 * Create default options on activation.
 */
function bhm_activate() {
	add_option( 'hide_menu_bh_plugin', '' );
	add_option( 'hide_sub_menu_bh_plugin', '' );
	add_option( 'hide_top_menu_bh_plugin', '' );
	add_option( 'menu_order_bh_plugin', '' );
}
register_activation_hook( __FILE__, 'bhm_activate' );

/**
 * Read a stored option that holds a JSON array keyed by role.
 */
function bhm_read_option( $name ) {
	$raw = get_option( $name, '' );
	if ( ! is_string( $raw ) || '' === $raw || 'null' === $raw ) {
		return array();
	}
	$data = json_decode( $raw, true );
	return is_array( $data ) ? $data : array();
}

/**
 * Roles of the currently logged-in user.
 */
function bhm_current_user_roles() {
	$user = wp_get_current_user();
	return is_array( $user->roles ) ? $user->roles : array();
}

/**
 * Settings page registration.
 */
function bhm_register_menu_page() {
	add_menu_page(
		__( 'Hide Menu', 'hide-admin-menu' ),
		__( 'Hide Menu', 'hide-admin-menu' ),
		'manage_options',
		'hide-admin-menu',
		'bhm_get_menu_list',
		'dashicons-hidden'
	);
}
add_action( 'admin_menu', 'bhm_register_menu_page' );

/**
 * Store the full menu list before anything is hidden, so the settings page can show every item.
 */
function bhm_capture_side_menus() {
	global $menu, $submenu, $bhm_session;
	$bhm_session['side']     = $menu;
	$bhm_session['side_sub'] = $submenu;
}
add_action( 'admin_menu', 'bhm_capture_side_menus', 9998 );

/**
 * Hide sidebar menus for the current user's roles.
 */
function bhm_hide_side_menus() {
	global $submenu;
	$roles = bhm_current_user_roles();

	$hidden = bhm_read_option( 'hide_menu_bh_plugin' );
	foreach ( $roles as $role ) {
		if ( empty( $hidden[ $role ] ) || ! is_array( $hidden[ $role ] ) ) {
			continue;
		}
		foreach ( $hidden[ $role ] as $slug ) {
			remove_menu_page( $slug );
		}
	}

	$hidden_sub = bhm_read_option( 'hide_sub_menu_bh_plugin' );
	foreach ( $roles as $role ) {
		if ( empty( $hidden_sub[ $role ] ) || ! is_array( $hidden_sub[ $role ] ) ) {
			continue;
		}
		foreach ( $hidden_sub[ $role ] as $pair ) {
			$parts = explode( '__con__', $pair, 2 );
			if ( 2 !== count( $parts ) ) {
				continue;
			}
			list( $parent, $child ) = $parts;

			// Special case: the Customize link is stored at a fixed index in the Appearance submenu.
			if ( 'themes.php' === $parent && 'customize.php' === wp_parse_url( $child, PHP_URL_PATH ) ) {
				unset( $submenu['themes.php'][6] );
			}
			remove_submenu_page( $parent, $child );
		}
	}
}
add_action( 'admin_menu', 'bhm_hide_side_menus', 9999 );

/**
 * Store the top bar nodes before anything is removed.
 */
function bhm_capture_top_menus( $wp_admin_bar ) {
	global $bhm_session;
	$bhm_session['top'] = $wp_admin_bar->get_nodes();
}
add_action( 'admin_bar_menu', 'bhm_capture_top_menus', 9998 );

/**
 * Hide top bar items for the current user's roles.
 */
function bhm_hide_top_menus( $wp_admin_bar ) {
	$hidden = bhm_read_option( 'hide_top_menu_bh_plugin' );
	foreach ( bhm_current_user_roles() as $role ) {
		if ( empty( $hidden[ $role ] ) || ! is_array( $hidden[ $role ] ) ) {
			continue;
		}
		foreach ( $hidden[ $role ] as $node_id ) {
			$wp_admin_bar->remove_node( $node_id );
		}
	}
}
add_action( 'admin_bar_menu', 'bhm_hide_top_menus', 9999 );

/**
 * Footer text on the settings page only.
 */
function bhm_admin_footer_text( $text ) {
	$screen = get_current_screen();
	if ( $screen && false !== strpos( $screen->id, 'hide-admin-menu' ) ) {
		return sprintf(
			/* translators: %s: plugin name in bold */
			__( 'If you like %s please leave us a rating. A huge thanks in advance!', 'hide-admin-menu' ),
			'<strong>Hide Admin Menu</strong>'
		);
	}
	return $text;
}
add_filter( 'admin_footer_text', 'bhm_admin_footer_text', 1 );

require_once plugin_dir_path( __FILE__ ) . 'menu-list.php';
