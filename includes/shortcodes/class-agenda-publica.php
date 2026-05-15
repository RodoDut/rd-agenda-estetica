<?php
namespace RDT\CentrosEstetica\Shortcodes;

/*
Shortcode para mostrar la agenda pública (clienta final)
Se accede a esta agenda mediante un enlace que contiene un token único, asociado a una jornada 
específica de un centro estético. 
Este token se genera al crear la jornada y se comparte con las clientas para que puedan reservar 
sus turnos.
*/

use function RDT\CentrosEstetica\Helpers\get_jornada_by_token;
use RDT\CentrosEstetica\Assets\AssetsLoader;

class AgendaPublica {

    private const SLUG_PAGINA = 'reserva-turno-depilacion';

    public static function register() {
        add_shortcode('agenda_publica', [self::class, 'render']);
        // Inyectamos las etiquetas en el head del sitio
        add_action('wp_head', [self::class, 'inject_opengraph_tags'], 5);
    }

    /**
     * Inyecta etiquetas OpenGraph para que el link compartido en WhatsApp se vea profesional.
     * Aprovecha el token de la URL para personalizar la información.
     */
    public static function inject_opengraph_tags(): void {
        // Solo actuar si estamos en la página de la agenda y hay un token
        if ( ! is_page( self::SLUG_PAGINA ) || empty( $_GET['token'] ) ) {
            return;
        }

        $token   = sanitize_text_field( $_GET['token'] );
        $jornada = get_jornada_by_token( $token );

        if ( ! $jornada ) {
            return;
        }

        // Obtenemos el nombre del centro estético dinámicamente
        $nombre_centro = get_the_title( $jornada->centro_estetico_id );
        $titulo        = "Reservá tu turno en {$nombre_centro}";
        $descripcion   = "Elegí tu horario para depilación láser Soprano Titanium de forma online.";
        $url_imagen    = 'https://rdtecnobelleza.net/wp-content/uploads/2024/logo-whatsapp.jpg'; 

        echo "\n<!-- RDT OpenGraph Tags -->\n";
        echo '<meta property="og:type" content="website" />' . "\n";
        echo '<meta property="og:title" content="' . esc_attr( $titulo ) . '" />' . "\n";
        echo '<meta property="og:description" content="' . esc_attr( $descripcion ) . '" />' . "\n";
        echo '<meta property="og:image" content="' . esc_url( $url_imagen ) . '" />' . "\n";
        echo '<meta property="og:url" content="' . esc_url( get_permalink() ) . '" />' . "\n";
        echo '<meta property="og:site_name" content="RD Tecno Belleza" />' . "\n";
        echo '<meta property="og:image:width" content="600" />' . "\n";
        echo '<meta property="og:image:height" content="600" />' . "\n";
    }

    public static function render() {

        if (empty($_GET['token'])) {
            return '<p>Agenda no disponible.</p>';
        }

        $token = sanitize_text_field($_GET['token']);
        $jornada = get_jornada_by_token($token);

        if (!$jornada) {
            return '<p>Agenda no válida o expirada.</p>';
        }

        AssetsLoader::load_agenda_publica($token);

        ob_start();
        ?>
        <div id="rdt-agenda-publica"
             data-token="<?= esc_attr($token) ?>">

            <h3>Reservá tu turno</h3>

            <label class="rdt-modal-label" for="rdt-servicio">Servicio</label>
            <select id="rdt-servicio" class="rdt-modal-select">
                <option value="">Seleccioná un servicio</option>
                <?php
                $servicios = get_posts([
                    'post_type' => 'servicios_clientes',
                    'numberposts' => -1,
                    'orderby' => 'title',
                    'order' => 'ASC',
                    'meta_query' => [
                        ['key' => 'centro_estetico_id', 'value' => $jornada->centro_estetico_id]
                    ]
                ]);
                foreach ($servicios as $s) {
                    $detalle = get_post_meta($s->ID, 'detalle_servicio', true);
                    $texto_option = esc_html($s->post_title);
                    if (!empty($detalle)) {
                        $texto_option .= ' — ' . esc_html($detalle);
                    }
                    echo "<option value='" . esc_attr($s->ID) . "'>{$texto_option}</option>";
                }
                ?>
            </select>

            <label class="rdt-modal-label" for="rdt-horarios">Horario</label>
            <select id="rdt-horarios" class="rdt-modal-select" disabled>
                <option value="">Seleccioná un horario</option>
            </select>

            <form id="rdt-form-turno" class="rdt-modal-cuerpo" style="display:none; padding: 0; margin-top: 20px;">
                <input type="hidden" id="rdt-hora">
                <label class="rdt-modal-label" for="rdt-nombre">Nombre completo</label>
                <input type="text" id="rdt-nombre" class="rdt-modal-input" placeholder="Ej: Ana García" required>
                <label class="rdt-modal-label" for="rdt-email">Email</label>
                <input type="email" id="rdt-email" class="rdt-modal-input" placeholder="Ej: ana@gmail.com" required>

                <label class="rdt-modal-label">Teléfono WhatsApp</label>
                <p class="rdt-telefono-hint">
                    Ingresá tu número en dos partes para que podamos contactarte por WhatsApp.
                </p>
                <div class="rdt-telefono-grupo">
                    <div class="rdt-telefono-campo">
                        <label class="rdt-telefono-label" for="rdt-cod-area">
                            Cód. de área
                            <span class="rdt-telefono-ejemplo">Sin el 0 · Ej: 341</span>
                        </label>
                        <input type="tel"
                               id="rdt-cod-area"
                               class="rdt-modal-input rdt-telefono-input"
                               inputmode="numeric"
                               pattern="[0-9]{2,5}"
                               maxlength="5"
                               placeholder="341"
                               required>
                    </div>
                    <div class="rdt-telefono-campo">
                        <label class="rdt-telefono-label" for="rdt-numero">
                            Número
                            <span class="rdt-telefono-ejemplo">Sin el 15 · Ej: 5795765</span>
                        </label>
                        <input type="tel"
                               id="rdt-numero"
                               class="rdt-modal-input rdt-telefono-input"
                               inputmode="numeric"
                               pattern="[0-9]{6,8}"
                               maxlength="8"
                               placeholder="5795765"
                               required>
                    </div>
                </div>
                <p class="rdt-telefono-preview" id="rdt-telefono-preview" aria-live="polite"></p>
                <button type="submit" class="rdt-modal-btn">Confirmar turno</button>
            </form>

            <div id="rdt-mensaje"></div>
        </div>
        <?php
        return ob_get_clean();
    }
}