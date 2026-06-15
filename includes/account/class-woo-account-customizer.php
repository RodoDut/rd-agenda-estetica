<?php

declare(strict_types=1);
namespace RDT\CentrosEstetica\Account;

use function RDT\CentrosEstetica\Helpers\get_centro_by_user;
use function RDT\CentrosEstetica\Helpers\get_datos_centro;
use RDT\CentrosEstetica\Repositories\ServiciosClientesRepository;

/**
 * WooAccountCustomizer
 *
 * Mejora visual de la página Mi Cuenta de WooCommerce
 * exclusivamente para usuarios con rol centro_estetico.
 *
 * Flujo:
 *  /my-account              → Escritorio: bienvenida + datos del centro en solo lectura
 *  /my-account/edit-account → Formulario único propio que reemplaza el de WooCommerce.
 *                             Incluye: datos del centro, datos de cuenta WP y cambio de contraseña.
 *                             El guardado usa los hooks nativos de WooCommerce para los campos
 *                             estándar (nombre, email, contraseña) y nuestro propio hook para
 *                             los campos del centro.
 */
final class WooAccountCustomizer
{
    public static function register(): void
    {
        add_action('wp_enqueue_scripts',             [self::class, 'encolarAssets']);
        add_filter('woocommerce_account_menu_items', [self::class, 'personalizarMenu']);
        add_action('woocommerce_account_dashboard',  [self::class, 'renderEscritorio'], 1);
        add_action('init',                           [self::class, 'registrarEndpoint']);
        add_filter('body_class',                     [self::class, 'agregarBodyClass']);
        add_action('rest_api_init',                  [self::class, 'registrarEndpointREST']);

        // Renderizar el contenido del endpoint panel-centro dentro de Mi Cuenta
        add_action('woocommerce_account_panel-centro_endpoint', [self::class, 'renderPanelCentro']);

        // Eliminar el formulario nativo de WooCommerce en edit-account
        // y reemplazarlo con el nuestro. Usamos prioridad 1 para asegurarnos
        // de ejecutarnos primero, y eliminamos el nativo antes de que corra.
        add_action('woocommerce_account_edit-account_endpoint', [self::class, 'renderFormularioEdicion'], 1);

        // Usamos output buffering para capturar todo el output del hook
        // woocommerce_account_edit-account_endpoint y descartar el formulario
        // nativo de WooCommerce que se inyecta además del nuestro.
        // remove_action con string no funciona en WooCommerce 10+ porque el
        // callback está registrado internamente de forma diferente.
        add_action('woocommerce_account_edit-account_endpoint', [self::class, 'iniciarBufferFormulario'], 0);
        add_action('woocommerce_account_edit-account_endpoint', [self::class, 'cerrarBufferFormulario'], 999);

        // Guardar los campos del centro cuando WooCommerce procesa el formulario
        add_action('woocommerce_save_account_details',            [self::class, 'guardarCamposCentro'], 10, 1);
        add_action('woocommerce_save_account_details_errors',     [self::class, 'validarCamposCentro'],  10, 2);
    }

    // --- Assets ------------------------------------------------------------------

    public static function encolarAssets(): void
    {
        // Cargamos el CSS en la página de Mi Cuenta para usuarios no logueados
        // (necesario para el popup de registro) y para centros logueados.
        $es_pagina_cuenta = function_exists('is_account_page') && is_account_page();

        if (!$es_pagina_cuenta && !self::esCentroLogueado()) {
            return;
        }

        \RDT\CentrosEstetica\Assets\AssetsLoader::load_woo_account();

        if (!self::esCentroLogueado()) {
            return;
        }

        // El panel de servicios se carga en el escritorio
        \RDT\CentrosEstetica\Assets\AssetsLoader::load_panel_servicios();

        // El calendario se carga AQUÍ (en wp_enqueue_scripts, antes de que se
        // impriman los scripts en el <head>) cuando detectamos que el usuario
        // está navegando al endpoint panel-centro.
        // Si lo cargamos desde el shortcode con wp_localize_script(), la variable
        // RDTCalendario llega tarde y el JS no la encuentra.
        if (self::estaEnPanelCentro()) {
            $centro_id = \RDT\CentrosEstetica\Helpers\get_centro_by_user(get_current_user_id());
            $fecha     = current_time('Y-m-d');
            if ($centro_id) {
                \RDT\CentrosEstetica\Assets\AssetsLoader::load_calendario_centro($fecha, $centro_id);
            }
        }
    }

    /**
     * Detecta si el usuario está navegando al endpoint panel-centro de Mi Cuenta.
     * Usado para cargar los assets del calendario en el momento correcto (wp_enqueue_scripts).
     */
    private static function estaEnPanelCentro(): bool
    {
        global $wp_query;
        return isset($wp_query->query_vars['panel-centro']);
    }

    // --- Body class --------------------------------------------------------------

    public static function agregarBodyClass(array $classes): array
    {
        if (self::esCentroLogueado()) {
            $classes[] = 'rdt-centro-logueado';
        }

        return $classes;
    }

    // --- Menú Mi Cuenta ----------------------------------------------------------

    public static function registrarEndpoint(): void
    {
        add_rewrite_endpoint('panel-centro', EP_ROOT | EP_PAGES);
    }

    public static function personalizarMenu(array $items): array
    {
        if (!self::esCentroLogueado()) {
            return $items;
        }

        $eliminar = ['orders', 'downloads', 'edit-address', 'payment-methods'];
        foreach ($eliminar as $key) {
            unset($items[$key]);
        }

        $dashboard = ['dashboard'       => $items['dashboard']       ?? __('Escritorio', 'woocommerce')];
        $logout    = ['customer-logout' => $items['customer-logout'] ?? __('Cerrar sesión', 'woocommerce')];

        unset($items['dashboard'], $items['customer-logout']);

        return array_merge(
            $dashboard,
            ['panel-centro' => 'Mi Agenda'],
            $items,
            $logout
        );
    }

    // --- Panel Centro (calendario del centro) -----------------------------------

    /**
     * Renderiza el contenido de /my-account/panel-centro/
     * WooCommerce llama a este hook cuando el usuario navega a ese endpoint.
     * Simplemente ejecuta el shortcode [calendario_centro] dentro del layout
     * de Mi Cuenta, sin necesidad de una página WordPress separada.
     */
    public static function renderPanelCentro(): void
    {
        if (!self::esCentroLogueado()) {
            echo '<p>' . esc_html__('Acceso no autorizado.', 'rdt') . '</p>';
            return;
        }

        // Los assets (JS + RDTCalendario localize) ya fueron cargados en
        // encolarAssets() via wp_enqueue_scripts antes de imprimir el <head>.
        // Aquí solo renderizamos el HTML del shortcode.
        echo do_shortcode('[calendario_centro]');
    }

    // --- Escritorio (solo lectura) -----------------------------------------------

    public static function renderEscritorio(): void
    {
        if (!self::esCentroLogueado()) {
            return;
        }

        $user      = wp_get_current_user();
        $centro_id = get_centro_by_user($user->ID);
        $datos     = $centro_id ? get_datos_centro($centro_id) : [];
        $nombre    = $datos['nombre'] ?? $user->display_name;

        $etiquetas = [
            'nombre'    => 'Nombre del centro',
            'telefono'  => 'Teléfono',
            'whatsapp'  => 'WhatsApp',
            'email'     => 'Email de contacto',
            'direccion' => 'Dirección',
            'localidad' => 'Localidad',
            'provincia' => 'Provincia',
        ];

        $myaccount_url = get_permalink(get_option('woocommerce_myaccount_page_id'));
        $url_editar    = trailingslashit($myaccount_url) . 'edit-account/';
        $url_agenda    = trailingslashit($myaccount_url) . 'panel-centro/';

        // Obtener los servicios del centro
        $servicioRepo = new ServiciosClientesRepository();
        $servicios = $servicioRepo->findByCentro($centro_id);

        // Obtener todas las opciones disponibles configuradas globalmente en el campo ACF 'categoria_servicio'
        // Esto permite que el centro elija entre todas las categorías definidas en el sistema.
        $all_categories = [];
        if (function_exists('acf_get_field')) {
            $field = acf_get_field('categoria_servicio');
            if ($field && !empty($field['choices'])) {
                $all_categories = $field['choices'];
            }
        }

        ?>
        <div class="rdt-account-escritorio">

            <div class="rdt-account-bienvenida">
                <h2 class="rdt-account-titulo">¡Hola, <?= esc_html($nombre) ?>!</h2>
                <p class="rdt-account-subtitulo">Desde tu panel podés gestionar tu agenda y los turnos de tus clientas.</p>
                <a href="<?= esc_url($url_agenda) ?>" class="rdt-account-btn">
                    Ir a mi agenda →
                </a>
            </div>

            <div class="rdt-perfil-card">

                <div class="rdt-perfil-header">
                    <h3 class="rdt-perfil-titulo">Datos del centro</h3>
                    <?php if ($centro_id): ?>
                    <a href="<?= esc_url($url_editar) ?>" class="rdt-perfil-btn-editar">
                        ✏️ Editar perfil
                    </a>
                    <?php endif; ?>
                </div>

                <?php if (!$centro_id): ?>
                    <p class="rdt-perfil-sin-datos">No tenés un perfil de centro asociado. Contactá al administrador.</p>

                <?php else: ?>
                    <div class="rdt-perfil-vista">
                        <dl class="rdt-perfil-lista">
                            <?php foreach ($etiquetas as $campo => $etiqueta):
                                $valor = $datos[$campo] ?? '';
                                if (empty($valor)) continue;
                            ?>
                            <div class="rdt-perfil-fila">
                                <dt class="rdt-perfil-campo"><?= esc_html($etiqueta) ?></dt>
                                <dd class="rdt-perfil-valor"><?= esc_html($valor) ?></dd>
                            </div>
                            <?php endforeach; ?>
                        </dl>
                    </div>
                <?php endif; ?>

            </div>

            <!-- Sección de Gestión de Servicios -->
            <div class="rdt-perfil-card">
                <div class="rdt-perfil-header">
                    <h3 class="rdt-perfil-titulo">Mis Servicios</h3>
                    <button id="rdt-btn-agregar-servicio" class="rdt-perfil-btn-editar" style="display:none;">
                        + Agregar Servicio
                    </button>
                </div>
                <div class="rdt-perfil-vista">
                    <div id="rdt-lista-servicios">
                        <!-- JS insertará aquí la lista -->
                    </div>
                </div>
            </div>

            <!-- Modal para Agregar/Editar Servicio -->
            <div id="rdt-servicios-overlay" class="rdt-overlay" aria-hidden="true">
                <div class="rdt-popup" role="dialog" aria-modal="true">
                    <div class="rdt-popup-header">
                        <h2 id="rdt-servicios-titulo-modal" class="rdt-popup-titulo">Servicio</h2>
                        <button type="button" id="rdt-btn-cerrar-servicio" class="rdt-popup-cerrar">&times;</button>
                    </div>
                    <div class="rdt-popup-body">
                        <form id="rdt-form-servicio" class="rdt-popup-form">
                            
                            <p class="rdt-form-row">
                                <label for="rdt-serv-nombre">Nombre del servicio <span class="required">*</span></label>
                                <input type="text" id="rdt-serv-nombre" class="rdt-input" required placeholder="Ej: Depilación Pierna Completa">
                            </p>

                            <p class="rdt-form-row">
                                <label for="rdt-serv-categoria">Categoría</label>
                                <select id="rdt-serv-categoria" class="rdt-input">
                                    <option value="">Seleccionar categoría</option>
                                    <?php foreach ($all_categories as $value => $label): ?>
                                        <option value="<?= esc_attr($value) ?>"><?= esc_html($label) ?></option>
                                    <?php endforeach; ?>

                                </select>
                            </p>

                            <p class="rdt-form-row">
                                <label for="rdt-serv-duracion">Duración (minutos) <span class="required">*</span></label>
                                <input type="number" id="rdt-serv-duracion" class="rdt-input" min="1" required placeholder="Ej: 30">
                            </p>

                            <p class="rdt-form-row">
                                <label for="rdt-serv-detalle">Detalle del servicio</label>
                                <textarea id="rdt-serv-detalle" class="rdt-input" rows="3" placeholder="Descripción opcional..."></textarea>
                            </p>

                            <p class="rdt-popup-acciones">
                                <button type="submit" class="rdt-btn-submit">Guardar Servicio</button>
                            </p>
                        </form>
                    </div>
                </div>
            </div>

        </div>
        <?php

        remove_action('woocommerce_account_dashboard', 'woocommerce_account_dashboard');
    }

    // --- Control de output buffering -----------------------------------------------

    /**
     * Inicia el buffer antes de que cualquier callback del hook escriba output.
     * Prioridad 0 — se ejecuta primero.
     */
    public static function iniciarBufferFormulario(): void
    {
        if (!self::esCentroLogueado()) {
            return;
        }
        ob_start();
    }

    /**
     * Al final del hook (prioridad 999), descartamos todo el output acumulado
     * e imprimimos solo el nuestro que ya fue capturado por renderFormularioEdicion.
     * Prioridad 999 — se ejecuta al final, después del formulario nativo de WooCommerce.
     */
    public static function cerrarBufferFormulario(): void
    {
        if (!self::esCentroLogueado()) {
            return;
        }

        // Descartamos todo lo que se acumuló en el buffer
        // (incluye nuestro formulario + el nativo de WooCommerce)
        $contenido = ob_get_clean();

        // Extraemos solo nuestro formulario — identificado por su clase CSS única
        // rdt-edicion-form. Todo lo demás (el formulario nativo de WooCommerce
        // con clase woocommerce-EditAccountForm) se descarta.
        if (preg_match('/<form[^>]+class="[^"]*rdt-edicion-form[^"]*".*?<\/form>/s', $contenido, $matches)) {
            echo $matches[0];
        } else {
            // Fallback: si no encontramos nuestro formulario, mostramos todo
            // para no romper la página silenciosamente.
            echo $contenido;
        }
    }

    // --- Formulario de edición (reemplaza el de WooCommerce) ---------------------

    /**
     * Renderiza nuestro formulario unificado en /my-account/edit-account.
     * Elimina el formulario nativo de WooCommerce y renderiza el nuestro,
     * que incluye:
     *  - Datos del centro estético (campos propios del CPT)
     *  - Datos de la cuenta WordPress (nombre visible, email)
     *  - Sección de cambio de contraseña (compatible con WooCommerce)
     *
     * El action del formulario apunta al procesador nativo de WooCommerce
     * para que los campos estándar (nombre, email, contraseña) sean
     * guardados por WooCommerce normalmente.
     */
    public static function renderFormularioEdicion(): void
    {
        if (!self::esCentroLogueado()) {
            return;
        }

        $user      = wp_get_current_user();
        $centro_id = get_centro_by_user($user->ID);
        $datos     = $centro_id ? get_datos_centro($centro_id) : [];

        $campos_centro = [
            'nombre'    => ['label' => 'Nombre del centro',  'type' => 'text',  'required' => true],
            'telefono'  => ['label' => 'Teléfono',           'type' => 'tel',   'required' => true],
            'whatsapp'  => ['label' => 'WhatsApp',           'type' => 'tel',   'required' => false],
            'email'     => ['label' => 'Email de contacto',  'type' => 'email', 'required' => false],
            'direccion' => ['label' => 'Dirección',          'type' => 'text',  'required' => true],
            'localidad' => ['label' => 'Localidad',          'type' => 'text',  'required' => true],
            'provincia' => ['label' => 'Provincia',          'type' => 'text',  'required' => true],
        ];

        // Mensajes de error/éxito de WooCommerce
        wc_print_notices();

        ?>
        <form class="rdt-edicion-form woocommerce-EditAccountForm edit-account"
              action=""
              method="post"
              <?php do_action('woocommerce_edit_account_form_tag'); ?>>

            <?php /* ── Sección: Datos del centro ── */ ?>
            <?php if ($centro_id): ?>
            <div class="rdt-edicion-seccion">
                <h3 class="rdt-edicion-titulo">Datos del centro estético</h3>

                <div class="rdt-edicion-grid">
                    <?php foreach ($campos_centro as $campo => $config):
                        $valor    = isset($_POST["rdt_centro_{$campo}"])
                            ? esc_attr(wp_unslash($_POST["rdt_centro_{$campo}"]))
                            : esc_attr($datos[$campo] ?? '');
                        $req_attr = $config['required'] ? 'required' : '';
                        $req_mark = $config['required'] ? ' <span class="required">*</span>' : '';
                    ?>
                    <p class="rdt-edicion-campo">
                        <label for="rdt_centro_<?= esc_attr($campo) ?>">
                            <?= esc_html($config['label']) ?><?= $req_mark ?>
                        </label>
                        <input
                            type="<?= esc_attr($config['type']) ?>"
                            name="rdt_centro_<?= esc_attr($campo) ?>"
                            id="rdt_centro_<?= esc_attr($campo) ?>"
                            class="rdt-input"
                            value="<?= $valor ?>"
                            <?= $req_attr ?>
                        />
                    </p>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <?php /* ── Sección: Configuración de turnos ── */ ?>
            <?php if ($centro_id): ?>
            <div class="rdt-edicion-seccion">
                <h3 class="rdt-edicion-titulo">Configuración de turnos</h3>

                <?php
                $requiere_aprobacion = (get_post_meta($centro_id, 'requiere_aprobacion_turno', true) === '1');
                $checked_aprobacion  = $requiere_aprobacion ? 'checked' : '';
                // Si el formulario fue enviado, usamos el valor del POST
                if (isset($_POST['save_account_details'])) {
                    $checked_aprobacion = isset($_POST['rdt_centro_requiere_aprobacion']) ? 'checked' : '';
                }
                ?>
                <div class="rdt-edicion-grid">
                    <p class="rdt-edicion-campo rdt-edicion-campo--full">
                        <label class="rdt-label-aprobacion">
                            <input
                                type="checkbox"
                                name="rdt_centro_requiere_aprobacion"
                                id="rdt_centro_requiere_aprobacion"
                                class="rdt-checkbox-aprobacion"
                                value="1"
                                <?= $checked_aprobacion ?>
                            />
                            <span class="rdt-label-aprobacion__texto">
                                Requerir aprobación manual de turnos
                            </span>
                        </label>
                        <span class="rdt-edicion-desc">
                            Cuando está activado, los nuevos turnos se crean como <strong>pendientes</strong>
                            y recibís un email para aprobarlos o rechazarlos antes de que el cliente reciba confirmación.
                            Útil si requerís un adelanto de pago o necesitás verificar disponibilidad manualmente.
                        </span>
                    </p>
                </div>
            </div>
            <?php endif; ?>

            <?php /* ── Sección: Datos de acceso (solo email) ── */ ?>
            <div class="rdt-edicion-seccion">
                <h3 class="rdt-edicion-titulo">Datos de acceso</h3>

                <?php
                // Campos ocultos requeridos por WooCommerce para procesar el formulario
                // correctamente sin mostrarlos al usuario (el nombre visible lo
                // sincronizamos con el nombre del centro al guardar).
                ?>
                <input type="hidden" name="account_first_name"    value="<?= esc_attr($user->first_name ?: $user->display_name) ?>" />
                <input type="hidden" name="account_last_name"     value="<?= esc_attr($user->last_name) ?>" />
                <input type="hidden" name="account_display_name" value="<?= esc_attr($user->display_name) ?>" />

                <div class="rdt-edicion-grid">
                    <p class="rdt-edicion-campo rdt-edicion-campo--full">
                        <label for="account_email">
                            <?= esc_html__('Correo electrónico de acceso', 'woocommerce') ?>
                            <span class="required">*</span>
                        </label>
                        <input
                            type="email"
                            name="account_email"
                            id="account_email"
                            class="rdt-input"
                            value="<?= esc_attr($user->user_email) ?>"
                            required
                        />
                    </p>
                </div>
            </div>

            <?php /* ── Sección: Cambio de contraseña ── */ ?>
            <div class="rdt-edicion-seccion">
                <h3 class="rdt-edicion-titulo">Cambio de contraseña</h3>
                <p class="rdt-edicion-desc-seccion">
                    Dejá estos campos en blanco si no querés cambiar tu contraseña.
                </p>

                <div class="rdt-edicion-grid">
                    <p class="rdt-edicion-campo rdt-edicion-campo--full">
                        <label for="password_current">
                            <?= esc_html__('Contraseña actual', 'woocommerce') ?>
                        </label>
                        <input
                            type="password"
                            name="password_current"
                            id="password_current"
                            class="rdt-input"
                            autocomplete="off"
                        />
                    </p>

                    <p class="rdt-edicion-campo">
                        <label for="password_1">
                            <?= esc_html__('Nueva contraseña', 'woocommerce') ?>
                        </label>
                        <input
                            type="password"
                            name="password_1"
                            id="password_1"
                            class="rdt-input"
                            autocomplete="off"
                        />
                    </p>

                    <p class="rdt-edicion-campo">
                        <label for="password_2">
                            <?= esc_html__('Confirmar nueva contraseña', 'woocommerce') ?>
                        </label>
                        <input
                            type="password"
                            name="password_2"
                            id="password_2"
                            class="rdt-input"
                            autocomplete="off"
                        />
                    </p>
                </div>
            </div>

            <p class="rdt-edicion-acciones">
                <?php wp_nonce_field('save_account_details', 'save-account-nonce'); ?>
                <input type="hidden" name="action" value="save_account_details" />
                <button type="submit" class="rdt-edicion-btn-guardar" name="save_account_details" value="<?= esc_attr__('Guardar cambios', 'woocommerce') ?>">
                    <?= esc_html__('Guardar cambios', 'woocommerce') ?>
                </button>
            </p>

        </form>
        <?php
    }

    // --- Guardado de campos del centro -------------------------------------------

    /**
     * Valida los campos del centro en el formulario de edición.
     * Se ejecuta antes de que WooCommerce guarde los datos.
     */
    public static function validarCamposCentro(\WP_Error $errors, \WP_User $user): void
    {
        if (!in_array('centro_estetico', (array) $user->roles, true)) {
            return;
        }

        $requeridos = [
            'rdt_centro_nombre'    => 'El nombre del centro es obligatorio.',
            'rdt_centro_telefono'  => 'El teléfono es obligatorio.',
            'rdt_centro_direccion' => 'La dirección es obligatoria.',
            'rdt_centro_localidad' => 'La localidad es obligatoria.',
            'rdt_centro_provincia' => 'La provincia es obligatoria.',
        ];

        foreach ($requeridos as $campo => $mensaje) {
            if (empty(trim($_POST[$campo] ?? ''))) {
                $errors->add($campo . '_requerido', $mensaje);
            }
        }
    }

    /**
     * Guarda los campos del centro después de que WooCommerce
     * haya guardado los campos estándar (nombre, email, contraseña).
     */
    public static function guardarCamposCentro(int $user_id): void
    {
        $user = get_userdata($user_id);
        if (!$user || !in_array('centro_estetico', (array) $user->roles, true)) {
            return;
        }

        $centro_id = get_centro_by_user($user_id);
        if (!$centro_id) {
            return;
        }

        $nombre = sanitize_text_field($_POST['rdt_centro_nombre'] ?? '');
        if (!empty($nombre)) {
            wp_update_post(['ID' => $centro_id, 'post_title' => $nombre]);

            // Sincronizar el display_name del usuario con el nombre del centro
            // para que los saludos y emails usen siempre el nombre actualizado.
            wp_update_user([
                'ID'           => $user_id,
                'display_name' => $nombre,
                'first_name'   => $nombre,
            ]);
        }

        $campos = ['telefono', 'whatsapp', 'email', 'direccion', 'localidad', 'provincia'];
        foreach ($campos as $campo) {
            $valor = sanitize_text_field($_POST["rdt_centro_{$campo}"] ?? '');
            update_post_meta($centro_id, $campo, $valor);

            if (function_exists('update_field')) {
                update_field($campo, $valor, $centro_id);
            }
        }

        // Guardar configuración de aprobación de turnos.
        // Un checkbox no enviado equivale a desmarcar (valor vacío).
        $requiere_aprobacion = isset($_POST['rdt_centro_requiere_aprobacion']) ? '1' : '';
        update_post_meta($centro_id, 'requiere_aprobacion_turno', $requiere_aprobacion);
        if (function_exists('update_field')) {
            update_field('requiere_aprobacion_turno', $requiere_aprobacion, $centro_id);
        }

        do_action('rdt/centro/perfil_actualizado', $centro_id);

        error_log("WooAccountCustomizer: Campos del centro guardados para centro ID {$centro_id}.");
    }

    // --- Endpoint REST -----------------------------------------------------------

    public static function registrarEndpointREST(): void
    {
        register_rest_route('rdt/v1', '/centro/perfil', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'guardarPerfil'],
            'permission_callback' => [self::class, 'verificarPermiso'],
        ]);
    }

    public static function verificarPermiso(): bool
    {
        return self::esCentroLogueado();
    }

    public static function guardarPerfil(\WP_REST_Request $request): \WP_REST_Response
    {
        $user_id   = get_current_user_id();
        $centro_id = get_centro_by_user($user_id);

        if (!$centro_id) {
            return new \WP_REST_Response(['error' => 'No tenés un centro asociado.'], 403);
        }

        $requeridos = ['nombre', 'telefono', 'direccion', 'localidad', 'provincia'];
        foreach ($requeridos as $campo) {
            if (empty(trim($request->get_param($campo) ?? ''))) {
                return new \WP_REST_Response([
                    'error' => 'El campo "' . $campo . '" es obligatorio.'
                ], 400);
            }
        }

        $nombre = sanitize_text_field($request->get_param('nombre'));
        wp_update_post(['ID' => $centro_id, 'post_title' => $nombre]);

        $campos = ['telefono', 'whatsapp', 'email', 'direccion', 'localidad', 'provincia'];
        foreach ($campos as $campo) {
            $valor = sanitize_text_field($request->get_param($campo) ?? '');
            update_post_meta($centro_id, $campo, $valor);

            if (function_exists('update_field')) {
                update_field($campo, $valor, $centro_id);
            }
        }

        do_action('rdt/centro/perfil_actualizado', $centro_id);

        return new \WP_REST_Response([
            'success' => true,
            'datos'   => get_datos_centro($centro_id),
        ], 200);
    }

    // --- Helper ------------------------------------------------------------------

    private static function esCentroLogueado(): bool
    {
        if (!is_user_logged_in()) {
            return false;
        }

        $user = wp_get_current_user();
        return in_array('centro_estetico', (array) $user->roles, true);
    }
}
