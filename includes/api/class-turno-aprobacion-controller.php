<?php

declare(strict_types=1);
namespace RDT\CentrosEstetica\Api;

use WP_REST_Request;
use WP_REST_Response;
use RDT\CentrosEstetica\Domain\TurnoEstado;
use RDT\CentrosEstetica\Repositories\TurnoClienteRepository;
use RDT\CentrosEstetica\Notifications\TurnoAprobacionMailer;

/**
 * TurnoAprobacionController
 *
 * Endpoints GET para las acciones de aprobación y rechazo desde el email del centro.
 *
 * GET /rdt/v1/turno/{id}/aprobar?token=XXX
 * GET /rdt/v1/turno/{id}/rechazar?token=XXX
 *
 * Son GET porque el centro hace click en un link del email — no puede hacer PATCH.
 * El token garantiza que solo el destinatario del email puede ejecutar la acción.
 * Son de un solo uso: se eliminan al procesarse.
 */
final class TurnoAprobacionController
{
    public static function register(): void
    {
        register_rest_route('rdt/v1', '/turno/(?P<id>\d+)/aprobar', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'handleAprobar'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route('rdt/v1', '/turno/(?P<id>\d+)/rechazar', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'handleRechazar'],
            'permission_callback' => '__return_true',
        ]);
    }

    public static function handleAprobar(WP_REST_Request $request): void
    {
        $turno_id = (int) $request->get_param('id');
        $token    = sanitize_text_field($request->get_param('token'));

        $error = self::validarToken($turno_id, $token);
        if ($error) {
            self::responderHtml('Error', $error, false);
            return;
        }

        $repo = new TurnoClienteRepository();
        $repo->actualizarEstado($turno_id, TurnoEstado::APROBADO);

        // Invalidar token (uso único)
        delete_post_meta($turno_id, 'rdt_aprobacion_token');
        delete_post_meta($turno_id, 'rdt_aprobacion_token_expiry');

        $mailer      = new TurnoAprobacionMailer();
        $datos_turno = self::getDatosTurno($turno_id);
        $nombre_centro = get_the_title($datos_turno['centro_id'] ?? 0) ?: 'el centro';

        $mailer->enviarAprobadoAlCliente(
            $turno_id,
            $datos_turno['email_cliente'],
            $datos_turno['nombre_cliente'],
            $nombre_centro,
            $datos_turno['telefono_centro'],
            $datos_turno['direccion_centro'],
            $datos_turno['email_centro'],
            $datos_turno['nombre_servicio'],   // clave correcta: 'nombre_servicio'
            $datos_turno['fecha'],
            $datos_turno['hora_inicio'],
            $datos_turno['hora_fin'],
            $datos_turno['token_turno']
        );

        self::responderHtml(
            '✓ Turno aprobado',
            "El turno de <strong>{$datos_turno['nombre_cliente']}</strong> fue aprobado correctamente. Se envió una confirmación a la clienta.",
            true
        );
    }

    public static function handleRechazar(WP_REST_Request $request): void
    {
        $turno_id = (int) $request->get_param('id');
        $token    = sanitize_text_field($request->get_param('token'));

        $error = self::validarToken($turno_id, $token);
        if ($error) {
            self::responderHtml('Error', $error, false);
            return;
        }

        $repo = new TurnoClienteRepository();
        $repo->actualizarEstado($turno_id, TurnoEstado::CANCELADO);

        delete_post_meta($turno_id, 'rdt_aprobacion_token');
        delete_post_meta($turno_id, 'rdt_aprobacion_token_expiry');

        $mailer      = new TurnoAprobacionMailer();
        $datos_turno = self::getDatosTurno($turno_id);
        $nombre_centro = get_the_title($datos_turno['centro_id'] ?? 0) ?: 'el centro';

        $mailer->enviarRechazadoAlCliente(
            $datos_turno['email_cliente'],
            $datos_turno['nombre_cliente'],
            $nombre_centro,
            $datos_turno['nombre_servicio'],   // clave correcta: 'nombre_servicio'
            $datos_turno['fecha'],
            $datos_turno['hora_inicio']
        );

        self::responderHtml(
            '✗ Turno rechazado',
            "El turno de <strong>{$datos_turno['nombre_cliente']}</strong> fue rechazado. Se notificó a la clienta.",
            false
        );
    }

    // ── Helpers privados ─────────────────────────────────────────────────────

    private static function validarToken(int $turno_id, string $token): ?string
    {
        if (!$turno_id || !$token) {
            return 'Solicitud inválida.';
        }

        $turno = get_post($turno_id);
        if (!$turno || $turno->post_type !== 'turno_cliente') {
            return 'Turno no encontrado.';
        }

        $token_guardado = get_post_meta($turno_id, 'rdt_aprobacion_token', true);
        $expiry         = (int) get_post_meta($turno_id, 'rdt_aprobacion_token_expiry', true);

        if (!$token_guardado || !hash_equals($token_guardado, $token)) {
            return 'Token inválido o ya utilizado.';
        }

        if ($expiry < time()) {
            return 'Este enlace expiró. El turno deberá gestionarse desde el panel.';
        }

        $estado_actual = get_post_meta($turno_id, 'estado_turno', true);
        if ($estado_actual !== TurnoEstado::PENDIENTE) {
            return 'Este turno ya fue procesado anteriormente.';
        }

        return null;
    }

    /**
     * Obtiene todos los datos del turno necesarios para las notificaciones.
     * Centraliza la resolución del campo centro_estetico_id (ACF puede
     * devolver objeto, array serializado o entero).
     *
     * Usa la clave 'nombre_servicio' (terminología canónica del dominio).
     */
    private static function getDatosTurno(int $turno_id): array
    {
        // El meta_key en la BD es 'servicio_id'.
        $servicio_bd_id  = (int) get_post_meta($turno_id, 'servicio_id', true);
        $nombre_servicio = get_the_title($servicio_bd_id) ?: 'el servicio';

        $centro_raw = get_post_meta($turno_id, 'centro_estetico_id', true);
        if (is_object($centro_raw) && isset($centro_raw->ID)) {
            $centro_id = $centro_raw->ID;
        } elseif (is_array($centro_raw)) {
            $centro_id = (int) ($centro_raw[0] ?? 0);
        } elseif (is_string($centro_raw) && str_starts_with($centro_raw, 'a:')) {
            $decoded   = @unserialize($centro_raw);
            $centro_id = is_array($decoded) ? (int) ($decoded[0] ?? 0) : 0;
        } elseif (is_string($centro_raw) && str_starts_with($centro_raw, 'O:')) {
            $decoded   = @unserialize($centro_raw);
            $centro_id = ($decoded instanceof \WP_Post) ? (int) $decoded->ID : 0;
        } else {
            $centro_id = (int) $centro_raw;
        }

        return [
            'email_cliente'    => (string) get_post_meta($turno_id, 'email_cliente',  true),
            'nombre_cliente'   => (string) get_post_meta($turno_id, 'nombre_cliente', true),
            'nombre_servicio'  => $nombre_servicio,
            'fecha'            => (string) get_post_meta($turno_id, 'fecha',          true),
            'hora_inicio'      => (string) get_post_meta($turno_id, 'hora_inicio',    true),
            'hora_fin'         => (string) get_post_meta($turno_id, 'hora_fin',       true),
            'token_turno'      => (string) get_post_meta($turno_id, 'token_turno',    true),
            'centro_id'        => $centro_id,
            'telefono_centro'  => (string) get_post_meta($centro_id, 'telefono',  true),
            'direccion_centro' => (string) get_post_meta($centro_id, 'direccion', true),
            'email_centro'     => (string) get_post_meta($centro_id, 'email',     true),
        ];
    }

    private static function responderHtml(string $titulo, string $mensaje, bool $exito): void
    {
        $color     = $exito ? '#2e7d32' : '#c62828';
        $icono     = $exito ? '✓' : '✗';
        $url_panel = home_url('/my-account/panel-centro/');

        status_header(200);
        header('Content-Type: text/html; charset=UTF-8');

        echo <<<HTML
        <!DOCTYPE html>
        <html lang="es">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <title>{$titulo}</title>
            <style>
                body { font-family: Arial, sans-serif; background: #f4f4f4; display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; }
                .card { background: #fff; border-radius: 12px; box-shadow: 0 4px 20px rgba(0,0,0,.1); padding: 40px 36px; max-width: 480px; width: 100%; text-align: center; }
                .icono { font-size: 3rem; color: {$color}; margin-bottom: 16px; }
                h1 { color: {$color}; font-size: 1.4rem; margin: 0 0 12px; }
                p { color: #555; line-height: 1.6; margin: 0 0 24px; }
                a { display: inline-block; padding: 11px 24px; background: #4A7A84; color: #fff; border-radius: 8px; text-decoration: none; font-weight: bold; font-size: 0.92rem; }
                a:hover { background: #3a6470; }
            </style>
        </head>
        <body>
            <div class="card">
                <div class="icono">{$icono}</div>
                <h1>{$titulo}</h1>
                <p>{$mensaje}</p>
                <a href="{$url_panel}">Ir al panel de agenda</a>
            </div>
        </body>
        </html>
        HTML;

        exit;
    }
}
