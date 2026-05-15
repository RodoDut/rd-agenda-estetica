<?php

declare(strict_types=1);
namespace RDT\CentrosEstetica\Account;

/**
 * RegistroUI
 *
 * Gestiona la interfaz de registro de centros estéticos en la página
 * de Mi Cuenta de WooCommerce.
 *
 * Responsabilidad única: todo lo relacionado con la presentación
 * del flujo de registro — el popup, el botón que lo abre, el
 * ocultamiento del formulario nativo de WooCommerce y los mensajes
 * post-registro visibles en pantalla.
 *
 * Lo que NO hace esta clase:
 *  - Procesar datos del formulario (→ CentroRegistration)
 *  - Enviar emails (→ RegistroMailer)
 */
final class RegistroUI
{
    public static function register(): void
    {
        // Botón de registro debajo del formulario de login
        add_action('woocommerce_login_form_end', [self::class, 'renderBotonYPopup']);

        // Ocultar la columna de registro nativa de WooCommerce.
        // No usamos woocommerce_registration_enabled=false porque bloquea
        // el procesamiento de nuestro propio formulario del popup.
        add_action('wp_head', [self::class, 'ocultarFormularioNativo']);

        // Reemplazar el mensaje de contraseña temporal de WooCommerce
        add_filter('woocommerce_registration_auth_new_customer', [self::class, 'suprimirAutologin'], 10, 2);
        add_action('woocommerce_registration_redirect',          [self::class, 'agregarMensajeExito']);
    }

    // -------------------------------------------------------------------------
    // Botón + popup de registro
    // -------------------------------------------------------------------------

    /**
     * Renderiza el botón "Registrarme" debajo del formulario de login
     * y el popup con el formulario completo de registro.
     * Si el formulario fue enviado con errores, el popup se re-abre
     * automáticamente con los valores ya completados.
     */
    public static function renderBotonYPopup(): void
    {
        if (is_user_logged_in()) {
            return;
        }

        // Recuperar valores del POST si volvió con errores de validación
        $nombre    = isset($_POST['rdt_nombre_centro']) ? esc_attr(wp_unslash($_POST['rdt_nombre_centro'])) : '';
        $telefono  = isset($_POST['rdt_telefono'])      ? esc_attr(wp_unslash($_POST['rdt_telefono']))      : '';
        $whatsapp  = isset($_POST['rdt_whatsapp'])      ? esc_attr(wp_unslash($_POST['rdt_whatsapp']))      : '';
        $email     = isset($_POST['email'])             ? esc_attr(wp_unslash($_POST['email']))             : '';
        $direccion = isset($_POST['rdt_direccion'])     ? esc_attr(wp_unslash($_POST['rdt_direccion']))     : '';
        $localidad = isset($_POST['rdt_localidad'])     ? esc_attr(wp_unslash($_POST['rdt_localidad']))     : '';
        $provincia = isset($_POST['rdt_provincia'])     ? esc_attr(wp_unslash($_POST['rdt_provincia']))     : '';
        // Si vuelve con error, reponer el estado del checkbox de términos desde el POST
        $checked = !empty($_POST['terms_accepted']) ? 'checked' : '';
        $url_terms = esc_url(home_url('/terminos-y-condiciones/'));
        $nonce_reg = wp_create_nonce('woocommerce-register');
        // El popup debe re-abrirse SOLO cuando el formulario volvió con errores
        // de validación — es decir, hay datos en POST pero también errores de WooCommerce.
        // Si no hay errores (registro exitoso o primera carga), NO re-abrimos.
        $hay_post_datos = !empty($_POST['rdt_nombre_centro']) || !empty($_POST['email']);
        $hay_errores    = wc_notice_count('error') > 0;
        $auto_open      = ($hay_post_datos && $hay_errores) ? 'true' : 'false';

        ?>
        <div class="rdt-registro-trigger">
            <p class="rdt-registro-separador">¿Tu primera vez acá?</p>
            <button type="button" id="rdt-btn-abrir-registro" class="rdt-btn-registro-link">
                Registrarme como centro estético
            </button>
        </div>

        <div id="rdt-registro-overlay" class="rdt-overlay" aria-hidden="true">
            <div class="rdt-popup" role="dialog" aria-modal="true" aria-labelledby="rdt-popup-titulo">

                <div class="rdt-popup-header">
                    <h2 id="rdt-popup-titulo" class="rdt-popup-titulo">Registrarme como centro estético</h2>
                    <button type="button" id="rdt-btn-cerrar-registro" class="rdt-popup-cerrar" aria-label="Cerrar">&times;</button>
                </div>

                <div class="rdt-popup-body">
                    <form method="post" class="rdt-popup-form">

                        <p class="rdt-form-row">
                            <label for="rdt-email">Correo electrónico <span class="required">*</span></label>
                            <input type="email" name="email" id="rdt-email" class="rdt-input" value="<?= $email ?>" required />
                        </p>

                        <div class="rdt-registro-centro">
                            <h3 class="rdt-registro-titulo">Datos del centro</h3>

                            <p class="rdt-form-row">
                                <label for="rdt-nombre-centro">Nombre del centro <span class="required">*</span></label>
                                <input type="text" name="rdt_nombre_centro" id="rdt-nombre-centro" class="rdt-input" value="<?= $nombre ?>" placeholder="Ej: Centro Belleza Rosario" required />
                            </p>
                            <p class="rdt-form-row">
                                <label for="rdt-telefono">Teléfono <span class="required">*</span></label>
                                <input type="tel" name="rdt_telefono" id="rdt-telefono" class="rdt-input" value="<?= $telefono ?>" placeholder="Ej: 3415001234" required />
                            </p>
                            <p class="rdt-form-row">
                                <label for="rdt-whatsapp">WhatsApp (sin 0 ni 15)</label>
                                <input type="tel" name="rdt_whatsapp" id="rdt-whatsapp" class="rdt-input" value="<?= $whatsapp ?>" placeholder="Ej: 3415001234" />
                            </p>
                            <p class="rdt-form-row">
                                <label for="rdt-direccion">Dirección <span class="required">*</span></label>
                                <input type="text" name="rdt_direccion" id="rdt-direccion" class="rdt-input" value="<?= $direccion ?>" placeholder="Ej: San Martín 1234" required />
                            </p>
                            <p class="rdt-form-row">
                                <label for="rdt-localidad">Localidad <span class="required">*</span></label>
                                <input type="text" name="rdt_localidad" id="rdt-localidad" class="rdt-input" value="<?= $localidad ?>" placeholder="Ej: Rosario" required />
                            </p>
                            <p class="rdt-form-row">
                                <label for="rdt-provincia">Provincia <span class="required">*</span></label>
                                <input type="text" name="rdt_provincia" id="rdt-provincia" class="rdt-input" value="<?= $provincia ?>" placeholder="Ej: Santa Fe" required />
                            </p>
                        </div>

                        <p class="rdt-form-row">
                            <label class="rdt-label-terminos" style="text-transform:none;font-size:0.88rem;font-weight:500;letter-spacing:0;">
                                <input type="checkbox" name="rdt_requiere_aprobacion" id="rdt-requiere-aprobacion" class="rdt-checkbox" value="1" />
                                Requiero aprobar manualmente los turnos de mis clientes antes de confirmarlos
                            </label>
                            <span style="display:block;font-size:0.75rem;color:#6b6067;margin-top:4px;margin-left:28px;">Activá esta opción si querés revisar cada turno antes de que quede confirmado (por ejemplo, si requerís un adelanto de pago).</span>
                        </p>

                        <p class="rdt-form-row rdt-form-row--terminos">
                            <label class="rdt-label-terminos">
                                <input type="checkbox" id="rdt-terminos-popup" class="rdt-checkbox" <?= $checked ? 'checked' : '' ?> />
                                Acepto los <a href="<?= $url_terms ?>" target="_blank">términos y condiciones</a>
                                <span class="required">*</span>
                            </label>
                        </p>
                        <!-- El campo que valida validar_aceptacion_terminos() en functions.php -->
                        <input type="hidden" name="terms_accepted" id="rdt-terms-accepted-hidden" value="" />

                        <input type="hidden" name="woocommerce-register-nonce" value="<?= $nonce_reg ?>" />
                        <input type="hidden" name="_wp_http_referer" value="<?= esc_attr(wp_unslash($_SERVER['REQUEST_URI'] ?? '')) ?>" />
                        <input type="hidden" name="register" value="1" />

                        <p class="rdt-popup-acciones">
                            <button type="submit" class="rdt-btn-submit">Solicitar registro</button>
                        </p>

                    </form>
                </div>

            </div>
        </div>

        <script>
        (function () {
            var overlay   = document.getElementById('rdt-registro-overlay');
            var btnAbrir  = document.getElementById('rdt-btn-abrir-registro');
            var btnCerrar = document.getElementById('rdt-btn-cerrar-registro');
            var autoOpen  = <?= $auto_open ?>;

            function abrir() {
                overlay.classList.add('rdt-overlay--activo');
                overlay.setAttribute('aria-hidden', 'false');
                document.body.style.overflow = 'hidden';
            }

            function cerrar() {
                overlay.classList.remove('rdt-overlay--activo');
                overlay.setAttribute('aria-hidden', 'true');
                document.body.style.overflow = '';
            }

            btnAbrir.addEventListener('click', abrir);
            btnCerrar.addEventListener('click', cerrar);

            overlay.addEventListener('click', function (e) {
                if (e.target === overlay) cerrar();
            });

            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape') cerrar();
            });

            // Sincronizar el checkbox de términos con el campo hidden que valida
            // la función validar_aceptacion_terminos() en functions.php del tema.
            var checkboxTerminos = document.getElementById('rdt-terminos-popup');
            var hiddenTerminos   = document.getElementById('rdt-terms-accepted-hidden');

            if (checkboxTerminos && hiddenTerminos) {
                // Valor inicial: si el checkbox arranca marcado (re-apertura con error)
                hiddenTerminos.value = checkboxTerminos.checked ? '1' : '';

                checkboxTerminos.addEventListener('change', function () {
                    hiddenTerminos.value = this.checked ? '1' : '';
                });
            }

            if (autoOpen) abrir();

            // Cerrar el popup al enviar el formulario
            var form = overlay.querySelector('.rdt-popup-form');
            if (form) {
                form.addEventListener('submit', function () {
                    cerrar();
                });
            }
        }());
        </script>
        <?php
    }

    // -------------------------------------------------------------------------
    // Ocultamiento del formulario nativo
    // -------------------------------------------------------------------------

    /**
     * Oculta el formulario de registro nativo de WooCommerce con CSS inline.
     * Centra el formulario de login ocupando todo el ancho disponible.
     */
    public static function ocultarFormularioNativo(): void
    {
        if (!function_exists('is_account_page') || !is_account_page() || is_user_logged_in()) {
            return;
        }
        ?>
        <style>
        .woocommerce-account .woocommerce .u-column2.col-2,
        .woocommerce-account .woocommerce #customer_login .u-column2 {
            display: none !important;
        }
        .woocommerce-account .woocommerce .u-column1.col-1,
        .woocommerce-account .woocommerce #customer_login .u-column1 {
            width: 100% !important;
            float: none !important;
            max-width: 480px;
            margin: 0 auto;
        }
        </style>
        <?php
    }

    // -------------------------------------------------------------------------
    // Mensajes post-registro
    // -------------------------------------------------------------------------

    /**
     * Devuelve false para que WooCommerce no autologuee al usuario
     * ni muestre el aviso de contraseña temporal tras el registro.
     */
    public static function suprimirAutologin(bool $auth, int $customer_id): bool
    {
        return false;
    }

    /**
     * Agrega el mensaje de éxito propio tras completar el registro.
     */
    public static function agregarMensajeExito(string $redirect): string
    {
        wc_add_notice(
            'Revisá tu casilla de correo: te enviamos un mail confirmando que recibimos tu solicitud de registro. ' .
            'Cuando tu cuenta sea aprobada, recibirás otro correo con acceso a tu cuenta.',
            'success'
        );

        return $redirect;
    }
}
