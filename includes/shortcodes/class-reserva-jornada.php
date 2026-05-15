<?php

declare(strict_types=1);
namespace RDT\CentrosEstetica\Shortcodes;

use function RDT\CentrosEstetica\Helpers\get_centro_by_user;
use function RDT\CentrosEstetica\Helpers\get_datos_centro;

/**
 * ReservaJornada — Shortcode [reserva_jornada]
 *
 * Renderiza el formulario de reserva de SSA con los datos del centro
 * estético logueado precargados automáticamente.
 *
 * Atributos opcionales:
 *  - redirect_url: URL a la que SSA redirige tras completar la reserva.
 *                  Por defecto redirige a la página de reservas (/reservas/).
 *  - centro_user_id: ID del usuario centro_estetico para precargar datos.
 *                    Solo admins o el propio usuario pueden usarlo.
 *
 * Uso básico:
 *   [reserva_jornada]
 *
 * Uso con redirect personalizado:
 *   [reserva_jornada redirect_url="https://sitio.com/reservas/"]
 *
 * Uso para admin crear jornada para un centro:
 *   [reserva_jornada centro_user_id="123" redirect_url="https://sitio.com/admin-feedback/"]
 */
final class ReservaJornada
{
    public static function register(): void
    {
        add_shortcode('reserva_jornada', [self::class, 'render']);
    }

    public static function render(array $atts): string
    {
        $atts = shortcode_atts([
            'redirect_url'   => home_url('/reservas/'),
            'centro_user_id' => '',
        ], $atts, 'reserva_jornada');

        $redirect_url = esc_url($atts['redirect_url']);
        $centro_user_id = (int) $atts['centro_user_id'];

        // Determinar el user_id a usar
        $target_user_id = 0;
        if ($centro_user_id > 0) {
            // Solo admins pueden especificar centro_user_id, o si es el propio usuario
            if (current_user_can('manage_options') || get_current_user_id() === $centro_user_id) {
                $target_user_id = $centro_user_id;
            }
        } elseif (is_user_logged_in()) {
            $user = wp_get_current_user();
            if (in_array('centro_estetico', (array) $user->roles, true)) {
                $target_user_id = $user->ID;
            }
        }

        // Cargamos el prefill si tenemos un target_user_id válido
        if ($target_user_id > 0) {
            $centro_id = get_centro_by_user($target_user_id);

            if ($centro_id) {
                $datos = get_datos_centro($centro_id);

                $user_obj = get_user_by('ID', $target_user_id);
                if (empty($datos['email']) && $user_obj) {
                    $datos['email'] = $user_obj->user_email;
                }

                $nombre = !empty($datos['nombre'])
                    ? $datos['nombre']
                    : ($user_obj ? $user_obj->display_name : '');

                \RDT\CentrosEstetica\Assets\AssetsLoader::load_ssa_prefill([
                    'nombre'     => $nombre,
                    'email'      => $datos['email'],
                    'telefono'   => $datos['telefono'] ?: $datos['whatsapp'],
                    'direccion'  => $datos['direccion'],
                    'localidad'  => $datos['localidad'],
                    'provincia'  => $datos['provincia'],
                    'redirectUrl' => $redirect_url,
                ]);
            }
        }

        // SSA acepta el atributo redirect_url en su shortcode para
        // redirigir al usuario al completar la reserva
        return do_shortcode('[ssa_booking redirect_url="' . $redirect_url . '"]');
    }
}
