<?php
//Email que se envía cuando un centro estético hace login mediante Magic Login

if (!defined('ABSPATH')) {
    exit;
}

add_filter('wp_mail', function ($args) {

    // El mail debe tener cuerpo
    if (empty($args['message'])) {
        return $args;
    }

    // Buscamos un link (magic link)
    if (!preg_match('/https?:\/\/[^\s"]+/', $args['message'], $matches)) {
        return $args;
    }

    $magic_link = $matches[0];

    // Intentamos obtener el usuario
    if (!is_string($args['to']) || empty($args['to'])) 
    {
        return;
    }

    $user = get_user_by('email', $args['to']);
    $nombre = $user ? $user->display_name : '';

    // Reescribimos el mail COMPLETO
    $args['subject'] = 'Acceso a tu agenda de depilación';

    $args['message'] =
        "Hola {$nombre},\n\n" .
        "Solicitaste acceso a tu agenda de turnos de depilación.\n\n" .
        "Ingresá desde el siguiente enlace (válido por 15 minutos y de un solo uso):\n\n" .
        $magic_link . "\n\n" .
        "Si no solicitaste este acceso, podés ignorar este mensaje.\n\n" .
        "— Equipo de RD Tecno Belleza";

    return $args;

});

