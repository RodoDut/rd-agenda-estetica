<?php
//Redirección Post Login de usuarios tipo centro_estetico
if (!defined('ABSPATH')) {
    exit;
}

add_filter('login_redirect', function ($redirect_to, $requested_redirect_to, $user) {

    if (!is_a($user, 'WP_User')) {
        return $redirect_to;
    }

    if (in_array('centro_estetico', (array) $user->roles, true)) {
        return home_url('/panel-centro');
    }

    return $redirect_to;

}, 10, 3);
