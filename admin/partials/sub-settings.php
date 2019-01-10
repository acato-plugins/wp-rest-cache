<div class="wrap">
    <div class="postbox-container">
        <form method="post" action="options.php" class="postbox" style="margin: 10px">

            <h3 style="padding: 0 12px"><span><?php esc_html_e( 'Settings', 'wp-rest-cache' ); ?></span></h3>
            <?php settings_fields( 'wp-rest-cache-settings' ); ?>
            <?php do_settings_sections( 'wp-rest-cache-settings' ); ?>
            <?php $wp_rest_cache_timeout = \WP_Rest_Cache_Plugin\Includes\Caching\Caching::get_instance()->get_timeout( false ); ?>
            <?php $wp_rest_cache_timeout_interval = \WP_Rest_Cache_Plugin\Includes\Caching\Caching::get_instance()->get_timeout_interval(); ?>

            <table class="form-table" style="margin: 0 12px">
                <tbody>
                <tr>
                    <th>Cache timeout</th>
                    <td>
                        <input type="number" min="1" name="wp_rest_cache_timeout" class="small-text"
                               value="<?php echo esc_attr( $wp_rest_cache_timeout ); ?>">
                        <select name="wp_rest_cache_timeout_interval" id="wp_rest_cache_timeout_interval"
                                style="vertical-align: initial">
                            <option value="<?php echo esc_attr( MINUTE_IN_SECONDS ); ?>"
                                <?php selected( $wp_rest_cache_timeout_interval, MINUTE_IN_SECONDS ); ?>>
                                <?php esc_html_e( 'Minute(s)', 'wp-rest-cache' ); ?>
                            </option>
                            <option value="<?php echo esc_attr( HOUR_IN_SECONDS ); ?>"
                                <?php selected( $wp_rest_cache_timeout_interval, HOUR_IN_SECONDS ); ?>>
                                <?php esc_html_e( 'Hour(s)', 'wp-rest-cache' ); ?>
                            </option>
                            <option value="<?php echo esc_attr( DAY_IN_SECONDS ); ?>"
                                <?php selected( $wp_rest_cache_timeout_interval, DAY_IN_SECONDS ); ?>>
                                <?php esc_html_e( 'Day(s)', 'wp-rest-cache' ); ?>
                            </option>
                            <option value="<?php echo esc_attr( WEEK_IN_SECONDS ); ?>"
                                <?php selected( $wp_rest_cache_timeout_interval, WEEK_IN_SECONDS ); ?>>
                                <?php esc_html_e( 'Week(s)', 'wp-rest-cache' ); ?>
                            </option>
                            <option value="<?php echo esc_attr( MONTH_IN_SECONDS ); ?>"
                                <?php selected( $wp_rest_cache_timeout_interval, MONTH_IN_SECONDS ); ?>>
                                <?php esc_html_e( 'Month(s)', 'wp-rest-cache' ); ?>
                            </option>
                            <option value="<?php echo esc_attr( YEAR_IN_SECONDS ); ?>"
                                <?php selected( $wp_rest_cache_timeout_interval, YEAR_IN_SECONDS ); ?>>
                                <?php esc_html_e( 'Year(s)', 'wp-rest-cache' ); ?>
                            </option>
                        </select>
                        <p class="description"
                           id="wp_rest_cache_timeout-description"><?php esc_html_e( 'Time until expiration of cache. (Default = 1 year)', 'wp-rest-cache' ); ?></p>
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
