<?php

declare(strict_types=1);

namespace RDT\CentrosEstetica\CPT;

class ServiciosClientes
{
    public static function register(): void
    {
        register_post_type('servicios_clientes', [
            'labels' => [
                'name'          => 'Servicios Clientes',
                'singular_name' => 'Servicio Cliente',
            ],
            'public'              => false,
            'show_ui'             => true,
            'show_in_menu'        => true,
            'menu_icon'           => 'dashicons-clock',
            'supports'            => ['title'],
            'capability_type'     => 'post',
            'map_meta_cap'        => true,
        ]);
    }
}