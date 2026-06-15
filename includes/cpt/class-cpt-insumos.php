<?php

declare(strict_types=1);

namespace RDT\CentrosEstetica\CPT;

class Insumos
{
    public static function register(): void
    {
        register_post_type('insumos', [
            'labels' => [
                'name'          => 'Insumos',
                'singular_name' => 'Insumo',
            ],
            'public'              => true,
            'show_ui'             => true,
            'show_in_menu'        => true,
            'menu_icon'           => 'dashicons-clipboard',
            'supports'            => ['title'],
            'capability_type'     => 'post',
            'map_meta_cap'        => true,
        ]);
    }
}