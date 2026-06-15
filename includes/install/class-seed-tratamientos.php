<?php
namespace RDT\CentrosEstetica\Install;

class SeedTratamientos {

    public static function run() {

        $tratamientos = [
            [
                'title' => 'Bozo / Mentón / Entrecejo',
                'duracion_min' => 5,
                'categoria' => 'Extra Pequeña',
                'combo' => false,
            ],
            [
                'title' => 'Axilas',
                'duracion_min' => 7,
                'categoria' => 'Pequeña',
                'combo' => false,
            ],
            [
                'title' => 'Cavado Full + Tira de cola',
                'duracion_min' => 15,
                'categoria' => 'Mediana',
                'combo' => false,
            ],
            [
                'title' => 'Media Pierna',
                'duracion_min' => 15,
                'categoria' => 'Grande',
                'combo' => false,
            ],
            [
                'title' => 'Pierna Entera',
                'duracion_min' => 25,
                'categoria' => 'XL',
                'combo' => false,
            ],
            [
                'title' => 'Espalda / Pecho (Hombres)',
                'duracion_min' => 25,
                'categoria' => 'XL',
                'combo' => false,
            ],
            [
                'title' => 'Brazos Completos',
                'duracion_min' => 15,
                'categoria' => 'Mediana',
                'combo' => false,
            ],
            [
                'title' => 'Combo Trío Express',
                'duracion_min' => 20,
                'categoria' => 'Combo',
                'combo' => true,
            ],
            [
                'title' => 'Combo Piernas Full',
                'duracion_min' => 45,
                'categoria' => 'Combo',
                'combo' => true,
            ],
            [
                'title' => 'Combo Cuerpo Completo',
                'duracion_min' => 60,
                'categoria' => 'Combo',
                'combo' => true,
            ],
        ];

        foreach ($tratamientos as $t) {

            if (get_page_by_title($t['title'], OBJECT, 'tratamiento_dep')) {
                continue;
            }

            $post_id = wp_insert_post([
                'post_type'   => 'tratamiento_dep',
                'post_title'  => $t['title'],
                'post_status' => 'publish',
                'meta_input'  => [
                    'duracion_min' => $t['duracion_min'],
                    'categoria'        => $t['categoria'],
                ],
            ]);

            if ($post_id && function_exists('update_field')) {
                update_field('duracion_min', $t['duracion_min'], $post_id);
                update_field('categoria', $t['categoria'], $post_id);
                update_field('es_combo', $t['combo'], $post_id);
            }
        }
        
    }
    
    public static function run_if_empty(): void
        {
        $exists = get_posts([
            'post_type'      => 'tratamiento_dep',
            'posts_per_page' => 1,
        ]);

        if (empty($exists)) {
            self::run();
        }
    }
}
