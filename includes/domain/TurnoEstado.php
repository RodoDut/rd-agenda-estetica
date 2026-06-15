<?php
namespace RDT\CentrosEstetica\Domain;

final class TurnoEstado
{
    public const APROBADO     = 'aprobado';
    public const CANCELADO  = 'cancelado';
    public const COMPLETADO = 'completado';
    public const AUSENTE    = 'ausente';
    public const EXPIRADO = 'expirado';

    public const PENDIENTE = 'pendiente';

    public const RECHAZADO = 'rechazado';


     /**
     * Valida si el estado es uno de los definidos en TurnoEstado.
     */

    public static function esValido(string $estado): bool
    {
        return in_array($estado, [
            self::APROBADO,
            self::CANCELADO,
            self::COMPLETADO,
            self::AUSENTE,
            self::EXPIRADO,
            self::PENDIENTE,
            self::RECHAZADO
        ], true);
    }
}
