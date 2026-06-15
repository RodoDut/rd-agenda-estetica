<?php

namespace RDT\CentrosEstetica\Shortcodes;

use RDT\CentrosEstetica\Assets\AssetsLoader;
class CancelarTurnoShortcode
{
    public static function register()
    {
        add_shortcode('cancelar_turno', [self::class, 'render']);
    }

    public static function render()
    {
        if (empty($_GET['token'])) {
            return '<p>Turno no válido.</p>';
        }

        $token = esc_attr($_GET['token']);

       AssetsLoader::load_cancelar_turno($token);
        
       ob_start();
       
       ?>
        <div id="rdt-cancelar-turno">
            <div class="rdt-ct-header">
                <h3 class="rdt-ct-titulo">Cancelar Turno</h3>
            </div>
            <div class="rdt-ct-cuerpo">
                <p class="rdt-ct-pregunta">¿Deseás cancelar tu turno?</p>
                <p class="rdt-ct-advertencia">Esta acción no se puede deshacer.</p>
                <button id="rdt-cancelar" class="rdt-ct-btn">Cancelar turno</button>
                <div id="rdt-msg" class="rdt-ct-msg"></div>
            </div>
        </div>
        <?php
       
       return ob_get_clean();
    }
}
