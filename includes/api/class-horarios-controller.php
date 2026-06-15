<?php
namespace RDT\CentrosEstetica\Api;

/*
Controlador REST para manejo de horarios disponibles.
Soporta dos modos:
  - Publico:  recibe token de jornada + servicio_id (parametro: servicio)
  - Interno:  recibe fecha + servicio_id (requiere usuario logueado)
*/

use RDT\CentrosEstetica\Domain\JornadaEstado;
use WP_REST_Request;
use WP_REST_Response;
use RDT\CentrosEstetica\Services\HorariosCalculator;
use RDT\CentrosEstetica\Repositories\JornadaCentroRepository;
use RDT\CentrosEstetica\Repositories\ServiciosClientesRepository;

class HorariosController
{
    public static function register(): void
    {
        register_rest_route('rdt/v1', '/horarios', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'handle'],
            'permission_callback' => '__return_true',
        ]);
    }

    public static function handle(WP_REST_Request $request): WP_REST_Response
    {
        $token      = sanitize_text_field($request->get_param('token'));
        $fecha      = sanitize_text_field($request->get_param('fecha'));
        $servicio_id = (int) $request->get_param('servicio');

        if (!$servicio_id) {
            return new WP_REST_Response(['error' => 'Parametro servicio requerido.'], 400);
        }

        // Resolver jornada segun el modo
        if ($token) {
            // Modo publico: agenda de clienta por token
            $jornada = JornadaCentroRepository::fromToken($token);

            if (!$jornada) {
                return new WP_REST_Response(['error' => 'Agenda invalida o no disponible.'], 404);
            }

            if ($jornada->jornada_estado !== JornadaEstado::ACTIVA) {
                return new WP_REST_Response([
                    'error' => 'La agenda esta ' . $jornada->jornada_estado . '. No se pueden mostrar horarios.'
                ], 403);
            }

        } elseif ($fecha) {
            // Modo interno: reserva manual desde el panel del centro
            // El centro_id se deriva del usuario logueado, nunca del parametro de URL
            if (!is_user_logged_in()) {
                return new WP_REST_Response(['error' => 'Autenticacion requerida.'], 401);
            }

            $centro_id = \RDT\CentrosEstetica\Helpers\get_centro_by_user(get_current_user_id());
            if (!$centro_id) {
                return new WP_REST_Response(['error' => 'No tenes un centro estetico asociado a tu cuenta.'], 403);
            }

            $jornadaRepo = new JornadaCentroRepository();
            $jornada     = $jornadaRepo->findActivaByCentroYFecha($centro_id, $fecha);

            if (!$jornada) {
                return new WP_REST_Response(['error' => 'No hay jornada activa para esa fecha.'], 404);
            }

            $jornada->centro_estetico_id = $centro_id;
            $jornada->fecha              = $fecha;
            $jornada->jornada_estado     = JornadaEstado::ACTIVA;

        } else {
            return new WP_REST_Response([
                'error' => 'Parametros incompletos. Requerido: token o fecha.'
            ], 400);
        }

        // Verificar que el servicio pertenezca al centro de la jornada
        if (!ServiciosClientesRepository::perteneceACentro($servicio_id, $jornada->centro_estetico_id)) {
            return new WP_REST_Response(['error' => 'El servicio no pertenece a este centro.'], 403);
        }

        // Resolver duracion del servicio
        $servicioRepo = new ServiciosClientesRepository();
        $duracion     = $servicioRepo->obtenerDuracion($servicio_id);

        if (!$duracion) {
            return new WP_REST_Response(['error' => 'Servicio invalido o sin duracion configurada.'], 400);
        }

        // Calcular horarios disponibles
        $calculator = new HorariosCalculator();

        $horarios = $calculator->calcular(
            $jornada->fecha,
            $duracion,
            $jornada->centro_estetico_id,
            $jornada->hora_inicio,
            $jornada->hora_fin
        );

        return new WP_REST_Response(['horarios' => $horarios], 200);
    }
}
