<?php
/**
 * Plugin Name: RDT Login Features
 * Description: Características principales para login de clientas.
 * Author: Rodolfo M. Duttweiler
 * Version: 1.0
 */

/**
 * add_filter('login_redirect', function ($redirect_to, $requested_redirect_to, $user) {

    if (!is_a($user, 'WP_User')) {
        return $redirect_to;
    }

    // Rol centro estético (lo crearemos luego)
    if (in_array('centro_estetico', (array) $user->roles, true)) {
        return home_url('/panel-centro');
    }

    return $redirect_to;

}, 10, 3);

//Ahora implementaremos cambios para los mails que quiera enviar desde Magic Login

add_filter('wp_mail', function ($args) {

    // Detectamos email de Magic Login por el asunto original
    if (isset($args['subject']) && str_contains($args['subject'], 'Login')) {

        // Intentamos extraer el link del mensaje original
        if (preg_match('/https?:\/\/[^\s"]+/', $args['message'], $matches)) {
            $magic_link = $matches[0];
        } else {
            return $args;
        }
        $user = get_user_by('email', $args['to']);

        $args['subject'] = 'Acceso a tu agenda de depilación';

        $args['message'] =
            "Hola " . $user . ",\n\n" .
            "Solicitaste acceso a tu agenda de turnos de depilación.\n\n" .
            "Ingresá desde el siguiente enlace (válido por 30 minutos y de un solo uso):\n\n" .
            $magic_link . "\n\n" .
            "Si no solicitaste este acceso, podés ignorar este mensaje.\n\n" .
            "— Equipo de RD Tecno Belleza";

    }

    return $args;

});

//Agregamos el rol principal a nuestro Plugin
register_activation_hook(__FILE__, function () {

    add_role(
        'centro_estetico',
        'Centro Estético',
        [
            'read' => true,
        ]
    );

});

//Eliminamos acceso a wp-admin para usuarios que no sean administradores
add_action('admin_init', function () {

    if (!current_user_can('administrator') && !wp_doing_ajax()) {
        wp_redirect(home_url('/panel-centro'));
        exit;
    }

});

 * 
 */
