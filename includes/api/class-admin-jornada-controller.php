<?php

declare(strict_types=1);
namespace RDT\CentrosEstetica\Api;

use WP_REST_Request;
use WP_REST_Response;
use RDT\CentrosEstetica\Services\JornadaCreator;

/**
 * AdminJornadaController
 *
 * Endpoints exclusivos para administradores del sitio.
 *
 * GET  /rdt/v1/admin/centros
 *      Lista todos los usuarios con rol centro_estetico o centro_estetico_premium
 *      para poblar el selector del shortcode.
 *
 * GET  /rdt/v1/admin/reserva-form
 *      Renderiza el HTML del shortcode [reserva_jornada] con centro_user_id especificado.
 *
 * POST /rdt/v1/admin/jornada (obsoleto, mantenido por compatibilidad)
 *      Crea una jornada_centro para cualquier centro, delegando en JornadaCreator.
 *
 * SEGURIDAD: ambos endpoints requieren capacidad 'manage_options' (administrador).
 * Nunca son accesibles para usuarios con rol centro_estetico.
 */
final class AdminJornadaController
{
    /** Roles que se consideran "centro estético" en el sistema. */
    private const ROLES_CENTRO = ['centro_estetico', 'centro_estetico_premium'];

    public static function register(): void
    {
        register_rest_route('rdt/v1', '/admin/centros', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'handleCentros'],
            'permission_callback' => [self::class, 'checkPermission'],
        ]);

        register_rest_route('rdt/v1', '/admin/reserva-form', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'handleReservaForm'],
            'permission_callback' => [self::class, 'checkPermission'],
        ]);

        register_rest_route('rdt/v1', '/admin/jornada', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'handleCrearJornada'],
            'permission_callback' => [self::class, 'checkPermission'],
        ]);
    }

    public static function checkPermission(): bool
    {
        return current_user_can('manage_options');
    }

    /**
     * Devuelve la lista de centros registrados con su wp_user_id y nombre,
     * para poblar el selector del formulario admin.
     */
    public static function handleCentros(WP_REST_Request $request): WP_REST_Response
    {
        $usuarios = get_users([
            'role__in' => self::ROLES_CENTRO,
            'orderby'  => 'display_name',
            'order'    => 'ASC',
            'fields'   => ['ID', 'display_name', 'user_email'],
        ]);

        $centros = array_map(fn($u) => [
            'wp_user_id'   => (int) $u->ID,
            'nombre'       => $u->display_name,
            'email'        => $u->user_email,
        ], $usuarios);

        return new WP_REST_Response(['centros' => $centros], 200);
    }

    /**
     * Renderiza el HTML del shortcode [reserva_jornada] con centro_user_id especificado.
     *
     * Query params:
     *   centro_user_id: int — ID del usuario centro_estetico
     *   redirect_url: string — opcional, URL de redirect
     */
    public static function handleReservaForm(WP_REST_Request $request): WP_REST_Response
    {
        $centro_user_id = (int) $request->get_param('centro_user_id');
        $redirect_url   = $request->get_param('redirect_url') ?: home_url('/admin-jornada-feedback/');

        if (!$centro_user_id) {
            return new WP_REST_Response(['error' => 'Falta centro_user_id.'], 400);
        }

        // Verificar que el usuario tiene rol de centro estético
        $usuario = get_userdata($centro_user_id);
        if (!$usuario) {
            return new WP_REST_Response(['error' => 'Usuario no encontrado.'], 404);
        }

        $roles_usuario = (array) $usuario->roles;
        if (!array_intersect($roles_usuario, self::ROLES_CENTRO)) {
            return new WP_REST_Response(['error' => 'El usuario no tiene un rol de centro estético.'], 422);
        }

        // Renderizar el shortcode
        $html = do_shortcode('[reserva_jornada centro_user_id="' . $centro_user_id . '" redirect_url="' . esc_url($redirect_url) . '"]');

        return new WP_REST_Response(['html' => $html], 200);
    }

    /**
     * Crea una jornada_centro para el centro indicado.
     *
     * Payload JSON esperado:
     *   {
     *     "wp_user_id":  int,    — ID del usuario WordPress del centro
     *     "fecha":       string, — Y-m-d
     *     "hora_inicio": string, — H:i
     *     "hora_fin":    string  — H:i
     *   }
     */
    public static function handleCrearJornada(WP_REST_Request $request): WP_REST_Response
    {
        $json = $request->get_json_params();

        $wp_user_id  = (int)    ($json['wp_user_id']  ?? 0);
        $fecha       = sanitize_text_field($json['fecha']       ?? '');
        $hora_inicio = sanitize_text_field($json['hora_inicio'] ?? '');
        $hora_fin    = sanitize_text_field($json['hora_fin']    ?? '');

        // Validaciones básicas
        if (!$wp_user_id || !$fecha || !$hora_inicio || !$hora_fin) {
            return new WP_REST_Response(['error' => 'Faltan campos requeridos: wp_user_id, fecha, hora_inicio, hora_fin.'], 400);
        }

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) {
            return new WP_REST_Response(['error' => 'Formato de fecha inválido. Use YYYY-MM-DD.'], 400);
        }

        if (!preg_match('/^\d{2}:\d{2}$/', $hora_inicio) || !preg_match('/^\d{2}:\d{2}$/', $hora_fin)) {
            return new WP_REST_Response(['error' => 'Formato de hora inválido. Use HH:MM.'], 400);
        }

        if ($hora_fin <= $hora_inicio) {
            return new WP_REST_Response(['error' => 'La hora de fin debe ser posterior a la hora de inicio.'], 400);
        }

        // Verificar que el usuario tiene rol de centro estético
        $usuario = get_userdata($wp_user_id);
        if (!$usuario) {
            return new WP_REST_Response(['error' => 'Usuario no encontrado.'], 404);
        }

        $roles_usuario = (array) $usuario->roles;
        if (!array_intersect($roles_usuario, self::ROLES_CENTRO)) {
            return new WP_REST_Response(['error' => 'El usuario no tiene un rol de centro estético.'], 422);
        }

        // Crear la jornada via el servicio
        $creator    = new JornadaCreator();
        $jornada_id = $creator->crear(
            $wp_user_id,
            $fecha,
            $hora_inicio,
            $hora_fin,
            0,    // sin ssa_reserva_id (creación manual)
            true  // sí enviar email al centro
        );

        if (is_wp_error($jornada_id)) {
            $data = $jornada_id->get_error_data();
            $status = (int) ($data['status'] ?? 500);

            // Para duplicados, devolvemos el ID existente para que el admin pueda verlo
            if ($jornada_id->get_error_code() === 'jornada_duplicada') {
                return new WP_REST_Response([
                    'error'      => $jornada_id->get_error_message(),
                    'jornada_id' => $data['jornada_id'] ?? null,
                ], $status);
            }

            return new WP_REST_Response(['error' => $jornada_id->get_error_message()], $status);
        }

        $token      = get_post_meta($jornada_id, 'token_publico', true);
        $url_agenda = home_url('reserva-turno-depilacion') . '/?token=' . $token;

        return new WP_REST_Response([
            'success'    => true,
            'jornada_id' => $jornada_id,
            'token'      => $token,
            'url_agenda' => $url_agenda,
            'centro'     => $usuario->display_name,
            'fecha'      => $fecha,
            'hora_inicio' => $hora_inicio,
            'hora_fin'   => $hora_fin,
        ], 201);
    }
}
