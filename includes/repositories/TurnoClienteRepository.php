<?php
namespace RDT\CentrosEstetica\Repositories;

use WP_Error;
use RDT\CentrosEstetica\Domain\TurnoEstado;

class TurnoClienteRepository
{
    /**
     * Crea un nuevo turno en la base de datos.
     *
     * Contrato del array $data:
     *   - centro_estetico_id  int     ID del CPT centro_estetico
     *   - fecha               string  YYYY-MM-DD
     *   - hora_inicio         string  HH:MM
     *   - hora_fin            string  HH:MM
     *   - duracion            int     minutos
     *   - servicio_id         int     ID del CPT servicios_clientes
     *   - nombre_cliente      string
     *   - email_cliente       string
     *   - telefono_cliente    string
     *   - token_turno         string  UUID
     *   - estado_turno        string  TurnoEstado::*
     */
    public function crear(array $data): int|WP_Error
    {
        error_log('TurnoClienteRepository::crear - Creating turno with data: (datos sensibles ocultos por seguridad)');

        $servicio_id = (int) ($data['servicio_id'] ?? 0);

        if (!$servicio_id) {
            error_log('TurnoClienteRepository::crear - ADVERTENCIA: servicio_id no recibido o es 0.');
        }

        $post_id = wp_insert_post([
            'post_type'   => 'turno_cliente',
            'post_status' => 'publish',
            'post_title'  => sprintf('Turno %s %s', $data['fecha'], $data['hora_inicio']),
            'meta_input'  => [
                'centro_estetico_id'   => $data['centro_estetico_id'],
                'fecha'                => $data['fecha'],
                'hora_inicio'          => $data['hora_inicio'],
                'hora_fin'             => $data['hora_fin'],
                'duracion'             => $data['duracion'],
                'servicio_id'          => $servicio_id,
                'nombre_cliente'       => $data['nombre_cliente'],
                'email_cliente'        => $data['email_cliente'],
                'telefono_cliente'     => $data['telefono_cliente'],
                'token_turno'          => $data['token_turno'],
                'estado_turno'         => $data['estado_turno'],
                'recordatorio_enviado' => 0,
            ],
        ]);

        if (is_wp_error($post_id) || !$post_id) {
            return new WP_Error('turno_no_creado', 'No se pudo registrar el turno');
        }

        // ACF requiere update_field para guardar correctamente los campos de tipo relación.
        if (function_exists('update_field')) {
            update_field('centro_estetico_id', $data['centro_estetico_id'], $post_id);
        }

        return $post_id;
    }

    public function findByToken(string $token): ?\WP_Post
    {
        $posts = get_posts([
            'post_type'   => 'turno_cliente',
            'numberposts' => 1,
            'meta_query'  => [
                ['key' => 'token_turno', 'value' => $token],
            ],
        ]);

        if (empty($posts) || !($posts[0] instanceof \WP_Post)) {
            return null;
        }

        return $posts[0];
    }

    /**
     * Marca un turno con el estado de recordatorio enviado.
     */
    public function marcarRecordatorioEnviado(int $turno_id): void
    {
        update_post_meta($turno_id, 'recordatorio_enviado', '1');

        if (function_exists('update_field')) {
            update_field('recordatorio_enviado', true, $turno_id);
        }

        clean_post_cache($turno_id);
        wp_cache_delete($turno_id, 'post_meta');
    }

    public function actualizarEstado(int $turno_id, string $estado): void
    {
        update_post_meta($turno_id, 'estado_turno', $estado);
        clean_post_cache($turno_id);
        wp_cache_delete($turno_id, 'post_meta');
    }

    /**
     * Devuelve todos los turnos (cualquier estado) de un centro en una fecha dada.
     * Usado por el calendario del panel para mostrar la grilla del día.
     *
     * IMPORTANTE: No filtramos por centro_estetico_id en la meta_query porque ACF
     * puede serializar ese campo de formas distintas. Filtramos en PHP después.
     *
     * @return \WP_Post[]
     */
    public function findByCentroYFecha(int $centro_id, string $fecha): array
    {
        $todos = get_posts([
            'post_type'              => 'turno_cliente',
            'posts_per_page'         => -1,
            'meta_query'             => [
                ['key' => 'fecha', 'value' => $fecha],
            ],
            'cache_results'          => false,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
            'no_found_rows'          => true,
        ]);

        return array_filter($todos, function (\WP_Post $turno) use ($centro_id) {
            $raw = get_post_meta($turno->ID, 'centro_estetico_id', true);

            if (is_array($raw)) {
                $id = (int) ($raw[0] ?? 0);
            } elseif (is_object($raw) && isset($raw->ID)) {
                $id = $raw->ID;
            } else {
                $id = (int) $raw;
            }

            return $id === $centro_id;
        });
    }

    /**
     * Devuelve todos los turnos APROBADOS de un centro en una fecha dada.
     * Usado para cancelar turnos en masa cuando se cancela o reagenda una jornada.
     *
     * @return \WP_Post[]
     */
    public function findAprobadosByCentroYFecha(int $centro_id, string $fecha): array
    {
        $todos = get_posts([
            'post_type'      => 'turno_cliente',
            'posts_per_page' => -1,
            'meta_query'     => [
                'relation' => 'AND',
                ['key' => 'fecha',        'value' => $fecha],
                ['key' => 'estado_turno', 'value' => TurnoEstado::APROBADO],
            ],
        ]);

        return array_values(array_filter($todos, function (\WP_Post $turno) use ($centro_id) {
            $raw = get_post_meta($turno->ID, 'centro_estetico_id', true);

            if (is_array($raw)) {
                $id = (int) ($raw[0] ?? 0);
            } elseif (is_object($raw) && isset($raw->ID)) {
                $id = $raw->ID;
            } else {
                $id = (int) $raw;
            }

            return $id === $centro_id;
        }));
    }
}
