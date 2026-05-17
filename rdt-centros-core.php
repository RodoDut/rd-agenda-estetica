<?php
/**
 * Plugin Name: RDT Centros Core
 * Description: Funcionalidades base para centros estéticos (agenda,roles, accesos y seguridad).
 * Version: 1.0.0
 * Author: RD Tecno Belleza
 */

if (!defined('ABSPATH')) {
    exit;
}

// Constantes base
define('RDT_CENTROS_PATH', plugin_dir_path(__FILE__));

// Includes
require_once RDT_CENTROS_PATH . 'includes/roles/roles-register.php';
require_once RDT_CENTROS_PATH . 'includes/roles/roles-restrict.php';

require_once RDT_CENTROS_PATH . 'includes/security/admin-block.php';

require_once RDT_CENTROS_PATH . 'includes/auth/login-redirect.php';
//require_once RDT_CENTROS_PATH . 'includes/auth/magic-login-mail.php';

//require_once RDT_CENTROS_PATH . 'includes/cpt/class-cpt-tratamiento.php';
require_once RDT_CENTROS_PATH . 'includes/cpt/class-cpt-servicios-clientes.php';
require_once RDT_CENTROS_PATH . 'includes/cpt/class-cpt-turno-cliente.php';
require_once RDT_CENTROS_PATH . 'includes/cpt/class-cpt-centro-estetico.php';
require_once RDT_CENTROS_PATH . 'includes/cpt/class-cpt-jornada-centro.php';

//Repositories
require_once RDT_CENTROS_PATH . 'includes/repositories/ServiciosClientesRepository.php';
require_once RDT_CENTROS_PATH . 'includes/repositories/JornadaCentroRepository.php';
require_once RDT_CENTROS_PATH . 'includes/repositories/TurnoClienteRepository.php';


//Account
require_once RDT_CENTROS_PATH . 'includes/account/class-centro-registration.php';
require_once RDT_CENTROS_PATH . 'includes/account/class-registro-ui.php';
require_once RDT_CENTROS_PATH . 'includes/account/class-woo-account-customizer.php';
require_once RDT_CENTROS_PATH . 'includes/account/class-ssa-prefill.php';

//Shortcodes
require_once RDT_CENTROS_PATH . 'includes/shortcodes/class-agenda-publica.php';
require_once RDT_CENTROS_PATH . 'includes/shortcodes/class-reserva-jornada.php';
require_once RDT_CENTROS_PATH . 'includes/shortcodes/class-cancela-turno-publica.php';
require_once RDT_CENTROS_PATH . 'includes/shortcodes/class-calendario-centro.php';
require_once RDT_CENTROS_PATH . 'includes/shortcodes/class-nueva-contrasena.php';
require_once RDT_CENTROS_PATH . 'includes/shortcodes/class-admin-crear-jornada.php';
//Dirección: https://rdtecnobelleza.net/panel-centro/?token=

require_once RDT_CENTROS_PATH . 'includes/assets/assets-loader.php';

//API
require_once RDT_CENTROS_PATH . 'includes/api/class-horarios-controller.php';
require_once RDT_CENTROS_PATH . 'includes/api/class-turno-controller.php';
require_once RDT_CENTROS_PATH . 'includes/api/class-cancelar-turno-controller.php';
require_once RDT_CENTROS_PATH . 'includes/api/class-calendario-controller.php';
require_once RDT_CENTROS_PATH . 'includes/api/class-registro-aprobacion-controller.php';
require_once RDT_CENTROS_PATH . 'includes/api/class-turno-estado-controller.php';
require_once RDT_CENTROS_PATH . 'includes/api/class-turno-aprobacion-controller.php';
require_once RDT_CENTROS_PATH . 'includes/api/class-calendar-export-controller.php';
require_once RDT_CENTROS_PATH . 'includes/api/class-servicios-clientes-controller.php';
require_once RDT_CENTROS_PATH . 'includes/api/class-jornada-gel-controller.php';
require_once RDT_CENTROS_PATH . 'includes/api/class-admin-jornada-controller.php';
require_once RDT_CENTROS_PATH . 'includes/notifications/class-turno-aprobacion-mailer.php';
require_once RDT_CENTROS_PATH . 'includes/notifications/class-turno-estado-mailer.php';

//Install

//require_once RDT_CENTROS_PATH . 'includes/install/class-seed-tratamientos.php';

//Services
require_once RDT_CENTROS_PATH . 'includes/services/HorariosCalculator.php';
require_once RDT_CENTROS_PATH . 'includes/services/TurnoCreator.php';
require_once RDT_CENTROS_PATH . 'includes/services/JornadaCreator.php';
require_once RDT_CENTROS_PATH . 'includes/services/CancelarTurno.php';
require_once RDT_CENTROS_PATH . 'includes/services/class-calendar-service.php';
require_once RDT_CENTROS_PATH . 'includes/services/JornadaExpirador.php';

//Helpers
require_once RDT_CENTROS_PATH . 'includes/helpers/centro-helper.php';
require_once RDT_CENTROS_PATH . 'includes/integrations/ssa-hooks.php';
require_once RDT_CENTROS_PATH . 'includes/helpers/jornada-helper.php';

//Domain
require_once RDT_CENTROS_PATH . 'includes/domain/JornadaEstado.php';
require_once RDT_CENTROS_PATH . 'includes/domain/TurnoEstado.php';

//Notifications
require_once RDT_CENTROS_PATH . 'includes/notifications/class-turno-notification.php';
require_once RDT_CENTROS_PATH . 'includes/notifications/class-ssa-booking-mail.php';
require_once RDT_CENTROS_PATH . 'includes/notifications/class-registro-mailer.php';



use RDT\CentrosEstetica\CPT\Tratamiento;
use RDT\CentrosEstetica\CPT\ServiciosClientes;
use RDT\CentrosEstetica\CPT\TurnoCliente;
//use RDT\CentrosEstetica\Install\SeedTratamientos;
use RDT\CentrosEstetica\Api\HorariosController;
use RDT\CentrosEstetica\Api\TurnoController;
use RDT\CentrosEstetica\CPT\CentroEstetico;
use RDT\CentrosEstetica\CPT\JornadaCentro;
use RDT\CentrosEstetica\Shortcodes\AgendaPublica;
use RDT\CentrosEstetica\Api\CancelarTurnoController;
use RDT\CentrosEstetica\Shortcodes\CancelarTurnoShortcode;
use RDT\CentrosEstetica\Shortcodes\CalendarioCentro;
use RDT\CentrosEstetica\Shortcodes\NuevaContrasena;
use RDT\CentrosEstetica\Shortcodes\AdminCrearJornada;
use RDT\CentrosEstetica\Shortcodes\ReservaJornada;
use RDT\CentrosEstetica\Api\CalendarioController;
use RDT\CentrosEstetica\Api\RegistroAprobacionController;
use RDT\CentrosEstetica\Api\TurnoEstadoController;
use RDT\CentrosEstetica\Api\TurnoAprobacionController;
use RDT\CentrosEstetica\Account\CentroRegistration;
use RDT\CentrosEstetica\Api\CalendarExportController;
use RDT\CentrosEstetica\Api\ServiciosClientesController;
use RDT\CentrosEstetica\Api\JornadaGelController;
use RDT\CentrosEstetica\Api\AdminJornadaController;
use RDT\CentrosEstetica\Account\WooAccountCustomizer;
use RDT\CentrosEstetica\Account\SsaPrefill;

/**
 * Inicializa el plugin una vez que todos los demás plugins están cargados.
 * Esto previene errores de "llamada incorrecta" con librerías como Action Scheduler.
 */
function rdt_centros_core_init()
{
    //Inicialización de funcionalidades
   // add_action('init', [Tratamiento::class, 'register']);   //Agrega Custom Post Type Tratamientos
    add_action('init', [ServiciosClientes::class, 'register']); //Agrega Custom Post Type Servicios Clientes
    add_action('init', [TurnoCliente::class, 'register']);  //Agrega Custom Post Type Turnos Clientes
    add_action('init', [AgendaPublica::class, 'register']);        //Shortcode para agenda pública
    add_action('init', [CancelarTurnoShortcode::class, 'register']); //Shortcode para cancelar turno
    add_action('init', [CalendarioCentro::class, 'register']);
    add_action('init', [NuevaContrasena::class,  'register']);
    add_action('init', [AdminCrearJornada::class, 'register']);        //Shortcode para calendario del panel
    add_action('init', [ReservaJornada::class, 'register']);           //Shortcode para página de reserva de jornada

    //Registro de endpoints de la API
    add_action('rest_api_init', [HorariosController::class, 'register']);
    add_action('rest_api_init', [TurnoController::class, 'register']);
    add_action('rest_api_init', [CancelarTurnoController::class, 'register']);
    add_action('rest_api_init', [CalendarioController::class, 'register']);
    add_action('rest_api_init', [RegistroAprobacionController::class, 'registrarRutas']);
    add_action('rest_api_init', [TurnoEstadoController::class, 'register']);
    add_action('rest_api_init', [TurnoAprobacionController::class, 'register']);
    add_action('rest_api_init', [CalendarExportController::class, 'register']);
    add_action('rest_api_init', [ServiciosClientesController::class, 'register']);
    add_action('rest_api_init', [JornadaGelController::class, 'register']);
    add_action('rest_api_init', [AdminJornadaController::class, 'register']);

    // Account: registro, personalización Mi Cuenta y autocompletado SSA
    CentroRegistration::register();
    WooAccountCustomizer::register();
    SsaPrefill::register();

    //Registro de roles y restricciones
    add_action('init', [CentroEstetico::class, 'register']);
    add_action('init', [JornadaCentro::class, 'register']);
}
add_action('plugins_loaded', 'rdt_centros_core_init');

/**
 * Al activar o actualizar el plugin, regeneramos las reglas de rewrite
 * para que los endpoints custom de WooCommerce (panel-centro, etc.)
 * queden registrados correctamente sin necesidad de ir a Ajustes > Enlaces permanentes.
 */
register_activation_hook(__FILE__, function () {
    // Registrar endpoints antes de flushear
    add_rewrite_endpoint('panel-centro', EP_ROOT | EP_PAGES);
    flush_rewrite_rules();
});

/**
 * Tambien flusheamos en cada carga si la version del plugin cambio.
 * Esto cubre el caso de actualizaciones sin desactivar/activar.
 */
add_action('init', function () {
    $version_guardada = get_option('rdt_centros_rewrite_version', '');
    $version_actual   = '1.0.1'; // incrementar manualmente al agregar nuevos endpoints

    if ($version_guardada !== $version_actual) {
        flush_rewrite_rules();
        update_option('rdt_centros_rewrite_version', $version_actual);
    }
}, 99);
