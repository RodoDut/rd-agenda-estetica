<?php

namespace RDT\CentrosEstetica\Assets;

if (!defined('ABSPATH')) {
    exit;
}

final class AssetsLoader
{
    /**
     * Agenda interna (centro estético)
     */
    public static function load_agenda_centro(string $fecha, int $centro_id): void
    {
        if (is_admin()) return;

        $js_path = plugin_dir_path(__FILE__) . 'js/agenda-centro.js';
        $version = file_exists($js_path) ? filemtime($js_path) : '1.0.0';

        wp_enqueue_script('rdt-agenda-centro', plugins_url('/js/agenda-centro.js', __FILE__), [], $version, true);
        wp_localize_script('rdt-agenda-centro', 'RDTAgenda', [
            'apiUrl'   => rest_url('rdt/v1/horarios'),
            'fecha'    => $fecha,
            'centroId' => $centro_id,
        ]);
    }

    /**
     * Agenda pública (clienta final)
     */
    public static function load_agenda_publica(string $token): void
    {
        if (is_admin()) return;

        $css_path    = plugin_dir_path(__FILE__) . 'css/agenda-publica.css';
        $css_version = file_exists($css_path) ? filemtime($css_path) : '1.0.0';
        wp_enqueue_style('rdt-agenda-publica', plugins_url('/css/agenda-publica.css', __FILE__), [], $css_version);

        $js_path = plugin_dir_path(__FILE__) . 'js/agenda-publica.js';
        $version = file_exists($js_path) ? filemtime($js_path) : '1.0.0';
        wp_enqueue_script('rdt-agenda-publica', plugins_url('/js/agenda-publica.js', __FILE__), [], $version, true);
        wp_localize_script('rdt-agenda-publica', 'RDTAgenda', [
            'apiUrlHorarios' => rest_url('rdt/v1/horarios'),
            'apiUrlTurno'    => rest_url('rdt/v1/turno'),
            'token'          => $token,
        ]);
    }

    /**
     * Calendario diario del panel del centro estético
     */
    public static function load_calendario_centro(string $fecha, int $centro_id): void
    {
        if (is_admin()) return;

        $js_path     = plugin_dir_path(__FILE__) . 'js/calendario-centro.js';
        $version     = file_exists($js_path) ? filemtime($js_path) : '1.0.0';
        $css_path    = plugin_dir_path(__FILE__) . 'css/calendario-centro.css';
        $css_version = file_exists($css_path) ? filemtime($css_path) : '1.0.0';

        wp_enqueue_script('rdt-calendario-centro', plugins_url('/js/calendario-centro.js', __FILE__), [], $version, true);
        wp_enqueue_style('rdt-calendario-centro', plugins_url('/css/calendario-centro.css', __FILE__), [], $css_version);

        wp_localize_script('rdt-calendario-centro', 'RDTCalendario', [
            'apiUrl'          => rest_url('rdt/v1/calendario'),
            'apiUrlJornadas'  => rest_url('rdt/v1/calendario/jornadas'),
            'apiUrlTurno'     => rest_url('rdt/v1/turno'),
            'apiUrlHorarios'  => rest_url('rdt/v1/horarios'),
            'apiUrlServicios' => rest_url('rdt/v1/servicios'),
            'fecha'           => $fecha,
            'centroId'        => $centro_id,
            'nonce'           => wp_create_nonce('wp_rest'),
        ]);
    }

    /**
     * Formulario admin para crear jornadas en nombre de un centro.
     * Solo se llama cuando el shortcode [admin_crear_jornada] está presente
     * y el usuario tiene capacidad manage_options.
     */
    public static function load_admin_crear_jornada(): void
    {
        $js_path     = plugin_dir_path(__FILE__) . 'js/admin-crear-jornada.js';
        $version     = file_exists($js_path) ? filemtime($js_path) : '1.0.0';
        $css_path    = plugin_dir_path(__FILE__) . 'css/admin-crear-jornada.css';
        $css_version = file_exists($css_path) ? filemtime($css_path) : '1.0.0';

        wp_enqueue_script('rdt-admin-crear-jornada', plugins_url('/js/admin-crear-jornada.js', __FILE__), [], $version, true);
        wp_enqueue_style('rdt-admin-crear-jornada', plugins_url('/css/admin-crear-jornada.css', __FILE__), [], $css_version);

        wp_localize_script('rdt-admin-crear-jornada', 'RDTAdminJornada', [
            'apiCentros'     => rest_url('rdt/v1/admin/centros'),
            'apiReservaForm' => rest_url('rdt/v1/admin/reserva-form'),
            'apiJornada'     => rest_url('rdt/v1/admin/jornada'),
            'nonce'          => wp_create_nonce('wp_rest'),
        ]);
    }

    /**
     * Popup de oferta SSA (obsoleto como método separado, mantenido por compatibilidad)
     */
    public static function load_ssa_booking_offer_popup(): void
    {
        // Este método ya no tiene efecto — la lógica se integró en load_ssa_prefill().
    }

    /**
     * Mi Cuenta del centro estético
     */
    public static function load_woo_account(): void
    {
        if (is_admin()) return;

        $css_path    = plugin_dir_path(__FILE__) . 'css/woo-account.css';
        $css_version = file_exists($css_path) ? filemtime($css_path) : '1.0.0';
        wp_enqueue_style('rdt-woo-account', plugins_url('/css/woo-account.css', __FILE__), ['woocommerce-general', 'woocommerce-layout'], $css_version);

        $js_path = plugin_dir_path(__FILE__) . 'js/woo-account.js';
        $version = file_exists($js_path) ? filemtime($js_path) : '1.0.0';
        wp_enqueue_script('rdt-woo-account', plugins_url('/js/woo-account.js', __FILE__), [], $version, true);
        wp_localize_script('rdt-woo-account', 'RDTAccount', [
            'apiUrl' => rest_url('rdt/v1/centro/perfil'),
            'nonce'  => wp_create_nonce('wp_rest'),
        ]);
    }

    /**
     * Panel de gestión de servicios del centro estético.
     */
    public static function load_panel_servicios(): void
    {
        if (is_admin()) return;

        $js_path     = plugin_dir_path(__FILE__) . 'js/panel-servicios.js';
        $version     = file_exists($js_path) ? filemtime($js_path) : '1.0.0';
        $css_path    = plugin_dir_path(__FILE__) . 'css/panel-servicios.css';
        $css_version = file_exists($css_path) ? filemtime($css_path) : '1.0.0';

        if (file_exists($css_path)) {
            wp_enqueue_style('rdt-panel-servicios', plugins_url('/css/panel-servicios.css', __FILE__), [], $css_version);
        }

        wp_enqueue_script('rdt-panel-servicios', plugins_url('/js/panel-servicios.js', __FILE__), [], $version, true);
        wp_localize_script('rdt-panel-servicios', 'RDTServicios', [
            'apiUrl' => rest_url('rdt/v1/centro/servicios'),
            'nonce'  => wp_create_nonce('wp_rest'),
        ]);
    }

    /**
     * Prefill del formulario SSA + popup de oferta de gel.
     * Inyecta datos del centro y del producto en el JS.
     */
    public static function load_ssa_prefill(array $datos): void
    {
        $js_path     = plugin_dir_path(__FILE__) . 'js/ssa-prefill.js';
        $version     = file_exists($js_path) ? filemtime($js_path) : '1.0.0';
        $css_path    = plugin_dir_path(__FILE__) . 'css/ssa-oferta-popup.css';
        $css_version = file_exists($css_path) ? filemtime($css_path) : '1.0.0';

        wp_enqueue_script('rdt-ssa-prefill', plugins_url('/js/ssa-prefill.js', __FILE__), [], $version, true);
        wp_enqueue_style('rdt-ssa-oferta-popup', plugins_url('/css/ssa-oferta-popup.css', __FILE__), [], $css_version);

        $oferta = null;
        if (function_exists('wc_get_product')) {
            $product_post = get_page_by_path('pote_gel', OBJECT, 'product');
            if ($product_post) {
                $product = wc_get_product($product_post->ID);
                if ($product && $product->is_purchasable() && $product->is_in_stock()) {
                    $oferta = [
                        'product_name'  => $product->get_name(),
                        'product_price' => wc_price($product->get_price()),
                    ];
                }
            }
        }

        wp_localize_script('rdt-ssa-prefill', 'RDTSsaPrefill', array_merge($datos, [
            'oferta'              => $oferta,
            'apiJornadaConfirmar' => rest_url('rdt/v1/jornada/confirmar'),
            'apiJornadaPendiente' => rest_url('rdt/v1/jornada/pendiente'),
            'nonce'               => wp_create_nonce('wp_rest'),
        ]));
    }

    /**
     * Prefill del formulario CF7.
     */
    public static function load_cf7_prefill(array $datos): void
    {
        if (is_admin()) return;

        $js_path = plugin_dir_path(__FILE__) . 'js/cf7-prefill.js';
        $version = file_exists($js_path) ? filemtime($js_path) : '1.0.0';
        wp_enqueue_script('rdt-cf7-prefill', plugins_url('/js/cf7-prefill.js', __FILE__), [], $version, true);
        wp_localize_script('rdt-cf7-prefill', 'RDTCf7Prefill', $datos);
    }

    public static function load_cancelar_turno(string $token): void
    {
        if (is_admin()) return;

        $css_path    = plugin_dir_path(__FILE__) . 'css/cancelar-turno.css';
        $css_version = file_exists($css_path) ? filemtime($css_path) : '1.0.0';
        wp_enqueue_style('rdt-cancelar-turno', plugins_url('/css/cancelar-turno.css', __FILE__), [], $css_version);

        $js_path = plugin_dir_path(__FILE__) . 'js/cancelar-turno.js';
        $version = file_exists($js_path) ? filemtime($js_path) : '1.0.0';
        wp_enqueue_script('rdt-cancelar-turno', plugins_url('/js/cancelar-turno.js', __FILE__), [], $version, true);
        wp_localize_script('rdt-cancelar-turno', 'RDTTurno', [
            'apiUrl' => rest_url('rdt/v1/turno/cancelar'),
            'token'  => $token,
        ]);
    }
}
 