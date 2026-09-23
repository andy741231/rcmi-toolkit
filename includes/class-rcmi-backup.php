<?php
/**
 * RCMI Backup — engine.
 *
 * Pure-PHP backup/restore: no exec(), no mysqldump (production is
 * Windows/IIS). Produces a single .zip containing:
 *   manifest.json  — versions, table prefix, site URL, timestamp, contents
 *   database.sql   — all tables, one statement per line, utf8mb4
 *   files/uploads/ — wp-content/uploads tree (type "full" only)
 *
 * Backups live in wp-content/uploads/rcmi-backups/ hardened like the
 * ticket-attachment store: index.php + .htaccess deny + IIS web.config
 * hidden segment. Downloads go through an authenticated admin endpoint.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'RCMI_BACKUP_DIR_NAME', 'rcmi-backups' );
define( 'RCMI_BACKUP_VERSION', '1' );
define( 'RCMI_BACKUP_SETTINGS', 'rcmi_toolkit_backup_settings' );
define( 'RCMI_BACKUP_CRON_DB', 'rcmi_toolkit_backup_db' );
define( 'RCMI_BACKUP_CRON_FULL', 'rcmi_toolkit_backup_full' );

// ── storage ─────────────────────────────────────────────────────────

function rcmi_backup_dir() {
	$uploads = wp_upload_dir( null, false );
	return trailingslashit( $uploads['basedir'] ) . RCMI_BACKUP_DIR_NAME;
}

function rcmi_backup_protection_files() {
	return array(
		'index.php'  => "<?php\nexit;\n",
		'.htaccess'  => "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n    Deny from all\n</IfModule>\n",
		'web.config' => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration>\n    <system.webServer>\n        <security>\n            <requestFiltering>\n                <hiddenSegments>\n                    <remove segment=\"rcmi-backups\" />\n                    <add segment=\"rcmi-backups\" />\n                </hiddenSegments>\n            </requestFiltering>\n        </security>\n    </system.webServer>\n</configuration>\n",
	);
}

function rcmi_backup_ensure_dir() {
	$dir = rcmi_backup_dir();
	if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
		return false;
	}
	foreach ( rcmi_backup_protection_files() as $name => $contents ) {
		$path = trailingslashit( $dir ) . $name;
		$cur  = file_exists( $path ) ? @file_get_contents( $path ) : false;
		if ( $cur !== $contents && false === @file_put_contents( $path, $contents, LOCK_EX ) ) {
			return false;
		}
	}
	return true;
}
add_action( 'init', 'rcmi_backup_ensure_dir' );

/**
 * Resolve a user-supplied backup filename to a path inside the backup
 * dir, or false if it fails validation.
 */
function rcmi_backup_resolve( $filename ) {
	$filename = basename( (string) $filename );
	if ( ! preg_match( '/^rcmi-backup-[a-zA-Z0-9._-]+\.zip$/', $filename ) ) {
		return false;
	}
	$path = trailingslashit( rcmi_backup_dir() ) . $filename;
	return is_file( $path ) ? $path : false;
}

// ── settings / schedule ─────────────────────────────────────────────

function rcmi_backup_defaults() {
	return array(
		'db_schedule'   => 'daily',   // off | daily | weekly
		'db_day'        => 'sunday',  // weekly only
		'db_time'       => '02:00',
		'db_keep'       => 7,
		'full_schedule' => 'weekly',
		'full_day'      => 'sunday',
		'full_time'     => '03:00',
		'full_keep'     => 4,
		'email_notify'  => false,
	);
}

function rcmi_backup_settings() {
	$saved = get_option( RCMI_BACKUP_SETTINGS, array() );
	return array_merge( rcmi_backup_defaults(), is_array( $saved ) ? $saved : array() );
}

/**
 * Next run timestamp (site-local) for one schedule spec.
 */
function rcmi_backup_next_run( $freq, $day, $time ) {
	if ( 'off' === $freq ) {
		return 0;
	}
	$tz    = wp_timezone();
	$parts = explode( ':', $time );
	$hh    = isset( $parts[0] ) ? (int) $parts[0] : 2;
	$mm    = isset( $parts[1] ) ? (int) $parts[1] : 0;

	$now    = new DateTime( 'now', $tz );
	$target = clone $now;
	$target->setTime( $hh, $mm, 0 );

	if ( 'daily' === $freq ) {
		if ( $target <= $now ) {
			$target->modify( '+1 day' );
		}
	} else { // weekly
		$day = strtolower( $day );
		$days = array( 'sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday' );
		if ( ! in_array( $day, $days, true ) ) {
			$day = 'sunday';
		}
		// Move to the chosen weekday at the chosen time, this week or next.
		while ( strtolower( $target->format( 'l' ) ) !== $day || $target <= $now ) {
			$target->modify( '+1 day' );
		}
	}
	return $target->getTimestamp();
}

function rcmi_backup_reschedule() {
	$s = rcmi_backup_settings();

	wp_clear_scheduled_hook( RCMI_BACKUP_CRON_DB );
	wp_clear_scheduled_hook( RCMI_BACKUP_CRON_FULL );

	$db_next = rcmi_backup_next_run( $s['db_schedule'], $s['db_day'], $s['db_time'] );
	if ( $db_next ) {
		wp_schedule_event( $db_next, 'daily' === $s['db_schedule'] ? 'daily' : 'weekly', RCMI_BACKUP_CRON_DB );
	}
	$full_next = rcmi_backup_next_run( $s['full_schedule'], $s['full_day'], $s['full_time'] );
	if ( $full_next ) {
		wp_schedule_event( $full_next, 'daily' === $s['full_schedule'] ? 'daily' : 'weekly', RCMI_BACKUP_CRON_FULL );
	}
}

function rcmi_backup_save_settings( $new ) {
	$clean = array_merge( rcmi_backup_defaults(), array(
		'db_schedule'   => in_array( $new['db_schedule'] ?? '', array( 'off', 'daily', 'weekly' ), true ) ? $new['db_schedule'] : 'daily',
		'db_day'        => sanitize_key( $new['db_day'] ?? 'sunday' ),
		'db_time'       => preg_match( '/^\d{2}:\d{2}$/', $new['db_time'] ?? '' ) ? $new['db_time'] : '02:00',
		'db_keep'       => max( 1, min( 60, (int) ( $new['db_keep'] ?? 7 ) ) ),
		'full_schedule' => in_array( $new['full_schedule'] ?? '', array( 'off', 'daily', 'weekly' ), true ) ? $new['full_schedule'] : 'weekly',
		'full_day'      => sanitize_key( $new['full_day'] ?? 'sunday' ),
		'full_time'     => preg_match( '/^\d{2}:\d{2}$/', $new['full_time'] ?? '' ) ? $new['full_time'] : '03:00',
		'full_keep'     => max( 1, min( 60, (int) ( $new['full_keep'] ?? 4 ) ) ),
		'email_notify'  => ! empty( $new['email_notify'] ),
	) );
	update_option( RCMI_BACKUP_SETTINGS, $clean, false );
	rcmi_backup_reschedule();
	return $clean;
}

// Self-heal: if settings expect a schedule but the cron event is missing
// (or vice versa), re-register. Runs once per request; no writes when in sync.
add_action( 'init', function () {
	$s        = rcmi_backup_settings();
	$db_next  = wp_next_scheduled( RCMI_BACKUP_CRON_DB );
	$full_next = wp_next_scheduled( RCMI_BACKUP_CRON_FULL );
	$drift    = ( 'off' === $s['db_schedule'] ) !== ! $db_next
		|| ( 'off' === $s['full_schedule'] ) !== ! $full_next;
	if ( $drift ) {
		rcmi_backup_reschedule();
	}
}, 20 );

add_action( RCMI_BACKUP_CRON_DB, function () {
	rcmi_backup_run_scheduled( 'db' );
} );
add_action( RCMI_BACKUP_CRON_FULL, function () {
	rcmi_backup_run_scheduled( 'full' );
} );

function rcmi_backup_run_scheduled( $type ) {
	$s      = rcmi_backup_settings();
	$result = rcmi_backup_create( $type );
	$keep   = 'db' === $type ? (int) $s['db_keep'] : (int) $s['full_keep'];
	if ( ! is_wp_error( $result ) ) {
		rcmi_backup_prune( $type, $keep );
	}
	if ( $s['email_notify'] ) {
		$admin   = get_option( 'admin_email' );
		$subject = is_wp_error( $result )
			? sprintf( '[%s] Scheduled %s backup FAILED', wp_parse_url( home_url(), PHP_URL_HOST ), $type )
			: sprintf( '[%s] Scheduled %s backup completed', wp_parse_url( home_url(), PHP_URL_HOST ), $type );
		$body    = is_wp_error( $result )
			? 'The scheduled backup failed: ' . $result->get_error_message()
			: 'Backup created: ' . basename( $result ) . ' (' . size_format( filesize( $result ) ) . ')';
		wp_mail( $admin, $subject, $body );
	}
	return $result;
}

// ── database dump ───────────────────────────────────────────────────

/**
 * Dump all tables to $path as one SQL statement per line (each line ends
 * with ";"). String values are escaped so no literal newlines appear —
 * restore splits safely on line boundaries.
 */
function rcmi_backup_dump_db( $path ) {
	global $wpdb;

	$out = fopen( $path, 'wb' );
	if ( ! $out ) {
		return new WP_Error( 'rcmi_backup_dump_open', 'Cannot write the database dump file.' );
	}

	$write = function ( $line ) use ( $out ) {
		fwrite( $out, $line . "\n" );
	};

	$write( '-- RCMI backup ' . RCMI_BACKUP_VERSION . ' — ' . gmdate( 'c' ) );
	$write( '-- site: ' . home_url() );
	$write( 'SET NAMES utf8mb4;' );
	$write( 'SET FOREIGN_KEY_CHECKS=0;' );
	$write( 'SET SQL_MODE="NO_AUTO_VALUE_ON_ZERO";' );

	$tables = $wpdb->get_col( 'SHOW TABLES' );
	foreach ( $tables as $table ) {
		$create = $wpdb->get_row( 'SHOW CREATE TABLE `' . esc_sql( $table ) . '`', ARRAY_N );
		if ( empty( $create[1] ) ) {
			continue;
		}
		$write( '' );
		$write( '-- Table: ' . $table );
		$write( 'DROP TABLE IF EXISTS `' . $table . '`;' );
		// Collapse to one line — CREATE TABLE contains newlines between
		// column defs, and the restore format is one statement per line.
		$write( preg_replace( '/\s+/', ' ', $create[1] ) . ';' );

		// Column types decide quoting: numeric unquoted, binary as hex,
		// everything else escaped string.
		$cols  = $wpdb->get_results( 'DESCRIBE `' . esc_sql( $table ) . '`', ARRAY_A );
		$kinds = array();
		foreach ( $cols as $c ) {
			$t = strtolower( $c['Type'] );
			if ( preg_match( '/int|decimal|float|double|real|bit/', $t ) ) {
				$kinds[] = 'num';
			} elseif ( preg_match( '/binary|blob/', $t ) ) {
				$kinds[] = 'hex';
			} else {
				$kinds[] = 'str';
			}
		}

		$offset  = 0;
		$per     = 2000; // rows per SELECT chunk
		$columns = '`' . implode( '`, `', wp_list_pluck( $cols, 'Field' ) ) . '`';
		while ( true ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare( 'SELECT * FROM `' . esc_sql( $table ) . '` LIMIT %d OFFSET %d', $per, $offset ),
				ARRAY_N
			);
			if ( empty( $rows ) ) {
				break;
			}
			$batch   = array();
			$batch_n = 0;
			foreach ( $rows as $row ) {
				$vals = array();
				foreach ( $row as $i => $v ) {
					if ( null === $v ) {
						$vals[] = 'NULL';
					} elseif ( 'num' === $kinds[ $i ] && is_numeric( $v ) ) {
						$vals[] = $v;
					} elseif ( 'hex' === $kinds[ $i ] ) {
						$vals[] = "0x" . bin2hex( $v );
					} else {
						$vals[] = "'" . $wpdb->_real_escape( $v ) . "'";
					}
				}
				$row_sql = '(' . implode( ',', $vals ) . ')';
				$batch[] = $row_sql;
				$batch_n += strlen( $row_sql );
				if ( count( $batch ) >= 250 || $batch_n > 524288 ) { // ~512KB per INSERT
					$write( 'INSERT INTO `' . $table . '` (' . $columns . ') VALUES ' . implode( ',', $batch ) . ';' );
					$batch   = array();
					$batch_n = 0;
				}
			}
			if ( $batch ) {
				$write( 'INSERT INTO `' . $table . '` (' . $columns . ') VALUES ' . implode( ',', $batch ) . ';' );
			}
			$offset += $per;
			if ( count( $rows ) < $per ) {
				break;
			}
		}
	}
	$write( '' );
	$write( 'SET FOREIGN_KEY_CHECKS=1;' );
	fclose( $out );
	return true;
}

/**
 * Versions of every component whose data lands in the backup — used by
 * the manifest and by the restore confirm screen to surface skew.
 */
function rcmi_backup_component_versions() {
	$tickets_file = WP_PLUGIN_DIR . '/rcmi-tickets/rcmi-tickets.php';
	$tickets_ver  = '';
	if ( file_exists( $tickets_file ) ) {
		$header      = get_file_data( $tickets_file, array( 'Version' => 'Version' ) );
		$tickets_ver = $header['Version'] ?? '';
	}
	return array(
		'theme'          => wp_get_theme()->get( 'Version' ),
		'tickets_plugin' => $tickets_ver,
		'tickets_db'     => get_option( 'rcmi_tickets_db_version' ),
		'analytics_db'   => get_option( 'rcmi_toolkit_analytics_db_version' ),
	);
}

// ── create ──────────────────────────────────────────────────────────

/**
 * Compression method per file. Already-compressed formats (images, video,
 * PDFs, Office docs, archives, fonts) gain ~0% from DEFLATE but cost the
 * same CPU — storing them raw keeps the silent zip->close() window short,
 * which matters on IIS where FastCGI activityTimeout kills quiet requests.
 */
function rcmi_backup_zip_method( $rel ) {
	static $store = array(
		'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'ico', 'heic', 'heif',
		'mp4', 'm4v', 'mov', 'webm', 'avi', 'mkv', 'mp3', 'm4a', 'ogg', 'wav',
		'zip', 'gz', 'bz2', '7z', 'rar', 'xz', 'tgz', 'jar', 'dmg', 'iso',
		'pdf', 'docx', 'xlsx', 'pptx', 'odt', 'ods', 'odp',
		'woff', 'woff2', 'eot', 'exe', 'msi',
	);
	$ext = strtolower( pathinfo( $rel, PATHINFO_EXTENSION ) );
	return in_array( $ext, $store, true ) ? ZipArchive::CM_STORE : ZipArchive::CM_DEFLATE;
}

/**
 * Build a backup zip. $type: 'db' | 'full'. Returns path or WP_Error.
 */
function rcmi_backup_create( $type = 'db', $label = '' ) {
	if ( ! in_array( $type, array( 'db', 'full' ), true ) ) {
		return new WP_Error( 'rcmi_backup_type', 'Unknown backup type.' );
	}
	if ( ! class_exists( 'ZipArchive' ) ) {
		return new WP_Error( 'rcmi_backup_zip', 'PHP ZipArchive extension is not available on this server.' );
	}
	if ( ! rcmi_backup_ensure_dir() ) {
		return new WP_Error( 'rcmi_backup_dir', 'Backup directory is not writable.' );
	}

	@set_time_limit( 0 );
	@ini_set( 'memory_limit', '512M' );

	$dir = rcmi_backup_dir();

	// DB dump → temp file
	$tmp_sql = wp_tempnam( 'rcmi-dump.sql' );
	$r = rcmi_backup_dump_db( $tmp_sql );
	if ( is_wp_error( $r ) ) {
		@unlink( $tmp_sql );
		return $r;
	}

	$stamp    = gmdate( 'Y-m-d-His' );
	$rand     = substr( wp_generate_password( 8, false, false ), 0, 8 );
	$filename = sprintf( 'rcmi-backup-%s-%s-%s.zip', $type, $stamp, strtolower( $rand ) );
	$path     = trailingslashit( $dir ) . $filename;

	$zip = new ZipArchive();
	if ( true !== $zip->open( $path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
		@unlink( $tmp_sql );
		return new WP_Error( 'rcmi_backup_zip_open', 'Failed to create the backup archive.' );
	}
	$zip->addFile( $tmp_sql, 'database.sql' );
	$zip->setCompressionName( 'database.sql', ZipArchive::CM_DEFLATE );

	$manifest = array(
		'format'        => 'rcmi-backup',
		'version'       => RCMI_BACKUP_VERSION,
		'type'          => $type,
		'label'         => $label,
		'created_gmt'   => gmdate( 'c' ),
		'created_local' => current_time( 'c' ),
		'site_url'      => home_url(),
		'table_prefix'  => $GLOBALS['wpdb']->prefix,
		'db_name'       => defined( 'DB_NAME' ) ? DB_NAME : '',
		'wp_version'    => get_bloginfo( 'version' ),
		'php_version'   => PHP_VERSION,
		'db_server'     => $GLOBALS['wpdb']->get_var( 'SELECT VERSION()' ),
		'toolkit'       => defined( 'RCMI_TOOLKIT_VERSION' ) ? RCMI_TOOLKIT_VERSION : '',
		// Component versions at backup time — the confirm screen compares
		// these to the running code so a schema/version skew is visible
		// before anything is overwritten.
		'components'    => rcmi_backup_component_versions(),
		'files'         => array(),
	);

	if ( 'full' === $type ) {
		$uploads = wp_upload_dir( null, false );
		$base    = trailingslashit( $uploads['basedir'] );
		$count   = 0;
		$bytes   = 0;
		$free    = disk_free_space( $dir );
		$iter    = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $base, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::LEAVES_ONLY
		);
		foreach ( $iter as $file ) {
			if ( ! $file->isFile() ) {
				continue;
			}
			$abs  = $file->getPathname();
			$rel  = ltrim( substr( $abs, strlen( $base ) ), '/' );
			$rel  = str_replace( DIRECTORY_SEPARATOR, '/', $rel );
			// Never archive the backup store itself or cache dirs.
			if ( 0 === strpos( $rel, RCMI_BACKUP_DIR_NAME . '/' ) || 0 === strpos( $rel, 'cache/' ) ) {
				continue;
			}
			$entry = 'files/uploads/' . $rel;
			$zip->addFile( $abs, $entry );
			$zip->setCompressionName( $entry, rcmi_backup_zip_method( $rel ) );
			$count++;
			$bytes += $file->getSize();
			// Raw bytes are a safe upper bound for the archive — bail before
			// close() writes a partial zip the disk can't hold.
			if ( false !== $free && $bytes > $free ) {
				$zip->close();
				@unlink( $path );
				@unlink( $tmp_sql );
				return new WP_Error( 'rcmi_backup_space', 'Not enough free disk space for this backup.' );
			}
		}
		$manifest['files'] = array( 'count' => $count, 'bytes' => $bytes );
	}

	$zip->addFromString( 'manifest.json', wp_json_encode( $manifest, JSON_PRETTY_PRINT ) );
	$zip->close();
	@unlink( $tmp_sql );

	update_option( 'rcmi_toolkit_backup_last_' . $type, array(
		'ts'   => time(),
		'file' => $filename,
		'size' => filesize( $path ),
	), false );

	return $path;
}

// ── list / prune / delete ───────────────────────────────────────────

function rcmi_backup_manifest( $path ) {
	$zip = new ZipArchive();
	if ( true !== $zip->open( $path ) ) {
		return false;
	}
	$json = $zip->getFromName( 'manifest.json' );
	$zip->close();
	$m = $json ? json_decode( $json, true ) : false;
	return is_array( $m ) ? $m : false;
}

function rcmi_backup_list() {
	$dir  = rcmi_backup_dir();
	$rows = array();
	foreach ( glob( trailingslashit( $dir ) . 'rcmi-backup-*.zip' ) ?: array() as $path ) {
		$m = rcmi_backup_manifest( $path );
		$rows[] = array(
			'file'    => basename( $path ),
			'path'    => $path,
			'size'    => filesize( $path ),
			'mtime'   => filemtime( $path ),
			'type'    => $m['type'] ?? 'unknown',
			'label'   => $m['label'] ?? '',
			'created' => $m['created_local'] ?? gmdate( 'c', filemtime( $path ) ),
			'files'   => $m['files'] ?? array(),
			'valid'   => false !== $m,
		);
	}
	usort( $rows, function ( $a, $b ) {
		return $b['mtime'] <=> $a['mtime'];
	} );
	return $rows;
}

/**
 * Keep the newest $keep backups of $type; delete the rest.
 * Pre-restore snapshots are never pruned.
 */
function rcmi_backup_prune( $type, $keep ) {
	$rows = array_values( array_filter( rcmi_backup_list(), function ( $r ) use ( $type ) {
		return $r['type'] === $type && 'pre-restore' !== $r['label'];
	} ) );
	$removed = 0;
	foreach ( array_slice( $rows, max( 1, (int) $keep ) ) as $r ) {
		if ( @unlink( $r['path'] ) ) {
			$removed++;
		}
	}
	return $removed;
}

// ── restore ─────────────────────────────────────────────────────────

/**
 * Validate an uploaded/local backup zip. Returns manifest or WP_Error.
 */
function rcmi_backup_validate( $path ) {
	if ( ! class_exists( 'ZipArchive' ) ) {
		return new WP_Error( 'rcmi_backup_zip', 'PHP ZipArchive is not available.' );
	}
	$zip = new ZipArchive();
	if ( true !== $zip->open( $path ) ) {
		return new WP_Error( 'rcmi_backup_zip_open', 'The file is not a readable zip archive.' );
	}
	$json = $zip->getFromName( 'manifest.json' );
	$sql  = $zip->locateName( 'database.sql' );
	$zip->close();
	if ( ! $json || false === $sql ) {
		return new WP_Error( 'rcmi_backup_manifest', 'Not an RCMI backup archive (manifest.json / database.sql missing).' );
	}
	$m = json_decode( $json, true );
	if ( ! is_array( $m ) || ( $m['format'] ?? '' ) !== 'rcmi-backup' ) {
		return new WP_Error( 'rcmi_backup_manifest', 'Unrecognized backup manifest.' );
	}
	if ( ( $m['table_prefix'] ?? '' ) !== $GLOBALS['wpdb']->prefix ) {
		return new WP_Error(
			'rcmi_backup_prefix',
			sprintf( 'Table prefix mismatch: backup uses "%s", this site uses "%s".', $m['table_prefix'], $GLOBALS['wpdb']->prefix )
		);
	}
	return $m;
}

/**
 * Import database.sql one statement per line (the dump format writes a
 * single statement per line terminated by ";").
 */
function rcmi_backup_import_sql( $path, $skip_tables = array() ) {
	global $wpdb;
	$in = fopen( $path, 'rb' );
	if ( ! $in ) {
		return new WP_Error( 'rcmi_backup_sql_open', 'Cannot read the SQL file.' );
	}
	$wpdb->query( 'SET FOREIGN_KEY_CHECKS=0' );
	$statements = 0;
	$skipped    = 0;
	$errors     = array();
	$buffer     = '';
	while ( ( $line = fgets( $in ) ) !== false ) {
		$line = rtrim( $line, "\r\n" );
		// Skip comments/blank lines only when not mid-statement.
		if ( '' === $buffer && ( '' === $line || 0 === strpos( $line, '--' ) ) ) {
			continue;
		}
		$buffer .= ( '' === $buffer ? '' : ' ' ) . $line;
		if ( ';' !== substr( $line, -1 ) ) {
			continue; // statement continues on the next line
		}
		$stmt   = substr( $buffer, 0, -1 );
		$buffer = '';
		if ( $skip_tables
			&& preg_match( '/^\s*(?:DROP\s+TABLE\s+IF\s+EXISTS|CREATE\s+TABLE|INSERT\s+INTO|TRUNCATE\s+TABLE|ALTER\s+TABLE|DELETE\s+FROM)\s+`?([A-Za-z0-9_]+)`?/i', $stmt, $tm )
			&& in_array( $tm[1], $skip_tables, true ) ) {
			$skipped++;
			continue;
		}
		if ( false === $wpdb->query( $stmt ) ) {
			$errors[] = $wpdb->last_error;
			if ( count( $errors ) >= 5 ) {
				break;
			}
		}
		$statements++;
	}
	fclose( $in );
	$wpdb->query( 'SET FOREIGN_KEY_CHECKS=1' );
	if ( $errors ) {
		return new WP_Error( 'rcmi_backup_sql', 'SQL import failed: ' . $errors[0], array( 'errors' => $errors, 'statements' => $statements ) );
	}
	return array( 'statements' => $statements, 'skipped' => $skipped );
}

// ============================================================================
// URL rewriting (clone mode)
// ============================================================================

/**
 * Build regex search/replace pairs that rewrite $from_url to $to_url.
 *
 * Covers the ways an absolute URL survives in DB content: plain, JSON-escaped
 * (https:\/\/…), and protocol-relative (//…). The lookahead requires a URL
 * boundary after the match so /rcmi never rewrites inside /rcmibeta.
 *
 * @param string $from_url e.g. https://uhph.uh.edu/rcmi
 * @param string $to_url   e.g. http://localhost:8000
 * @return array pattern => replacement
 */
function rcmi_backup_url_pairs( $from_url, $to_url ) {
	$from       = untrailingslashit( $from_url );
	$to         = untrailingslashit( $to_url );
	$from_hp    = preg_replace( '#^https?://#i', '', $from );
	$to_hp      = preg_replace( '#^https?://#i', '', $to );
	$q_hp       = preg_quote( $from_hp, '#' );                          // uhph\.uh\.edu/rcmi (preg_quote leaves / bare for a # delimiter)
	$q_hp_esc   = str_replace( '/', '\\\\/', $q_hp );                   // regex matching JSON-escaped uhph.uh.edu\/rcmi
	// Replacement strings: preg_replace treats \ and $ specially, so
	// escape them first; '\\\\/' emits a literal '\/' for JSON contexts.
	$to_r       = str_replace( array( '\\', '$' ), array( '\\\\', '\\$' ), $to );
	$to_esc_s   = str_replace( '/', '\\\\/', $to_r );                   // emits http:\/\/localhost:8000
	$to_hp_safe = str_replace( array( '\\', '$' ), array( '\\\\', '\\$' ), '//' . $to_hp );
	$look       = '(?=[/"\'?\#\s\\\\<)\]]|$)';                          // URL must end or be followed by a delimiter (# escaped — it's the pattern delimiter)
	return array(
		'#https?://' . $q_hp . $look . '#i'                => $to_r,
		'#https?:\\\\/\\\\/' . $q_hp_esc . $look . '#i'    => $to_esc_s,
		'#//' . $q_hp . $look . '#i'                       => $to_hp_safe,
	);
}

/**
 * Replace all URL patterns inside a plain string.
 */
function rcmi_backup_replace_str( $str, $pairs ) {
	foreach ( $pairs as $re => $rep ) {
		$str = preg_replace( $re, $rep, $str );
	}
	return $str;
}

/**
 * Walk a serialized blob token by token, rewriting URL patterns inside
 * string payloads and recomputing their declared byte lengths. Never calls
 * unserialize — payloads are sliced by declared length, so quotes or
 * "s:1:"-looking text inside string data can't desync the walk, and objects
 * whose class isn't loaded (__PHP_Incomplete_Class — e.g. Freemius FS_*
 * objects stored in options) keep their exact byte structure instead of
 * fataling on property writes.
 *
 * Tokens handled: s:LEN:"DATA";  O:LEN:"Class":N:{...}  E:LEN:"Enum:Case";
 * and C:LEN:"Class":DLEN:{DATA} (legacy Serializable interface — its second
 * length-prefixed payload is rewritten too). a:/i:/d:/b:/N;/R: carry no
 * string payload and pass through as structure.
 */
function rcmi_backup_replace_serialized( $ser, $pairs ) {
	$out = '';
	$pos = 0;
	$len = strlen( $ser );
	while ( $pos < $len && preg_match( '/([sOCE]):(\d+):"/', $ser, $m, PREG_OFFSET_CAPTURE, $pos ) ) {
		$mpos    = $m[0][1];
		$decl    = (int) $m[2][0];
		$payload = substr( $ser, $mpos + strlen( $m[0][0] ), $decl );
		$new     = rcmi_backup_replace_blob( $payload, $pairs );
		// Emit tag + corrected length + payload WITHOUT a terminator — the
		// original bytes after the payload ('";' for s/E, '":' for O/C) are
		// copied as structure, preserving each type's real syntax.
		$out .= substr( $ser, $pos, $mpos - $pos ) . $m[1][0] . ':' . strlen( $new ) . ':"' . $new;
		$pos  = $mpos + strlen( $m[0][0] ) + $decl;
		if ( 'C' === $m[1][0] && preg_match( '/":(\d+):\{/', $ser, $m2, PREG_OFFSET_CAPTURE, $pos ) && $pos === $m2[0][1] ) {
			$cpay = substr( $ser, $m2[0][1] + strlen( $m2[0][0] ), (int) $m2[1][0] );
			$cnew = rcmi_backup_replace_blob( $cpay, $pairs );
			$out .= '":' . strlen( $cnew ) . ':{' . $cnew;
			$pos  = $m2[0][1] + strlen( $m2[0][0] ) + (int) $m2[1][0];
		}
	}
	return $out . substr( $ser, $pos );
}

/**
 * Replace for a single DB column value. Serialized blobs take the
 * token-walking rewrite so length prefixes stay valid (checked BEFORE any
 * replace — a naive str_replace first would stale the lengths and make
 * is_serialized fail); anything else is a plain string replace. Recurses
 * into double-serialized payloads via the scanner.
 */
function rcmi_backup_replace_blob( $str, $pairs ) {
	if ( ! is_string( $str ) || '' === $str ) {
		return $str;
	}
	return is_serialized( $str )
		? rcmi_backup_replace_serialized( $str, $pairs )
		: rcmi_backup_replace_str( $str, $pairs );
}

/**
 * Alias kept as the public entry point for the rewrite loop.
 */
function rcmi_backup_replace_value( $value, $pairs ) {
	return rcmi_backup_replace_blob( $value, $pairs );
}

/**
 * Rewrite URLs across every text column of every table. Rows are prefiltered
 * by a LIKE on the bare host so only candidate rows are touched.
 *
 * @param array  $pairs       pattern => replacement from rcmi_backup_url_pairs()
 * @param string $like_needle prefilter substring (bare host of the source URL)
 * @param array  $skip_tables tables to leave alone (e.g. preserved users)
 * @return array table.column => rows changed, plus '_errors' if any
 */
function rcmi_backup_rewrite_urls( $pairs, $like_needle, $skip_tables = array() ) {
	global $wpdb;
	$report = array();
	$like   = '%' . $wpdb->esc_like( $like_needle ) . '%';
	foreach ( $wpdb->get_col( 'SHOW TABLES' ) as $table ) {
		if ( in_array( $table, $skip_tables, true ) ) {
			continue;
		}
		$pk        = null;
		$text_cols = array();
		foreach ( $wpdb->get_results( 'DESCRIBE `' . esc_sql( $table ) . '`', ARRAY_A ) as $c ) {
			if ( 'PRI' === $c['Key'] && null === $pk ) {
				$pk = $c['Field'];
			}
			if ( preg_match( '/char|text/i', $c['Type'] ) ) {
				$text_cols[] = $c['Field'];
			}
		}
		foreach ( $text_cols as $col ) {
			$sel  = null !== $pk
				? "SELECT `$pk` AS _id, `$col` AS _v FROM `$table` WHERE `$col` LIKE %s"
				: "SELECT `$col` AS _v FROM `$table` WHERE `$col` LIKE %s";
			foreach ( (array) $wpdb->get_results( $wpdb->prepare( $sel, $like ), ARRAY_A ) as $row ) {
				$new = rcmi_backup_replace_value( $row['_v'], $pairs );
				if ( $new === $row['_v'] ) {
					continue;
				}
				$ok = null !== $pk
					? $wpdb->update( $table, array( $col => $new ), array( $pk => $row['_id'] ) )
					: $wpdb->query( $wpdb->prepare( "UPDATE `$table` SET `$col` = %s WHERE `$col` = %s", $new, $row['_v'] ) );
				if ( false === $ok ) {
					$report['_errors'][] = "$table.$col: " . $wpdb->last_error;
					continue;
				}
				$report[ $table . '.' . $col ] = ( $report[ $table . '.' . $col ] ?? 0 ) + 1;
			}
		}
	}
	return $report;
}

/**
 * Restore files from a backup zip into wp-content/uploads (overwrites
 * existing files; never deletes extras).
 */
function rcmi_backup_restore_files( $zip_path ) {
	$uploads = wp_upload_dir( null, false );
	$base    = trailingslashit( $uploads['basedir'] );

	$zip = new ZipArchive();
	if ( true !== $zip->open( $zip_path ) ) {
		return new WP_Error( 'rcmi_backup_zip_open', 'Cannot open the archive.' );
	}
	$prefix  = 'files/uploads/';
	$restored = 0;
	for ( $i = 0; $i < $zip->numFiles; $i++ ) {
		$name = $zip->getNameIndex( $i );
		if ( 0 !== strpos( $name, $prefix ) || '/' === substr( $name, -1 ) ) {
			continue;
		}
		$rel = substr( $name, strlen( $prefix ) );
		// Guard against traversal in hand-crafted zips.
		if ( false !== strpos( $rel, '..' ) ) {
			continue;
		}
		$dest = $base . $rel;
		if ( ! is_dir( dirname( $dest ) ) ) {
			wp_mkdir_p( dirname( $dest ) );
		}
		$stream = $zip->getStream( $name );
		if ( ! $stream ) {
			continue;
		}
		$out = fopen( $dest, 'wb' );
		if ( ! $out ) {
			fclose( $stream );
			continue;
		}
		stream_copy_to_stream( $stream, $out );
		fclose( $stream );
		fclose( $out );
		$restored++;
	}
	$zip->close();
	return $restored;
}

function rcmi_backup_maintenance( $on ) {
	$file = ABSPATH . '.maintenance';
	if ( $on ) {
		file_put_contents( $file, '<?php $upgrading = ' . time() . ';' );
	} elseif ( file_exists( $file ) ) {
		unlink( $file );
	}
}

/**
 * Full restore pipeline for a backup zip at $path.
 *
 * $opts:
 *   'snapshot'       bool   Create a pre-restore DB snapshot (default true).
 *   'files'          bool   Legacy flag — superseded by 'files_mode'.
 *   'files_mode'     string 'restore' | 'absolute' | 'none'. 'absolute' keeps
 *                           file URLs pointing at the source site's uploads
 *                           instead of copying files (clone only).
 *   'rewrite_urls'   bool   Clone: rewrite the backup's site_url to this
 *                           site's home_url() across all tables.
 *   'preserve_users' bool   Clone: leave wp_users/wp_usermeta untouched so
 *                           this site's accounts and roles survive.
 */
function rcmi_backup_restore( $path, $opts = array() ) {
	global $wpdb;
	$opts = array_merge( array(
		'snapshot'       => true,
		'files'          => true,
		'files_mode'     => null,
		'rewrite_urls'   => false,
		'preserve_users' => false,
	), $opts );
	$m = rcmi_backup_validate( $path );
	if ( is_wp_error( $m ) ) {
		return $m;
	}
	$files_mode = null !== $opts['files_mode']
		? $opts['files_mode']
		: ( $opts['files'] ? 'restore' : 'none' );

	@set_time_limit( 0 );
	@ini_set( 'memory_limit', '512M' );

	// Extract the SQL to a temp file.
	$zip = new ZipArchive();
	$zip->open( $path );
	$tmp_sql = wp_tempnam( 'rcmi-restore.sql' );
	file_put_contents( $tmp_sql, $zip->getFromName( 'database.sql' ) );
	$zip->close();

	// Safety snapshot of the current DB before overwriting.
	$snapshot = null;
	if ( $opts['snapshot'] ) {
		$snapshot = rcmi_backup_create( 'db', 'pre-restore' );
		if ( is_wp_error( $snapshot ) ) {
			@unlink( $tmp_sql );
			return new WP_Error( 'rcmi_backup_snapshot', 'Could not create the pre-restore snapshot: ' . $snapshot->get_error_message() );
		}
	}

	rcmi_backup_maintenance( true );
	$result = array( 'snapshot' => $snapshot ? basename( $snapshot ) : null );

	// Preserved tables are skipped at statement level — DROP, CREATE and
	// INSERT for them never run, so existing rows stay exactly as they are.
	$skip_tables = $opts['preserve_users'] ? array( $wpdb->users, $wpdb->usermeta ) : array();

	$imported = rcmi_backup_import_sql( $tmp_sql, $skip_tables );
	@unlink( $tmp_sql );
	if ( is_wp_error( $imported ) ) {
		rcmi_backup_maintenance( false );
		$result['error'] = $imported->get_error_message();
		$result['ok']    = false;
		return (object) $result;
	}
	$result['statements']         = $imported['statements'];
	$result['skipped_statements'] = $imported['skipped'];
	if ( $skip_tables ) {
		$result['skipped_tables'] = $skip_tables;
	}

	// A cross-site restore ("clone") — the backup's site_url differs from
	// this install's. Rewrite URLs so this WordPress keeps its own address.
	$is_clone = ! empty( $m['site_url'] )
		&& untrailingslashit( $m['site_url'] ) !== untrailingslashit( home_url() );

	if ( $is_clone && $opts['rewrite_urls'] ) {
		$result['rewritten'] = rcmi_backup_rewrite_urls(
			rcmi_backup_url_pairs( $m['site_url'], home_url() ),
			(string) wp_parse_url( $m['site_url'], PHP_URL_HOST ),
			$skip_tables
		);
	}

	if ( 'restore' === $files_mode && 'full' === ( $m['type'] ?? '' ) ) {
		$result['files'] = rcmi_backup_restore_files( $path );
		if ( is_wp_error( $result['files'] ) ) {
			$result['files'] = 0;
		}
	} elseif ( 'absolute' === $files_mode && $is_clone ) {
		// After the global rewrite, uploads URLs point at this site — flip
		// just the uploads prefix back to the source so files are served
		// from the origin site instead of being copied.
		$src_uploads = untrailingslashit( $m['site_url'] ) . '/wp-content/uploads';
		$tgt_uploads = wp_upload_dir( null, false )['baseurl'];
		$result['uploads_linked'] = rcmi_backup_rewrite_urls(
			rcmi_backup_url_pairs( $tgt_uploads, $src_uploads ),
			(string) wp_parse_url( $tgt_uploads, PHP_URL_HOST ),
			$skip_tables
		);
	}
	$result['clone'] = $is_clone;

	wp_cache_flush();
	rcmi_backup_maintenance( false );
	$result['ok'] = true;

	if ( $is_clone ) {
		/**
		 * Fires after a clone restore completes — the place for
		 * environment adjustments (deactivate SSO plugins, local admin
		 * users, dev-only settings).
		 *
		 * @param array $result Restore report: statements, rewritten rows
		 *                      per table.column, skipped_tables, files.
		 * @param array $m      The backup's manifest (site_url, versions…).
		 */
		do_action( 'rcmi_backup_post_clone', $result, $m );
	}
	return (object) $result;
}
