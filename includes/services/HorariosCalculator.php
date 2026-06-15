<?php
//Dado un día + centro + duración → devolver slots disponibles
namespace RDT\CentrosEstetica\Services;

use RDT\CentrosEstetica\Repositories\JornadaCentroRepository;
use RDT\CentrosEstetica\Domain\TurnoEstado;

class HorariosCalculator
{
    
    public function calcular(string $fecha, int $duracion, int $centro_id, 
    string $hora_inicio_jornada, string $hora_fin_jornada): array
    {
        $inicio = strtotime($hora_inicio_jornada);
        $fin    = strtotime($hora_fin_jornada);

        $slots = [];

        $ocupados = $this->obtenerTurnosOcupados($fecha, $centro_id);

        for ($t = $inicio; $t + ($duracion * 60) <= $fin; $t += 300) {
            if ($this->slotDisponible($t, $duracion, $ocupados)) {
                $slots[] = date('H:i', $t);
            }
        }
        
        return $slots;
    }

    /*
        * Obtener turnos ocupados para un centro en una fecha dada
        * Devuelve un array con los turnos ocupados (inicio, fin)
    */
    private function obtenerTurnosOcupados(string $fecha, int $centro_id): array
    {
        $turnos = get_posts([
            'post_type'  => 'turno_cliente',
            'relation'   => 'AND',
            'meta_query' => [
                'relation' => 'AND',
                ['key' => 'fecha', 'value' => $fecha],
                ['key' => 'centro_estetico_id', 'value' => $centro_id],
                [
                 'key' => 'estado_turno',
                 'value' => [TurnoEstado::CANCELADO, TurnoEstado::COMPLETADO, TurnoEstado::AUSENTE],
                 'compare' => 'NOT IN'
                 ],
                ],
            'posts_per_page' => -1,
        ]);

        $ocupados = [];

        foreach ($turnos as $turno) {
            $ocupados[] = [
                'inicio'   => strtotime(get_post_meta($turno->ID, 'hora_inicio', true)),
                'fin'      => strtotime(get_post_meta($turno->ID, 'hora_fin', true)),
                
            ];
        }

        return $ocupados = self::filtrarHorariosPasados($ocupados, $fecha);
    }

    private function slotDisponible(int $inicio, int $duracion, array $ocupados): bool
    {

        $fin = $inicio + ($duracion * 60);

        foreach ($ocupados as $o) {
            $o_inicio = $o['inicio'];
            $o_fin    = $o['fin'];

            if ($inicio < $o_fin && $fin > $o_inicio) {
                return false;
            }
        }

        return true;
    }

    private function filtrarHorariosPasados(array $slots, string $fecha): array
    {
        $hoy = current_time('Y-m-d');

        if ($fecha !== $hoy) {
            return $slots;
        }

        $ahora = current_time('H:i');

    // Filtrar horarios pasados
    // y reindexar el array para evitar huecos en las claves
        return array_values(array_filter($slots, function ($hora) use ($ahora) {
            return $hora > $ahora;
        }));
    }

}
?>