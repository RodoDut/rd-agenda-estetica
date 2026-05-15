<?php

declare(strict_types=1);
namespace RDT\CentrosEstetica\Shortcodes;

/**
 * NuevaContrasena
 *
 * Maneja la página personalizada de creación de contraseña para centros
 * estéticos aprobados. En lugar de enviarlos a wp-login.php (que muestra
 * el logo de WordPress), los redirige a /crear-contrasena/ con diseño
 * propio de RD-Tecno Belleza.
 *
 * Estrategia: no usamos un shortcode sino un hook en template_redirect
 * para interceptar la petición a /crear-contrasena/ y servir nuestra
 * propia página HTML antes de que WordPress renderice el tema.
 *
 * La página /crear-contrasena/ debe existir en WordPress (cualquier
 * contenido, solo necesitamos el slug para que WP la reconozca).
 */
final class NuevaContrasena
{
    public static function register(): void
    {
        add_action('template_redirect', [self::class, 'interceptar'], 1);
    }

    /**
     * Intercepta la petición si estamos en /crear-contrasena/ y
     * sirve la página personalizada en lugar del tema de WordPress.
     */
    public static function interceptar(): void
    {
        // Solo actuar en la página /crear-contrasena/
        if (!is_page('crear-contrasena')) {
            return;
        }

        // Si hay un POST en curso, procesarlo
        if (
            $_SERVER['REQUEST_METHOD'] === 'POST'
            && isset($_POST['rdt_nueva_pass'])
        ) {
            self::procesarFormulario();
            return;
        }

        // Mostrar el formulario
        self::mostrarFormulario();
    }

    // ── Formulario de nueva contraseña ────────────────────────────────────────

    private static function mostrarFormulario(): void
    {
        $key   = sanitize_text_field($_GET['key']   ?? '');
        $login = sanitize_text_field($_GET['login']  ?? '');

        if (empty($key) || empty($login)) {
            self::renderPagina(
                'Enlace inválido',
                self::cardError(
                    'Enlace inválido',
                    'Este enlace no es válido. Si recibiste un email de aprobación, asegurate de copiar el enlace completo.'
                )
            );
            return;
        }

        $user = get_user_by('login', $login);
        if (!$user) {
            self::renderPagina(
                'Usuario no encontrado',
                self::cardError('Usuario no encontrado', 'No se encontró la cuenta asociada a este enlace.')
            );
            return;
        }

        $check = check_password_reset_key($key, $login);
        if (is_wp_error($check)) {
            self::renderPagina(
                'Enlace expirado',
                self::cardError(
                    'Enlace expirado',
                    'Este enlace ya fue usado o expiró. Podés solicitar uno nuevo desde ' .
                    '<a href="' . esc_url(wp_lostpassword_url()) . '">esta página</a>.'
                )
            );
            return;
        }

        $nonce        = wp_create_nonce('rdt_nueva_contrasena');
        $url_actual   = esc_url(add_query_arg([]));

        $contenido = '
        <div class="rdt-pass-header">
            ' . self::logoHtml() . '
            <h1 class="rdt-pass-titulo">Creá tu contraseña</h1>
            <p class="rdt-pass-subtitulo">Establecé la contraseña para tu cuenta en <strong>RD-Tecno Belleza</strong>.</p>
        </div>
        <div class="rdt-pass-cuerpo">
            <form method="post" action="' . esc_url(home_url('/crear-contrasena/')) . '" class="rdt-pass-form" id="rdt-pass-form">
                <input type="hidden" name="rdt_key"   value="' . esc_attr($key) . '">
                <input type="hidden" name="rdt_login" value="' . esc_attr($login) . '">
                <input type="hidden" name="rdt_nonce" value="' . esc_attr($nonce) . '">

                <div class="rdt-pass-campo">
                    <label for="rdt_nueva_pass" class="rdt-pass-label">Nueva contraseña</label>
                    <input type="password" name="rdt_nueva_pass" id="rdt_nueva_pass"
                           class="rdt-pass-input" placeholder="Mínimo 8 caracteres"
                           required minlength="8" autocomplete="new-password" />
                </div>

                <div class="rdt-pass-campo">
                    <label for="rdt_confirmar_pass" class="rdt-pass-label">Confirmá la contraseña</label>
                    <input type="password" name="rdt_confirmar_pass" id="rdt_confirmar_pass"
                           class="rdt-pass-input" placeholder="Repetí la contraseña"
                           required minlength="8" autocomplete="new-password" />
                </div>

                <div id="rdt-pass-error" class="rdt-pass-msg rdt-pass-msg--error" style="display:none;"></div>

                <button type="submit" class="rdt-pass-btn">Guardar contraseña</button>
            </form>
        </div>
        <div class="rdt-pass-footer"><p>&mdash; Equipo de RD-Tecno Belleza</p></div>

        <script>
        document.getElementById("rdt-pass-form").addEventListener("submit", function(e) {
            var p1 = document.getElementById("rdt_nueva_pass").value;
            var p2 = document.getElementById("rdt_confirmar_pass").value;
            var err = document.getElementById("rdt-pass-error");
            if (p1 !== p2) {
                e.preventDefault();
                err.textContent = "Las contraseñas no coinciden. Verificá e intentá de nuevo.";
                err.style.display = "block";
            } else if (p1.length < 8) {
                e.preventDefault();
                err.textContent = "La contraseña debe tener al menos 8 caracteres.";
                err.style.display = "block";
            } else {
                err.style.display = "none";
            }
        });
        </script>';

        self::renderPagina('Crear contraseña — RD-Tecno Belleza', $contenido);
    }

    // ── Procesamiento del formulario ──────────────────────────────────────────

    private static function procesarFormulario(): void
    {
        $key   = sanitize_text_field($_POST['rdt_key']   ?? '');
        $login = sanitize_text_field($_POST['rdt_login'] ?? '');
        $nonce = sanitize_text_field($_POST['rdt_nonce'] ?? '');
        $pass1 = $_POST['rdt_nueva_pass']     ?? '';
        $pass2 = $_POST['rdt_confirmar_pass'] ?? '';

        if (!wp_verify_nonce($nonce, 'rdt_nueva_contrasena')) {
            self::renderPagina('Sesión inválida', self::cardError(
                'Sesión inválida',
                'El formulario expiró. Volvé al enlace del email e intentá de nuevo.'
            ));
            return;
        }

        if (empty($pass1) || $pass1 !== $pass2 || strlen($pass1) < 8) {
            self::renderPagina('Error', self::cardError(
                'Error en la contraseña',
                'Verificá que las contraseñas coincidan y tengan al menos 8 caracteres.'
            ));
            return;
        }

        $user  = get_user_by('login', $login);
        $check = $user ? check_password_reset_key($key, $login) : new \WP_Error('invalid');

        if (is_wp_error($check) || !$user) {
            self::renderPagina('Enlace expirado', self::cardError(
                'Enlace expirado',
                'Este enlace ya fue usado o expiró. Solicitá uno nuevo desde ' .
                '<a href="' . esc_url(wp_lostpassword_url()) . '">esta página</a>.'
            ));
            return;
        }

        // Aplicar la nueva contraseña y autologuear
        reset_password($user, $pass1);
        wp_set_auth_cookie($user->ID, false);

        $url_cuenta = esc_url(home_url('/my-account/'));

        $contenido = '
        <div class="rdt-pass-header">
            ' . self::logoHtml() . '
            <div class="rdt-pass-icono-ok">✓</div>
            <h1 class="rdt-pass-titulo">¡Contraseña creada!</h1>
            <p class="rdt-pass-subtitulo">Ya podés ingresar a tu cuenta. Te redirigimos en un momento...</p>
        </div>
        <div class="rdt-pass-cuerpo">
            <a href="' . $url_cuenta . '" class="rdt-pass-btn">Ir a Mi Cuenta →</a>
        </div>
        <div class="rdt-pass-footer"><p>&mdash; Equipo de RD-Tecno Belleza</p></div>
        <script>setTimeout(function(){ window.location.href = "' . $url_cuenta . '"; }, 2500);</script>';

        self::renderPagina('Contraseña creada — RD-Tecno Belleza', $contenido);
    }

    // ── Helpers de renderizado ────────────────────────────────────────────────

    private static function cardError(string $titulo, string $mensaje): string
    {
        return '
        <div class="rdt-pass-header">
            ' . self::logoHtml() . '
            <div class="rdt-pass-icono-error">✕</div>
            <h1 class="rdt-pass-titulo">' . esc_html($titulo) . '</h1>
            <p class="rdt-pass-subtitulo">' . wp_kses_post($mensaje) . '</p>
        </div>
        <div class="rdt-pass-footer"><p>&mdash; Equipo de RD-Tecno Belleza</p></div>';
    }

    private static function logoHtml(): string
    {
        $site_icon_id = get_option('site_icon');
        if ($site_icon_id) {
            $url = wp_get_attachment_image_url($site_icon_id, 'medium');
            if ($url) {
                return '<img src="' . esc_url($url) . '" alt="RD-Tecno Belleza" class="rdt-pass-logo">';
            }
        }
        return '<div class="rdt-pass-nombre-marca">RD-Tecno Belleza</div>';
    }

    /**
     * Imprime la página HTML completa standalone y termina la ejecución.
     * Al llamar exit() evitamos que WordPress renderice el tema encima.
     */
    private static function renderPagina(string $titulo, string $contenido): void
    {
        status_header(200);
        nocache_headers();
        header('Content-Type: text/html; charset=UTF-8');

        echo '<!DOCTYPE html>
<html lang="es-AR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>' . esc_html($titulo) . '</title>
    <style>' . self::estilos() . '</style>
</head>
<body class="rdt-pass-body">
    <div class="rdt-pass-card">
        ' . $contenido . '
    </div>
</body>
</html>';

        exit;
    }

    // ── CSS ───────────────────────────────────────────────────────────────────

    private static function estilos(): string
    {
        return '
            *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

            .rdt-pass-body {
                min-height:      100vh;
                display:         flex;
                align-items:     center;
                justify-content: center;
                background:      linear-gradient(135deg, #4A7A84 0%, #d6eaed 100%);
                font-family:     -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
                padding:         24px 16px;
            }

            .rdt-pass-card {
                background:    #ffffff;
                border-radius: 16px;
                box-shadow:    0 12px 48px rgba(13, 20, 26, 0.18);
                width:         100%;
                max-width:     440px;
                overflow:      hidden;
            }

            .rdt-pass-header {
                background:  #4A7A84;
                padding:     36px 32px 28px;
                text-align:  center;
                color:       #ffffff;
            }

            .rdt-pass-logo {
                width:         80px;
                height:        80px;
                border-radius: 50%;
                object-fit:    cover;
                margin-bottom: 16px;
                border:        3px solid rgba(255,255,255,0.35);
                display:       block;
                margin-left:   auto;
                margin-right:  auto;
            }

            .rdt-pass-nombre-marca {
                font-size:     1.3rem;
                font-weight:   800;
                color:         #ffffff;
                margin-bottom: 14px;
                letter-spacing: 0.03em;
            }

            .rdt-pass-icono-ok,
            .rdt-pass-icono-error {
                width:           52px;
                height:          52px;
                border-radius:   50%;
                display:         flex;
                align-items:     center;
                justify-content: center;
                font-size:       1.5rem;
                margin:          0 auto 14px;
            }

            .rdt-pass-icono-ok    { background: rgba(255,255,255,0.2); border: 2px solid rgba(255,255,255,0.5); }
            .rdt-pass-icono-error { background: rgba(192,57,43,0.3);   border: 2px solid rgba(192,57,43,0.5); }

            .rdt-pass-titulo {
                font-size:     1.25rem;
                font-weight:   700;
                color:         #ffffff;
                margin-bottom: 8px;
            }

            .rdt-pass-subtitulo {
                font-size:   0.9rem;
                color:       rgba(255,255,255,0.88);
                line-height: 1.55;
            }

            .rdt-pass-subtitulo a { color: #ecc77c; }

            .rdt-pass-cuerpo {
                padding: 28px 32px;
            }

            .rdt-pass-campo {
                margin-bottom: 18px;
            }

            .rdt-pass-label {
                display:        block;
                font-size:      0.75rem;
                font-weight:    600;
                color:          #6b6067;
                text-transform: uppercase;
                letter-spacing: 0.05em;
                margin-bottom:  6px;
            }

            .rdt-pass-input {
                display:       block;
                width:         100%;
                padding:       11px 14px;
                border:        1.5px solid #e6e0e2;
                border-radius: 10px;
                background:    #faf8f6;
                color:         #0d141a;
                font-size:     1rem;
                font-family:   inherit;
                transition:    border-color 0.15s, box-shadow 0.15s;
            }

            .rdt-pass-input:focus {
                outline:      none;
                border-color: #4A7A84;
                box-shadow:   0 0 0 3px rgba(74,122,132,0.14);
            }

            .rdt-pass-msg { padding: 10px 14px; border-radius: 8px; font-size: 0.86rem; font-weight: 500; margin-bottom: 16px; }
            .rdt-pass-msg--error { background: #fee2e2; color: #991b1b; }

            .rdt-pass-btn {
                display:         block;
                width:           100%;
                padding:         13px;
                background:      #4A7A84;
                color:           #ffffff;
                border:          none;
                border-radius:   10px;
                font-size:       1rem;
                font-weight:     600;
                font-family:     inherit;
                cursor:          pointer;
                text-align:      center;
                text-decoration: none;
                transition:      background 0.2s, transform 0.1s;
                margin-top:      4px;
            }

            .rdt-pass-btn:hover { background: #3a6470; transform: translateY(-1px); color: #ffffff; }

            .rdt-pass-footer {
                padding:    14px 20px;
                text-align: center;
                font-size:  0.76rem;
                color:      #a09499;
                border-top: 1px solid #e6e0e2;
            }

            @media (max-width: 480px) {
                .rdt-pass-header  { padding: 28px 20px 22px; }
                .rdt-pass-cuerpo  { padding: 22px 20px; }
            }
        ';
    }
}
