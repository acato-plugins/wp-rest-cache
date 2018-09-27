<div class="wrap">
    <h1>Acato rest cache</h1>

    <div class="postbox-container">
        <form method="post" action="options.php" class="postbox" style="margin: 10px">

            <h2 style="padding: 0 12px"><span><?php _e('Settings', 'acato-rest-cache'); ?></span></h2>
            <?php settings_fields('acato-rest-cache-settings-group'); ?>
            <?php do_settings_sections('acato-rest-cache-settings-group'); ?>
            <table class="form-table" style="margin: 0 12px">
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
