<?php

declare(strict_types=1);
namespace RDT\CentrosEstetica\Notifications;

use function RDT\CentrosEstetica\Helpers\get_centro_by_user;
use function RDT\CentrosEstetica\Helpers\get_datos_centro;

/**
 * SsaBookingMail
 *
 * Envía el email de confirmación de jornada al centro estético
 * con el enlace de la agenda pública para compartir con sus clientes.
 *
 * Responsabilidad única: construir y enviar este email.
 * Quién decide cuándo llamarlo (hook SSA, endpoint REST, etc.) es
 * responsabilidad de la capa que lo invoca.
 */
final class SsaBookingMail
{
    private const SLUG_AGENDA = 'reserva-turno-depilacion';

    /**
     * Envía el mail de confirmación al centro estético.
     * Si además se pasa $email_admin, envía una copia al administrador
     * con el asunto adaptado para indicar que es una reserva gestionada.
     *
     * @param int    $wp_user_id       ID del usuario WordPress del centro.
     * @param string $fecha            Fecha de la jornada (Y-m-d).
     * @param string $hora_inicio      Hora de inicio de la jornada (H:i).
     * @param string $hora_fin         Hora de fin de la jornada (H:i).
     * @param string $token            Token público de la jornada.
     * @param bool   $es_reprogramacion
     * @param bool   $solicito_gel     Si se solicitó el pote de gel neutro.
     * @param string $email_admin      Si no vacío, envía copia al admin con ese email.
     */
    public function enviar(
        int    $wp_user_id,
        string $fecha,
        string $hora_inicio,
        string $hora_fin,
        string $token,
        bool   $es_reprogramacion = false,
        bool   $solicito_gel      = false,
        string $email_admin       = ''
    ): void {
        $id_centro = get_centro_by_user($wp_user_id);

        if (!$id_centro) {
            error_log("SsaBookingMail: No se encontró centro para wp_user_id={$wp_user_id}");
            return;
        }

        $datos_centro = get_datos_centro($id_centro);

        if (empty($datos_centro['email'])) {
            error_log("SsaBookingMail: El centro ID={$id_centro} no tiene email configurado");
            return;
        }

        $email_centro     = $datos_centro['email'];
        $nombre_centro    = $datos_centro['nombre'];
        $url_agenda       = home_url(self::SLUG_AGENDA) . '/?token=' . $token;
        $fecha_formateada = date_i18n('d \d\e F \d\e Y', strtotime($fecha));

        $url_whatsapp = 'https://wa.me/?text=' . urlencode(
            "¡Hola! 👋 Te comparto el enlace para reservar tu turno de depilación láser en mi agenda.\n\nIngresá aquí para elegir tu horario:\n{$url_agenda}"
        );
        $url_mi_cuenta = home_url('/my-account/');

        $headers = ['Content-Type: text/html; charset=UTF-8'];
        $cuerpo  = $this->construirCuerpo(
            $nombre_centro, $fecha_formateada, $hora_inicio, $hora_fin,
            $url_agenda, $url_whatsapp, $url_mi_cuenta, $solicito_gel
        );

        // ── Email al centro ───────────────────────────────────────────────────
        $asunto_centro = $es_reprogramacion
            ? 'Tu jornada ha sido reprogramada'
            : 'Tu agenda de turnos está lista';

        $enviado = wp_mail($email_centro, $asunto_centro, $cuerpo, $headers);

        if ($enviado) {
            error_log("SsaBookingMail: Email enviado al centro {$email_centro} (token={$token})");
        } else {
            error_log("SsaBookingMail: ERROR al enviar email al centro {$email_centro}");
        }

        // ── Email al admin (solo cuando gestiona la reserva en nombre del centro) ──
        // El cuerpo es el mismo — el admin necesita ver los mismos datos que el centro,
        // incluyendo el link de agenda y la solicitud de gel.
        if (!empty($email_admin)) {
            $asunto_admin = "Nueva jornada reservada para: {$nombre_centro}";
            $enviado_admin = wp_mail($email_admin, $asunto_admin, $cuerpo, $headers);

            if ($enviado_admin) {
                error_log("SsaBookingMail: Email de copia enviado al admin {$email_admin}");
            } else {
                error_log("SsaBookingMail: ERROR al enviar email al admin {$email_admin}");
            }
        }
    }

    private function construirCuerpo(
        string $nombre,
        string $fecha_formateada,
        string $hora_inicio,
        string $hora_fin,
        string $url_agenda,
        string $url_whatsapp,
        string $url_mi_cuenta,
        bool   $solicito_gel = false
    ): string {
        $fila_gel = $solicito_gel
            ? '<tr style="border-bottom: 1px solid #ddd;"><td style="background-color: #f9f9f9; font-weight: bold;">Productos extras:</td><td>Pote Gel Neutro 5 kg ✓</td></tr>'
            : '';

        return <<<HTML
        <div style="font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px;">
            <h2>¡Hola, {$nombre}!</h2>
            <p>Tu jornada de RD-Tecno Belleza Soprano Titanium fue registrada con éxito. Aquí están los detalles:</p>
            <table cellpadding="10" style="width: 100%; max-width: 600px; border-collapse: collapse; margin: 20px 0; border: 1px solid #ddd;">
                <tr style="border-bottom: 1px solid #ddd;">
                    <td style="background-color: #f9f9f9; width: 140px; font-weight: bold;">Fecha:</td>
                    <td>{$fecha_formateada}</td>
                </tr>
                <tr style="border-bottom: 1px solid #ddd;">
                    <td style="background-color: #f9f9f9; font-weight: bold;">Horario:</td>
                    <td>{$hora_inicio} a {$hora_fin} hs</td>
                </tr>
                {$fila_gel}
            </table>
            <p>
                Compartí el siguiente enlace con tus clientes para que puedan reservar su turno:
            </p>
            <p style="background: #f5f5f5; border-radius: 6px; padding: 12px 16px; font-size: 0.9em; word-break: break-all; margin: 16px 0;">
                {$url_agenda}
            </p>
            <p style="text-align: center; margin: 24px 0 8px;">
                <a href="{$url_whatsapp}"
                   style="display: inline-block; background-color: #25D366; color: white; padding: 13px 28px; text-decoration: none; border-radius: 6px; font-weight: bold; font-size: 1rem;">
                    📲 Compartir por WhatsApp
                </a>
            </p>
            <p style="text-align: center; margin: 0 0 24px;">
                <a href="{$url_mi_cuenta}"
                   style="display: inline-block; background-color: #4a90e2; color: white; padding: 13px 28px; text-decoration: none; border-radius: 6px; font-weight: bold; font-size: 1rem;">
                    Gestionar mis turnos
                </a>
            </p>
            <hr style="border: 0; border-top: 1px solid #eee; margin: 20px 0;">
            <p style="font-size: 0.85em; color: #777;">— Equipo de RD Tecno Belleza</p>
        </div>
        HTML;
    }
}
