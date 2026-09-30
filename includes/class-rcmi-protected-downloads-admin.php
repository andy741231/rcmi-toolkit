<?php
/**
 * RCMI Protected Downloads — admin UI (RCMI → Protected Downloads).
 *
 * One page with: readiness warnings (storage root + mail acknowledgment),
 * the settings form, dataset management (upload / register / edit /
 * enable-disable / revoke-all), a paginated request log with per-request
 * revoke, and a streamed CSV export. Files themselves are never deleted
 * from the admin UI — v1 is intentionally non-destructive for files.
 *
 * Every mutation requires manage_options + a nonce; destructive-ish
 * actions (revoke-all) also need an explicit confirmation checkbox.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'RCMI_Protected_Downloads_Admin' ) ) {

	class RCMI_Protected_Downloads_Admin {

		const PAGE_SLUG = 'rcmi-downloads';
		const PER_PAGE  = 50;

		public static function init() {
			add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
			add_action( 'admin_post_rcmi_pd_settings', array( __CLASS__, 'handle_settings' ) );
			add_action( 'admin_post_rcmi_pd_add_upload', array( __CLASS__, 'handle_upload' ) );
			add_action( 'admin_post_rcmi_pd_add_register', array( __CLASS__, 'handle_register' ) );
			add_action( 'admin_post_rcmi_pd_edit', array( __CLASS__, 'handle_edit' ) );
			add_action( 'admin_post_rcmi_pd_toggle', array( __CLASS__, 'handle_toggle' ) );
			add_action( 'admin_post_rcmi_pd_revoke_dataset', array( __CLASS__, 'handle_revoke_dataset' ) );
			add_action( 'admin_post_rcmi_pd_revoke_request', array( __CLASS__, 'handle_revoke_request' ) );
			add_action( 'admin_post_rcmi_pd_export', array( __CLASS__, 'handle_export' ) );
			add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'register_exporter' ) );
			add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'register_eraser' ) );
			add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		}

		/**
		 * Route-local stylesheet — loaded only on our admin screen.
		 */
		public static function enqueue( $hook ) {
			if ( ! isset( $_GET['page'] ) || self::PAGE_SLUG !== $_GET['page'] ) {
				return;
			}
			$path = RCMI_TOOLKIT_PATH . 'assets/css/protected-downloads-admin.css';
			wp_enqueue_style(
				'rcmi-pd-admin',
				RCMI_TOOLKIT_URL . 'assets/css/protected-downloads-admin.css',
				array(),
				file_exists( $path ) ? (string) filemtime( $path ) : RCMI_TOOLKIT_VERSION
			);
		}

		public static function menu() {
			add_submenu_page(
				'rcmi-toolkit',
				__( 'Protected Downloads', 'rcmi-toolkit' ),
				__( 'Protected Downloads', 'rcmi-toolkit' ),
				'manage_options',
				self::PAGE_SLUG,
				array( __CLASS__, 'render_page' )
			);
		}

		private static function redirect( $args = array() ) {
			wp_safe_redirect( add_query_arg( array_merge( array( 'page' => self::PAGE_SLUG ), $args ), admin_url( 'admin.php' ) ) );
			exit;
		}

		/**
		 * Every mutation is POST-only first — admin-post.php also fires
		 * these hooks for GET, so a nonce in a query string must never
		 * authorize a change.
		 */
		private static function guard( $nonce_action ) {
			$method = strtoupper( isset( $_SERVER['REQUEST_METHOD'] ) ? (string) $_SERVER['REQUEST_METHOD'] : '' );
			if ( 'POST' !== $method || ! current_user_can( 'manage_options' ) || ! check_admin_referer( $nonce_action ) ) {
				wp_die( 'Unauthorized' );
			}
		}

		private static function post_text( $key, $max = 4000 ) {
			$v = isset( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : '';
			if ( ! is_scalar( $v ) ) {
				return '';
			}
			return substr( trim( (string) $v ), 0, $max );
		}

		// ====================================================================
		// Handlers
		// ====================================================================

		public static function handle_settings() {
			self::guard( 'rcmi_pd_settings' );
			$raw   = isset( $_POST['rcmi_pd'] ) && is_array( $_POST['rcmi_pd'] ) ? wp_unslash( $_POST['rcmi_pd'] ) : array();
			$clean = RCMI_Protected_Downloads::update_settings( $raw );
			if ( is_wp_error( $clean ) ) {
				self::redirect( array( 'rcmi_pd_err' => $clean->get_error_message() ) );
			}
			if ( ! empty( $raw['enabled'] ) && empty( $clean['enabled'] ) ) {
				self::redirect( array( 'rcmi_pd_err' => 'Downloads were not enabled: acknowledge mail readiness, set a privacy notice, and configure a valid private storage root first.' ) );
			}
			self::redirect( array( 'rcmi_pd_msg' => 'Settings saved.' ) );
		}

		/**
		 * Upload a new dataset file directly into the private root. Never
		 * enters the Media Library and never gets a public URL.
		 */
		public static function handle_upload() {
			self::guard( 'rcmi_pd_add' );
			$root = RCMI_Protected_Downloads::storage_root();
			if ( false === $root ) {
				self::redirect( array( 'rcmi_pd_err' => 'Private storage root is not configured. Set RCMI_PROTECTED_DOWNLOADS_DIR in wp-config.php first.' ) );
			}
			$f = isset( $_FILES['dataset_file'] ) && is_array( $_FILES['dataset_file'] ) ? $_FILES['dataset_file'] : null;
			if ( ! $f || ! isset( $f['error'] ) || UPLOAD_ERR_NO_FILE === $f['error'] ) {
				self::redirect( array( 'rcmi_pd_err' => 'No file was uploaded.' ) );
			}
			if ( UPLOAD_ERR_OK !== $f['error'] ) {
				$limits = 'upload_max_filesize=' . ini_get( 'upload_max_filesize' ) . ', post_max_size=' . ini_get( 'post_max_size' );
				$msgs   = array(
					UPLOAD_ERR_INI_SIZE   => 'The file exceeds the server upload limit (' . $limits . '). Use the "register existing file" form for large files delivered via SFTP.',
					UPLOAD_ERR_FORM_SIZE  => 'The file exceeds the form upload limit.',
					UPLOAD_ERR_PARTIAL    => 'The upload was incomplete — try again.',
					UPLOAD_ERR_NO_TMP_DIR => 'Server misconfiguration: no temporary upload directory.',
					UPLOAD_ERR_CANT_WRITE => 'Server could not write the uploaded file to disk.',
					UPLOAD_ERR_EXTENSION  => 'A server extension blocked the upload.',
				);
				$msg = isset( $msgs[ $f['error'] ] ) ? $msgs[ $f['error'] ] : 'Upload failed (error ' . (int) $f['error'] . ').';
				self::redirect( array( 'rcmi_pd_err' => $msg ) );
			}
			$orig = basename( (string) $f['name'] );
			$ext  = strtolower( pathinfo( $orig, PATHINFO_EXTENSION ) );
			if ( ! in_array( $ext, RCMI_Protected_Downloads::allowed_extensions(), true ) ) {
				self::redirect( array( 'rcmi_pd_err' => 'File type not allowed. Permitted: .' . implode( ', .', RCMI_Protected_Downloads::allowed_extensions() ) ) );
			}
			$title = self::post_text( 'title', 200 );
			if ( '' === $title ) {
				self::redirect( array( 'rcmi_pd_err' => 'A dataset title is required.' ) );
			}
			if ( empty( $f['tmp_name'] ) || ! is_uploaded_file( $f['tmp_name'] ) ) {
				self::redirect( array( 'rcmi_pd_err' => 'Invalid upload.' ) );
			}
			$stored = RCMI_Protected_Downloads::unique_stored_name( $ext );
			if ( false === $stored ) {
				self::redirect( array( 'rcmi_pd_err' => 'Could not allocate a storage filename.' ) );
			}
			if ( ! move_uploaded_file( $f['tmp_name'], $root . '/' . $stored ) ) {
				self::redirect( array( 'rcmi_pd_err' => 'Could not store the file in the private directory.' ) );
			}
			$id = RCMI_Protected_Downloads::add_dataset(
				array(
					'title'         => $title,
					'description'   => self::post_text( 'description' ),
					'stored_name'   => $stored,
					'download_name' => RCMI_Protected_Downloads::sanitize_download_name( $orig ),
					'file_size'     => (int) filesize( $root . '/' . $stored ),
					'enabled'       => 1,
				)
			);
			if ( is_wp_error( $id ) ) {
				self::redirect( array( 'rcmi_pd_err' => $id->get_error_message() ) );
			}
			self::redirect( array( 'rcmi_pd_msg' => 'Dataset added.' ) );
		}

		/**
		 * Register a file already present in the private root (e.g. a large
		 * file delivered via SFTP). Strict realpath containment; the name
		 * must be a relative filename, never an absolute path.
		 */
		public static function handle_register() {
			self::guard( 'rcmi_pd_add' );
			if ( false === RCMI_Protected_Downloads::storage_root() ) {
				self::redirect( array( 'rcmi_pd_err' => 'Private storage root is not configured. Set RCMI_PROTECTED_DOWNLOADS_DIR in wp-config.php first.' ) );
			}
			$rel  = self::post_text( 'filename', 255 );
			$real = '' !== $rel ? RCMI_Protected_Downloads::resolve_rel( $rel ) : false;
			if ( false === $real ) {
				self::redirect( array( 'rcmi_pd_err' => 'File not found inside the private storage root (relative filename only).' ) );
			}
			$ext = strtolower( pathinfo( $real, PATHINFO_EXTENSION ) );
			if ( ! in_array( $ext, RCMI_Protected_Downloads::allowed_extensions(), true ) ) {
				self::redirect( array( 'rcmi_pd_err' => 'File type not allowed. Permitted: .' . implode( ', .', RCMI_Protected_Downloads::allowed_extensions() ) ) );
			}
			$title = self::post_text( 'title', 200 );
			if ( '' === $title ) {
				self::redirect( array( 'rcmi_pd_err' => 'A dataset title is required.' ) );
			}
			$id = RCMI_Protected_Downloads::add_dataset(
				array(
					'title'         => $title,
					'description'   => self::post_text( 'description' ),
					'stored_name'   => $rel,
					'download_name' => RCMI_Protected_Downloads::sanitize_download_name( basename( $real ) ),
					'file_size'     => (int) filesize( $real ),
					'enabled'       => 1,
				)
			);
			if ( is_wp_error( $id ) ) {
				self::redirect( array( 'rcmi_pd_err' => $id->get_error_message() ) );
			}
			self::redirect( array( 'rcmi_pd_msg' => 'Dataset registered.' ) );
		}

		public static function handle_edit() {
			self::guard( 'rcmi_pd_edit' );
			global $wpdb;
			$id      = isset( $_POST['dataset_id'] ) ? absint( $_POST['dataset_id'] ) : 0;
			$dataset = RCMI_Protected_Downloads::get_dataset( $id );
			if ( ! $dataset ) {
				self::redirect( array( 'rcmi_pd_err' => 'Dataset not found.' ) );
			}
			$title = self::post_text( 'title', 200 );
			if ( '' === $title ) {
				self::redirect( array( 'rcmi_pd_err' => 'A dataset title is required.', 'rcmi_pd_edit' => $id ) );
			}
			$ok = $wpdb->update(
				RCMI_Protected_Downloads::table( 'datasets' ),
				array(
					'title'         => $title,
					'description'   => self::post_text( 'description' ),
					'download_name' => RCMI_Protected_Downloads::sanitize_download_name( self::post_text( 'download_name', 255 ), 'dataset-file' ),
					'enabled'       => ! empty( $_POST['enabled'] ) ? 1 : 0,
					'updated_at'    => gmdate( 'Y-m-d H:i:s' ),
				),
				array( 'id' => $id ),
				array( '%s', '%s', '%s', '%d', '%s' ),
				array( '%d' )
			);
			if ( false === $ok ) {
				self::redirect( array( 'rcmi_pd_err' => 'Dataset update failed — no changes were saved.', 'rcmi_pd_edit' => $id ) );
			}
			self::redirect( array( 'rcmi_pd_msg' => 'Dataset updated.', 'rcmi_pd_edit' => $id ) );
		}

		public static function handle_toggle() {
			self::guard( 'rcmi_pd_toggle' );
			global $wpdb;
			$id      = isset( $_POST['dataset_id'] ) ? absint( $_POST['dataset_id'] ) : 0;
			$dataset = RCMI_Protected_Downloads::get_dataset( $id );
			if ( ! $dataset ) {
				self::redirect( array( 'rcmi_pd_err' => 'Dataset not found.' ) );
			}
			$enabled = $dataset->enabled ? 0 : 1;
			$ok      = $wpdb->update(
				RCMI_Protected_Downloads::table( 'datasets' ),
				array(
					'enabled'    => $enabled,
					'updated_at' => gmdate( 'Y-m-d H:i:s' ),
				),
				array( 'id' => $id ),
				array( '%d', '%s' ),
				array( '%d' )
			);
			if ( false === $ok ) {
				self::redirect( array( 'rcmi_pd_err' => 'Dataset update failed — the dataset was NOT ' . ( $enabled ? 'enabled' : 'disabled' ) . '.' ) );
			}
			self::redirect( array( 'rcmi_pd_msg' => 'Dataset ' . ( $enabled ? 'enabled' : 'disabled' ) . '.' ) );
		}

		/**
		 * Revoke every pending token and active grant for one dataset.
		 * Requires the explicit confirmation checkbox.
		 */
		public static function handle_revoke_dataset() {
			self::guard( 'rcmi_pd_revoke_dataset' );
			if ( empty( $_POST['confirm_revoke'] ) ) {
				self::redirect( array( 'rcmi_pd_err' => 'Tick the confirmation box to revoke all links for this dataset.' ) );
			}
			global $wpdb;
			$id = isset( $_POST['dataset_id'] ) ? absint( $_POST['dataset_id'] ) : 0;
			if ( ! RCMI_Protected_Downloads::get_dataset( $id ) ) {
				self::redirect( array( 'rcmi_pd_err' => 'Dataset not found — nothing was revoked.' ) );
			}
			$req   = RCMI_Protected_Downloads::table( 'requests' );
			$quiet = $wpdb->suppress_errors( true );
			$n     = $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$req} SET revoked_at = %s WHERE dataset_id = %d AND revoked_at IS NULL",
					gmdate( 'Y-m-d H:i:s' ),
					$id
				)
			);
			$wpdb->suppress_errors( $quiet );
			if ( false === $n ) {
				self::redirect( array( 'rcmi_pd_err' => 'Revoke failed — requests for this dataset were NOT revoked. Try again.' ) );
			}
			self::redirect( array( 'rcmi_pd_msg' => 'Revoked ' . (int) $n . ' request(s) for this dataset.' ) );
		}

		public static function handle_revoke_request() {
			self::guard( 'rcmi_pd_revoke_request' );
			global $wpdb;
			$id    = isset( $_POST['request_id'] ) ? absint( $_POST['request_id'] ) : 0;
			$req   = RCMI_Protected_Downloads::table( 'requests' );
			$quiet = $wpdb->suppress_errors( true );
			$n     = $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$req} SET revoked_at = %s WHERE id = %d AND revoked_at IS NULL",
					gmdate( 'Y-m-d H:i:s' ),
					$id
				)
			);
			$wpdb->suppress_errors( $quiet );
			$back = isset( $_POST['back_args'] ) ? json_decode( wp_unslash( $_POST['back_args'] ), true ) : array();
			$back = is_array( $back ) ? $back : array();
			if ( false === $n ) {
				self::redirect( array_merge( $back, array( 'rcmi_pd_err' => 'Revoke failed — the request was NOT revoked.' ) ) );
			}
			if ( 0 === (int) $n ) {
				self::redirect( array_merge( $back, array( 'rcmi_pd_err' => 'Request not found or already revoked — nothing changed.' ) ) );
			}
			self::redirect( array_merge( $back, array( 'rcmi_pd_msg' => 'Request revoked.' ) ) );
		}

		// ====================================================================
		// CSV export — POST only, streamed in batches, formula-safe, and
		// never contains hashes, tokens, IPs, or filesystem paths.
		// ====================================================================

		/**
		 * Prefix a cell that spreadsheet apps would treat as a formula:
		 * values starting with = + - or @ (optionally after leading
		 * whitespace/control chars) get a leading apostrophe.
		 */
		private static function csv_safe( $v ) {
			$v = (string) $v;
			if ( preg_match( '/^[\s\x00-\x1f]*[=+\-@]/', $v ) ) {
				return "'" . $v;
			}
			return $v;
		}

		public static function handle_export() {
			self::guard( 'rcmi_pd_export' );
			global $wpdb;
			$req      = RCMI_Protected_Downloads::table( 'requests' );
			$datasets = RCMI_Protected_Downloads::table( 'datasets' );
			$batch    = 500;
			$offset   = 0;

			// Fetch the first batch BEFORE headers: a DB failure here must
			// refuse with a generic error rather than emit a header row that
			// looks like a successful (but empty) export.
			$rows = self::export_batch( $req, $datasets, $batch, $offset );
			if ( false === $rows ) {
				wp_die( 'The export could not be generated — a database error occurred. Try again later.', 'Export failed', array( 'response' => 500 ) );
			}

			header( 'Content-Type: text/csv; charset=utf-8' );
			header( 'Content-Disposition: attachment; filename="rcmi-download-requests-' . gmdate( 'Y-m-d' ) . '.csv"' );
			header( 'Cache-Control: private, no-store' );
			header( 'X-Content-Type-Options: nosniff' );
			$out = fopen( 'php://output', 'wb' );
			fputcsv(
				$out,
				array(
					'request_id', 'dataset', 'name', 'email', 'job_title',
					'requested_at_utc', 'mail_status', 'redeemed_at_utc',
					'revoked_at_utc', 'download_initiations', 'first_download_at_utc', 'last_download_at_utc',
				),
				',',
				'"',
				''
			);
			while ( ! empty( $rows ) ) {
				foreach ( $rows as $row ) {
					fputcsv( $out, array_map( array( __CLASS__, 'csv_safe' ), $row ), ',', '"', '' );
				}
				$offset += count( $rows );
				if ( count( $rows ) < $batch ) {
					break;
				}
				fflush( $out );
				$rows = self::export_batch( $req, $datasets, $batch, $offset );
				if ( false === $rows ) {
					// A mid-stream failure must not leave a file that looks
					// complete — end with an explicit, formula-safe marker row.
					fputcsv( $out, array( 'ERROR: export incomplete; retry later' ), ',', '"', '' );
					break;
				}
			}
			fclose( $out );
			exit;
		}

		/**
		 * One export batch. Returns array of rows, or false on DB failure
		 * (distinguishable from an empty result, which is a valid page).
		 */
		private static function export_batch( $req, $datasets, $batch, $offset ) {
			global $wpdb;
			$quiet = $wpdb->suppress_errors( true );
			$rows  = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT r.id, d.title AS dataset, r.name, r.email, r.job_title,
					        r.requested_at, r.mail_status, r.redeemed_at, r.revoked_at,
					        r.download_count, r.first_download_at, r.last_download_at
					 FROM {$req} r LEFT JOIN {$datasets} d ON d.id = r.dataset_id
					 ORDER BY r.id ASC LIMIT %d OFFSET %d",
					$batch,
					$offset
				),
				ARRAY_N
			);
			// wpdb returns [] on a failed query too — last_error is the
			// only reliable failure signal.
			$err = (string) $wpdb->last_error;
			$wpdb->suppress_errors( $quiet );
			if ( ! is_array( $rows ) || '' !== $err ) {
				return false;
			}
			return $rows;
		}

		// ====================================================================
		// WP privacy exporter / eraser
		// ====================================================================

		public static function register_exporter( $exporters ) {
			$exporters['rcmi-protected-downloads'] = array(
				'exporter_friendly_name' => __( 'RCMI Protected Downloads', 'rcmi-toolkit' ),
				'callback'               => array( __CLASS__, 'privacy_export' ),
			);
			return $exporters;
		}

		public static function register_eraser( $erasers ) {
			$erasers['rcmi-protected-downloads'] = array(
				'eraser_friendly_name' => __( 'RCMI Protected Downloads', 'rcmi-toolkit' ),
				'callback'             => array( __CLASS__, 'privacy_erase' ),
			);
			return $erasers;
		}

		/**
		 * Export the download-request records held for an email address.
		 * Tokens/grants are hashes — they are never exported.
		 */
		public static function privacy_export( $email, $page = 1 ) {
			global $wpdb;
			$req      = RCMI_Protected_Downloads::table( 'requests' );
			$datasets = RCMI_Protected_Downloads::table( 'datasets' );
			$per      = 100;
			// Suppressed — the WHERE clause carries the subject's email and
			// a failed query must not spill it into DB error logs.
			$quiet = $wpdb->suppress_errors( true );
			$rows  = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT r.*, d.title AS dataset_title FROM {$req} r
					 LEFT JOIN {$datasets} d ON d.id = r.dataset_id
					 WHERE r.email = %s ORDER BY r.id ASC LIMIT %d OFFSET %d",
					$email,
					$per,
					( max( 1, (int) $page ) - 1 ) * $per
				)
			);
			// wpdb returns [] on a failed query too — last_error is the
			// only reliable failure signal.
			$err = (string) $wpdb->last_error;
			$wpdb->suppress_errors( $quiet );
			if ( ! is_array( $rows ) || '' !== $err ) {
				// A failed query must NOT surface as "no records".
				return new WP_Error( 'rcmi_pd_export_db', __( 'Protected-download request data could not be exported because of a database error. Try again later.', 'rcmi-toolkit' ) );
			}
			$items = array();
			foreach ( $rows as $r ) {
				$items[] = array(
					'group_id'    => 'rcmi-protected-download-requests',
					'group_label' => __( 'Protected download requests', 'rcmi-toolkit' ),
					'item_id'     => 'rcmi-pd-request-' . (int) $r->id,
					'data'        => array(
						array( 'name' => __( 'Dataset', 'rcmi-toolkit' ), 'value' => (string) $r->dataset_title ),
						array( 'name' => __( 'Name', 'rcmi-toolkit' ), 'value' => (string) $r->name ),
						array( 'name' => __( 'Email', 'rcmi-toolkit' ), 'value' => (string) $r->email ),
						array( 'name' => __( 'Job title', 'rcmi-toolkit' ), 'value' => (string) $r->job_title ),
						array( 'name' => __( 'Requested at (UTC)', 'rcmi-toolkit' ), 'value' => (string) $r->requested_at ),
						array( 'name' => __( 'Download initiations', 'rcmi-toolkit' ), 'value' => (int) $r->download_count ),
					),
				);
			}
			return array(
				'data' => $items,
				'done' => count( $items ) < $per,
			);
		}

		/**
		 * Erase PII + token/grant material for an email. The row and its
		 * aggregate counts stay; files are never touched.
		 */
		public static function privacy_erase( $email, $page = 1 ) {
			global $wpdb;
			$req     = RCMI_Protected_Downloads::table( 'requests' );
			$quiet   = $wpdb->suppress_errors( true );
			$removed = $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$req} SET name = '', email = '', job_title = '', token_hash = NULL, grant_hash = '' WHERE email = %s",
					$email
				)
			);
			$wpdb->suppress_errors( $quiet );
			if ( false === $removed ) {
				// Report honestly: nothing was erased and the rows are
				// retained — never claim a successful wipe on DB failure.
				return array(
					'items_removed'  => false,
					'items_retained' => true,
					'messages'       => array( __( 'Protected-download request data could not be erased because of a database error. The records were NOT erased.', 'rcmi-toolkit' ) ),
					'done'           => true,
				);
			}
			return array(
				'items_removed'  => $removed > 0,
				'items_retained' => false,
				'messages'       => array(),
				'done'           => true,
			);
		}

		// ====================================================================
		// Page
		// ====================================================================

		public static function render_page() {
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( 'Unauthorized' );
			}
			global $wpdb;
			$s        = RCMI_Protected_Downloads::get_settings();
			$root     = RCMI_Protected_Downloads::storage_root();
			$datasets = RCMI_Protected_Downloads::table( 'datasets' );
			$requests = RCMI_Protected_Downloads::table( 'requests' );
			$edit_id  = isset( $_GET['rcmi_pd_edit'] ) ? absint( $_GET['rcmi_pd_edit'] ) : 0;
			$editing  = $edit_id ? RCMI_Protected_Downloads::get_dataset( $edit_id ) : null;
			?>
			<div class="wrap rcmi-pd-admin">
				<h1><?php esc_html_e( 'Protected Downloads', 'rcmi-toolkit' ); ?></h1>
				<p class="description">Private datasets served through emailed one-time links. Files live outside the web root and are never publicly reachable. See <code>PROTECTED-DOWNLOADS.md</code> for deployment notes.</p>

				<?php if ( isset( $_GET['rcmi_pd_msg'] ) ) : ?>
					<div class="notice notice-success"><p><?php echo esc_html( wp_unslash( $_GET['rcmi_pd_msg'] ) ); ?></p></div>
				<?php endif; ?>
				<?php if ( isset( $_GET['rcmi_pd_err'] ) ) : ?>
					<div class="notice notice-error"><p><?php echo esc_html( wp_unslash( $_GET['rcmi_pd_err'] ) ); ?></p></div>
				<?php endif; ?>

				<?php if ( ! defined( 'RCMI_PROTECTED_DOWNLOADS_DIR' ) ) : ?>
					<div class="notice notice-error"><p><strong>Storage root not configured.</strong> Add <code>define( 'RCMI_PROTECTED_DOWNLOADS_DIR', '…' )</code> to <code>wp-config.php</code> pointing at an existing directory <em>outside</em> the web root. There is no uploads fallback — nothing is downloadable until this is set.</p></div>
				<?php elseif ( false === $root ) : ?>
					<div class="notice notice-error"><p><strong>Storage root is invalid.</strong> <code>RCMI_PROTECTED_DOWNLOADS_DIR</code> must resolve to an existing directory outside <code>ABSPATH</code> and <code>DOCUMENT_ROOT</code>. The current value fails that check — the feature stays disabled.</p></div>
				<?php endif; ?>
				<?php if ( empty( $s['mail_ready'] ) ) : ?>
					<div class="notice notice-warning"><p><strong>Mail not verified.</strong> Request links are sent with <code>wp_mail()</code>. Send yourself a test (e.g. WP-CLI <code>wp eval 'var_dump(wp_mail("you@example.org","t","t"));'</code>), then acknowledge below. <code>wp_mail()</code> returning true means the mailer accepted the message — not that it was delivered.</p></div>
				<?php endif; ?>
				<?php if ( empty( $s['enabled'] ) ) : ?>
					<div class="notice notice-info"><p><strong>Downloads are OFF.</strong> Request links and streaming are disabled site-wide until the feature is enabled below.</p></div>
				<?php endif; ?>

				<div class="card rcmi-pd-panel">
					<h2>Settings</h2>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<?php wp_nonce_field( 'rcmi_pd_settings' ); ?>
						<input type="hidden" name="action" value="rcmi_pd_settings" />
						<table class="form-table" role="presentation">
							<tr>
								<th scope="row">Enable downloads</th>
								<td>
									<label><input type="checkbox" name="rcmi_pd[enabled]" value="1" <?php checked( ! empty( $s['enabled'] ) ); ?> /> Serve request forms and download links</label>
									<p class="description">Requires mail acknowledgment, a privacy notice, and a valid private storage root — otherwise this stays off.</p>
								</td>
							</tr>
							<tr>
								<th scope="row">Mail verified</th>
								<td><label><input type="checkbox" name="rcmi_pd[mail_ready]" value="1" <?php checked( ! empty( $s['mail_ready'] ) ); ?> /> I have confirmed <code>wp_mail()</code> can send on this site</label></td>
							</tr>
							<tr>
								<th scope="row"><label for="rcmi_pd_retention">Retention (days)</label></th>
								<td><input type="number" id="rcmi_pd_retention" name="rcmi_pd[retention_days]" value="<?php echo esc_attr( $s['retention_days'] ); ?>" min="1" max="365" /> <span class="description">Request records are pruned after this many days (1–365, default 90).</span></td>
							</tr>
							<tr>
								<th scope="row"><label for="rcmi_pd_notice">Privacy notice</label></th>
								<td>
									<textarea id="rcmi_pd_notice" name="rcmi_pd[privacy_notice]" rows="4" class="large-text" maxlength="4000" required><?php echo esc_textarea( $s['privacy_notice'] ); ?></textarea>
									<p class="description">Shown verbatim on the request form (plain text). Required before downloads can be enabled.</p>
								</td>
							</tr>
						</table>
						<?php submit_button( 'Save settings' ); ?>
					</form>
					<?php if ( false !== $root ) : ?>
						<p class="description">Storage root: <code class="rcmi-pd-path"><?php echo esc_html( $root ); ?></code> — never expose this path publicly.</p>
					<?php endif; ?>
				</div>

				<h2>Datasets</h2>
				<div class="rcmi-pd-scroll">
				<table class="widefat striped rcmi-pd-table rcmi-pd-datasets">
					<thead><tr><th>Title</th><th>File</th><th>Size</th><th>Status</th><th>Request URL</th><th>Actions</th></tr></thead>
					<tbody>
					<?php
					$rows = $wpdb->get_results( "SELECT * FROM {$datasets} ORDER BY id DESC" );
					if ( ! $rows ) :
						?>
						<tr><td colspan="6">No datasets yet.</td></tr>
					<?php else : ?>
						<?php foreach ( $rows as $d ) : ?>
							<tr>
								<td><strong><?php echo esc_html( $d->title ); ?></strong><?php echo '' !== trim( (string) $d->description ) ? '<br><span class="description">' . esc_html( wp_trim_words( $d->description, 20 ) ) . '</span>' : ''; ?></td>
								<td><code><?php echo esc_html( $d->download_name ); ?></code></td>
								<td><?php echo esc_html( size_format( (int) $d->file_size ) ); ?></td>
								<td><?php echo $d->enabled ? '<span style="color:#007a66;font-weight:600;">Enabled</span>' : '<span style="color:#b32d2e;font-weight:600;">Disabled</span>'; ?></td>
								<td><input type="text" readonly class="regular-text rcmi-pd-url" aria-label="<?php esc_attr_e( 'Request URL for', 'rcmi-toolkit' ); ?> <?php echo esc_attr( $d->title ); ?>" value="<?php echo esc_attr( RCMI_Protected_Downloads::url_request( $d->id ) ); ?>" onclick="this.select();" /></td>
								<td>
									<a class="button button-small" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&rcmi_pd_edit=' . (int) $d->id ) ); ?>">Edit</a>
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
										<?php wp_nonce_field( 'rcmi_pd_toggle' ); ?>
										<input type="hidden" name="action" value="rcmi_pd_toggle" />
										<input type="hidden" name="dataset_id" value="<?php echo (int) $d->id; ?>" />
										<button type="submit" class="button button-small"><?php echo $d->enabled ? 'Disable' : 'Enable'; ?></button>
									</form>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
					</tbody>
				</table>
				</div>

				<?php if ( $editing ) : ?>
					<div class="card rcmi-pd-panel">
						<h2>Edit dataset #<?php echo (int) $editing->id; ?></h2>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<?php wp_nonce_field( 'rcmi_pd_edit' ); ?>
							<input type="hidden" name="action" value="rcmi_pd_edit" />
							<input type="hidden" name="dataset_id" value="<?php echo (int) $editing->id; ?>" />
							<table class="form-table" role="presentation">
								<tr><th scope="row"><label for="rcmi_pd_etitle">Title</label></th><td><input type="text" id="rcmi_pd_etitle" name="title" class="regular-text" maxlength="200" value="<?php echo esc_attr( $editing->title ); ?>" required /></td></tr>
								<tr><th scope="row"><label for="rcmi_pd_edesc">Description</label></th><td><textarea id="rcmi_pd_edesc" name="description" rows="3" class="large-text" maxlength="4000"><?php echo esc_textarea( $editing->description ); ?></textarea></td></tr>
								<tr><th scope="row"><label for="rcmi_pd_ename">Download filename</label></th><td><input type="text" id="rcmi_pd_ename" name="download_name" class="regular-text" maxlength="255" value="<?php echo esc_attr( $editing->download_name ); ?>" /><p class="description">The filename visitors receive.</p></td></tr>
								<tr><th scope="row">Enabled</th><td><label><input type="checkbox" name="enabled" value="1" <?php checked( ! empty( $editing->enabled ) ); ?> /> Dataset can be requested and downloaded</label></td></tr>
							</table>
							<?php submit_button( 'Save dataset' ); ?>
							<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) ); ?>">Done</a>
						</form>
						<hr />
						<h3>Revoke all links for this dataset</h3>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<?php wp_nonce_field( 'rcmi_pd_revoke_dataset' ); ?>
							<input type="hidden" name="action" value="rcmi_pd_revoke_dataset" />
							<input type="hidden" name="dataset_id" value="<?php echo (int) $editing->id; ?>" />
							<p><label><input type="checkbox" name="confirm_revoke" value="1" required /> Revoke every pending link and active download grant for this dataset</label></p>
							<?php submit_button( 'Revoke all', 'delete' ); ?>
						</form>
					</div>
				<?php endif; ?>

				<div class="rcmi-pd-admin-grid">
					<div class="card rcmi-pd-panel">
						<h2>Add dataset — upload</h2>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
							<?php wp_nonce_field( 'rcmi_pd_add' ); ?>
							<input type="hidden" name="action" value="rcmi_pd_add_upload" />
							<p><label for="rcmi_pd_up_title">Title</label> <input type="text" id="rcmi_pd_up_title" name="title" class="regular-text" maxlength="200" required /></p>
							<p><label for="rcmi_pd_up_desc">Description</label> <textarea id="rcmi_pd_up_desc" name="description" rows="2" class="large-text" maxlength="4000"></textarea></p>
							<p><label for="rcmi_pd_up_file">Dataset file</label> <input type="file" id="rcmi_pd_up_file" name="dataset_file" accept=".sas7bdat,.zip,.csv,.pdf" required /></p>
							<p class="description">Stored under a random name in the private root — never in the Media Library, never a public URL. PHP limits: upload_max_filesize=<?php echo esc_html( ini_get( 'upload_max_filesize' ) ); ?>, post_max_size=<?php echo esc_html( ini_get( 'post_max_size' ) ); ?>.</p>
							<?php submit_button( 'Upload &amp; add', 'primary', 'submit', false ); ?>
						</form>
					</div>
					<div class="card rcmi-pd-panel">
						<h2>Add dataset — register existing file</h2>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<?php wp_nonce_field( 'rcmi_pd_add' ); ?>
							<input type="hidden" name="action" value="rcmi_pd_add_register" />
							<p><label for="rcmi_pd_reg_title">Title</label> <input type="text" id="rcmi_pd_reg_title" name="title" class="regular-text" maxlength="200" required /></p>
							<p><label for="rcmi_pd_reg_desc">Description</label> <textarea id="rcmi_pd_reg_desc" name="description" rows="2" class="large-text" maxlength="4000"></textarea></p>
							<p><label for="rcmi_pd_reg_file">Filename in private root</label> <input type="text" id="rcmi_pd_reg_file" name="filename" class="regular-text" maxlength="255" placeholder="nhanes-2021.sas7bdat" required /></p>
							<p class="description">For files placed in the storage root directly (SFTP). Relative filename only — no paths outside the root.</p>
							<?php submit_button( 'Register file', 'primary', 'submit', false ); ?>
						</form>
					</div>
				</div>

				<h2 style="margin-top:28px;">Requests</h2>
				<?php self::render_requests_table( $datasets ); ?>
			</div>
			<?php
		}

		/**
		 * Paginated, filtered request log + per-request revoke + CSV export.
		 */
		private static function render_requests_table( $datasets_table ) {
			global $wpdb;
			$requests    = RCMI_Protected_Downloads::table( 'requests' );
			$f_dataset   = isset( $_GET['f_dataset'] ) ? absint( $_GET['f_dataset'] ) : 0;
			$f_status    = isset( $_GET['f_status'] ) ? sanitize_key( wp_unslash( $_GET['f_status'] ) ) : '';
			$paged       = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;

			$where  = array( '1=1' );
			$params = array();
			if ( $f_dataset ) {
				$where[]  = 'r.dataset_id = %d';
				$params[] = $f_dataset;
			}
			if ( 'pending' === $f_status ) {
				$where[] = 'r.redeemed_at IS NULL AND r.revoked_at IS NULL';
			} elseif ( 'redeemed' === $f_status ) {
				$where[] = 'r.redeemed_at IS NOT NULL AND r.revoked_at IS NULL';
			} elseif ( 'revoked' === $f_status ) {
				$where[] = 'r.revoked_at IS NOT NULL';
			} elseif ( in_array( $f_status, array( 'queued', 'sent', 'failed' ), true ) ) {
				$where[]  = 'r.mail_status = %s';
				$params[] = $f_status;
			}
			$where_sql = implode( ' AND ', $where );

			$count_sql = "SELECT COUNT(*) FROM {$requests} r WHERE {$where_sql}";
			$list_sql  = "SELECT r.*, d.title AS dataset_title FROM {$requests} r
				LEFT JOIN {$datasets_table} d ON d.id = r.dataset_id
				WHERE {$where_sql} ORDER BY r.id DESC LIMIT %d OFFSET %d";
			if ( $params ) {
				$total = (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) );
				$rows  = $wpdb->get_results( $wpdb->prepare( $list_sql, array_merge( $params, array( self::PER_PAGE, ( $paged - 1 ) * self::PER_PAGE ) ) ) );
			} else {
				$total = (int) $wpdb->get_var( $count_sql );
				$rows  = $wpdb->get_results( $wpdb->prepare( $list_sql, self::PER_PAGE, ( $paged - 1 ) * self::PER_PAGE ) );
			}
			$pages    = max( 1, (int) ceil( $total / self::PER_PAGE ) );
			$back_args = array_filter(
				array(
					'f_dataset' => $f_dataset ? $f_dataset : null,
					'f_status'  => '' !== $f_status ? $f_status : null,
					'paged'     => $paged > 1 ? $paged : null,
				)
			);
			$all_ds = $wpdb->get_results( "SELECT id, title FROM {$datasets_table} ORDER BY title ASC" );
			?>
			<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="rcmi-pd-filters">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>" />
				<label class="screen-reader-text" for="rcmi_pd_f_dataset"><?php esc_html_e( 'Filter by dataset', 'rcmi-toolkit' ); ?></label>
				<select id="rcmi_pd_f_dataset" name="f_dataset">
					<option value="0">All datasets</option>
					<?php foreach ( (array) $all_ds as $d ) : ?>
						<option value="<?php echo (int) $d->id; ?>" <?php selected( $f_dataset, $d->id ); ?>><?php echo esc_html( $d->title ); ?></option>
					<?php endforeach; ?>
				</select>
				<label class="screen-reader-text" for="rcmi_pd_f_status"><?php esc_html_e( 'Filter by status', 'rcmi-toolkit' ); ?></label>
				<select id="rcmi_pd_f_status" name="f_status">
					<option value="">Any status</option>
					<?php foreach ( array( 'pending' => 'Pending', 'redeemed' => 'Link used', 'revoked' => 'Revoked', 'queued' => 'Mail queued', 'sent' => 'Mail accepted', 'failed' => 'Mail failed' ) as $k => $l ) : ?>
						<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $f_status, $k ); ?>><?php echo esc_html( $l ); ?></option>
					<?php endforeach; ?>
				</select>
				<button class="button">Filter</button>
			</form>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="rcmi-pd-export">
				<?php wp_nonce_field( 'rcmi_pd_export' ); ?>
				<input type="hidden" name="action" value="rcmi_pd_export" />
				<button class="button">Export CSV</button>
			</form>
			<div class="rcmi-pd-scroll">
			<table class="widefat striped rcmi-pd-table rcmi-pd-requests">
				<thead><tr>
					<th>Requested (UTC)</th><th>Dataset</th><th>Name</th><th>Email</th><th>Job title</th>
					<th>Mail</th><th>Redeemed</th><th>Downloads initiated</th><th>Actions</th>
				</tr></thead>
				<tbody>
				<?php if ( ! $rows ) : ?>
					<tr><td colspan="9">No requests found.</td></tr>
				<?php else : ?>
					<?php foreach ( $rows as $r ) : ?>
						<tr>
							<td><?php echo esc_html( $r->requested_at ); ?></td>
							<td><?php echo esc_html( $r->dataset_title ?: '#' . (int) $r->dataset_id ); ?></td>
							<td><?php echo esc_html( $r->name ); ?></td>
							<td><?php echo esc_html( $r->email ); ?></td>
							<td><?php echo esc_html( $r->job_title ); ?></td>
							<td><?php echo esc_html( 'sent' === $r->mail_status ? 'accepted' : $r->mail_status ); ?></td>
							<td><?php echo $r->revoked_at ? 'revoked' : ( $r->redeemed_at ? esc_html( $r->redeemed_at ) : '—' ); ?></td>
							<td><?php echo (int) $r->download_count; ?></td>
							<td>
								<?php if ( empty( $r->revoked_at ) ) : ?>
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
										<?php wp_nonce_field( 'rcmi_pd_revoke_request' ); ?>
										<input type="hidden" name="action" value="rcmi_pd_revoke_request" />
										<input type="hidden" name="request_id" value="<?php echo (int) $r->id; ?>" />
										<input type="hidden" name="back_args" value="<?php echo esc_attr( wp_json_encode( $back_args ) ); ?>" />
										<button class="button button-small">Revoke</button>
									</form>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
				</tbody>
			</table>
			</div>
			<?php if ( $pages > 1 ) : ?>
				<div class="tablenav bottom"><div class="tablenav-pages">
					<?php
					echo wp_kses_post(
						paginate_links(
							array(
								'base'      => add_query_arg( array_merge( array( 'page' => self::PAGE_SLUG ), $back_args, array( 'paged' => '%#%' ) ), admin_url( 'admin.php' ) ),
								'format'    => '',
								'current'   => $paged,
								'total'     => $pages,
								'prev_text' => '&laquo;',
								'next_text' => '&raquo;',
							)
						)
					);
					?>
					<span class="description">(<?php echo (int) $total; ?> total)</span>
				</div></div>
			<?php endif; ?>
			<?php
		}
	}

	RCMI_Protected_Downloads_Admin::init();
}

