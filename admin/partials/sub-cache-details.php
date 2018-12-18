<div class="wrap">
    <h3><?= __( 'Cache details', 'wp-rest-cache' ); ?></h3>
    <?php $cache = \WP_Rest_Cache_Plugin\Includes\Caching\Caching::get_instance()->get_cache_data(
        filter_input( INPUT_GET, 'cache_key', FILTER_SANITIZE_STRING )
    );
    ?>
    <div class="poststuff">
        <div id="post-body" class="metabox-holder">
            <div id="post-body-content">
                <div class="meta-box-sortables ui-sortable">
                    <div class="postbox">
                        <h3 class="hndle"><?= __( 'Cache info', 'wp-rest-cache' ); ?></h3>
                        <div class="inside">
                            <p>
                                <?php if ( is_null( $cache ) ): ?>
                                    <?= __( 'Sorry the cache could not be found.', 'wp-rest-cache' ); ?>
                                <?php else: ?>
                            <table class="form-table">
                                <tr valign="top">
                                    <th scope="row"><?= __( 'Cache Key', 'wp-rest-cache' ); ?></th>
                                    <td><?= esc_html( $cache['row']['cache_key'] ); ?></td>
                                </tr>
                                <tr valign="top" class="alternate">
                                    <th scope="row"><?= __( 'Cache Type', 'wp-rest-cache' ); ?></th>
                                    <td><?= esc_html( $cache['row']['cache_type'] ); ?></td>
                                </tr>
                                <tr valign="top">
                                    <th scope="row"><?= __( 'Request URI', 'wp-rest-cache' ); ?></th>
                                    <td><?= esc_html( $cache['row']['request_uri'] ); ?></td>
                                </tr>
                                <tr valign="top" class="alternate">
                                    <th scope="row"><?= __( 'Object Type', 'wp-rest-cache' ); ?></th>
                                    <td><?= esc_html( $cache['row']['object_type'] ); ?></td>
                                </tr>
                                <tr valign="top">
                                    <th scope="row"><?= __( 'Expiration', 'wp-rest-cache' ); ?></th>
                                    <td><?= esc_html( $cache['row']['expiration'] ); ?></td>
                                </tr>
                                <tr valign="top" class="alternate">
                                    <th scope="row"><?= __( '# Cache Hits', 'wp-rest-cache' ); ?></th>
                                    <td><?= esc_html( $cache['row']['cache_hits'] ); ?></td>
                                </tr>
                                <tr valign="top">
                                    <th scope="row"><?= __( 'Active', 'wp-rest-cache' ); ?></th>
                                    <td>
                                        <?php if ( $cache['row']['is_active'] ) {
                                            echo sprintf( '<span class="dashicons dashicons-yes" style="color:green" title="%s"></span>
                                                    <span class="screen-reader-text">%s</span>',
                                                __( 'Cache is ready to be served.', 'wp-rest-cache' ),
                                                __( 'Cache is ready to be served.', 'wp-rest-cache' )
                                            );
                                        } else {

                                            echo sprintf( '<span class="dashicons dashicons-no" style="color:red" title="%s"></span>
                                                    <span class="screen-reader-text">%s</span>',
                                                __( 'Cache is expired or flushed.', 'wp-rest-cache' ),
                                                __( 'Cache is expired or flushed.', 'wp-rest-cache' )
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
                    <div class="postbox">
                        <h3 class="hndle"><?= __( 'Cache data', 'wp-rest-cache' ); ?></h3>
                        <div class="inside">
                            <p>
                                <?php if ( ! isset( $cache['data'] ) ): ?>
                                    <?= __( 'Cache is expired or flushed.', 'wp-rest-cache' ); ?>
                                <?php else: ?>
                                    <pre><?= esc_html( json_encode( $cache['data']['data'], JSON_PRETTY_PRINT ) ); ?></pre>
                                <?php endif; ?>
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
