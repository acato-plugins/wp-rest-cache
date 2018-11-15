<div class="wrap">
    <h1>WP REST Cache</h1>

    <div class="postbox-container">
        <form method="post" action="options.php" class="postbox" style="margin: 10px">

            <h2 style="padding: 0 12px"><span><?php _e( 'Settings', 'wp-rest-cache' ); ?></span></h2>
            <?php settings_fields( 'wp-rest-cache-settings' ); ?>
            <?php do_settings_sections( 'wp-rest-cache-settings' ); ?>
            <?php $timeout = WP_Rest_Cache::get_timeout(); ?>

            <table class="form-table" style="margin: 0 12px">
                <tbody>
                <tr>
                    <th>Cache timeout</th>
                    <td>
                        <input type="number" min="0" name="wp_rest_cache_timeout" value="<?= $timeout ?>">
                        <p class="description"
                           id="wp_rest_cache_timeout-description"><?= __( 'Time until expiration in seconds from now, or 0 for never expires. (Default = 0)', 'wp-rest-cache' ); ?></p>
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
