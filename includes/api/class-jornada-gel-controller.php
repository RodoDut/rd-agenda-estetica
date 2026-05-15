<?php

declare(strict_types=1);
namespace RDT\CentrosEstetica\Api;

use WP_REST_Request;
use WP_REST_Response;
use function RDT\CentrosEstetica\Helpers\get_centro_by_user;
use RDT\CentrosEstetica\Notifications\SsaBookingMail;

/**
 * JornadaGelController
 *
 * Endpoints para la funcionalidad de oferta del pote de gel post-reserva SSA.
 *
 * GET  /rdt/v1/jornada/pendiente
 *      Devuelve los datos de la jornada recién creada (jornada_id, fechas, token).
 *      El JS los consulta cuando SSA muestra la pantalla de confirmación.
 *      Consume el transient al leerlo.
 *
 * POST /rdt/v1/jornada/confirmar
 *      Punto de cierre del flujo: recibe si el centro solicitó gel (bool),
 *      marca el campo ACF 'solicito_gel' en la jornada y envía el email de
 *      confirmación de jornada con ese dato incluido.
 *      Se llama desde el JS tanto si el centro acepta como si rechaza la oferta,
 *      garantizando que el email siempre se envíe al terminar el popup.
 *
 * PATCH /rdt/v1/jornada/{id}/solicito-gel (OBSOLETO)
 *      Endpoint legacy. Ya no se usa, pero se mantiene por compatibilidad.
 *      Verifica que la jornada pertenezca al centro del usuario autenticado.
 *
 * SEGURIDAD:
 * - Solo usuarios logueados con rol centro_estetico pueden operar.
 * - El POST verifica que la jornada pertenezca al centro del usuario autenticado.
 */
final class JornadaGelController
{
    public static function register(): void
    {
        register_rest_route('rdt/v1', '/jornada/pendiente', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'handlePendiente'],
            'permission_callback' => [self::class, 'checkPermission'],
        ]);

        register_rest_route('rdt/v1', '/jornada/confirmar', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'handleConfirmar'],
            'permission_callback' => [self::class, 'checkPermission'],
        ]);

        // El endpoint para marcar solicito_gel = true es PATCH /rdt/v1/jornada/{id}/solicito-gel
        register_rest_route('rdt/v1', '/jornada/(?P<id>\d+)/solicito-gel', [
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
    }

    //
    public static function checkPermission(): bool
    {
        if (!is_user_logged_in()) {
            return false;
        }
        // Admins pueden operar en nombre de un centro (flujo [admin_crear_jornada]).
        // Centros estéticos pueden operar sus propias jornadas.
        if (current_user_can('manage_options')) {
            return true;
        }
        $user = wp_get_current_user();
        return in_array('centro_estetico', (array) $user->roles, true)
            || in_array('centro_estetico_premium', (array) $user->roles, true);
    }

    /**
     * Devuelve los datos de la jornada pendiente desde el transient.
     * El hook ssa/appointment/booked los guarda durante 60 segundos.
     * Consume el transient al leerlo para que no persista innecesariamente.
     */
    public static function handlePendiente(WP_REST_Request $request): WP_REST_Response
    {
        $user_id       = get_current_user_id();
        $transient_key = 'rdt_jornada_pendiente_' . $user_id;
        $datos         = get_transient($transient_key);

        error_log('JornadaGelController::handlePendiente - user_id: ' . $user_id . ' transient_key: ' . $transient_key . ' tiene_datos: ' . ($datos ? 'si' : 'no'));

        if (!$datos || empty($datos['jornada_id'])) {
            error_log('JornadaGelController::handlePendiente - No hay datos o jornada_id vacío');
            return new WP_REST_Response(['jornada_id' => null], 200);
        }

        delete_transient($transient_key);

        return new WP_REST_Response([
            'jornada_id'  => (int) $datos['jornada_id'],
            'wp_user_id'  => (int) ($datos['wp_user_id'] ?? $user_id),
            'fecha'       => $datos['fecha']       ?? '',
            'hora_inicio' => $datos['hora_inicio'] ?? '',
            'hora_fin'    => $datos['hora_fin']    ?? '',
            'token'       => $datos['token']       ?? '',
        ], 200);
    }

    /**
     * Cierra el flujo de la oferta de gel:
     *   1. Verifica que la jornada pertenece al centro logueado.
     *   2. Si el centro aceptó el gel, marca solicito_gel = true en ACF.
     *   3. Envía el email de confirmación de jornada con el dato del gel incluido.
     *
     * Payload JSON esperado:
     *   {
     *     "jornada_id":  int,
     *     "solicito_gel": bool,
     *     "wp_user_id":  int,
     *     "fecha":       string (Y-m-d),
     *     "hora_inicio": string (H:i),
     *     "hora_fin":    string (H:i),
     *     "token":       string
     *   }
     */
    public static function handleConfirmar(WP_REST_Request $request): WP_REST_Response
    {
        $json = $request->get_json_params();

        $jornada_id   = (int)  ($json['jornada_id']   ?? 0);
        $solicito_gel = (bool) ($json['solicito_gel'] ?? false);
        $wp_user_id   = (int)  ($json['wp_user_id']   ?? 0);
        $fecha        = sanitize_text_field($json['fecha']       ?? '');
        $hora_inicio  = sanitize_text_field($json['hora_inicio'] ?? '');
        $hora_fin     = sanitize_text_field($json['hora_fin']    ?? '');
        $token        = sanitize_text_field($json['token']       ?? '');

        error_log("JornadaGelController::handleConfirmar - Payload recibido: jornada_id={$jornada_id} wp_user_id={$wp_user_id} fecha={$fecha} token={$token} solicito_gel=" . ($solicito_gel ? 'true' : 'false'));

        if (!$jornada_id || !$wp_user_id || !$fecha || !$token) {
            error_log('JornadaGelController::handleConfirmar - Datos incompletos. Abortando.');
            return new WP_REST_Response(['error' => 'Datos incompletos.'], 400);
        }

        $jornada = get_post($jornada_id);
        if (!$jornada || $jornada->post_type !== 'jornada_centro') {
            return new WP_REST_Response(['error' => 'Jornada no encontrada.'], 404);
        }

        // Verificar pertenencia de la jornada al centro.
        // Si el usuario actual es admin, usa el wp_user_id del payload para resolver el centro.
        // Si es un centro, usa su propio centro.
        if (current_user_can('manage_options')) {
            $centro_id = get_centro_by_user($wp_user_id);
            error_log("JornadaGelController::handleConfirmar - Modo admin. Buscando centro para wp_user_id={$wp_user_id}. centro_id encontrado=" . ($centro_id ?? 'null'));
        } else {
            $centro_id = get_centro_by_user(get_current_user_id());
            error_log("JornadaGelController::handleConfirmar - Modo centro. current_user_id=" . get_current_user_id() . " centro_id encontrado=" . ($centro_id ?? 'null'));
        }
        if (!$centro_id) {
            error_log('JornadaGelController::handleConfirmar - No se encontró centro. Devolviendo 403.');
            return new WP_REST_Response(['error' => 'No se encontró un centro estético asociado.'], 403);
        }

        $raw = get_post_meta($jornada_id, 'centro_estetico_id', true);
        if (is_array($raw)) {
            $jornada_centro_id = (int) ($raw[0] ?? 0);
        } elseif (is_object($raw) && isset($raw->ID)) {
            $jornada_centro_id = (int) $raw->ID;
        } elseif (is_string($raw) && str_starts_with($raw, 'a:')) {
            $decoded           = @unserialize($raw);
            $jornada_centro_id = is_array($decoded) ? (int) ($decoded[0] ?? 0) : 0;
        } else {
            $jornada_centro_id = (int) $raw;
        }

        if ($jornada_centro_id !== $centro_id) {
            return new WP_REST_Response(['error' => 'No tenés permiso para modificar esta jornada.'], 403);
        }

        // Marcar el campo ACF si el centro aceptó el gel
        if ($solicito_gel) {
            if (function_exists('update_field')) {
                update_field('solicito_gel', true, $jornada_id);
            } else {
                update_post_meta($jornada_id, 'solicito_gel', '1');
            }
            error_log("JornadaGelController: solicito_gel=true en jornada ID {$jornada_id}");
        }

        // Enviar el email de confirmación de jornada con el dato del gel.
        // Si el usuario actual es admin, también le enviamos una copia a él.
        // SsaBookingMail::enviar() acepta el email del admin como parámetro opcional.
        $email_admin = '';
        if (current_user_can('manage_options')) {
            $admin = get_userdata(get_current_user_id());
            if ($admin) {
                $email_admin = $admin->user_email;
            }
        }

        (new SsaBookingMail())->enviar(
            $wp_user_id,
            $fecha,
            $hora_inicio,
            $hora_fin,
            $token,
            false,         // $es_reprogramacion
            $solicito_gel, // $solicito_gel
            $email_admin   // copia al admin si aplica
        );
        error_log("JornadaGelController::handleConfirmar - Email SsaBookingMail enviado para wp_user_id={$wp_user_id}" . ($email_admin ? " + copia admin {$email_admin}" : ''));

        return new WP_REST_Response([
            'success'      => true,
            'jornada_id'   => $jornada_id,
            'solicito_gel' => $solicito_gel,
            'url_agenda'   => home_url('reserva-turno-depilacion') . '/?token=' . $token,
            'centro'       => get_the_title($centro_id) ?: '',
            'fecha'        => $fecha,
            'hora_inicio'  => $hora_inicio,
            'hora_fin'     => $hora_fin,
        ], 200);
    }
    /**
     * Marca solicito_gel = true en la jornada indicada (endpoint PATCH legacy).
     * Verifica que la jornada pertenezca al centro del usuario logueado.
     */
    public static function handle(WP_REST_Request $request): WP_REST_Response
    {
        $jornada_id = (int) $request->get_param('id');

        $jornada = get_post($jornada_id);
        if (!$jornada || $jornada->post_type !== 'jornada_centro') {
            return new WP_REST_Response(['error' => 'Jornada no encontrada.'], 404);
        }

        $centro_id = get_centro_by_user(get_current_user_id());
        if (!$centro_id) {
            return new WP_REST_Response(['error' => 'No tenés un centro estético asociado.'], 403);
        }

        // Resolución defensiva del centro_estetico_id (ACF puede serializar de varias formas)
        $raw = get_post_meta($jornada_id, 'centro_estetico_id', true);

        if (is_array($raw)) {
            $jornada_centro_id = (int) ($raw[0] ?? 0);
        } elseif (is_object($raw) && isset($raw->ID)) {
            $jornada_centro_id = (int) $raw->ID;
        } elseif (is_string($raw) && str_starts_with($raw, 'a:')) {
            $decoded           = @unserialize($raw);
            $jornada_centro_id = is_array($decoded) ? (int) ($decoded[0] ?? 0) : 0;
        } else {
            $jornada_centro_id = (int) $raw;
        }

        if ($jornada_centro_id !== $centro_id) {
            return new WP_REST_Response(['error' => 'No tenés permiso para modificar esta jornada.'], 403);
        }

        // Actualizar el campo ACF 'solicito_gel' a true.
        // update_field usa el slug del campo definido en ACF.
        // update_post_meta es el fallback si ACF no está activo.
        if (function_exists('update_field')) {
            update_field('solicito_gel', true, $jornada_id);
        } else {
            update_post_meta($jornada_id, 'solicito_gel', '1');
        }

        error_log("JornadaGelController: solicito_gel=true en jornada ID {$jornada_id}");

        return new WP_REST_Response(['success' => true, 'jornada_id' => $jornada_id], 200);
    }
}
