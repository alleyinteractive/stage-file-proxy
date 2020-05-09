<?php
/*
 * My Photon main object
 */

class SFP_Content_Filters {
	/**
	 * Class variables
	 */
	// Oh look, a singleton
	private static $__instance = null;

	// Allowed extensions must match http://code.trac.wordpress.org/browser/photon/index.php#L31
	protected static $extensions = array(
		'gif',
		'jpg',
		'jpeg',
		'png'
	);

	// Don't access this directly. Instead, use self::image_sizes() so it's actually populated with something.
	protected static $image_sizes = null;

	/**
	 * Singleton implementation
	 *
	 * @return object
	 */
	public static function instance() {
		if ( ! is_a( self::$__instance, 'SFP_Content_Filters' ) ) {
			self::$__instance = new SFP_Content_Filters;
			self::$__instance->setup();
		}

		return self::$__instance;
	}

	/**
	 * Silence is golden.
	 */
	private function __construct() {}

	/**
	 * Register actions and filters, but only if basic Photon functions are available.
	 * The basic functions are found in ./functions.photon.php.
	 *
	 * @uses add_action, add_filter
	 * @return null
	 */
	private function setup() {
		// Images in post content and galleries
		add_filter( 'the_content', array( __CLASS__, 'filter_the_content' ), 999999 );
		add_filter( 'get_post_gallery', array( __CLASS__, 'filter_the_content' ), 999999 );

		// Core image retrieval
		add_filter( 'image_downsize', array( $this, 'filter_image_downsize' ), 10, 3 );
	}


	/**
	 ** IN-CONTENT IMAGE MANIPULATION FUNCTIONS
	 **/

	/**
	 * Match all images and any relevant <a> tags in a block of HTML.
	 *
	 * @param string $content Some HTML.
	 * @return array An array of $images matches, where $images[0] is
	 *         an array of full matches, and the link_url, img_tag,
	 *         and img_url keys are arrays of those matches.
	 */
	public static function parse_images_from_html( $content ) {
		$images = array();

		if ( preg_match_all( '#(?:<a[^>]+?href=["|\'](?P<link_url>[^\s]+?)["|\'][^>]*?>\s*)?(?P<img_tag><img[^>]+?src=["|\'](?P<img_url>[^\s]+?)["|\'].*?>){1}(?:\s*</a>)?#is', $content, $images ) ) {
			foreach ( $images as $key => $unused ) {
				// Simplify the output as much as possible, mostly for confirming test results.
				if ( is_numeric( $key ) && $key > 0 )
					unset( $images[$key] );
			}

			return $images;
		}

		return array();
	}

	protected function replace_url( $url ) {
		return sfp_get_url( preg_replace( '/.*\/wp\-content\/uploads(\/sites\/\d+)?\//i', '', $url ) );
	}

	/**
	 * Identify images in post content, and if images are local (uploaded to the
	 * source database), pass through SFP.
	 *
	 * @param string $content
	 * @filter the_content
	 * @return string
	 */
	public static function filter_the_content( $content ) {

		$images = SFP_Content_Filters::parse_images_from_html( $content );

		if ( ! empty( $images ) ) {

			foreach ( $images[0] as $index => $tag ) {
				$src = $src_orig = $images['img_url'][ $index ];

				// Allow specific images to be skipped
				if ( apply_filters( 'sfp_skip_image', false, $src, $tag ) ) {
					continue;
				}

				// Check if image URL should be modified
				if ( self::validate_image_url( $src ) ) {

					$image_url = $this->replace_url( $src );

					// Modify image tag
					// Ensure changes are only applied to the current image by copying and modifying the matched tag, then replacing the entire tag with our modified version.
					if ( $src != $image_url ) {
						$new_tag = $tag;

						// If present, replace the link href too.
						if ( ! empty( $images['link_url'][ $index ] ) && self::validate_image_url( $images['link_url'][ $index ] ) ) {
							$new_tag = preg_replace( '#(href=["|\'])' . $images['link_url'][ $index ] . '(["|\'])#i', '\1' . $this->replace_url( $images['link_url'][ $index ] ) . '\2', $new_tag, 1 );
						}

						// Supplant the original source value with our URL
						$image_url = esc_url( $image_url );
						$new_tag = str_replace( $src_orig, $image_url, $new_tag );

						// Replace original tag with modified version
						$content = str_replace( $tag, $new_tag, $content );
					}
				} elseif ( false !== strpos( $src, sfp_get_base_url() ) && ! empty( $images['link_url'][ $index ] ) && self::validate_image_url( $images['link_url'][ $index ] ) ) {
					$new_tag = preg_replace( '#(href=["|\'])' . $images['link_url'][ $index ] . '(["|\'])#i', '\1' . $this->replace_url( $images['link_url'][ $index ] ) . '\2', $tag, 1 );

					$content = str_replace( $tag, $new_tag, $content );
				}
			}
		}

		return $content;
	}

	/**
	 ** CORE IMAGE RETRIEVAL
	 **/

	/**
	 * Filter post thumbnail image retrieval, passing images through Photon
	 *
	 * @param string|bool $image
	 * @param int $attachment_id
	 * @param string|array $size
	 * @uses is_admin, apply_filters, wp_get_attachment_url, self::validate_image_url, this::image_sizes, my_photon_url
	 * @filter image_downsize
	 * @return string|bool
	 */
	public function filter_image_downsize( $image, $attachment_id, $size ) {
		// Don't foul up the admin side of things, and provide plugins a way of preventing Photon from being applied to images.
		if ( is_admin() || apply_filters( 'sfp_override_image_downsize', false, compact( 'image', 'attachment_id', 'size' ) ) ) {
			return $image;
		}

		// Get the image URL
		$image_url = wp_get_attachment_url( $attachment_id );

		if ( $image_url ) {
			// Check if image URL should be used with SFP
			if ( ! self::validate_image_url( $image_url ) ) {
				return $image;
			}

			// If an image is requested with a size known to WordPress, use that size's settings with Photon
			if ( ( is_string( $size ) || is_int( $size ) ) && array_key_exists( $size, self::image_sizes() ) ) {
				$image_args = self::image_sizes();
				$image_args = $image_args[ $size ];

				$photon_args = array();

				// `full` is a special case in WP
				// To ensure filter receives consistent data regardless of requested size, `$image_args` is overridden with dimensions of original image.
				if ( 'full' == $size ) {
					$image_meta = wp_get_attachment_metadata( $attachment_id );
					if ( isset( $image_meta['width'], $image_meta['height'] ) ) {
						// 'crop' is true so Photon's `resize` method is used
						$image_args = array(
							'width'  => $image_meta['width'],
							'height' => $image_meta['height'],
							'crop'   => true
						);
					}
				}

				// Expose determined arguments to a filter before passing to Photon
				$transform = $image_args['crop'] ? 'resize' : 'fit';

				// Check specified image dimensions and account for possible zero values; photon fails to resize if a dimension is zero.
				if ( 0 == $image_args['width'] || 0 == $image_args['height'] ) {
					if ( 0 == $image_args['width'] && 0 < $image_args['height'] )
						$photon_args['h'] = $image_args['height'];
					elseif ( 0 == $image_args['height'] && 0 < $image_args['width'] )
						$photon_args['w'] = $image_args['width'];
				} else {
					if( 'resize' == $transform ) {
						// Lets make sure that we don't upscale images since wp never upscales them as well
						$image_meta = wp_get_attachment_metadata( $attachment_id );

						$smaller_width  = ( ( $image_meta['width']  < $image_args['width']  ) ? $image_meta['width']  : $image_args['width']  );
						$smaller_height = ( ( $image_meta['height'] < $image_args['height'] ) ? $image_meta['height'] : $image_args['height'] );

						$photon_args[ $transform ] = $smaller_width . ',' . $smaller_height;
					} else {
						$photon_args[ $transform ] = $image_args['width'] . ',' . $image_args['height'];
					}

				}

				$photon_args = apply_filters( 'my_photon_image_downsize_string', $photon_args, compact( 'image_args', 'image_url', 'attachment_id', 'size', 'transform' ) );

				// Generate Photon URL
				$image = array(
					my_photon_url( $image_url, $photon_args ),
					false,
					false
				);
			} elseif ( is_array( $size ) ) {
				// Pull width and height values from the provided array, if possible
				$width = isset( $size[0] ) ? (int) $size[0] : false;
				$height = isset( $size[1] ) ? (int) $size[1] : false;

				// Don't bother if necessary parameters aren't passed.
				if ( ! $width || ! $height )
					return $image;

				// Expose arguments to a filter before passing to Photon
				$photon_args = array(
					'fit' => $width . ',' . $height
				);

				$photon_args = apply_filters( 'my_photon_image_downsize_array', $photon_args, compact( 'width', 'height', 'image_url', 'attachment_id' ) );

				// Generate Photon URL
				$image = array(
					my_photon_url( $image_url, $photon_args ),
					false,
					false
				);
			}
		}

		return $image;
	}

	public function get_sized_image_url( $id, $size ) {
		$img_url = wp_get_attachment_url( $id );
		$meta = wp_get_attachment_metadata( $id );
		$img_url_basename = wp_basename( $img_url );

		// try for a new style intermediate size
		if ( $intermediate = image_get_intermediate_size( $id, $size ) ) {
			$img_url = str_replace( $img_url_basename, $intermediate['file'], $img_url );
		} elseif ( 'thumbnail' == $size ) {
			// fall back to the old thumbnail
			if ( ( $thumb_file = wp_get_attachment_thumb_file( $id ) ) && $info = getimagesize( $thumb_file ) ) {
				$img_url = str_replace( $img_url_basename, wp_basename( $thumb_file ), $img_url );
			}
		}

		return $image_url;
	}

	/**
	 ** GENERAL FUNCTIONS
	 **/

	/**
	 * Ensure image URL is valid for Photon.
	 * Though Photon functions address some of the URL issues, we should avoid unnecessary processing if we know early on that the image isn't supported.
	 *
	 * @param string $url
	 * @uses wp_parse_args
	 * @return bool
	 */
	protected static function validate_image_url( $url ) {
		$parsed_url = @parse_url( $url );

		if ( ! $parsed_url )
			return false;

		// Parse URL and ensure needed keys exist, since the array returned by `parse_url` only includes the URL components it finds.
		$url_info = wp_parse_args( $parsed_url, array(
			'scheme' => null,
			'host'   => null,
			'port'   => null,
			'path'   => null
		) );

		// Bail if scheme isn't http or port is set that isn't port 80
		if ( ( 'http' != $url_info['scheme'] || ! in_array( $url_info['port'], array( 80, null ) ) ) && apply_filters( 'my_photon_reject_https', true ) )
			return false;

		// Bail if no host is found
		if ( is_null( $url_info['host'] ) )
			return false;

		// Bail if the image alredy went through Photon
		if ( false !== strpos( sfp_get_base_url(), $url_info['host'] ) )
			return false;

		// Bail if no path is found
		if ( is_null( $url_info['path'] ) )
			return false;

		// Ensure image extension is acceptable
		if ( ! in_array( strtolower( pathinfo( $url_info['path'], PATHINFO_EXTENSION ) ), self::$extensions ) )
			return false;

		// If we got this far, we should have an acceptable image URL
		// But let folks filter to decline if they prefer.
		return apply_filters( 'photon_validate_image_url', true, $url, $parsed_url );
	}

	/**
	 * Checks if the file exists before it passes the file to photon
	 *
	 * @param string $src The image URL
	 * @return string
	 **/
	protected static function strip_image_dimensions_maybe( $src ) {
		$stripped_src = $src;

		// Build URL, first removing WP's resized string so we pass the original image to Photon
		if ( preg_match( '#(-\d+x\d+)\.(' . implode('|', self::$extensions ) . '){1}$#i', $src, $src_parts ) ) {
			$stripped_src = str_replace( $src_parts[1], '', $src );
			$upload_dir = wp_upload_dir();

			// Extracts the file path to the image minus the base url
			$file_path = substr( $stripped_src, strlen ( $upload_dir['baseurl'] ) );

			if( file_exists( $upload_dir["basedir"] . $file_path ) )
				$src = $stripped_src;
		}

		return $src;
	}

	/**
	 * Provide an array of available image sizes and corresponding dimensions.
	 * Similar to get_intermediate_image_sizes() except that it includes image sizes' dimensions, not just their names.
	 *
	 * @global $wp_additional_image_sizes
	 * @uses get_option
	 * @return array
	 */
	protected static function image_sizes() {
		if ( null == self::$image_sizes ) {
			global $_wp_additional_image_sizes;

			// Populate an array matching the data structure of $_wp_additional_image_sizes so we have a consistent structure for image sizes
			$images = array(
				'thumb'  => array(
					'width'  => intval( get_option( 'thumbnail_size_w' ) ),
					'height' => intval( get_option( 'thumbnail_size_h' ) ),
					'crop'   => (bool) get_option( 'thumbnail_crop' )
				),
				'medium' => array(
					'width'  => intval( get_option( 'medium_size_w' ) ),
					'height' => intval( get_option( 'medium_size_h' ) ),
					'crop'   => false
				),
				'large'  => array(
					'width'  => intval( get_option( 'large_size_w' ) ),
					'height' => intval( get_option( 'large_size_h' ) ),
					'crop'   => false
				),
				'full'   => array(
					'width'  => null,
					'height' => null,
					'crop'   => false
				)
			);

			// Compatibility mapping as found in wp-includes/media.php
			$images['thumbnail'] = $images['thumb'];

			// Update class variable, merging in $_wp_additional_image_sizes if any are set
			if ( is_array( $_wp_additional_image_sizes ) && ! empty( $_wp_additional_image_sizes ) )
				self::$image_sizes = array_merge( $images, $_wp_additional_image_sizes );
			else
				self::$image_sizes = $images;
		}

		return is_array( self::$image_sizes ) ? self::$image_sizes : array();
	}

}
SFP_Content_Filters::instance();