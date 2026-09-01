<?php
/**
 * Plugin Name: RCMI Toolkit
 * Description: Custom Gutenberg blocks and tools for the RCMI theme — parallax hero, impact strip (tabs), role selector, impact stats, card grids, quote block, CTA band, Spectra integration, and lightweight cookieless analytics.
 * Version: 1.1.0
 * Author: UH RCMI Web Team
 * License: GPL-2.0-or-later
 * Text Domain: rcmi-toolkit
 *
 * @package rcmi-toolkit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'RCMI_TOOLKIT_VERSION', '1.1.0' );
define( 'RCMI_TOOLKIT_PATH', plugin_dir_path( __FILE__ ) );
define( 'RCMI_TOOLKIT_URL', plugin_dir_url( __FILE__ ) );
define( 'RCMI_TOOLKIT_GITHUB_USER', 'andy741231' );
define( 'RCMI_TOOLKIT_GITHUB_REPO', 'rcmi-toolkit' );

// Lightweight, cookieless, first-party analytics. See includes/class-rcmi-analytics.php.
require_once RCMI_TOOLKIT_PATH . 'includes/class-rcmi-analytics.php';
require_once RCMI_TOOLKIT_PATH . 'includes/class-rcmi-analytics-admin.php';

function rcmi_toolkit_github_updates_disabled() {
	return 'production' !== wp_get_environment_type() || is_dir( __DIR__ . '/.git' );
}

function rcmi_toolkit_blog_hero_group_attributes( $args, $block_type ) {
	if ( 'core/group' !== $block_type ) {
		return $args;
	}
	$args['attributes']['rcmiBlogHeroCustomHeight']  = array( 'type' => 'boolean', 'default' => false );
	$args['attributes']['rcmiBlogHeroDesktopHeight'] = array( 'type' => 'number', 'default' => 70 );
	$args['attributes']['rcmiBlogHeroMobileHeight']  = array( 'type' => 'number', 'default' => 65 );
	return $args;
}
add_filter( 'register_block_type_args', 'rcmi_toolkit_blog_hero_group_attributes', 10, 2 );

/**
 * Convert a hex color to an rgba() string with the given alpha.
 *
 * @param string $hex   Hex color (with or without leading #).
 * @param float  $alpha Opacity 0–1.
 * @return string rgba(r,g,b,a) value.
 */
function rcmi_toolkit_hex_to_rgba( $hex, $alpha = 1 ) {
	$hex = ltrim( $hex, '#' );
	if ( strlen( $hex ) === 3 ) {
		$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
	}
	$r = hexdec( substr( $hex, 0, 2 ) );
	$g = hexdec( substr( $hex, 2, 2 ) );
	$b = hexdec( substr( $hex, 4, 2 ) );
	return sprintf( 'rgba(%d,%d,%d,%s)', $r, $g, $b, number_format( $alpha, 2 ) );
}

/**
 * Migrate old simple scrim attributes (scrimColor/scrimOpacity) to
 * the new multi-stop format. Returns a 3-stop gradient array.
 */
function rcmi_toolkit_migrate_scrim_stops( $attrs, $key = 'scrimStops' ) {
	if ( ! empty( $attrs[ $key ] ) ) {
		return $attrs[ $key ];
	}
	// Only apply scrimColor/scrimOpacity fallback for the default key.
	if ( 'scrimStops' === $key ) {
		$color   = $attrs['scrimColor'] ?? '#ffffff';
		$opacity = $attrs['scrimOpacity'] ?? 0.9;
		return array(
			array( 'color' => $color, 'opacity' => $opacity, 'position' => 0 ),
			array( 'color' => $color, 'opacity' => $opacity * 0.6, 'position' => 50 ),
			array( 'color' => $color, 'opacity' => 0, 'position' => 100 ),
		);
	}
	// For non-default keys (e.g. globalScrimStops), return a sensible default.
	return array(
		array( 'color' => '#ffffff', 'opacity' => 0.9, 'position' => 0 ),
		array( 'color' => '#ffffff', 'opacity' => 0.54, 'position' => 50 ),
		array( 'color' => '#ffffff', 'opacity' => 0, 'position' => 100 ),
	);
}

/**
 * Build a CSS gradient string from an array of color stops.
 *
 * @param array  $stops  Array of [ 'color' => hex, 'opacity' => 0-1, 'position' => 0-100 ].
 * @param string $type   'linear' or 'radial'.
 * @param int    $angle  Angle in degrees (for linear only).
 * @return string CSS gradient value (without the `background:` wrapper).
 */
function rcmi_toolkit_build_gradient( $stops, $type = 'linear', $angle = 90 ) {
	if ( empty( $stops ) || ! is_array( $stops ) ) {
		return 'linear-gradient(0deg, transparent, transparent)';
	}

	$parts = array();
	foreach ( $stops as $stop ) {
		$color    = $stop['color'] ?? '#ffffff';
		$opacity  = isset( $stop['opacity'] ) ? floatval( $stop['opacity'] ) : 1;
		$position = intval( $stop['position'] ?? 0 );
		$parts[]  = rcmi_toolkit_hex_to_rgba( $color, $opacity ) . ' ' . $position . '%';
	}

	$stops_str = implode( ', ', $parts );

	if ( $type === 'radial' ) {
		return 'radial-gradient(circle at center, ' . $stops_str . ')';
	}

	return 'linear-gradient(' . intval( $angle ) . 'deg, ' . $stops_str . ')';
}

// ============================================================================
// GitHub-based auto-update system (commit-based, no tags required)
// Checks the latest commit on the main branch and surfaces updates in
// WP Admin → Plugins as native "Update now" links. No third-party libs.
// ============================================================================

/**
 * Fetch the latest commit info from the GitHub API.
 * Cached for 6 hours in a transient to avoid rate-limiting.
 *
 * @return array|false Commit data or false on failure.
 */
function rcmi_toolkit_get_github_commit() {
	$cache = get_transient( 'rcmi_toolkit_github_commit' );
	if ( false !== $cache ) {
		return $cache;
	}

	// Fetch the latest commit on the main branch.
	$url = sprintf(
		'https://api.github.com/repos/%s/%s/commits/main',
		RCMI_TOOLKIT_GITHUB_USER,
		RCMI_TOOLKIT_GITHUB_REPO
	);

	$response = wp_remote_get( $url, array(
		'headers' => array( 'Accept' => 'application/vnd.github.v3+json' ),
		'timeout' => 10,
	) );

	if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
		set_transient( 'rcmi_toolkit_github_commit', false, 30 * MINUTE_IN_SECONDS );
		return false;
	}

	$body = json_decode( wp_remote_retrieve_body( $response ), true );
	if ( empty( $body['sha'] ) ) {
		set_transient( 'rcmi_toolkit_github_commit', false, 30 * MINUTE_IN_SECONDS );
		return false;
	}

	$sha       = $body['sha'];
	$short_sha = substr( $sha, 0, 7 );
	$commit    = $body['commit'] ?? array();
	$message   = $commit['message'] ?? '';
	$date      = $commit['committer']['date'] ?? '';
	$html_url  = $body['html_url'] ?? '';

	// Download URL: codeload ZIP of the main branch at this commit.
	// Using the SHA ensures we download the exact version we checked.
	$download_url = sprintf(
		'https://codeload.github.com/%s/%s/zip/refs/heads/main',
		RCMI_TOOLKIT_GITHUB_USER,
		RCMI_TOOLKIT_GITHUB_REPO
	);

	$data = array(
		'sha'          => $sha,
		'short_sha'    => $short_sha,
		'message'      => $message,
		'date'         => $date,
		'html_url'     => $html_url,
		'download_url' => $download_url,
	);

	set_transient( 'rcmi_toolkit_github_commit', $data, 6 * HOUR_IN_SECONDS );
	return $data;
}

/**
 * Get the commit SHA that is currently installed.
 *
 * Stored as a WP option, updated after each successful upgrade.
 * Falls back to RCMI_TOOLKIT_VERSION for backward compatibility.
 *
 * @return string Installed commit SHA or version string.
 */
function rcmi_toolkit_get_installed_sha() {
	$sha = get_option( 'rcmi_toolkit_installed_sha' );
	if ( ! empty( $sha ) ) {
		return $sha;
	}
	// Backward compat: if no SHA stored, use the plugin version.
	// This ensures existing installs get an update offer on first check.
	return RCMI_TOOLKIT_VERSION;
}

/**
 * Inject update data into the WP update transient.
 *
 * Compares the installed commit SHA against the latest GitHub commit.
 * If they differ, offers an update.
 *
 * @param object $transient The update_plugins transient.
 * @return object
 */
function rcmi_toolkit_check_for_updates( $transient ) {
	$plugin_slug = plugin_basename( __FILE__ );
	if ( rcmi_toolkit_github_updates_disabled() ) {
		if ( isset( $transient->response[ $plugin_slug ] ) ) {
			unset( $transient->response[ $plugin_slug ] );
		}
		return $transient;
	}
	if ( empty( $transient->checked ) ) {
		return $transient;
	}

	$commit = rcmi_toolkit_get_github_commit();
	if ( ! $commit ) {
		return $transient;
	}

	$installed_sha = rcmi_toolkit_get_installed_sha();

	// Only offer an update if the remote SHA differs from what's installed.
	if ( $commit['sha'] === $installed_sha ) {
		return $transient;
	}

	$update = (object) array(
		'slug'        => dirname( $plugin_slug ),
		'plugin'      => $plugin_slug,
		'new_version' => $commit['short_sha'],
		'url'         => $commit['html_url'],
		'package'     => $commit['download_url'],
		'tested'      => '7.0',
		'icons'       => array(),
		'banners'     => array(),
	);

	$transient->response[ $plugin_slug ] = $update;

	return $transient;
}
add_filter( 'pre_set_site_transient_update_plugins', 'rcmi_toolkit_check_for_updates' );

/**
 * Populate the "View details" popup with GitHub commit info.
 *
 * @param false|object|array $result  The result object or array.
 * @param string             $action  The plugins_api action.
 * @param object             $args    Extra arguments.
 * @return false|object
 */
function rcmi_toolkit_plugins_api_info( $result, $action, $args ) {
	if ( 'plugin_information' !== $action ) {
		return $result;
	}
	if ( empty( $args->slug ) || 'rcmi-toolkit' !== $args->slug ) {
		return $result;
	}

	$commit = rcmi_toolkit_get_github_commit();
	if ( ! $commit ) {
		return $result;
	}

	// Build a readable changelog from the commit message.
	$changelog = $commit['message'] ?: 'See GitHub commit history for details.';
	$changelog = wp_kses_post( nl2br( esc_html( $changelog ) ) );

	return (object) array(
		'name'          => 'RCMI Toolkit',
		'slug'          => 'rcmi-toolkit',
		'version'       => $commit['short_sha'],
		'author'        => 'UH RCMI Web Team',
		'homepage'      => $commit['html_url'],
		'short_description' => 'Custom Gutenberg blocks and tools for the RCMI theme.',
		'sections'      => array(
			'description' => 'Custom Gutenberg blocks and tools for the RCMI theme — parallax hero, impact strip (tabs), role selector, impact stats, quote block, CTA band, and Spectra integration.',
			'changelog'   => $changelog,
		),
		'last_updated'  => $commit['date'],
		'download_link' => $commit['download_url'],
	);
}
add_filter( 'plugins_api', 'rcmi_toolkit_plugins_api_info', 10, 3 );

/**
 * Rename the extracted GitHub ZIP folder ("rcmi-toolkit-main") to the
 * plugin's real folder name ("rcmi-toolkit") BEFORE WordPress computes
 * the install destination from basename($source).
 *
 * This is the reliable fix for the problem the old post_install rename
 * caused: the result array is passed to upgrader_post_install by value,
 * so changing destination_name there never reached the caller - the
 * AJAX update handler then called get_plugins() on the ZIP's folder
 * name (which our old filter had deleted), producing the
 * "Trying to access array offset on false" warning in ajax-actions.php.
 *
 * The extracted folder lives in wp-content/upgrade/ and is never locked,
 * so rename() works on every platform including Windows.
 */
function rcmi_toolkit_fix_source_folder( $source, $remote_source, $upgrader, $hook_extra ) {
	if ( isset( $hook_extra['plugin'] ) && false !== strpos( $hook_extra['plugin'], 'rcmi-toolkit' ) && rcmi_toolkit_github_updates_disabled() ) {
		return new WP_Error( 'rcmi_toolkit_updates_disabled', 'RCMI Toolkit updates are disabled in development and Git working copies.' );
	}
	if ( is_wp_error( $source ) || ! isset( $hook_extra['plugin'] ) ) {
		return $source;
	}
	if ( false === strpos( $hook_extra['plugin'], 'rcmi-toolkit' ) ) {
		return $source;
	}
	$expected = 'rcmi-toolkit';
	if ( basename( $source ) === $expected ) {
		return $source;
	}
	$new_source = trailingslashit( dirname( untrailingslashit( $source ) ) ) . $expected;
	if ( @rename( untrailingslashit( $source ), $new_source ) ) {
		return trailingslashit( $new_source );
	}
	return $source;
}
add_filter( 'upgrader_source_selection', 'rcmi_toolkit_fix_source_folder', 10, 4 );

/**
 * Prevent WordPress from aborting the update when the old plugin folder
 * cannot be deleted. On Windows, the active plugin's files are locked by
 * PHP (opcache) and delete fails, which would otherwise abort the install.
 *
 * By returning true here, the install proceeds: WordPress's copy step
 * (copy_dir with overwrite=true) overwrites the old files in place.
 */
function rcmi_toolkit_skip_clear_destination( $removed, $local_destination, $remote_destination, $hook_extra ) {
	if ( ! isset( $hook_extra['plugin'] ) ) {
		return $removed;
	}
	if ( false === strpos( $hook_extra['plugin'], 'rcmi-toolkit' ) ) {
		return $removed;
	}
	// Override WordPress's delete_old_plugin (which fails on Windows
	// because locked files can't be deleted). Return true so the install
	// proceeds - the copy step overwrites files in place (copy_dir uses
	// overwrite=true).
	return true;
}
add_filter( 'upgrader_clear_destination', 'rcmi_toolkit_skip_clear_destination', 20, 4 );

/**
 * Post-install bookkeeping: clears the plugin cache and records the
 * installed commit SHA. All file placement is handled by WordPress itself
 * because rcmi_toolkit_fix_source_folder() renames the extracted ZIP
 * folder before the destination path is computed.
 *
 * @param bool   $response    Install response.
 * @param array  $hook_extra  Extra arguments.
 * @param array  $result      Installation result data.
 * @return array
 */
function rcmi_toolkit_post_install_rename( $response, $hook_extra, $result ) {
	if ( ! isset( $hook_extra['plugin'] ) ) {
		return $result;
	}
	if ( false === strpos( $hook_extra['plugin'], 'rcmi-toolkit' ) ) {
		return $result;
	}

	// Clear the plugin cache so get_plugins() sees the new files.
	wp_clean_plugins_cache();

	// Clear PHP's file stat cache so the editor sees updated files.
	// On IIS with persistent FastCGI processes, PHP caches file metadata
	// and doesn't notice replaced files until the stat cache expires.
	clearstatcache( true );

	// Reset opcache if available — forces PHP to re-read all PHP files.
	if ( function_exists( 'opcache_reset' ) ) {
		opcache_reset();
	}

	// Record the commit SHA we just installed so we don't re-offer the
	// same update. The SHA is fetched from GitHub (cached transient).
	$commit = rcmi_toolkit_get_github_commit();
	if ( $commit && ! empty( $commit['sha'] ) ) {
		update_option( 'rcmi_toolkit_installed_sha', $commit['sha'] );
	}

	// Clear the commit cache so the next check fetches fresh data.
	delete_transient( 'rcmi_toolkit_github_commit' );

	return $result;
}
add_filter( 'upgrader_post_install', 'rcmi_toolkit_post_install_rename', 10, 3 );

/**
 * Force a re-check of updates (clears the transient cache).
 * Hooked to admin_init so the ?rcmi_toolkit_check_updates=1 link
 * triggers an immediate GitHub API call — no 6-hour wait.
 */
function rcmi_toolkit_maybe_refresh_release_cache() {
	if ( rcmi_toolkit_github_updates_disabled() ) {
		return;
	}
	if ( isset( $_GET['rcmi_toolkit_check_updates'] ) ) {
		// Clear the cached commit data so the next call hits GitHub.
		delete_transient( 'rcmi_toolkit_github_commit' );

		// Clear WordPress's own update transient so our filter runs again.
		delete_site_transient( 'update_plugins' );

		// Re-fetch the commit and re-populate the update transient.
		rcmi_toolkit_get_github_commit();

		// Redirect back to the plugins page without the query arg,
		// so a refresh doesn't re-trigger the check.
		$redirect = remove_query_arg( 'rcmi_toolkit_check_updates' );
		wp_safe_redirect( $redirect );
		exit;
	}
}
add_action( 'admin_init', 'rcmi_toolkit_maybe_refresh_release_cache' );

/**
 * Add a "Check for updates" link to the plugin's action row on the
 * Plugins page. Clicking it forces an immediate GitHub API check
 * instead of waiting for the 6-hour transient cache to expire.
 *
 * @param array  $links  Existing action links.
 * @param string $file   Plugin basename.
 * @return array
 */
function rcmi_toolkit_add_check_updates_link( $links, $file ) {
	if ( rcmi_toolkit_github_updates_disabled() || plugin_basename( __FILE__ ) !== $file ) {
		return $links;
	}

	$url = add_query_arg( 'rcmi_toolkit_check_updates', '1', admin_url( 'plugins.php' ) );
	$check_link = '<a href="' . esc_url( $url ) . '">Check for updates</a>';

	// Prepend so it appears first (before Activate/Deactivate).
	array_unshift( $links, $check_link );
	return $links;
}
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'rcmi_toolkit_add_check_updates_link', 10, 2 );

/**
 * Show the installed GitHub commit beside the plugin version on the
 * WordPress Plugins screen.
 *
 * @param array  $plugin_meta Existing plugin metadata links.
 * @param string $plugin_file Plugin basename.
 * @return array
 */
function rcmi_toolkit_add_commit_meta( $plugin_meta, $plugin_file ) {
	if ( plugin_basename( __FILE__ ) !== $plugin_file ) {
		return $plugin_meta;
	}

	$installed_sha = rcmi_toolkit_get_installed_sha();
	if ( empty( $installed_sha ) || RCMI_TOOLKIT_VERSION === $installed_sha ) {
		return $plugin_meta;
	}

	$commit_url = sprintf(
		'https://github.com/%s/%s/commit/%s',
		RCMI_TOOLKIT_GITHUB_USER,
		RCMI_TOOLKIT_GITHUB_REPO,
		$installed_sha
	);
	$commit_meta = sprintf(
		'Commit: <a href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
		esc_url( $commit_url ),
		esc_html( substr( $installed_sha, 0, 7 ) )
	);

	$plugin_meta[] = $commit_meta;
	return $plugin_meta;
}
add_filter( 'plugin_row_meta', 'rcmi_toolkit_add_commit_meta', 10, 2 );

/**
 * Register the custom block category.
 *
 * @param array  $categories Existing block categories.
 * @return array
 */
function rcmi_toolkit_category( $categories ) {
	return array_merge(
		$categories,
		array(
			array(
				'slug'  => 'rcmi-stories',
				'title' => __( 'Story Sections', 'rcmi-toolkit' ),
				'icon'  => 'book-alt',
			),
			array(
				'slug'  => 'rcmi-sections',
				'title' => __( 'RCMI Sections', 'rcmi-toolkit' ),
				'icon'  => 'layout',
			),
		)
	);
}
add_filter( 'block_categories_all', 'rcmi_toolkit_category' );

function rcmi_toolkit_post_story_blocks( $allowed_block_types, $editor_context ) {
	if ( empty( $editor_context->post ) || 'post' !== $editor_context->post->post_type ) {
		return $allowed_block_types;
	}
	return array(
		'rcmi/story-featured-image',
		'rcmi/story-text',
		'rcmi/story-image',
		'rcmi/story-split',
		'rcmi/story-quote',
		'rcmi/story-immersive',
	);
}
add_filter( 'allowed_block_types_all', 'rcmi_toolkit_post_story_blocks', 20, 2 );

function rcmi_toolkit_render_story_media( $block_content, $block ) {
	$name = $block['blockName'] ?? '';
	if ( 0 !== strpos( $name, 'rcmi/story-' ) ) {
		return $block_content;
	}
	$image_url = $block['attrs']['imageUrl'] ?? '';
	if ( ! $image_url && in_array( $name, array( 'rcmi/story-featured-image', 'rcmi/story-image', 'rcmi/story-immersive' ), true ) ) {
		return '';
	}
	if ( ! class_exists( 'WP_HTML_Tag_Processor' ) ) {
		return $block_content;
	}
	$processor = new WP_HTML_Tag_Processor( $block_content );
	if ( ! $image_url && 'rcmi/story-split' === $name && $processor->next_tag( array( 'class_name' => 'rcmi-story-split' ) ) ) {
		$processor->add_class( 'has-no-media' );
		return $processor->get_updated_html();
	}
	if ( $processor->next_tag( 'IMG' ) ) {
		$processor->set_attribute( 'decoding', 'async' );
		$image_id   = (int) ( $block['attrs']['imageId'] ?? 0 );
		$image_data = $image_id ? wp_get_attachment_image_src( $image_id, 'full' ) : false;
		if ( $image_data ) {
			$processor->set_attribute( 'width', (string) $image_data[1] );
			$processor->set_attribute( 'height', (string) $image_data[2] );
		}
		if ( 'rcmi/story-featured-image' === $name ) {
			$processor->set_attribute( 'loading', 'eager' );
			$processor->set_attribute( 'fetchpriority', 'high' );
		} else {
			$processor->set_attribute( 'loading', 'lazy' );
		}
	}
	return $processor->get_updated_html();
}
add_filter( 'render_block', 'rcmi_toolkit_render_story_media', 10, 2 );

/**
 * Resolve a media library attachment by its filename (basename of the URL).
 *
 * Queries the posts table for an attachment whose guid ends with the given
 * filename. Returns an array with 'id' and 'url' keys, or empty values when
 * the image is not found. This lets the hero preset reference images by a
 * stable filename rather than a database-specific attachment ID.
 *
 * @param string $filename Basename of the image file (e.g. "park.png").
 * @return array { 'id' => int, 'url' => string }
 */
function rcmi_resolve_preset_image( $filename ) {
	$filename = sanitize_file_name( $filename );
	if ( '' === $filename ) {
		return array( 'id' => 0, 'url' => '' );
	}

	// Query attachments by guid LIKE %filename. The guid stores the full URL.
	$cache_key = 'rcmi_preset_img_' . md5( $filename );
	$cached = get_transient( $cache_key );
	if ( false !== $cached && isset( $cached['id'] ) ) {
		// Verify the attachment still exists.
		if ( $cached['id'] && get_post_status( $cached['id'] ) ) {
			$src = wp_get_attachment_image_src( $cached['id'], 'full' );
			if ( $src ) {
				return array( 'id' => intval( $cached['id'] ), 'url' => $src[0] );
			}
		}
	}

	global $wpdb;
	$like = '%' . $wpdb->esc_like( $filename );
	$post_id = $wpdb->get_var(
		$wpdb->prepare(
			"SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' AND guid LIKE %s ORDER BY ID DESC LIMIT 1",
			$like
		)
	);

	if ( $post_id ) {
		$src = wp_get_attachment_image_src( $post_id, 'full' );
		if ( $src ) {
			$result = array( 'id' => intval( $post_id ), 'url' => $src[0] );
			set_transient( $cache_key, $result, HOUR_IN_SECONDS );
			return $result;
		}
	}

	set_transient( $cache_key, array( 'id' => 0, 'url' => '' ), HOUR_IN_SECONDS );
	return array( 'id' => 0, 'url' => '' );
}

/**
 * Return the full RCMI Hero preset configuration matching the Home page hero.
 *
 * Image layers are resolved at call time from the media library by filename.
 * When an image is not found, its ID/URL are left as 0/'' so the editor shows
 * a usable placeholder and the frontend renderer omits the layer gracefully.
 *
 * @return array Preset attributes for rcmi/parallax, plus an 'imagesAvailable' map.
 */
function rcmi_hero_preset() {
	// Resolve each layer's desktop and mobile image by filename.
	$bg    = rcmi_resolve_preset_image( 'fulll-background.png' );
	$mid   = rcmi_resolve_preset_image( 'park.png' );
	$fg    = rcmi_resolve_preset_image( 'peole-at-table.png' );
	$bg_mob  = rcmi_resolve_preset_image( 'mobile-crop-1785948049463.png' );
	$mid_mob = rcmi_resolve_preset_image( 'mobile-crop-1785948040122.png' );
	$fg_mob  = rcmi_resolve_preset_image( 'mobile-crop-1785947989669.png' );

	$attributes = array(
		'mode'        => 'parallax',
		'bgImageId'   => $bg['id'],
		'bgImageUrl'  => $bg['url'],
		'bgSpeed'     => 1.1,
		'midImageId'  => $mid['id'],
		'midImageUrl' => $mid['url'],
		'midSpeed'    => 0.95,
		'fgImageId'   => $fg['id'],
		'fgImageUrl'  => $fg['url'],
		'fgSpeed'     => 0.15,
		'contentSpeed' => 0,
		'bgZIndex'      => 0,
		'midZIndex'     => 1,
		'fgZIndex'      => 4,
		'scrimZIndex'   => 2,
		'contentZIndex' => 3,
		'mobileIntensity' => 1.1,
		'tabletScaleMultiplier' => 1,
		'bgPositionY' => 58,
		'bgScale'     => 190,
		'midPositionY' => 54,
		'midScale'    => 190,
		'fgPositionX' => 63,
		'fgScale'    => 115,
		'bgMobileScale'    => 235,
		'bgMobilePositionY' => 75,
		'bgMobileImageId'  => $bg_mob['id'],
		'bgMobileImageUrl' => $bg_mob['url'],
		'midMobileScale'    => 233,
		'midMobilePositionY' => 63,
		'midMobileImageId'  => $mid_mob['id'],
		'midMobileImageUrl' => $mid_mob['url'],
		'fgMobileScale'    => 115,
		'fgMobilePositionX' => 64,
		'fgMobileImageId'  => $fg_mob['id'],
		'fgMobileImageUrl' => $fg_mob['url'],
		'scrimStops'  => array(
			array( 'color' => '#f8f5ee', 'opacity' => 0.85, 'position' => 0 ),
			array( 'color' => '#f8f5ee', 'opacity' => 0.5,  'position' => 50 ),
			array( 'color' => '#f8f5ee', 'opacity' => 0,    'position' => 65 ),
		),
	);

	$images_available = array(
		'background'      => ! empty( $bg['id'] ),
		'middle'          => ! empty( $mid['id'] ),
		'foreground'      => ! empty( $fg['id'] ),
		'backgroundMobile'  => ! empty( $bg_mob['id'] ),
		'middleMobile'      => ! empty( $mid_mob['id'] ),
		'foregroundMobile'  => ! empty( $fg_mob['id'] ),
	);

	return array(
		'attributes'       => $attributes,
		'imagesAvailable'  => $images_available,
	);
}

/**
 * Enqueue editor assets (block registration JS).
 */
function rcmi_toolkit_editor_assets() {
	$ver = file_exists( RCMI_TOOLKIT_PATH . 'src/blocks.js' ) ? filemtime( RCMI_TOOLKIT_PATH . 'src/blocks.js' ) : RCMI_TOOLKIT_VERSION;

	wp_enqueue_script(
		'rcmi-toolkit-editor',
		RCMI_TOOLKIT_URL . 'src/blocks.js',
		array( 'wp-blocks', 'wp-block-editor', 'wp-element', 'wp-components', 'wp-i18n', 'wp-data', 'wp-hooks', 'wp-server-side-render', 'wp-api-fetch' ),
		$ver,
		true
	);

	// Expose the resolved hero preset to the block editor so newly inserted
	// rcmi/parallax blocks can receive Home-compatible attributes without
	// embedding database-specific attachment IDs in the JS source.
	$preset = rcmi_hero_preset();
	wp_add_inline_script(
		'rcmi-toolkit-editor',
		'window.rcmiToolkitHeroPreset = ' . wp_json_encode( $preset ) . ';',
		'before'
	);
}
add_action( 'enqueue_block_editor_assets', 'rcmi_toolkit_editor_assets' );

/**
 * Register server-side render callbacks for RCMI blocks.
 * This allows render_block() to work for Spectra JSON generation
 * and for front-end rendering of the blocks.
 */
function rcmi_block_defaults( $block_name ) {
	$defaults = array(
		'rcmi/quote-block' => array(
			'quote' => "Chronic disease doesn't yield to single disciplines or single institutions. It yields to relationships — built slowly, across communities, and measured in lives improved.",
			'citeName' => 'RCMI Coordinating Center', 'citeRole' => 'Guiding Principle',
		),
		'rcmi/cta-band' => array(
			'heading' => 'Ready to start?', 'text' => 'Find the support you need to move your research forward.',
			'btn1Text' => 'Request Support', 'btn1Link' => '/#start', 'btn1Style' => 'btn-outline',
			'btn2Text' => 'Explore Research', 'btn2Link' => '/cores/#investigator', 'btn2Style' => 'btn-primary',
		),
		'rcmi/impact-stats-block' => array(
			'statCount' => 4,
			'stat1Value' => '62', 'stat1Label' => 'Active Investigators', 'stat1Desc' => 'Researchers advancing chronic disease science across Houston and beyond.',
			'stat2Value' => '38', 'stat2Label' => 'Community Partnerships', 'stat2Desc' => 'Trusted relationships helping shape relevant, equitable research.',
			'stat3Value' => '19', 'stat3Label' => 'Counties Served', 'stat3Desc' => 'Research capacity and support reaching communities throughout the region.',
			'stat4Value' => '24', 'stat4Label' => 'Active Research Projects', 'stat4Desc' => 'Studies translating strong ideas into meaningful real-world impact.',
			'stat5Value' => '', 'stat5Label' => '', 'stat5Desc' => '',
			'stat6Value' => '', 'stat6Label' => '', 'stat6Desc' => '',
			'ctaText' => 'Learn More', 'ctaLink' => '/dashboard/',
		),
		'rcmi/role-selector-block' => array(
			'eyebrow' => 'Start Collaborating', 'heading' => 'I am…', 'note' => 'Choose the path that fits you best. Every route leads to the resources most relevant to you.',
			'roles' => array(
				array( 'title' => 'An early-stage investigator', 'desc' => 'Find pilot funding, mentoring, and training pathways to launch your research.', 'link' => '/cores/#investigator' ),
				array( 'title' => 'A community organization', 'desc' => 'Join the Community Advisory Board or propose a shared research priority.', 'link' => '/cores/#community' ),
				array( 'title' => 'A student', 'desc' => 'Explore training opportunities and see where your research idea could go.', 'link' => '/journey/' ),
				array( 'title' => 'A faculty member', 'desc' => 'Request biostatistics, data science, or research navigation support.', 'link' => '/cores/#research' ),
				array( 'title' => 'A healthcare organization', 'desc' => 'Explore implementation support and shared chronic-disease priorities.', 'link' => '/partners/' ),
				array( 'title' => 'A funder', 'desc' => 'Review outcomes, publications, and funding leveraged to date.', 'link' => '/publications/' ),
			),
			'role1Title' => 'An early-stage investigator', 'role1Desc' => 'Find pilot funding, mentoring, and training pathways to launch your research.', 'role1Link' => '/cores/#investigator',
			'role2Title' => 'A community organization', 'role2Desc' => 'Join the Community Advisory Board or propose a shared research priority.', 'role2Link' => '/cores/#community',
			'role3Title' => 'A student', 'role3Desc' => 'Explore training opportunities and see where your research idea could go.', 'role3Link' => '/journey/',
			'role4Title' => 'A faculty member', 'role4Desc' => 'Request biostatistics, data science, or research navigation support.', 'role4Link' => '/cores/#research',
			'role5Title' => 'A healthcare organization', 'role5Desc' => 'Explore implementation support and shared chronic-disease priorities.', 'role5Link' => '/partners/',
			'role6Title' => 'A funder', 'role6Desc' => 'Review outcomes, publications, and funding leveraged to date.', 'role6Link' => '/publications/',
				'scrimStops' => array(
					array( 'color' => '#ffffff', 'opacity' => 0.9, 'position' => 0 ),
					array( 'color' => '#ffffff', 'opacity' => 0.54, 'position' => 50 ),
					array( 'color' => '#ffffff', 'opacity' => 0, 'position' => 100 ),
				),
				'scrimType' => 'linear', 'scrimAngle' => 125,
				'bgImageId' => 0, 'bgImageUrl' => '',
		),
		'rcmi/impact-strip-block' => array(
			'tabs' => array(
				array( 'id' => 'develop', 'label' => 'Develop', 'heading' => 'Growing the next generation <strong>of research leaders</strong>', 'note' => 'We invest early and often in the people who will carry chronic disease research forward — through funding, mentorship, and structured training pathways.', 'buttons' => array( array( 'text' => 'View More', 'link' => '#' ) ), 'btnText' => 'View More', 'btnLink' => '#', 'cards' => array(
					array( 'tag' => 'People', 'title' => 'Investigator Development', 'desc' => 'Individualized pathways that move early-stage researchers from idea to independent funding.' ), array( 'tag' => 'Funding', 'title' => 'Pilot Awards', 'desc' => 'Seed funding for promising, high-risk / high-reward chronic disease research.' ), array( 'tag' => 'Guidance', 'title' => 'Mentoring', 'desc' => 'Paired mentorship with senior faculty across biostatistics, design, and dissemination.' ), array( 'tag' => 'Skills', 'title' => 'Training', 'desc' => 'Workshops and cohort programs covering methods, grant writing, and community-engaged research.' ),
				) ),
				array( 'id' => 'build', 'label' => 'Build', 'heading' => 'Research capacity that scales with <strong>ambition</strong>', 'note' => 'Shared infrastructure — statistical, technical, and navigational — so investigators spend less time re-building the basics and more time discovering.', 'buttons' => array( array( 'text' => 'View More', 'link' => '#' ) ), 'btnText' => 'View More', 'btnLink' => '#', 'cards' => array(
					array( 'tag' => 'Capacity', 'title' => 'Research Capacity', 'desc' => 'Institutional infrastructure that supports rigorous, reproducible science at every stage.' ), array( 'tag' => 'Methods', 'title' => 'Biostatistics', 'desc' => 'Consultation on study design, analysis plans, and power calculations.' ), array( 'tag' => 'Data', 'title' => 'Data Science', 'desc' => 'Support for data management, integration, and advanced analytics.' ), array( 'tag' => 'Access', 'title' => 'Research Resources', 'desc' => 'Shared tools, templates, and navigation support across the research lifecycle.' ),
				) ),
				array( 'id' => 'partner', 'label' => 'Partner', 'heading' => 'Community at the center, <strong>not the edge</strong>', 'note' => 'Research is designed with communities, not delivered to them. Our engagement model shares power over priorities and process.', 'buttons' => array( array( 'text' => 'View More', 'link' => '#' ) ), 'btnText' => 'View More', 'btnLink' => '#', 'cards' => array(
					array( 'tag' => 'Engagement', 'title' => 'Community Engagement', 'desc' => 'Ongoing, two-way relationships between researchers and community organizations.' ), array( 'tag' => 'Governance', 'title' => 'Community Advisory Board', 'desc' => 'Community leaders shape priorities, review protocols, and guide dissemination.' ), array( 'tag' => 'Model', 'title' => 'Value-Based Community Engagement', 'desc' => 'A framework that measures and reinforces mutual value across every partnership.' ), array( 'tag' => 'Network', 'title' => 'Community Partnerships', 'desc' => 'A growing network of trusted organizations across Houston\u2019s diverse communities.' ),
				) ),
				array( 'id' => 'accelerate', 'label' => 'Accelerate', 'heading' => 'From question to real-world impact, <strong>faster</strong>', 'note' => 'Core services and translational infrastructure exist to remove friction between a good idea and a funded, executed study.', 'buttons' => array( array( 'text' => 'View More', 'link' => '#' ) ), 'btnText' => 'View More', 'btnLink' => '#', 'cards' => array(
					array( 'tag' => 'Portfolio', 'title' => 'Research Projects', 'desc' => 'An active portfolio spanning prevention, treatment, and implementation science.' ), array( 'tag' => 'Infrastructure', 'title' => 'Core Services', 'desc' => 'Shared cores in biostatistics, community engagement, and administration.' ), array( 'tag' => 'Growth', 'title' => 'Innovation', 'desc' => 'New methods and technologies piloted to strengthen chronic disease research.' ), array( 'tag' => 'Bridge', 'title' => 'Translational Science', 'desc' => 'Moving discoveries from bench and community into practice and policy.' ),
				) ),
				array( 'id' => 'improve', 'label' => 'Improve', 'heading' => 'We measure what matters, <strong>in public</strong>', 'note' => 'Impact is a living, monthly record of progress toward better chronic disease outcomes.', 'buttons' => array( array( 'text' => 'View More', 'link' => '#' ) ), 'btnText' => 'View More', 'btnLink' => '#', 'cards' => array(
					array( 'tag' => 'Voices', 'title' => 'Impact Stories', 'desc' => 'Real accounts of problems studied, lessons learned, and what\u2019s next.' ), array( 'tag' => 'Evidence', 'title' => 'Publications', 'desc' => 'Findings organized by theme, not by committee.' ), array( 'tag' => 'Live', 'title' => 'Outcomes Dashboard', 'desc' => 'Monthly-updated metrics on investigators, funding, and communities served.' ), array( 'tag' => 'Focus', 'title' => 'Chronic Disease Priorities', 'desc' => 'Priorities set together with the communities most affected.' ),
				) ),
			),
		),
	);

	return $defaults[ $block_name ] ?? array();
}

function rcmi_apply_block_defaults( $block_name, $attrs ) {
	$defaults = rcmi_block_defaults( $block_name );
	foreach ( $defaults as $key => $default ) {
		if ( ! array_key_exists( $key, $attrs ) || '' === $attrs[ $key ] || null === $attrs[ $key ] || ( is_array( $attrs[ $key ] ) && empty( $attrs[ $key ] ) ) ) {
			$attrs[ $key ] = $default;
		}
	}
	return $attrs;
}

/**
 * Legacy content fallback for rcmi/parallax (hero) block.
 *
 * Existing hero instances that were saved before the InnerBlocks migration
 * are self-closing (no inner HTML). Their text lives in attributes
 * (eyebrow, headline, lede, buttonText, buttonLink). This helper renders
 * that attribute-based markup so old pages keep working until migrated.
 *
 * @param array $attrs Block attributes.
 * @return string HTML markup for the hero content area.
 */
function rcmi_legacy_hero_content( $attrs ) {
	$headline   = $attrs['headline'] ?? '';
	$eyebrow    = $attrs['eyebrow'] ?? '';
	$lede       = $attrs['lede'] ?? '';
	$button_text = $attrs['buttonText'] ?? '';
	$button_link = $attrs['buttonLink'] ?? '#';

	$html  = '<h1>' . wp_kses_post( $headline ) . '</h1>';
	$html .= '<span class="eyebrow">' . wp_kses_post( $eyebrow ) . '</span>';
	$html .= '<p class="lede">' . wp_kses_post( $lede ) . '</p>';
	$html .= '<div class="hero-actions">';
	$html .= '<a href="' . esc_url( $button_link ) . '" class="btn btn-primary">' . esc_html( $button_text ) . '</a>';
	$html .= '</div>';
	return $html;
}

/**
 * Resolve the inner-block content for a dynamic block with InnerBlocks.
 *
 * If $content contains inner-block markup (post-migration), use it.
 * Otherwise, fall back to the legacy attribute-based content.
 *
 * @param string $content       The inner HTML passed to the render_callback.
 * @param string $legacy_html   Pre-rendered legacy fallback HTML.
 * @return string The content HTML to echo inside the block wrapper.
 */
function rcmi_resolve_inner_content( $content, $legacy_html ) {
	$content = trim( $content );
	if ( ! empty( $content ) ) {
		return $content;
	}
	return $legacy_html;
}

/**
 * Legacy content fallback for rcmi/quote-block.
 *
 * Pre-migration instances store quote text in attributes (quote, citeName,
 * citeRole). This renders that markup so old pages keep working.
 *
 * @param array $attrs Block attributes.
 * @return string HTML for the quote body.
 */
function rcmi_legacy_quote_content( $attrs ) {
	$quote    = $attrs['quote'] ?? '';
	$citeName = $attrs['citeName'] ?? '';
	$citeRole = $attrs['citeRole'] ?? '';

	$html  = '<p>' . wp_kses_post( $quote ) . '</p>';
	$html .= '<cite>' . wp_kses_post( $citeName ) . ' <span>' . wp_kses_post( $citeRole ) . '</span></cite>';
	return $html;
}

/**
 * Legacy content fallback for rcmi/cta-band.
 *
 * Pre-migration instances store heading, text, and button attributes.
 * This renders that markup so old pages keep working.
 *
 * @param array $attrs Block attributes.
 * @return string HTML for the CTA band content.
 */
function rcmi_legacy_cta_content( $attrs ) {
	$heading   = $attrs['heading'] ?? '';
	$text      = $attrs['text'] ?? '';
	$btn1Text  = $attrs['btn1Text'] ?? '';
	$btn1Link  = $attrs['btn1Link'] ?? '';
	$btn1Style = $attrs['btn1Style'] ?? 'btn-outline';
	$btn2Text  = $attrs['btn2Text'] ?? '';
	$btn2Link  = $attrs['btn2Link'] ?? '';
	$btn2Style = $attrs['btn2Style'] ?? 'btn-primary';

	$html  = '<div class="cta-copy">';
	$html .= '<h2>' . wp_kses_post( $heading ) . '</h2>';
	$html .= '<p>' . wp_kses_post( $text ) . '</p>';
	$html .= '</div>';
	$html .= '<div class="cta-actions">';
	$html .= '<a href="' . esc_url( $btn1Link ) . '" class="btn ' . esc_attr( $btn1Style ) . '">' . esc_html( $btn1Text ) . '</a>';
	$html .= '<a href="' . esc_url( $btn2Link ) . '" class="btn ' . esc_attr( $btn2Style ) . '">' . esc_html( $btn2Text ) . '</a>';
	$html .= '</div>';
	return $html;
}

function rcmi_story_image_attributes() {
	return array(
		'imageId'   => array( 'type' => 'number', 'default' => 0 ),
		'imageUrl'  => array( 'type' => 'string', 'default' => '' ),
		'imageAlt'  => array( 'type' => 'string', 'default' => '' ),
		'caption'   => array( 'type' => 'string', 'default' => '' ),
		'credit'    => array( 'type' => 'string', 'default' => '' ),
		'positionX' => array( 'type' => 'number', 'default' => 50 ),
		'positionY' => array( 'type' => 'number', 'default' => 50 ),
	);
}

function rcmi_register_server_side_blocks() {
	$story_supports = array( 'html' => false, 'reusable' => false );
	register_block_type( 'rcmi/story-featured-image', array(
		'attributes' => array_merge( rcmi_story_image_attributes(), array( 'aspect' => array( 'type' => 'string', 'default' => 'cinematic' ) ) ),
		'supports'   => array_merge( $story_supports, array( 'align' => array( 'wide', 'full' ) ) ),
	) );
	register_block_type( 'rcmi/story-text', array(
		'attributes' => array(
			'eyebrow' => array( 'type' => 'string', 'default' => '' ),
			'heading' => array( 'type' => 'string', 'default' => '' ),
			'body'    => array( 'type' => 'string', 'default' => '' ),
			'width'   => array( 'type' => 'string', 'default' => 'standard' ),
			'dropCap' => array( 'type' => 'boolean', 'default' => false ),
		),
		'supports' => $story_supports,
	) );
	register_block_type( 'rcmi/story-image', array(
		'attributes' => array_merge( rcmi_story_image_attributes(), array( 'size' => array( 'type' => 'string', 'default' => 'wide' ) ) ),
		'supports'   => array_merge( $story_supports, array( 'align' => array( 'wide', 'full' ) ) ),
	) );
	register_block_type( 'rcmi/story-split', array(
		'attributes' => array_merge( rcmi_story_image_attributes(), array(
			'eyebrow'   => array( 'type' => 'string', 'default' => '' ),
			'heading'   => array( 'type' => 'string', 'default' => '' ),
			'body'      => array( 'type' => 'string', 'default' => '' ),
			'imageSide' => array( 'type' => 'string', 'default' => 'left' ),
			'tone'      => array( 'type' => 'string', 'default' => 'light' ),
		) ),
		'supports' => array_merge( $story_supports, array( 'align' => array( 'wide', 'full' ) ) ),
	) );
	register_block_type( 'rcmi/story-quote', array(
		'attributes' => array(
			'quote'    => array( 'type' => 'string', 'default' => '' ),
			'citation' => array( 'type' => 'string', 'default' => '' ),
			'context'  => array( 'type' => 'string', 'default' => '' ),
			'tone'     => array( 'type' => 'string', 'default' => 'slate' ),
		),
		'supports' => array_merge( $story_supports, array( 'align' => array( 'wide', 'full' ) ) ),
	) );
	register_block_type( 'rcmi/story-immersive', array(
		'attributes' => array_merge( rcmi_story_image_attributes(), array(
			'eyebrow'         => array( 'type' => 'string', 'default' => '' ),
			'heading'         => array( 'type' => 'string', 'default' => '' ),
			'body'            => array( 'type' => 'string', 'default' => '' ),
			'height'          => array( 'type' => 'number', 'default' => 80 ),
			'contentPosition' => array( 'type' => 'string', 'default' => 'bottom-left' ),
			'scrim'           => array( 'type' => 'number', 'default' => 65 ),
		) ),
		'supports' => array_merge( $story_supports, array( 'align' => array( 'full' ) ) ),
	) );

	// rcmi/quote-block — large pull quote with citation.
	register_block_type( 'rcmi/quote-block', array(
		'attributes' => array(
			'quote'    => array( 'type' => 'string', 'default' => '' ),
			'citeName' => array( 'type' => 'string', 'default' => '' ),
			'citeRole' => array( 'type' => 'string', 'default' => '' ),
		),
		'supports' => array(
			'html' => false,
			'align' => array( 'full', 'wide' ),
			'color' => array(
				'text'       => true,
				'background' => false,
				'gradient'   => false,
				'link'       => false,
			),
			'typography' => array(
				'fontFamily' => true,
				'textAlign'  => true,
			),
		),
		'render_callback' => function ( $attrs, $content = '' ) {
			$attrs = rcmi_apply_block_defaults( 'rcmi/quote-block', $attrs );

			$color_class = '';
			$color_style = '';
			if ( ! empty( $attrs['textColor'] ) ) {
				$color_class = ' has-text-color has-' . sanitize_title( $attrs['textColor'] ) . '-color';
			} elseif ( ! empty( $attrs['style']['color']['text'] ) ) {
				$color_class = ' has-text-color';
				$color_style = 'color: ' . sanitize_hex_color( $attrs['style']['color']['text'] ) . ';';
			}

			$inner_content = rcmi_resolve_inner_content( $content, rcmi_legacy_quote_content( $attrs ) );

			ob_start();
			?>
			<section class="bg-alt<?php echo esc_attr( $color_class ); ?>"<?php echo $color_style ? ' style="' . esc_attr( $color_style ) . '"' : ''; ?>>
				<div class="wrap quote-block">
					<div class="quote-mark">&ldquo;</div>
					<div class="quote-body">
						<?php echo $inner_content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- inner blocks are already escaped by WP, legacy content uses wp_kses_post() ?>
					</div>
					<div class="quote-mark quote-mark-close">&rdquo;</div>
				</div>
			</section>
			<?php
			return ob_get_clean();
		},
	) );

	// rcmi/cta-band — CTA band with heading + 2 buttons.
	register_block_type( 'rcmi/cta-band', array(
		'attributes' => array(
			'heading'   => array( 'type' => 'string', 'default' => '' ),
			'text'      => array( 'type' => 'string', 'default' => '' ),
			'btn1Text'  => array( 'type' => 'string', 'default' => '' ),
			'btn1Link'  => array( 'type' => 'string', 'default' => '' ),
			'btn1Style' => array( 'type' => 'string', 'default' => 'btn-outline' ),
			'btn2Text'  => array( 'type' => 'string', 'default' => '' ),
			'btn2Link'  => array( 'type' => 'string', 'default' => '' ),
			'btn2Style' => array( 'type' => 'string', 'default' => 'btn-primary' ),
		),
		'supports' => array(
			'html' => false,
			'align' => array( 'full', 'wide' ),
			'color' => array(
				'text'       => true,
				'background' => false,
				'gradient'   => false,
				'link'       => false,
			),
			'typography' => array(
				'fontFamily' => true,
				'textAlign'  => true,
			),
		),
		'render_callback' => function ( $attrs, $content = '' ) {
			$attrs = rcmi_apply_block_defaults( 'rcmi/cta-band', $attrs );

			$color_class = '';
			$color_style = '';
			if ( ! empty( $attrs['textColor'] ) ) {
				$color_class = ' has-text-color has-' . sanitize_title( $attrs['textColor'] ) . '-color';
			} elseif ( ! empty( $attrs['style']['color']['text'] ) ) {
				$color_class = ' has-text-color';
				$color_style = 'color: ' . sanitize_hex_color( $attrs['style']['color']['text'] ) . ';';
			}

			$inner_content = rcmi_resolve_inner_content( $content, rcmi_legacy_cta_content( $attrs ) );

			ob_start();
			?>
			<section class="bg-primary<?php echo esc_attr( $color_class ); ?>"<?php echo $color_style ? ' style="' . esc_attr( $color_style ) . '"' : ''; ?>>
				<div class="wrap">
					<div class="cta-band">
						<?php echo $inner_content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- inner blocks are already escaped by WP, legacy content uses wp_kses_post() ?>
					</div>
				</div>
			</section>
			<?php
			return ob_get_clean();
		},
	) );

	// rcmi/impact-stats-block — 4-stat grid + CTA.
	register_block_type( 'rcmi/impact-stats-block', array(
		'supports' => array(
			'html' => false,
			'align' => array( 'full', 'wide' ),
			'color' => array(
				'text'       => true,
				'background' => false,
				'gradient'   => false,
				'link'       => false,
			),
			'typography' => array(
				'fontFamily' => true,
				'textAlign'  => true,
			),
		),
		'attributes' => array(
			'statCount'  => array( 'type' => 'number', 'default' => 4 ),
			'stat1Value' => array( 'type' => 'string', 'default' => '' ), 'stat1Label' => array( 'type' => 'string', 'default' => '' ), 'stat1Desc' => array( 'type' => 'string', 'default' => '' ),
			'stat2Value' => array( 'type' => 'string', 'default' => '' ), 'stat2Label' => array( 'type' => 'string', 'default' => '' ), 'stat2Desc' => array( 'type' => 'string', 'default' => '' ),
			'stat3Value' => array( 'type' => 'string', 'default' => '' ), 'stat3Label' => array( 'type' => 'string', 'default' => '' ), 'stat3Desc' => array( 'type' => 'string', 'default' => '' ),
			'stat4Value' => array( 'type' => 'string', 'default' => '' ), 'stat4Label' => array( 'type' => 'string', 'default' => '' ), 'stat4Desc' => array( 'type' => 'string', 'default' => '' ),
			'stat5Value' => array( 'type' => 'string', 'default' => '' ), 'stat5Label' => array( 'type' => 'string', 'default' => '' ), 'stat5Desc' => array( 'type' => 'string', 'default' => '' ),
			'stat6Value' => array( 'type' => 'string', 'default' => '' ), 'stat6Label' => array( 'type' => 'string', 'default' => '' ), 'stat6Desc' => array( 'type' => 'string', 'default' => '' ),
			'ctaText'    => array( 'type' => 'string', 'default' => '' ),
			'ctaLink'    => array( 'type' => 'string', 'default' => '' ),
		),
		'render_callback' => function ( $attrs ) {
			$attrs = rcmi_apply_block_defaults( 'rcmi/impact-stats-block', $attrs );

			// Text color support (preset slug or custom hex).
			$color_class = '';
			$color_style = '';
			if ( ! empty( $attrs['textColor'] ) ) {
				$color_class = ' has-text-color has-' . sanitize_title( $attrs['textColor'] ) . '-color';
			} elseif ( ! empty( $attrs['style']['color']['text'] ) ) {
				$color_class = ' has-text-color';
				$color_style = 'color: ' . sanitize_hex_color( $attrs['style']['color']['text'] ) . ';';
			}

			$stat_count = intval( $attrs['statCount'] ?? 4 );
			if ( $stat_count < 1 ) { $stat_count = 1; }
			if ( $stat_count > 6 ) { $stat_count = 6; }
			$grid_style = '--stat-cols: ' . $stat_count . ';';
			$stats = '';
			for ( $i = 1; $i <= $stat_count; $i++ ) {
				$stats .= sprintf(
					'<article class="impact-stat"><strong>%s</strong><span>%s</span><p>%s</p></article>',
					wp_kses_post( $attrs[ "stat{$i}Value" ] ?? '' ),
					wp_kses_post( $attrs[ "stat{$i}Label" ] ?? '' ),
					wp_kses_post( $attrs[ "stat{$i}Desc" ] ?? '' )
				);
			}
			ob_start();
			?>
			<div class="wrap impact-stats-wrap<?php echo esc_attr( $color_class ); ?>" aria-label="RCMI impact statistics"<?php echo $color_style ? ' style="' . esc_attr( $color_style ) . '"' : ''; ?>>
				<div class="impact-stats" style="<?php echo esc_attr( $grid_style ); ?>">
					<?php echo $stats; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<div class="impact-stats-cta">
						<a href="<?php echo esc_url( $attrs['ctaLink'] ); ?>" class="btn btn-primary"><?php echo esc_html( $attrs['ctaText'] ); ?> <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg></a>
					</div>
				</div>
			</div>
			<?php
			return ob_get_clean();
		},
	) );

	// rcmi/role-selector-block — "I am..." section with 6 role cards.
	register_block_type( 'rcmi/role-selector-block', array(
		'supports' => array(
			'html' => false,
			'align' => array( 'full', 'wide' ),
			'color' => array(
				'text'       => true,
				'background' => false,
				'gradient'   => false,
				'link'       => false,
			),
			'typography' => array(
				'fontFamily' => true,
				'textAlign'  => true,
			),
		),
		'attributes' => array(
			'eyebrow' => array( 'type' => 'string', 'default' => '' ),
			'heading' => array( 'type' => 'string', 'default' => '' ),
			'note'    => array( 'type' => 'string', 'default' => '' ),
			'roles'   => array( 'type' => 'array', 'default' => array() ),
			'role1Title' => array( 'type' => 'string', 'default' => '' ), 'role1Desc' => array( 'type' => 'string', 'default' => '' ), 'role1Link' => array( 'type' => 'string', 'default' => '' ),
			'role2Title' => array( 'type' => 'string', 'default' => '' ), 'role2Desc' => array( 'type' => 'string', 'default' => '' ), 'role2Link' => array( 'type' => 'string', 'default' => '' ),
			'role3Title' => array( 'type' => 'string', 'default' => '' ), 'role3Desc' => array( 'type' => 'string', 'default' => '' ), 'role3Link' => array( 'type' => 'string', 'default' => '' ),
			'role4Title' => array( 'type' => 'string', 'default' => '' ), 'role4Desc' => array( 'type' => 'string', 'default' => '' ), 'role4Link' => array( 'type' => 'string', 'default' => '' ),
			'role5Title' => array( 'type' => 'string', 'default' => '' ), 'role5Desc' => array( 'type' => 'string', 'default' => '' ), 'role5Link' => array( 'type' => 'string', 'default' => '' ),
			'role6Title' => array( 'type' => 'string', 'default' => '' ), 'role6Desc' => array( 'type' => 'string', 'default' => '' ), 'role6Link' => array( 'type' => 'string', 'default' => '' ),
				'scrimStops' => array( 'type' => 'array', 'default' => array(
					array( 'color' => '#ffffff', 'opacity' => 0.9, 'position' => 0 ),
					array( 'color' => '#ffffff', 'opacity' => 0.54, 'position' => 50 ),
					array( 'color' => '#ffffff', 'opacity' => 0, 'position' => 100 ),
				) ),
				'scrimType' => array( 'type' => 'string', 'default' => 'linear' ),
				'scrimAngle' => array( 'type' => 'number', 'default' => 125 ),
				'bgImageId' => array( 'type' => 'number', 'default' => 0 ),
				'bgImageUrl' => array( 'type' => 'string', 'default' => '' ),
			),
			'render_callback' => function ( $attrs ) {
				$attrs = rcmi_apply_block_defaults( 'rcmi/role-selector-block', $attrs );
				$roles = '';
				$role_list = $attrs['roles'] ?? array();
				// Fallback to legacy role1Title..role6Title attributes if roles array is empty.
				if ( empty( $role_list ) ) {
					for ( $i = 1; $i <= 6; $i++ ) {
						$title = $attrs[ "role{$i}Title" ] ?? '';
						if ( ! $title ) continue;
						$role_list[] = array(
							'title' => $title,
							'desc'  => $attrs[ "role{$i}Desc" ] ?? '',
							'link'  => $attrs[ "role{$i}Link" ] ?? '#',
						);
					}
				}
				foreach ( $role_list as $role ) {
					$roles .= sprintf(
						'<a href="%s" class="role-card"><h4>%s</h4><p>%s</p><span class="role-link">Start here <svg width="14" height="14" viewBox="0 0 16 16" fill="none"><path d="M3 8h10M9 4l4 4-4 4" stroke="currentColor" stroke-width="1.6"/></svg></span></a>',
						esc_url( $role['link'] ?? '#' ),
						wp_kses_post( $role['title'] ?? '' ),
						wp_kses_post( $role['desc'] ?? '' )
					);
				}

				// Build the scrim overlay style from block attributes.
				$scrim_style = 'background: ' . rcmi_toolkit_build_gradient(
					rcmi_toolkit_migrate_scrim_stops( $attrs ),
					$attrs['scrimType'] ?? 'linear',
					intval( $attrs['scrimAngle'] ?? 125 )
				) . ';';

				// Optional inline background image on the section.
				$section_style = '';
				if ( ! empty( $attrs['bgImageUrl'] ) ) {
					$section_style = 'background-image: url(' . esc_url( $attrs['bgImageUrl'] ) . '); background-size: cover; background-position: center;';
				}

				// Text color support (preset slug or custom hex).
				$color_class = '';
				$color_style = '';
				if ( ! empty( $attrs['textColor'] ) ) {
					$color_class = ' has-text-color has-' . sanitize_title( $attrs['textColor'] ) . '-color';
				} elseif ( ! empty( $attrs['style']['color']['text'] ) ) {
					$color_class = ' has-text-color';
					$color_style = 'color: ' . sanitize_hex_color( $attrs['style']['color']['text'] ) . ';';
				}

				ob_start();
				?>
				<section id="start" class="collaborating-section<?php echo esc_attr( $color_class ); ?>"<?php echo ( $section_style || $color_style ) ? ' style="' . esc_attr( trim( $section_style . ' ' . $color_style ) ) . '"' : ''; ?>>
					<div class="rcmi-section-scrim" aria-hidden="true" style="<?php echo esc_attr( $scrim_style ); ?>"></div>
					<div class="wrap">
						<div class="section-head">
							<div>
								<span class="eyebrow"><?php echo wp_kses_post( $attrs['eyebrow'] ?? '' ); ?></span>
								<h2><?php echo wp_kses_post( $attrs['heading'] ?? '' ); ?></h2>
							</div>
							<p class="section-note"><?php echo wp_kses_post( $attrs['note'] ?? '' ); ?></p>
						</div>
						<div class="role-grid"><?php echo $roles; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
					</div>
				</section>
				<?php
				return ob_get_clean();
			},
		) );

	// rcmi/impact-strip-block — interactive tabbed section with 5 tabs.
	register_block_type( 'rcmi/impact-strip-block', array(
		'supports' => array(
			'html' => false,
			'align' => array( 'full', 'wide' ),
			'color' => array(
				'text'       => true,
				'background' => false,
				'gradient'   => false,
				'link'       => false,
			),
			'typography' => array(
				'fontFamily' => true,
				'textAlign'  => true,
			),
		),
		'attributes' => array(
			'tabs' => array( 'type' => 'array', 'default' => array() ),
			'transition' => array( 'type' => 'string', 'default' => 'none' ),
			'height' => array( 'type' => 'number', 'default' => 0 ),
			'tabBtnBgColor' => array( 'type' => 'string', 'default' => '#fbf7f0' ),
			'tabBtnTextColor' => array( 'type' => 'string', 'default' => '#7d2832' ),
			'tabBtnActiveBgColor' => array( 'type' => 'string', 'default' => '#ffffff' ),
			'tabBtnActiveTextColor' => array( 'type' => 'string', 'default' => '#c8102e' ),
			'globalScrim' => array( 'type' => 'boolean', 'default' => false ),
			'globalScrimStops' => array( 'type' => 'array', 'default' => array() ),
			'globalScrimType' => array( 'type' => 'string', 'default' => 'linear' ),
			'globalScrimAngle' => array( 'type' => 'number', 'default' => 90 ),
			'buttonRadius'     => array( 'type' => 'number', 'default' => 999 ),
		),
		'render_callback' => function ( $attrs ) {
			$defaults = rcmi_block_defaults( 'rcmi/impact-strip-block' );
			$tabs = ! empty( $attrs['tabs'] ) ? $attrs['tabs'] : $defaults['tabs'];

			$transition = $attrs['transition'] ?? 'none';
			if ( ! in_array( $transition, array( 'none', 'fade', 'slide', 'curtain', 'wipe', 'reveal' ), true ) ) {
				$transition = 'none';
			}

			// Tab strip.
			$btn_bg = sanitize_hex_color( $attrs['tabBtnBgColor'] ?? '' );
			$btn_text = sanitize_hex_color( $attrs['tabBtnTextColor'] ?? '' );
			$btn_active_bg = sanitize_hex_color( $attrs['tabBtnActiveBgColor'] ?? '' );
			$btn_active_text = sanitize_hex_color( $attrs['tabBtnActiveTextColor'] ?? '' );
			// Pass colors as CSS custom properties on the wrapper so the
			// .is-active class can override inline styles via CSS specificity.
			$wrapper_vars = '';
			if ( $btn_bg ) { $wrapper_vars .= ' --tab-btn-bg:' . $btn_bg . ';'; }
			if ( $btn_text ) { $wrapper_vars .= ' --tab-btn-text:' . $btn_text . ';'; }
			if ( $btn_active_bg ) { $wrapper_vars .= ' --tab-btn-active-bg:' . $btn_active_bg . ';'; }
			if ( $btn_active_text ) { $wrapper_vars .= ' --tab-btn-active-text:' . $btn_active_text . ';'; }
			$wrapper_style = $wrapper_vars ? ' style="' . esc_attr( $wrapper_vars ) . '"' : '';
			$strip = '<section class="impact-overview" id="impact-strip" aria-label="How RCMI works"><div class="wrap"><div class="impact-strip"' . $wrapper_style . '><div class="impact-steps" role="tablist">';
			foreach ( $tabs as $i => $tab ) {
				$active = 0 === $i ? ' is-active' : '';
				$selected = 0 === $i ? 'true' : 'false';
				$strip .= sprintf(
					'<button class="impact-step%s" role="tab" aria-selected="%s" data-tab="%s"><span class="impact-step-copy"><strong>%s</strong></span></button>',
					esc_attr( $active ),
					esc_attr( $selected ),
					esc_attr( $tab['id'] ),
					esc_html( $tab['label'] )
				);
			}
			$strip .= '</div></div></div></section>';

			// Tab panels.
			$panels = '<div class="tab-panels" data-transition="' . esc_attr( $transition ) . '">';
			foreach ( $tabs as $i => $tab ) {
				$active = 0 === $i ? ' is-active' : '';
				$bg_alt = $i % 2 === 1 ? ' bg-alt' : '';
				$cards_html = '';
				foreach ( ( $tab['cards'] ?? array() ) as $card ) {
					$cards_html .= sprintf(
						'<div class="card"><span class="tag">%s</span><h4>%s</h4><p>%s</p></div>',
						wp_kses_post( $card['tag'] ),
						wp_kses_post( $card['title'] ),
						wp_kses_post( $card['desc'] )
					);
				}
				// Build per-tab scrim style from multi-stop gradient.
				// When global scrim is enabled, use the global gradient for all tabs.
				if ( ! empty( $attrs['globalScrim'] ) ) {
					$tab_stops = rcmi_toolkit_migrate_scrim_stops( $attrs, 'globalScrimStops' );
					$tab_scrim_style = 'background: ' . rcmi_toolkit_build_gradient(
						$tab_stops,
						$attrs['globalScrimType'] ?? 'linear',
						intval( $attrs['globalScrimAngle'] ?? 90 )
					) . ';';
				} else {
					$tab_stops = rcmi_toolkit_migrate_scrim_stops( $tab );
					$tab_scrim_style = 'background: ' . rcmi_toolkit_build_gradient(
						$tab_stops,
						$tab['scrimType'] ?? 'linear',
						intval( $tab['scrimAngle'] ?? 90 )
					) . ';';
				}

				// Build panel inline style: background image + height + colors.
				$panel_style = '';
				if ( ! empty( $tab['bgImageUrl'] ) ) {
					$panel_style .= 'background-image: url(' . esc_url( $tab['bgImageUrl'] ) . ');';
				}
				$panel_height = intval( $attrs['height'] ?? 0 );
				if ( $panel_height > 0 ) {
					$panel_style .= ' height:' . $panel_height . 'px;';
				}

				// Build buttons HTML from buttons array (falls back to legacy btnText/btnLink).
				$buttons_html = '';
				$tab_buttons = $tab['buttons'] ?? array();
				if ( ! empty( $tab_buttons ) ) {
					foreach ( $tab_buttons as $btn ) {
						if ( ! empty( $btn['text'] ) ) {
							$buttons_html .= sprintf(
								'<a href="%s" class="btn btn-primary">%s</a>',
								esc_url( $btn['link'] ?? '#' ),
								esc_html( $btn['text'] )
							);
						}
					}
				} elseif ( ! empty( $tab['btnText'] ) ) {
					$buttons_html .= sprintf(
						'<a href="%s" class="btn btn-primary">%s</a>',
						esc_url( $tab['btnLink'] ?? '#' ),
						esc_html( $tab['btnText'] )
					);
				}

				$panels .= sprintf(
					'<section id="%s" class="tab-panel%s%s" role="tabpanel" style="%s"><div class="rcmi-tab-scrim" aria-hidden="true" style="%s"></div><div class="wrap"><div class="section-head"><div><h2>%s</h2></div><p class="section-note">%s</p></div><div class="card-grid">%s</div>%s</div></section>',
					esc_attr( $tab['id'] ),
					esc_attr( $active ),
					esc_attr( $bg_alt ),
					esc_attr( $panel_style ),
					esc_attr( $tab_scrim_style ),
					wp_kses_post( $tab['heading'] ),
					wp_kses_post( $tab['note'] ),
					$cards_html,
					$buttons_html ? '<div style="margin-top:var(--space-5);display:flex;gap:var(--space-2);flex-wrap:wrap;">' . $buttons_html . '</div>' : ''
				);
			}
			$panels .= '</div>';

			// Text color support (preset slug or custom hex).
			$color_class = '';
			$color_style = '';
			if ( ! empty( $attrs['textColor'] ) ) {
				$color_class = ' has-text-color has-' . sanitize_title( $attrs['textColor'] ) . '-color';
			} elseif ( ! empty( $attrs['style']['color']['text'] ) ) {
				$color_class = ' has-text-color';
				$color_style = 'color: ' . sanitize_hex_color( $attrs['style']['color']['text'] ) . ';';
			}

			return '<div class="rcmi-impact-strip-wrapper' . esc_attr( $color_class ) . '" data-transition="' . esc_attr( $transition ) . '"' . ( $color_style ? ' style="' . esc_attr( $color_style ) . '"' : '' ) . '>' . $strip . $panels . '</div>';
		},
	) );

	// rcmi/slide — child block for a single slide inside rcmi/slide-block.
	// Has its own background image, gradient scrim, content alignment,
	// and free-form InnerBlocks content. Server-side rendered (dynamic).
	register_block_type( 'rcmi/slide', array(
		'supports' => array(
			'html' => false,
			'color' => array(
				'text'       => true,
				'background' => false,
				'gradient'   => false,
				'link'       => false,
			),
			'typography' => array(
				'fontFamily' => true,
				'textAlign'  => true,
			),
		),
		'attributes' => array(
			'bgImageId'         => array( 'type' => 'number', 'default' => 0 ),
			'bgImageUrl'        => array( 'type' => 'string', 'default' => '' ),
			'bgPositionX'       => array( 'type' => 'number', 'default' => 50 ),
			'bgPositionY'       => array( 'type' => 'number', 'default' => 50 ),
			'bgScale'           => array( 'type' => 'number', 'default' => 120 ),
			'bgMobileImageId'   => array( 'type' => 'number', 'default' => 0 ),
			'bgMobileImageUrl'  => array( 'type' => 'string', 'default' => '' ),
			'bgMobileScale'     => array( 'type' => 'number', 'default' => 110 ),
			'bgMobilePositionX' => array( 'type' => 'number', 'default' => 50 ),
			'bgMobilePositionY' => array( 'type' => 'number', 'default' => 50 ),
			'scrimStops'        => array( 'type' => 'array', 'default' => array() ),
			'scrimType'         => array( 'type' => 'string', 'default' => 'linear' ),
			'scrimAngle'        => array( 'type' => 'number', 'default' => 90 ),
			'contentAlign'      => array( 'type' => 'string', 'default' => 'left' ),
		),
		'render_callback' => function ( $attrs, $content ) {
			$attrs = is_array( $attrs ) ? $attrs : array();
			$content = trim( $content ?? '' );

			// Text color support.
			$color_class = '';
			$color_style = '';
			if ( ! empty( $attrs['textColor'] ) ) {
				$color_class = ' has-text-color has-' . sanitize_title( $attrs['textColor'] ) . '-color';
			} elseif ( ! empty( $attrs['style']['color']['text'] ) ) {
				$color_class = ' has-text-color';
				$color_style = 'color: ' . sanitize_hex_color( $attrs['style']['color']['text'] ) . ';';
			}

			// Background image style.
			$bg_url = $attrs['bgImageUrl'] ?? '';
			$bg_style = 'height:80vh;'; // Default height; parent slide-block overrides via regex.
			if ( $bg_url ) {
				$bg_scale = intval( $attrs['bgScale'] ?? 120 );
				$bg_pos_x = intval( $attrs['bgPositionX'] ?? 50 );
				$bg_pos_y = intval( $attrs['bgPositionY'] ?? 50 );
				$bg_style .= sprintf(
					'background-image:url(%s);background-size:%d%%;background-position:%d%% %d%%;background-repeat:no-repeat;',
					esc_url( $bg_url ),
					$bg_scale,
					$bg_pos_x,
					$bg_pos_y
				);
			}

			// Scrim gradient.
			$scrim_style = 'background: ' . rcmi_toolkit_build_gradient(
				$attrs['scrimStops'] ?? array(),
				$attrs['scrimType'] ?? 'linear',
				intval( $attrs['scrimAngle'] ?? 90 )
			) . ';';

			// Content alignment.
			$align = $attrs['contentAlign'] ?? 'left';
			$align_class = 'rcmi-align-' . ( in_array( $align, array( 'left', 'center', 'right' ), true ) ? $align : 'left' );

			$copy_style = '';
			if ( $align === 'center' ) {
				$copy_style = 'max-width:760px;margin:0 auto;';
			} elseif ( $align === 'right' ) {
				$copy_style = 'max-width:570px;margin-left:auto;margin-right:0;';
			}

			// Mobile background image data attributes.
			$mobile_bg_attr = '';
			$bg_mobile_url = $attrs['bgMobileImageUrl'] ?? '';
			if ( $bg_mobile_url ) {
				$mobile_bg_attr = ' data-mobile-bg="' . esc_url( $bg_mobile_url ) . '"';
				$mobile_bg_attr .= ' data-mobile-scale="' . intval( $attrs['bgMobileScale'] ?? 110 ) . '"';
				$mobile_bg_attr .= ' data-mobile-pos-x="' . intval( $attrs['bgMobilePositionX'] ?? 50 ) . '"';
				$mobile_bg_attr .= ' data-mobile-pos-y="' . intval( $attrs['bgMobilePositionY'] ?? 50 ) . '"';
			}

			return sprintf(
				'<section class="rcmi-slide%s%s" style="%s"%s><div class="rcmi-slide-scrim" aria-hidden="true" style="%s"></div><div class="wrap rcmi-slide-inner"><div class="rcmi-slide-copy" style="%s">%s</div></div></section>',
				esc_attr( ' ' . $align_class ),
				esc_attr( $color_class ),
				esc_attr( $bg_style ),
				$mobile_bg_attr,
				esc_attr( $scrim_style ),
				esc_attr( $copy_style ),
				$content // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- inner blocks are already escaped by WP
			);
		},
	) );

	// rcmi/slide-block — parent slider container. Uses InnerBlocks to
	// contain rcmi/slide children. Server-side rendered (dynamic).
	// The rendered child blocks arrive as $content; the parent wraps
	// them in the slider track + navigation.
	register_block_type( 'rcmi/slide-block', array(
		'supports' => array(
			'html' => false,
			'align' => array( 'full', 'wide' ),
			'color' => array(
				'text'       => true,
				'background' => false,
				'gradient'   => false,
				'link'       => false,
			),
			'typography' => array(
				'fontFamily' => true,
				'textAlign'  => true,
			),
		),
		'attributes' => array(
			'autoplay'         => array( 'type' => 'boolean', 'default' => false ),
			'autoplayInterval' => array( 'type' => 'number', 'default' => 5 ),
			'pauseOnHover'     => array( 'type' => 'boolean', 'default' => true ),
			'randomStart'      => array( 'type' => 'boolean', 'default' => false ),
			'loop'             => array( 'type' => 'boolean', 'default' => true ),
			'transition'       => array( 'type' => 'string', 'default' => 'fade' ),
			'showArrows'       => array( 'type' => 'boolean', 'default' => true ),
			'showDots'         => array( 'type' => 'boolean', 'default' => true ),
			'navPosition'      => array( 'type' => 'string', 'default' => 'bottom' ),
			'height'           => array( 'type' => 'number', 'default' => 80 ),
			'globalScrim'      => array( 'type' => 'boolean', 'default' => false ),
			'globalScrimStops' => array( 'type' => 'array', 'default' => array() ),
			'globalScrimType'  => array( 'type' => 'string', 'default' => 'linear' ),
			'globalScrimAngle' => array( 'type' => 'number', 'default' => 90 ),
			'buttonRadius'     => array( 'type' => 'number', 'default' => 999 ),
		),
		'render_callback' => function ( $attrs, $content ) {
			$attrs = is_array( $attrs ) ? $attrs : array();
			$content = trim( $content ?? '' );

			if ( empty( $content ) ) {
				return '';
			}

			$autoplay          = ! empty( $attrs['autoplay'] );
			$autoplay_interval = max( 3, intval( $attrs['autoplayInterval'] ?? 5 ) );
			$pause_on_hover    = ! empty( $attrs['pauseOnHover'] );
			$random_start      = ! empty( $attrs['randomStart'] );
			$loop              = ! empty( $attrs['loop'] );
			$transition        = $attrs['transition'] ?? 'fade';
			$show_arrows       = ! empty( $attrs['showArrows'] );
			$show_dots         = ! empty( $attrs['showDots'] );
			$nav_position      = $attrs['navPosition'] ?? 'bottom';
			$global_height     = intval( $attrs['height'] ?? 80 );
			$global_scrim      = ! empty( $attrs['globalScrim'] );

			// Text color support (preset slug or custom hex).
			$color_class = '';
			$color_style = '';
			if ( ! empty( $attrs['textColor'] ) ) {
				$color_class = ' has-text-color has-' . sanitize_title( $attrs['textColor'] ) . '-color';
			} elseif ( ! empty( $attrs['style']['color']['text'] ) ) {
				$color_class = ' has-text-color';
				$color_style = 'color: ' . sanitize_hex_color( $attrs['style']['color']['text'] ) . ';';
			}

			// Apply global height to all slide sections.
			// Replace height in style attributes of <section class="rcmi-slide..."> elements.
			$content = preg_replace(
				'/(<section class="rcmi-slide[^"]*" style="[^"]*?)(height:[^;]+;)/',
				'$1height:' . $global_height . 'vh;',
				$content
			);

			// Apply global scrim if enabled — replace all per-slide scrims.
			if ( $global_scrim ) {
				$global_scrim_css = 'background: ' . rcmi_toolkit_build_gradient(
					$attrs['globalScrimStops'] ?? array(),
					$attrs['globalScrimType'] ?? 'linear',
					intval( $attrs['globalScrimAngle'] ?? 90 )
				) . ';';
				$content = preg_replace(
					'/(<div class="rcmi-slide-scrim"[^>]*style=")background:[^"]*?(")/',
					'$1' . esc_attr( $global_scrim_css ) . '$2',
					$content
				);
			}

			// Apply button radius to all buttons.
			$btn_radius = intval( $attrs['buttonRadius'] ?? 999 );
			if ( $btn_radius !== 999 ) {
				$content = preg_replace(
					'/(border-radius:)\d+px/',
					'$1' . $btn_radius . 'px',
					$content
				);
			}

			// Mark the first slide as active.
			$content = preg_replace(
				'/(<section class="rcmi-slide)/',
				'$1 is-active',
				$content,
				1
			);

			// Count slides for dots and data attribute.
			$slide_count = preg_match_all( '/<section class="rcmi-slide/', $content );

			// Build dots.
			$dots_html = '';
			if ( $show_dots ) {
				for ( $i = 0; $i < $slide_count; $i++ ) {
					$dots_html .= sprintf(
						'<button class="rcmi-slide-dot%s" type="button" data-slide="%d" aria-label="%s"></button>',
						0 === $i ? ' is-active' : '',
						$i,
						esc_attr( sprintf( __( 'Go to slide %d', 'rcmi-toolkit' ), $i + 1 ) )
					);
				}
				$dots_html = '<div class="rcmi-slide-dots rcmi-slide-dots-' . esc_attr( $nav_position ) . '">' . $dots_html . '</div>';
			}

			// Build arrows.
			$arrows_html = '';
			if ( $show_arrows ) {
				$arrows_html = '<button class="rcmi-slide-arrow rcmi-slide-arrow-prev" type="button" aria-label="' . esc_attr__( 'Previous slide', 'rcmi-toolkit' ) . '">&#8249;</button><button class="rcmi-slide-arrow rcmi-slide-arrow-next" type="button" aria-label="' . esc_attr__( 'Next slide', 'rcmi-toolkit' ) . '">&#8250;</button>';
			}

			// Assemble nav.
			$top_nav    = ( 'top' === $nav_position ) ? $dots_html : '';
			$bottom_nav = ( 'bottom' === $nav_position ) ? $dots_html : '';

			// Data attributes for the frontend JS.
			$wrapper_attrs = sprintf(
				'data-autoplay="%s" data-interval="%d" data-pause-on-hover="%s" data-random-start="%s" data-loop="%s" data-transition="%s" data-slide-count="%d"',
				$autoplay ? '1' : '0',
				$autoplay_interval,
				$pause_on_hover ? '1' : '0',
				$random_start ? '1' : '0',
				$loop ? '1' : '0',
				esc_attr( $transition ),
				$slide_count
			);

			return sprintf(
				'<div class="rcmi-slide-block-wrapper%s"%s><div class="rcmi-slide-block" %s>%s<div class="rcmi-slide-track">%s</div>%s%s</div></div>',
				esc_attr( $color_class ),
				$color_style ? ' style="' . esc_attr( $color_style ) . '"' : '',
				$wrapper_attrs,
				$top_nav,
				$content, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- rendered child blocks
				$arrows_html,
				$bottom_nav
			);
		},
	) );

	// rcmi/parallax — hero block with static or parallax mode.
	// Replaces the old rcmi/hero block. Mode toggle: 'static' (single bg
	// image) or 'parallax' (3-layer depth effect). Includes editable
	// gradient scrim and content alignment controls.

	// Generate responsive image sizes for parallax layers.
	// Produces 3 widths (desktop, tablet, mobile) × 2 formats (webp, png).
	// Images are RESIZED but NOT cropped — the original aspect ratio is
	// preserved so the full image is always visible with object-fit:contain.
	// Files are cached in wp-content/uploads/rcmi-crops/.
	//
	// Max widths:
	//   desktop: 1920px
	//   tablet:  1100px
	//   mobile:  800px
	if ( ! function_exists( 'rcmi_generate_responsive_sizes' ) ) {
		function rcmi_generate_responsive_sizes( $image_id ) {
			$image_id = intval( $image_id );
			if ( ! $image_id ) {
				return array();
			}

			$src = wp_get_attachment_image_src( $image_id, 'full' );
			if ( ! $src ) {
				return array();
			}
			$orig_url  = $src[0];
			$orig_w    = $src[1];
			$orig_h    = $src[2];

			$upload_dir = wp_upload_dir();
			$orig_path  = str_replace( $upload_dir['baseurl'], $upload_dir['basedir'], $orig_url );
			if ( ! file_exists( $orig_path ) ) {
				return array();
			}

			// Target max widths (height auto-scales to preserve aspect).
			$widths = array(
				'desktop' => 1920,
				'tablet'  => 1100,
				'mobile'  => 800,
			);

			$filename_base = pathinfo( $orig_path, PATHINFO_FILENAME );
			$crop_dir = $upload_dir['basedir'] . '/rcmi-crops/';
			$crop_url_base = $upload_dir['baseurl'] . '/rcmi-crops/';

			if ( ! file_exists( $crop_dir ) ) {
				wp_mkdir_p( $crop_dir );
			}

			$results = array();

			foreach ( $widths as $name => $max_w ) {
				// Skip if original is already smaller than target —
				// no point upscaling.
				if ( $orig_w <= $max_w ) {
					$results[ $name ] = array();
					continue;
				}

				$formats = array( 'webp', 'png' );
				$results[ $name ] = array();

				foreach ( $formats as $fmt ) {
					$out_path = $crop_dir . $filename_base . '-' . $name . '.' . $fmt;
					$out_url  = $crop_url_base . $filename_base . '-' . $name . '.' . $fmt;

					// Check cache.
					if ( file_exists( $out_path ) ) {
						$results[ $name ][ $fmt ] = $out_url;
						continue;
					}

					$editor = wp_get_image_editor( $orig_path );
					if ( is_wp_error( $editor ) ) {
						continue;
					}

					// Resize without cropping — preserve aspect ratio.
					$editor->resize( $max_w, null, false );
					$quality = $fmt === 'webp' ? 85 : 90;
					$editor->set_quality( $quality );

					$saved = $editor->save( $out_path, 'image/' . $fmt );
					if ( is_wp_error( $saved ) ) {
						continue;
					}

					$results[ $name ][ $fmt ] = $out_url;
				}
			}

			return $results;
		}
	}

	register_block_type( 'rcmi/parallax', array(
		'supports' => array(
			'html' => false,
			'align' => array( 'full', 'wide' ),
			'color' => array(
				'text'       => true,
				'background' => false,
				'gradient'   => false,
				'link'       => false,
			),
			'typography' => array(
				'fontFamily' => true,
				'textAlign'  => true,
			),
		),
		'attributes' => array(
			'mode'        => array( 'type' => 'string', 'default' => 'static' ),
			'bgImageId'   => array( 'type' => 'number', 'default' => 0 ),
			'bgImageUrl'  => array( 'type' => 'string', 'default' => '' ),
			'bgSpeed'     => array( 'type' => 'number', 'default' => 0.2 ),
			'midImageId'  => array( 'type' => 'number', 'default' => 0 ),
			'midImageUrl' => array( 'type' => 'string', 'default' => '' ),
			'midSpeed'    => array( 'type' => 'number', 'default' => 0.45 ),
			'fgImageId'   => array( 'type' => 'number', 'default' => 0 ),
			'fgImageUrl'  => array( 'type' => 'string', 'default' => '' ),
			'fgSpeed'     => array( 'type' => 'number', 'default' => 0.7 ),
			'contentSpeed' => array( 'type' => 'number', 'default' => 0.1 ),
			'bgZIndex'      => array( 'type' => 'number', 'default' => 0 ),
			'midZIndex'     => array( 'type' => 'number', 'default' => 1 ),
			'fgZIndex'      => array( 'type' => 'number', 'default' => 2 ),
			'scrimZIndex'   => array( 'type' => 'number', 'default' => 3 ),
			'contentZIndex' => array( 'type' => 'number', 'default' => 4 ),
			'parallaxMode' => array( 'type' => 'string', 'default' => 'scroll' ),
			'height'      => array( 'type' => 'number', 'default' => 80 ),
			'mobileIntensity' => array( 'type' => 'number', 'default' => 0.7 ),
			'tabletScaleMultiplier' => array( 'type' => 'number', 'default' => 0.75 ),
			// Per-layer position (object-position) and scale (visual zoom).
			// Position X/Y: 0-100% controls which part of the image is visible
			// (50/50 = center). Scale: 100-300% controls how much larger the
			// image is than the section — bigger scale = more parallax headroom
			// and deeper zoom. Default 200% matches the original 2× oversize.
			'bgPositionX' => array( 'type' => 'number', 'default' => 50 ),
			'bgPositionY' => array( 'type' => 'number', 'default' => 50 ),
			'bgScale'     => array( 'type' => 'number', 'default' => 200 ),
			'midPositionX'=> array( 'type' => 'number', 'default' => 50 ),
			'midPositionY'=> array( 'type' => 'number', 'default' => 50 ),
			'midScale'    => array( 'type' => 'number', 'default' => 200 ),
			'fgPositionX' => array( 'type' => 'number', 'default' => 50 ),
			'fgPositionY' => array( 'type' => 'number', 'default' => 50 ),
			'fgScale'     => array( 'type' => 'number', 'default' => 200 ),
			// Per-layer mobile scale & position (used on screens <768px).
			// These are independent from desktop values.
			'bgMobileScale'    => array( 'type' => 'number', 'default' => 100 ),
			'bgMobilePositionX'=> array( 'type' => 'number', 'default' => 50 ),
			'bgMobilePositionY'=> array( 'type' => 'number', 'default' => 50 ),
			'midMobileScale'    => array( 'type' => 'number', 'default' => 100 ),
			'midMobilePositionX'=> array( 'type' => 'number', 'default' => 50 ),
			'midMobilePositionY'=> array( 'type' => 'number', 'default' => 50 ),
			'fgMobileScale'    => array( 'type' => 'number', 'default' => 100 ),
			'fgMobilePositionX'=> array( 'type' => 'number', 'default' => 50 ),
			'fgMobilePositionY'=> array( 'type' => 'number', 'default' => 50 ),
			// Per-layer mobile image (optional). If set, used on screens
			// <768px via <picture><source>. User should pre-crop to portrait.
			'bgMobileImageId'  => array( 'type' => 'number', 'default' => 0 ),
			'bgMobileImageUrl' => array( 'type' => 'string', 'default' => '' ),
			'midMobileImageId'  => array( 'type' => 'number', 'default' => 0 ),
			'midMobileImageUrl' => array( 'type' => 'string', 'default' => '' ),
			'fgMobileImageId'  => array( 'type' => 'number', 'default' => 0 ),
			'fgMobileImageUrl' => array( 'type' => 'string', 'default' => '' ),
			'scrimStops'  => array( 'type' => 'array', 'default' => array(
				array( 'color' => '#f8f5ee', 'opacity' => 0.85, 'position' => 0 ),
				array( 'color' => '#f8f5ee', 'opacity' => 0.34, 'position' => 40 ),
				array( 'color' => '#f8f5ee', 'opacity' => 0, 'position' => 65 ),
			) ),
			'scrimType'   => array( 'type' => 'string', 'default' => 'linear' ),
			'scrimAngle'  => array( 'type' => 'number', 'default' => 90 ),
			'contentAlign' => array( 'type' => 'string', 'default' => 'left' ),
			'eyebrow'     => array( 'type' => 'string', 'default' => 'Accelerating Real‑World Impact.' ),
			'headline'    => array( 'type' => 'string', 'default' => 'Advancing Chronic<br> Disease Research.' ),
			'lede'        => array( 'type' => 'string', 'default' => 'Building research capacity, developing investigators, and partnering with communities to improve chronic disease outcomes across Houston and beyond.' ),
			'buttonText'  => array( 'type' => 'string', 'default' => 'Request Support' ),
			'buttonLink'  => array( 'type' => 'string', 'default' => '#start' ),
		),
		'render_callback' => function ( $attrs, $content = '' ) {
			$mode    = $attrs['mode'] ?? 'static';
			$height  = intval( $attrs['height'] ?? 80 );
			if ( $height < 40 ) { $height = 40; }
			if ( $height > 100 ) { $height = 100; }

			// Helper: build an <img srcset> element for a parallax layer.
			// Accepts a structured $layer array with keys:
			//   image_id, fallback_url, pos_x, pos_y, scale, speed,
			//   z_index, layer_name, mobile_image_id, mobile_scale,
			//   mobile_pos_x, mobile_pos_y
			// Uses wp_get_attachment_image_srcset() so the browser natively
			// picks the right image file for the viewport — mobile downloads
			// a smaller file, desktop downloads a larger one. Falls back to
			// a plain <img src> when there's no attachment ID (e.g. the image
			// was deleted from the media library or imported from a URL).
			//
			// $scale is a percentage (25-300) controlling visual zoom:
			//   25  = image is 1/4 the section size (windowed, shows section bg around it)
			//   100 = image exactly fills the section (no parallax headroom)
			//   200 = image is 2× the section size (good parallax room)
			//   300 = deep zoom, lots of parallax travel
			//
			// $pos_x / $pos_y are 0-100 percentages controlling which
			// part of the image stays visible. Panning uses TWO mechanisms
			// that stack:
			//   1. object-position — shifts image content within the img
			//      box. Works in the dimension where object-fit:cover crops
			//      (image aspect ≠ section aspect). This works at ANY scale,
			//      including 100%, so the position slider always does
			//      something. At scale 100% this is the only panning mechanism.
			//   2. transform — shifts the entire img element. Works in both
			//      dimensions, but only when scale > 100% (img must be larger
			//      than the section to have room to shift).
			// object-fit:cover locks the aspect ratio (crops, never stretches).
			$render_layer_img = function ( $layer ) {
				$image_id = intval( $layer['image_id'] ?? 0 );
				$fallback_url = $layer['fallback_url'] ?? '';
				$pos_x    = intval( $layer['pos_x'] ?? 50 );
				$pos_y    = intval( $layer['pos_y'] ?? 50 );
				$scale    = max( 25, min( 300, intval( $layer['scale'] ?? 200 ) ) );
				$speed    = floatval( $layer['speed'] ?? 0 );
				$z_index  = intval( $layer['z_index'] ?? 0 );
				$layer_name = $layer['layer_name'] ?? 'background';
				$mobile_image_id = intval( $layer['mobile_image_id'] ?? 0 );
				$mobile_scale    = max( 25, min( 300, intval( $layer['mobile_scale'] ?? 100 ) ) );
				$mobile_pos_x    = intval( $layer['mobile_pos_x'] ?? 50 );
				$mobile_pos_y    = intval( $layer['mobile_pos_y'] ?? 50 );

				// Position offset as % of the img's own width/height.
				$img_slack = max( 0, $scale - 100 ) / 2;
				$range = max( 100, $img_slack );
				$pos_offset_x = ( $pos_x - 50 ) * $range / $scale;
				// Y axis is inverted: high pos_y = up. CSS object-position
				// uses (100 - pos_y) and the transform offset uses (50 - pos_y)
				// so that increasing the slider moves the image up.
				$pos_offset_y = ( 50 - $pos_y ) * $range / $scale;

				// Desktop/tablet: object-fit:contain (full image visible).
				// Mobile: object-fit:cover (fills screen, may crop).
				// The JS in frontend.js handles the switch at ≤768px.
				// Mobile values are stored as CSS custom properties so a
				// single global media-query rule in rcmi.css can apply them
				// at first paint (before JS runs), preventing the initial
				// mobile "jump" without per-layer <style> tags.
				$mobile_slack = max( 0, $mobile_scale - 100 ) / 2;
				$mobile_range = max( 100, $mobile_slack );
				$mobile_pos_offset_x = ( $mobile_pos_x - 50 ) * $mobile_range / $mobile_scale;
				$mobile_pos_offset_y = ( 50 - $mobile_pos_y ) * $mobile_range / $mobile_scale;
				$mobile_object_fit = $mobile_image_id ? 'contain' : 'cover';

				$style = 'position:absolute;top:50%;left:50%;'
					. 'width:' . $scale . '%;height:' . $scale . '%;'
					. 'max-width:none;max-height:none;'
					. 'object-fit:contain;'
					. 'object-position:' . $pos_x . '% ' . ( 100 - $pos_y ) . '%;'
					. '--pos-x:' . $pos_offset_x . '%;'
					. '--pos-y:' . $pos_offset_y . '%;'
					. '--rcmi-mobile-scale:' . $mobile_scale . '%;'
					. '--rcmi-mobile-pos-x:' . $mobile_pos_offset_x . '%;'
					. '--rcmi-mobile-pos-y:' . $mobile_pos_offset_y . '%;'
					. '--rcmi-mobile-object-fit:' . $mobile_object_fit . ';'
					. '--rcmi-mobile-object-position:' . $mobile_pos_x . '% ' . ( 100 - $mobile_pos_y ) . '%;'
					. 'transform:translate(calc(-50% + var(--pos-x)),calc(-50% + var(--pos-y)));'
					. 'z-index:' . $z_index . ';'
					. 'will-change:transform;pointer-events:none;';

				// Class identifies the layer; no random UID needed since
				// mobile styling is handled by the global CSS rule using
				// the custom properties set above.
				$class = 'rcmi-parallax-layer rcmi-parallax-layer-' . esc_attr( $layer_name );

				if ( $image_id ) {
					// Generate responsive resized copies (no cropping).
					$crops = rcmi_generate_responsive_sizes( $image_id );
					$src    = wp_get_attachment_image_src( $image_id, 'full' );
					$src_url = $src ? $src[0] : $fallback_url;
					if ( empty( $src_url ) ) {
						return '';
					}

					// Build <picture> with <source> tags for each breakpoint.
					// Media queries match the JS breakpoints:
					//   desktop: min-width: 1440px
					//   tablet:  768px – 1439px
					//   mobile:  max-width: 767px
					$sources = '';

					// Desktop source (≥1440px).
					if ( ! empty( $crops['desktop']['webp'] ) ) {
						$sources .= '<source media="(min-width: 1440px)" srcset="' . esc_url( $crops['desktop']['webp'] ) . '" type="image/webp" />';
					}
					if ( ! empty( $crops['desktop']['png'] ) ) {
						$sources .= '<source media="(min-width: 1440px)" srcset="' . esc_url( $crops['desktop']['png'] ) . '" type="image/png" />';
					}

					// Tablet source (768–1439px).
					if ( ! empty( $crops['tablet']['webp'] ) ) {
						$sources .= '<source media="(min-width: 768px)" srcset="' . esc_url( $crops['tablet']['webp'] ) . '" type="image/webp" />';
					}
					if ( ! empty( $crops['tablet']['png'] ) ) {
						$sources .= '<source media="(min-width: 768px)" srcset="' . esc_url( $crops['tablet']['png'] ) . '" type="image/png" />';
					}

					// Mobile source (<768px): if a dedicated mobile image is
					// set, use it (user pre-cropped to portrait). Otherwise
					// fall back to the auto-resized mobile version.
					$mobile_src_url = '';
					if ( $mobile_image_id ) {
						$mobile_src = wp_get_attachment_image_src( $mobile_image_id, 'full' );
						if ( $mobile_src ) {
							$mobile_src_url = $mobile_src[0];
						}
					}
					if ( empty( $mobile_src_url ) && ! empty( $crops['mobile']['webp'] ) ) {
						$mobile_src_url = $crops['mobile']['webp'];
					}
					if ( empty( $mobile_src_url ) && ! empty( $crops['mobile']['png'] ) ) {
						$mobile_src_url = $crops['mobile']['png'];
					}
					if ( empty( $mobile_src_url ) ) {
						$mobile_src_url = $src_url;
					}

					$sources .= '<source media="(max-width: 767px)" srcset="' . esc_url( $mobile_src_url ) . '" />';

					// Fallback <img> (also used by the parallax JS for transforms).
					$img_attrs = array(
						'class'             => $class,
						'style'             => $style,
						'data-speed'        => esc_attr( $speed ),
						'data-has-mobile'   => $mobile_image_id ? '1' : '0',
						'data-mobile-scale' => esc_attr( $mobile_scale ),
						'data-mobile-pos-x' => esc_attr( $mobile_pos_x ),
						'data-mobile-pos-y' => esc_attr( $mobile_pos_y ),
						'src'               => esc_url( $src_url ),
						'alt'               => '',
						'aria-hidden'       => 'true',
						'decoding'          => 'async',
						'loading'           => 'eager',
					);

					$img_tag = '<img ' . array_reduce( array_keys( $img_attrs ), function ( $carry, $key ) use ( $img_attrs ) {
						return $carry . $key . '="' . $img_attrs[ $key ] . '" ';
					}, '' ) . '/>';

					return '<picture>' . $sources . $img_tag . '</picture>';
				} elseif ( $fallback_url ) {
					return '<img class="' . esc_attr( $class ) . '" style="' . esc_attr( $style ) . '" data-speed="' . esc_attr( $speed ) . '" src="' . esc_url( $fallback_url ) . '" alt="" aria-hidden="true" decoding="async" loading="eager" />';
				}
				return '';
			};

			// Resolve inner-block content, falling back to legacy attributes
			// for instances that haven't been migrated yet.
			$inner_content = rcmi_resolve_inner_content( $content, rcmi_legacy_hero_content( $attrs ) );

			// Build the scrim gradient from multi-stop picker attributes.
			$scrim_style = 'background: ' . rcmi_toolkit_build_gradient(
				rcmi_toolkit_migrate_scrim_stops( $attrs ),
				$attrs['scrimType'] ?? 'linear',
				intval( $attrs['scrimAngle'] ?? 90 )
			) . ';';

			// Content alignment class.
			$align = $attrs['contentAlign'] ?? 'left';
			$align_class = 'rcmi-align-' . ( in_array( $align, array( 'left', 'center', 'right' ), true ) ? $align : 'left' );

			// Build text color classes/style from block supports (supports.color.text).
			// WordPress stores preset colors as a slug in textColor, custom colors
			// in style.color.text. We need to manually add the classes since we
			// use a custom render callback (not get_block_wrapper_attributes).
			$color_class = '';
			$color_style = '';
			if ( ! empty( $attrs['textColor'] ) ) {
				$color_class = ' has-text-color has-' . sanitize_title( $attrs['textColor'] ) . '-color';
			} elseif ( ! empty( $attrs['style']['color']['text'] ) ) {
				$color_class = ' has-text-color';
				$color_style = 'color: ' . sanitize_hex_color( $attrs['style']['color']['text'] ) . ';';
			}

			// Alignment is handled by the rcmi-align-* CSS classes on the section.
			$copy_style = '';

			ob_start();

			if ( $mode === 'parallax' ) {
				// Parallax mode: 3 <img> layers with data-speed attributes.
				// Each layer is an <img srcset> element so the browser picks
				// the right image file for the viewport (mobile gets smaller
				// files automatically). Scale controls visual zoom and
				// parallax headroom; position X/Y controls object-position.
				// Speed is signed: positive = foreground drifts down, negative
				// = foreground rises. The fg/bg depth split is handled in JS.
				$parallax_mode = $attrs['parallaxMode'] ?? 'scroll';
				if ( ! in_array( $parallax_mode, array( 'scroll', 'mouse' ), true ) ) {
					$parallax_mode = 'scroll';
				}
				// Scrim z-index from attribute (no longer auto-calculated).
				$content_z = intval( $attrs['contentZIndex'] ?? 4 );
				$scrim_z = intval( $attrs['scrimZIndex'] ?? 3 );
				$mobile_intensity = max( 0, min( 2, floatval( $attrs['mobileIntensity'] ?? 0.7 ) ) );
				$tablet_scale_mult = $attrs['tabletScaleMultiplier'] ?? 0.75;
				?>
				<section class="rcmi-parallax alignfull <?php echo esc_attr( $align_class . $color_class ); ?>" data-mode="<?php echo esc_attr( $parallax_mode ); ?>" data-mobile-intensity="<?php echo esc_attr( $mobile_intensity ); ?>" data-tablet-scale-mult="<?php echo esc_attr( $tablet_scale_mult ); ?>" style="min-height: <?php echo $height; ?>vh;<?php echo esc_attr( $color_style ); ?>">
					<?php
					// Build structured layer arrays for the three parallax layers.
					$parallax_layers = array(
						array(
							'image_id' => $attrs['bgImageId'] ?? 0, 'fallback_url' => $attrs['bgImageUrl'] ?? '',
							'pos_x' => $attrs['bgPositionX'] ?? 50, 'pos_y' => $attrs['bgPositionY'] ?? 50, 'scale' => $attrs['bgScale'] ?? 200,
							'speed' => $attrs['bgSpeed'] ?? 0.2, 'z_index' => $attrs['bgZIndex'] ?? 0, 'layer_name' => 'background',
							'mobile_image_id' => $attrs['bgMobileImageId'] ?? 0,
							'mobile_scale' => $attrs['bgMobileScale'] ?? 100, 'mobile_pos_x' => $attrs['bgMobilePositionX'] ?? 50, 'mobile_pos_y' => $attrs['bgMobilePositionY'] ?? 50,
						),
						array(
							'image_id' => $attrs['midImageId'] ?? 0, 'fallback_url' => $attrs['midImageUrl'] ?? '',
							'pos_x' => $attrs['midPositionX'] ?? 50, 'pos_y' => $attrs['midPositionY'] ?? 50, 'scale' => $attrs['midScale'] ?? 200,
							'speed' => $attrs['midSpeed'] ?? 0.45, 'z_index' => $attrs['midZIndex'] ?? 1, 'layer_name' => 'middle',
							'mobile_image_id' => $attrs['midMobileImageId'] ?? 0,
							'mobile_scale' => $attrs['midMobileScale'] ?? 100, 'mobile_pos_x' => $attrs['midMobilePositionX'] ?? 50, 'mobile_pos_y' => $attrs['midMobilePositionY'] ?? 50,
						),
						array(
							'image_id' => $attrs['fgImageId'] ?? 0, 'fallback_url' => $attrs['fgImageUrl'] ?? '',
							'pos_x' => $attrs['fgPositionX'] ?? 50, 'pos_y' => $attrs['fgPositionY'] ?? 50, 'scale' => $attrs['fgScale'] ?? 200,
							'speed' => $attrs['fgSpeed'] ?? 0.7, 'z_index' => $attrs['fgZIndex'] ?? 2, 'layer_name' => 'foreground',
							'mobile_image_id' => $attrs['fgMobileImageId'] ?? 0,
							'mobile_scale' => $attrs['fgMobileScale'] ?? 100, 'mobile_pos_x' => $attrs['fgMobilePositionX'] ?? 50, 'mobile_pos_y' => $attrs['fgMobilePositionY'] ?? 50,
						),
					);
					foreach ( $parallax_layers as $layer ) {
						echo $render_layer_img( $layer ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML built with esc_* functions
					}
					?>
					<div class="rcmi-parallax-scrim" aria-hidden="true" style="<?php echo esc_attr( $scrim_style . ' z-index: ' . $scrim_z . ';' ); ?>"></div>
					<div class="wrap rcmi-parallax-inner" style="z-index: <?php echo esc_attr( $content_z ); ?>;">
						<div class="rcmi-parallax-copy" data-speed="<?php echo esc_attr( $attrs['contentSpeed'] ?? 0.1 ); ?>" style="<?php echo esc_attr( $copy_style ); ?>">
							<?php echo $inner_content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- inner blocks are already escaped by WP, legacy content uses wp_kses_post() ?>
						</div>
					</div>
				</section>
				<?php
			} else {
				// Static mode: single background image as an <img srcset>.
				// Position X/Y and scale work the same as parallax layers.
				$bg_z      = intval( $attrs['bgZIndex'] ?? 0 );
				$content_z = intval( $attrs['contentZIndex'] ?? 4 );
				$scrim_z   = intval( $attrs['scrimZIndex'] ?? 3 );

				$bg_img_html = $render_layer_img( array(
					'image_id' => $attrs['bgImageId'] ?? 0, 'fallback_url' => $attrs['bgImageUrl'] ?? '',
					'pos_x' => $attrs['bgPositionX'] ?? 50, 'pos_y' => $attrs['bgPositionY'] ?? 50, 'scale' => $attrs['bgScale'] ?? 200,
					'speed' => 0, // No parallax in static mode.
					'z_index' => $bg_z, 'layer_name' => 'background',
					'mobile_image_id' => $attrs['bgMobileImageId'] ?? 0,
					'mobile_scale' => $attrs['bgMobileScale'] ?? 100, 'mobile_pos_x' => $attrs['bgMobilePositionX'] ?? 50, 'mobile_pos_y' => $attrs['bgMobilePositionY'] ?? 50,
				) );
				?>
				<section class="hero -tight <?php echo esc_attr( $align_class . $color_class ); ?>" style="min-height: <?php echo $height; ?>vh;<?php echo esc_attr( $color_style ); ?>">
					<?php if ( $bg_img_html ) : ?>
						<?php echo $bg_img_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<?php else : ?>
						<div class="hero-media" aria-hidden="true" style="background: #f8f5ee; z-index: <?php echo esc_attr( $bg_z ); ?>;"></div>
					<?php endif; ?>
					<div class="rcmi-parallax-scrim" aria-hidden="true" style="<?php echo esc_attr( $scrim_style . ' z-index: ' . $scrim_z . ';' ); ?>"></div>
					<div class="wrap hero-inner" style="z-index: <?php echo esc_attr( $content_z ); ?>;">
						<div class="hero-grid">
							<div class="hero-copy" style="<?php echo esc_attr( $copy_style ); ?>">
								<?php echo $inner_content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- inner blocks are already escaped by WP, legacy content uses wp_kses_post() ?>
							</div>
						</div>
					</div>
				</section>
				<?php
			}

			return ob_get_clean();
		},
	) );
}
add_action( 'init', 'rcmi_register_server_side_blocks' );

/**
 * Enqueue front-end assets (tab switching JS).
 * The theme's nav.js already handles tabs, but we enqueue here too
 * so the plugin is self-contained if used without the theme.
 */
function rcmi_toolkit_frontend_assets() {
	if ( is_admin() ) {
		return;
	}
	$gsap_ver = file_exists( RCMI_TOOLKIT_PATH . 'assets/js/gsap.min.js' ) ? filemtime( RCMI_TOOLKIT_PATH . 'assets/js/gsap.min.js' ) : RCMI_TOOLKIT_VERSION;
	wp_enqueue_script(
		'gsap',
		RCMI_TOOLKIT_URL . 'assets/js/gsap.min.js',
		array(),
		$gsap_ver,
		true
	);

	$ver = file_exists( RCMI_TOOLKIT_PATH . 'src/frontend.js' ) ? filemtime( RCMI_TOOLKIT_PATH . 'src/frontend.js' ) : RCMI_TOOLKIT_VERSION;

	wp_enqueue_script(
		'rcmi-toolkit-frontend',
		RCMI_TOOLKIT_URL . 'src/frontend.js',
		array( 'gsap' ),
		$ver,
		true
	);
}
add_action( 'wp_enqueue_scripts', 'rcmi_toolkit_frontend_assets' );

// ============================================================================
// Spectra upsell suppression
// Hides "Upgrade Now", "Get Access", "PREMIUM" badges, "Free vs Pro" menu
// from Spectra's Design Library and admin dashboard.
// ============================================================================

/**
 * Enqueue upsell suppression CSS + JS in admin (editor + dashboard).
 */
function rcmi_spectra_upsell_suppression_assets() {
	$css_ver = file_exists( RCMI_TOOLKIT_PATH . 'assets/css/spectra-upsell-suppression.css' )
		? filemtime( RCMI_TOOLKIT_PATH . 'assets/css/spectra-upsell-suppression.css' )
		: RCMI_TOOLKIT_VERSION;
	$js_ver = file_exists( RCMI_TOOLKIT_PATH . 'assets/js/spectra-upsell-suppression.js' )
		? filemtime( RCMI_TOOLKIT_PATH . 'assets/js/spectra-upsell-suppression.js' )
		: RCMI_TOOLKIT_VERSION;

	wp_enqueue_style(
		'rcmi-spectra-upsell-suppression',
		RCMI_TOOLKIT_URL . 'assets/css/spectra-upsell-suppression.css',
		array(),
		$css_ver
	);

	wp_enqueue_script(
		'rcmi-spectra-upsell-suppression',
		RCMI_TOOLKIT_URL . 'assets/js/spectra-upsell-suppression.js',
		array(),
		$js_ver,
		true
	);
}
add_action( 'admin_enqueue_scripts', 'rcmi_spectra_upsell_suppression_assets' );
add_action( 'enqueue_block_assets', 'rcmi_spectra_upsell_suppression_assets' );

/**
 * Filter Spectra's "Get Pro" URLs to point nowhere (disables upgrade links).
 */
function rcmi_spectra_disable_pro_url( $url ) {
	return '#';
}
add_filter( 'spectra_blocks_get_pro_url', 'rcmi_spectra_disable_pro_url' );
add_filter( 'ast_block_templates_pro_url', 'rcmi_spectra_disable_pro_url' );

/**
 * Tell Spectra that Pro is active (suppresses "Upgrade" prompts at the source).
 * This sets the isPro flag in the localized JS vars.
 */
function rcmi_spectra_fake_pro_status( $vars ) {
	$vars['isPro'] = true;
	$vars['getProURL'] = '#';
	$vars['license_status'] = true;
	$vars['spectra_blocks_pro_status'] = 'active';
	$vars['spectra_pro_status'] = 'active';
	$vars['astra_sites_pro_status'] = 'active';
	return $vars;
}
add_filter( 'ast_block_templates_localize_vars', 'rcmi_spectra_fake_pro_status' );

/**
 * Remove the "Free vs Pro" admin submenu page entirely.
 */
function rcmi_spectra_remove_free_vs_pro_menu() {
	remove_submenu_page( 'spectra-blocks', 'spectra-blocks&path=free-vs-pro' );
}
add_action( 'admin_menu', 'rcmi_spectra_remove_free_vs_pro_menu', 999 );
add_action( 'admin_menu', 'rcmi_spectra_remove_free_vs_pro_menu', 999 );

// ============================================================================
// Spectra Design Library integration — block import interception
// When the user clicks "Insert" in the Design Library, Spectra makes an AJAX
// call (ast_block_templates_data_option) to its CLOUD API to fetch the full
// block data by ID. Our RCMI block IDs (99001-99007) don't exist on Spectra's
// cloud, so we intercept the AJAX call and serve local data for RCMI IDs.
// ============================================================================

/**
 * Intercept Spectra's block data AJAX request.
 * For RCMI block IDs (99000-99999), serve local data instead of hitting
 * Spectra's cloud API. Runs at priority 5 so it fires before Spectra's handler.
 */
function rcmi_intercept_block_data_request() {
	// Only handle our AJAX action.
	if ( ! isset( $_REQUEST['action'] ) || 'ast_block_templates_data_option' !== $_REQUEST['action'] ) {
		return;
	}

	// Verify nonce.
	check_ajax_referer( 'ast-block-templates-ajax-nonce', '_ajax_nonce' );

	$block_id = isset( $_REQUEST['id'] ) ? absint( $_REQUEST['id'] ) : 0;

	// Only intercept RCMI block IDs (99000-99999 range).
	if ( $block_id < 99000 || $block_id > 99999 ) {
		return; // Let Spectra handle non-RCMI blocks.
	}

	if ( ! current_user_can( 'manage_ast_block_templates' ) ) {
		wp_send_json_error( __( 'You are not allowed to perform this action', 'rcmi-toolkit' ) );
	}

	// Load the block data from the local JSON file.
	$json_dir  = trailingslashit( wp_upload_dir()['basedir'] ) . 'ast-block-templates-json/';
	$page1_file = $json_dir . 'ast-block-templates-blocks-1.json';
	if ( ! file_exists( $page1_file ) ) {
		wp_send_json_error( array( 'message' => 'RCMI block data not found' ) );
	}

	$page1 = json_decode( file_get_contents( $page1_file ), true );
	if ( ! is_array( $page1 ) ) {
		wp_send_json_error( array( 'message' => 'RCMI block data invalid' ) );
	}

	$key = 'id-' . $block_id;
	if ( ! isset( $page1[ $key ] ) ) {
		wp_send_json_error( array( 'message' => 'RCMI block not found: ' . $block_id ) );
	}

	$block_data = $page1[ $key ];

	// Build the response object that Spectra's JS expects.
	// The JS uses `data.original_content` for the import, and `data.ID` for the block ID.
	$response = array(
		'ID'               => $block_id,
		'id'               => $block_id,
		'title'            => $block_data['title'],
		'content'          => $block_data['content'],
		'original_content' => $block_data['content'],
		'type'             => 'block',
		'category'         => $block_data['category'],
		'blocks-category'  => array( $block_data['category'] ),
		'spectra-ver'      => 'v3',
		'astra-sites-type' => 'free',
		'page-builder'     => 'gutenberg',
		'required-plugins' => array(),
		'post-meta'         => array(
			'astra-blocks-required-plugins' => '',
		),
	);

	wp_send_json_success( $response );
}
add_action( 'wp_ajax_ast_block_templates_data_option', 'rcmi_intercept_block_data_request', 5 );

// ============================================================================
// Spectra Design Library integration
// Registers RCMI patterns in Spectra's template library so they appear in
// the Spectra Design Library UI alongside Spectra's built-in templates.
// ============================================================================

/**
 * Ensure the RCMI patterns JSON file exists in Spectra's JSON directory.
 * Regenerates it from the theme's pattern files if missing or outdated.
 * This protects against Spectra cloud sync overwriting our custom additions.
 */
function rcmi_ensure_spectra_patterns() {
	$json_dir = trailingslashit( wp_upload_dir()['basedir'] ) . 'ast-block-templates-json/';
	$page1_file = $json_dir . 'ast-block-templates-blocks-1.json';
	$theme_patterns_dir = get_template_directory() . '/patterns/';
	$theme_assets_url   = get_template_directory_uri() . '/assets/';

	// Ensure the uploads JSON directory exists (Spectra may not have created it yet).
	if ( ! is_dir( $json_dir ) ) {
		wp_mkdir_p( $json_dir );
	}

	// Read the theme's main CSS file and convert relative URLs to absolute.
	// This CSS is injected into Spectra's shadow DOM preview as the "stylesheet" field.
	$rcmi_css = '';
	$rcmi_css_path = get_template_directory() . '/assets/css/rcmi.css';
	if ( file_exists( $rcmi_css_path ) ) {
		$rcmi_css = file_get_contents( $rcmi_css_path );
		// Convert relative asset paths (url(../images/...)) to absolute URLs.
		$rcmi_css = preg_replace_callback(
			'/url\(\s*["\']?(?:\.\.\/)+(images\/[^"\')]+)["\']?\s*\)/',
			function ( $m ) use ( $theme_assets_url ) {
				return 'url("' . $theme_assets_url . $m[1] . '")';
			},
			$rcmi_css
		);
		// Also handle url(images/...) without the ../ prefix.
		$rcmi_css = preg_replace_callback(
			'/url\(\s*["\']?(images\/[^"\')]+)["\']?\s*\)/',
			function ( $m ) use ( $theme_assets_url ) {
				return 'url("' . $theme_assets_url . $m[1] . '")';
			},
			$rcmi_css
		);
	}

	// Build the RCMI blocks from theme pattern files.
	$pattern_files = array(
		'hero'           => 'hero.php',
		'impact-stats'   => 'impact-stats.php',
		'impact-strip'   => 'impact-strip.php',
		'quote-block'    => 'quote-block.php',
		'role-selector'  => 'role-selector.php',
		'cta-band'       => 'cta-band.php',
	);

	$spectra_blocks = array();
	$i = 0;
	foreach ( $pattern_files as $slug => $filename ) {
		$filepath = $theme_patterns_dir . $filename;
		if ( ! file_exists( $filepath ) ) {
			continue;
		}
		$php_content = file_get_contents( $filepath );

		// Extract block content from the pattern.
		// Strategy: First try raw HTML (wp:html) for Spectra JSON generation.
		// If no wp:html blocks, try rendering custom RCMI blocks (wp:rcmi/*).
		$html = '';

		// First: extract raw HTML between <!-- wp:html --> and <!-- /wp:html -->.
		preg_match_all( '/<!-- wp:html -->(.*?)<!-- \/wp:html -->/s', $php_content, $matches );
		$html = implode( "\n", array_map( 'trim', $matches[1] ) );

		// If no wp:html found, try rendering custom RCMI blocks.
		if ( empty( $html ) ) {
			preg_match_all( '/<!-- wp:(rcmi\/[a-z-]+)\s+(\{[^}]*\})\s*\/?-->/', $php_content, $block_matches, PREG_SET_ORDER );
			foreach ( $block_matches as $bm ) {
				$block_name = $bm[1];
				$attrs_json = $bm[2];
				// The pattern file may contain PHP code in the JSON attrs.
				// Evaluate it to get the actual values.
				if ( strpos( $attrs_json, '<?php' ) !== false ) {
					ob_start();
					eval( 'echo ' . var_export( $attrs_json, true ) . ';' );
					$attrs_json = ob_get_clean();
				}
				$attrs = json_decode( $attrs_json, true );
				if ( ! is_array( $attrs ) ) {
					$attrs = array();
				}
				$rendered = render_block( array(
					'blockName'    => $block_name,
					'attrs'        => $attrs,
					'innerContent' => array(),
				) );
				$html .= trim( $rendered ) . "\n";
			}
		}

		// Convert relative image src paths in the HTML to absolute URLs.
		$html = preg_replace_callback(
			'/src=["\'](?:\.\.\/)+(images\/[^"\']+)["\']/',
			function ( $m ) use ( $theme_assets_url ) {
				return 'src="' . $theme_assets_url . $m[1] . '"';
			},
			$html
		);
		$html = preg_replace_callback(
			'/src=["\']images\/([^"\']+)["\']/',
			function ( $m ) use ( $theme_assets_url ) {
				return 'src="' . $theme_assets_url . 'images/' . $m[1] . '"';
			},
			$html
		);

		$block_id = 99001 + $i;
		$spectra_blocks[ 'id-' . $block_id ] = array(
			'title'             => 'RCMI ' . ucwords( str_replace( '-', ' ', $slug ) ),
			'url'               => home_url( '/?rcmi-pattern=' . $slug ),
			'tag'               => array( 'rcmi' ),
			'category'          => 9901,
			'primary-category'  => 'rcmi-sections',
			'type'              => 'block',
			'astra-sites-type'  => 'free',
			'page-builder'      => 'gutenberg',
			'required-plugins'  => array(),
			'stylesheet'        => $rcmi_css,
			'content'           => $html,
			'spectra-ver'       => 'v3',
		);
		$i++;
	}

	if ( empty( $spectra_blocks ) ) {
		return;
	}

	// Merge RCMI blocks into page 1 (the first page Spectra fetches).
	// This ensures they appear in the default fetch range (pages 1-50).
	if ( file_exists( $page1_file ) ) {
		$page1 = json_decode( file_get_contents( $page1_file ), true );
		if ( ! is_array( $page1 ) ) {
			$page1 = array();
		}
	} else {
		$page1 = array();
	}

	// Remove any existing RCMI blocks (IDs 99001-99099) before re-adding.
	$page1 = array_filter(
		$page1,
		function ( $key ) {
			$id = (int) str_replace( 'id-', '', $key );
			return $id < 99000 || $id > 99099;
		},
		ARRAY_FILTER_USE_KEY
	);

	// Merge RCMI blocks into page 1.
	$page1 = array_merge( $page1, $spectra_blocks );
	file_put_contents( $page1_file, wp_json_encode( $page1 ) );

	// Ensure the RCMI category exists in the categories file.
	$cats_file = $json_dir . 'ast-block-templates-categories.json';
	if ( file_exists( $cats_file ) ) {
		$categories = json_decode( file_get_contents( $cats_file ), true );
		if ( ! is_array( $categories ) ) {
			$categories = array();
		}
		// Remove existing RCMI category if present, then re-add with correct count.
		$categories = array_filter(
			$categories,
			function ( $c ) {
				return ! isset( $c['id'] ) || 9901 !== (int) $c['id'];
			}
		);
		$categories[] = array(
			'id'     => 9901,
			'name'   => 'RCMI Sections',
			'slug'   => 'rcmi-sections',
			'parent' => 0,
			'count'  => count( $spectra_blocks ),
		);
		file_put_contents( $cats_file, wp_json_encode( $categories ) );
	}
}
add_action( 'admin_init', 'rcmi_ensure_spectra_patterns' );

/**
 * Re-inject RCMI patterns after Spectra cloud sync overwrites the JSON files.
 */
function rcmi_restore_after_spectra_sync() {
	rcmi_ensure_spectra_patterns();
}
add_action( 'ast_block_templates_after_sync', 'rcmi_restore_after_spectra_sync' );
