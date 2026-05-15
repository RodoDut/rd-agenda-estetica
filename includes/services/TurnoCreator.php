<?php
namespace RDT\CentrosEstetica\Services;

use WP_Error;
use RDT\CentrosEstetica\Repositories\JornadaCentroRepository;
use RDT\CentrosEstetica\Repositories\ServiciosClientesRepository;
use RDT\CentrosEstetica\Repositories\TurnoClienteRepository;
use RDT\CentrosEstetica\Notifications\TurnoNotification;
use RDT\CentrosEstetica\Domain\TurnoEstado;
use RDT\CentrosEstetica\Notifications\TurnoAprobacionMailer;
use function RDT\CentrosEstetica\Helpers\get_telefono_centro;

class TurnoCreator
{
    private JornadaCentroRepository $jornadaRepo;
    private ServiciosClientesRepository $servicioRepo;
    private TurnoClienteRepository $turnoRepo;
    private HorariosCalculator $calculator;
    private TurnoNotification $notification;

    public function __construct(
        JornadaCentroRepository $jornadaRepo,
        ServiciosClientesRepository $servicioRepo,
        TurnoClienteRepository $turnoRepo,
        HorariosCalculator $calculator,
        TurnoNotification $notification
    ) {
        $this->jornadaRepo     = $jornadaRepo;
        $this->servicioRepo    = $servicioRepo;
        $this->turnoRepo       = $turnoRepo;
        $this->calculator      = $calculator;
        $this->notification    = $notification;
    }

    public function crear(array $data): int|WP_Error
    {
        /** 1. Resolver contexto */
        $contexto = $this->resolverContexto($data);
        if (is_wp_error($contexto)) {
            return $contexto;
        }
        error_log('TurnoCreator::crear - Contexto resuelto: ' . print_r($contexto, true));

        [$centro_estetico_id, $fecha, $hora_inicio_jornada, $hora_fin_jornada] = $contexto;

        /** 1.1 No permitir turnos en horarios pasados */
        $fechaHoraTurno = strtotime($fecha . ' ' . $data['hora_inicio']);
        $ahora          = current_time('timestamp');

        if ($fechaHoraTurno <= $ahora) {
            return new WP_Error(
                'horario_pasado',
                'No se puede reservar un turno en un horario ya pasado.',
                ['status' => 408]
            );
        }

        /** 2. Duración del servicio */
        $duracion = $this->servicioRepo->obtenerDuracion($data['servicio_id']);
        if (!$duracion) {
            return new WP_Error(
                'servicio_invalido',
                'Servicio no válido',
                ['status' => 400]
            );
        }

        /** 2.1 Validar que el servicio pertenezca al centro de la jornada */
        // Esto evita que se reserven servicios de otros centros.
        $servicio_centro_id = (int) get_post_meta($data['servicio_id'], 'centro_estetico_id', true);
        if ($servicio_centro_id !== 0 && $servicio_centro_id !== $centro_estetico_id) {
            return new WP_Error(
                'servicio_ajeno',
                'El servicio seleccionado no pertenece a este centro estético.',
                ['status' => 403]
            );
        }

        /** 3. Validar disponibilidad */
        $slots = $this->calculator->calcular(
            $fecha,
            $duracion,
            $centro_estetico_id,
            $hora_inicio_jornada,
            $hora_fin_jornada
        );

        if (!in_array($data['hora_inicio'], $slots, true)) {
            return new WP_Error(
                'horario_no_disponible',
                'El horario seleccionado ya no está disponible',
                ['status' => 409]
            );
        }

        /** 4. Calcular hora fin y generar token */
        $hora_inicio_ts = strtotime($fecha . ' ' . $data['hora_inicio']);
        $hora_fin       = date('H:i', $hora_inicio_ts + ($duracion * 60));
        $token_turno    = wp_generate_uuid4();

        /**
         * 5. Determinar estado inicial del turno.
         * Si el centro tiene activado el modo de aprobación
         * (meta 'requiere_aprobacion_turno' = '1' en el CPT centro_estetico),
         * el turno se crea como PENDIENTE y se notifica al centro para que lo apruebe.
         * También se le envía un email al cliente informando que su turno está pendiente.
         * De lo contrario, se crea como APROBADO y se notifica al cliente directamente.
         */
        $requiere_aprobacion = (get_post_meta($centro_estetico_id, 'requiere_aprobacion_turno', true) === '1');
        $estado_inicial      = $requiere_aprobacion ? TurnoEstado::PENDIENTE : TurnoEstado::APROBADO;

        /** 6. Persistir turno */
        $turno_id = $this->turnoRepo->crear([
            'centro_estetico_id' => $centro_estetico_id,
            'fecha'              => $fecha,
            'hora_inicio'        => $data['hora_inicio'],
            'hora_fin'           => $hora_fin,
            'duracion'           => $duracion,
            'servicio_id'        => $data['servicio_id'],
            'nombre_cliente'     => $data['nombre_cliente'],
            'email_cliente'      => $data['email_cliente'],
            'telefono_cliente'   => $data['telefono_cliente'],
            'token_turno'        => $token_turno,
            'estado_turno'       => $estado_inicial,
        ]);

        if (is_wp_error($turno_id)) {
            return $turno_id;
        }

        /** 7. Preparar datos para notificaciones */
        $telefono_centro = get_telefono_centro($centro_estetico_id);

        // get_the_title() devuelve el nombre del servicio directamente del CPT servicios_clientes.
        $nombre_servicio = get_the_title($data['servicio_id']) ?: 'Servicio';

        $nombre_centro = get_the_title($centro_estetico_id);

        // Fallback defensivo: get_the_title devuelve string vacío si el CPT
        // está en pending o no existe. Buscamos el post directamente.
        if (empty($nombre_centro)) {
            $post_centro   = get_post($centro_estetico_id);
            $nombre_centro = $post_centro ? $post_centro->post_title : 'Centro Estético';
        }

        /** 8. Enviar notificaciones según el flujo */
        if ($requiere_aprobacion) {
            // Flujo de aprobación: solo notificamos al centro para que apruebe o rechace.
            // Al cliente le llegará el email cuando el centro tome acción.
            $aprobacion_mailer = new TurnoAprobacionMailer();

            $aprobacion_mailer->enviarPendienteAlCentro(
                $turno_id,
                $centro_estetico_id,
                $data['nombre_cliente'],
                $data['email_cliente'],
                $data['telefono_cliente'],
                $nombre_servicio,
                $fecha,
                $data['hora_inicio']
            );

            $aprobacion_mailer->enviarPendienteAlCliente(
                $data['email_cliente'],
                $data['nombre_cliente'],
                $nombre_centro,
                $nombre_servicio,
                $fecha,
                $data['hora_inicio']
            );

        } else {
            // Flujo estándar: confirmación inmediata al cliente y aviso al centro.
            $this->notification->notificarCliente(
                $data['email_cliente'],
                $data['nombre_cliente'],
                $nombre_servicio,
                $nombre_centro,
                $telefono_centro,
                $fecha,
                $data['hora_inicio'],
                $token_turno
            );

            $this->notification->notificarCentro([
                'centro_id'      => $centro_estetico_id,
                'nombre_cliente' => $data['nombre_cliente'],
                'servicio_id'    => $data['servicio_id'],
                'fecha'          => $fecha,
                'hora_inicio'    => $data['hora_inicio'],
            ]);
        }

        /** 9. Verificar si se agotaron los turnos tras esta reserva */
        // Recalculamos disponibilidad. Si ahora está vacío, cerramos la jornada.
        $slots_restantes = $this->calculator->calcular(
            $fecha,
            $duracion,
            $centro_estetico_id,
            $hora_inicio_jornada,
            $hora_fin_jornada
        );

        if (empty($slots_restantes) && !empty($data['token'])) {
            error_log('TurnoCreator::crear - Se agotaron los turnos disponibles para centro ID ' . $centro_estetico_id . '. Cerrando jornada.');
            $this->jornadaRepo->marcarComoCompletada($data['token']);
        }

        return $turno_id;
    }

    /**
     * Resuelve el contexto de la reserva y devuelve:
     * [centro_estetico_id, fecha, hora_inicio_jornada, hora_fin_jornada]
     *
     * Modo público (token): la clienta accede desde la agenda compartida por el centro.
     * Modo interno (fecha + usuario logueado): el centro crea un turno desde su panel.
     */
    private function resolverContexto(array $data): array|WP_Error
    {
        // Modo público (token)
        if (!empty($data['token'])) {
            $jornada = $this->jornadaRepo->fromToken($data['token']);

            if (!$jornada) {
                return new WP_Error(
                    'token_invalido',
                    'La jornada no existe o expiró',
                    ['status' => 400]
                );
            }

            error_log('TurnoCreator::resolverContexto - Jornada ID ' . $jornada->id .
                ' para token ' . $data['token'] .
                ' - Centro ID: ' . $jornada->centro_estetico_id .
                ' Fecha: ' . $jornada->fecha);

            return [
                $jornada->centro_estetico_id,
                $jornada->fecha,
                $jornada->hora_inicio,
                $jornada->hora_fin,
            ];
        }

        // Modo interno (modal de reserva desde el panel del centro)
        // En Arquitectura CLEAN, el servicio recibe la identidad ya validada 
        // por el controlador (centro_id).
        if (!empty($data['fecha']) && !empty($data['centro_id'])) {
            $centro_id = (int) $data['centro_id'];

            if (!$centro_id) {
                return new WP_Error(
                    'centro_no_encontrado',
                    'No tenés un centro estético asociado a tu cuenta.',
                    ['status' => 403]
                );
            }

            $jornada = $this->jornadaRepo->findActivaByCentroYFecha($centro_id, $data['fecha']);

            if (!$jornada) {
                return new WP_Error(
                    'jornada_invalida',
                    'No hay jornada activa para ese día.',
                    ['status' => 400]
                );
            }

            error_log('TurnoCreator::resolverContexto - Modo interno. Centro ID: ' . $centro_id . ' Fecha: ' . $data['fecha']);

            return [
                $centro_id,
                $data['fecha'],
                $jornada->hora_inicio,
                $jornada->hora_fin,
            ];
        }

        return new WP_Error(
            'contexto_invalido',
            'No se pudo resolver el contexto de la agenda',
            ['status' => 400]
        );
    }
}
