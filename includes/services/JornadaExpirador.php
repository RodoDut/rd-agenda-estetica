<?php

declare(strict_types=1);

namespace RDT\CentrosEstetica\Services;

use RDT\CentrosEstetica\Domain\JornadaEstado;
use RDT\CentrosEstetica\Repositories\JornadaCentroRepository;

/**
 * JornadaExpirador
 *
 * Servicio de dominio responsable de detectar jornadas cuyo horario
 * de fin ya pasó y marcarlas como EXPIRADA en la base de datos.
 *
 * Principio de diseño (SRP):
 * Este servicio tiene una única responsabilidad: expirar jornadas vencidas.
 * No sabe nada de HTTP, de shortcodes, ni de notificaciones.
 *
 * Estrategia: "Lazy Expiration"
 * No usamos wp-cron. El cambio de estado ocurre de forma reactiva:
 * la próxima vez que el sistema consulta las jornadas de un centro
 * (ej: cuando el centro abre su panel), este servicio corre primero,
 * expira lo que corresponda, y luego el repositorio devuelve solo
 * las jornadas realmente activas.
 *
 * Esto es suficiente para el MVP ya que el cambio eventual es aceptable
 * y evita la complejidad de configurar tareas programadas en wp-cron.
 */
final class JornadaExpirador
{
    private JornadaCentroRepository $repo;

    public function __construct(JornadaCentroRepository $repo)
    {
        $this->repo = $repo;
    }

    /**
     * Busca todas las jornadas ACTIVAS de un centro cuyo datetime de fin
     * ya pasó, y las marca como EXPIRADA.
     *
     * La comparación usa fecha + hora_fin para ser exacta:
     * una jornada de hoy con hora_fin='18:00' no expira a medianoche,
     * sino a las 18:00 de ese día.
     *
     * @param int $centro_id ID del CPT centro_estetico
     */
    public function expirarVencidas(int $centro_id): void
    {
        $jornadas_activas = $this->repo->findActivasByCentro($centro_id);

        if (empty($jornadas_activas)) {
            return;
        }

        // Usamos current_time('timestamp') para respetar la zona horaria
        // configurada en WordPress (Ajustes > General > Zona horaria).
        // No usamos time() porque devuelve UTC y nuestras fechas están en hora local.
        $ahora = current_time('timestamp');

        foreach ($jornadas_activas as $jornada) {
            // Construimos el timestamp exacto del fin de la jornada
            // combinando la fecha (YYYY-MM-DD) y la hora_fin (HH:MM).
            $fin_jornada = strtotime($jornada->fecha . ' ' . $jornada->hora_fin);

            if ($fin_jornada === false) {
                // Si los datos están corruptos, logueamos y continuamos
                // sin romper el flujo para las demás jornadas.
                error_log(sprintf(
                    'JornadaExpirador: No se pudo parsear fecha/hora de jornada ID %d (fecha=%s hora_fin=%s)',
                    $jornada->id,
                    $jornada->fecha,
                    $jornada->hora_fin
                ));
                continue;
            }

            if ($ahora > $fin_jornada) {
                $this->repo->marcarComoExpirada($jornada->id);

                error_log(sprintf(
                    'JornadaExpirador: Jornada ID %d expirada (fecha=%s hora_fin=%s).',
                    $jornada->id,
                    $jornada->fecha,
                    $jornada->hora_fin
                ));
            }
        }
    }
}
