/**
 * admin-crear-jornada.js
 *
 * Lógica del Paso 1 del shortcode [admin_crear_jornada]:
 * carga los centros disponibles en el selector y navega al Paso 2
 * cuando el admin confirma la selección.
 *
 * El Paso 2 (formulario SSA + feedback) lo gestiona ssa-prefill.js,
 * que detecta la variable RDTSsaPrefill.esAdmin y muestra el card
 * de confirmación con el link de agenda en lugar del toast habitual.
 */
document.addEventListener('DOMContentLoaded', async () => {

    const contenedor = document.getElementById('rdt-admin-jornada');
    if (!contenedor) return;

    const selectCentro = document.getElementById('rdt-admin-centro');
    const btnSubmit    = document.getElementById('rdt-admin-submit');
    const msgEl        = document.getElementById('rdt-admin-msg');

    // Si no hay selector, estamos en el Paso 2 — este script no tiene trabajo aquí.
    if (!selectCentro || !btnSubmit || !msgEl) return;

    const { apiCentros, nonce } = RDTAdminJornada;
    const urlBase = btnSubmit.dataset.urlBase || window.location.pathname;

    // ─── Carga inicial de centros ─────────────────────────────────────────────
    try {
        const res  = await fetch(apiCentros, { headers: { 'X-WP-Nonce': nonce } });
        const data = await res.json();

        if (!res.ok || !data.centros) throw new Error(data.error || 'Error al cargar centros.');

        selectCentro.innerHTML = '<option value="">Seleccioná un centro estético</option>';
        data.centros.forEach(c => {
            const opt       = document.createElement('option');
            opt.value       = c.wp_user_id;
            opt.textContent = `${c.nombre} — ${c.email}`;
            selectCentro.appendChild(opt);
        });

    } catch (e) {
        selectCentro.innerHTML = '<option value="">Error al cargar centros</option>';
        mostrarMensaje('No se pudieron cargar los centros: ' + e.message, 'error');
    }

    // ─── Navegación al Paso 2 ─────────────────────────────────────────────────
    // No usamos AJAX: navegamos a la misma página con ?centro_user_id=X.
    // Esto garantiza que [ssa_booking] se renderice correctamente en PHP
    // con los datos del centro prefillados por AssetsLoader::load_ssa_prefill().
    btnSubmit.addEventListener('click', () => {
        const wp_user_id = parseInt(selectCentro.value);


        if (!wp_user_id) {
            mostrarMensaje('Seleccioná un centro estético antes de continuar.', 'error');
            return;
        }

        // Navegar al Paso 2
        window.location.href = urlBase + '?centro_user_id=' + wp_user_id;
    });

    // ─── Helper ──────────────────────────────────────────────────────────────
    function mostrarMensaje(texto, tipo) {
        msgEl.textContent = texto;
        msgEl.className   = tipo ? `rdt-admin-msg rdt-admin-msg--${tipo}` : 'rdt-admin-msg';
    }
});
