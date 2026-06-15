<?php
namespace RDT\CentrosEstetica\CPT;

class JornadaCentro {

    public static function register() {

        register_post_type('jornada_centro', [
            'label' => 'Jornadas de Centros',
            'public' => false,
            'show_ui' => true,
            'menu_icon' => 'dashicons-calendar-alt',
            'supports' => ['title'],
            'capability_type' => 'post',
            'map_meta_cap' => true,
        ]);
    }
}
