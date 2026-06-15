<?php

declare(strict_types=1);
namespace RDT\CentrosEstetica\Notifications;

/**
 * RegistroMailer
 *
 * Gestiona todos los emails del flujo de registro y aprobación
 * de centros estéticos.
 *
 * Emails que maneja:
 *  - Solicitud pendiente → al nuevo usuario tras registrarse
 *  - Nuevo registro      → al admin con botones de aprobar/rechazar
 *  - Cuenta aprobada     → al usuario con enlace para crear contraseña
 *  - Registro rechazado  → al usuario notificando el rechazo
 */
final class RegistroMailer
{
    private const HEADERS = ['Content-Type: text/html; charset=UTF-8'];

    /**
     * Notifica al nuevo usuario que su solicitud está siendo procesada.
     */
    public static function enviarPendienteAlUsuario(
        string $email,
        string $nombre_centro
    ): void {
        $asunto = 'Tu solicitud de registro está siendo procesada';

        $cuerpo = <<<HTML
        <div style="font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px;">
            <h2>¡Gracias por registrarte, {$nombre_centro}!</h2>
            <p>
                Recibimos tu solicitud de registro en <strong>RD-Tecno Belleza</strong>.
                Nuestro equipo está revisando tus datos y en breve te enviaremos
                un correo confirmando el acceso a tu cuenta.
            </p>
            <p>Una vez aprobada tu cuenta podrás:</p>
            <ul>
                <li>Reservar jornadas de alquiler del equipo Soprano Titanium</li>
                <li>Gestionar los turnos de tus clientas</li>
                <li>Compartir tu agenda pública</li>
            </ul>
            <p>Si tenés alguna consulta, respondé este correo o contactanos por WhatsApp.</p>
            <hr style="border: 0; border-top: 1px solid #eee; margin: 20px 0;">
            <p style="font-size: 0.85em; color: #777;">&mdash; Equipo de RD Tecno Belleza</p>
        </div>
        HTML;

        $enviado = wp_mail($email, $asunto, $cuerpo, self::HEADERS);
        error_log('RegistroMailer: pendiente ' . ($enviado ? 'OK' : 'ERROR') . " → {$email}");
    }

    /**
     * Notifica al admin que hay un nuevo centro esperando aprobación.
     * Incluye botones de Aprobar y Rechazar con tokens de un solo uso.
     */
    public static function enviarNuevoRegistroAlAdmin(
        string $nombre_centro,
        string $email_usuario,
        string $telefono,
        string $localidad,
        string $provincia,
        int    $user_id,
        string $token
    ): void {
        $email_admin = get_option('admin_email');
        $asunto      = 'Nuevo centro estético pendiente de aprobación';

        $url_aprobar  = rest_url("rdt/v1/registro/aprobar?token={$token}");
        $url_rechazar = rest_url("rdt/v1/registro/rechazar?token={$token}");

        $cuerpo = <<<HTML
        <div style="font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px;">
            <h2>Nuevo centro estético registrado</h2>
            <p>Un nuevo centro estético completó el formulario de registro y está esperando aprobación:</p>
            <table cellpadding="10" style="width: 100%; max-width: 600px; border-collapse: collapse; margin: 20px 0; border: 1px solid #ddd;">
                <tr style="border-bottom: 1px solid #ddd;">
                    <td style="background-color: #f9f9f9; width: 140px; font-weight: bold;">Centro:</td>
                    <td>{$nombre_centro}</td>
                </tr>
                <tr style="border-bottom: 1px solid #ddd;">
                    <td style="background-color: #f9f9f9; font-weight: bold;">Email:</td>
                    <td>{$email_usuario}</td>
                </tr>
                <tr style="border-bottom: 1px solid #ddd;">
                    <td style="background-color: #f9f9f9; font-weight: bold;">Teléfono:</td>
                    <td>{$telefono}</td>
                </tr>
                <tr style="border-bottom: 1px solid #ddd;">
                    <td style="background-color: #f9f9f9; font-weight: bold;">Localidad:</td>
                    <td>{$localidad}, {$provincia}</td>
                </tr>
            </table>
            <p style="text-align: center; margin: 28px 0 8px;">
                <a href="{$url_aprobar}"
                   style="display: inline-block; background-color: #4A7A84; color: white; padding: 13px 32px;
                          text-decoration: none; border-radius: 6px; font-weight: bold; font-size: 1rem; margin-right: 12px;">
                    ✓ Aprobar
                </a>
                <a href="{$url_rechazar}"
                   style="display: inline-block; background-color: #c0392b; color: white; padding: 13px 32px;
                          text-decoration: none; border-radius: 6px; font-weight: bold; font-size: 1rem;">
                    ✗ Rechazar
                </a>
            </p>
            <p style="text-align: center; font-size: 0.78rem; color: #999; margin-top: 6px;">
                Estos enlaces son de un solo uso y expiran en 7 días.
            </p>
            <hr style="border: 0; border-top: 1px solid #eee; margin: 20px 0;">
            <p style="font-size: 0.85em; color: #777;">&mdash; Sistema RD Tecno Belleza</p>
        </div>
        HTML;

        $enviado = wp_mail($email_admin, $asunto, $cuerpo, self::HEADERS);
        error_log('RegistroMailer: admin ' . ($enviado ? 'OK' : 'ERROR') . " → {$email_admin}");
    }

    /**
     * Notifica al centro que su cuenta fue aprobada.
     * Incluye el enlace para que el usuario establezca su contraseña.
     */
    public static function enviarAprobacionAlUsuario(
        string $email,
        string $nombre,
        string $url_password = ''
    ): void {
        $url_cuenta = home_url('/my-account/');
        $asunto     = '¡Tu cuenta en RD-Tecno Belleza fue aprobada!';

        $bloque_password = '';
        if (!empty($url_password)) {
            $bloque_password = <<<HTML
            <p>Para ingresar a tu cuenta, primero necesitás crear tu contraseña:</p>
            <p style="text-align: center; margin: 24px 0;">
                <a href="{$url_password}"
                   style="display: inline-block; background-color: #4A7A84; color: white; padding: 13px 28px;
                          text-decoration: none; border-radius: 6px; font-weight: bold; font-size: 1rem;">
                    Crear mi contraseña
                </a>
            </p>
            <p style="font-size: 0.82rem; color: #777; text-align: center;">
                Este enlace expira en 24 horas. Si no lo usás a tiempo, podés solicitar uno nuevo
                desde <a href="{$url_cuenta}" style="color: #4A7A84;">Mi Cuenta</a>.
            </p>
            HTML;
        }

        $cuerpo = <<<HTML
        <div style="font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px;">
            <h2>¡Bienvenido/a, {$nombre}!</h2>
            <p>
                Tu cuenta como centro estético en <strong>RD-Tecno Belleza</strong>
                fue aprobada. Ya podés comenzar a usar la plataforma.
            </p>
            <p>Desde tu cuenta podrás:</p>
            <ul>
                <li>Reservar jornadas de alquiler del equipo Soprano Titanium</li>
                <li>Gestionar los turnos de tus clientas</li>
                <li>Compartir tu agenda pública con tus clientas</li>
            </ul>
            {$bloque_password}
            <hr style="border: 0; border-top: 1px solid #eee; margin: 20px 0;">
            <p style="font-size: 0.85em; color: #777;">&mdash; Equipo de RD Tecno Belleza</p>
        </div>
        HTML;

        $enviado = wp_mail($email, $asunto, $cuerpo, self::HEADERS);
        error_log('RegistroMailer: aprobación ' . ($enviado ? 'OK' : 'ERROR') . " → {$email}");
    }

    /**
     * Notifica al usuario que su registro fue rechazado.
     */
    public static function enviarRechazoAlUsuario(
        string $email,
        string $nombre
    ): void {
        $asunto = 'Tu solicitud de registro en RD-Tecno Belleza';

        $cuerpo = <<<HTML
        <div style="font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px;">
            <h2>Hola, {$nombre}</h2>
            <p>
                Luego de revisar tu solicitud de registro en <strong>RD-Tecno Belleza</strong>,
                lamentablemente no podemos aprobar tu cuenta en este momento.
            </p>
            <p>
                Si creés que esto es un error o querés más información,
                podés contactarnos respondiendo este correo.
            </p>
            <hr style="border: 0; border-top: 1px solid #eee; margin: 20px 0;">
            <p style="font-size: 0.85em; color: #777;">&mdash; Equipo de RD Tecno Belleza</p>
        </div>
        HTML;

        $enviado = wp_mail($email, $asunto, $cuerpo, self::HEADERS);
        error_log('RegistroMailer: rechazo ' . ($enviado ? 'OK' : 'ERROR') . " → {$email}");
    }
}
