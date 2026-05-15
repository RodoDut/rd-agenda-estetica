<?php
namespace RDT\CentrosEstetica\Api;

/*
Controlador REST para manejo de turnos.
Recibe la solicitud de reserva de turno desde el frontend, valida los datos, 
y delega la creación del turno al servicio TurnoCreator.
*/

use RDT\CentrosEstetica\Repositories\ServiciosClientesRepository;
use WP_REST_Request;
use WP_REST_Response;
use RDT\CentrosEstetica\Services\TurnoCreator;
use RDT\CentrosEstetica\Repositories\JornadaCentroRepository;
//use RDT\CentrosEstetica\Repositories\TratamientoRepository;
use RDT\CentrosEstetica\Repositories\TurnoClienteRepository;
use function RDT\CentrosEstetica\Helpers\get_centro_by_user;
use RDT\CentrosEstetica\Services\HorariosCalculator;
use RDT\CentrosEstetica\Notifications\TurnoNotification;

class TurnoController
{
    public static function register(): void
    {
        register_rest_route('rdt/v1', '/turno', [
            'methods'  => 'POST',
            'callback' => [self::class, 'handle'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route('rdt/v1', '/reserva/fetch-oferta', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'handleFetchOferta'],
            'permission_callback' => 'is_user_logged_in', // Solo centros logueados
        ]);

        register_rest_route('rdt/v1', '/jornada/(?P<id>\d+)/solicitar-gel', [
            'methods'             => 'PATCH',
            'callback'            => [self::class, 'handleSolicitarGel'],
            'permission_callback' => [self::class, 'checkJornadaPermission'],
            'args'                => [
                'id' => [
                    'required'          => true,
                    'validate_callback' => fn($v) => is_numeric($v) && (int)$v > 0,
                    'sanitize_callback' => 'absint',
                ],
            ],
        ]);
    }

    public static function handle(WP_REST_Request $request): WP_REST_Response
    {
        $json = $request->get_json_params();
 
        if (!$json || empty($json)) {
        return new WP_REST_Response([
            'error' => 'Payload JSON inválido o vacío.'
        ], 400);
    }
 
        error_log('TurnoController::handle - Received JSON: (datos sensibles ocultos por seguridad)');
 
        // Los datos del turno vienen en el cuerpo (body) de la petición JSON.
        // El token de la jornada puede venir en el cuerpo o como parámetro en la URL.
        $token = isset($json['token']) ? sanitize_text_field($json['token']) : sanitize_text_field($request->get_param('token'));

        $data = [
            'token'            => $token,
            'fecha'            => isset($json['fecha']) ? sanitize_text_field($json['fecha']) : '',
            'servicio_id'      => isset($json['servicio_id']) ? (int) $json['servicio_id'] : 0,
            'hora_inicio'      => isset($json['hora_inicio']) ? sanitize_text_field($json['hora_inicio']) : '',
            'nombre_cliente'   => isset($json['nombre_cliente']) ? sanitize_text_field($json['nombre_cliente']) : '',
            'email_cliente'    => isset($json['email_cliente']) ? sanitize_email($json['email_cliente']) : '',
            'telefono_cliente' => isset($json['telefono_cliente']) ? sanitize_text_field($json['telefono_cliente']) : '',
        ];

        // Si el usuario está logueado, obtenemos el centro_id y lo inyectamos al arreglo existente.
        // Usamos la identidad del servidor, no la del cliente, por seguridad.
        if (is_user_logged_in()) {
            $logged_in_centro_id = get_centro_by_user(get_current_user_id());
            if ($logged_in_centro_id) {
                $data['centro_id'] = $logged_in_centro_id;
                error_log('TurnoController::handle - centro_id inyectado: ' . $logged_in_centro_id);
            }
        }

        error_log('TurnoController::handle - Final data array for TurnoCreator: (datos sensibles ocultos por seguridad)');

        $required = [
            'servicio_id',
            'hora_inicio',
            'nombre_cliente',
            'email_cliente',
            'telefono_cliente',
        ];

        foreach ($required as $key) {
            if (empty($data[$key])) {
                return new WP_REST_Response([
                    'error' => 'Dato Faltante: ' . $key
                ], 400);
            }
        }
    
        

        //Caso de uso.
        $creator = new TurnoCreator(
            new JornadaCentroRepository(),
            new ServiciosClientesRepository(),
            new TurnoClienteRepository(),
            new HorariosCalculator(),
            new TurnoNotification()
        );

        $resultado = $creator->crear($data);

        if (is_wp_error($resultado)) {
            return new WP_REST_Response([
                'error' => $resultado->get_error_message()
            ], $resultado->get_error_data()['status'] ?? 400);
        }

        return new WP_REST_Response([
            'success'  => true,
            'turno_id' => $resultado
        ], 201);
    }

    /**
     * Verifica si el usuario logueado tiene una oferta de producto pendiente (pote_gel)
     * tras haber completado una reserva de jornada en SSA.
     */
    public static function handleFetchOferta(): WP_REST_Response
    {
        $user_id = get_current_user_id();
        $transient_key = 'rdt_gel_pot_offer_' . $user_id;
        $offer_data = get_transient($transient_key);

        if (!$offer_data) {
            return new WP_REST_Response(['show_offer' => false], 200);
        }

        // Eliminamos el transient inmediatamente para que la oferta no se repita
        delete_transient($transient_key);

        return new WP_REST_Response(['show_offer' => true, 'data' => $offer_data], 200);

    }

    /**
     * Marca el campo ACF 'solicito_gel' a true para una jornada específica.
     */
    public static function handleSolicitarGel(WP_REST_Request $request): WP_REST_Response
    {
        $jornada_id = (int) $request->get_param('id');

        // La verificación de permisos ya se hizo en checkJornadaPermission

        if (function_exists('update_field')) {
            update_field('solicito_gel', true, $jornada_id);
            // Limpiar caché del post para que el cambio sea visible inmediatamente
            clean_post_cache($jornada_id);
            wp_cache_delete($jornada_id, 'post_meta');
        } else {
            return new WP_REST_Response(['error' => 'ACF no está activo o update_field no existe.'], 500);
        }

        return new WP_REST_Response(['success' => true], 200);
    }

    /**
     * Callback de permiso para verificar que la jornada pertenece al centro logueado.
     */
    public static function checkJornadaPermission(WP_REST_Request $request): bool
    {
        if (!is_user_logged_in()) return false;
        $user_centro_id = get_centro_by_user(get_current_user_id());
        if (!$user_centro_id) return false;

        $jornada_id = (int) $request->get_param('id');
        $jornada_centro_id = (int) get_post_meta($jornada_id, 'centro_estetico_id', true);

        return $user_centro_id === $jornada_centro_id;
    }
}