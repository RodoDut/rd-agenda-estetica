<?php
namespace RDT\CentrosEstetica\Api;

/*
Controlador REST para manejo de cancelación de turnos.
Recibe la solicitud de cancelación de turno desde el frontend, valida el token del turno,
y delega la lógica de negocio al servicio CancelarTurno.
*/

use WP_REST_Request;
use WP_REST_Response;
use RDT\CentrosEstetica\Services\CancelarTurno;
use RDT\CentrosEstetica\Repositories\TurnoClienteRepository;
use RDT\CentrosEstetica\Repositories\JornadaCentroRepository;


final class CancelarTurnoController
{
    public static function register(): void
    {
        register_rest_route('rdt/v1', '/turno/cancelar', [
            'methods'  => 'POST',
            'callback' => [self::class, 'handle'],
            'permission_callback' => '__return_true',
        ]);
    }

    public static function handle(WP_REST_Request $request): WP_REST_Response
    {
        $token = sanitize_text_field($request->get_param('token_turno'));

        if (!$token) {
            return new WP_REST_Response([
                'error' => 'Token de turno requerido.'
            ], 400);
        }

        /** 1️⃣ Delegar la cancelación al servicio */
        $repo = new TurnoClienteRepository();   //Obtiene datos del turno desde la BD
        $jornadaRepo = new JornadaCentroRepository();  //Obtiene datos de la jornada desde la BD
        $cancelarTurno = new CancelarTurno($repo, $jornadaRepo);  //Servicio que contiene la lógica de negocio para cancelar el turno
        $resultado = $cancelarTurno->cancelar($token); //Ejecuta la cancelación y obtiene el resultado (true o WP_Error)

        if (is_wp_error($resultado)) {
            return new WP_REST_Response([
                'error' => $resultado->get_error_message()
            ], $resultado->get_error_data()['status'] ?? 400);
        }

        return new WP_REST_Response([
            'success' => true,
            'message' => 'Turno cancelado correctamente.'
        ], 200);
    }
}