<?php
namespace RDT\CentrosEstetica\Services;

use WP_Error;

class HorariosService
{
    private HorariosCalculator $calculator;

    public function __construct(HorariosCalculator $calculator)
    {
        $this->calculator = $calculator;
    }

    /**
     * Devuelve slots disponibles para una jornada válida
     */
    public function obtenerHorarios(
        int $centro_id,
        string $fecha,
        int $duracion
    ): array|WP_Error {

        // 2. Validar duración del tratamiento
        if (!$duracion) {
            return new WP_Error(
                'tratamiento_invalido',
                'Tratamiento no válido',
                ['status' => 400]
            );
        }

        // 3. Delegar cálculo puro
        return $this->calculator->calcular(
            $fecha,
            $duracion,
            $centro_id
        );
    }
}
