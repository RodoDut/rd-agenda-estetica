<?php
namespace RDT\CentrosEstetica\Repositories;

use RDT\CentrosEstetica\Domain\JornadaEstado;

class JornadaCentroRepository
{
    /**
     * Obtiene una jornada válida a partir de un token público y el estado actual de la jornada (activa).
     * Luego retorna un objeto con los datos mínimos.
     */
    public static function fromToken(string $token): ?object
    {
        if (!$token) {
            return null;
        }

       $posts = get_posts([
        'post_type'   => 'jornada_centro',
        'numberposts' => 1,
        'meta_query'  => [
            'relation' => 'AND',
            [
                'key'   => 'token_publico',
                'value' => $token,
            ],        
        ],
    ]);

      
//Verificamos que $posts[0] sea un objeto WP_Post para evitar non-object error.
        if (empty($posts) || !($posts[0] instanceof \WP_Post)) {
            error_log('JornadaCentroRepository::fromToken - No se encontró jornada para el token ' . $token);
            return null;
        }
        
            $id = $posts[0]->ID;
        
        // Obtengo los datos ACF mínimos de la jornada.
        // OJO: Un campo de tipo Post Object de ACF puede devolver el objeto WP_Post completo.
        // Si se hace un cast (int) de un objeto, el resultado es 1. Por eso es importante
        // verificar si es un objeto y obtener el ID, o si ya es un ID.
        $centro_id_value = function_exists('get_field') ? get_field('centro_estetico_id', $id) : get_post_meta($id, 'centro_estetico_id', true);
        if (is_object($centro_id_value) && isset($centro_id_value->ID)) {
            $centro_id = $centro_id_value->ID;
        } else {
            $centro_id = (int) $centro_id_value;
        }

        return (object) [
            'id'                 => $id,
            'centro_estetico_id' => $centro_id,
            'ssa_reserva_id'     => (int) get_post_meta($id, 'ssa_reserva_id', true),
            'fecha'              => get_post_meta($id, 'fecha', true),
            'hora_inicio'        => get_post_meta($id, 'hora_inicio', true),
            'hora_fin'           => get_post_meta($id, 'hora_fin', true),
            'jornada_estado'     => get_post_meta($id, 'jornada_estado', true),
        ];
    }

/*
    Verifica si existe una jornada para un centro estético en una fecha específica.
    Esto es útil para evitar crear jornadas duplicadas para el mismo centro y fecha.
*/
    /**
     * Obtiene el ssa_reserva_id de la jornada asociada a un centro y fecha.
     * Retorna 0 si no existe o no tiene reserva SSA asociada.
     */
    public function obtenerSsaReservaId(int $centro_id, string $fecha): int
    {
        $posts = get_posts([
            'post_type'      => 'jornada_centro',
            'numberposts'    => 1,
            'meta_query'     => [
                'relation' => 'AND',
                ['key' => 'fecha', 'value' => $fecha],
            ],
        ]);

        if (empty($posts) || !($posts[0] instanceof \WP_Post)) {
            return 0;
        }

        // Verificamos el centro resolviendo el posible array serializado de ACF
        $raw = get_post_meta($posts[0]->ID, 'centro_estetico_id', true);
        if (is_array($raw)) {
            $id_jornada_centro = (int) ($raw[0] ?? 0);
        } elseif (is_object($raw) && isset($raw->ID)) {
            $id_jornada_centro = $raw->ID;
        } else {
            $id_jornada_centro = (int) $raw;
        }

        if ($id_jornada_centro !== $centro_id) {
            return 0;
        }

        return (int) get_post_meta($posts[0]->ID, 'ssa_reserva_id', true);
    }

    /**
     * Busca una jornada por su ID de reserva en SSA.
     * Retorna un objeto con los datos mínimos o null si no existe.
     */
    public function findBySsaReservaId(int $ssa_reserva_id): ?object
    {
        $posts = get_posts([
            'post_type'      => 'jornada_centro',
            'numberposts'    => 1,
            'meta_query'     => [
                ['key' => 'ssa_reserva_id', 'value' => $ssa_reserva_id],
            ],
        ]);

        if (empty($posts) || !($posts[0] instanceof \WP_Post)) {
            return null;
        }

        $id  = $posts[0]->ID;
        $raw = get_post_meta($id, 'centro_estetico_id', true);

        if (is_array($raw)) {
            $centro_id = (int) ($raw[0] ?? 0);
        } elseif (is_object($raw) && isset($raw->ID)) {
            $centro_id = $raw->ID;
        } else {
            $centro_id = (int) $raw;
        }

        return (object) [
            'id'                 => $id,
            'centro_estetico_id' => $centro_id,
            'fecha'              => get_post_meta($id, 'fecha', true),
            'hora_inicio'        => get_post_meta($id, 'hora_inicio', true),
            'hora_fin'           => get_post_meta($id, 'hora_fin', true),
            'jornada_estado'     => get_post_meta($id, 'jornada_estado', true),
            'token_publico'      => get_post_meta($id, 'token_publico', true),
        ];
    }

    /**
     * Actualiza la fecha y horario de una jornada existente.
     */
    public function actualizarFechaYHorario(int $jornada_id, string $fecha, string $hora_inicio, string $hora_fin): void
    {
        update_post_meta($jornada_id, 'fecha',       $fecha);
        update_post_meta($jornada_id, 'hora_inicio', $hora_inicio);
        update_post_meta($jornada_id, 'hora_fin',    $hora_fin);

        wp_update_post([
            'ID'         => $jornada_id,
            'post_title' => 'Jornada ' . $fecha,
        ]);

        error_log("JornadaCentroRepository: Jornada ID {$jornada_id} actualizada a fecha={$fecha} {$hora_inicio}-{$hora_fin}.");
    }

    /**
     * Busca la jornada activa de un centro para una fecha específica.
     * Retorna un objeto con los datos mínimos o null si no existe.
     * Usado por TurnoCreator en modo interno para resolver el contexto.
     */
    public function findActivaByCentroYFecha(int $centro_id, string $fecha): ?object
    {
        $posts = get_posts([
            'post_type'      => 'jornada_centro',
            'posts_per_page' => -1,
            'meta_query'     => [
                'relation' => 'AND',
                ['key' => 'fecha',          'value' => $fecha],
                ['key' => 'jornada_estado', 'value' => JornadaEstado::ACTIVA],
            ],
        ]);

        if (empty($posts)) {
            return null;
        }

        foreach ($posts as $post) {
            $raw = get_post_meta($post->ID, 'centro_estetico_id', true);

            if (is_array($raw)) {
                $id = (int) ($raw[0] ?? 0);
            } elseif (is_object($raw) && isset($raw->ID)) {
                $id = $raw->ID;
            } else {
                $id = (int) $raw;
            }

            if ($id !== $centro_id) {
                continue;
            }

            return (object) [
                'id'          => $post->ID,
                'hora_inicio' => get_post_meta($post->ID, 'hora_inicio', true),
                'hora_fin'    => get_post_meta($post->ID, 'hora_fin', true),
            ];
        }

        return null;
    }

    public function existe(int $centro_estetico_id, string $fecha): bool
    {
        $posts = get_posts([
            'post_type'   => 'jornada_centro',
            'meta_query'  => [
                [
                    'key'   => 'centro_estetico_id',    //Asociado al token en ssa-hooks.php
                    'value' => $centro_estetico_id,
                ],
                [
                    'key'   => 'fecha',
                    'value' => $fecha,
                ],
            ],
            'numberposts' => 1,
        ]);

        return !empty($posts);
    }

    public function marcarComoCompletada(string $token): void
    {
        $jornada = self::fromToken($token);
        if(!$jornada){
            return;
        }
        update_post_meta($jornada->id, 'jornada_estado', JornadaEstado::COMPLETADA);
    }

    public function marcarComoCancelada(string $token): void
    {
        $jornada = self::fromToken($token);
        if(!$jornada){
            return;
        }
        update_post_meta($jornada->id, 'jornada_estado', JornadaEstado::CANCELADA);
    }

    public function marcarComoActiva(string $token): void
    {
        $jornada = self::fromToken($token);
        if(!$jornada){
            return;
        }
        update_post_meta($jornada->id, 'jornada_estado', JornadaEstado::ACTIVA);
    }

    public function estadoJornada(string $token): ?string
    {
        $jornada = self::fromToken($token);
        if(!$jornada){
            return null;
        }
        return $jornada->jornada_estado;
    }

    /**
     * Si la jornada asociada a un centro y fecha estaba COMPLETADA,
     * y la fecha de la jornada es posterior a hoy, la reactiva.
     * Se llama tras cancelar un turno para liberar la jornada si correspondía.
     */
    public function reactivarSiCorresponde(int $centro_id, string $fecha_turno): void
    {
        // El campo centro_estetico_id puede estar serializado como array por ACF
        // (ej: a:1:{i:0;s:4:"8847";}) por lo que no podemos filtrarlo directamente
        // en la meta_query. Buscamos por fecha y estado, y verificamos el centro en PHP.
        $posts = get_posts([
            'post_type'      => 'jornada_centro',
            'posts_per_page' => -1,
            'meta_query'     => [
                'relation' => 'AND',
                ['key' => 'fecha',          'value' => $fecha_turno],
                ['key' => 'jornada_estado', 'value' => JornadaEstado::COMPLETADA],
            ],
        ]);

        if (empty($posts)) {
            error_log('JornadaCentroRepository::reactivarSiCorresponde - No se encontró jornada COMPLETADA para fecha=' . $fecha_turno);
            return;
        }

        $hoy = current_time('Y-m-d');

        if ($fecha_turno <= $hoy) {
            error_log('JornadaCentroRepository::reactivarSiCorresponde - Jornada no reactivada. Fecha ' . $fecha_turno . ' no es futura.');
            return;
        }

        foreach ($posts as $post) {
            $raw = get_post_meta($post->ID, 'centro_estetico_id', true);

            // ACF puede guardar el valor como array serializado, como objeto WP_Post,
            // o como entero simple, dependiendo de la configuración del campo.
            if (is_array($raw)) {
                $id_jornada = (int) ($raw[0] ?? 0);
            } elseif (is_object($raw) && isset($raw->ID)) {
                $id_jornada = $raw->ID;
            } else {
                $id_jornada = (int) $raw;
            }

            if ($id_jornada !== $centro_id) {
                continue;
            }

            update_post_meta($post->ID, 'jornada_estado', JornadaEstado::ACTIVA);
            error_log('JornadaCentroRepository::reactivarSiCorresponde - Jornada ID ' . $post->ID . ' reactivada tras cancelación de turno.');
            return;
        }

        error_log('JornadaCentroRepository::reactivarSiCorresponde - No se encontró jornada COMPLETADA para centro_id=' . $centro_id . ' fecha=' . $fecha_turno);
    }

    // -------------------------------------------------------------------------
    // Métodos añadidos para soportar JornadaExpirador
    // -------------------------------------------------------------------------

    /**
     * Devuelve todas las jornadas ACTIVAS de un centro estético.
     * Incluye solo los campos necesarios para que JornadaExpirador
     * pueda determinar si cada jornada ya venció.
     *
     * NOTA sobre el filtro de centro_estetico_id:
     * ACF puede guardar este campo como array serializado, objeto WP_Post o entero,
     * por lo que NO podemos filtrarlo de forma confiable en la meta_query de WordPress.
     * Lo filtramos en PHP después de obtener los resultados, igual que hacemos
     * en reactivarSiCorresponde() y findActivaByCentroYFecha().
     *
     * @param int $centro_id
     * @return object[] Array de objetos con: id, fecha, hora_fin
     */
    public function findActivasByCentro(int $centro_id): array
    {
        $posts = get_posts([
            'post_type'      => 'jornada_centro',
            'posts_per_page' => -1,
            'meta_query'     => [
                ['key' => 'jornada_estado', 'value' => JornadaEstado::ACTIVA],
            ],
        ]);

        if (empty($posts)) {
            return [];
        }

        $resultado = [];

        foreach ($posts as $post) {
            $raw = get_post_meta($post->ID, 'centro_estetico_id', true);

            if (is_array($raw)) {
                $id_centro = (int) ($raw[0] ?? 0);
            } elseif (is_object($raw) && isset($raw->ID)) {
                $id_centro = $raw->ID;
            } else {
                $id_centro = (int) $raw;
            }

            if ($id_centro !== $centro_id) {
                continue;
            }

            $resultado[] = (object) [
                'id'       => $post->ID,
                'fecha'    => get_post_meta($post->ID, 'fecha', true),
                'hora_fin' => get_post_meta($post->ID, 'hora_fin', true),
            ];
        }

        return $resultado;
    }

    /**
     * Marca una jornada como EXPIRADA directamente por su ID.
     * A diferencia de marcarComoCompletada/Cancelada (que usan token),
     * este método usa el ID porque JornadaExpirador ya tiene el objeto
     * completo de la jornada y no necesita una segunda búsqueda por token.
     *
     * @param int $jornada_id ID del CPT jornada_centro
     */
    public function marcarComoExpirada(int $jornada_id): void
    {
        update_post_meta($jornada_id, 'jornada_estado', JornadaEstado::EXPIRADA);
    }
}
