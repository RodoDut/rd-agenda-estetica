<?php

declare(strict_types=1);
namespace RDT\CentrosEstetica\CPT;

/**
 * CentroEstetico CPT
 *
 * Registra el Custom Post Type 'centro_estetico' y gestiona
 * sus meta boxes en el panel de administración.
 *
 * Campos del perfil del centro:
 *  - post_title          → Nombre del centro
 *  - usuario_responsable → ID del usuario WP vinculado
 *  - telefono            → Teléfono de contacto
 *  - whatsapp            → Número de WhatsApp
 *  - email               → Email de contacto
 *  - direccion           → Dirección del local
 *  - localidad           → Localidad
 *  - provincia           → Provincia
 */
class CentroEstetico
{
    public static function register(): void
    {
        register_post_type('centro_estetico', [
            'label'           => 'Centros Estéticos',
            'public'          => false,
            'show_ui'         => true,
            'menu_icon'       => 'dashicons-store',
            'supports'        => ['title'],
            'capability_type' => 'post',
            'map_meta_cap'    => true,
        ]);

        // Registrar meta boxes para el admin
        add_action('add_meta_boxes', [self::class, 'registrarMetaBoxes']);
        add_action('save_post_centro_estetico', [self::class, 'guardarMetaBoxes']);
    }

    /**
     * Registra el meta box de datos del centro en el admin.
     */
    public static function registrarMetaBoxes(): void
    {
        add_meta_box(
            'rdt_datos_centro',
            'Datos del Centro Estético',
            [self::class, 'renderMetaBox'],
            'centro_estetico',
            'normal',
            'high'
        );
    }

    /**
     * Renderiza el meta box en el admin con todos los campos del centro.
     */
    public static function renderMetaBox(\WP_Post $post): void
    {
        wp_nonce_field('rdt_guardar_centro', 'rdt_centro_nonce');

        $campos = [
            'usuario_responsable' => ['label' => 'ID Usuario Responsable', 'type' => 'number', 'desc' => 'ID del usuario de WordPress vinculado a este centro.'],
            'telefono'            => ['label' => 'Teléfono',               'type' => 'tel',    'desc' => 'Teléfono de contacto del centro.'],
            'whatsapp'            => ['label' => 'WhatsApp',               'type' => 'tel',    'desc' => 'Número de WhatsApp (sin 0 ni 15). Ej: 3415001234'],
            'email'               => ['label' => 'Email de contacto',      'type' => 'email',  'desc' => 'Email público del centro.'],
            'direccion'           => ['label' => 'Dirección',              'type' => 'text',   'desc' => 'Dirección del local.'],
            'localidad'           => ['label' => 'Localidad',              'type' => 'text',   'desc' => 'Ciudad o localidad.'],
            'provincia'           => ['label' => 'Provincia',              'type' => 'text',   'desc' => 'Provincia.'],
        ];

        echo '<table class="form-table" style="width:100%;">';

        foreach ($campos as $key => $campo) {
            $value = get_post_meta($post->ID, $key, true);
            $label = esc_html($campo['label']);
            $type  = esc_attr($campo['type']);
            $desc  = esc_html($campo['desc']);
            $val   = esc_attr($value);

            echo "
            <tr>
                <th style='width:180px; padding:8px 10px;'>
                    <label for='rdt_{$key}'>{$label}</label>
                </th>
                <td style='padding:8px 10px;'>
                    <input
                        type='{$type}'
                        id='rdt_{$key}'
                        name='rdt_{$key}'
                        value='{$val}'
                        style='width:100%; max-width:400px;'
                        class='regular-text'
                    />
                    <p class='description'>{$desc}</p>
                </td>
            </tr>";
        }

        echo '</table>';
    }

    /**
     * Guarda los datos del meta box al actualizar el post en el admin.
     */
    public static function guardarMetaBoxes(int $post_id): void
    {
        // Verificar nonce
        if (
            !isset($_POST['rdt_centro_nonce']) ||
            !wp_verify_nonce($_POST['rdt_centro_nonce'], 'rdt_guardar_centro')
        ) {
            return;
        }

        // No guardar en auto-save
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        // Verificar permisos
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        $campos = [
            'usuario_responsable',
            'telefono',
            'whatsapp',
            'email',
            'direccion',
            'localidad',
            'provincia',
        ];

        foreach ($campos as $campo) {
            if (isset($_POST["rdt_{$campo}"])) {
                $valor = sanitize_text_field($_POST["rdt_{$campo}"]);
                update_post_meta($post_id, $campo, $valor);

                // Sincronizar también via ACF si está disponible
                if (function_exists('update_field')) {
                    update_field($campo, $valor, $post_id);
                }
            }
        }

        // Disparar hook para que otros servicios (ej: SsaPrefill) puedan reaccionar
        do_action('rdt/centro/perfil_actualizado', $post_id);

        error_log("CentroEstetico: Meta box guardado para CPT ID {$post_id}.");
    }
}
