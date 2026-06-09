<?php
/**
 * Admin page for Bulk SKU Search & Draft.
 */

defined( 'ABSPATH' ) || exit;

class BSSD_Admin_Page {

	const PAGE_SLUG        = 'bulk-sku-search-draft';
	const NONCE_SEARCH     = 'bssd_search_skus';
	const NONCE_DRAFT      = 'bssd_draft_products';
	const NONCE_SKU        = 'bssd_update_sku';
	const PER_PAGE         = 50;

	/**
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * @return self
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_bssd_draft_batch', array( $this, 'ajax_draft_batch' ) );
		add_action( 'wp_ajax_bssd_update_sku', array( $this, 'ajax_update_sku' ) );
	}

	/**
	 * Register WooCommerce submenu page.
	 */
	public function register_menu() {
		add_submenu_page(
			'woocommerce',
			__( 'Bulk SKU Search', 'bulk-sku-search-draft' ),
			__( 'Bulk SKU Search', 'bulk-sku-search-draft' ),
			'manage_woocommerce',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Enqueue admin assets on plugin page only.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_assets( $hook ) {
		if ( 'woocommerce_page_' . self::PAGE_SLUG !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'bssd-admin',
			BSSD_PLUGIN_URL . 'assets/admin.css',
			array(),
			BSSD_VERSION
		);

		wp_enqueue_script(
			'bssd-admin',
			BSSD_PLUGIN_URL . 'assets/admin.js',
			array( 'jquery' ),
			BSSD_VERSION,
			true
		);

		wp_localize_script(
			'bssd-admin',
			'bssdAdmin',
			array(
				'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
				'nonce'     => wp_create_nonce( self::NONCE_DRAFT ),
				'skuNonce'  => wp_create_nonce( self::NONCE_SKU ),
				'batchSize' => (int) BSSD_BATCH_SIZE,
				'i18n'      => array(
					'confirmDraft' => __( 'This will set %d published product(s) to draft. Continue?', 'bulk-sku-search-draft' ),
					'drafting'     => __( 'Setting products to draft…', 'bulk-sku-search-draft' ),
					'complete'     => __( 'Draft update complete.', 'bulk-sku-search-draft' ),
					'error'        => __( 'An error occurred. Please try again.', 'bulk-sku-search-draft' ),
					'editSku'      => __( 'Edit SKU', 'bulk-sku-search-draft' ),
					'saveSku'      => __( 'Save', 'bulk-sku-search-draft' ),
					'cancelSku'    => __( 'Cancel', 'bulk-sku-search-draft' ),
					'savingSku'    => __( 'Saving…', 'bulk-sku-search-draft' ),
					'skuSaved'     => __( 'SKU saved.', 'bulk-sku-search-draft' ),
					'skuFailed'    => __( 'Could not save SKU.', 'bulk-sku-search-draft' ),
				),
			)
		);
	}

	/**
	 * AJAX handler: process one batch of product IDs.
	 */
	public function ajax_draft_batch() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error(
				array( 'message' => __( 'Permission denied.', 'bulk-sku-search-draft' ) ),
				403
			);
		}

		check_ajax_referer( self::NONCE_DRAFT, 'nonce' );

		$offset = max( 0, (int) ( $_POST['offset'] ?? 0 ) );

		$cached = get_transient( BSSD_TRANSIENT_KEY );
		if ( ! is_array( $cached ) || empty( $cached['draftable_ids'] ) ) {
			wp_send_json_error(
				array( 'message' => __( 'Search results expired. Please run the search again.', 'bulk-sku-search-draft' ) ),
				400
			);
		}

		$all_ids = array_map( 'absint', (array) $cached['draftable_ids'] );
		$batch   = array_slice( $all_ids, $offset, BSSD_BATCH_SIZE );

		if ( empty( $batch ) ) {
			wp_send_json_success(
				array(
					'done'      => true,
					'offset'    => $offset,
					'total'     => count( $all_ids ),
					'succeeded' => 0,
					'failed'    => 0,
					'skipped'   => 0,
					'errors'    => array(),
				)
			);
		}

		$result = BSSD_Draft_Processor::process_batch( $batch );
		$new_offset = $offset + count( $batch );

		wp_send_json_success(
			array(
				'done'      => $new_offset >= count( $all_ids ),
				'offset'    => $new_offset,
				'total'     => count( $all_ids ),
				'succeeded' => (int) $result['succeeded'],
				'failed'    => (int) $result['failed'],
				'skipped'   => (int) $result['skipped'],
				'errors'    => array_slice( $result['errors'], 0, 10 ),
			)
		);
	}

	/**
	 * AJAX handler: update a product SKU inline.
	 */
	public function ajax_update_sku() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error(
				array( 'message' => __( 'Permission denied.', 'bulk-sku-search-draft' ) ),
				403
			);
		}

		check_ajax_referer( self::NONCE_SKU, 'nonce' );

		$product_id = absint( $_POST['product_id'] ?? 0 );
		$new_sku    = isset( $_POST['sku'] ) ? wp_unslash( $_POST['sku'] ) : '';

		$result = BSSD_SKU_Updater::update( $product_id, $new_sku );

		if ( ! $result['success'] ) {
			wp_send_json_error(
				array(
					'message' => $result['message'],
					'sku'     => $result['sku'],
				),
				400
			);
		}

		BSSD_SKU_Updater::update_cached_results( $product_id, $result['sku'] );

		wp_send_json_success(
			array(
				'message' => $result['message'],
				'sku'     => $result['sku'],
			)
		);
	}

	/**
	 * Render the admin page.
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'bulk-sku-search-draft' ) );
		}

		$results = null;
		$error   = null;
		$input   = '';

		if ( isset( $_POST['bssd_search'] ) ) {
			$input  = sanitize_textarea_field( wp_unslash( $_POST['bssd_skus'] ?? '' ) );
			$result = $this->handle_search( $input );

			if ( is_wp_error( $result ) ) {
				$error = $result;
			} else {
				$results = $result;
			}
		} else {
			$cached = get_transient( BSSD_TRANSIENT_KEY );
			if ( is_array( $cached ) ) {
				$results = $cached;
				$input   = $cached['input'] ?? '';
			}
		}

		$this->render_search_form( $input, $results, $error );

		if ( $results ) {
			$this->render_results( $results );
		}
	}

	/**
	 * Process search form submission.
	 *
	 * @param string $input Raw textarea input.
	 * @return array|WP_Error
	 */
	private function handle_search( $input ) {
		check_admin_referer( self::NONCE_SEARCH );

		$parsed = BSSD_SKU_Parser::parse( $input );
		if ( is_wp_error( $parsed ) ) {
			return $parsed;
		}

		$search = BSSD_SKU_Finder::search( $parsed['skus'] );

		$results = array(
			'input'          => $input,
			'skus'           => $parsed['skus'],
			'found'          => $search['found'],
			'not_found'      => $search['not_found'],
			'summary'        => $search['summary'],
			'draftable_ids'  => BSSD_SKU_Finder::get_draftable_ids( $search['found'] ),
			'searched_at'    => time(),
		);

		set_transient( BSSD_TRANSIENT_KEY, $results, HOUR_IN_SECONDS );

		return $results;
	}

	/**
	 * Render search form.
	 *
	 * @param string          $input   Textarea value.
	 * @param array|null      $results Search results.
	 * @param WP_Error|null   $error   Error object.
	 */
	private function render_search_form( $input, $results, $error ) {
		?>
		<div class="wrap bssd-wrap">
			<h1><?php esc_html_e( 'Bulk SKU Search', 'bulk-sku-search-draft' ); ?></h1>
			<p>
				<?php
				printf(
					/* translators: %d: maximum SKU count */
					esc_html__( 'Paste up to %d SKUs (one per line) to find matching WooCommerce products. You can then set published matches to draft.', 'bulk-sku-search-draft' ),
					(int) BSSD_MAX_SKUS
				);
				?>
			</p>

			<?php if ( $error ) : ?>
				<div class="notice notice-error bssd-notice-inline">
					<p><?php echo esc_html( $error->get_error_message() ); ?></p>
				</div>
			<?php endif; ?>

			<form method="post" class="bssd-search-form">
				<?php wp_nonce_field( self::NONCE_SEARCH ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">
							<label for="bssd_skus"><?php esc_html_e( 'SKU List', 'bulk-sku-search-draft' ); ?></label>
						</th>
						<td>
							<textarea
								name="bssd_skus"
								id="bssd_skus"
								rows="12"
								class="large-text code"
								placeholder="<?php esc_attr_e( 'Enter one SKU per line (max 500)', 'bulk-sku-search-draft' ); ?>"
							><?php echo esc_textarea( $input ); ?></textarea>
						</td>
					</tr>
				</table>
				<p class="submit">
					<button type="submit" name="bssd_search" class="button button-primary">
						<?php esc_html_e( 'Search Products', 'bulk-sku-search-draft' ); ?>
					</button>
				</p>
			</form>
		</div>
		<?php
	}

	/**
	 * Render search results.
	 *
	 * @param array $results Search results.
	 */
	private function render_results( $results ) {
		$summary       = $results['summary'] ?? array();
		$draftable     = (int) ( $summary['draftable'] ?? 0 );
		$draftable_ids = array_map( 'absint', (array) ( $results['draftable_ids'] ?? array() ) );
		$view          = sanitize_key( wp_unslash( $_GET['bssd_view'] ?? 'found' ) );

		if ( ! in_array( $view, array( 'found', 'not_found', 'draftable' ), true ) ) {
			$view = 'found';
		}

		$rows = $this->get_view_rows( $results, $view );
		$paged = max( 1, (int) ( $_GET['paged'] ?? 1 ) );
		$total_pages = max( 1, (int) ceil( count( $rows ) / self::PER_PAGE ) );
		$paged = min( $paged, $total_pages );
		$offset = ( $paged - 1 ) * self::PER_PAGE;
		$page_rows = array_slice( $rows, $offset, self::PER_PAGE );

		$base_url = add_query_arg(
			array(
				'page'      => self::PAGE_SLUG,
				'bssd_view' => $view,
			),
			admin_url( 'admin.php' )
		);

		?>
		<div class="bssd-wrap">
			<h2><?php esc_html_e( 'Search Results', 'bulk-sku-search-draft' ); ?></h2>

			<div class="bssd-summary">
				<div class="bssd-summary-card bssd-summary-card--total">
					<?php esc_html_e( 'Total SKUs', 'bulk-sku-search-draft' ); ?>
					<strong><?php echo esc_html( (string) ( $summary['total'] ?? 0 ) ); ?></strong>
				</div>
				<div class="bssd-summary-card bssd-summary-card--found">
					<?php esc_html_e( 'Found in Store', 'bulk-sku-search-draft' ); ?>
					<strong><?php echo esc_html( (string) ( $summary['found_skus'] ?? 0 ) ); ?></strong>
				</div>
				<div class="bssd-summary-card bssd-summary-card--missing">
					<?php esc_html_e( 'Not Found', 'bulk-sku-search-draft' ); ?>
					<strong><?php echo esc_html( (string) ( $summary['not_found'] ?? 0 ) ); ?></strong>
				</div>
				<div class="bssd-summary-card bssd-summary-card--draftable">
					<?php esc_html_e( 'Published (Draftable)', 'bulk-sku-search-draft' ); ?>
					<strong><?php echo esc_html( (string) $draftable ); ?></strong>
				</div>
				<div class="bssd-summary-card bssd-summary-card--draft">
					<?php esc_html_e( 'Already Draft', 'bulk-sku-search-draft' ); ?>
					<strong><?php echo esc_html( (string) ( $summary['already_draft'] ?? 0 ) ); ?></strong>
				</div>
				<?php if ( ! empty( $summary['other_status'] ) ) : ?>
					<div class="bssd-summary-card bssd-summary-card--other">
						<?php esc_html_e( 'Other Status', 'bulk-sku-search-draft' ); ?>
						<strong><?php echo esc_html( (string) $summary['other_status'] ); ?></strong>
					</div>
				<?php endif; ?>
			</div>

			<div class="bssd-actions">
				<button
					type="button"
					id="bssd-draft-btn"
					class="button button-primary"
					data-count="<?php echo esc_attr( (string) count( $draftable_ids ) ); ?>"
					<?php disabled( count( $draftable_ids ) === 0 ); ?>
				>
					<?php
					printf(
						/* translators: %d: number of draftable products */
						esc_html__( 'Set Published to Draft (%d)', 'bulk-sku-search-draft' ),
						count( $draftable_ids )
					);
					?>
				</button>
				<span id="bssd-draft-progress" class="bssd-draft-progress" aria-live="polite"></span>
			</div>

			<nav class="nav-tab-wrapper bssd-view-tabs">
				<?php
				$tabs = array(
					'found'      => __( 'Found', 'bulk-sku-search-draft' ) . ' (' . (int) ( $summary['found_rows'] ?? 0 ) . ')',
					'not_found'  => __( 'Not Found', 'bulk-sku-search-draft' ) . ' (' . (int) ( $summary['not_found'] ?? 0 ) . ')',
					'draftable'  => __( 'Draftable', 'bulk-sku-search-draft' ) . ' (' . $draftable . ')',
				);

				foreach ( $tabs as $tab_key => $label ) {
					$url = add_query_arg(
						array(
							'page'      => self::PAGE_SLUG,
							'bssd_view' => $tab_key,
						),
						admin_url( 'admin.php' )
					);
					$active = $view === $tab_key ? ' nav-tab-active' : '';
					printf(
						'<a href="%s" class="nav-tab%s">%s</a>',
						esc_url( $url ),
						esc_attr( $active ),
						esc_html( $label )
					);
				}
				?>
			</nav>

			<?php if ( empty( $rows ) ) : ?>
				<div class="notice notice-info bssd-notice-inline">
					<p><?php esc_html_e( 'No items to display for this view.', 'bulk-sku-search-draft' ); ?></p>
				</div>
			<?php else : ?>
				<table class="widefat striped bssd-results-table">
					<thead>
						<tr>
							<?php if ( 'not_found' !== $view ) : ?>
								<th><?php esc_html_e( 'SKU', 'bulk-sku-search-draft' ); ?></th>
								<th><?php esc_html_e( 'Product Name', 'bulk-sku-search-draft' ); ?></th>
								<th><?php esc_html_e( 'Status', 'bulk-sku-search-draft' ); ?></th>
								<th><?php esc_html_e( 'Type', 'bulk-sku-search-draft' ); ?></th>
								<th><?php esc_html_e( 'Actions', 'bulk-sku-search-draft' ); ?></th>
							<?php else : ?>
								<th><?php esc_html_e( 'SKU', 'bulk-sku-search-draft' ); ?></th>
							<?php endif; ?>
						</tr>
					</thead>
					<tbody>
						<?php if ( 'not_found' === $view ) : ?>
							<?php foreach ( $page_rows as $sku ) : ?>
								<tr>
									<td><?php echo esc_html( $sku ); ?></td>
								</tr>
							<?php endforeach; ?>
						<?php else : ?>
							<?php foreach ( $page_rows as $row ) : ?>
								<?php
								$product_id = (int) ( $row['product_id'] ?? 0 );
								$sku_value  = (string) ( $row['sku'] ?? $row['input_sku'] ?? '' );
								?>
								<tr data-product-id="<?php echo esc_attr( (string) $product_id ); ?>">
									<td class="bssd-sku-cell">
										<div class="bssd-sku-display">
											<code class="bssd-sku-value"><?php echo esc_html( $sku_value ); ?></code>
											<button type="button" class="button button-small bssd-sku-edit-btn" title="<?php esc_attr_e( 'Edit SKU', 'bulk-sku-search-draft' ); ?>">
												<?php esc_html_e( 'Edit SKU', 'bulk-sku-search-draft' ); ?>
											</button>
										</div>
										<div class="bssd-sku-editor" hidden>
											<input
												type="text"
												class="bssd-sku-input regular-text"
												value="<?php echo esc_attr( $sku_value ); ?>"
												maxlength="100"
											/>
											<div class="bssd-sku-editor-actions">
												<button type="button" class="button button-primary button-small bssd-sku-save-btn">
													<?php esc_html_e( 'Save', 'bulk-sku-search-draft' ); ?>
												</button>
												<button type="button" class="button button-small bssd-sku-cancel-btn">
													<?php esc_html_e( 'Cancel', 'bulk-sku-search-draft' ); ?>
												</button>
												<span class="bssd-sku-feedback" aria-live="polite"></span>
											</div>
										</div>
									</td>
									<td>
										<?php if ( ! empty( $row['edit_link'] ) ) : ?>
											<a href="<?php echo esc_url( $row['edit_link'] ); ?>" target="_blank" rel="noopener noreferrer">
												<?php echo esc_html( $row['title'] ?? '' ); ?>
											</a>
										<?php else : ?>
											<?php echo esc_html( $row['title'] ?? '' ); ?>
										<?php endif; ?>
									</td>
									<td>
										<span class="bssd-status bssd-status--<?php echo esc_attr( sanitize_html_class( $row['status'] ?? 'unknown' ) ); ?>">
											<?php echo esc_html( ucfirst( (string) ( $row['status'] ?? '' ) ) ); ?>
										</span>
									</td>
									<td><?php echo esc_html( $this->format_post_type( $row['post_type'] ?? '' ) ); ?></td>
									<td class="bssd-actions-cell">
										<?php if ( ! empty( $row['view_link'] ) ) : ?>
											<a href="<?php echo esc_url( $row['view_link'] ); ?>" class="bssd-quick-link" target="_blank" rel="noopener noreferrer">
												<?php esc_html_e( 'View', 'bulk-sku-search-draft' ); ?>
											</a>
										<?php endif; ?>
										<?php if ( ! empty( $row['edit_link'] ) ) : ?>
											<a href="<?php echo esc_url( $row['edit_link'] ); ?>" class="bssd-quick-link" target="_blank" rel="noopener noreferrer">
												<?php esc_html_e( 'Edit', 'bulk-sku-search-draft' ); ?>
											</a>
										<?php endif; ?>
										<?php if ( empty( $row['view_link'] ) && empty( $row['edit_link'] ) ) : ?>
											&mdash;
										<?php endif; ?>
									</td>
								</tr>
							<?php endforeach; ?>
						<?php endif; ?>
					</tbody>
				</table>

				<?php if ( $total_pages > 1 ) : ?>
					<div class="tablenav bssd-pagination">
						<div class="tablenav-pages">
							<?php
							echo wp_kses_post(
								paginate_links(
									array(
										'base'      => add_query_arg( 'paged', '%#%', $base_url ),
										'format'    => '',
										'current'   => $paged,
										'total'     => $total_pages,
										'prev_text' => '&laquo;',
										'next_text' => '&raquo;',
									)
								)
							);
							?>
						</div>
					</div>
				<?php endif; ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Get rows for the active results view.
	 *
	 * @param array  $results Search results.
	 * @param string $view    View key.
	 * @return array<int, mixed>
	 */
	private function get_view_rows( $results, $view ) {
		if ( 'not_found' === $view ) {
			return array_values( (array) ( $results['not_found'] ?? array() ) );
		}

		$found = (array) ( $results['found'] ?? array() );

		if ( 'draftable' === $view ) {
			return array_values(
				array_filter(
					$found,
					function ( $row ) {
						return ! empty( $row['is_draftable'] );
					}
				)
			);
		}

		return $found;
	}

	/**
	 * Format post type label for display.
	 *
	 * @param string $post_type Post type slug.
	 * @return string
	 */
	private function format_post_type( $post_type ) {
		if ( 'product_variation' === $post_type ) {
			return __( 'Variation', 'bulk-sku-search-draft' );
		}

		return __( 'Product', 'bulk-sku-search-draft' );
	}
}
