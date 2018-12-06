<div class="wrap">
    <h2><?= __( 'Cache details', 'wp-rest-cache' ); ?></h2>
    <?php $cache = \WP_Rest_Cache_Plugin\Includes\Caching\Caching::get_instance()->get_cache_data(
        filter_input( INPUT_GET, 'cache_key', FILTER_SANITIZE_STRING )
    );
    ?>
    <div class="poststuff">
        <div id="post-body" class="metabox-holder">
            <div id="post-body-content">
                <div class="meta-box-sortables ui-sortable">
                    <div class="postbox">
                        <h3 class="hndle"><?= __( 'Cache data', 'wp-rest-cache' ); ?></h3>
                        <div class="inside">
                            <p>
                                <?php if ( is_null( $cache ) ): ?>
                                    <?= __( 'Sorry the cache could not be found.', 'wp-rest-cache' ); ?>
                                <?php else: ?>
                            <table class="form-table">

                            </table>
                            <?php endif; ?>
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
