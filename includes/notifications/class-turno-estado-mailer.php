<?php

declare(strict_types=1);
namespace RDT\CentrosEstetica\Notifications;

use RDT\CentrosEstetica\Domain\TurnoEstado;

/**
 * TurnoEstadoMailer
 *
 * Notifica al cliente cuando el centro cambia manualmente el estado
 * de su turno desde el calendario del panel (endpoint PATCH /turno/{id}/estado).
 *
 * SRP: solo gestiona emails de cambio de estado manual desde el panel.
 *
 * Relación con otras clases de notificación:
 * - TurnoNotification  → confirmación y cancelación estándar (flujo sin aprobación)
 * - TurnoAprobacionMailer → flujo de aprobación por email (token): pendiente al centro,
 *                           aprobado/rechazado al cliente vía link del email
 * - TurnoEstadoMailer  → cambio manual de estado desde el panel del centro (este archivo)
 *
 * Terminología canónica del dominio:
 * - "servicio"  (no "tratamiento") — alineado con ServiciosClientes CPT
 * - "aprobado"  (no "activo")      — alineado con TurnoEstado::APROBADO
 */
final class TurnoEstadoMailer
{
    /**
     * Envía al cliente un email informando el nuevo estado de su turno.
     *
     * Solo notifica para estados con impacto directo en el cliente:
     *   - aprobado  → turno confirmado, puede cancelar
     *   - cancelado → turno cancelado por el centro
     *   - pendiente → turno vuelve a revisión
     *
     * No notifica para estados internos de gestión (completado, ausente,
     * rechazado, expirado), que el centro registra para su propio control
     * pero que no requieren comunicación al cliente.
     *
     * @param int    $turno_id     ID del CPT turno_cliente
     * @param int    $centro_id    ID del CPT centro_estetico
     * @param string $nuevo_estado Valor de TurnoEstado::*
     */
    public function notificarCambioEstado(
        int    $turno_id,
        int    $centro_id,
        string $nuevo_estado
    ): void {
        $email_cliente  = (string) get_post_meta($turno_id, 'email_cliente',  true);
        $nombre_cliente = (string) get_post_meta($turno_id, 'nombre_cliente', true);
        $fecha          = (string) get_post_meta($turno_id, 'fecha',          true);
        $hora_inicio    = (string) get_post_meta($turno_id, 'hora_inicio',    true);
        $token_turno    = (string) get_post_meta($turno_id, 'token_turno',    true);

        // El meta_key en la BD es 'servicio_id'.
        $servicio_id    = (int) get_post_meta($turno_id, 'servicio_id', true);
        $nombre_servicio = get_the_title($servicio_id) ?: 'el servicio';
        $nombre_centro   = get_the_title($centro_id)   ?: 'el centro';

        if (empty($email_cliente)) {
            error_log("TurnoEstadoMailer: No se encontró email_cliente para turno ID {$turno_id}");
            return;
        }

        $fecha_fmt       = date_i18n('d \d\e F \d\e Y', strtotime($fecha));
        $url_cancelacion = home_url('cancelar-turno') . '?token=' . $token_turno;
        $headers         = ['Content-Type: text/html; charset=UTF-8'];

        switch ($nuevo_estado) {

            case TurnoEstado::APROBADO:
                $asunto = "✓ Tu turno en {$nombre_centro} está confirmado";
                $cuerpo = self::templateAprobado(
                    $nombre_cliente, $nombre_centro, $nombre_servicio,
                    $fecha_fmt, $hora_inicio, $url_cancelacion
                );
                break;

            case TurnoEstado::CANCELADO:
                $asunto = "Tu turno en {$nombre_centro} fue cancelado";
                $cuerpo = self::templateCancelado(
                    $nombre_cliente, $nombre_centro, $nombre_servicio,
                    $fecha_fmt, $hora_inicio
                );
                break;

            case TurnoEstado::PENDIENTE:
                $asunto = "Tu turno en {$nombre_centro} está pendiente de confirmación";
                $cuerpo = self::templatePendiente(
                    $nombre_cliente, $nombre_centro, $nombre_servicio,
                    $fecha_fmt, $hora_inicio
                );
                break;

            default:
                // Estados internos: completado, ausente, rechazado, expirado.
                // El centro los registra para su gestión pero no notificamos al cliente.
                error_log("TurnoEstadoMailer: Estado '{$nuevo_estado}' no requiere notificación al cliente. Turno ID {$turno_id}");
                return;
        }

        $enviado = wp_mail($email_cliente, $asunto, $cuerpo, $headers);

        if ($enviado) {
            error_log("TurnoEstadoMailer: Email '{$nuevo_estado}' enviado a {$email_cliente} (turno ID {$turno_id})");
        } else {
            error_log("TurnoEstadoMailer: ERROR al enviar email '{$nuevo_estado}' a {$email_cliente} (turno ID {$turno_id})");
        }
    }

    // ── Templates privados ───────────────────────────────────────────────────
    //
    // Estos métodos son estáticos porque no dependen del estado de la instancia.
    // Son privados porque son detalles de implementación: solo este mailer
    // decide cuándo y cómo renderizarlos.
    //
    // Terminología: "servicio" (no "tratamiento"), "aprobado" (no "activo").

    private static function templateAprobado(
        string $nombre_cliente,
        string $nombre_centro,
        string $nombre_servicio,
        string $fecha_fmt,
        string $hora_inicio,
        string $url_cancelacion
    ): string {
        return <<<HTML
        <div style="font-family:Arial,sans-serif;line-height:1.6;color:#333;max-width:600px;">
            <h2>¡Hola, {$nombre_cliente}!</h2>
            <p>
                Tu turno en <strong>{$nombre_centro}</strong> fue
                <strong style="color:#2e7d32;">aprobado</strong>.
                Aquí están los detalles:
            </p>
            <table cellpadding="10" style="width:100%;max-width:600px;border-collapse:collapse;margin:20px 0;border:1px solid #ddd;">
                <tr style="border-bottom:1px solid #ddd;">
                    <td style="background:#f9f9f9;width:140px;font-weight:bold;">Centro:</td>
                    <td>{$nombre_centro}</td>
                </tr>
                <tr style="border-bottom:1px solid #ddd;">
                    <td style="background:#f9f9f9;font-weight:bold;">Servicio:</td>
                    <td>{$nombre_servicio}</td>
                </tr>
                <tr style="border-bottom:1px solid #ddd;">
                    <td style="background:#f9f9f9;font-weight:bold;">Fecha:</td>
                    <td>{$fecha_fmt}</td>
                </tr>
                <tr>
                    <td style="background:#f9f9f9;font-weight:bold;">Hora:</td>
                    <td>{$hora_inicio} hs</td>
                </tr>
            </table>
            <p>Si necesitás cancelar tu turno, podés hacerlo aquí:</p>
            <p style="text-align:center;margin:24px 0;">
                <a href="{$url_cancelacion}"
                   style="display:inline-block;background:#d9534f;color:#fff;padding:12px 24px;text-decoration:none;border-radius:6px;font-weight:bold;">
                    Cancelar turno
                </a>
            </p>
            <hr style="border:0;border-top:1px solid #eee;margin:20px 0;">
            <p style="font-size:0.85em;color:#777;">— Equipo de RD Tecno Belleza</p>
        </div>
        HTML;
    }

    private static function templateCancelado(
        string $nombre_cliente,
        string $nombre_centro,
        string $nombre_servicio,
        string $fecha_fmt,
        string $hora_inicio
    ): string {
        return <<<HTML
        <div style="font-family:Arial,sans-serif;line-height:1.6;color:#333;max-width:600px;">
            <h2>Hola, {$nombre_cliente}</h2>
            <p>
                Te informamos que tu turno en <strong>{$nombre_centro}</strong>
                fue <strong style="color:#c62828;">cancelado</strong>.
            </p>
            <table cellpadding="10" style="width:100%;max-width:600px;border-collapse:collapse;margin:20px 0;border:1px solid #ddd;">
                <tr style="border-bottom:1px solid #ddd;">
                    <td style="background:#f9f9f9;width:140px;font-weight:bold;">Centro:</td>
                    <td>{$nombre_centro}</td>
                </tr>
                <tr style="border-bottom:1px solid #ddd;">
                    <td style="background:#f9f9f9;font-weight:bold;">Servicio:</td>
                    <td>{$nombre_servicio}</td>
                </tr>
                <tr style="border-bottom:1px solid #ddd;">
                    <td style="background:#f9f9f9;font-weight:bold;">Fecha:</td>
                    <td>{$fecha_fmt}</td>
                </tr>
                <tr>
                    <td style="background:#f9f9f9;font-weight:bold;">Hora:</td>
                    <td>{$hora_inicio} hs</td>
                </tr>
            </table>
            <p>
                Si querés reservar en otro horario, comunicate con el centro o
                esperá a que compartan un nuevo enlace de agenda.
            </p>
            <hr style="border:0;border-top:1px solid #eee;margin:20px 0;">
            <p style="font-size:0.85em;color:#777;">— Equipo de RD Tecno Belleza</p>
        </div>
        HTML;
    }

    private static function templatePendiente(
        string $nombre_cliente,
        string $nombre_centro,
        string $nombre_servicio,
        string $fecha_fmt,
        string $hora_inicio
    ): string {
        return <<<HTML
        <div style="font-family:Arial,sans-serif;line-height:1.6;color:#333;max-width:600px;">
            <h2>Hola, {$nombre_cliente}</h2>
            <p>
                Tu turno en <strong>{$nombre_centro}</strong> está
                <strong style="color:#856404;">pendiente de confirmación</strong>.
                El centro revisará tu solicitud y te avisaremos cuando esté aprobado.
            </p>
            <table cellpadding="10" style="width:100%;max-width:600px;border-collapse:collapse;margin:20px 0;border:1px solid #ddd;">
                <tr style="border-bottom:1px solid #ddd;">
                    <td style="background:#f9f9f9;width:140px;font-weight:bold;">Centro:</td>
                    <td>{$nombre_centro}</td>
                </tr>
                <tr style="border-bottom:1px solid #ddd;">
                    <td style="background:#f9f9f9;font-weight:bold;">Servicio:</td>
                    <td>{$nombre_servicio}</td>
                </tr>
                <tr style="border-bottom:1px solid #ddd;">
                    <td style="background:#f9f9f9;font-weight:bold;">Fecha:</td>
                    <td>{$fecha_fmt}</td>
                </tr>
                <tr>
                    <td style="background:#f9f9f9;font-weight:bold;">Hora:</td>
                    <td>{$hora_inicio} hs</td>
                </tr>
            </table>
            <p style="font-size:0.9em;color:#777;">
                No es necesario que hagas nada por ahora. Te enviaremos un email
                cuando el centro apruebe o cancele tu turno.
            </p>
            <hr style="border:0;border-top:1px solid #eee;margin:20px 0;">
            <p style="font-size:0.85em;color:#777;">— Equipo de RD Tecno Belleza</p>
        </div>
        HTML;
    }
}
