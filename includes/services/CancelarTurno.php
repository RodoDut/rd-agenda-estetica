<?php

declare(strict_types=1);
namespace RDT\CentrosEstetica\Services;

/*
Servicio para cancelar un turno existente. Recibe el token del turno, 
busca el turno asociado, valida su estado actual, y si es cancelable, 
actualiza su estado a "cancelado". 
Retorna un WP_Error si el turno no existe o no puede ser cancelado.
*/

use WP_Error;
use RDT\CentrosEstetica\Repositories\TurnoClienteRepository;
use RDT\CentrosEstetica\Repositories\JornadaCentroRepository;
use RDT\CentrosEstetica\Domain\TurnoEstado;

final class CancelarTurno
{
    public function __construct(
        private TurnoClienteRepository $repo,
        private JornadaCentroRepository $jornadaRepo
    ) {}

    public function cancelar(string $token_turno)
    {
        $turno = $this->repo->findByToken($token_turno);

        if (!$turno) {
            return new WP_Error('not_found', 'Turno no encontrado.', ['status' => 404]);
        }

        $estado = get_post_meta($turno->ID, 'estado_turno', true);
        error_log("CancelarTurno: Ejecutado");

        if (in_array($estado, [
            TurnoEstado::CANCELADO,
            TurnoEstado::COMPLETADO,
            TurnoEstado::AUSENTE,
        ], true)) {
            return new WP_Error(
                'invalid_state',
                'El turno no puede cancelarse. Estado actual del turno: ' . $estado,
                ['status' => 409]
            );
        }

        $this->repo->actualizarEstado($turno->ID, TurnoEstado::CANCELADO);

        /** Reactivar jornada si estaba COMPLETADA y la fecha aún es futura */
        $centro_id_raw = function_exists('get_field')
            ? get_field('centro_estetico_id', $turno->ID)
            : get_post_meta($turno->ID, 'centro_estetico_id', true);

        // get_field() en campos Post Object puede devolver un WP_Post en lugar del ID
        if (is_object($centro_id_raw) && isset($centro_id_raw->ID)) {
            $centro_id = $centro_id_raw->ID;
        } else {
            $centro_id = (int) $centro_id_raw;
        }

        $fecha_turno = get_post_meta($turno->ID, 'fecha', true);

        error_log("CancelarTurno: centro_id={$centro_id} fecha_turno={$fecha_turno}");

        if ($centro_id && $fecha_turno) {
            $this->jornadaRepo->reactivarSiCorresponde($centro_id, $fecha_turno);
        }

        // Disparar hook propio para notificar la cancelación del turno por parte del cliente.
        // NO se dispara el hook de SSA (ssa/appointment/canceled) porque ese hook cancela
        // también la jornada del centro, lo cual es un efecto no deseado en este contexto.
        do_action('rdt/turno/cancelado_por_cliente', $turno->ID);
        error_log("CancelarTurno: Hook rdt/turno/cancelado_por_cliente disparado para turno ID {$turno->ID}.");

        return true;
    }
}