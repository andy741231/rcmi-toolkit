<?php
/**
 * RCMI Backup — admin UI (RCMI → Backups).
 *
 * Page: status, on-demand backup buttons, backup list
 * (download/restore/delete), schedule + retention settings, and the
 * import flow (upload → validate → confirm → restore).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'admin_menu', function () {
	add_submenu_page(
		'rcmi-toolkit',
		__( 'Backups', 'rcmi-toolkit' ),
		__( 'Backups', 'rcmi-toolkit' ),
		'manage_options',
		'rcmi-backup',
		'rcmi_backup_admin_page'
	);
} );

// ── actions ─────────────────────────────────────────────────────────

function rcmi_backup_redirect( $args = array() ) {
	wp_safe_redirect( add_query_arg( array_merge( array( 'page' => 'rcmi-backup' ), $args ), admin_url( 'admin.php' ) ) );
	exit;
}

add_action( 'admin_post_rcmi_backup_create', function () {
	if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'rcmi_backup_create' ) ) {
		wp_die( 'Unauthorized' );
	}
	$type   = isset( $_POST['type'] ) && 'full' === $_POST['type'] ? 'full' : 'db';
	$result = rcmi_backup_create( $type );
	rcmi_backup_redirect( is_wp_error( $result )
		? array( 'rcmi_error' => rawurlencode( $result->get_error_message() ) )
		: array( 'rcmi_created' => rawurlencode( basename( $result ) ) )
	);
} );

add_action( 'admin_post_rcmi_backup_download', function () {
	if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'rcmi_backup_download' ) ) {
		wp_die( 'Unauthorized' );
	}
	$path = rcmi_backup_resolve( $_POST['file'] ?? '' );
	if ( ! $path ) {
		rcmi_backup_redirect( array( 'rcmi_error' => 'Backup not found.' ) );
	}
	// Stream the file — backups are never exposed via a public URL.
	// Chunked + flushed so IIS/FastCGI keeps seeing output (activityTimeout)
	// and PHP's default max_execution_time doesn't cap long transfers.
	// Range support lets browsers resume interrupted multi-GB downloads.
	@set_time_limit( 0 );
	while ( ob_get_level() > 0 ) {
		ob_end_clean();
	}
	$size      = filesize( $path );
	$rangeable = ( false !== $size && ( PHP_INT_SIZE >= 8 || $size < 2147483647 ) );
	$start     = 0;
	$end       = $rangeable ? $size - 1 : PHP_INT_MAX;
	$partial   = false;
	if ( $rangeable && ! empty( $_SERVER['HTTP_RANGE'] )
		&& preg_match( '/bytes=(\d*)-(\d*)/', $_SERVER['HTTP_RANGE'], $m ) ) {
		if ( '' === $m[1] ) {
			$start = max( 0, $size - (int) $m[2] );
		} else {
			$start = (int) $m[1];
			if ( '' !== $m[2] ) {
				$end = min( (int) $m[2], $size - 1 );
			}
		}
		if ( $start > $end || $start >= $size ) {
			status_header( 416 );
			header( 'Content-Range: bytes */' . sprintf( '%.0f', $size ) );
			exit;
		}
		$partial = true;
	}
	nocache_headers();
	header( 'Content-Type: application/zip' );
	header( 'Content-Disposition: attachment; filename="' . basename( $path ) . '"' );
	if ( $rangeable ) {
		header( 'Accept-Ranges: bytes' );
		header( 'Content-Length: ' . sprintf( '%.0f', $end - $start + 1 ) );
		if ( $partial ) {
			status_header( 206 );
			header( 'Content-Range: bytes ' . sprintf( '%.0f-%.0f/%.0f', $start, $end, $size ) );
		}
	}
	$in = fopen( $path, 'rb' );
	if ( ! $in ) {
		wp_die( 'Could not read backup file.' );
	}
	if ( $start > 0 ) {
		fseek( $in, $start );
	}
	$out       = fopen( 'php://output', 'wb' );
	$remaining = $end - $start + 1;
	while ( $remaining > 0 && ! feof( $in ) ) {
		$chunk = fread( $in, min( 1048576, $remaining ) );
		if ( false === $chunk ) {
			break;
		}
		$remaining -= fwrite( $out, $chunk );
		fflush( $out );
		flush();
	}
	fclose( $in );
	fclose( $out );
	exit;
} );

add_action( 'admin_post_rcmi_backup_delete', function () {
	if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'rcmi_backup_delete' ) ) {
		wp_die( 'Unauthorized' );
	}
	$path = rcmi_backup_resolve( $_POST['file'] ?? '' );
	if ( $path ) {
		@unlink( $path );
	}
	rcmi_backup_redirect( array( 'rcmi_deleted' => $path ? basename( $path ) : '' ) );
} );

add_action( 'admin_post_rcmi_backup_settings', function () {
	if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'rcmi_backup_settings' ) ) {
		wp_die( 'Unauthorized' );
	}
	rcmi_backup_save_settings( wp_unslash( $_POST['rcmi_backup'] ?? array() ) );
	rcmi_backup_redirect( array( 'rcmi_saved' => '1' ) );
} );

// Step 1: accept the uploaded zip (or reuse a stored one), show a
// confirmation screen before anything destructive happens.
add_action( 'admin_post_rcmi_backup_restore_upload', function () {
	if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'rcmi_backup_restore_upload' ) ) {
		wp_die( 'Unauthorized' );
	}
	if ( ! rcmi_backup_ensure_dir() ) {
		rcmi_backup_redirect( array( 'rcmi_error' => 'Backup directory is not writable.' ) );
	}
	$stored = rcmi_backup_resolve( $_POST['file'] ?? '' ); // restore an existing backup
	if ( $stored ) {
		rcmi_backup_redirect( array( 'rcmi_confirm' => basename( $stored ) ) );
	}
	if ( empty( $_FILES['backup_zip']['tmp_name'] ) || UPLOAD_ERR_OK !== $_FILES['backup_zip']['error'] ) {
		rcmi_backup_redirect( array( 'rcmi_error' => 'No archive uploaded.' ) );
	}
	$name = basename( sanitize_file_name( $_FILES['backup_zip']['name'] ) );
	if ( ! preg_match( '/\.zip$/i', $name ) ) {
		rcmi_backup_redirect( array( 'rcmi_error' => 'Only .zip archives are accepted.' ) );
	}
	$dest = trailingslashit( rcmi_backup_dir() )
		. sprintf( 'rcmi-backup-upload-%s-%s.zip', gmdate( 'Ymd-His' ), strtolower( wp_generate_password( 8, false, false ) ) );
	if ( ! move_uploaded_file( $_FILES['backup_zip']['tmp_name'], $dest ) ) {
		rcmi_backup_redirect( array( 'rcmi_error' => 'Could not store the uploaded archive.' ) );
	}
	$m = rcmi_backup_validate( $dest );
	if ( is_wp_error( $m ) ) {
		@unlink( $dest );
		rcmi_backup_redirect( array( 'rcmi_error' => rawurlencode( $m->get_error_message() ) ) );
	}
	rcmi_backup_redirect( array( 'rcmi_confirm' => basename( $dest ) ) );
} );

// Step 2: the actual restore — requires the typed confirmation phrase.
add_action( 'admin_post_rcmi_backup_restore', function () {
	if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'rcmi_backup_restore' ) ) {
		wp_die( 'Unauthorized' );
	}
	$path = rcmi_backup_resolve( $_POST['file'] ?? '' );
	if ( ! $path ) {
		rcmi_backup_redirect( array( 'rcmi_error' => 'Backup not found.' ) );
	}
	if ( 'RESTORE' !== strtoupper( trim( wp_unslash( $_POST['confirm'] ?? '' ) ) ) ) {
		rcmi_backup_redirect( array( 'rcmi_error' => 'Confirmation phrase did not match — restore cancelled.', 'rcmi_confirm' => basename( $path ) ) );
	}
	$opts = array(
		'snapshot' => ! empty( $_POST['snapshot'] ),
		'files'    => ! empty( $_POST['restore_files'] ),
	);
	$result = rcmi_backup_restore( $path, $opts );
	if ( is_wp_error( $result ) ) {
		rcmi_backup_redirect( array( 'rcmi_error' => rawurlencode( $result->get_error_message() ) ) );
	}
	rcmi_backup_redirect( ! empty( $result->ok )
		? array( 'rcmi_restored' => basename( $path ), 'rcmi_snapshot' => $result->snapshot ?: '' )
		: array( 'rcmi_error' => rawurlencode( $result->error ) )
	);
} );

// ── page ────────────────────────────────────────────────────────────

function rcmi_backup_admin_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Unauthorized' );
	}
	$s      = rcmi_backup_settings();
	$list   = rcmi_backup_list();
	$last   = array(
		'db'   => get_option( 'rcmi_toolkit_backup_last_db' ),
		'full' => get_option( 'rcmi_toolkit_backup_last_full' ),
	);
	$next_db   = wp_next_scheduled( RCMI_BACKUP_CRON_DB );
	$next_full = wp_next_scheduled( RCMI_BACKUP_CRON_FULL );
	$confirm   = isset( $_GET['rcmi_confirm'] ) ? rcmi_backup_resolve( sanitize_file_name( wp_unslash( $_GET['rcmi_confirm'] ) ) ) : false;
	$confirm_m = $confirm ? rcmi_backup_manifest( $confirm ) : false;
	$days      = array( 'sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday' );
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Backups', 'rcmi-toolkit' ); ?></h1>
		<p class="description">Database and uploads backups, stored in a protected directory. Downloads are authenticated; archives are never publicly reachable.</p>

		<?php if ( isset( $_GET['rcmi_error'] ) ) : ?>
			<div class="notice notice-error"><p><?php echo esc_html( wp_unslash( $_GET['rcmi_error'] ) ); ?></p></div>
		<?php endif; ?>
		<?php if ( isset( $_GET['rcmi_created'] ) ) : ?>
			<div class="notice notice-success"><p>Backup created: <code><?php echo esc_html( wp_unslash( $_GET['rcmi_created'] ) ); ?></code></p></div>
		<?php endif; ?>
		<?php if ( isset( $_GET['rcmi_restored'] ) ) : ?>
			<div class="notice notice-success"><p>Restore completed from <code><?php echo esc_html( wp_unslash( $_GET['rcmi_restored'] ) ); ?></code>.<?php echo ! empty( $_GET['rcmi_snapshot'] ) ? ' Pre-restore snapshot: <code>' . esc_html( wp_unslash( $_GET['rcmi_snapshot'] ) ) . '</code>' : ''; ?></p></div>
		<?php endif; ?>
		<?php if ( isset( $_GET['rcmi_deleted'] ) ) : ?>
			<div class="notice notice-info"><p>Backup deleted.</p></div>
		<?php endif; ?>
		<?php if ( isset( $_GET['rcmi_saved'] ) ) : ?>
			<div class="notice notice-success"><p>Schedule saved.</p></div>
		<?php endif; ?>

		<?php if ( $confirm && $confirm_m ) : ?>
			<div class="card" style="max-width:720px;border-left:4px solid #d63638;">
				<h2 style="margin-top:0">Confirm restore</h2>
				<p>You are about to restore <code><?php echo esc_html( basename( $confirm ) ); ?></code>:</p>
				<ul>
					<li>Created: <strong><?php echo esc_html( $confirm_m['created_local'] ?? 'unknown' ); ?></strong></li>
					<li>Site: <strong><?php echo esc_html( $confirm_m['site_url'] ?? 'unknown' ); ?></strong></li>
					<li>Contents: <strong><?php echo esc_html( 'full' === ( $confirm_m['type'] ?? '' ) ? 'database + uploads' : 'database only' ); ?></strong></li>
					<li>WP <?php echo esc_html( $confirm_m['wp_version'] ?? '?' ); ?> · PHP <?php echo esc_html( $confirm_m['php_version'] ?? '?' ); ?></li>
				</ul>
				<?php
				// Warn when the backup was made with different component
				// versions than the code about to run on top of it.
				$now_v  = rcmi_backup_component_versions();
				$diffs  = array();
				$labels = array(
					'theme'          => 'Theme',
					'tickets_plugin' => 'Tickets plugin',
					'tickets_db'     => 'Tickets DB schema',
					'analytics_db'   => 'Analytics DB schema',
				);
				foreach ( $labels as $k => $label ) {
					$then = $confirm_m['components'][ $k ] ?? null;
					$now  = $now_v[ $k ] ?? null;
					if ( null !== $then && '' !== $then && (string) $then !== (string) $now ) {
						$diffs[] = "$label: backup v$then → current v$now";
					}
				}
				if ( $diffs ) : ?>
					<div class="notice notice-warning inline" style="border-left-color:#dba617;">
						<p><strong>Version differences detected.</strong> After restoring, each component's schema upgrader will reconcile an older DB automatically — but <em>newer</em> backups on older code are not downgraded. Keep code updated before restoring.</p>
						<ul><?php foreach ( $diffs as $d ) : ?><li><?php echo esc_html( $d ); ?></li><?php endforeach; ?></ul>
					</div>
				<?php endif; ?>
				<p><strong>This overwrites the current database</strong> (all content, settings, tickets, analytics)<?php echo 'full' === ( $confirm_m['type'] ?? '' ) ? ' and replaces files in uploads' : ''; ?>. The site briefly enters maintenance mode during the restore.</p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'rcmi_backup_restore' ); ?>
					<input type="hidden" name="action" value="rcmi_backup_restore" />
					<input type="hidden" name="file" value="<?php echo esc_attr( basename( $confirm ) ); ?>" />
					<p><label><input type="checkbox" name="snapshot" value="1" checked /> Create a pre-restore database snapshot first (recommended)</label></p>
					<?php if ( 'full' === ( $confirm_m['type'] ?? '' ) ) : ?>
						<p><label><input type="checkbox" name="restore_files" value="1" checked /> Also restore uploaded files</label></p>
					<?php endif; ?>
					<p>Type <code>RESTORE</code> to confirm:<br />
					<input type="text" name="confirm" class="regular-text" autocomplete="off" required /></p>
					<button type="submit" class="button button-primary">Restore now</button>
					<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=rcmi-backup' ) ); ?>">Cancel</a>
				</form>
			</div>
		<?php endif; ?>

		<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:16px;max-width:1100px;margin-top:16px;">

			<div class="card" style="margin:0;">
				<h2 style="margin-top:0">Status</h2>
				<ul>
					<li>Database backup: <strong><?php echo $last['db'] ? esc_html( date_i18n( 'M j, Y g:i a', $last['db']['ts'] ) ) . ' · ' . esc_html( size_format( $last['db']['size'] ) ) : 'never'; ?></strong></li>
					<li>Full backup: <strong><?php echo $last['full'] ? esc_html( date_i18n( 'M j, Y g:i a', $last['full']['ts'] ) ) . ' · ' . esc_html( size_format( $last['full']['size'] ) ) : 'never'; ?></strong></li>
					<li>Next DB backup: <strong><?php echo $next_db ? esc_html( date_i18n( 'M j, Y g:i a', $next_db ) ) : 'not scheduled'; ?></strong></li>
					<li>Next full backup: <strong><?php echo $next_full ? esc_html( date_i18n( 'M j, Y g:i a', $next_full ) ) : 'not scheduled'; ?></strong></li>
					<li>Storage: <code><?php echo esc_html( rcmi_backup_dir() ); ?></code></li>
				</ul>
			</div>

			<div class="card" style="margin:0;">
				<h2 style="margin-top:0">Create a backup</h2>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
					<?php wp_nonce_field( 'rcmi_backup_create' ); ?>
					<input type="hidden" name="action" value="rcmi_backup_create" />
					<input type="hidden" name="type" value="db" />
					<button type="submit" class="button button-primary">Back up database now</button>
				</form>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;margin-left:8px;">
					<?php wp_nonce_field( 'rcmi_backup_create' ); ?>
					<input type="hidden" name="action" value="rcmi_backup_create" />
					<input type="hidden" name="type" value="full" />
					<button type="submit" class="button">Back up database + files</button>
				</form>
				<p class="description">Archives are zip files containing a manifest and a SQL dump; full backups also include uploads.</p>
			</div>

			<div class="card" style="margin:0;">
				<h2 style="margin-top:0">Import / restore</h2>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
					<?php wp_nonce_field( 'rcmi_backup_restore_upload' ); ?>
					<input type="hidden" name="action" value="rcmi_backup_restore_upload" />
					<input type="file" name="backup_zip" accept=".zip" required />
					<button type="submit" class="button">Upload &amp; continue</button>
				</form>
				<p class="description">Validates the archive, shows what it will replace, and asks for confirmation before touching anything.</p>
			</div>
		</div>

		<h2 style="margin-top:24px;">Schedule</h2>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="card" style="max-width:1100px;">
			<?php wp_nonce_field( 'rcmi_backup_settings' ); ?>
			<input type="hidden" name="action" value="rcmi_backup_settings" />
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">Database backup</th>
					<td>
						<select name="rcmi_backup[db_schedule]">
							<option value="off" <?php selected( $s['db_schedule'], 'off' ); ?>>Off</option>
							<option value="daily" <?php selected( $s['db_schedule'], 'daily' ); ?>>Daily</option>
							<option value="weekly" <?php selected( $s['db_schedule'], 'weekly' ); ?>>Weekly</option>
						</select>
						<select name="rcmi_backup[db_day]">
							<?php foreach ( $days as $d ) : ?>
								<option value="<?php echo esc_attr( $d ); ?>" <?php selected( $s['db_day'], $d ); ?>><?php echo esc_html( ucfirst( $d ) ); ?></option>
							<?php endforeach; ?>
						</select>
						<input type="time" name="rcmi_backup[db_time]" value="<?php echo esc_attr( $s['db_time'] ); ?>" />
						&nbsp;keep last <input type="number" name="rcmi_backup[db_keep]" value="<?php echo esc_attr( $s['db_keep'] ); ?>" min="1" max="60" style="width:70px;" />
						<p class="description">Weekly uses the day + time; daily uses the time only.</p>
					</td>
				</tr>
				<tr>
					<th scope="row">Full backup (database + uploads)</th>
					<td>
						<select name="rcmi_backup[full_schedule]">
							<option value="off" <?php selected( $s['full_schedule'], 'off' ); ?>>Off</option>
							<option value="daily" <?php selected( $s['full_schedule'], 'daily' ); ?>>Daily</option>
							<option value="weekly" <?php selected( $s['full_schedule'], 'weekly' ); ?>>Weekly</option>
						</select>
						<select name="rcmi_backup[full_day]">
							<?php foreach ( $days as $d ) : ?>
								<option value="<?php echo esc_attr( $d ); ?>" <?php selected( $s['full_day'], $d ); ?>><?php echo esc_html( ucfirst( $d ) ); ?></option>
							<?php endforeach; ?>
						</select>
						<input type="time" name="rcmi_backup[full_time]" value="<?php echo esc_attr( $s['full_time'] ); ?>" />
						&nbsp;keep last <input type="number" name="rcmi_backup[full_keep]" value="<?php echo esc_attr( $s['full_keep'] ); ?>" min="1" max="60" style="width:70px;" />
					</td>
				</tr>
				<tr>
					<th scope="row">Notifications</th>
					<td><label><input type="checkbox" name="rcmi_backup[email_notify]" value="1" <?php checked( $s['email_notify'] ); ?> /> Email <?php echo esc_html( get_option( 'admin_email' ) ); ?> when a scheduled backup finishes or fails</label></td>
				</tr>
			</table>
			<p class="submit"><button type="submit" class="button button-primary">Save schedule</button>
			<span class="description" style="margin-left:10px;">Schedules run via WP-Cron — they fire on site traffic, so exact times are approximate. For precise timing, run <code>wp cron event run --due-now</code> from a system cron.</span></p>
		</form>

		<h2 style="margin-top:24px;">Stored backups</h2>
		<?php if ( empty( $list ) ) : ?>
			<p>No backups yet.</p>
		<?php else : ?>
		<table class="widefat striped" style="max-width:1100px;">
			<thead><tr><th>Created</th><th>Type</th><th>Size</th><th>Contents</th><th style="width:240px;">Actions</th></tr></thead>
			<tbody>
			<?php foreach ( $list as $b ) : ?>
				<tr>
					<td><?php echo esc_html( date_i18n( 'M j, Y g:i:s a', $b['mtime'] ) ); ?><?php echo $b['label'] ? ' <em>(' . esc_html( $b['label'] ) . ')</em>' : ''; ?></td>
					<td><?php echo esc_html( 'full' === $b['type'] ? 'Full' : 'DB' ); ?><?php echo $b['valid'] ? '' : ' <span style="color:#b32d2e;">(unreadable)</span>'; ?></td>
					<td><?php echo esc_html( size_format( $b['size'] ) ); ?></td>
					<td><?php echo 'full' === $b['type'] && ! empty( $b['files']['count'] ) ? esc_html( number_format( $b['files']['count'] ) . ' files' ) : 'database only'; ?></td>
					<td>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
							<?php wp_nonce_field( 'rcmi_backup_download' ); ?>
							<input type="hidden" name="action" value="rcmi_backup_download" />
							<input type="hidden" name="file" value="<?php echo esc_attr( $b['file'] ); ?>" />
							<button class="button button-small">Download</button>
						</form>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
							<?php wp_nonce_field( 'rcmi_backup_restore_upload' ); ?>
							<input type="hidden" name="action" value="rcmi_backup_restore_upload" />
							<input type="hidden" name="file" value="<?php echo esc_attr( $b['file'] ); ?>" />
							<button class="button button-small">Restore…</button>
						</form>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;" onsubmit="return confirm('Delete this backup? This cannot be undone.');">
							<?php wp_nonce_field( 'rcmi_backup_delete' ); ?>
							<input type="hidden" name="action" value="rcmi_backup_delete" />
							<input type="hidden" name="file" value="<?php echo esc_attr( $b['file'] ); ?>" />
							<button class="button button-small button-link-delete">Delete</button>
						</form>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php endif; ?>
	</div>
	<?php
}
