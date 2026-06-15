<?php

declare(strict_types=1);
namespace RDT\CentrosEstetica\Services;

use RDT\CentrosEstetica\Domain\JornadaEstado;
use RDT\CentrosEstetica\Notifications\SsaBookingMail;
use WP_Error;

/**
 * JornadaCreator
 *
 * Encapsula la lógica de creación de un post CPT jornada_centro.
 * Es la fuente de verdad para esta operación, independientemente
 * del disparador (hook SSA, endpoint admin, o cualquier otro futuro).
 *
 * SRP: solo crea jornadas. No gestiona turnos ni notificaciones de turno.
 * OCP: el comportamiento puede extenderse sin modificar esta clase
 *      (por ejemplo, nuevas validaciones via filtros de WordPress).
 */
final class JornadaCreator
{
    /**
     * Crea una jornada_centro y notifica al centro por email.
     *
     * @param int    $wp_user_id       ID del usuario WordPress del centro (usuario_responsable).
     * @param string $fecha            Fecha de la jornada (Y-m-d).
     * @param string $hora_inicio      Hora de inicio (H:i).
     * @param string $hora_fin         Hora de fin (H:i).
     * @param int    $ssa_reserva_id   ID de la reserva SSA (0 si se crea desde el admin).
     * @param bool   $enviar_email     Si true, envía el email de confirmación al centro.
     *
     * @return int|WP_Error  El ID del post creado o un WP_Error en caso de fallo.
     */
    public function crear(
        int    $wp_user_id,
        string $fecha,
        string $hora_inicio,
        string $hora_fin,
        int    $ssa_reserva_id = 0,
        bool   $enviar_email   = true
    ): int|WP_Error {

        // 1. Resolver el centro estético del usuario
        $centros = get_posts([
            'post_type'   => 'centro_estetico',
            'numberposts' => 1,
            'meta_key'    => 'usuario_responsable',
            'meta_value'  => $wp_user_id,
            'fields'      => 'ids',
        ]);

        if (empty($centros)) {
            return new WP_Error(
                'centro_no_encontrado',
                "No se encontró un centro estético para el usuario ID {$wp_user_id}.",
                ['status' => 404]
            );
        }

        $centro_id = (int) $centros[0];

        // 2. Validar que no existe ya una jornada para ese centro y fecha
        $existe = get_posts([
            'post_type'      => 'jornada_centro',
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'meta_query'     => [
                'relation' => 'AND',
                ['key' => 'fecha', 'value' => $fecha],
            ],
        ]);

        // Filtramos por centro en PHP por el problema de serialización de ACF
        $jornada_existente_id = null;
        foreach ($existe as $post_id) {
            $raw = get_post_meta($post_id, 'centro_estetico_id', true);
            $id_centro_jornada = is_array($raw) ? (int)($raw[0] ?? 0)
                : (is_object($raw) && isset($raw->ID) ? (int)$raw->ID : (int)$raw);

            if ($id_centro_jornada === $centro_id) {
                // Solo considerar duplicada si no está cancelada
                $estado = get_post_meta($post_id, 'jornada_estado', true);
                if ($estado !== JornadaEstado::CANCELADA) {
                    $jornada_existente_id = $post_id;
                    break;
                }
            }
        }

        if ($jornada_existente_id) {
            return new WP_Error(
                'jornada_duplicada',
                "Ya existe una jornada para este centro en la fecha {$fecha} (ID: {$jornada_existente_id}).",
                ['status' => 409, 'jornada_id' => $jornada_existente_id]
            );
        }

        // 3. Generar token público único
        $token = wp_generate_uuid4();

        // 4. Crear el post
        $meta = [
            'centro_estetico_id' => $centro_id,
            'fecha'              => $fecha,
            'token_publico'      => $token,
            'hora_inicio'        => $hora_inicio,
            'hora_fin'           => $hora_fin,
            'jornada_estado'     => JornadaEstado::ACTIVA,
        ];

        if ($ssa_reserva_id > 0) {
            $meta['ssa_reserva_id'] = $ssa_reserva_id;
        }

        $jornada_id = wp_insert_post([
            'post_type'   => 'jornada_centro',
            'post_status' => 'publish',
            'post_title'  => 'Jornada ' . $fecha,
            'meta_input'  => $meta,
        ]);

        if (is_wp_error($jornada_id) || !$jornada_id) {
            return new WP_Error(
                'jornada_no_creada',
                'No se pudo crear la jornada.',
                ['status' => 500]
            );
        }

        error_log("JornadaCreator: Jornada ID {$jornada_id} creada para centro ID {$centro_id}, fecha {$fecha}.");

        // 5. Enviar email de confirmación si corresponde
        if ($enviar_email) {
            (new SsaBookingMail())->enviar(
                $wp_user_id,
                $fecha,
                $hora_inicio,
                $hora_fin,
                $token,
                false,  // no es reprogramación
                false   // no solicitó gel (flujo admin)
            );
        }

        return $jornada_id;
    }
}
