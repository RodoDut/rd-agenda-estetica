/**
 * panel-servicios.js
 * Gestión de servicios del centro estético desde el escritorio de Mi Cuenta.
 *
 * Conecta con:
 *   GET    /wp-json/rdt/v1/centro/servicios
 *   POST   /wp-json/rdt/v1/centro/servicios
 *   PUT    /wp-json/rdt/v1/centro/servicios/{id}
 *   DELETE /wp-json/rdt/v1/centro/servicios/{id}
 *
 * RDTServicios.apiUrl y RDTServicios.nonce se inyectan via wp_localize_script.
 */
document.addEventListener('DOMContentLoaded', () => {

    const lista        = document.getElementById('rdt-lista-servicios');
    const btnAgregar   = document.getElementById('rdt-btn-agregar-servicio');
    const overlay      = document.getElementById('rdt-servicios-overlay');
    const btnCerrar    = document.getElementById('rdt-btn-cerrar-servicio');
    const tituloModal  = document.getElementById('rdt-servicios-titulo-modal');
    const form         = document.getElementById('rdt-form-servicio');

    if (!lista || !form) return;

    const api   = RDTServicios.apiUrl;
    const nonce = RDTServicios.nonce;

    let servicioEditandoId = null;
    let totalServicios     = 0;
    let limiteServicios    = 10;

    // ── Carga inicial ─────────────────────────────────────────────────────────

    cargarServicios();

    // ── Abrir / cerrar modal ──────────────────────────────────────────────────

    btnAgregar?.addEventListener('click', () => abrirModal());
    btnCerrar?.addEventListener('click',  () => cerrarModal());

    overlay?.addEventListener('click', (e) => {
        if (e.target === overlay) cerrarModal();
    });

    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') cerrarModal();
    });

    // ── Submit del formulario ─────────────────────────────────────────────────

    form.addEventListener('submit', async (e) => {
        e.preventDefault();

        const nombre   = document.getElementById('rdt-serv-nombre').value.trim();
        const categoria = document.getElementById('rdt-serv-categoria').value;
        const duracion = parseInt(document.getElementById('rdt-serv-duracion').value);
        const detalle  = document.getElementById('rdt-serv-detalle').value.trim();

        if (!nombre || !duracion || duracion < 1) {
            mostrarErrorForm('Nombre y duración son obligatorios (duración mínima: 1 minuto).');
            return;
        }

        const submitBtn = form.querySelector('button[type="submit"]');
        submitBtn.disabled    = true;
        submitBtn.textContent = 'Guardando...';

        const payload = {
            nombre,
            duracion_servicio:  duracion,
            detalle_servicio:   detalle,
            categoria_servicio: categoria,
        };

        try {
            const url    = servicioEditandoId ? `${api}/${servicioEditandoId}` : api;
            const method = servicioEditandoId ? 'PUT' : 'POST';

            const res  = await fetch(url, {
                method,
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce':   nonce,
                },
                body: JSON.stringify(payload),
            });

            const data = await res.json();

            if (!res.ok || data.error) {
                mostrarErrorForm(data.error || 'Error al guardar el servicio.');
                submitBtn.disabled    = false;
                submitBtn.textContent = 'Guardar Servicio';
                return;
            }

            cerrarModal();
            await cargarServicios();

        } catch (err) {
            console.error(err);
            mostrarErrorForm('Error de conexión. Intentá de nuevo.');
            submitBtn.disabled    = false;
            submitBtn.textContent = 'Guardar Servicio';
        }
    });

    // ── Funciones principales ─────────────────────────────────────────────────

    async function cargarServicios() {
        lista.innerHTML = '<p class="rdt-panel-cargando">Cargando servicios...</p>';

        try {
            const res  = await fetch(api, {
                headers: { 'X-WP-Nonce': nonce },
            });
            const data = await res.json();

            if (!res.ok) {
                lista.innerHTML = '<p class="rdt-panel-error">Error al cargar los servicios.</p>';
                return;
            }

            totalServicios  = data.total  ?? 0;
            limiteServicios = data.limite ?? 10;

            // Mostrar/ocultar botón según el límite
            if (btnAgregar) {
                btnAgregar.style.display = data.puede_agregar ? 'inline-flex' : 'none';
            }

            renderizarLista(data.servicios ?? []);

        } catch (err) {
            console.error(err);
            lista.innerHTML = '<p class="rdt-panel-error">Error de conexión.</p>';
        }
    }

    function renderizarLista(servicios) {
        if (!servicios.length) {
            lista.innerHTML = `
                <p class="rdt-panel-vacio">
                    Todavía no tenés servicios cargados. Hacé click en "+ Agregar Servicio" para empezar.
                </p>`;
            if (btnAgregar) btnAgregar.style.display = 'inline-flex';
            return;
        }

        const contador = document.createElement('p');
        contador.className   = 'rdt-panel-contador';
        contador.textContent = `${totalServicios} de ${limiteServicios} servicios`;

        const tabla = document.createElement('table');
        tabla.className = 'rdt-servicios-tabla';
        tabla.innerHTML = `
            <thead>
                <tr>
                    <th>Nombre</th>
                    <th>Categoría</th>
                    <th>Duración</th>
                    <th>Acciones</th>
                </tr>
            </thead>
            <tbody></tbody>`;

        const tbody = tabla.querySelector('tbody');

        servicios.forEach(s => {
            const tr = document.createElement('tr');
            tr.innerHTML = `
                <td>
                    <strong>${escHtml(s.nombre)}</strong>
                    ${s.detalle ? `<br><small class="rdt-serv-detalle">${escHtml(s.detalle)}</small>` : ''}
                </td>
                <td>${escHtml(s.categoria || '—')}</td>
                <td>${s.duracion} min</td>
                <td class="rdt-serv-acciones">
                    <button type="button" class="rdt-serv-btn rdt-serv-btn--editar" data-id="${s.id}">✏️ Editar</button>
                    <button type="button" class="rdt-serv-btn rdt-serv-btn--eliminar" data-id="${s.id}" data-nombre="${escHtml(s.nombre)}">🗑 Eliminar</button>
                </td>`;

            tr.querySelector('.rdt-serv-btn--editar').addEventListener('click', () => {
                abrirModal(s);
            });

            tr.querySelector('.rdt-serv-btn--eliminar').addEventListener('click', () => {
                confirmarEliminar(s.id, s.nombre, tr);
            });

            tbody.appendChild(tr);
        });

        lista.innerHTML = '';
        lista.appendChild(contador);
        lista.appendChild(tabla);
    }

    async function confirmarEliminar(id, nombre, fila) {
        if (!confirm(`¿Eliminar el servicio "${nombre}"? Esta acción no se puede deshacer.`)) {
            return;
        }

        try {
            const res = await fetch(`${api}/${id}`, {
                method:  'DELETE',
                headers: { 'X-WP-Nonce': nonce },
            });

            const data = await res.json();

            if (!res.ok || data.error) {
                alert(data.error || 'Error al eliminar el servicio.');
                return;
            }

            await cargarServicios();

        } catch (err) {
            console.error(err);
            alert('Error de conexión al eliminar el servicio.');
        }
    }

    // ── Helpers del modal ─────────────────────────────────────────────────────

    function abrirModal(servicio = null) {
        servicioEditandoId = servicio ? servicio.id : null;

        tituloModal.textContent = servicio ? 'Editar Servicio' : 'Nuevo Servicio';

        // Rellenar o limpiar campos
        document.getElementById('rdt-serv-nombre').value   = servicio?.nombre   ?? '';
        document.getElementById('rdt-serv-duracion').value = servicio?.duracion ?? '';
        document.getElementById('rdt-serv-detalle').value  = servicio?.detalle  ?? '';

        const catSelect = document.getElementById('rdt-serv-categoria');
        if (servicio?.categoria) {
            catSelect.value = servicio.categoria;
        } else {
            catSelect.selectedIndex = 0;
        }

        limpiarErrorForm();

        const submitBtn = form.querySelector('button[type="submit"]');
        submitBtn.disabled    = false;
        submitBtn.textContent = 'Guardar Servicio';

        overlay.classList.add('rdt-overlay--activo');
        overlay.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';

        document.getElementById('rdt-serv-nombre').focus();
    }

    function cerrarModal() {
        overlay.classList.remove('rdt-overlay--activo');
        overlay.setAttribute('aria-hidden', 'true');
        document.body.style.overflow = '';
        servicioEditandoId = null;
        form.reset();
        limpiarErrorForm();
    }

    function mostrarErrorForm(msg) {
        let errorDiv = form.querySelector('.rdt-form-error');
        if (!errorDiv) {
            errorDiv = document.createElement('p');
            errorDiv.className = 'rdt-form-error';
            form.insertBefore(errorDiv, form.querySelector('.rdt-popup-acciones'));
        }
        errorDiv.textContent = msg;
    }

    function limpiarErrorForm() {
        form.querySelector('.rdt-form-error')?.remove();
    }

    function escHtml(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }
});
