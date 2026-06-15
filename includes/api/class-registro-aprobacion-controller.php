<?php

declare(strict_types=1);
namespace RDT\CentrosEstetica\Api;

use RDT\CentrosEstetica\Notifications\RegistroMailer;

/**
 * RegistroAprobacionController
 *
 * Expone dos endpoints REST que permiten al admin aprobar o rechazar
 * el registro de un centro estético directamente desde el email,
 * sin necesidad de ingresar al panel de WordPress.
 *
 * Endpoints:
 *   GET /wp-json/rdt/v1/registro/aprobar?token=XXX
 *   GET /wp-json/rdt/v1/registro/rechazar?token=XXX
 *
 * Seguridad:
 *   - El token es un hash único generado con wp_generate_password()
 *     y almacenado en user_meta al momento del registro.
 *   - El token es de un solo uso: se elimina al procesar la acción.
 *   - Los endpoints no requieren autenticación porque el token
 *     actúa como credencial de un solo uso.
 *   - El token expira a los 7 días del registro.
 */
final class RegistroAprobacionController
{
    private const META_TOKEN   = 'rdt_registro_token';
    private const META_EXPIRY  = 'rdt_registro_token_expiry';
    private const TOKEN_TTL    = 7 * DAY_IN_SECONDS;

    public static function register(): void
    {
        add_action('rest_api_init', [self::class, 'registrarRutas']);
    }

    public static function registrarRutas(): void
    {
        register_rest_route('rdt/v1', '/registro/aprobar', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'aprobar'],
            'permission_callback' => '__return_true',
            'args'                => [
                'token' => ['required' => true, 'sanitize_callback' => 'sanitize_text_field'],
            ],
        ]);

        register_rest_route('rdt/v1', '/registro/rechazar', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'rechazar'],
            'permission_callback' => '__return_true',
            'args'                => [
                'token' => ['required' => true, 'sanitize_callback' => 'sanitize_text_field'],
            ],
        ]);
    }

    // -------------------------------------------------------------------------
    // Generación y validación de tokens
    // -------------------------------------------------------------------------

    /**
     * Genera un token seguro de un solo uso para el usuario dado
     * y lo almacena en user_meta junto con su fecha de expiración.
     */
    public static function generarToken(int $user_id): string
    {
        $token  = wp_generate_password(48, false);
        $expiry = time() + self::TOKEN_TTL;

        update_user_meta($user_id, self::META_TOKEN,  $token);
        update_user_meta($user_id, self::META_EXPIRY, $expiry);

        return $token;
    }

    /**
     * Busca el usuario que tiene el token dado.
     * Devuelve el WP_User si el token es válido y no expiró,
     * null en cualquier otro caso.
     */
    private static function resolverUsuarioPorToken(string $token): ?\WP_User
    {
        $usuarios = get_users([
            'meta_key'   => self::META_TOKEN,
            'meta_value' => $token,
            'number'     => 1,
        ]);

        if (empty($usuarios)) {
            return null;
        }

        $user   = $usuarios[0];
        $expiry = (int) get_user_meta($user->ID, self::META_EXPIRY, true);

        if (time() > $expiry) {
            self::limpiarToken($user->ID);
            return null;
        }

        return $user;
    }

    /**
     * Elimina el token del usuario después de usarlo.
     */
    private static function limpiarToken(int $user_id): void
    {
        delete_user_meta($user_id, self::META_TOKEN);
        delete_user_meta($user_id, self::META_EXPIRY);
    }

    // -------------------------------------------------------------------------
    // Acción: Aprobar
    // -------------------------------------------------------------------------

    /**
     * Aprueba el registro del centro estético:
     *  1. Valida el token
     *  2. Cambia el rol a centro_estetico
     *  3. Publica el CPT centro_estetico que estaba en pending
     *  4. Genera enlace para que el usuario cree su contraseña
     *  5. Envía email de aprobación con el enlace
     *  6. Muestra página de confirmación al admin
     */
    public static function aprobar(\WP_REST_Request $request): void
    {
        $token = $request->get_param('token');
        $user  = self::resolverUsuarioPorToken($token);

        if (!$user) {
            self::mostrarRespuesta(
                'Token inválido o expirado',
                'El enlace de aprobación no es válido o ya fue utilizado.',
                false
            );
            return;
        }

        // Cambiar rol
        $user->set_role('centro_estetico');

        // Publicar CPT pending
        $centros = get_posts([
            'post_type'   => 'centro_estetico',
            'post_status' => 'pending',
            'meta_key'    => 'usuario_responsable',
            'meta_value'  => $user->ID,
            'numberposts' => 1,
            'fields'      => 'ids',
        ]);

        if (!empty($centros)) {
            wp_update_post(['ID' => $centros[0], 'post_status' => 'publish']);
            error_log("RegistroAprobacionController: CPT {$centros[0]} publicado para usuario ID {$user->ID}.");
        }

        // Generar enlace de reseteo de contraseña apuntando a nuestra
        // página personalizada /crear-contrasena/ en lugar de wp-login.php
        $reset_key    = get_password_reset_key($user);
        $url_password = '';

        if (!is_wp_error($reset_key)) {
            $url_password = add_query_arg(
                [
                    'key'   => $reset_key,
                    'login' => rawurlencode($user->user_login),
                ],
                home_url('/crear-contrasena/')
            );
        }

        // Limpiar token — uso único
        self::limpiarToken($user->ID);

        // Notificar al usuario
        RegistroMailer::enviarAprobacionAlUsuario(
            $user->user_email,
            $user->display_name,
            $url_password
        );

        error_log("RegistroAprobacionController: usuario ID {$user->ID} aprobado.");

        self::mostrarRespuesta(
            '✓ Centro aprobado',
            "La cuenta de <strong>{$user->display_name}</strong> fue aprobada correctamente. " .
            "Se le envió un email con el enlace para crear su contraseña.",
            true
        );
    }

    // -------------------------------------------------------------------------
    // Acción: Rechazar
    // -------------------------------------------------------------------------

    /**
     * Rechaza el registro del centro estético:
     *  1. Valida el token
     *  2. Obtiene datos del usuario antes de eliminarlo
     *  3. Elimina el CPT pending
     *  4. Elimina el usuario de WordPress
     *  5. Envía email de rechazo
     *  6. Muestra página de confirmación al admin
     */
    public static function rechazar(\WP_REST_Request $request): void
    {
        $token = $request->get_param('token');
        $user  = self::resolverUsuarioPorToken($token);

        if (!$user) {
            self::mostrarRespuesta(
                'Token inválido o expirado',
                'El enlace de rechazo no es válido o ya fue utilizado.',
                false
            );
            return;
        }

        $user_id      = $user->ID;
        $email        = $user->user_email;
        $nombre       = $user->display_name;

        // Eliminar CPT pending
        $centros = get_posts([
            'post_type'   => 'centro_estetico',
            'post_status' => 'pending',
            'meta_key'    => 'usuario_responsable',
            'meta_value'  => $user_id,
            'numberposts' => 1,
            'fields'      => 'ids',
        ]);

        if (!empty($centros)) {
            wp_delete_post($centros[0], true);
            error_log("RegistroAprobacionController: CPT {$centros[0]} eliminado por rechazo.");
        }

        // Limpiar token antes de eliminar el usuario
        self::limpiarToken($user_id);

        // Eliminar usuario
        require_once ABSPATH . 'wp-admin/includes/user.php';
        wp_delete_user($user_id);
        error_log("RegistroAprobacionController: usuario ID {$user_id} eliminado por rechazo.");

        // Notificar al usuario
        RegistroMailer::enviarRechazoAlUsuario($email, $nombre);

        self::mostrarRespuesta(
            '✗ Registro rechazado',
            "El registro de <strong>{$nombre}</strong> fue rechazado. " .
            "Se le envió un email notificando la decisión.",
            false
        );
    }

    // -------------------------------------------------------------------------
    // Página de respuesta HTML
    // -------------------------------------------------------------------------

    /**
     * Muestra una página HTML simple al admin confirmando el resultado
     * de la acción (aprobación o rechazo).
     * Usa wp_die() para renderizar fuera del contexto REST normal.
     */
    private static function mostrarRespuesta(
        string $titulo,
        string $mensaje,
        bool   $exito
    ): void {
        $color  = $exito ? '#4A7A84' : '#c0392b';
        $url_admin = admin_url('users.php');

        wp_die(
            "<div style='font-family: Arial, sans-serif; max-width: 520px; margin: 60px auto; text-align: center;'>
                <h2 style='color: {$color};'>{$titulo}</h2>
                <p style='color: #333; font-size: 1rem; line-height: 1.6;'>{$mensaje}</p>
                <a href='{$url_admin}'
                   style='display: inline-block; margin-top: 24px; background: {$color}; color: white;
                          padding: 12px 28px; border-radius: 8px; text-decoration: none; font-weight: 600;'>
                    Ir a usuarios
                </a>
            </div>",
            $titulo,
            ['response' => 200]
        );
    }
}
