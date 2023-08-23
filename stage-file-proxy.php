<?php
/*
	Plugin Name: Stage File Proxy
	Plugin URI: https://www.alley.com
	Description: Get only the files you need from your production environment. Don't ever run this in production!
	Version: 2023
	Author: Austin Smith, Alley Interactive
	Author URI: https://www.alley.com
*/

/*
 * A very important mission we have is to shut up all errors on static-looking paths, otherwise errors
 * are going to screw up the header or download & serve process. So this plugin has to execute first.
 *
 * We're also going to *assume* that if a request for /wp-content/uploads/ causes PHP to load, it's
 * going to be a 404, and we should go and get it from the remote server.
 *
 * Developers need to know that this stuff is happening and should generally understand how this plugin
 * works before they employ it.
 *
 * The dynamic resizing portion was adapted from dynamic-image-resizer.
 * See: https://wordpress.org/plugins/dynamic-image-resizer/
 */
add_action( 'activated_plugin', 'sfp_first' );
if ( stripos( $_SERVER['REQUEST_URI'], '/wp-content/uploads/' ) !== false ) {
	sfp_expect();
}
add_filter( 'wp_generate_attachment_metadata', 'sfp_generate_metadata' );
add_filter( 'intermediate_image_sizes_advanced', 'sfp_image_sizes_advanced' );

/**
 * Load SFP before anything else to silence other plugins' warnings.
 * @see https://wordpress.org/support/topic/how-to-change-plugins-load-order
 */
function sfp_first(): void {
	$plugin_path    = 'stage-file-proxy/stage-file-proxy.php';
	$active_plugins = get_option( 'active_plugins' );
	$plugin_key     = array_search( $plugin_path, $active_plugins );
	if ( $plugin_key ) { // if it's 0 it's the first plugin already, no need to continue
		array_splice( $active_plugins, $plugin_key, 1 );
		array_unshift( $active_plugins, $plugin_path );
		update_option( 'active_plugins', $active_plugins );
	}
}


/**
 * This function, triggered above, sets the chain in motion.
 */
function sfp_expect(): void {
	ob_start();
	ini_set( 'display_errors', 'off' );
	add_action( 'init', 'sfp_dispatch' );
}

/**
 * This function can fetch a remote image or resize a local one.
 *
 * If a cropped image is requested, and the original does not exist locally, it will take two runs of
 * this function to return the proper resized image, which is achieved by the header("Location: ...")
 * bits. The first run will fetch the remote image, the second will resize it.
 *
 * Ideally we could do this in one pass.
 */
function sfp_dispatch(): void {
	$mode          = sfp_get_mode();
	$relative_path = sfp_get_relative_path();
	if ( 'header' === $mode ) {
		header( 'Location: ' . sfp_get_base_url() . $relative_path );
		exit;
	}

	$doing_resize = false;
	// resize an image maybe
	if ( preg_match( '/(.+)(-r)?-([0-9]+)x([0-9]+)(c)?\.(jpe?g|png|gif)/iU', $relative_path, $matches ) ) {
		$doing_resize       = true;
		$resize             = array();
		$resize['filename'] = $matches[1] . '.' . $matches[6];
		$resize['width']    = $matches[3];
		$resize['height']   = $matches[4];
		$resize['crop']     = ! empty( $matches[5] );
		$resize['mode']     = substr( $matches[2], 1 );

		if ( 'photon' === $mode ) {
			header(
				'Location: ' . add_query_arg(
					array(
						'w'      => $resize['width'],
						'h'      => $resize['height'],
						'resize' => $resize['crop'] ? "{$resize['width']},{$resize['height']}" : null,
					),
					sfp_get_base_url() . $resize['filename']
				)
			);
			exit;
		}

		$uploads_dir = wp_upload_dir();
		$basefile    = $uploads_dir['basedir'] . '/' . $resize['filename'];
		sfp_resize_image( $basefile, $resize );
		$relative_path = $resize['filename'];
	} elseif ( 'photon' === $mode ) {
		header( 'Location: ' . sfp_get_base_url() . $relative_path );
		exit;
	}

	// Download a full-size original from the remote server.
	// If it needs to be resized, it will be on the next load.
	$remote_url = sfp_get_base_url() . $relative_path;

	/**
	 * Filter: sfp_http_request_args
	 *
	 * Alter the args of the GET request.
	 *
	 * @param array $remote_http_request_args The request arguments.
	 */
	$remote_http_request_args = apply_filters( 'sfp_http_remote_args', array( 'timeout' => 30 ) );
	$remote_request           = wp_remote_get( $remote_url, $remote_http_request_args );

	if ( is_wp_error( $remote_request ) || $remote_request['response']['code'] > 400 ) {
		// If local mode, failover to local files
		if ( 'local' === $mode ) {
			// Cache replacement image by hashed request URI
			$transient_key = 'sfp_image_' . md5( $_SERVER['REQUEST_URI'] );
			if ( false === ( $basefile = get_transient( $transient_key ) ) ) {
				$basefile = sfp_get_random_local_file_path( $doing_resize );
				set_transient( $transient_key, $basefile );
			}

			// Resize if necessary
			if ( $doing_resize ) {
				sfp_resize_image( $basefile, $resize );
			} else {
				sfp_serve_requested_file( $basefile );
			}
		} else {
			sfp_error();
		}
	}

	// we could be making some dangerous assumptions here, but if WP is setup normally, this will work:
	$path_parts = explode( '/', $remote_url );
	$name       = array_pop( $path_parts );

	if ( strpos( $name, '?' ) ) {
		list( $name, $crap ) = explode( '?', $name, 2 );
	}

	$month = array_pop( $path_parts );
	$year  = array_pop( $path_parts );

	$upload = wp_upload_bits( $name, null, $remote_request['body'], "$year/$month" );

	if ( ! $upload['error'] ) {
		// if there was some other sort of error, and the file now does not exist, we could loop on accident.
		// should think about some other strategies.
		if ( $doing_resize ) {
			sfp_dispatch();
		} else {
			sfp_serve_requested_file( $upload['file'] );
		}
	} else {
		sfp_error();
	}
}

/**
 * Resizes $basefile based on parameters in $resize
 *
 * @param string $basefile The path to the file to resize.
 * @param array  $resize   The resize parameters.
 */
function sfp_resize_image( $basefile, $resize ): void {
	if ( file_exists( $basefile ) ) {
		$suffix = $resize['width'] . 'x' . $resize['height'];
		if ( $resize['crop'] ) {
			$suffix .= 'c';
		}
		if ( 'r' == $resize['mode'] ) {
			$suffix = 'r-' . $suffix;
		}
		$img = wp_get_image_editor( $basefile );

		// wp_get_image_editor can return a WP_Error if the file exists but is corrupted.
		if ( is_wp_error( $img  ) ) {
			sfp_error();
		}

		$img->resize( $resize['width'], $resize['height'], $resize['crop'] );
		$info             = pathinfo( $basefile );
		$path_to_new_file = $info['dirname'] . '/' . $info['filename'] . '-' . $suffix . '.' . $info['extension'];
		$img->save( $path_to_new_file );
		sfp_serve_requested_file( $path_to_new_file );
	}
}

/**
 * Serve the file directly.
 *
 * @param string $filename The path to the file to serve.
 */
function sfp_serve_requested_file( $filename ): void {
	// find the mime type
	$finfo = finfo_open( FILEINFO_MIME_TYPE );
	$type  = finfo_file( $finfo, $filename );
	// serve the image this one time (next time the webserver will do it for us)
	ob_end_clean();
	header( 'Content-Type: ' . $type );
	header( 'Content-Length: ' . filesize( $filename ) );
	readfile( $filename );
	exit;
}

/**
 * Prevent WordPress from generating resized images on upload.
 *
 * @param array $sizes Associative array of image sizes to be created.
 * @return array
 */
function sfp_image_sizes_advanced( $sizes ): array {
	global $dynimg_image_sizes;

	// save the sizes to a global, because the next function needs them to lie to WP about what sizes were generated
	$dynimg_image_sizes = $sizes;

	// force WP to not make sizes by telling it there's no sizes to make
	return array();
}

/**
 * Trick WP into thinking the images were generated anyways.
 *
 * @param array $meta An array of attachment meta data.
 * @return array
 */
function sfp_generate_metadata( $meta ) {
	global $dynimg_image_sizes;

	if ( ! is_array( $dynimg_image_sizes ) ) {
		return $meta;
	}

	foreach ( $dynimg_image_sizes as $sizename => $size ) {
		// figure out what size WP would make this:
		$newsize = image_resize_dimensions( $meta['width'], $meta['height'], $size['width'], $size['height'], $size['crop'] );

		if ( $newsize ) {
			$info = pathinfo( $meta['file'] );
			$ext  = $info['extension'];
			$name = wp_basename( $meta['file'], ".$ext" );

			$suffix = "r-{$newsize[4]}x{$newsize[5]}";
			if ( $size['crop'] ) {
				$suffix .= 'c';
			}

			// build the fake meta entry for the size in question
			$resized = array(
				'file'   => "{$name}-{$suffix}.{$ext}",
				'width'  => $newsize[4],
				'height' => $newsize[5],
			);

			$meta['sizes'][ $sizename ] = $resized;
		}
	}

	return $meta;
}

/**
 * Get the relative file path by stripping out the /wp-content/uploads/ business.
 *
 * @return string The relative path.
 */
function sfp_get_relative_path() {
	static $path;
	if ( ! $path ) {
		$path = preg_replace( '/.*\/wp\-content\/uploads(\/sites\/\d+)?\//i', '', $_SERVER['REQUEST_URI'] );
	}
	/**
	 * Filters the relative path of an image in SFP.
	 *
	 * @param string $path The relative path of the file.
	 */
	$path = apply_filters( 'sfp_relative_path', $path );
	return $path;
}

/**
 * Grab a random file from a local directory and return the path.
 *
 * @return string The local path to the file.
 */
function sfp_get_random_local_file_path(): string {
	static $local_dir;
	$transient_key = 'sfp-replacement-images';
	if ( ! $local_dir ) {
		$local_dir = get_option( 'sfp_local_dir' );
		if ( ! $local_dir ) {
			$local_dir = 'sfp-images';
		}
	}

	$replacement_image_path = get_template_directory() . '/' . $local_dir . '/';

	// Cache image directory contents
	if ( false === ( $images = get_transient( $transient_key ) ) ) {
		foreach ( glob( $replacement_image_path . '*' ) as $filename ) {
			// Exclude resized images
			if ( ! preg_match( '/.+[0-9]+x[0-9]+c?\.(jpe?g|png|gif)$/iU', $filename ) ) {
				$images[] = basename( $filename );
			}
		}
		set_transient( $transient_key, $images );
	}

	$rand = wp_rand( 0, count( $images ) - 1 );
	return $replacement_image_path . $images[ $rand ];
}

/**
 * Retrieve the saved mode. See the README for the available modes.
 *
 * @return string The saved mode. Default is 'header'.
 */
function sfp_get_mode() {
	static $mode;
	if ( ! $mode ) {
		$mode = get_option( 'sfp_mode' );
		if ( ! $mode ) {
			$mode = 'header';
		}
	}
	return $mode;
}

/**
 * Get the base URL of the uploads/ directory (i.e. the first possible directory on the remote side that could store a file)
 *
 * @return string
 */
function sfp_get_base_url() {
	static $url;
	$mode = sfp_get_mode();
	if ( ! $url ) {
		$url = get_option( 'sfp_url' );
		if ( ! $url && 'local' !== $mode ) {
			sfp_error();
		}
	}
	return $url;
}

/**
 * Die with an error.
 */
function sfp_error() {
	die( 'SFP tried to load but encountered an error' );
}
