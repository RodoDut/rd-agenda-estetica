<?php
namespace RDT\CentrosEstetica\Repositories;

/**
 * TratamientoRepository
 *
 * Acceso a datos del CPT tratamiento_dep.
 * Cada tratamiento pertenece a un centro estético específico
 * (meta: centro_estetico_id). Límite: 10 tratamientos por centro.
 */
class TratamientoRepository
{
    public const LIMITE_POR_CENTRO = 10;

    // ── Lectura ───────────────────────────────────────────────────────────────

    /**
     * Obtiene datos mínimos de un tratamiento por ID.
     */
    public static function find(int $tratamiento_id): ?object
    {
        if (!$tratamiento_id) {
            return null;
        }

        $post = get_post($tratamiento_id);

        if (!$post || $post->post_type !== 'tratamiento_dep') {
            return null;
        }

        $duracion = (int) get_post_meta($tratamiento_id, 'duracion_min', true);

        if (!$duracion) {
            return null;
        }

        return (object) [
            'id'       => $tratamiento_id,
            'nombre'   => $post->post_title,
            'duracion' => $duracion,
            'detalle'  => (string) get_post_meta($tratamiento_id, 'detalle', true),
        ];
    }

    /**
     * Devuelve la duración en minutos de un tratamiento.
     */
    public function obtenerDuracion(int $tratamiento_id): int|null
    {
        $tratamiento = self::find($tratamiento_id);
        return $tratamiento ? $tratamiento->duracion : null;
    }

    /**
     * Devuelve todos los tratamientos de un centro estético específico,
     * ordenados alfabéticamente por nombre.
     *
     * @return array Array de objetos {id, nombre, duracion, detalle}
     */
    public static function findByCentro(int $centro_id): array
    {
        $posts = get_posts([
            'post_type'      => 'tratamiento_dep',
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
            'id'      => $p->ID,
            'nombre'  => $p->post_title,
            'duracion' => (int) get_post_meta($p->ID, 'duracion_min', true),
            'detalle'  => (string) get_post_meta($p->ID, 'detalle', true),
        ], $posts);
    }

    /**
     * Cuenta cuántos tratamientos tiene un centro.
     * Usado para validar el límite antes de crear uno nuevo.
     */
    public static function contarPorCentro(int $centro_id): int
    {
        $posts = get_posts([
            'post_type'      => 'tratamiento_dep',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'meta_query'     => [
                [
                    'key'   => 'centro_estetico_id',
                    'value' => $centro_id,
                    'type'  => 'NUMERIC',
                ],
            ],
        ]);

        return count($posts);
    }

    /**
     * Verifica que un tratamiento pertenezca a un centro específico.
     * Usado para autorización antes de editar o eliminar.
     */
    public static function perteneceACentro(int $tratamiento_id, int $centro_id): bool
    {
        $raw = get_post_meta($tratamiento_id, 'centro_estetico_id', true);

        if (is_array($raw)) {
            $id = (int) ($raw[0] ?? 0);
        } elseif (is_object($raw) && isset($raw->ID)) {
            $id = (int) $raw->ID;
        } else {
            $id = (int) $raw;
        }

        return $id === $centro_id;
    }

    // ── Escritura ─────────────────────────────────────────────────────────────

    /**
     * Crea un nuevo tratamiento asociado a un centro.
     *
     * @param int    $centro_id
     * @param string $nombre     Nombre del tratamiento (título del CPT)
     * @param int    $duracion   Duración en minutos
     * @param string $detalle    Descripción opcional
     * @return int|\WP_Error     ID del nuevo post o error
     */
    public static function crear(
        int    $centro_id,
        string $nombre,
        int    $duracion,
        string $detalle = ''
    ): int|\WP_Error {
        if (self::contarPorCentro($centro_id) >= self::LIMITE_POR_CENTRO) {
            return new \WP_Error(
                'limite_alcanzado',
                'Alcanzaste el límite máximo de ' . self::LIMITE_POR_CENTRO . ' tratamientos.',
                ['status' => 422]
            );
        }

        $post_id = wp_insert_post([
            'post_type'   => 'tratamiento_dep',
            'post_title'  => $nombre,
            'post_status' => 'publish',
            'meta_input'  => [
                'centro_estetico_id' => $centro_id,
                'duracion_min'       => $duracion,
                'detalle'            => $detalle,
            ],
        ], true);

        if (is_wp_error($post_id)) {
            return $post_id;
        }

        // Sincronizar con ACF si está activo
        if (function_exists('update_field')) {
            update_field('centro_estetico_id', $centro_id, $post_id);
            update_field('duracion_min',       $duracion,  $post_id);
        }

        return $post_id;
    }

    /**
     * Actualiza nombre, duración y/o detalle de un tratamiento existente.
     * Valida previamente que el tratamiento pertenezca al centro.
     */
    public static function actualizar(
        int    $tratamiento_id,
        int    $centro_id,
        string $nombre,
        int    $duracion,
        string $detalle = ''
    ): true|\WP_Error {
        if (!self::perteneceACentro($tratamiento_id, $centro_id)) {
            return new \WP_Error(
                'sin_permiso',
                'No tenés permiso para modificar este tratamiento.',
                ['status' => 403]
            );
        }

        $result = wp_update_post([
            'ID'         => $tratamiento_id,
            'post_title' => $nombre,
        ], true);

        if (is_wp_error($result)) {
            return $result;
        }

        update_post_meta($tratamiento_id, 'duracion_min', $duracion);
        update_post_meta($tratamiento_id, 'detalle',      $detalle);

        if (function_exists('update_field')) {
            update_field('duracion_min', $duracion, $tratamiento_id);
        }

        return true;
    }

    /**
     * Elimina un tratamiento. Valida previamente la pertenencia al centro.
     */
    public static function eliminar(int $tratamiento_id, int $centro_id): true|\WP_Error
    {
        if (!self::perteneceACentro($tratamiento_id, $centro_id)) {
            return new \WP_Error(
                'sin_permiso',
                'No tenés permiso para eliminar este tratamiento.',
                ['status' => 403]
            );
        }

        $deleted = wp_delete_post($tratamiento_id, true);

        if (!$deleted) {
            return new \WP_Error(
                'error_eliminar',
                'No se pudo eliminar el tratamiento.',
                ['status' => 500]
            );
        }

        return true;
    }
}
