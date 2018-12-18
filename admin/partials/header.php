<h1>WP REST Cache</h1>
<h2 class="nav-tab-wrapper">
    <a href="<?= admin_url( 'options-general.php?page=wp-rest-cache&sub=settings' ); ?>" id="settings"
       class="nav-tab <?= $sub == 'settings' ? 'nav-tab-active' : ''; ?>"><?= __( 'Settings', 'wp-rest-cache' ); ?></a>
    <a href="<?= admin_url( 'options-general.php?page=wp-rest-cache&sub=endpoint-api' ); ?>" id="endpoint-api"
       class="nav-tab <?= $sub == 'endpoint-api' ? 'nav-tab-active' : ''; ?>"><?= __( 'Endpoint API Caches', 'wp-starter-plugin' ); ?></a>
    <a href="<?= admin_url( 'options-general.php?page=wp-rest-cache&sub=item-api' ); ?>" id="item-api"
       class="nav-tab <?= $sub == 'item-api' ? 'nav-tab-active' : ''; ?>"><?= __( 'Item API Caches', 'wp-starter-plugin' ); ?></a>
</h2>