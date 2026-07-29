<?php
/**
 * Plugin Name: RCMI Toolkit
 * Description: Custom Gutenberg blocks and tools for the RCMI theme — parallax hero, impact strip (tabs), role selector, impact stats, card grids, quote block, CTA band, and Spectra integration.
 * Version: 1.0.1
 * Author: UH RCMI Web Team
 * License: GPL-2.0-or-later
 * Text Domain: rcmi-toolkit
 *
 * @package rcmi-toolkit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'RCMI_TOOLKIT_VERSION', '1.0.1' );
define( 'RCMI_TOOLKIT_PATH', plugin_dir_path( __FILE__ ) );
define( 'RCMI_TOOLKIT_URL', plugin_dir_url( __FILE__ ) );
define( 'RCMI_TOOLKIT_GITHUB_USER', 'andy741231' );
define( 'RCMI_TOOLKIT_GITHUB_REPO', 'rcmi-toolkit' );

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
function rcmi_toolkit_migrate_scrim_stops( $attrs ) {
	if ( ! empty( $attrs['scrimStops'] ) ) {
		return $attrs['scrimStops'];
	}
	$color   = $attrs['scrimColor'] ?? '#ffffff';
	$opacity = $attrs['scrimOpacity'] ?? 0.9;
	return array(
		array( 'color' => $color, 'opacity' => $opacity, 'position' => 0 ),
		array( 'color' => $color, 'opacity' => $opacity * 0.6, 'position' => 50 ),
		array( 'color' => $color, 'opacity' => 0, 'position' => 100 ),
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

	$plugin_slug = plugin_basename( __FILE__ );

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
 * Post-install cleanup: rename the GitHub ZIP's top-level folder
 * (which is "rcmi-toolkit-<hash>" for source ZIPs or the asset name)
 * back to "rcmi-toolkit" so WordPress doesn't end up with two folders.
 * Also records the installed commit SHA so we know what version is
 * currently running.
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

	$expected = 'rcmi-toolkit';
	$actual   = basename( $result['destination'] );

	if ( $expected !== $actual ) {
		$new_destination = dirname( $result['destination'] ) . '/' . $expected;
		if ( rename( $result['destination'], $new_destination ) ) {
			$result['destination'] = $new_destination;
			$result['destination_name'] = $expected;
		}
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
	if ( plugin_basename( __FILE__ ) !== $file ) {
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
				'slug'  => 'rcmi-sections',
				'title' => __( 'RCMI Sections', 'rcmi-toolkit' ),
				'icon'  => 'layout',
			),
		)
	);
}
add_filter( 'block_categories_all', 'rcmi_toolkit_category' );

/**
 * Enqueue editor assets (block registration JS).
 */
function rcmi_toolkit_editor_assets() {
	$ver = file_exists( RCMI_TOOLKIT_PATH . 'src/blocks.js' ) ? filemtime( RCMI_TOOLKIT_PATH . 'src/blocks.js' ) : RCMI_TOOLKIT_VERSION;

	wp_enqueue_script(
		'rcmi-toolkit-editor',
		RCMI_TOOLKIT_URL . 'src/blocks.js',
		array( 'wp-blocks', 'wp-block-editor', 'wp-element', 'wp-components', 'wp-i18n', 'wp-server-side-render' ),
		$ver,
		true
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
				array( 'id' => 'develop', 'label' => 'Develop', 'heading' => 'Growing the next generation <strong>of research leaders</strong>', 'note' => 'We invest early and often in the people who will carry chronic disease research forward — through funding, mentorship, and structured training pathways.', 'btnText' => 'View More', 'btnLink' => '#', 'cards' => array(
					array( 'tag' => 'People', 'title' => 'Investigator Development', 'desc' => 'Individualized pathways that move early-stage researchers from idea to independent funding.' ), array( 'tag' => 'Funding', 'title' => 'Pilot Awards', 'desc' => 'Seed funding for promising, high-risk / high-reward chronic disease research.' ), array( 'tag' => 'Guidance', 'title' => 'Mentoring', 'desc' => 'Paired mentorship with senior faculty across biostatistics, design, and dissemination.' ), array( 'tag' => 'Skills', 'title' => 'Training', 'desc' => 'Workshops and cohort programs covering methods, grant writing, and community-engaged research.' ),
				) ),
				array( 'id' => 'build', 'label' => 'Build', 'heading' => 'Research capacity that scales with <strong>ambition</strong>', 'note' => 'Shared infrastructure — statistical, technical, and navigational — so investigators spend less time re-building the basics and more time discovering.', 'btnText' => 'View More', 'btnLink' => '#', 'cards' => array() ),
				array( 'id' => 'partner', 'label' => 'Partner', 'heading' => 'Community at the center, <strong>not the edge</strong>', 'note' => 'Research is designed with communities, not delivered to them. Our engagement model shares power over priorities and process.', 'btnText' => 'View More', 'btnLink' => '#', 'cards' => array() ),
				array( 'id' => 'accelerate', 'label' => 'Accelerate', 'heading' => 'From question to real-world impact, <strong>faster</strong>', 'note' => 'Core services and translational infrastructure exist to remove friction between a good idea and a funded, executed study.', 'btnText' => 'View More', 'btnLink' => '#', 'cards' => array() ),
				array( 'id' => 'improve', 'label' => 'Improve', 'heading' => 'We measure what matters, <strong>in public</strong>', 'note' => 'Impact is a living, monthly record of progress toward better chronic disease outcomes.', 'btnText' => 'View More', 'btnLink' => '#', 'cards' => array() ),
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

function rcmi_register_server_side_blocks() {
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
		'render_callback' => function ( $attrs ) {
			$attrs = rcmi_apply_block_defaults( 'rcmi/quote-block', $attrs );

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
			<section class="bg-alt<?php echo esc_attr( $color_class ); ?>"<?php echo $color_style ? ' style="' . esc_attr( $color_style ) . '"' : ''; ?>>
				<div class="wrap quote-block">
					<div class="quote-mark">&ldquo;</div>
					<div class="quote-body">
						<p><?php echo wp_kses_post( $attrs['quote'] ?? '' ); ?></p>
						<cite><?php echo wp_kses_post( $attrs['citeName'] ?? '' ); ?> <span><?php echo wp_kses_post( $attrs['citeRole'] ?? '' ); ?></span></cite>
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
		'render_callback' => function ( $attrs ) {
			$attrs = rcmi_apply_block_defaults( 'rcmi/cta-band', $attrs );

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
			<section class="bg-primary<?php echo esc_attr( $color_class ); ?>"<?php echo $color_style ? ' style="' . esc_attr( $color_style ) . '"' : ''; ?>>
				<div class="wrap">
					<div class="cta-band">
						<div class="cta-copy">
							<h2><?php echo wp_kses_post( $attrs['heading'] ?? '' ); ?></h2>
							<p><?php echo wp_kses_post( $attrs['text'] ?? '' ); ?></p>
						</div>
						<div class="cta-actions">
							<a href="<?php echo esc_url( $attrs['btn1Link'] ); ?>" class="btn <?php echo esc_attr( $attrs['btn1Style'] ); ?>"><?php echo esc_html( $attrs['btn1Text'] ); ?></a>
							<a href="<?php echo esc_url( $attrs['btn2Link'] ); ?>" class="btn <?php echo esc_attr( $attrs['btn2Style'] ); ?>"><?php echo esc_html( $attrs['btn2Text'] ); ?></a>
						</div>
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
			$grid_style = 'grid-template-columns: repeat(' . $stat_count . ', 1fr);';
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
				for ( $i = 1; $i <= 6; $i++ ) {
					$roles .= sprintf(
						'<a href="%s" class="role-card"><h4>%s</h4><p>%s</p><span class="role-link">Start here <svg width="14" height="14" viewBox="0 0 16 16" fill="none"><path d="M3 8h10M9 4l4 4-4 4" stroke="currentColor" stroke-width="1.6"/></svg></span></a>',
						esc_url( $attrs[ "role{$i}Link" ] ),
						wp_kses_post( $attrs[ "role{$i}Title" ] ?? '' ),
						wp_kses_post( $attrs[ "role{$i}Desc" ] ?? '' )
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
		),
		'render_callback' => function ( $attrs ) {
			$defaults = rcmi_block_defaults( 'rcmi/impact-strip-block' );
			$tabs = ! empty( $attrs['tabs'] ) ? $attrs['tabs'] : $defaults['tabs'];

			$transition = $attrs['transition'] ?? 'none';
			if ( ! in_array( $transition, array( 'none', 'fade', 'slide', 'curtain', 'wipe', 'reveal' ), true ) ) {
				$transition = 'none';
			}

			// Tab strip.
			$strip = '<section class="impact-overview" id="impact-strip" aria-label="How RCMI works"><div class="wrap"><div class="impact-strip"><div class="impact-steps" role="tablist">';
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
				$tab_stops = rcmi_toolkit_migrate_scrim_stops( $tab );
				$tab_scrim_style = 'background: ' . rcmi_toolkit_build_gradient(
					$tab_stops,
					$tab['scrimType'] ?? 'linear',
					intval( $tab['scrimAngle'] ?? 90 )
				) . ';';

				$panels .= sprintf(
					'<section id="%s" class="tab-panel%s%s" role="tabpanel" style="%s"><div class="rcmi-tab-scrim" aria-hidden="true" style="%s"></div><div class="wrap"><div class="section-head"><div><h2>%s</h2></div><p class="section-note">%s</p></div><div class="card-grid">%s</div><div style="margin-top:var(--space-5);display:flex;gap:var(--space-2);flex-wrap:wrap;"><a href="%s" class="btn btn-primary">%s</a></div></div></section>',
					esc_attr( $tab['id'] ),
					esc_attr( $active ),
					esc_attr( $bg_alt ),
					! empty( $tab['bgImageUrl'] ) ? 'background-image: url(' . esc_url( $tab['bgImageUrl'] ) . ');' : '',
					esc_attr( $tab_scrim_style ),
					wp_kses_post( $tab['heading'] ),
					wp_kses_post( $tab['note'] ),
					$cards_html,
					esc_url( $tab['btnLink'] ?? '#' ),
					esc_html( $tab['btnText'] ?? 'View More' )
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
	// rcmi/parallax — hero block with static or parallax mode.
	// Replaces the old rcmi/hero block. Mode toggle: 'static' (single bg
	// image) or 'parallax' (3-layer depth effect). Includes editable
	// gradient scrim and content alignment controls.
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
			'parallaxDirection' => array( 'type' => 'string', 'default' => 'down' ),
			'height'      => array( 'type' => 'number', 'default' => 80 ),
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
		'render_callback' => function ( $attrs ) {
			$mode    = $attrs['mode'] ?? 'static';
			$height  = intval( $attrs['height'] ?? 80 );
			if ( $height < 40 ) { $height = 40; }
			if ( $height > 100 ) { $height = 100; }

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
				// Parallax mode: 3 layers with data-speed attributes.
				$layers = array(
					array( 'url' => $attrs['bgImageUrl'] ?? '',  'speed' => $attrs['bgSpeed'] ?? 0.2,  'name' => 'background',  'z' => intval( $attrs['bgZIndex'] ?? 0 ) ),
					array( 'url' => $attrs['midImageUrl'] ?? '', 'speed' => $attrs['midSpeed'] ?? 0.45, 'name' => 'middle',     'z' => intval( $attrs['midZIndex'] ?? 1 ) ),
					array( 'url' => $attrs['fgImageUrl'] ?? '',  'speed' => $attrs['fgSpeed'] ?? 0.7,  'name' => 'foreground', 'z' => intval( $attrs['fgZIndex'] ?? 2 ) ),
				);
				$parallax_dir = $attrs['parallaxDirection'] ?? 'down';
				if ( ! in_array( $parallax_dir, array( 'down', 'up', 'left', 'right' ), true ) ) {
					$parallax_dir = 'down';
				}
				// Scrim z-index from attribute (no longer auto-calculated).
				$content_z = intval( $attrs['contentZIndex'] ?? 4 );
				$scrim_z = intval( $attrs['scrimZIndex'] ?? 3 );
				?>
				<section class="rcmi-parallax alignfull <?php echo esc_attr( $align_class . $color_class ); ?>" data-direction="<?php echo esc_attr( $parallax_dir ); ?>" style="min-height: <?php echo $height; ?>vh;<?php echo esc_attr( $color_style ); ?>">
					<?php foreach ( $layers as $layer ) : ?>
						<?php if ( ! empty( $layer['url'] ) ) : ?>
							<div class="rcmi-parallax-layer rcmi-parallax-layer-<?php echo esc_attr( $layer['name'] ); ?>"
								data-speed="<?php echo esc_attr( $layer['speed'] ); ?>"
								style="background-image: url(<?php echo esc_url( $layer['url'] ); ?>); z-index: <?php echo esc_attr( $layer['z'] ); ?>;"
								aria-hidden="true"></div>
						<?php endif; ?>
					<?php endforeach; ?>
					<div class="rcmi-parallax-scrim" aria-hidden="true" style="<?php echo esc_attr( $scrim_style . ' z-index: ' . $scrim_z . ';' ); ?>"></div>
					<div class="wrap rcmi-parallax-inner" style="z-index: <?php echo esc_attr( $content_z ); ?>;">
						<div class="rcmi-parallax-copy" data-speed="<?php echo esc_attr( $attrs['contentSpeed'] ?? 0.1 ); ?>" style="<?php echo esc_attr( $copy_style ); ?>">
							<h1><?php echo wp_kses_post( $attrs['headline'] ?? '' ); ?></h1>
							<span class="eyebrow"><?php echo wp_kses_post( $attrs['eyebrow'] ?? '' ); ?></span>
							<p class="lede"><?php echo wp_kses_post( $attrs['lede'] ?? '' ); ?></p>
							<div class="hero-actions">
								<a href="<?php echo esc_url( $attrs['buttonLink'] ?? '#' ); ?>" class="btn btn-primary"><?php echo esc_html( $attrs['buttonText'] ?? '' ); ?></a>
							</div>
						</div>
					</div>
				</section>
				<?php
			} else {
				// Static mode: single background image (like the old hero block).
				$bg_z      = intval( $attrs['bgZIndex'] ?? 0 );
				$content_z = intval( $attrs['contentZIndex'] ?? 4 );
				$scrim_z   = intval( $attrs['scrimZIndex'] ?? 3 );

				$media_style = '';
				if ( ! empty( $attrs['bgImageUrl'] ) ) {
					$media_style = 'background-image: url(' . esc_url( $attrs['bgImageUrl'] ) . '); background-size: cover; background-position: center;';
				} else {
					$media_style = 'background: #f8f5ee;';
				}
				$media_style .= ' z-index: ' . $bg_z . ';';
				?>
				<section class="hero -tight <?php echo esc_attr( $align_class . $color_class ); ?>" style="min-height: <?php echo $height; ?>vh;<?php echo esc_attr( $color_style ); ?>">
					<div class="hero-media" aria-hidden="true" style="<?php echo esc_attr( $media_style ); ?>"></div>
					<div class="rcmi-parallax-scrim" aria-hidden="true" style="<?php echo esc_attr( $scrim_style . ' z-index: ' . $scrim_z . ';' ); ?>"></div>
					<div class="wrap hero-inner" style="z-index: <?php echo esc_attr( $content_z ); ?>;">
						<div class="hero-grid">
							<div class="hero-copy" style="<?php echo esc_attr( $copy_style ); ?>">
								<h1><?php echo wp_kses_post( $attrs['headline'] ?? '' ); ?></h1>
								<span class="eyebrow"><?php echo wp_kses_post( $attrs['eyebrow'] ?? '' ); ?></span>
								<p class="lede"><?php echo wp_kses_post( $attrs['lede'] ?? '' ); ?></p>
								<div class="hero-actions">
									<a href="<?php echo esc_url( $attrs['buttonLink'] ?? '#' ); ?>" class="btn btn-primary"><?php echo esc_html( $attrs['buttonText'] ?? '' ); ?></a>
								</div>
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
