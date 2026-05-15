<?php

declare(strict_types=1);
namespace RDT\CentrosEstetica\Account;

use RDT\CentrosEstetica\Notifications\RegistroMailer;
use RDT\CentrosEstetica\Api\RegistroAprobacionController;

/**
 * CentroRegistration
 *
 * Orquesta el flujo de registro de centros estéticos en WooCommerce.
 *
 * Responsabilidades de esta clase:
 *  - Registrar los hooks de WooCommerce del flujo de registro
 *  - Renderizar y validar los campos custom del formulario
 *  - Procesar el registro: crear usuario + CPT centro_estetico
 *  - Gestionar la aprobación cuando el admin cambia el rol
 *
 * Lo que NO hace esta clase:
 *  - Enviar emails       (→ RegistroMailer)
 *  - Renderizar el popup (→ RegistroUI)
 *
 * FLUJO DE APROBACIÓN:
 *  1. El interesado completa el formulario del popup
 *  2. Se crea el usuario con rol "subscriber" (sin acceso especial)
 *  3. Se crea el CPT centro_estetico en estado "pending"
 *  4. RegistroMailer notifica al usuario y al admin
 *  5. El admin cambia el rol a "centro_estetico" en WordPress
 *  6. Se publica el CPT y RegistroMailer envía el email de aprobación
 */
final class CentroRegistration
{
    public static function register(): void
    {
        // Formulario y procesamiento
        add_action('woocommerce_register_form',               [self::class, 'renderCampos']);
        add_filter('woocommerce_process_registration_errors', [self::class, 'validarCampos'],    10, 3);
        add_action('woocommerce_created_customer',            [self::class, 'procesarRegistro'], 10, 3);

        // Username desde el nombre del centro en lugar del email
        add_filter('woocommerce_new_customer_data', [self::class, 'forzarUsername'], 10, 1);

        // Suprimir email nativo de WooCommerce — lo reemplaza RegistroMailer
        add_filter('woocommerce_email_enabled_customer_new_account', '__return_false');

        // UI: popup, botón y mensajes post-registro
        RegistroUI::register();
    }

    // -------------------------------------------------------------------------
    // Formulario
    // -------------------------------------------------------------------------

    /**
     * Agrega los campos custom del centro al formulario de registro de WooCommerce.
     * Se renderiza dentro del popup gestionado por RegistroUI.
     */
    public static function renderCampos(): void
    {
        $campos = [
            'rdt_nombre_centro' => ['label' => 'Nombre del centro estético', 'placeholder' => 'Ej: Centro Belleza Rosario', 'required' => true,  'type' => 'text'],
            'rdt_telefono'      => ['label' => 'Teléfono de contacto',       'placeholder' => 'Ej: 3415001234',             'required' => true,  'type' => 'tel'],
            'rdt_whatsapp'      => ['label' => 'WhatsApp (sin 0 ni 15)',      'placeholder' => 'Ej: 3415001234',             'required' => false, 'type' => 'tel'],
            'rdt_direccion'     => ['label' => 'Dirección',                   'placeholder' => 'Ej: San Martín 1234',        'required' => true,  'type' => 'text'],
            'rdt_localidad'     => ['label' => 'Localidad',                   'placeholder' => 'Ej: Rosario',                'required' => true,  'type' => 'text'],
            'rdt_provincia'     => ['label' => 'Provincia',                   'placeholder' => 'Ej: Santa Fe',               'required' => true,  'type' => 'text'],
        ];

        echo '<div class="rdt-registro-centro">';
        echo '<h3 class="rdt-registro-titulo">Datos del centro estético</h3>';

        foreach ($campos as $name => $campo) {
            $value    = isset($_POST[$name]) ? esc_attr(wp_unslash($_POST[$name])) : '';
            $required = $campo['required'] ? 'required' : '';
            $label    = esc_html($campo['label']);
            $type     = esc_attr($campo['type']);
            $ph       = esc_attr($campo['placeholder']);
            $req_mark = $campo['required'] ? ' <span class="required">*</span>' : '';

            echo "
            <p class=\"rdt-form-row\">
                <label for=\"{$name}\">{$label}{$req_mark}</label>
                <input type=\"{$type}\" name=\"{$name}\" id=\"{$name}\" class=\"rdt-input\"
                       value=\"{$value}\" placeholder=\"{$ph}\" {$required} />
            </p>";
        }

        $url_terminos = esc_url(home_url('/terminos-y-condiciones/'));

        // El campo hidden terms_accepted es el que valida validar_aceptacion_terminos()
        // en functions.php del tema. El checkbox lo sincroniza via JS.
        echo "
        <p class='rdt-form-row rdt-form-row--terminos'>
            <label class='rdt-label-terminos'>
                <input type='checkbox' id='rdt_terminos_native' class='rdt-checkbox' />
                Acepto los
                <a href='{$url_terminos}' target='_blank' style='color:#4A7A84;'>términos y condiciones</a>
                <span class='required'>*</span>
            </label>
        </p>
        <input type='hidden' name='terms_accepted' id='rdt-terms-accepted-native' value='' />
        <script>
        (function(){
            var cb = document.getElementById('rdt_terminos_native');
            var hf = document.getElementById('rdt-terms-accepted-native');
            if (cb && hf) {
                cb.addEventListener('change', function(){ hf.value = this.checked ? '1' : ''; });
            }
        }());
        </script>";

        echo '</div>';
    }

    /**
     * Valida que los campos requeridos del centro estén completos.
     */
    public static function validarCampos(
        \WP_Error $errors,
        string $username,
        string $email
    ): \WP_Error {
        $requeridos = [
            'rdt_nombre_centro' => 'El nombre del centro es obligatorio.',
            'rdt_telefono'      => 'El teléfono de contacto es obligatorio.',
            'rdt_direccion'     => 'La dirección es obligatoria.',
            'rdt_localidad'     => 'La localidad es obligatoria.',
            'rdt_provincia'     => 'La provincia es obligatoria.',
        ];

        foreach ($requeridos as $campo => $mensaje) {
            if (empty($_POST[$campo]) || trim($_POST[$campo]) === '') {
                $errors->add($campo . '_requerido', $mensaje);
            }
        }

        return $errors;
    }

    /**
     * Genera el username desde el nombre del centro en lugar del email.
     * WooCommerce garantiza unicidad agregando sufijo numérico si es necesario.
     */
    public static function forzarUsername(array $data): array
    {
        $nombre_centro = sanitize_text_field($_POST['rdt_nombre_centro'] ?? '');

        if (empty($nombre_centro)) {
            return $data;
        }

        $username = sanitize_user(
            str_replace(' ', '-', strtolower(remove_accents($nombre_centro))),
            true
        );

        if (!empty($username)) {
            $data['user_login']   = wc_create_new_customer_username($data['user_email'], [], $username);
            $data['display_name'] = $nombre_centro;
            $data['first_name']   = $nombre_centro;
        }

        return $data;
    }

    // -------------------------------------------------------------------------
    // Procesamiento
    // -------------------------------------------------------------------------

    /**
     * Tras crear el usuario en WooCommerce:
     *  1. Asigna rol subscriber (pendiente de aprobación)
     *  2. Confirma display_name y first_name
     *  3. Crea el CPT centro_estetico en estado pending
     *  4. Delega el envío de emails a RegistroMailer
     */
    public static function procesarRegistro(
        int $customer_id,
        array $new_customer_data,
        string $password_generated
    ): void {
        $user = new \WP_User($customer_id);
        $user->set_role('subscriber');

        $nombre_centro = sanitize_text_field($_POST['rdt_nombre_centro'] ?? '');
        $telefono      = sanitize_text_field($_POST['rdt_telefono']      ?? '');
        $whatsapp      = sanitize_text_field($_POST['rdt_whatsapp']      ?? '');
        $direccion     = sanitize_text_field($_POST['rdt_direccion']     ?? '');
        $localidad     = sanitize_text_field($_POST['rdt_localidad']     ?? '');
        $provincia     = sanitize_text_field($_POST['rdt_provincia']     ?? '');
        $email_usuario = $new_customer_data['user_email'] ?? '';

        if (empty($nombre_centro)) {
            error_log("CentroRegistration: nombre_centro vacío para usuario ID {$customer_id}.");
            return;
        }

        // Confirmar display_name y first_name por si forzarUsername no alcanzó
        wp_update_user([
            'ID'           => $customer_id,
            'display_name' => $nombre_centro,
            'first_name'   => $nombre_centro,
        ]);

        $requiere_aprobacion = isset($_POST['rdt_requiere_aprobacion']) && $_POST['rdt_requiere_aprobacion'] === '1' ? '1' : '';

        $centro_id = wp_insert_post([
            'post_type'   => 'centro_estetico',
            'post_status' => 'pending',
            'post_title'  => $nombre_centro,
            'meta_input'  => [
                'usuario_responsable'     => $customer_id,
                'telefono'                => $telefono,
                'whatsapp'                => $whatsapp,
                'email'                   => $email_usuario,
                'direccion'               => $direccion,
                'localidad'               => $localidad,
                'provincia'               => $provincia,
                'requiere_aprobacion_turno' => $requiere_aprobacion,
            ],
        ]);

        if (is_wp_error($centro_id)) {
            error_log("CentroRegistration: Error al crear CPT para usuario ID {$customer_id}: " . $centro_id->get_error_message());
            return;
        }

        if (function_exists('update_field')) {
            update_field('usuario_responsable', $customer_id, $centro_id);
            update_field('telefono',            $telefono,    $centro_id);
        }

        error_log("CentroRegistration: CPT ID {$centro_id} creado (pending) para usuario ID {$customer_id} ({$nombre_centro}).");

        // Generar token de aprobación de un solo uso para los botones del email al admin
        $token = RegistroAprobacionController::generarToken($customer_id);

        RegistroMailer::enviarPendienteAlUsuario($email_usuario, $nombre_centro);
        RegistroMailer::enviarNuevoRegistroAlAdmin($nombre_centro, $email_usuario, $telefono, $localidad, $provincia, $customer_id, $token);
    }
}
