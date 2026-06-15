<?php

declare(strict_types=1);
namespace RDT\CentrosEstetica\Api;

/*
Controlador REST para el calendario del panel del centro estético.
Recibe centro_id y fecha, busca la jornada de ese día, y devuelve
todos los slots (ocupados y libres) con sus datos para renderizar
el calendario en el frontend.
*/

use WP_REST_Request;
use WP_REST_Response;
use RDT\CentrosEstetica\Repositories\JornadaCentroRepository;
use RDT\CentrosEstetica\Repositories\TurnoClienteRepository;
use RDT\CentrosEstetica\Repositories\ServiciosClientesRepository;
use RDT\CentrosEstetica\Domain\TurnoEstado;
use RDT\CentrosEstetica\Services\JornadaExpirador;
use function RDT\CentrosEstetica\Helpers\get_centro_by_user;

final class CalendarioController
{
    public static function register(): void
    {
        // Endpoint para obtener las fechas con jornadas activas del centro.
        // El JS lo llama al inicializar para saber entre qué fechas puede navegar.
        // Endpoint para obtener la lista de tratamientos disponibles.
        // Usado por el modal de reserva interna del calendario.
        register_rest_route('rdt/v1', '/servicios', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'handleTratamientos'],
            'permission_callback' => [self::class, 'check_permission'],
        ]);

        register_rest_route('rdt/v1', '/calendario/jornadas', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'handleJornadas'],
            'permission_callback' => [self::class, 'check_permission'],
        ]);

        register_rest_route('rdt/v1', '/calendario', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'handle'],
            'permission_callback' => [self::class, 'check_permission'],
        ]);
    }

    /**
     * Solo usuarios logueados pueden acceder a los endpoints del calendario.
     */
    public static function check_permission(WP_REST_Request $request): bool
    {
        return is_user_logged_in();
    }

    /**
     * Resuelve el centro_id del usuario logueado actualmente.
     * Retorna null si el usuario no tiene un centro asociado.
     *
     * SEGURIDAD: nunca usamos el centro_id que viene como parámetro
     * de la URL porque el usuario podría manipularlo para acceder
     * a la agenda de otro centro. Siempre lo derivamos del usuario
     * autenticado en el servidor.
     */
    private static function resolverCentroDelUsuario(): ?int
    {
        return get_centro_by_user(get_current_user_id());
    }

    /**
     * Construye e inyecta el servicio JornadaExpirador con su dependencia.
     * Al centralizarlo aquí evitamos repetir la instanciación en cada método.
     */
    private static function crearExpirador(): JornadaExpirador
    {
        return new JornadaExpirador(new JornadaCentroRepository());
    }

    /**
     * Devuelve la lista de servicios disponibles para el modal de reserva interna.
     */
    public static function handleTratamientos(WP_REST_Request $request): WP_REST_Response
    {
        $centro_id = self::resolverCentroDelUsuario();

        if (!$centro_id) {
            return new WP_REST_Response(['error' => 'No tenés un centro estético asociado.'], 403);
        }

        //Filtro los servicios que pertenecen al centro del usuario autenticado para evitar que un centro vea los servicios de otro.
        $servicios = ServiciosClientesRepository::findByCentro($centro_id);

//Devuelvo los tratamientos correspondientes al centro autenticado, con su id y nombre. El frontend necesita el id para crear el turno y el nombre para mostrarlo en el modal.        
        $tratamientos = array_map(fn($s) => [
            'id'     => $s->id,
            'nombre' => $s->nombre,
        ], $servicios);

        return new WP_REST_Response(['tratamientos' => $tratamientos], 200);
    }

    /**
     * Devuelve el array de fechas (YYYY-MM-DD) que tienen jornadas activas
     * para el centro solicitado, ordenadas cronológicamente.
     * El frontend lo usa para restringir la navegación solo a esas fechas.
     *
     * Antes de consultar, ejecuta JornadaExpirador para que cualquier
     * jornada cuyo horario ya pasó quede marcada como EXPIRADA y no
     * aparezca en la lista que se devuelve al frontend.
     */
    public static function handleJornadas(WP_REST_Request $request): WP_REST_Response
    {
        $centro_id = self::resolverCentroDelUsuario();

        if (!$centro_id) {
            return new WP_REST_Response(['error' => 'No tenés un centro estético asociado a tu cuenta.'], 403);
        }

        // Expirar jornadas vencidas antes de consultar.
        // De esta forma, la lista que devolvemos al frontend solo contiene
        // jornadas realmente activas. El cambio es "lazy": ocurre la próxima
        // vez que el centro abre su panel, no en un proceso de fondo.
        self::crearExpirador()->expirarVencidas($centro_id);

        $posts = get_posts([
            'post_type'      => 'jornada_centro',
            'posts_per_page' => -1,
            'meta_query'     => [
                'relation' => 'AND',
                ['key' => 'jornada_estado', 'value' => \RDT\CentrosEstetica\Domain\JornadaEstado::ACTIVA],
            ],
            // Ordenamos por fecha ASC para que el JS pueda usarlas directamente
            'meta_key'       => 'fecha',
            'orderby'        => 'meta_value',
            'order'          => 'ASC',
        ]);

        $fechas = [];

        foreach ($posts as $post) {
            // Verificamos que la jornada pertenece al centro correcto
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

            $fecha = get_post_meta($post->ID, 'fecha', true);
            if ($fecha) {
                $fechas[] = $fecha;
            }
        }

        // Eliminamos duplicados y reindexamos por si acaso
        $fechas = array_values(array_unique($fechas));

        return new WP_REST_Response(['fechas' => $fechas], 200);
    }

    public static function handle(WP_REST_Request $request): WP_REST_Response
    {
        $centro_id = self::resolverCentroDelUsuario();
        $fecha     = sanitize_text_field($request->get_param('fecha'));

        // --- Validación ---
        if (!$centro_id) {
            return new WP_REST_Response([
                'error' => 'No tenés un centro estético asociado a tu cuenta.'
            ], 403);
        }

        if (!$fecha) {
            return new WP_REST_Response([
                'error' => 'Parámetro fecha requerido.'
            ], 400);
        }

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) {
            return new WP_REST_Response([
                'error' => 'Formato de fecha inválido. Use YYYY-MM-DD.'
            ], 400);
        }

        // Expirar jornadas vencidas antes de buscar la jornada del día.
        // Esto garantiza que si el centro navega directamente a una fecha
        // cuya jornada ya venció, el estado que devolvemos es el correcto.
        self::crearExpirador()->expirarVencidas($centro_id);

        // --- Buscar la jornada del centro para esa fecha ---
        // Necesitamos hora_inicio y hora_fin para construir los slots del día.
        // Si no hay jornada, el día no tiene agenda habilitada.
        $jornada = self::obtenerJornadaPorCentroYFecha($centro_id, $fecha);

        if (!$jornada) {
            return new WP_REST_Response([
                'fecha'          => $fecha,
                'jornada_estado' => 'sin_jornada',
                'mensaje'        => 'No hay jornada registrada para este día.',
                'slots'          => [],
            ], 200);
        }

        // --- Obtener todos los turnos del día (cualquier estado) ---
        $turnoRepo = new TurnoClienteRepository();
        $turnos    = $turnoRepo->findByCentroYFecha($centro_id, $fecha);

        // Leer metas directamente de la BD (bypaseando object cache / Redis)
        // para garantizar que el estado sea siempre el real y no un valor
        // cacheado de una request anterior.
        global $wpdb;

        // Construimos un mapa post_id => [meta_key => meta_value] en una sola query
        $ids_turnos  = array_map(fn($t) => $t->ID, $turnos);
        $metas_fresh = [];

        if (!empty($ids_turnos)) {
            $placeholders = implode(',', array_fill(0, count($ids_turnos), '%d'));
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT post_id, meta_key, meta_value
                     FROM {$wpdb->postmeta}
                     WHERE post_id IN ({$placeholders})
                       AND meta_key IN ('hora_inicio','hora_fin','estado_turno',
                                        'nombre_cliente','email_cliente',
                                        'telefono_cliente','servicio_id',
                                        'centro_estetico_id','recordatorio_enviado')",
                    ...$ids_turnos
                )
            );

            foreach ($rows as $row) {
                $metas_fresh[$row->post_id][$row->meta_key] = $row->meta_value;
            }
        }

        // Helper para leer una meta del mapa fresco
        $meta = fn(int $id, string $key) => $metas_fresh[$id][$key] ?? '';

        // Indexamos los turnos por hora_inicio para acceso O(1) al construir slots
        $turnos_por_hora = [];
        foreach ($turnos as $turno) {
            $hora_inicio = $meta($turno->ID, 'hora_inicio');
            $estado      = $meta($turno->ID, 'estado_turno');

            $servicio_id = (int) $meta($turno->ID, 'servicio_id');

            $servicio         = ServiciosClientesRepository::find($servicio_id);
            $nombre_servicio  = $servicio ? $servicio->nombre : get_the_title($servicio_id);

            // Usamos un array simple en lugar de indexar por hora para evitar que 
            // un turno activo sobreescriba a uno cancelado en el mismo horario.
            $turnos_por_hora[] = [
                'turno_id'         => $turno->ID,
                'nombre_cliente'   => $meta($turno->ID, 'nombre_cliente'),
                'email_cliente'    => $meta($turno->ID, 'email_cliente'),
                'telefono_cliente' => $meta($turno->ID, 'telefono_cliente'),
                'tratamiento'      => $nombre_servicio,
                'hora_inicio'      => $hora_inicio,
                'hora_fin'         => $meta($turno->ID, 'hora_fin'),
                'estado'           => $estado,
                'recordatorio_enviado' => $meta($turno->ID, 'recordatorio_enviado'),
            ];
        }

        // --- Construir grilla de slots cada 30 minutos ---
        $slots     = self::construirSlots($jornada->hora_inicio, $jornada->hora_fin, $turnos_por_hora);

        return new WP_REST_Response([
            'fecha'          => $fecha,
            'jornada_id'     => $jornada->id,
            'jornada_estado' => $jornada->jornada_estado,
            'hora_inicio'    => $jornada->hora_inicio,
            'hora_fin'       => $jornada->hora_fin,
            'slots'          => $slots,
            '_v'             => 'wpdb-direct-2025',  // marcador de version — eliminar tras confirmar
        ], 200);
    }

    /**
     * Construye la grilla de slots de la jornada.
     *
     * Los turnos se muestran en su hora exacta.
     * Los huecos entre turnos se agrupan en un único bloque libre
     * (ej: 'Libre 09:00 – 10:30') en lugar de generar una fila por cada
     * slot de 5 minutos, lo que haría la lista muy larga e ilegible.
     */
    private static function construirSlots(
        string $hora_inicio,
        string $hora_fin,
        array  $turnos_por_hora
    ): array {
        $slots  = [];
        $inicio = strtotime($hora_inicio);
        $fin    = strtotime($hora_fin);

        // Ordenamos los turnos cronológicamente por hora_inicio
        $turnos_ordenados = array_values($turnos_por_hora);
        usort($turnos_ordenados, fn($a, $b) => strtotime($a['hora_inicio']) <=> strtotime($b['hora_inicio']));

        $cursor = $inicio;

        foreach ($turnos_ordenados as $turno) {
            $turno_inicio = strtotime($turno['hora_inicio']);
            $turno_fin    = strtotime($turno['hora_fin']);
            $estado       = $turno['estado'];

            // Definimos qué estados se consideran "ocupantes" del tiempo real.
            // Los cancelados o rechazados no deberían bloquear la generación de un slot "Libre".
            $es_ocupante = !in_array($estado, [TurnoEstado::CANCELADO, TurnoEstado::RECHAZADO], true);

            // Si hay un hueco antes del turno, lo agrupamos en un único bloque libre
            if ($cursor < $turno_inicio) {
                $slots[] = [
                    'hora'     => date('H:i', $cursor),
                    'hora_fin' => date('H:i', $turno_inicio),
                    'estado'   => 'libre',
                ];
            }

            // Agregar el turno ocupado en su hora exacta
            $slots[] = [
                'hora'             => $turno['hora_inicio'],
                'hora_fin'         => $turno['hora_fin'],
                'estado'           => 'ocupado',
                'turno_id'         => $turno['turno_id'],
                'nombre_cliente'   => $turno['nombre_cliente'],
                'email_cliente'    => $turno['email_cliente'],
                'telefono_cliente' => $turno['telefono_cliente'],
                'tratamiento'      => $turno['tratamiento'],
                'estado_turno'     => $turno['estado'],
                'recordatorio_enviado' => $turno['recordatorio_enviado'],
            ];

            // Solo avanzamos el cursor si el turno realmente ocupa el espacio.
            if ($es_ocupante && $turno_fin > $cursor) {
                $cursor = $turno_fin;
            }
        }

        // Si hay un hueco después del último turno hasta el fin de la jornada
        if ($cursor < $fin) {
            $slots[] = [
                'hora'     => date('H:i', $cursor),
                'hora_fin' => date('H:i', $fin),
                'estado'   => 'libre',
            ];
        }

        return $slots;
    }

    /**
     * Busca la jornada de un centro para una fecha específica.
     * Retorna un objeto con los datos mínimos o null si no existe.
     *
     * IMPORTANTE: este método no filtra por jornada_estado intencionalmente,
     * para que el centro pueda ver también jornadas expiradas o canceladas
     * si navega a esas fechas desde el historial. El estado correcto ya fue
     * actualizado por JornadaExpirador antes de llamar a este método.
     */
    private static function obtenerJornadaPorCentroYFecha(int $centro_id, string $fecha): ?object
    {
        $posts = get_posts([
            'post_type'      => 'jornada_centro',
            'posts_per_page' => 1,
            'meta_query'     => [
                'relation' => 'AND',
                ['key' => 'fecha', 'value' => $fecha],
            ],
        ]);

        if (empty($posts)) {
            return null;
        }

        foreach ($posts as $post) {
            // Verificamos que la jornada pertenece al centro correcto
            // (ACF puede devolver objeto, array o entero)
            $raw = get_post_meta($post->ID, 'centro_estetico_id', true);

            if (is_array($raw)) {
                $id_jornada_centro = (int) ($raw[0] ?? 0);
            } elseif (is_object($raw) && isset($raw->ID)) {
                $id_jornada_centro = $raw->ID;
            } else {
                $id_jornada_centro = (int) $raw;
            }

            if ($id_jornada_centro !== $centro_id) {
                continue;
            }

            return (object) [
                'id'             => $post->ID,
                'jornada_estado' => get_post_meta($post->ID, 'jornada_estado', true),
                'hora_inicio'    => get_post_meta($post->ID, 'hora_inicio', true),
                'hora_fin'       => get_post_meta($post->ID, 'hora_fin', true),
            ];
        }

        return null;
    }
}
