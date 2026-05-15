<?php
namespace RDT\CentrosEstetica\Services;

use RDT\CentrosEstetica\Repositories\TurnoClienteRepository;

class DisponibilidadService {

    private TurnoClienteRepository $repo;

    public function __construct(TurnoClienteRepository $repo) {
        $this->repo = $repo;
    }

    public function obtenerHorariosDisponibles(
        int $centro_id,
        string $fecha,
        int $duracion_min
    ): array {

        $inicio_jornada = strtotime("$fecha 08:00");
        $fin_jornada    = strtotime("$fecha 20:00");

        $turnos_existentes = $this->repo
            ->obtenerTurnosPorCentroYFecha($centro_id, $fecha);

        $ocupados = [];

        foreach ($turnos_existentes as $turno) {
            $ocupados[] = [
                'inicio' => strtotime($turno['hora_inicio']),
                'fin'    => strtotime($turno['hora_fin']),
            ];
        }

        $horarios_disponibles = [];

        for ($t = $inicio_jornada; $t + ($duracion_min * 60) <= $fin_jornada; $t += 300) {

            $inicio = $t;
            $fin    = $t + ($duracion_min * 60);

            if ($this->colisiona($inicio, $fin, $ocupados)) {
                continue;
            }

            $horarios_disponibles[] = date('H:i', $inicio);
        }

        return $horarios_disponibles;
    }

    private function colisiona(int $inicio, int $fin, array $ocupados): bool {

        foreach ($ocupados as $o) {
            if ($inicio < $o['fin'] && $fin > $o['inicio']) {
                return true;
            }
        }
        return false;
    }
}