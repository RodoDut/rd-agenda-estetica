<?php
/*
* Helper para obtener la Jornada por su token único
*/
namespace RDT\CentrosEstetica\Helpers;

function get_jornada_by_token(string $token): ?\WP_Post
{
    $jornadas = get_posts([
        'post_type'  => 'jornada_centro',
        'meta_key'   => 'token_publico',
        'meta_value' => $token,
        'numberposts'=> 1,
    ]);

    return $jornadas[0] ?? null;
}
