<?php
/*
	Plugin Name: Stage File Proxy
	Plugin URI: http://alleyinteractive.com/
	Description: Get only the files you need from your production environment. Don't ever run this in production!
	Version: 0.1
	Author: Austin Smith, Alley Interactive
	Author URI: http://www.alleyinteractive.com/
*/

/**
 * A very important mission we have is to shut up all errors on static-looking paths, otherwise errors
 * are going to screw up the header or download & serve process. So this plugin has to execute first.
 *
 * We're also going to *assume* that if a request for /wp-content/uploads/ causes PHP to load, it's
 * going to be a 404 and we should go and get it from the remote server.
 *
 * Developers need to know that this stuff is happening and should generally understand how this plugin 
 * works before they employ it.
 */

/**
 * Load SFP before anything else so we can shut up any other plugins' warnings.
 * @see http://wordpress.org/support/topic/how-to-change-plugins-load-order
 */
function sfp_first() {
	$plugin_path = 'stage-file-proxy/stage-file-proxy.php';
	$active_plugins = get_option( 'active_plugins' );
	$plugin_key = array_search( $plugin_path, $active_plugins );
	if ( $plugin_key ) { // if it's 0 it's the first plugin already, no need to continue
		array_splice( $active_plugins, $plugin_key, 1 );
		array_unshift( $active_plugins, $plugin_path );
		update_option( 'active_plugins', $active_plugins );
	}
}
add_action( 'activated_plugin', 'sfp_first' );

if ( stripos( $_SERVER['REQUEST_URI'], '/wp-content/uploads/' ) !== false ) sfp_expect();

/**
 * This function, triggered above, sets the chain in motion.
 */
function sfp_expect() {
	ini_set( 'display_errors', 'off' );
	add_action( 'init', 'sfp_dispatch' );
}

function sfp_dispatch() {
	$mode = sfp_get_mode();
	$remote_url = sfp_get_remote_url();
	if ( 'header' === $mode ) {
		header( "Location: $remote_url" );
		exit;
	} else {
		$remote_request = wp_remote_get( $remote_url, array( 'timeout' => 30 ) );

		if ( is_wp_error( $remote_request ) || $remote_request['response']['code'] > 400 ) {
			sfp_error();
		}

		// we could be making some dangerous assumptions here, but if WP is setup normally, this will work:
		$path_parts = explode( '/', $remote_url );
		$name = array_pop( $path_parts );

		if ( strpos( $name, '?' ) ) list( $name, $crap ) = explode( '?', $name, 2 );

		$month = array_pop( $path_parts );
		$year = array_pop( $path_parts );

		$upload = wp_upload_bits( $name, null, $remote_request['body'], "$year/$month" );

		if ( !$upload['error'] ) {
			// if there WAS an error, and the file now does not exist, we could churn on accident.
			// should think about some other strategies.
			header( "Location: {$_SERVER['REQUEST_URI']}" );
			exit;
		} else {
			sfp_error();
		}
	}
}

/**
 * Build the full URL to the remote path.
 */
function sfp_get_remote_url() {
	return sfp_get_base_url() . sfp_get_relative_path();
}

/**
 * Get the relative file path by stripping out the /wp-content/uploads/ business.
 */
function sfp_get_relative_path() {
	static $path;
	if ( !$path ) {
		$path = preg_replace( '/.*\/wp\-content\/uploads(\/sites\/\d+)?\//i', '', $_SERVER['REQUEST_URI'] );
	}
	return $path;
}

/**
 * SFP can operate in two modes, 'download' and 'header'
 */
function sfp_get_mode() {
	static $mode;
	if ( !$mode ) {
		$mode = get_option( 'sfp_mode' );
		if ( !$mode ) $mode = 'header';
	}
	return $mode;
}

/**
 * Get the base URL of the uploads directory (i.e. the first possible directory on the remote side that could store a file)
 */
function sfp_get_base_url() {
	static $url;
	if ( !$url ) {
		$url = get_option( 'sfp_url' );
		if ( !$url ) sfp_error();
	}
	return $url;
}

function sfp_error() {
	die( 'SFP tried to load, but encountered an error' );
}