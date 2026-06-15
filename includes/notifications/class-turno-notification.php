<?php
namespace RDT\CentrosEstetica\Notifications;

class TurnoNotification {

    /**
     * URL base para la cancelación. Se asume que existe la página /cancelar-turno/
     * que procesa el shortcode o script de cancelación.
     */
    private const SLUG_CANCELACION = 'cancelar-turno';

    public function notificarCentro(array $data): bool|\WP_Error {

        // El dato $data['centro_id'] que recibimos es el ID del CPT 'centro_estetico'.
        // Necesitamos obtener el ID del usuario de WordPress asociado a ese centro,
        // que se guarda en el campo 'usuario_responsable'.
        $centro_post_id = $data['centro_id'];
        $usuario_responsable_id = get_post_meta($centro_post_id, 'usuario_responsable', true);

        if (empty($usuario_responsable_id)) {
            error_log("TurnoNotification: No se encontró el meta 'usuario_responsable' para el centro con Post ID: " . $centro_post_id);
            return new \WP_Error('usuario_responsable_no_encontrado', 'No se pudo determinar el destinatario del centro.');
        }

        $centro_usuario = get_userdata((int) $usuario_responsable_id);

        if (!$centro_usuario) {
            error_log("TurnoNotification: No se encontró un usuario de WP con el ID: " . $usuario_responsable_id);
            return new \WP_Error('centro_usuario_no_encontrado', 'Usuario del centro no encontrado');
        }

        error_log("TurnoNotification: Enviando email al centro {$centro_usuario->user_email} para el turno de {$data['nombre_cliente']}");

        $nombre_display    = $centro_usuario->display_name;
        $nombre_cliente    = $data['nombre_cliente'];
        $nombre_servicio   = get_the_title($data['servicio_id']);
        $fecha_formateada  = date_i18n('d \d\e F \d\e Y', strtotime($data['fecha']));
        $hora_inicio       = $data['hora_inicio'];
        $url_mi_cuenta     = home_url('/my-account/');

        $asunto  = 'Nuevo turno reservado en tu agenda';
        $headers = ['Content-Type: text/html; charset=UTF-8'];

        $cuerpo = <<<HTML
        <div style="font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px;">
            <h2>¡Hola, {$nombre_display}!</h2>
            <p>Una clienta acaba de reservar un nuevo turno en tu agenda. Aquí están los detalles:</p>
            <table cellpadding="10" style="width: 100%; max-width: 600px; border-collapse: collapse; margin: 20px 0; border: 1px solid #ddd;">
                <tr style="border-bottom: 1px solid #ddd;">
                    <td style="background-color: #f9f9f9; width: 140px; font-weight: bold;">Clienta:</td>
                    <td>{$nombre_cliente}</td>
                </tr>
                <tr style="border-bottom: 1px solid #ddd;">
                    <td style="background-color: #f9f9f9; font-weight: bold;">Servicio:</td>
                    <td>{$nombre_servicio}</td>
                </tr>
                <tr style="border-bottom: 1px solid #ddd;">
                    <td style="background-color: #f9f9f9; font-weight: bold;">Fecha:</td>
                    <td>{$fecha_formateada}</td>
                </tr>
                <tr style="border-bottom: 1px solid #ddd;">
                    <td style="background-color: #f9f9f9; font-weight: bold;">Hora:</td>
                    <td>{$hora_inicio} hs</td>
                </tr>
            </table>
            <p>Podés ver y gestionar todos los turnos de tu agenda desde tu cuenta:</p>
            <p style="text-align: center; margin: 24px 0;">
                <a href="{$url_mi_cuenta}"
                   style="display: inline-block; background-color: #4a90e2; color: white; padding: 13px 28px; text-decoration: none; border-radius: 6px; font-weight: bold; font-size: 1rem;">
                    Gestionar mis turnos
                </a>
            </p>
            <hr style="border: 0; border-top: 1px solid #eee; margin: 20px 0;">
            <p style="font-size: 0.85em; color: #777;">&mdash; Equipo de RD Tecno Belleza</p>
        </div>
        HTML;

        $enviado = wp_mail($centro_usuario->user_email, $asunto, $cuerpo, $headers);

        if ($enviado) {
            error_log("TurnoNotification: Email de notificación de nuevo turno enviado a {$centro_usuario->user_email}.");
        } else {
            error_log("TurnoNotification: ERROR al enviar email de notificación de nuevo turno a {$centro_usuario->user_email}");
        }

        return true;
    }

    /**
     * Envía un email de confirmación de turno a la clienta con enlace de cancelación.
     */
    public function notificarCliente(
        string $email_cliente,
        string $nombre_cliente,
        string $nombre_servicio,
        string $nombre_centro,
        string $telefono_centro,
        string $fecha,
        string $hora_inicio,
        string $token_turno
    ): void {
        $asunto = 'Confirmación de tu turno de depilación en ' . $nombre_centro;

        // Construir URL dinámica usando home_url()
        $url_cancelacion = home_url(self::SLUG_CANCELACION) . '?token=' . $token_turno;
        //Mensaje de whatsapp para que el usuario final haga una consulta al centro estético.
        $wtsp_mens = "Hola%2C%20tengo%20un%20turno%20de%20depilaci%C3%B3n%20y%20quiero%20hacerte%20una%20consulta";
        // Cuerpo del mensaje en HTML
        $cuerpo = <<<HTML
        <div style="font-family: Arial, sans-serif; line-height: 1.6; color: #333;">
            <h2>¡Hola, {$nombre_cliente}!</h2>
            <p>Tu turno de depilación ha sido confirmado con éxito. Aquí están los detalles:</p>
            <table cellpadding="10" style="width: 100%; max-width: 600px; border-collapse: collapse; margin: 20px 0; border: 1px solid #ddd;">
                <tr style="border-bottom: 1px solid #ddd;">
                    <td style="background-color: #f9f9f9; width: 140px; font-weight: bold;">Centro estético:</td>
                    <td>{$nombre_centro}</td>
                </tr>
                <tr style="border-bottom: 1px solid #ddd;">
                    <td style="background-color: #f9f9f9; font-weight: bold;">Nombre del servicio:</td>
                    <td>{$nombre_servicio}</td>
                </tr>
                <tr style="border-bottom: 1px solid #ddd;">
                    <td style="background-color: #f9f9f9; font-weight: bold;">Fecha:</td>
                    <td>{$fecha}</td>
                </tr>
                <tr style="border-bottom: 1px solid #ddd;">
                    <td style="background-color: #f9f9f9; font-weight: bold;">Hora:</td>
                    <td>{$hora_inicio} hs</td>
                </tr>
            </table>
            <p>
                <strong>Recordá que...</strong>
                <ul>
                    <li>La zona a depilar debe estar limpia y seca antes del tratamiento (sin residuos de maquillaje, cremas ni productos)</li>
                    <li>Es recomendable afeitarse 24 o 48 hs antes de la depilación (sin arrancar el vello, método por gillete)</li>
                    <li>Si tenés tatuajes o manchas en la piel en la zona a tratar, debes taparlos</li>
                    <li>Y muy IMPORTANTE: No olvides traer tu toalla.</li>
                </ul>
            </p>
            <!-------------Botón de Whatsapp para comunicarse con el centro------------>
            <p>
                Si tenés alguna duda o necesitas ayuda, podés contactar al centro directamente por WhatsApp:
                <a href="https://wa.me/+549{$telefono_centro}?text={$wtsp_mens}" style="color: #25D366; text-decoration: none; font-weight: bold;">
                    <img src="https://upload.wikimedia.org/wikipedia/commons/6/6b/WhatsApp.svg" alt="WhatsApp" style="width: 20px; vertical-align: middle; margin-right: 5px;">
                    Enviar mensaje
                </a>
            </p>

            <p>
                Si necesitas cancelar tu turno, puedes hacerlo haciendo clic en el siguiente botón:
            </p>
            <p style="text-align: center; margin: 30px 0;">
                <a href="{$url_cancelacion}" style="background-color: #d9534f; color: white; padding: 12px 24px; text-decoration: none; border-radius: 4px; font-weight: bold;">
                    Cancelar Turno
                </a>
            </p>
            <hr style="border: 0; border-top: 1px solid #eee; margin: 20px 0;">
            <p style="font-size: 0.85em; color: #777;">
                Si el botón no funciona, copia y pega este enlace en tu navegador:<br>
                {$url_cancelacion}
            </p>
        </div>
        HTML;

        $headers = ['Content-Type: text/html; charset=UTF-8'];

        $enviado = wp_mail($email_cliente, $asunto, $cuerpo, $headers);

        if ($enviado) {
            error_log("TurnoNotification: Email de confirmación enviado a {$email_cliente}. Cuerpo: {$cuerpo}. (Token: {$token_turno})");
        } else {
            error_log("TurnoNotification: ERROR al enviar email a {$email_cliente}");
        }
        return;
    }

    /**
     * Notifica al centro estético que un cliente canceló su turno.
     */
    public function notificarCentroCancelacionPorCliente(
        int    $centro_id,
        string $nombre_cliente,
        string $nombre_servicio,
        string $fecha,
        string $hora_inicio
    ): void {
        $usuario_responsable_id = get_post_meta($centro_id, 'usuario_responsable', true);

        if (empty($usuario_responsable_id)) {
            error_log("TurnoNotification::notificarCentroCancelacionPorCliente - No se encontró 'usuario_responsable' para centro ID: {$centro_id}");
            return;
        }

        $centro_usuario = get_userdata((int) $usuario_responsable_id);

        if (!$centro_usuario) {
            error_log("TurnoNotification::notificarCentroCancelacionPorCliente - No se encontró usuario WP con ID: {$usuario_responsable_id}");
            return;
        }

        $fecha_formateada = date_i18n('d \d\e F \d\e Y', strtotime($fecha));
        $asunto           = 'Un cliente canceló su turno';

        $cuerpo = <<<HTML
        <div style="font-family: Arial, sans-serif; line-height: 1.6; color: #333;">
            <h2>Hola, {$centro_usuario->display_name}</h2>
            <p>Te informamos que un cliente canceló su turno en tu agenda:</p>
            <table cellpadding="10" style="width: 100%; max-width: 600px; border-collapse: collapse; margin: 20px 0; border: 1px solid #ddd;">
                <tr style="border-bottom: 1px solid #ddd;">
                    <td style="background-color: #f9f9f9; width: 140px; font-weight: bold;">Cliente:</td>
                    <td>{$nombre_cliente}</td>
                </tr>
                <tr style="border-bottom: 1px solid #ddd;">
                    <td style="background-color: #f9f9f9; font-weight: bold;">Servicio:</td>
                    <td>{$nombre_servicio}</td>
                </tr>
                <tr style="border-bottom: 1px solid #ddd;">
                    <td style="background-color: #f9f9f9; font-weight: bold;">Fecha cancelada:</td>
                    <td>{$fecha_formateada} a las {$hora_inicio} hs</td>
                </tr>
            </table>
            <p>El horario ha quedado disponible en tu agenda.</p>
            <hr style="border: 0; border-top: 1px solid #eee; margin: 20px 0;">
            <p style="font-size: 0.85em; color: #777;">— Equipo de RD Tecno Belleza</p>
        </div>
        HTML;

        $headers = ['Content-Type: text/html; charset=UTF-8'];
        $enviado = wp_mail($centro_usuario->user_email, $asunto, $cuerpo, $headers);

        if ($enviado) {
            error_log("TurnoNotification: Email de cancelación por cliente enviado al centro {$centro_usuario->user_email}.");
        } else {
            error_log("TurnoNotification: ERROR al enviar email de cancelación por cliente al centro {$centro_usuario->user_email}.");
        }
    }

    /**
     * Notifica a un cliente que él mismo canceló su turno exitosamente.
     */
    public function notificarCancelacionPorCliente(
        string $email_cliente,
        string $nombre_cliente,
        string $nombre_servicio,
        string $nombre_centro,
        string $fecha,
        string $hora_inicio
    ): void {
        $asunto           = 'Tu turno en ' . $nombre_centro . ' fue cancelado';
        $fecha_formateada = date_i18n('d \d\e F \d\e Y', strtotime($fecha));

        $cuerpo = <<<HTML
        <div style="font-family: Arial, sans-serif; line-height: 1.6; color: #333;">
            <h2>Hola, {$nombre_cliente}</h2>
            <p>Tu turno ha sido cancelado correctamente. Aquí están los detalles del turno cancelado:</p>
            <table cellpadding="10" style="width: 100%; max-width: 600px; border-collapse: collapse; margin: 20px 0; border: 1px solid #ddd;">
                <tr style="border-bottom: 1px solid #ddd;">
                    <td style="background-color: #f9f9f9; width: 140px; font-weight: bold;">Centro estético:</td>
                    <td>{$nombre_centro}</td>
                </tr>
                <tr style="border-bottom: 1px solid #ddd;">
                    <td style="background-color: #f9f9f9; font-weight: bold;">Servicio:</td>
                    <td>{$nombre_servicio}</td>
                </tr>
                <tr style="border-bottom: 1px solid #ddd;">
                    <td style="background-color: #f9f9f9; font-weight: bold;">Fecha cancelada:</td>
                    <td>{$fecha_formateada} a las {$hora_inicio} hs</td>
                </tr>
            </table>
            <p>Si deseas reservar un nuevo turno, podés hacerlo desde el enlace que te compartirá el centro.</p>
            <hr style="border: 0; border-top: 1px solid #eee; margin: 20px 0;">
            <p style="font-size: 0.85em; color: #777;">— Equipo de RD Tecno Belleza</p>
        </div>
        HTML;

        $headers = ['Content-Type: text/html; charset=UTF-8'];
        $enviado = wp_mail($email_cliente, $asunto, $cuerpo, $headers);

        if ($enviado) {
            error_log("TurnoNotification: Email de cancelación por cliente enviado a {$email_cliente}.");
        } else {
            error_log("TurnoNotification: ERROR al enviar email de cancelación por cliente a {$email_cliente}.");
        }
    }

    /**
     * Notifica a un cliente que su turno fue cancelado porque el centro
     * reprogramó su jornada de trabajo a una nueva fecha.
     */
    public function notificarCancelacionPorReagenda(
        string $email_cliente,
        string $nombre_cliente,
        string $nombre_servicio,
        string $nombre_centro,
        string $fecha_original,
        string $hora_inicio
    ): void {
        $asunto           = 'Tu turno en ' . $nombre_centro . ' fue cancelado por cambio de agenda';
        $fecha_formateada = date_i18n('d \d\e F \d\e Y', strtotime($fecha_original));

        $cuerpo = <<<HTML
        <div style="font-family: Arial, sans-serif; line-height: 1.6; color: #333;">
            <h2>Hola, {$nombre_cliente}</h2>
            <p>
                Te informamos que el centro estético <strong>{$nombre_centro}</strong> ha reprogramado
                su jornada de trabajo, por lo que tu turno fue cancelado temporalmente.
            </p>
            <table cellpadding="10" style="width: 100%; max-width: 600px; border-collapse: collapse; margin: 20px 0; border: 1px solid #ddd;">
                <tr style="border-bottom: 1px solid #ddd;">
                    <td style="background-color: #f9f9f9; width: 140px; font-weight: bold;">Centro estético:</td>
                    <td>{$nombre_centro}</td>
                </tr>
                <tr style="border-bottom: 1px solid #ddd;">
                    <td style="background-color: #f9f9f9; font-weight: bold;">Servicio:</td>
                    <td>{$nombre_servicio}</td>
                </tr>
                <tr style="border-bottom: 1px solid #ddd;">
                    <td style="background-color: #f9f9f9; font-weight: bold;">Turno cancelado:</td>
                    <td>{$fecha_formateada} a las {$hora_inicio} hs</td>
                </tr>
            </table>
            <p>
                Podés reservar un nuevo turno cuando el centro habilite su nueva agenda.
                Te llegará un nuevo enlace con los horarios disponibles.
            </p>
            
            <hr style="border: 0; border-top: 1px solid #eee; margin: 20px 0;">
            <p style="font-size: 0.85em; color: #777;">— Equipo de RD Tecno Belleza</p>
        </div>
        HTML;

        $headers = ['Content-Type: text/html; charset=UTF-8'];
        $enviado = wp_mail($email_cliente, $asunto, $cuerpo, $headers);

        if ($enviado) {
            error_log("TurnoNotification: Email de cancelación por reagenda enviado a {$email_cliente}.");
        } else {
            error_log("TurnoNotification: ERROR al enviar email de cancelación por reagenda a {$email_cliente}.");
        }
    }
}