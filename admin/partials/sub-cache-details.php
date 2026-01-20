<?php
/**
 * View for the Cache details.
 *
 * @link: https://www.acato.nl
 * @since 2018.1
 *
 * @package    WP_Rest_Cache_Plugin
 * @subpackage WP_Rest_Cache_Plugin/Admin/Partials
 */

/** This filter is documented in admin/class-admin.php in the function create_menu(). */
if ( ! current_user_can( apply_filters( 'wp_rest_cache/settings_capability', 'administrator' ) ) ) {
	wp_die( esc_html__( 'You do not have permission to access this page.', 'wp-rest-cache' ) );
}
?>
<div class="wrap">
	<h3><?php esc_html_e( 'Cache details', 'wp-rest-cache' ); ?></h3>
	<?php
	$wp_rest_cache = \WP_Rest_Cache_Plugin\Includes\Caching\Caching::get_instance()->get_cache_data(
		filter_input( INPUT_GET, 'cache_key', FILTER_SANITIZE_FULL_SPECIAL_CHARS )
	);
	if ( ! is_null( $wp_rest_cache ) ) {
		$wprc_page = filter_input( INPUT_GET, 'page', FILTER_SANITIZE_FULL_SPECIAL_CHARS );
		$wprc_sub  = filter_input( INPUT_GET, 'sub', FILTER_SANITIZE_FULL_SPECIAL_CHARS );
		?>
		<p>
			<?php if ( $wp_rest_cache['row']['is_active'] ) : ?>
				<a href="
				<?php
				printf(
					'?page=%s&sub=%s&action=%s&cache_key=%s&wp_rest_cache_nonce=%s',
					esc_attr( $wprc_page ),
					esc_attr( $wprc_sub ),
					'flush',
					esc_attr( $wp_rest_cache['row']['cache_key'] ),
					esc_attr( wp_create_nonce( 'wp_rest_cache_flush_cache' ) )
				);
				?>
				" class="button button-primary" rel="noopener noreferrer">
					<?php esc_html_e( 'Flush cache', 'wp-rest-cache' ); ?>
				</a>
			<?php endif; ?>
			<a href="
			<?php
			printf(
				'?page=%s&sub=%s&action=%s&cache_key=%s&wp_rest_cache_nonce=%s',
				esc_attr( $wprc_page ),
				esc_attr( $wprc_sub ),
				'delete',
				esc_attr( $wp_rest_cache['row']['cache_key'] ),
				esc_attr( wp_create_nonce( 'wp_rest_cache_delete_cache' ) )
			);
			?>
			" class="button button-secondary" rel="noopener noreferrer">
				<?php esc_html_e( 'Delete cache', 'wp-rest-cache' ); ?>
			</a>
		</p>
		<?php
	}
	?>
	<div class="poststuff">
		<div id="post-body" class="metabox-holder">
			<div id="post-body-content">
				<div class="meta-box-sortables ui-sortable">
					<div class="postbox">
						<h3 class="hndle"><?php esc_html_e( 'Cache info', 'wp-rest-cache' ); ?></h3>
						<div class="inside">
							<p>
								<?php if ( is_null( $wp_rest_cache ) ) : ?>
									<?php esc_html_e( 'Sorry the cache could not be found.', 'wp-rest-cache' ); ?>
								<?php else : ?>
							<table class="form-table">
								<tr valign="top">
									<th scope="row"><?php esc_html_e( 'Cache Key', 'wp-rest-cache' ); ?></th>
									<td><?php echo esc_html( $wp_rest_cache['row']['cache_key'] ); ?></td>
								</tr>
								<tr valign="top" class="alternate">
									<th scope="row"><?php esc_html_e( 'Cache Type', 'wp-rest-cache' ); ?></th>
									<td><?php echo esc_html( $wp_rest_cache['row']['cache_type'] ); ?></td>
								</tr>
								<tr valign="top">
									<th scope="row"><?php esc_html_e( 'Request URI', 'wp-rest-cache' ); ?></th>
									<td><?php echo esc_html( $wp_rest_cache['row']['request_uri'] ); ?></td>
								</tr>
								<tr valign="top" class="alternate">
									<th scope="row"><?php esc_html_e( 'Object Type', 'wp-rest-cache' ); ?></th>
									<td><?php echo esc_html( $wp_rest_cache['row']['object_type'] ); ?></td>
								</tr>
								<tr valign="top">
									<th scope="row"><?php esc_html_e( 'Expiration', 'wp-rest-cache' ); ?></th>
									<td><?php echo esc_html( $wp_rest_cache['row']['expiration'] ); ?></td>
								</tr>
								<tr valign="top" class="alternate">
									<th scope="row"><?php esc_html_e( '# Cache Hits', 'wp-rest-cache' ); ?></th>
									<td><?php echo esc_html( $wp_rest_cache['row']['cache_hits'] ); ?></td>
								</tr>
								<tr valign="top">
									<th scope="row"><?php esc_html_e( 'Active', 'wp-rest-cache' ); ?></th>
									<td>
										<?php
										if ( $wp_rest_cache['row']['is_active'] ) {
											printf(
												'<span class="dashicons dashicons-yes" style="color:green" title="%s"></span>
                                                    <span class="screen-reader-text">%s</span>',
												esc_html__( 'Cache is ready to be served.', 'wp-rest-cache' ),
												esc_html__( 'Cache is ready to be served.', 'wp-rest-cache' )
											);
										} else {

											printf(
												'<span class="dashicons dashicons-no" style="color:red" title="%s"></span>
                                                    <span class="screen-reader-text">%s</span>',
												esc_html__( 'Cache is expired or flushed.', 'wp-rest-cache' ),
												esc_html__( 'Cache is expired or flushed.', 'wp-rest-cache' )
											);
										}
										?>
									</td>
								</tr>
							</table>
							<?php endif; ?>
							</p>
						</div>
					</div>
					<?php if ( $wp_rest_cache['row']['request_headers'] ) : ?>
						<div class="postbox">
							<h3 class="hndle"><?php esc_html_e( 'Cached request headers', 'wp-rest-cache' ); ?></h3>
							<div class="inside">
								<p>
								<pre><?php echo esc_html( wp_json_encode( json_decode( $wp_rest_cache['row']['request_headers'], true ), JSON_PRETTY_PRINT ) ); ?></pre>
								</p>
							</div>
						</div>
					<?php endif; ?>
					<div class="postbox">
						<h3 class="hndle"><?php esc_html_e( 'Cache data', 'wp-rest-cache' ); ?></h3>
						<div class="inside">
							<p>
								<?php if ( empty( $wp_rest_cache['data'] ) ) : ?>
									<?php esc_html_e( 'Cache is expired or flushed.', 'wp-rest-cache' ); ?>
								<?php else : ?>
							<pre><?php echo esc_html( wp_json_encode( $wp_rest_cache['data']['data'], JSON_PRETTY_PRINT ) ); ?></pre>
							<?php endif; ?>
							</p>
						</div>
					</div>
				</div>
			</div>
		</div>
	</div>
</div>
