<?php

declare(strict_types=1);
namespace RDT\CentrosEstetica\Notifications;

/**
 * TurnoAprobacionMailer
 *
 * Gestiona los emails del flujo de aprobación de turnos por token.
 * Este flujo se activa cuando el centro tiene habilitado
 * 'requiere_aprobacion_turno = 1' en su CPT centro_estetico.
 *
 * Emails de este flujo:
 *   1. Al centro:   "Nuevo turno pendiente" con botones Aprobar / Rechazar
 *   2. Al cliente:  "Tu turno está pendiente de aprobación" (acuse de recibo)
 *   3. Al cliente:  "Tu turno fue aprobado" (con botón de cancelación)
 *   4. Al cliente:  "Tu turno fue rechazado"
 *
 * SRP: solo gestiona emails del flujo de aprobación por token desde email.
 *
 * Relación con otras clases de notificación:
 * - TurnoNotification  → confirmación y cancelación estándar (flujo sin aprobación)
 * - TurnoEstadoMailer  → cambio manual de estado desde el panel del centro
 * - TurnoAprobacionMailer → este archivo
 *
 * Terminología canónica del dominio:
 * - "servicio"  (no "tratamiento") — alineado con ServiciosClientes CPT
 * - "aprobado"  (no "activo")      — alineado con TurnoEstado::APROBADO
 */
final class TurnoAprobacionMailer
{
    /**
     * Envía al centro el email de turno pendiente con botones de aprobación.
     * Genera y persiste el token de un solo uso (TTL 7 días) vinculado al turno.
     *
     * @param int    $turno_id
     * @param int    $centro_id
     * @param string $nombre_cliente
     * @param string $email_cliente
     * @param string $telefono_cliente
     * @param string $nombre_servicio   Nombre del CPT servicios_clientes
     * @param string $fecha             YYYY-MM-DD
     * @param string $hora_inicio       HH:MM
     */
    public function enviarPendienteAlCentro(
        int    $turno_id,
        int    $centro_id,
        string $nombre_cliente,
        string $email_cliente,
        string $telefono_cliente,
        string $nombre_servicio,
        string $fecha,
        string $hora_inicio
    ): void {
        $usuario_responsable_id = get_post_meta($centro_id, 'usuario_responsable', true);

        if (empty($usuario_responsable_id)) {
            error_log("TurnoAprobacionMailer::enviarPendienteAlCentro - No se encontró usuario_responsable para centro ID {$centro_id}");
            return;
        }

        $centro_usuario = get_userdata((int) $usuario_responsable_id);
        if (!$centro_usuario) {
            error_log("TurnoAprobacionMailer::enviarPendienteAlCentro - No se encontró WP User con ID {$usuario_responsable_id}");
            return;
        }

        $fecha_fmt = date_i18n('d \d\e F \d\e Y', strtotime($fecha));

        // Token de aprobación de un solo uso, TTL 7 días
        $token = wp_generate_password(40, false, false);
        update_post_meta($turno_id, 'rdt_aprobacion_token',        $token);
        update_post_meta($turno_id, 'rdt_aprobacion_token_expiry', time() + (7 * DAY_IN_SECONDS));

        $url_aprobar  = rest_url("rdt/v1/turno/{$turno_id}/aprobar")  . "?token={$token}";
        $url_rechazar = rest_url("rdt/v1/turno/{$turno_id}/rechazar") . "?token={$token}";

        $asunto  = "Nuevo turno pendiente de aprobación — {$nombre_cliente}";
        $headers = ['Content-Type: text/html; charset=UTF-8'];

        $cuerpo = <<<HTML
        <div style="font-family:Arial,sans-serif;line-height:1.6;color:#333;max-width:600px;">
            <h2>Hola, {$centro_usuario->display_name}</h2>
            <p>
                Una persona reservó un turno que requiere tu aprobación.
                Revisá los datos y decidí si lo confirmás o rechazás.
            </p>
            <table cellpadding="10" style="width:100%;max-width:600px;border-collapse:collapse;margin:20px 0;border:1px solid #ddd;">
                <tr style="border-bottom:1px solid #ddd;">
                    <td style="background:#f9f9f9;width:140px;font-weight:bold;">Nombre:</td>
                    <td>{$nombre_cliente}</td>
                </tr>
                <tr style="border-bottom:1px solid #ddd;">
                    <td style="background:#f9f9f9;font-weight:bold;">Email:</td>
                    <td>{$email_cliente}</td>
                </tr>
                <tr style="border-bottom:1px solid #ddd;">
                    <td style="background:#f9f9f9;font-weight:bold;">Teléfono:</td>
                    <td>{$telefono_cliente}</td>
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
            <p style="text-align:center;margin:28px 0;">
                <a href="{$url_aprobar}"
                   style="display:inline-block;background:#2e7d32;color:#fff;padding:13px 28px;text-decoration:none;border-radius:6px;font-weight:bold;font-size:1rem;margin-right:16px;">
                    ✓ Aprobar turno
                </a>
                <a href="{$url_rechazar}"
                   style="display:inline-block;background:#c62828;color:#fff;padding:13px 28px;text-decoration:none;border-radius:6px;font-weight:bold;font-size:1rem;">
                    ✗ Rechazar turno
                </a>
            </p>
            <p style="font-size:0.83em;color:#999;text-align:center;">
                Estos botones son válidos por 7 días. Si no tomás acción, el turno permanecerá pendiente.
            </p>
            <hr style="border:0;border-top:1px solid #eee;margin:20px 0;">
            <p style="font-size:0.85em;color:#777;">— Equipo de RD Tecno Belleza</p>
        </div>
        HTML;

        $enviado = wp_mail($centro_usuario->user_email, $asunto, $cuerpo, $headers);

        if (!$enviado) {
            error_log("TurnoAprobacionMailer: ERROR al enviar email de aprobación pendiente a {$centro_usuario->user_email}");
        }
    }

    /**
     * Notifica al cliente que su turno fue recibido y está pendiente de aprobación.
     * Es un acuse de recibo: le explica que el centro revisará la solicitud.
     *
     * @param string $nombre_servicio  Nombre del CPT servicios_clientes
     */
    public function enviarPendienteAlCliente(
        string $email_cliente,
        string $nombre_cliente,
        string $nombre_centro,
        string $nombre_servicio,
        string $fecha,
        string $hora_inicio
    ): void {
        $fecha_fmt = date_i18n('d \d\e F \d\e Y', strtotime($fecha));
        $asunto    = "Tu turno en {$nombre_centro} está pendiente de aprobación";
        $headers   = ['Content-Type: text/html; charset=UTF-8'];

        $cuerpo = <<<HTML
        <div style="font-family:Arial,sans-serif;line-height:1.6;color:#333;max-width:600px;">
            <h2>Hola, {$nombre_cliente}</h2>
            <p>
                Gracias por reservar tu turno en <strong>{$nombre_centro}</strong>.
                Tu solicitud fue recibida y está pendiente de aprobación por parte del centro.
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

        $enviado = wp_mail($email_cliente, $asunto, $cuerpo, $headers);
        if (!$enviado) {
            error_log("TurnoAprobacionMailer: ERROR al enviar email de pendiente al cliente {$email_cliente}");
        }
    }

    /**
     * Notifica al cliente que su turno fue aprobado por el centro vía email.
     * Incluye datos completos del centro y botón de cancelación.
     *
     * @param string $nombre_servicio  Nombre del CPT servicios_clientes
     */
    public function enviarAprobadoAlCliente(
        int    $turno_id,
        string $email_cliente,
        string $nombre_cliente,
        string $nombre_centro,
        string $telefono_centro,
        string $direccion_centro,
        string $email_centro,
        string $nombre_servicio,
        string $fecha,
        string $hora_inicio,
        string $hora_fin,
        string $token_turno
    ): void {
        $fecha_fmt       = date_i18n('d \d\e F \d\e Y', strtotime($fecha));
        $url_cancelacion = home_url('cancelar-turno') . '?token=' . $token_turno;
        $url_agendar     = rest_url("rdt/v1/turno/{$turno_id}/agendar") . "?token={$token_turno}";

        $asunto  = "¡Tu turno en {$nombre_centro} fue aprobado!";
        $headers = ['Content-Type: text/html; charset=UTF-8'];

        $cuerpo = <<<HTML
        <div style="font-family:Arial,sans-serif;line-height:1.6;color:#333;max-width:600px;">
            <h2>¡Hola, {$nombre_cliente}!</h2>
            <p>
                Buenas noticias: el centro <strong>{$nombre_centro}</strong>
                aprobó tu turno. Aquí están los detalles:
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
                    <td style="background:#f9f9f9;font-weight:bold;">Dirección:</td>
                    <td>{$direccion_centro}</td>
                </tr>
                <tr style="border-bottom:1px solid #ddd;">
                    <td style="background:#f9f9f9;font-weight:bold;">Teléfono:</td>
                    <td>{$telefono_centro}</td>
                </tr>
                <tr style="border-bottom:1px solid #ddd;">
                    <td style="background:#f9f9f9;font-weight:bold;">Email:</td>
                    <td>{$email_centro}</td>
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
            <p>Podés agregarlo a tu calendario o cancelarlo si es necesario:</p>
            <p style="text-align:center;margin:28px 0;">
                <a href="{$url_agendar}" target="_blank"
                   style="display:inline-block;background:#4285F4;color:#fff;padding:12px 24px;text-decoration:none;border-radius:6px;font-weight:bold;margin-right:10px;font-size:0.9rem;">
                    Agregar al Calendario
                </a>
                <a href="{$url_cancelacion}"
                   style="display:inline-block;background:#d9534f;color:#fff;padding:12px 24px;text-decoration:none;border-radius:6px;font-weight:bold;font-size:0.9rem;">
                    Cancelar Turno
                </a>
            </p>
            <hr style="border:0;border-top:1px solid #eee;margin:20px 0;">
            <p style="font-size:0.85em;color:#777;">— Equipo de RD Tecno Belleza</p>
        </div>
        HTML;

        $enviado = wp_mail($email_cliente, $asunto, $cuerpo, $headers);
        if (!$enviado) {
            error_log("TurnoAprobacionMailer: ERROR al enviar email de aprobación a {$email_cliente}");
        }
    }

    /**
     * Notifica al cliente que su turno fue rechazado por el centro.
     *
     * @param string $nombre_servicio  Nombre del CPT servicios_clientes
     */
    public function enviarRechazadoAlCliente(
        string $email_cliente,
        string $nombre_cliente,
        string $nombre_centro,
        string $nombre_servicio,
        string $fecha,
        string $hora_inicio
    ): void {
        $fecha_fmt = date_i18n('d \d\e F \d\e Y', strtotime($fecha));
        $asunto    = "Tu turno en {$nombre_centro} no pudo ser confirmado";
        $headers   = ['Content-Type: text/html; charset=UTF-8'];

        $cuerpo = <<<HTML
        <div style="font-family:Arial,sans-serif;line-height:1.6;color:#333;max-width:600px;">
            <h2>Hola, {$nombre_cliente}</h2>
            <p>
                Lamentamos informarte que el centro <strong>{$nombre_centro}</strong>
                no pudo confirmar tu turno para:
            </p>
            <table cellpadding="10" style="width:100%;max-width:600px;border-collapse:collapse;margin:20px 0;border:1px solid #ddd;">
                <tr style="border-bottom:1px solid #ddd;">
                    <td style="background:#f9f9f9;width:140px;font-weight:bold;">Servicio:</td>
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
                Podés comunicarte con el centro para consultar disponibilidad
                o reservar en otro horario.
            </p>
            <hr style="border:0;border-top:1px solid #eee;margin:20px 0;">
            <p style="font-size:0.85em;color:#777;">— Equipo de RD Tecno Belleza</p>
        </div>
        HTML;

        $enviado = wp_mail($email_cliente, $asunto, $cuerpo, $headers);
        if (!$enviado) {
            error_log("TurnoAprobacionMailer: ERROR al enviar email de rechazo a {$email_cliente}");
        }
    }
}
