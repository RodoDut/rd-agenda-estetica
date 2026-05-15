<?php

declare(strict_types=1);
namespace RDT\CentrosEstetica\Shortcodes;

use RDT\CentrosEstetica\Assets\AssetsLoader;
use function RDT\CentrosEstetica\Helpers\get_centro_by_user;

/*
Shortcode [calendario_centro]
Muestra el calendario diario del centro estético logueado.
Permite navegar día a día y ver los turnos dados y horarios libres.
*/
class CalendarioCentro
{
    public static function register(): void
    {
        add_shortcode('calendario_centro', [self::class, 'render']);
    }

    public static function render(): string
    {
        // Solo usuarios logueados (dueños de centro)
        if (!is_user_logged_in()) {
            return '<p>Debes iniciar sesión para ver tu calendario.</p>';
        }

        $centro_id = get_centro_by_user(get_current_user_id());
        if (!$centro_id) {
            return '<p>No tenés un centro estético asociado a tu cuenta.</p>';
        }

        // La fecha puede venir por GET (al navegar) o es el día de hoy por defecto
        $fecha = isset($_GET['cal_fecha'])
            ? sanitize_text_field($_GET['cal_fecha'])
            : current_time('Y-m-d');

        // Validamos que sea una fecha real antes de pasarla al JS
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) {
            $fecha = current_time('Y-m-d');
        }

        AssetsLoader::load_calendario_centro($fecha, $centro_id);

        ob_start();
        ?>
        <div id="rdt-calendario" data-centro="<?= esc_attr($centro_id) ?>" data-fecha="<?= esc_attr($fecha) ?>">

            <div class="rdt-cal-header">
                <button id="rdt-cal-anterior" class="rdt-cal-nav" aria-label="Día anterior">&#8592;</button>
                <h3 id="rdt-cal-titulo" class="rdt-cal-titulo">Cargando...</h3>
                <button id="rdt-cal-siguiente" class="rdt-cal-nav" aria-label="Día siguiente">&#8594;</button>
            </div>

            <div id="rdt-cal-estado-jornada" class="rdt-cal-estado"></div>

            <div id="rdt-cal-grilla" class="rdt-cal-grilla">
                <p class="rdt-cal-cargando">Cargando turnos...</p>
            </div>

        </div>
        <?php
        return ob_get_clean();
    }
}
