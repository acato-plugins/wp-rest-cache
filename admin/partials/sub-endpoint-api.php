<div class="wrap">
    <h3><?= __( 'Endpoint API Caches', 'wp-rest-cache' ); ?></h3>
    <?php
    $list = new \WP_Rest_Cache_Plugin\Admin\Includes\API_Caches_Table( 'endpoint' );
    include_once( 'caches-table.php' );
    ?>
</div>
