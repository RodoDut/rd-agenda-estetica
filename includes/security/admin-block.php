<?php
//Esto es una capa extra de seguridad (defensivo).
//Por si un usuario tipo centro_estetico quiere ingresar al panel de administración de Wordpress
if (!defined('ABSPATH')) {
    exit;
}

add_action('init', function () {

    if (is_admin() && is_user_logged_in()) {

        $user = wp_get_current_user();

        if (in_array('centro_estetico', (array) $user->roles, true)) {
            wp_redirect(home_url('/panel-centro'));
            exit;
        }

    }

});
