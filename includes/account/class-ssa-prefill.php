<?php

declare(strict_types=1);
namespace RDT\CentrosEstetica\Account;

/**
 * SsaPrefill
 *
 * El prefill de SSA está integrado directamente en el shortcode
 * [reserva_jornada] (class-reserva-jornada.php), que carga
 * AssetsLoader::load_ssa_prefill() solo cuando corresponde.
 *
 * Esta clase se mantiene para compatibilidad con el registro
 * en rdt-centros-core.php.
 */
final class SsaPrefill
{
    public static function register(): void
    {
        // Sin acción global — el prefill lo gestiona ReservaJornada
    }
}
