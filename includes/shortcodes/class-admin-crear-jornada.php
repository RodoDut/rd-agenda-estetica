<?php

declare(strict_types=1);
namespace RDT\CentrosEstetica\Shortcodes;

use RDT\CentrosEstetica\Assets\AssetsLoader;
use function RDT\CentrosEstetica\Helpers\get_centro_by_user;
use function RDT\CentrosEstetica\Helpers\get_datos_centro;

/**
 * AdminCrearJornada — Shortcode [admin_crear_jornada]
 *
 * Permite a un administrador crear una jornada en nombre de un centro estético,
 * manteniendo la sincronización completa con SSA.
 *
 * FLUJO:
 *   Paso 1 — El admin ve el selector de centros (no hay ?centro_user_id en la URL).
 *             Al hacer click en "Continuar", navega a la misma página con
 *             ?centro_user_id={id} como parámetro GET.
 *
 *   Paso 2 — El shortcode detecta ?centro_user_id, obtiene los datos del centro,
 *             renderiza [ssa_booking] prefillado con esos datos y muestra un aviso
 *             al admin. A partir de aquí el flujo es idéntico al de un centro
 *             estético logueado: SSA dispara el hook booked, se crea jornada_centro,
 *             el email se envía después del popup de gel.
 *
 * SEGURIDAD: solo usuarios con 'manage_options' pueden usar este shortcode.
 * El parámetro centro_user_id se valida en servidor para confirmar que el usuario
 * pertenece al rol centro_estetico antes de precargar cualquier dato.
 *
 * Uso: [admin_crear_jornada]
 */
final class AdminCrearJornada
{
    public static function register(): void
    {
        add_shortcode('admin_crear_jornada', [self::class, 'render']);
    }

    public static function render(): string
    {
        if (!current_user_can('manage_options')) {
            return '';
        }

        // ── Paso 2: ya se eligió el centro ──────────────────────────────────
        $centro_user_id = (int) ($_GET['centro_user_id'] ?? 0);

        if ($centro_user_id > 0) {
            return self::renderizarFormularioSSA($centro_user_id);
        }

        // ── Paso 1: selector de centros ─────────────────────────────────────
        return self::renderizarSelector();
    }

    // ── Paso 1 ───────────────────────────────────────────────────────────────

    private static function renderizarSelector(): string
    {
        AssetsLoader::load_admin_crear_jornada();

        $url_actual = esc_url(strtok($_SERVER['REQUEST_URI'] ?? '', '?'));

        ob_start();
        ?>
        <div id="rdt-admin-jornada" class="rdt-admin-jornada">

            <div class="rdt-admin-jornada__header">
                <h2 class="rdt-admin-jornada__titulo">Crear jornada para un centro</h2>
                <p class="rdt-admin-jornada__subtitulo">
                    Seleccioná el centro estético al que querés asignarle una jornada.
                    Luego completarás la fecha y el horario directamente en el formulario de reserva.
                </p>
            </div>

            <div class="rdt-admin-jornada__form">

                <div class="rdt-admin-campo">
                    <label class="rdt-admin-label" for="rdt-admin-centro">Centro estético</label>
                    <select id="rdt-admin-centro" class="rdt-admin-select">
                        <option value="">Cargando centros...</option>
                    </select>
                </div>

                <p id="rdt-admin-msg" class="rdt-admin-msg" aria-live="polite"></p>

                <button type="button"
                        id="rdt-admin-submit"
                        class="rdt-admin-btn"
                        data-url-base="<?= esc_attr($url_actual) ?>">
                    Continuar con la reserva →
                </button>

            </div>

        </div>
        <?php
        return ob_get_clean();
    }

    // ── Paso 2 ───────────────────────────────────────────────────────────────

    private static function renderizarFormularioSSA(int $centro_user_id): string
    {
        error_log('AdminCrearJornada::renderizarFormularioSSA - Inicio con centro_user_id: ' . $centro_user_id);

        // Validar que el usuario tiene rol de centro estético
        $usuario = get_userdata($centro_user_id);
        if (!$usuario) {
            error_log('AdminCrearJornada::renderizarFormularioSSA - Usuario no encontrado para ID: ' . $centro_user_id);
            return '<p class="rdt-admin-error">Usuario no encontrado.</p>';
        }

        $roles_centro = ['centro_estetico', 'centro_estetico_premium'];
        if (!array_intersect((array) $usuario->roles, $roles_centro)) {
            error_log('AdminCrearJornada::renderizarFormularioSSA - Usuario no tiene rol de centro: ' . implode(', ', $usuario->roles));
            return '<p class="rdt-admin-error">El usuario seleccionado no tiene rol de centro estético.</p>';
        }

        $centro_id = get_centro_by_user($centro_user_id);
        if (!$centro_id) {
            error_log('AdminCrearJornada::renderizarFormularioSSA - No se encontró perfil de centro para user ID: ' . $centro_user_id);
            return '<p class="rdt-admin-error">No se encontró el perfil de centro estético para este usuario.</p>';
        }

        error_log('AdminCrearJornada::renderizarFormularioSSA - Centro ID encontrado: ' . $centro_id);

        $datos       = get_datos_centro($centro_id);
        $nombre      = !empty($datos['nombre']) ? $datos['nombre'] : $usuario->display_name;
        $redirect_url = home_url('/reservas/');

        error_log('AdminCrearJornada::renderizarFormularioSSA - Datos del centro: (datos sensibles ocultos por seguridad)');

        // Cargar el prefill de SSA con los datos del centro seleccionado.
        // El flag 'es_admin' indica al JS que debe mostrar el card de feedback
        // con el link de agenda en lugar del toast habitual.
        AssetsLoader::load_ssa_prefill([
            'nombre'     => $nombre,
            'email'      => !empty($datos['email']) ? $datos['email'] : $usuario->user_email,
            'telefono'   => $datos['telefono'] ?: $datos['whatsapp'],
            'direccion'  => $datos['direccion'],
            'localidad'  => $datos['localidad'],
            'provincia'  => $datos['provincia'],
            'redirectUrl' => $redirect_url,
            'esAdmin'    => true,  // activa el card de feedback para admins
        ]);

        error_log('AdminCrearJornada::renderizarFormularioSSA - Prefill cargado para email: (email oculto por seguridad)');

        $url_volver = esc_url(strtok($_SERVER['REQUEST_URI'] ?? '', '?'));

        ob_start();
        ?>
        <div class="rdt-admin-jornada">

            <div class="rdt-admin-jornada__header">
                <h2 class="rdt-admin-jornada__titulo">
                    Reservando jornada para <em><?= esc_html($nombre) ?></em>
                </h2>
                <p class="rdt-admin-jornada__subtitulo">
                    Completá la fecha y el horario en el formulario de abajo.
                    Al confirmar, la jornada quedará registrada en el calendario de SSA
                    y se creará el enlace de agenda para compartir con los clientes del centro.
                </p>
            </div>

            <div class="rdt-admin-jornada__aviso">
                <span class="rdt-admin-jornada__aviso-icono">ℹ️</span>
                Cuando SSA confirme la reserva, aparecerá automáticamente el enlace de agenda
                para que lo puedas copiar y enviar al centro.
                <a href="<?= $url_volver ?>" class="rdt-admin-jornada__volver">← Cambiar centro</a>
            </div>

            <div class="rdt-admin-jornada__ssa-wrap">
                <?= do_shortcode('[ssa_booking redirect_url="' . $redirect_url . '"]') ?>
            </div>

            <!-- Card de feedback: lo inyecta ssa-prefill.js cuando detecta la confirmación de SSA -->
            <div id="rdt-admin-feedback" class="rdt-admin-feedback" style="display:none;"></div>

        </div>
        <?php
        return ob_get_clean();
    }
}
