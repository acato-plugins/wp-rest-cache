<div id="poststuff">
    <div id="post-body" class="metabox-holder">
        <div class="meta-box-sortables ui-sortable">
            <form method="post">
                <input type="hidden" name="page" value="wp-rest-cache"/>
                <input type="hidden" name="sub" value="<?= esc_attr( $_GET['sub'] ); ?>"/>
                <?php
                $list->prepare_items();
                $list->search_box( __( 'Search', 'wp-rest-cache' ), 'search_id' );
                $list->display();
                ?>
            </form>
        </div>
    </div>
</div>
<br class="clear">