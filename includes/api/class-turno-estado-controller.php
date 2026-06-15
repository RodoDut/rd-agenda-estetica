<?php

declare(strict_types=1);
namespace RDT\CentrosEstetica\Api;

use WP_REST_Request;
use WP_REST_Response;
use RDT\CentrosEstetica\Domain\TurnoEstado;
use RDT\CentrosEstetica\Repositories\TurnoClienteRepository;
use RDT\CentrosEstetica\Notifications\TurnoEstadoMailer;
use function RDT\CentrosEstetica\Helpers\get_centro_by_user;

/**
 * TurnoEstadoController
 *
 * Endpoint PATCH /rdt/v1/turno/{id}/estado
 *
 * Permite al centro estético cambiar el estado de un turno de sus clientes
 * desde el calendario del panel. Al cambiar el estado, notifica al cliente
 * via email sobre el nuevo estado de su turno.
 *
 * SEGURIDAD:
 * - Solo usuarios logueados con rol centro_estetico pueden operar.
 * - El centro_id se deriva del usuario autenticado, nunca del payload.
 * - Se verifica que el turno pertenezca al centro antes de modificarlo.
 *
 * REGLAS DE NEGOCIO:
 * - EXPIRADO no puede asignarse manualmente (es un estado de sistema).
 * - Si el turno ya pasó (fecha+hora en el pasado), solo se permiten estados
 *   de cierre: cancelado, completado, ausente, rechazado.
 *   No se puede volver a activo o pendiente porque esos implican un futuro.
 */
final class TurnoEstadoController
{
    /**
     * Estados que el centro puede asignar manualmente desde el calendario.
     * EXPIRADO queda fuera: lo asigna exclusivamente el sistema (JornadaExpirador).
     */
    private const ESTADOS_PERMITIDOS = [
        TurnoEstado::APROBADO,
        TurnoEstado::PENDIENTE,
        TurnoEstado::CANCELADO,
        TurnoEstado::RECHAZADO,
        TurnoEstado::COMPLETADO,
        TurnoEstado::AUSENTE,
    ];

    /**
     * Estados válidos para un turno cuya fecha/hora ya pasó.
     * No tiene sentido marcar como activo o pendiente un turno que ya ocurrió.
     */
    private const ESTADOS_PERMITIDOS_EN_PASADO = [
        TurnoEstado::CANCELADO,
        TurnoEstado::RECHAZADO,
        TurnoEstado::COMPLETADO,
        TurnoEstado::AUSENTE,
    ];

    public static function register(): void
    {
        register_rest_route('rdt/v1', '/turno/(?P<id>\d+)/estado', [
            'methods'             => 'PATCH',
            'callback'            => [self::class, 'handle'],
            'permission_callback' => [self::class, 'checkPermission'],
            'args'                => [
                'id' => [
                    'required'          => true,
                    'validate_callback' => fn($v) => is_numeric($v) && (int)$v > 0,
                    'sanitize_callback' => 'absint',
                ],
            ],
        ]);

        register_rest_route('rdt/v1', '/turno/(?P<id>\d+)/recordatorio', [
            'methods'             => 'PATCH',
            'callback'            => [self::class, 'handleRecordatorio'],
            'permission_callback' => [self::class, 'checkPermission'],
            'args'                => [
                'id' => [
                    'required'          => true,
                    'validate_callback' => fn($v) => is_numeric($v) && (int)$v > 0,
                    'sanitize_callback' => 'absint',
                ],
            ],
        ]);
    }

    public static function checkPermission(): bool
    {
        if (!is_user_logged_in()) {
            return false;
        }
        $user = wp_get_current_user();
        return in_array('centro_estetico', (array) $user->roles, true);
    }

    /**
     * Maneja la petición para registrar el envío de un recordatorio de WhatsApp.
     */
    public static function handleRecordatorio(WP_REST_Request $request): WP_REST_Response
    {
        $turno_id  = (int) $request->get_param('id');
        $centro_id = get_centro_by_user(get_current_user_id());

        if (!$centro_id) {
            return new WP_REST_Response(['error' => 'No tenés un centro estético asociado.'], 403);
        }

        $turno = get_post($turno_id);
        if (!$turno || $turno->post_type !== 'turno_cliente') {
            return new WP_REST_Response(['error' => 'Turno no encontrado.'], 404);
        }

        // Reutilizamos lógica de validación de pertenencia
        if (!self::verificarPertenencia($turno_id, $centro_id)) {
            return new WP_REST_Response(['error' => 'No tenés permiso para modificar este turno.'], 403);
        }

        $repo = new TurnoClienteRepository();
        $repo->marcarRecordatorioEnviado($turno_id);

        return new WP_REST_Response(['success' => true], 200);
    }

    public static function handle(WP_REST_Request $request): WP_REST_Response
    {
        $turno_id     = (int) $request->get_param('id');
        $json         = $request->get_json_params();
        $nuevo_estado = sanitize_text_field($json['estado'] ?? '');

        if ($nuevo_estado === TurnoEstado::EXPIRADO) {
            return new WP_REST_Response([
                'error' => 'El estado "expirado" es asignado automáticamente por el sistema y no puede modificarse manualmente.',
            ], 400);
        }

        if (!in_array($nuevo_estado, self::ESTADOS_PERMITIDOS, true)) {
            return new WP_REST_Response([
                'error' => 'Estado no válido. Los estados permitidos son: activo, pendiente, cancelado, rechazado, completado, ausente.',
            ], 400);
        }

        $centro_id = get_centro_by_user(get_current_user_id());
        if (!$centro_id) {
            return new WP_REST_Response(['error' => 'No tenés un centro estético asociado.'], 403);
        }

        $turno = get_post($turno_id);
        if (!$turno || $turno->post_type !== 'turno_cliente') {
            return new WP_REST_Response(['error' => 'Turno no encontrado.'], 404);
        }

        if (!self::verificarPertenencia($turno_id, $centro_id)) {
            return new WP_REST_Response(['error' => 'No tenés permiso para modificar este turno.'], 403);
        }

        $estado_anterior = (string) get_post_meta($turno_id, 'estado_turno', true);
        if ($estado_anterior === $nuevo_estado) {
            return new WP_REST_Response([
                'success'  => true,
                'turno_id' => $turno_id,
                'estado'   => $nuevo_estado,
                'mensaje'  => 'El turno ya tenía ese estado.',
            ], 200);
        }

        $fecha_turno = (string) get_post_meta($turno_id, 'fecha', true);
        $hora_inicio = (string) get_post_meta($turno_id, 'hora_inicio', true);

        if ($fecha_turno && $hora_inicio) {
            $ts_turno = strtotime("{$fecha_turno} {$hora_inicio}");
            $ts_ahora = current_time('timestamp');

            if ($ts_turno <= $ts_ahora && !in_array($nuevo_estado, self::ESTADOS_PERMITIDOS_EN_PASADO, true)) {
                return new WP_REST_Response([
                    'error' => 'Este turno ya ocurrió. Solo podés marcarlo como: cancelado, rechazado, completado o ausente.',
                ], 422);
            }
        }

        $repo = new TurnoClienteRepository();
        $repo->actualizarEstado($turno_id, $nuevo_estado);

        $mailer = new TurnoEstadoMailer();
        $mailer->notificarCambioEstado($turno_id, $centro_id, $nuevo_estado);

        return new WP_REST_Response([
            'success'  => true,
            'turno_id' => $turno_id,
            'estado'   => $nuevo_estado,
        ], 200);
    }

    /**
     * Helper para verificar que el turno pertenece al centro logueado.
     */
    private static function verificarPertenencia(int $turno_id, int $centro_id): bool
    {
        $raw = get_post_meta($turno_id, 'centro_estetico_id', true);

        if (is_array($raw)) {
            $turno_centro_id = (int) ($raw[0] ?? 0);
        } elseif (is_object($raw) && isset($raw->ID)) {
            $turno_centro_id = (int) $raw->ID;
        } elseif (is_string($raw) && str_starts_with($raw, 'a:')) {
            $decoded         = @unserialize($raw);
            $turno_centro_id = is_array($decoded) ? (int) ($decoded[0] ?? 0) : 0;
        } elseif (is_string($raw) && str_starts_with($raw, 'O:')) {
            $decoded         = @unserialize($raw);
            $turno_centro_id = ($decoded instanceof \WP_Post) ? (int) $decoded->ID : 0;
        } else {
            $turno_centro_id = (int) $raw;
        }

        error_log("TurnoEstadoController: turno_centro_id resuelto = {$turno_centro_id}, centro logueado = {$centro_id}");

        return $turno_centro_id === $centro_id;
    }
}
