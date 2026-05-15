<?php
namespace RDT\CentrosEstetica\Shortcodes;

use RDT\CentrosEstetica\Assets\AssetsLoader;
use function RDT\CentrosEstetica\Helpers\get_centro_by_user;


class AgendaCentro {

    public static function register() {
        add_shortcode('agenda_centro', [self::class, 'render']);
    }

    public static function render() {

        // Flujo Interno: Solo usuarios logueados (Dueños de centro)
        if ( ! is_user_logged_in() ) {
            return '<p>Debes iniciar sesión para ver tu agenda.</p>';
        }

        $centro_id = get_centro_by_user(get_current_user_id());
        if ( ! $centro_id ) {
            return '<p>No tienes un centro asociado.</p>';
        }

        // En panel interno, la fecha suele venir por GET o es hoy
        $fecha = isset($_GET['fecha']) ? sanitize_text_field($_GET['fecha']) : date('Y-m-d');

        AssetsLoader::load_agenda_centro($fecha, $centro_id);
        
        ob_start();
        ?>
        <div id="rdt-agenda"
             data-centro="<?= esc_attr($centro_id) ?>" 
             data-fecha="<?= esc_attr($fecha) ?>">

            <h3>Reservá tu turno</h3>

            <label>Tratamiento</label>
            <select id="rdt-tratamiento">
                <option value="">Seleccioná un tratamiento</option>
                <?php
                $tratamientos = get_posts([
                    'post_type' => 'tratamiento_dep',
                    'numberposts' => -1
                ]);
                foreach ($tratamientos as $t) {
                    echo "<option value='{$t->ID}'>{$t->post_title}</option>";
                }
                ?>
            </select>
            
            <label>Horario:</label>
            <select id="rdt-horarios"></select>

            <form id="rdt-form-turno" style="display:none;">
                <input type="hidden" id="rdt-hora">

                <label>Nombre</label>
                <input type="text" id="rdt-nombre" required>

                <label>Email</label>
                <input type="email" id="rdt-email" required>

                <label>Teléfono</label>
                <input type="text" id="rdt-telefono" required>

                <button type="submit">Confirmar turno</button>
            </form>

            <div id="rdt-mensaje"></div>
        </div>
        <?php
        return ob_get_clean();
    }
}