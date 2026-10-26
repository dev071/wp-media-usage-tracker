<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WMUT_Admin {

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'wp_ajax_wmut_rescan', array( __CLASS__, 'ajax_rescan' ) );
		add_action( 'admin_post_wmut_export_csv', array( __CLASS__, 'handle_csv_export' ) );
	}

	public static function capability() {
		return apply_filters( 'wmut_capability', 'upload_files' );
	}

	public static function add_menu() {
		add_media_page(
			__( 'Media Usage Tracker', 'wp-media-usage-tracker' ),
			__( 'Media Usage', 'wp-media-usage-tracker' ),
			self::capability(),
			'wmut-usage',
			array( __CLASS__, 'render_page' )
		);
	}

	public static function action_links( $links ) {
		$url = admin_url( 'upload.php?page=wmut-usage' );
		array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Scan Media Usage', 'wp-media-usage-tracker' ) . '</a>' );
		return $links;
	}

	public static function enqueue_assets( $hook_suffix ) {
		if ( 'media_page_wmut-usage' !== $hook_suffix && 'upload_page_wmut-usage' !== $hook_suffix ) {
			return;
		}

		wp_enqueue_style( 'wmut-admin', WMUT_URL . 'assets/admin.css', array(), WMUT_VERSION );
		wp_enqueue_script( 'wmut-admin', WMUT_URL . 'assets/admin.js', array( 'jquery' ), WMUT_VERSION, true );

		$scan_data = WMUT_Scanner::get_data();

		// Collect unique post types & statuses for filters
		$available_types = array();
		$available_stati = array();

		if ( ! empty( $scan_data['items'] ) ) {
			foreach ( $scan_data['items'] as $item ) {
				foreach ( $item['usages'] as $u ) {
					$available_types[ $u['post_type'] ] = $u['type_label'];
					$available_stati[ $u['post_status'] ] = $u['status_label'];
				}
			}
		}

		wp_localize_script(
			'wmut-admin',
			'wmutData',
			array(
				'ajaxUrl'        => admin_url( 'admin-ajax.php' ),
				'nonce'          => wp_create_nonce( 'wmut_admin_nonce' ),
				'exportUrl'      => admin_url( 'admin-post.php?action=wmut_export_csv&_wpnonce=' . wp_create_nonce( 'wmut_export_nonce' ) ),
				'scanData'       => $scan_data,
				'availableTypes' => $available_types,
				'availableStati' => $available_stati,
				'i18n'           => array(
					'scanning'     => __( 'Scanning Media Library...', 'wp-media-usage-tracker' ),
					'scanSuccess'  => __( 'Media Library scanned successfully!', 'wp-media-usage-tracker' ),
					'scanError'    => __( 'Error scanning Media Library. Please try again.', 'wp-media-usage-tracker' ),
					'unused'       => __( 'Unused (Orphan)', 'wp-media-usage-tracker' ),
					'usedIn'       => __( 'Used in %d place(s)', 'wp-media-usage-tracker' ),
					'noMatch'      => __( 'No media items found matching your filters.', 'wp-media-usage-tracker' ),
					'edit'         => __( 'Edit', 'wp-media-usage-tracker' ),
					'view'         => __( 'View', 'wp-media-usage-tracker' ),
					'showing'      => __( 'Showing %1$d - %2$d of %3$d media items', 'wp-media-usage-tracker' ),
				),
			)
		);
	}

	public static function ajax_rescan() {
		check_ajax_referer( 'wmut_admin_nonce', 'nonce' );

		if ( ! current_user_can( self::capability() ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'wp-media-usage-tracker' ) ) );
		}

		$data = WMUT_Scanner::get_data( true );

		$available_types = array();
		$available_stati = array();
		if ( ! empty( $data['items'] ) ) {
			foreach ( $data['items'] as $item ) {
				foreach ( $item['usages'] as $u ) {
					$available_types[ $u['post_type'] ] = $u['type_label'];
					$available_stati[ $u['post_status'] ] = $u['status_label'];
				}
			}
		}

		wp_send_json_success(
			array(
				'scanData'       => $data,
				'availableTypes' => $available_types,
				'availableStati' => $available_stati,
				'message'        => __( 'Scan completed successfully!', 'wp-media-usage-tracker' ),
			)
		);
	}

	public static function handle_csv_export() {
		if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'wmut_export_nonce' ) ) {
			wp_die( esc_html__( 'Security check failed.', 'wp-media-usage-tracker' ) );
		}

		if ( ! current_user_can( self::capability() ) ) {
			wp_die( esc_html__( 'Permission denied.', 'wp-media-usage-tracker' ) );
		}

		$data = WMUT_Scanner::get_data();

		$filename = 'media-usage-report-' . gmdate( 'Y-m-d-His' ) . '.csv';

		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=' . $filename );
		header( 'Pragma: no-cache' );
		header( 'Expires: 0' );

		$output = fopen( 'php://output', 'w' );

		// UTF-8 BOM for Excel
		fputs( $output, "\xEF\xBB\xBF" );

		// Headers
		fputcsv(
			$output,
			array(
				'Attachment ID',
				'Title',
				'Filename',
				'MIME Type',
				'Type Group',
				'File Size (Bytes)',
				'File Size Formatted',
				'Dimensions',
				'Upload Date',
				'Usage Status',
				'Usage Count',
				'Distinct Posts Count',
				'Used In Locations',
				'Media URL',
				'Edit URL',
			)
		);

		if ( ! empty( $data['items'] ) ) {
			foreach ( $data['items'] as $item ) {
				$usages_str = array();
				foreach ( $item['usages'] as $u ) {
					$usages_str[] = sprintf(
						'[%s: %s (%s) - %s]',
						$u['type_label'],
						$u['title'],
						$u['status_label'],
						implode( ', ', $u['contexts'] )
					);
				}

				fputcsv(
					$output,
					array(
						$item['id'],
						$item['title'],
						$item['filename'],
						$item['mime_type'],
						ucfirst( $item['type_group'] ),
						$item['filesize'],
						$item['filesize_formatted'],
						str_replace( '&times;', 'x', $item['dimensions'] ),
						$item['upload_date'],
						( $item['usage_count'] > 0 ) ? 'Used' : 'Unused',
						$item['usage_count'],
						$item['distinct_posts'],
						implode( ' | ', $usages_str ),
						$item['full_url'],
						$item['edit_url'],
					)
				);
			}
		}

		fclose( $output );
		exit;
	}

	public static function render_page() {
		if ( ! current_user_can( self::capability() ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'wp-media-usage-tracker' ) );
		}

		$data = WMUT_Scanner::get_data();
		?>
		<div class="wrap wmut-wrap">
			<!-- Header -->
			<div class="wmut-header">
				<div class="wmut-header-left">
					<div class="wmut-title-badge">
						<span class="dashicons dashicons-admin-media"></span>
						<h1><?php esc_html_e( 'Media Usage Tracker', 'wp-media-usage-tracker' ); ?></h1>
					</div>
					<p class="wmut-subtitle">
						<?php esc_html_e( 'Track where your media files are used across Posts, Pages, and CPTs (Published, Draft, Trashed & more) and identify unused orphan files.', 'wp-media-usage-tracker' ); ?>
					</p>
				</div>
				<div class="wmut-header-right">
					<div class="wmut-last-scan">
						<span class="wmut-pulse-dot"></span>
						<span id="wmut-last-scan-text">
							<?php
							printf(
								/* translators: %s: formatted date */
								esc_html__( 'Last Scanned: %s', 'wp-media-usage-tracker' ),
								'<strong id="wmut-scanned-date">' . esc_html( $data['scanned_formatted'] ) . '</strong>'
							);
							?>
						</span>
					</div>
					<button type="button" id="wmut-btn-rescan" class="button button-primary wmut-btn-action">
						<span class="dashicons dashicons-update"></span>
						<span class="btn-text"><?php esc_html_e( 'Re-Scan Now', 'wp-media-usage-tracker' ); ?></span>
					</button>
					<a id="wmut-btn-export" href="<?php echo esc_url( admin_url( 'admin-post.php?action=wmut_export_csv&_wpnonce=' . wp_create_nonce( 'wmut_export_nonce' ) ) ); ?>" class="button button-secondary wmut-btn-action">
						<span class="dashicons dashicons-download"></span>
						<span class="btn-text"><?php esc_html_e( 'Export CSV', 'wp-media-usage-tracker' ); ?></span>
					</a>
				</div>
			</div>

			<!-- Status Toast / Alert -->
			<div id="wmut-alert" class="wmut-alert" style="display: none;"></div>

			<!-- KPI Summary Cards -->
			<div class="wmut-kpi-grid">
				<!-- Total Media -->
				<div class="wmut-kpi-card wmut-kpi-total">
					<div class="wmut-kpi-icon">
						<span class="dashicons dashicons-images-alt2"></span>
					</div>
					<div class="wmut-kpi-content">
						<span class="wmut-kpi-label"><?php esc_html_e( 'Total Media Files', 'wp-media-usage-tracker' ); ?></span>
						<div class="wmut-kpi-value" id="kpi-total-media"><?php echo esc_html( number_format_i18n( $data['total_media'] ) ); ?></div>
						<div class="wmut-kpi-meta" id="kpi-total-size"><?php echo esc_html( $data['total_size_form'] ); ?> <?php esc_html_e( 'total disk size', 'wp-media-usage-tracker' ); ?></div>
					</div>
				</div>

				<!-- Used Media -->
				<div class="wmut-kpi-card wmut-kpi-used">
					<div class="wmut-kpi-icon">
						<span class="dashicons dashicons-yes-alt"></span>
					</div>
					<div class="wmut-kpi-content">
						<span class="wmut-kpi-label"><?php esc_html_e( 'Used Media Files', 'wp-media-usage-tracker' ); ?></span>
						<div class="wmut-kpi-value" id="kpi-used-media"><?php echo esc_html( number_format_i18n( $data['used_media'] ) ); ?></div>
						<div class="wmut-kpi-meta" id="kpi-used-pct">
							<?php
							$used_pct = $data['total_media'] > 0 ? round( ( $data['used_media'] / $data['total_media'] ) * 100, 1 ) : 0;
							printf( esc_html__( '%s%% of media library', 'wp-media-usage-tracker' ), esc_html( $used_pct ) );
							?>
						</div>
					</div>
				</div>

				<!-- Unused Media -->
				<div class="wmut-kpi-card wmut-kpi-unused">
					<div class="wmut-kpi-icon">
						<span class="dashicons dashicons-trash"></span>
					</div>
					<div class="wmut-kpi-content">
						<span class="wmut-kpi-label"><?php esc_html_e( 'Unused (Orphan) Files', 'wp-media-usage-tracker' ); ?></span>
						<div class="wmut-kpi-value" id="kpi-unused-media"><?php echo esc_html( number_format_i18n( $data['unused_media'] ) ); ?></div>
						<div class="wmut-kpi-meta" id="kpi-unused-meta">
							<?php
							$unused_pct = $data['total_media'] > 0 ? round( ( $data['unused_media'] / $data['total_media'] ) * 100, 1 ) : 0;
							printf(
								esc_html__( '%1$s%% &bull; %2$s space wasted', 'wp-media-usage-tracker' ),
								esc_html( $unused_pct ),
								esc_html( $data['unused_size_form'] )
							);
							?>
						</div>
					</div>
				</div>

				<!-- Total Usages -->
				<div class="wmut-kpi-card wmut-kpi-usages">
					<div class="wmut-kpi-icon">
						<span class="dashicons dashicons-networking"></span>
					</div>
					<div class="wmut-kpi-content">
						<span class="wmut-kpi-label"><?php esc_html_e( 'Total Usage Instances', 'wp-media-usage-tracker' ); ?></span>
						<div class="wmut-kpi-value" id="kpi-total-usages"><?php echo esc_html( number_format_i18n( $data['total_usages'] ) ); ?></div>
						<div class="wmut-kpi-meta">
							<?php esc_html_e( 'Across Posts, Pages, & CPTs', 'wp-media-usage-tracker' ); ?>
						</div>
					</div>
				</div>
			</div>

			<!-- Filter Bar -->
			<div class="wmut-toolbar-card">
				<!-- Segmented Status Tabs -->
				<div class="wmut-tabs" role="tablist">
					<button type="button" class="wmut-tab active" data-tab="all" role="tab">
						<?php esc_html_e( 'All Media', 'wp-media-usage-tracker' ); ?>
						<span class="wmut-tab-badge" id="tab-count-all"><?php echo esc_html( $data['total_media'] ); ?></span>
					</button>
					<button type="button" class="wmut-tab" data-tab="used" role="tab">
						<?php esc_html_e( 'Used Only', 'wp-media-usage-tracker' ); ?>
						<span class="wmut-tab-badge wmut-badge-green" id="tab-count-used"><?php echo esc_html( $data['used_media'] ); ?></span>
					</button>
					<button type="button" class="wmut-tab" data-tab="unused" role="tab">
						<?php esc_html_e( 'Unused (Orphan)', 'wp-media-usage-tracker' ); ?>
						<span class="wmut-tab-badge wmut-badge-red" id="tab-count-unused"><?php echo esc_html( $data['unused_media'] ); ?></span>
					</button>
				</div>

				<!-- Filters and Search Form -->
				<div class="wmut-filters-row">
					<!-- Search Input -->
					<div class="wmut-search-wrap">
						<span class="dashicons dashicons-search"></span>
						<input type="search" id="wmut-search-input" placeholder="<?php esc_attr_e( 'Search by filename, title, or ID...', 'wp-media-usage-tracker' ); ?>" autocomplete="off">
						<button type="button" id="wmut-search-clear" class="wmut-search-clear" style="display:none;" title="<?php esc_attr_e( 'Clear search', 'wp-media-usage-tracker' ); ?>">&times;</button>
					</div>

					<!-- Media Type Filter -->
					<div class="wmut-filter-item">
						<select id="wmut-filter-mime">
							<option value=""><?php esc_html_e( 'All Media Types', 'wp-media-usage-tracker' ); ?></option>
							<option value="image"><?php esc_html_e( 'Images', 'wp-media-usage-tracker' ); ?></option>
							<option value="document"><?php esc_html_e( 'Documents / PDFs', 'wp-media-usage-tracker' ); ?></option>
							<option value="audio"><?php esc_html_e( 'Audio', 'wp-media-usage-tracker' ); ?></option>
							<option value="video"><?php esc_html_e( 'Video', 'wp-media-usage-tracker' ); ?></option>
							<option value="other"><?php esc_html_e( 'Other Files', 'wp-media-usage-tracker' ); ?></option>
						</select>
					</div>

					<!-- Post Type Filter -->
					<div class="wmut-filter-item">
						<select id="wmut-filter-post-type">
							<option value=""><?php esc_html_e( 'All Content Types', 'wp-media-usage-tracker' ); ?></option>
						</select>
					</div>

					<!-- Post Status Filter -->
					<div class="wmut-filter-item">
						<select id="wmut-filter-post-status">
							<option value=""><?php esc_html_e( 'All Post Statuses', 'wp-media-usage-tracker' ); ?></option>
						</select>
					</div>

					<!-- Sort Select -->
					<div class="wmut-filter-item">
						<select id="wmut-sort-select">
							<option value="usages-desc"><?php esc_html_e( 'Usage: Most Used First', 'wp-media-usage-tracker' ); ?></option>
							<option value="usages-asc"><?php esc_html_e( 'Usage: Least Used First', 'wp-media-usage-tracker' ); ?></option>
							<option value="size-desc"><?php esc_html_e( 'File Size: Largest First', 'wp-media-usage-tracker' ); ?></option>
							<option value="size-asc"><?php esc_html_e( 'File Size: Smallest First', 'wp-media-usage-tracker' ); ?></option>
							<option value="date-desc"><?php esc_html_e( 'Date: Newest First', 'wp-media-usage-tracker' ); ?></option>
							<option value="date-asc"><?php esc_html_e( 'Date: Oldest First', 'wp-media-usage-tracker' ); ?></option>
							<option value="title-asc"><?php esc_html_e( 'Title: A to Z', 'wp-media-usage-tracker' ); ?></option>
						</select>
					</div>

					<!-- Reset Filters -->
					<button type="button" id="wmut-btn-reset-filters" class="button button-link wmut-btn-reset" title="<?php esc_attr_e( 'Reset all filters', 'wp-media-usage-tracker' ); ?>">
						<?php esc_html_e( 'Reset Filters', 'wp-media-usage-tracker' ); ?>
					</button>
				</div>
			</div>

			<!-- Main Table Card -->
			<div class="wmut-card wmut-table-card">
				<!-- Table Top Bar -->
				<div class="wmut-table-topbar">
					<div class="wmut-count-info" id="wmut-count-info">
						<?php printf( esc_html__( 'Showing %d media items', 'wp-media-usage-tracker' ), count( $data['items'] ) ); ?>
					</div>
					<div class="wmut-per-page-wrap">
						<label for="wmut-per-page"><?php esc_html_e( 'Per page:', 'wp-media-usage-tracker' ); ?></label>
						<select id="wmut-per-page">
							<option value="20">20</option>
							<option value="50" selected>50</option>
							<option value="100">100</option>
							<option value="999999"><?php esc_html_e( 'All', 'wp-media-usage-tracker' ); ?></option>
						</select>
					</div>
				</div>

				<div class="wmut-table-responsive">
					<table class="wmut-table" id="wmut-media-table">
						<thead>
							<tr>
								<th class="col-thumb"><?php esc_html_e( 'Preview', 'wp-media-usage-tracker' ); ?></th>
								<th class="col-file"><?php esc_html_e( 'File Details', 'wp-media-usage-tracker' ); ?></th>
								<th class="col-status"><?php esc_html_e( 'Status', 'wp-media-usage-tracker' ); ?></th>
								<th class="col-usages"><?php esc_html_e( 'Where It Is Used', 'wp-media-usage-tracker' ); ?></th>
								<th class="col-actions"><?php esc_html_e( 'Actions', 'wp-media-usage-tracker' ); ?></th>
							</tr>
						</thead>
						<tbody id="wmut-table-body">
							<!-- Populated via JavaScript -->
						</tbody>
					</table>
				</div>

				<!-- Empty State -->
				<div id="wmut-empty-state" class="wmut-empty-state" style="display: none;">
					<span class="dashicons dashicons-search"></span>
					<h3><?php esc_html_e( 'No matching media items found', 'wp-media-usage-tracker' ); ?></h3>
					<p><?php esc_html_e( 'Try clearing your search query or adjusting your filters.', 'wp-media-usage-tracker' ); ?></p>
				</div>

				<!-- Table Pagination -->
				<div class="wmut-pagination" id="wmut-pagination">
					<!-- Populated via JS -->
				</div>
			</div>
		</div>
		<?php
	}
}
