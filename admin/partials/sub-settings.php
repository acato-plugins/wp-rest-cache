<div class="wrap">
    <div class="postbox-container">
        <form method="post" action="options.php" class="postbox" style="margin: 10px">

            <h3 style="padding: 0 12px"><span><?php _e( 'Settings', 'wp-rest-cache' ); ?></span></h3>
            <?php settings_fields( 'wp-rest-cache-settings' ); ?>
            <?php do_settings_sections( 'wp-rest-cache-settings' ); ?>
            <?php $timeout = \WP_Rest_Cache_Plugin\Includes\Caching\Caching::get_instance()->get_timeout(); ?>

            <table class="form-table" style="margin: 0 12px">
                <tbody>
                <tr>
                    <th>Cache timeout</th>
                    <td>
                        <input type="number" min="1" name="wp_rest_cache_timeout" value="<?= $timeout ?>">
                        <p class="description"
                           id="wp_rest_cache_timeout-description"><?= __( 'Time until expiration in seconds from now. (Default = 1 year = 3153600 seconds)', 'wp-rest-cache' ); ?></p>
                    </td>
                </tr>
                </tbody>
                <tfoot>
                <tr>
                    <td colspan="2" align="center">
                        <?php submit_button(); ?>
                    </td>
                </tr>
                </tfoot>
            </table>
        </form>
    </div>
</div>
