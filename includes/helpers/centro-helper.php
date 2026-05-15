<?php
namespace RDT\CentrosEstetica\Helpers;
/*
    * Funciones helper para obtener datos de los centros estéticos.
    * Centralizan la lógica de acceso a los custom post types y campos personalizados,
    * evitando código repetido en distintos lugares del plugin.
    *
    * La función principal es get_datos_centro(), que devuelve un array con todos los datos
    * relevantes del centro a partir de su ID. Las demás funciones son auxiliares para obtener
    * campos específicos o para mapear entre user IDs y centro IDs.
    *
    * Estas funciones se usan en varios lugares del plugin, como en los shortcodes para prefill
    * de formularios, en las notificaciones por email, y en cualquier lugar donde necesitemos
    * mostrar o procesar información del centro estético.
    *
    * Importante: estas funciones asumen que el ID del centro es un post ID del tipo 'centro_estetico',
    * no un user ID de WordPress. Para obtener el centro a partir de un user ID, se debe usar
    * get_centro_by_user() primero.
    * Ejemplo de uso:
    $centro_id = get_centro_by_user($user_id);
    $datos_centro = get_datos_centro($centro_id);
*/

//Devuelve el ID del centro estético asociado a un user ID de WordPress, o null si no se encuentra ninguno.
function get_centro_by_user(int $user_id): ?int
{
    $centros = get_posts([
        'post_type'  => 'centro_estetico',
        'meta_query' => [
            [
                'key'   => 'usuario_responsable',
                'value' => $user_id,
            ],
        ],
        'fields'     => 'ids',
        'numberposts' => 1,
    ]);

    return $centros[0] ?? null;
}

//Recibe el ID del centro y devuelve su teléfono. Si no tiene, devuelve string vacío.
//Ése ID es un post ID del tipo 'centro_estetico', no un user ID de WordPress.
function get_telefono_centro(int $centro_id): string {
    $telefono= (string)get_field('telefono', $centro_id,true);
    
        return $telefono;
    
    //return get_post_meta($centro_id, 'telefono', true);
}


function get_email_centro(int $centro_id): string {
    return (string) get_post_meta($centro_id, 'email', true);
}

function get_direccion_centro(int $centro_id): string {
    return (string) get_post_meta($centro_id, 'direccion', true);
}

function get_whatsapp_centro(int $centro_id): string {
    return (string) get_post_meta($centro_id, 'whatsapp', true);
}

function get_localidad_centro(int $centro_id): string {
    return (string) get_post_meta($centro_id, 'localidad', true);
}

function get_provincia_centro(int $centro_id): string {
    return (string) get_post_meta($centro_id, 'provincia', true);
}

/**
 * Devuelve todos los datos del perfil del centro como array.
 * Útil para precompletar formularios o construir respuestas de API.
 */
function get_datos_centro(int $centro_id): array {
    if(!get_post($centro_id) || get_post_type($centro_id) !== 'centro_estetico') {
        return [];
    }
    return [
        'nombre'    => get_the_title($centro_id),
        'telefono'  => get_telefono_centro($centro_id),
        'whatsapp'  => get_whatsapp_centro($centro_id),
        'email'     => get_email_centro($centro_id),
        'direccion' => get_direccion_centro($centro_id),
        'localidad' => get_localidad_centro($centro_id),
        'provincia' => get_provincia_centro($centro_id),
    ];
}