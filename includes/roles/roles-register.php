<?php
if (!defined('ABSPATH')) {
    exit;
}

register_activation_hook(
    dirname(__DIR__, 2) . '/rdt-centros-core.php',
    function () {

        // Rol Centro Estético
        add_role(
            'centro_estetico',
            'Centro Estético',
            [
                'read' => true, // necesario para login
            ]
        );

    }
);
