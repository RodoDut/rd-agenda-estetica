<?php

declare(strict_types=1);
namespace RDT\CentrosEstetica\Api;

use WP_REST_Request;
use WP_REST_Response;
use RDT\CentrosEstetica\Repositories\ServiciosClientesRepository;
use function RDT\CentrosEstetica\Helpers\get_centro_by_user;

/**
 * ServiciosClientesController
 *
 * CRUD REST para que cada centro gestione sus propios servicios 
 * (anteriormente tratamientos).
 */
final class ServiciosClientesController
{
    public static function register(): void
    {
        register_rest_route('rdt/v1', '/centro/servicios', [
            [
                'methods'             => 'GET',
                'callback'            => [self::class, 'listar'],
                'permission_callback' => [self::class, 'checkPermission'],
            ],
            [
                'methods'             => 'POST',
                'callback'            => [self::class, 'crear'],
                'permission_callback' => [self::class, 'checkPermission'],
            ],
        ]);

        register_rest_route('rdt/v1', '/centro/servicios/(?P<id>\d+)', [
            [
                'methods'             => 'PUT',
                'callback'            => [self::class, 'actualizar'],
                'permission_callback' => [self::class, 'checkPermission'],
                'args'                => self::argsId(),
            ],
            [
                'methods'             => 'DELETE',
                'callback'            => [self::class, 'eliminar'],
                'permission_callback' => [self::class, 'checkPermission'],
                'args'                => self::argsId(),
            ],
        ]);
    }

    public static function checkPermission(): bool
    {
        if (!is_user_logged_in()) return false;
        $user = wp_get_current_user();
        return in_array('centro_estetico', (array) $user->roles, true);
    }

    // ── GET /centro/servicios ────────────────────────────────────────────────

    public static function listar(WP_REST_Request $request): WP_REST_Response
    {
        $centro_id = get_centro_by_user(get_current_user_id());
        if (!$centro_id) {
            return new WP_REST_Response(['error' => 'No tenés un centro estético asociado.'], 403);
        }

        $servicios = ServiciosClientesRepository::findByCentro($centro_id);
        $limite    = ServiciosClientesRepository::LIMITE_POR_CENTRO;
        $cantidad  = count($servicios);

        return new WP_REST_Response([
            'servicios'     => $servicios,
            'total'         => $cantidad,
            'limite'        => $limite,
            'puede_agregar' => $cantidad < $limite,
        ], 200);
    }

    // ── POST /centro/servicios ───────────────────────────────────────────────

    public static function crear(WP_REST_Request $request): WP_REST_Response
    {
        $centro_id = get_centro_by_user(get_current_user_id());
        if (!$centro_id) {
            return new WP_REST_Response(['error' => 'No tenés un centro estético asociado.'], 403);
        }

        $json = $request->get_json_params();

        $nombre   = sanitize_text_field($json['nombre']   ?? '');
        $duracion = (int) ($json['duracion_servicio']     ?? 0);
        $detalle  = sanitize_textarea_field($json['detalle_servicio'] ?? '');
        $categoria = sanitize_text_field($json['categoria_servicio'] ?? '');

        if (empty(trim($nombre)) || $duracion <= 0) {
            return new WP_REST_Response(['error' => 'Nombre y duración son obligatorios.'], 400);
        }

        $result = ServiciosClientesRepository::crear($centro_id, $nombre, $duracion, $detalle, $categoria);

        if (is_wp_error($result)) {
            $status = $result->get_error_data()['status'] ?? 400;
            return new WP_REST_Response(['error' => $result->get_error_message()], $status);
        }

        return new WP_REST_Response(['success' => true, 'id' => $result], 201);
    }

    // ── PUT /centro/servicios/{id} ───────────────────────────────────────────

    public static function actualizar(WP_REST_Request $request): WP_REST_Response
    {
        $centro_id  = get_centro_by_user(get_current_user_id());
        $servicio_id = (int) $request->get_param('id');

        if (!$centro_id) return new WP_REST_Response(['error' => 'Acceso denegado'], 403);

        $json = $request->get_json_params();
        $nombre   = sanitize_text_field($json['nombre']      ?? '');
        $duracion = (int) ($json['duracion_servicio']        ?? 0);
        $detalle  = sanitize_textarea_field($json['detalle_servicio'] ?? '');
        $categoria = sanitize_text_field($json['categoria_servicio'] ?? '');

        $result = ServiciosClientesRepository::actualizar($servicio_id, $centro_id, $nombre, $duracion, $detalle, $categoria);

        if (is_wp_error($result)) {
            return new WP_REST_Response(['error' => $result->get_error_message()], 403);
        }

        return new WP_REST_Response(['success' => true], 200);
    }

    // ── DELETE /centro/servicios/{id} ────────────────────────────────────────

    public static function eliminar(WP_REST_Request $request): WP_REST_Response
    {
        $centro_id  = get_centro_by_user(get_current_user_id());
        $servicio_id = (int) $request->get_param('id');

        if (!$centro_id) return new WP_REST_Response(['error' => 'Acceso denegado'], 403);

        $result = ServiciosClientesRepository::eliminar($servicio_id, $centro_id);

        if (is_wp_error($result)) {
            return new WP_REST_Response(['error' => $result->get_error_message()], 403);
        }

        return new WP_REST_Response(['success' => true], 200);
    }

    private static function argsId(): array
    {
        return [
            'id' => [
                'required'          => true,
                'validate_callback' => fn($v) => is_numeric($v) && (int)$v > 0,
                'sanitize_callback' => 'absint',
            ],
        ];
    }
}