<?php
namespace RDT\CentrosEstetica\Domain;

final class JornadaEstado
{
    public const ACTIVA     = 'activa';
    public const CANCELADA  = 'cancelada';
    public const EXPIRADA   = 'expirada';
    public const COMPLETADA = 'completada';

    public static function esValido(string $estado): bool
    {
        return in_array($estado, [
            self::ACTIVA,
            self::CANCELADA,
            self::EXPIRADA,
            self::COMPLETADA,
        ], true);
    }
}
