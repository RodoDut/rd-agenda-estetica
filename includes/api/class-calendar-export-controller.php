<?php

declare(strict_types=1);

namespace RDT\CentrosEstetica\Api;

use WP_REST_Request;
use RDT\CentrosEstetica\Services\CalendarService;

class CalendarExportController
{
    public static function register(): void
    {
        // GET /rdt/v1/turno/{id}/ics?token={token_turno}
        register_rest_route('rdt/v1', '/turno/(?P<id>\d+)/ics', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'handleIcs'],
            'permission_callback' => '__return_true', // Validamos por token manualmente
        ]);

        register_rest_route('rdt/v1', '/turno/(?P<id>\d+)/agendar', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'handleSmartCalendar'],
            'permission_callback' => '__return_true',
        ]);
    }

    public static function handleIcs(WP_REST_Request $request): void
    {
        $turno_id = (int) $request->get_param('id');
        $token    = sanitize_text_field($request->get_param('token'));

        // 1. Validar turno y token
        $turno = get_post($turno_id);
        if (!$turno || $turno->post_type !== 'turno_cliente') {
            status_header(404);
            die('Turno no encontrado');
        }

        $token_real = get_post_meta($turno_id, 'token_turno', true);
        if (!$token_real || $token !== $token_real) {
            status_header(403);
            die('Acceso denegado');
        }

        // 2. Obtener datos
        $fecha          = get_post_meta($turno_id, 'fecha', true);
        $hora_inicio    = get_post_meta($turno_id, 'hora_inicio', true);
        $hora_fin       = get_post_meta($turno_id, 'hora_fin', true);
        $tratamiento_id = get_post_meta($turno_id, 'tratamiento_id', true);
        $centro_id_raw  = get_post_meta($turno_id, 'centro_estetico_id', true);

        // Resolver ID centro (ACF puede devolver array/objeto)
        $centro_id = is_object($centro_id_raw) ? $centro_id_raw->ID : (int) (is_array($centro_id_raw) ? $centro_id_raw[0] : $centro_id_raw);

        $nombre_tratamiento = get_the_title($tratamiento_id);
        $nombre_centro      = get_the_title($centro_id);
        $direccion_centro   = get_post_meta($centro_id, 'direccion', true) ?: 'Centro Estético';

        // 3. Generar contenido ICS
        $service = new CalendarService();
        
        $titulo      = "Turno: {$nombre_tratamiento}";
        $descripcion = "Turno confirmado en {$nombre_centro}. Tratamiento: {$nombre_tratamiento}.";
        
        // Usamos el token como UID para que sea único pero consistente
        $uid = "rdt-turno-{$turno_id}-{$token}@rdtecnobelleza.net";

        $ics_content = $service->generateIcsContent(
            $uid,
            $titulo,
            $descripcion,
            $direccion_centro,
            $fecha,
            $hora_inicio,
            $hora_fin
        );

        // 4. Servir archivo
        $filename = "turno-{$fecha}.ics";

        header('Content-Type: text/calendar; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        
        echo $ics_content;
        exit;
    }

    /**
     * Detecta el dispositivo del usuario y redirige al formato de calendario adecuado.
     */
    public static function handleSmartCalendar(WP_REST_Request $request): void
    {
        $turno_id = (int) $request->get_param('id');
        $token    = sanitize_text_field($request->get_param('token'));

        // 1. Validar turno y token (Seguridad: no redirigir si el token es inválido)
        $turno = get_post($turno_id);
        if (!$turno || $turno->post_type !== 'turno_cliente') {
            status_header(404);
            die('Turno no encontrado');
        }
        $token_real = get_post_meta($turno_id, 'token_turno', true);
        if (!$token_real || $token !== $token_real) {
            status_header(403);
            die('Acceso denegado');
        }

        // 2. Detectar dispositivo mediante User-Agent
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $es_apple = (stripos($ua, 'iphone') !== false || stripos($ua, 'ipad') !== false || stripos($ua, 'macintosh') !== false);

        // 3. Redirigir según el dispositivo
        if ($es_apple) {
            // Si es Apple, servimos el archivo .ics
            $url_ics = rest_url("rdt/v1/turno/{$turno_id}/ics") . "?token={$token}";
            wp_redirect($url_ics);
            exit;
        } else {
            // Si es Android/Windows/Linux, redirigimos a Google Calendar
            
            // Obtenemos los datos necesarios para generar el link
            $fecha          = get_post_meta($turno_id, 'fecha', true);
            $hora_inicio    = get_post_meta($turno_id, 'hora_inicio', true);
            $hora_fin       = get_post_meta($turno_id, 'hora_fin', true);
            $tratamiento_id = get_post_meta($turno_id, 'tratamiento_id', true);
            $centro_id_raw  = get_post_meta($turno_id, 'centro_estetico_id', true);

            // Resolver ID centro
            $centro_id = is_object($centro_id_raw) ? $centro_id_raw->ID : (int) (is_array($centro_id_raw) ? $centro_id_raw[0] : $centro_id_raw);

            $nombre_tratamiento = get_the_title($tratamiento_id);
            $nombre_centro      = get_the_title($centro_id);
            $direccion_centro   = get_post_meta($centro_id, 'direccion', true) ?: 'Centro Estético';

            $service = new CalendarService();
            
            $url_google = $service->getGoogleCalendarUrl(
                "Turno de depilación en {$nombre_centro}",
                "Tratamiento: {$nombre_tratamiento}. Recordá que podés cancelar tu turno desde el email de confirmación.",
                $direccion_centro,
                $fecha,
                $hora_inicio,
                $hora_fin
            );

            wp_redirect($url_google);
            exit;
        }
    }
}