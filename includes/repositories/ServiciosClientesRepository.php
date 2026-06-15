<?php

declare(strict_types=1);

namespace RDT\CentrosEstetica\Repositories;

/**
 * ServiciosClientesRepository
 *
 * Acceso a datos del CPT servicios_clientes.
 * Cada servicio pertenece a un centro estético específico (meta: centro_estetico_id).
 * Límite: 10 servicios por centro.
 */
class ServiciosClientesRepository
{
    public const LIMITE_POR_CENTRO = 10;
    private const POST_TYPE = 'servicios_clientes';

    // ── Lectura ───────────────────────────────────────────────────────────────

    /**
     * Obtiene datos mínimos de un servicio por ID.
     */
    public static function find(int $id): ?object
    {
        if (!$id) {
            return null;
        }

        $post = get_post($id);

        if (!$post || $post->post_type !== self::POST_TYPE) {
            return null;
        }

        $duracion = (int) get_post_meta($id, 'duracion_servicio', true);

        if (!$duracion) {
            return null;
        }

        return (object) [
            'id'       => $id,
            'nombre'   => $post->post_title,
            'duracion' => $duracion,
            'detalle'  => (string) get_post_meta($id, 'detalle_servicio', true),
            'categoria' => (string) get_post_meta($id, 'categoria_servicio', true),
        ];
    }

    /**
     * Devuelve la duración en minutos de un servicio.
     */
    public function obtenerDuracion(int $id): int|null
    {
        $servicio = self::find($id);
        return $servicio ? $servicio->duracion : null;
    }

    /**
     * Devuelve todos los servicios de un centro estético específico,
     * ordenados alfabéticamente por nombre.
     */
    public static function findByCentro(int $centro_id): array
    {
        $posts = get_posts([
            'post_type'      => self::POST_TYPE,
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'orderby'        => 'title',
            'order'          => 'ASC',
            'meta_query'     => [
                [
                    'key'   => 'centro_estetico_id',
                    'value' => $centro_id,
                    'type'  => 'NUMERIC',
                ],
            ],
        ]);

        return array_map(fn(\WP_Post $p) => (object) [
            'id'       => $p->ID,
            'nombre'   => $p->post_title,
            'duracion' => (int) get_post_meta($p->ID, 'duracion_servicio', true),
            'detalle'  => (string) get_post_meta($p->ID, 'detalle_servicio', true),
            'categoria' => (string) get_post_meta($p->ID, 'categoria_servicio', true),
        ], $posts);
    }

    /**
     * Cuenta cuántos servicios tiene un centro para validar límites.
     */
    public static function contarPorCentro(int $centro_id): int
    {
        $posts = get_posts([
            'post_type'      => self::POST_TYPE,
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'meta_query'     => [
                ['key' => 'centro_estetico_id', 'value' => $centro_id, 'type' => 'NUMERIC'],
            ],
        ]);

        return count($posts);
    }

    public static function perteneceACentro(int $id, int $centro_id): bool
    {
        $raw = get_post_meta($id, 'centro_estetico_id', true);
        $id_centro_meta = is_object($raw) ? $raw->ID : (is_array($raw) ? ($raw[0] ?? 0) : (int) $raw);

        return $id_centro_meta === $centro_id;
    }

    // ── Escritura ─────────────────────────────────────────────────────────────

    public static function crear(int $centro_id, string $nombre, int $duracion, string $detalle = '', string $categoria = ''): int|\WP_Error
    {
        if (self::contarPorCentro($centro_id) >= self::LIMITE_POR_CENTRO) {
            return new \WP_Error('limite_alcanzado', 'Alcanzaste el límite máximo de ' . self::LIMITE_POR_CENTRO . ' servicios.', ['status' => 422]);
        }

        $post_id = wp_insert_post([
            'post_type'   => self::POST_TYPE,
            'post_title'  => $nombre,
            'post_status' => 'publish',
            'meta_input'  => [
                'centro_estetico_id' => $centro_id,
                'duracion_servicio'  => $duracion,
                'detalle_servicio'   => $detalle,
                'categoria_servicio' => $categoria,
            ],
        ], true);

        if (is_wp_error($post_id)) {
            return $post_id;
        }

        if (function_exists('update_field')) {
            update_field('centro_estetico_id', $centro_id, $post_id);
            update_field('duracion_servicio', $duracion, $post_id);
            update_field('categoria_servicio', $categoria, $post_id);
            update_field('detalle_servicio', $detalle, $post_id);
        }

        return $post_id;
    }

    public static function actualizar(int $id, int $centro_id, string $nombre, int $duracion, string $detalle = '', string $categoria = ''): true|\WP_Error
    {
        if (!self::perteneceACentro($id, $centro_id)) {
            return new \WP_Error('sin_permiso', 'No tenés permiso para modificar este servicio.', ['status' => 403]);
        }

        wp_update_post(['ID' => $id, 'post_title' => $nombre], true);
        update_post_meta($id, 'duracion_servicio', $duracion);
        update_post_meta($id, 'detalle_servicio', $detalle);
        update_post_meta($id, 'categoria_servicio', $categoria);

        return true;
    }

    public static function eliminar(int $id, int $centro_id): true|\WP_Error
    {
        if (!self::perteneceACentro($id, $centro_id)) {
            return new \WP_Error('sin_permiso', 'No tenés permiso para eliminar este servicio.', ['status' => 403]);
        }

        $deleted = wp_delete_post($id, true);
        return $deleted ? true : new \WP_Error('error_eliminar', 'No se pudo eliminar el servicio.', ['status' => 500]);
    }
}