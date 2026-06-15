<?php
if (!defined('ABSPATH')) {
    exit;
}

add_action('admin_init', function () {

    if (wp_doing_ajax()) {
        return;
    }

    if (current_user_can('centro_estetico')) {
        wp_redirect(home_url('/panel-centro'));
        exit;
    }

});


