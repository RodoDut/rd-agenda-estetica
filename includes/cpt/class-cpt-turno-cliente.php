<?php
namespace RDT\CentrosEstetica\CPT;

class TurnoCliente {

    public static function register() {

        register_post_type('turno_cliente', [
            'labels' => [
                'name'          => 'Turnos de Clientes',
                'singular_name' => 'Turno de Cliente',
            ],
            'public'              => false,
            'show_ui'             => true,
            'menu_icon'           => 'dashicons-calendar-alt',
            'supports'            => ['title'],
            'exclude_from_search' => true,
        ]);
    }
}
