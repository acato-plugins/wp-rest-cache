<h1>WP REST Cache</h1>
<h2 class="nav-tab-wrapper">
    <a href="<?php echo esc_attr( admin_url( 'options-general.php?page=wp-rest-cache&sub=settings' ) ); ?>" id="settings"
       class="nav-tab <?php echo $sub === 'settings' ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Settings', 'wp-rest-cache' ); ?></a>
    <a href="<?php echo esc_attr( admin_url( 'options-general.php?page=wp-rest-cache&sub=endpoint-api' ) ); ?>" id="endpoint-api"
       class="nav-tab <?php echo $sub === 'endpoint-api' ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Endpoint API Caches', 'wp-rest-cache' ); ?></a>
    <a href="<?php echo esc_attr( admin_url( 'options-general.php?page=wp-rest-cache&sub=item-api' ) ); ?>" id="item-api"
       class="nav-tab <?php echo $sub === 'item-api' ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Item API Caches', 'wp-rest-cache' ); ?></a>
</h2>
