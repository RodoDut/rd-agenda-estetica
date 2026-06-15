<?php

declare(strict_types=1);
namespace RDT\CentrosEstetica\Api;

use WP_REST_Request;
use WP_REST_Response;
use RDT\CentrosEstetica\Repositories\TratamientoRepository;
use function RDT\CentrosEstetica\Helpers\get_centro_by_user;

/**
 * TratamientoCentroController
 *
 * CRUD REST para que cada centro gestione sus propios tratamientos.
 *
 * Endpoints:
 *   GET    /rdt/v1/centro/tratamientos         → lista los tratamientos del centro logueado
 *   POST   /rdt/v1/centro/tratamientos         → crea un nuevo tratamiento (límite: 10)
 *   PUT    /rdt/v1/centro/tratamientos/{id}    → edita nombre, duración y detalle
 *   DELETE /rdt/v1/centro/tratamientos/{id}    → elimina un tratamiento
 *
 * Seguridad:
 *   - Solo usuarios con rol centro_estetico pueden operar.
 *   - El centro_id siempre se deriva del usuario autenticado.
 *   - Antes de editar/eliminar se verifica que el tratamiento pertenezca al centro.
 */
final class TratamientoCentroController
{
    public static function register(): void
    {
        register_rest_route('rdt/v1', '/centro/tratamientos', [
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

        register_rest_route('rdt/v1', '/centro/tratamientos/(?P<id>\d+)', [
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

    // ── GET /centro/tratamientos ──────────────────────────────────────────────

    public static function listar(WP_REST_Request $request): WP_REST_Response
    {
        $centro_id = self::getCentroId();
        if (!$centro_id) {
            return new WP_REST_Response(['error' => 'No tenés un centro estético asociado.'], 403);
        }

        $tratamientos = TratamientoRepository::findByCentro($centro_id);
        $limite       = TratamientoRepository::LIMITE_POR_CENTRO;
        $cantidad     = count($tratamientos);

        return new WP_REST_Response([
            'tratamientos' => array_map(fn($t) => [
                'id'      => $t->id,
                'nombre'  => $t->nombre,
                'duracion' => $t->duracion,
                'detalle'  => $t->detalle,
            ], $tratamientos),
            'total'  => $cantidad,
            'limite' => $limite,
            'puede_agregar' => $cantidad < $limite,
        ], 200);
    }

    // ── POST /centro/tratamientos ─────────────────────────────────────────────

    public static function crear(WP_REST_Request $request): WP_REST_Response
    {
        $centro_id = self::getCentroId();
        if (!$centro_id) {
            return new WP_REST_Response(['error' => 'No tenés un centro estético asociado.'], 403);
        }

        $json = $request->get_json_params();

        $nombre   = sanitize_text_field($json['nombre']   ?? '');
        $duracion = (int) ($json['duracion']              ?? 0);
        $detalle  = sanitize_textarea_field($json['detalle'] ?? '');

        $error = self::validarCampos($nombre, $duracion);
        if ($error) {
            return new WP_REST_Response(['error' => $error], 400);
        }

        $result = TratamientoRepository::crear($centro_id, $nombre, $duracion, $detalle);

        if (is_wp_error($result)) {
            $status = $result->get_error_data()['status'] ?? 400;
            return new WP_REST_Response(['error' => $result->get_error_message()], $status);
        }

        $tratamiento = TratamientoRepository::find($result);

        return new WP_REST_Response([
            'success'     => true,
            'tratamiento' => [
                'id'      => $tratamiento->id,
                'nombre'  => $tratamiento->nombre,
                'duracion' => $tratamiento->duracion,
                'detalle'  => $tratamiento->detalle,
            ],
        ], 201);
    }

    // ── PUT /centro/tratamientos/{id} ─────────────────────────────────────────

    public static function actualizar(WP_REST_Request $request): WP_REST_Response
    {
        $centro_id     = self::getCentroId();
        $tratamiento_id = (int) $request->get_param('id');

        if (!$centro_id) {
            return new WP_REST_Response(['error' => 'No tenés un centro estético asociado.'], 403);
        }

        $json = $request->get_json_params();

        $nombre   = sanitize_text_field($json['nombre']      ?? '');
        $duracion = (int) ($json['duracion']                 ?? 0);
        $detalle  = sanitize_textarea_field($json['detalle'] ?? '');

        $error = self::validarCampos($nombre, $duracion);
        if ($error) {
            return new WP_REST_Response(['error' => $error], 400);
        }

        $result = TratamientoRepository::actualizar($tratamiento_id, $centro_id, $nombre, $duracion, $detalle);

        if (is_wp_error($result)) {
            $status = $result->get_error_data()['status'] ?? 400;
            return new WP_REST_Response(['error' => $result->get_error_message()], $status);
        }

        return new WP_REST_Response([
            'success'     => true,
            'tratamiento' => [
                'id'      => $tratamiento_id,
                'nombre'  => $nombre,
                'duracion' => $duracion,
                'detalle'  => $detalle,
            ],
        ], 200);
    }

    // ── DELETE /centro/tratamientos/{id} ──────────────────────────────────────

    public static function eliminar(WP_REST_Request $request): WP_REST_Response
    {
        $centro_id     = self::getCentroId();
        $tratamiento_id = (int) $request->get_param('id');

        if (!$centro_id) {
            return new WP_REST_Response(['error' => 'No tenés un centro estético asociado.'], 403);
        }

        $result = TratamientoRepository::eliminar($tratamiento_id, $centro_id);

        if (is_wp_error($result)) {
            $status = $result->get_error_data()['status'] ?? 400;
            return new WP_REST_Response(['error' => $result->get_error_message()], $status);
        }

        return new WP_REST_Response(['success' => true, 'eliminado' => $tratamiento_id], 200);
    }

    // ── Helpers privados ──────────────────────────────────────────────────────

    private static function getCentroId(): ?int
    {
        return get_centro_by_user(get_current_user_id());
    }

    private static function validarCampos(string $nombre, int $duracion): ?string
    {
        if (empty(trim($nombre))) {
            return 'El nombre del tratamiento es obligatorio.';
        }
        if (strlen($nombre) > 120) {
            return 'El nombre no puede superar los 120 caracteres.';
        }
        if ($duracion <= 0) {
            return 'La duración debe ser mayor a 0 minutos.';
        }
        if ($duracion > 480) {
            return 'La duración no puede superar las 8 horas (480 minutos).';
        }
        return null;
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
