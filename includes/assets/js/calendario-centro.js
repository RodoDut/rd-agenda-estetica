/**
 * calendario-centro.js
 * Lógica del calendario diario del panel del centro estético.
 *
 * Flujo de inicialización:
 * 1. Carga las fechas con jornadas activas desde /rdt/v1/calendario/jornadas
 * 2. Arranca en la primera jornada activa (próxima fecha >= hoy, o la primera disponible)
 * 3. Las flechas de navegación solo se mueven entre esas fechas
 * 4. Al hacer click en un slot libre se abre el modal de reserva interna
 */

document.addEventListener('DOMContentLoaded', async () => {

    const contenedor    = document.getElementById('rdt-calendario');
    const grilla        = document.getElementById('rdt-cal-grilla');
    const titulo        = document.getElementById('rdt-cal-titulo');
    const estadoJornada = document.getElementById('rdt-cal-estado-jornada');
    const btnAnterior   = document.getElementById('rdt-cal-anterior');
    const btnSiguiente  = document.getElementById('rdt-cal-siguiente');

    if (!contenedor || !grilla) return;

    const centroId        = contenedor.dataset.centro;
    const apiUrlTurnoBase = RDTCalendario.apiUrlTurno;

    let indiceActual  = 0;
    let fechasActivas = [];
    let fechaActual   = null;
    let servicios     = [];

    // ─── Inicialización ──────────────────────────────────────────────────────
    grilla.innerHTML = '<p class="rdt-cal-cargando">Cargando agenda...</p>';

    try {
        const [resJornadas, resTratamientos] = await Promise.all([
            fetch(RDTCalendario.apiUrlJornadas,     { headers: { 'X-WP-Nonce': RDTCalendario.nonce } }),
            fetch(RDTCalendario.apiUrlServicios,     { headers: { 'X-WP-Nonce': RDTCalendario.nonce } }),
        ]);

        const dataJornadas     = await resJornadas.json();
        const dataTratamientos = await resTratamientos.json();

        if (!resJornadas.ok || dataJornadas.error) {
            grilla.innerHTML = `<p class="rdt-cal-error">${dataJornadas.error || 'Error al cargar las jornadas.'}</p>`;
            return;
        }
        if (!resTratamientos.ok || dataTratamientos.error) {
            grilla.innerHTML = `<p class="rdt-cal-error">${dataTratamientos.error || 'Error al cargar los tratamientos.'}</p>`;
            return;
        }


        fechasActivas = dataJornadas.fechas          || [];
        servicios     = dataTratamientos.tratamientos || [];

    } catch (e) {
        console.error('CalendarioCentro (init):', e);
        grilla.innerHTML = '<p class="rdt-cal-error">Error de conexión al cargar la agenda.</p>';
        return;
    }

    if (fechasActivas.length === 0) {
        titulo.textContent = 'Sin jornadas activas';
        grilla.innerHTML   = '<p class="rdt-cal-vacio">No tenés jornadas activas registradas.</p>';
        actualizarBotones();
        return;
    }

    const fechaHoy = hoy();
    indiceActual   = fechasActivas.findIndex(f => f >= fechaHoy);
    if (indiceActual === -1) indiceActual = 0;

    actualizarBotones();
    cargarCalendario(fechasActivas[indiceActual]);

    // ─── Recarga al recuperar el foco de la pestaña ───────────────────────────
    // Cuando el centro aprueba un turno desde el email (otra pestaña) y vuelve
    // al panel, recargamos el calendario para mostrar el estado actualizado.
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible' && fechaActual) {
            cargarCalendario(fechaActual);
        }
    });

    // ─── Cerrar todos los dropdowns al hacer click fuera ─────────────────────
    document.addEventListener('click', () => {
        cerrarTodosLosDropdowns();
    });

    // ─── Navegación ──────────────────────────────────────────────────────────
    btnAnterior.addEventListener('click', () => {
        if (indiceActual <= 0) return;
        indiceActual--;
        actualizarBotones();
        cargarCalendario(fechasActivas[indiceActual]);
    });

    btnSiguiente.addEventListener('click', () => {
        if (indiceActual >= fechasActivas.length - 1) return;
        indiceActual++;
        actualizarBotones();
        cargarCalendario(fechasActivas[indiceActual]);
    });

    function actualizarBotones() {
        btnAnterior.disabled  = (indiceActual <= 0 || fechasActivas.length === 0);
        btnSiguiente.disabled = (indiceActual >= fechasActivas.length - 1 || fechasActivas.length === 0);
    }

    // ─── Carga del día ───────────────────────────────────────────────────────
    async function cargarCalendario(fecha) {
        fechaActual               = fecha;
        titulo.textContent        = formatearFechaTitulo(fecha);
        estadoJornada.textContent = '';
        grilla.innerHTML          = '<p class="rdt-cal-cargando">Cargando turnos...</p>';

        try {
            const url      = `${RDTCalendario.apiUrl}?fecha=${fecha}&_=${Date.now()}`;
            const response = await fetch(url, { headers: { 'X-WP-Nonce': RDTCalendario.nonce } });
            const data     = await response.json();

            if (!response.ok || data.error) {
                grilla.innerHTML = `<p class="rdt-cal-error">${data.error || 'Error al cargar el calendario.'}</p>`;
                return;
            }

            renderizarEstadoJornada(data);
            renderizarGrilla(data);

        } catch (e) {
            console.error('CalendarioCentro:', e);
            grilla.innerHTML = '<p class="rdt-cal-error">Error de conexión. Intente nuevamente.</p>';
        }
    }

    // ─── Renderizado ─────────────────────────────────────────────────────────
    function renderizarEstadoJornada(data) {
        if (data.jornada_estado === 'sin_jornada') {
            estadoJornada.innerHTML = '<span class="rdt-badge rdt-badge--sin-jornada">Sin jornada registrada</span>';
            return;
        }

        const etiquetas = {
            activa:     { texto: 'Jornada activa',     clase: 'activa'     },
            completada: { texto: 'Jornada completada', clase: 'completada' },
            cancelada:  { texto: 'Jornada cancelada',  clase: 'cancelada'  },
            expirada:   { texto: 'Jornada expirada',   clase: 'expirada'   },
        };

        const info = etiquetas[data.jornada_estado] || { texto: data.jornada_estado, clase: '' };
        estadoJornada.innerHTML =
            `<span class="rdt-badge rdt-badge--${info.clase}">${info.texto}</span>` +
            (data.hora_inicio && data.hora_fin
                ? ` <span class="rdt-cal-horario-jornada">${data.hora_inicio} – ${data.hora_fin} hs</span>`
                : '');
    }

    function renderizarGrilla(data) {
        if (!data.slots || data.slots.length === 0) {
            grilla.innerHTML = '<p class="rdt-cal-vacio">No hay horarios disponibles para este día.</p>';
            return;
        }

        grilla.innerHTML = '';

        data.slots.forEach(slot => {
            const fila = document.createElement('div');

            if (slot.estado === 'ocupado') {
                const estadoActual = slot.estado_turno || 'aprobado';
                const infoBadge    = etiquetaEstado(estadoActual);
                const esPasado     = esTurnoPasado(fechaActual, slot.hora);
                const esExpirado   = estadoActual === 'expirado';

                const claseAtenuado = ['cancelado', 'rechazado'].includes(estadoActual) ? ' rdt-slot--atenuado' : '';
                fila.className = `rdt-slot rdt-slot--ocupado${claseAtenuado}`;

                // Construir opciones del menú según reglas de negocio
                let opcionesHtml = '';
                if (!esExpirado) {
                    const estadosDeCierre = [
                        { valor: 'cancelado',  texto: 'Cancelado'  },
                        { valor: 'rechazado',  texto: 'Rechazado'  },
                        { valor: 'completado', texto: 'Completado' },
                        { valor: 'ausente',    texto: 'Ausente'    },
                    ];
                    const estadosDeFuturo = esPasado ? [] : [
                        { valor: 'aprobado',    texto: 'Aprobado'    },
                        { valor: 'pendiente', texto: 'Pendiente' },
                    ];

                    opcionesHtml = [...estadosDeFuturo, ...estadosDeCierre].map(({ valor, texto }) => `
                        <li role="option"
                            data-estado="${valor}"
                            class="rdt-estado-dropdown__opcion ${estadoActual === valor ? 'is-active' : ''}">
                            <span class="rdt-estado-dropdown__dot rdt-estado-dropdown__dot--${valor}"></span>
                            ${texto}
                        </li>
                    `).join('');
                }

                // ── Recordatorio WhatsApp ─────────────────────────────────────
                // Delegamos la responsabilidad de verificar el estado de envío a la función especializada.
                const wppHtml     = construirHtmlWhatsapp(slot, esExpirado);

                fila.innerHTML = `
                    <div class="rdt-slot__hora">${slot.hora}</div>
                    <div class="rdt-slot__detalle">
                        <span class="rdt-badge-estado rdt-badge-estado--${escHtml(estadoActual)}">${infoBadge.icono} ${infoBadge.texto}</span>
                        <span class="rdt-slot__cliente">${escHtml(slot.nombre_cliente)}</span>
                        <span class="rdt-slot__tratamiento">${escHtml(slot.tratamiento)}</span>
                        <span class="rdt-slot__hasta">hasta las ${escHtml(slot.hora_fin)} hs</span>
                    </div>
                    <div class="rdt-slot__contacto">
                        ${wppHtml}
                    </div>
                    <div class="rdt-slot__acciones">
                        <div class="rdt-estado-dropdown"
                             data-turno-id="${slot.turno_id}"
                             data-estado-actual="${escHtml(estadoActual)}"
                             data-fecha="${escHtml(fechaActual)}"
                             data-hora="${escHtml(slot.hora)}">
                            <button class="rdt-estado-dropdown__trigger"
                                    type="button"
                                    aria-haspopup="true"
                                    aria-expanded="false"
                                    ${esExpirado ? 'disabled title="Turno expirado — no puede modificarse"' : ''}>
                                <span class="rdt-estado-dropdown__label">${infoBadge.icono} ${infoBadge.texto}</span>
                                ${!esExpirado ? '<span class="rdt-estado-dropdown__chevron">&#9660;</span>' : ''}
                            </button>
                            ${!esExpirado ? `<ul class="rdt-estado-dropdown__menu" role="listbox">${opcionesHtml}</ul>` : ''}
                            <span class="rdt-estado-dropdown__feedback"></span>
                        </div>
                    </div>
                `;

                // ── Eventos del dropdown de estado ────────────────────────────
                if (!esExpirado) {
                    const dropdown = fila.querySelector('.rdt-estado-dropdown');
                    const trigger  = dropdown.querySelector('.rdt-estado-dropdown__trigger');
                    const menu     = dropdown.querySelector('.rdt-estado-dropdown__menu');

                    trigger.addEventListener('click', (e) => {
                        e.stopPropagation();
                        const yaAbierto = dropdown.classList.contains('is-open');
                        cerrarTodosLosDropdowns();
                        if (!yaAbierto) {
                            dropdown.classList.add('is-open');
                            trigger.setAttribute('aria-expanded', 'true');
                        }
                    });

                    menu.querySelectorAll('.rdt-estado-dropdown__opcion').forEach(opcion => {
                        opcion.addEventListener('click', async (e) => {
                            e.stopPropagation();
                            const nuevoEstado    = opcion.dataset.estado;
                            const estadoAnterior = dropdown.dataset.estadoActual;
                            cerrarTodosLosDropdowns();
                            if (nuevoEstado === estadoAnterior) return;
                            const nombreCliente = dropdown
                                .closest('.rdt-slot')
                                ?.querySelector('.rdt-slot__cliente')
                                ?.textContent?.trim() || 'esta cliente';
                            const confirmado = await confirmarCambioEstado(
                                nombreCliente,
                                etiquetaEstado(estadoAnterior),
                                etiquetaEstado(nuevoEstado)
                            );
                            if (!confirmado) return;
                            await cambiarEstadoTurno(dropdown.dataset.turnoId, nuevoEstado, dropdown);
                        });
                    });
                }

                // ── Evento del botón WhatsApp ─────────────────────────────────
                const btnWpp = fila.querySelector('.rdt-slot__btn-whatsapp');
                // Solo vinculamos el evento si el recordatorio no ha sido enviado aún.
                if (btnWpp && (!slot.recordatorio_enviado || slot.recordatorio_enviado === '0')) {
                    btnWpp.addEventListener('click', async (e) => {
                        e.stopPropagation();

                        const tel = slot.telefono_cliente.replace(/\D/g, '');
                        const fechaFmt = formatearFechaTitulo(fechaActual);
                        const msg = `Hola! ${slot.nombre_cliente} Queremos recordarte que el día ${fechaFmt} tiene un turno de ${slot.tratamiento} a las ${slot.hora} hs. Puede confirmar su asistencia?`;
                        const url = `https://wa.me/${tel}?text=${encodeURIComponent(msg)}`;

                        window.open(url, '_blank', 'noopener,noreferrer');

                        const exito = await registrarEnvioWhatsapp(slot.turno_id, btnWpp);
                        
                        if (exito) {
                            slot.recordatorio_enviado = true; // Sincronización con el estado booleano del servidor
                            const contactoDiv = btnWpp.closest('.rdt-slot__contacto');
                            if (contactoDiv) {
                                contactoDiv.innerHTML = construirHtmlWhatsapp(slot, esExpirado);
                            }
                        }
                    });
                }

            } else {
                fila.className = 'rdt-slot rdt-slot--libre';
                fila.innerHTML = `
                    <div class="rdt-slot__hora">${slot.hora}</div>
                    <div class="rdt-slot__detalle rdt-slot__detalle--libre">Libre hasta las ${escHtml(slot.hora_fin)} hs</div>
                    <div class="rdt-slot__accion">
                        <button class="rdt-slot__btn-reservar" data-hora="${escHtml(slot.hora)}">+ Reservar</button>
                    </div>
                `;
                fila.querySelector('.rdt-slot__btn-reservar').addEventListener('click', () => {
                    abrirModal(slot.hora);
                });
            }

            grilla.appendChild(fila);
        });
    }

    // ─── WhatsApp reminder: helpers ───────────────────────────────────────────

    /**
     * Devuelve el HTML del bloque de contacto WhatsApp.
     * Verifica de forma robusta el campo ACF 'recordatorio_enviado'.
     *
     * @param {object}  slot        - Objeto con datos del turno.
     * @param {boolean} esExpirado  - Si el tiempo de la jornada ya pasó.
     */
    function construirHtmlWhatsapp(slot, esExpirado) {
        const enviado = slot.recordatorio_enviado;
        console.log(`Nombre Cliente: ${slot.nombre_cliente} - recordatorio_enviado:`, enviado);
        // Verificación robusta: no nulo, no falso, no string vacío y no "0" (común en metadatos de WP/ACF)
        if (enviado && enviado !== '0' && enviado !== '') {
            // Estado: recordatorio ya enviado
            return `
                <div class="rdt-slot__wpp-enviado">
                    <span class="rdt-slot__wpp-check">✓</span>
                    <span class="rdt-slot__wpp-texto">Recordatorio enviado</span>
                </div>
            `;
        }

        // Estado: pendiente de envío
        const disabled = esExpirado ? 'disabled title="Turno expirado"' : '';
        return `
            <button type="button" class="rdt-slot__btn-whatsapp" ${disabled}>
                📲 Enviar recordatorio por WhatsApp
            </button>
        `;
    }

    /**
     * Registra en el servidor que se ha enviado el recordatorio de WhatsApp.
     * Llama al endpoint PATCH /turno/{id}/recordatorio.
     */
    async function registrarEnvioWhatsapp(turnoId, btn) {
        btn.disabled = true;
        const originalText = btn.innerHTML;
        btn.textContent = 'Guardando...';

        try {
            const res = await fetch(`${apiUrlTurnoBase}/${turnoId}/recordatorio`, {
                method: 'PATCH',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': RDTCalendario.nonce
                }
            });
            const data = await res.json();
            if (!res.ok || data.error) throw new Error(data.error || 'Error desconocido');
            return true;
        } catch (e) {
            console.error('registrarEnvioWhatsapp:', e);
            btn.innerHTML = originalText;
            btn.disabled = false;
            return false;
        }
    }

    // ─── Helpers de dropdown ─────────────────────────────────────────────────

    function cerrarTodosLosDropdowns() {
        grilla.querySelectorAll('.rdt-estado-dropdown.is-open').forEach(d => {
            d.classList.remove('is-open');
            d.querySelector('.rdt-estado-dropdown__trigger')?.setAttribute('aria-expanded', 'false');
        });
    }

    // ─── Modal de reserva interna ─────────────────────────────────────────────
    function crearModal() {
        if (document.getElementById('rdt-modal')) return;

        const opciones = servicios.map(t =>
            `<option value="${t.id}">${escHtml(t.nombre)}</option>`
        ).join('');

        const modal = document.createElement('div');
        modal.id        = 'rdt-modal';
        modal.className = 'rdt-modal-overlay';
        modal.innerHTML = `
            <div class="rdt-modal" role="dialog" aria-modal="true" aria-labelledby="rdt-modal-titulo">
                <div class="rdt-modal-header">
                    <h3 id="rdt-modal-titulo" class="rdt-modal-titulo">Nuevo turno</h3>
                    <button class="rdt-modal-cerrar" aria-label="Cerrar">&#x2715;</button>
                </div>
                <div class="rdt-modal-cuerpo">
                    <p class="rdt-modal-subtitulo">
                        <span id="rdt-modal-fecha"></span> — <span id="rdt-modal-hora"></span> hs
                    </p>

                    <label class="rdt-modal-label" for="rdt-modal-tratamiento">Servicio</label>
                    <select id="rdt-modal-tratamiento" class="rdt-modal-select">
                        <option value="">Seleccioná un servicio</option>
                        ${opciones}
                    </select>

                    <div id="rdt-modal-horarios-wrap" style="display:none;">
                        <label class="rdt-modal-label" for="rdt-modal-horario">Horario disponible</label>
                        <select id="rdt-modal-horario" class="rdt-modal-select"></select>
                    </div>

                    <div id="rdt-modal-form-wrap" style="display:none;">
                        <label class="rdt-modal-label" for="rdt-modal-nombre">Nombre del cliente</label>
                        <input type="text" id="rdt-modal-nombre" class="rdt-modal-input" placeholder="Ej: Ana García" required>

                        <label class="rdt-modal-label" for="rdt-modal-email">Email</label>
                        <input type="email" id="rdt-modal-email" class="rdt-modal-input" placeholder="email@ejemplo.com" required>

                        <label class="rdt-modal-label">Teléfono WhatsApp</label>
                        <div class="rdt-modal-telefono-grupo">
                            <div class="rdt-modal-telefono-campo">
                                <span class="rdt-modal-telefono-hint">Cód. de área <em>sin el 0</em></span>
                                <input type="tel"
                                       id="rdt-modal-cod-area"
                                       class="rdt-modal-input rdt-modal-telefono-input"
                                       inputmode="numeric"
                                       maxlength="5"
                                       placeholder="341"
                                       required>
                            </div>
                            <div class="rdt-modal-telefono-campo">
                                <span class="rdt-modal-telefono-hint">Número <em>sin el 15</em></span>
                                <input type="tel"
                                       id="rdt-modal-numero"
                                       class="rdt-modal-input rdt-modal-telefono-input"
                                       inputmode="numeric"
                                       maxlength="8"
                                       placeholder="5795765"
                                       required>
                            </div>
                        </div>
                        <p class="rdt-modal-telefono-preview" id="rdt-modal-tel-preview" aria-live="polite"></p>
                    </div>

                    <div id="rdt-modal-msg" class="rdt-modal-msg"></div>

                    <button id="rdt-modal-submit" class="rdt-modal-btn" style="display:none;">Confirmar turno</button>
                </div>
            </div>
        `;

        document.body.appendChild(modal);

        // ── Teléfono: solo dígitos y preview en tiempo real ───────────────────
        const codAreaModal = modal.querySelector('#rdt-modal-cod-area');
        const numeroModal  = modal.querySelector('#rdt-modal-numero');
        const telPreview   = modal.querySelector('#rdt-modal-tel-preview');

        function actualizarPreviewModal() {
            const cod = codAreaModal.value.replace(/\D/g, '');
            const num = numeroModal.value.replace(/\D/g, '');
            if (!cod && !num) { telPreview.textContent = ''; return; }
            telPreview.textContent = `+549 ${cod} ${num}`;
        }

        [codAreaModal, numeroModal].forEach(input => {
            input.addEventListener('input', (e) => {
                e.target.value = e.target.value.replace(/\D/g, '');
                actualizarPreviewModal();
            });
        });
        // ─────────────────────────────────────────────────────────────────────

        modal.querySelector('.rdt-modal-cerrar').addEventListener('click', cerrarModal);
        modal.addEventListener('click', e => { if (e.target === modal) cerrarModal(); });
        document.addEventListener('keydown', e => { if (e.key === 'Escape') cerrarModal(); });

        modal.querySelector('#rdt-modal-tratamiento').addEventListener('change', async function () {
            const servicioId   = this.value;
            const horariosWrap = modal.querySelector('#rdt-modal-horarios-wrap');
            const formWrap     = modal.querySelector('#rdt-modal-form-wrap');
            const submitBtn    = modal.querySelector('#rdt-modal-submit');
            const msg          = modal.querySelector('#rdt-modal-msg');

            horariosWrap.style.display = 'none';
            formWrap.style.display     = 'none';
            submitBtn.style.display    = 'none';
            msg.textContent            = '';

            if (!servicioId) return;

            msg.textContent = 'Cargando horarios...';
            msg.className   = 'rdt-modal-msg';

            try {
                const hora   = modal.querySelector('#rdt-modal-hora').textContent;
                const params = new URLSearchParams({ fecha: fechaActual, servicio: servicioId });

                const res  = await fetch(`${RDTCalendario.apiUrlHorarios}?${params}`, {
                    headers: { 'X-WP-Nonce': RDTCalendario.nonce },
                });
                const data = await res.json();

                msg.textContent = '';

                if (!res.ok || data.error) {
                    msg.textContent = data.error || 'No hay horarios disponibles.';
                    msg.className   = 'rdt-modal-msg rdt-modal-msg--error';
                    return;
                }

                if (!data.horarios || data.horarios.length === 0) {
                    msg.textContent = 'No hay horarios disponibles para este servicio.';
                    msg.className   = 'rdt-modal-msg rdt-modal-msg--error';
                    return;
                }

                const selectHorario = modal.querySelector('#rdt-modal-horario');
                selectHorario.innerHTML = '<option value="">Seleccioná un horario</option>';
                data.horarios.forEach(h => {
                    const opt       = document.createElement('option');
                    opt.value       = h;
                    opt.textContent = h;
                    if (h === hora) opt.selected = true;
                    selectHorario.appendChild(opt);
                });

                horariosWrap.style.display = 'block';
                formWrap.style.display     = 'block';
                submitBtn.style.display    = 'block';

            } catch (e) {
                console.error('Modal horarios:', e);
                msg.textContent = 'Error de conexión.';
                msg.className   = 'rdt-modal-msg rdt-modal-msg--error';
            }
        });

        modal.querySelector('#rdt-modal-submit').addEventListener('click', async () => {
            const msg       = modal.querySelector('#rdt-modal-msg');
            const submitBtn = modal.querySelector('#rdt-modal-submit');
            const horario   = modal.querySelector('#rdt-modal-horario').value;
            const servicio  = modal.querySelector('#rdt-modal-tratamiento').value;
            const nombre    = modal.querySelector('#rdt-modal-nombre').value.trim();
            const email     = modal.querySelector('#rdt-modal-email').value.trim();
            const codArea   = modal.querySelector('#rdt-modal-cod-area').value.replace(/\D/g, '');
            const numero    = modal.querySelector('#rdt-modal-numero').value.replace(/\D/g, '');

            msg.textContent = '';
            msg.className   = 'rdt-modal-msg';

            if (!horario || !servicio || !nombre || !email) {
                msg.textContent = 'Completá todos los campos antes de confirmar.';
                msg.className   = 'rdt-modal-msg rdt-modal-msg--error';
                return;
            }

            if (codArea.length < 2 || numero.length < 6) {
                msg.textContent = 'Ingresá el código de área (sin el 0) y el número (sin el 15).';
                msg.className   = 'rdt-modal-msg rdt-modal-msg--error';
                return;
            }

            // Formato internacional para wa.me: 549 + código de área + número
            const telefono_completo = '549' + codArea + numero;

            submitBtn.disabled    = true;
            submitBtn.textContent = 'Confirmando...';

            try {
                const res = await fetch(RDTCalendario.apiUrlTurno, {
                    method:  'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-WP-Nonce':   RDTCalendario.nonce,
                    },
                    body: JSON.stringify({
                        fecha:            fechaActual,
                        servicio_id:      parseInt(servicio),
                        hora_inicio:      horario,
                        nombre_cliente:   nombre,
                        email_cliente:    email,
                        telefono_cliente: telefono_completo,
                    }),
                });

                const data = await res.json();

                if (!res.ok || data.error) {
                    msg.textContent       = data.error || 'No se pudo confirmar el turno.';
                    msg.className         = 'rdt-modal-msg rdt-modal-msg--error';
                    submitBtn.disabled    = false;
                    submitBtn.textContent = 'Confirmar turno';
                    return;
                }

                msg.textContent = '✓ Turno confirmado correctamente.';
                msg.className   = 'rdt-modal-msg rdt-modal-msg--exito';

                setTimeout(() => {
                    cerrarModal();
                    cargarCalendario(fechaActual);
                }, 1200);

            } catch (e) {
                console.error('Modal submit:', e);
                msg.textContent       = 'Error de conexión.';
                msg.className         = 'rdt-modal-msg rdt-modal-msg--error';
                submitBtn.disabled    = false;
                submitBtn.textContent = 'Confirmar turno';
            }
        });
    }

    function abrirModal(hora) {
        crearModal();
        const modal = document.getElementById('rdt-modal');

        modal.querySelector('#rdt-modal-tratamiento').value           = '';
        modal.querySelector('#rdt-modal-horarios-wrap').style.display = 'none';
        modal.querySelector('#rdt-modal-form-wrap').style.display     = 'none';
        modal.querySelector('#rdt-modal-submit').style.display        = 'none';
        modal.querySelector('#rdt-modal-submit').disabled             = false;
        modal.querySelector('#rdt-modal-submit').textContent          = 'Confirmar turno';
        modal.querySelector('#rdt-modal-nombre').value        = '';
        modal.querySelector('#rdt-modal-email').value          = '';
        modal.querySelector('#rdt-modal-cod-area').value       = '';
        modal.querySelector('#rdt-modal-numero').value         = '';
        modal.querySelector('#rdt-modal-tel-preview').textContent = '';
        modal.querySelector('#rdt-modal-msg').textContent      = '';
        modal.querySelector('#rdt-modal-msg').className        = 'rdt-modal-msg';

        modal.querySelector('#rdt-modal-fecha').textContent = formatearFechaTitulo(fechaActual);
        modal.querySelector('#rdt-modal-hora').textContent  = hora;

        modal.classList.add('rdt-modal-overlay--visible');
        document.body.classList.add('rdt-modal-abierto');
    }

    function cerrarModal() {
        const modal = document.getElementById('rdt-modal');
        if (!modal) return;
        modal.classList.remove('rdt-modal-overlay--visible');
        document.body.classList.remove('rdt-modal-abierto');
    }

    // ─── Diálogo de confirmación ──────────────────────────────────────────────
    function confirmarCambioEstado(nombreCliente, infoAnterior, infoNuevo) {
        return new Promise((resolve) => {

            const overlayId = 'rdt-confirm-overlay';
            let overlay = document.getElementById(overlayId);
            if (overlay) overlay.remove();

            overlay = document.createElement('div');
            overlay.id        = overlayId;
            overlay.className = 'rdt-confirm-overlay';
            overlay.innerHTML = `
                <div class="rdt-confirm" role="dialog" aria-modal="true" aria-labelledby="rdt-confirm-titulo">
                    <div class="rdt-confirm__header">
                        <span class="rdt-confirm__icono">&#9888;</span>
                        <h3 id="rdt-confirm-titulo" class="rdt-confirm__titulo">Confirmar cambio de estado</h3>
                    </div>
                    <div class="rdt-confirm__cuerpo">
                        <p class="rdt-confirm__mensaje">
                            Vas a cambiar el turno de
                            <strong>${escHtml(nombreCliente)}</strong>
                            de
                            <span class="rdt-confirm__estado rdt-confirm__estado--anterior">
                                ${escHtml(infoAnterior.icono)} ${escHtml(infoAnterior.texto)}
                            </span>
                            a
                            <span class="rdt-confirm__estado rdt-confirm__estado--nuevo">
                                ${escHtml(infoNuevo.icono)} ${escHtml(infoNuevo.texto)}
                            </span>.
                        </p>
                        <p class="rdt-confirm__aviso">Se enviará una notificación al cliente informando el cambio.</p>
                    </div>
                    <div class="rdt-confirm__acciones">
                        <button class="rdt-confirm__btn rdt-confirm__btn--cancelar" type="button">Cancelar</button>
                        <button class="rdt-confirm__btn rdt-confirm__btn--ok"       type="button">Confirmar</button>
                    </div>
                </div>
            `;

            document.body.appendChild(overlay);

            const btnOk       = overlay.querySelector('.rdt-confirm__btn--ok');
            const btnCancelar = overlay.querySelector('.rdt-confirm__btn--cancelar');

            function cerrar(resultado) {
                overlay.classList.remove('rdt-confirm-overlay--visible');
                setTimeout(() => overlay.remove(), 180);
                resolve(resultado);
            }

            btnOk.addEventListener('click',      () => cerrar(true));
            btnCancelar.addEventListener('click', () => cerrar(false));
            overlay.addEventListener('click', (e) => { if (e.target === overlay) cerrar(false); });

            const onKeyDown = (e) => {
                if (e.key === 'Escape') { document.removeEventListener('keydown', onKeyDown); cerrar(false); }
                if (e.key === 'Enter')  { document.removeEventListener('keydown', onKeyDown); cerrar(true);  }
            };
            document.addEventListener('keydown', onKeyDown);

            requestAnimationFrame(() => overlay.classList.add('rdt-confirm-overlay--visible'));
            btnOk.focus();
        });
    }

    // ─── PATCH de estado ─────────────────────────────────────────────────────
    async function cambiarEstadoTurno(turnoId, nuevoEstado, dropdown) {
        const trigger  = dropdown.querySelector('.rdt-estado-dropdown__trigger');
        const feedback = dropdown.querySelector('.rdt-estado-dropdown__feedback');
        const label    = dropdown.querySelector('.rdt-estado-dropdown__label');

        trigger.disabled     = true;
        feedback.textContent = '';
        feedback.className   = 'rdt-estado-dropdown__feedback';

        const labelOriginal = label.innerHTML;
        label.textContent   = 'Guardando...';

        try {
            const res = await fetch(`${apiUrlTurnoBase}/${turnoId}/estado`, {
                method:  'PATCH',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce':   RDTCalendario.nonce,
                },
                body: JSON.stringify({ estado: nuevoEstado }),
            });

            const data = await res.json();

            if (!res.ok || data.error) {
                label.innerHTML      = labelOriginal;
                trigger.disabled     = false;
                feedback.textContent = data.error || 'Error al guardar';
                feedback.className   = 'rdt-estado-dropdown__feedback rdt-estado-dropdown__feedback--error';
                setTimeout(() => { feedback.textContent = ''; }, 4000);
                console.error('cambiarEstadoTurno:', data.error);
                return;
            }

            const info = etiquetaEstado(nuevoEstado);
            label.innerHTML               = `${info.icono} ${info.texto}`;
            dropdown.dataset.estadoActual = nuevoEstado;

            dropdown.querySelectorAll('.rdt-estado-dropdown__opcion').forEach(op => {
                op.classList.toggle('is-active', op.dataset.estado === nuevoEstado);
            });

            const slotPadre = dropdown.closest('.rdt-slot');
            const badge     = slotPadre?.querySelector('.rdt-badge-estado');
            if (badge) {
                badge.textContent = `${info.icono} ${info.texto}`;
                badge.className   = `rdt-badge-estado rdt-badge-estado--${nuevoEstado}`;
            }

            const esAtenuado = ['cancelado', 'rechazado'].includes(nuevoEstado);
            slotPadre?.classList.toggle('rdt-slot--atenuado', esAtenuado);

            feedback.textContent = '✓ Guardado';
            feedback.className   = 'rdt-estado-dropdown__feedback rdt-estado-dropdown__feedback--exito';
            setTimeout(() => { feedback.textContent = ''; }, 2000);

            trigger.disabled = false;

        } catch (e) {
            console.error('cambiarEstadoTurno (red):', e);
            label.innerHTML      = labelOriginal;
            trigger.disabled     = false;
            feedback.textContent = 'Error de conexión';
            feedback.className   = 'rdt-estado-dropdown__feedback rdt-estado-dropdown__feedback--error';
            setTimeout(() => { feedback.textContent = ''; }, 3000);
        }
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    function etiquetaEstado(estado) {
        const mapa = {
            aprobado:     { icono: '✅', texto: 'Aprobado'     },
            pendiente:  { icono: '⏳', texto: 'Pendiente'  },
            cancelado:  { icono: '❌', texto: 'Cancelado'  },
            rechazado:  { icono: '🚫', texto: 'Rechazado'  },
            completado: { icono: '✔️', texto: 'Completado' },
            ausente:    { icono: '👻', texto: 'Ausente'    },
            expirado:   { icono: '⌛', texto: 'Expirado'   },
        };
        return mapa[estado] || { icono: '●', texto: estado };
    }

    function esTurnoPasado(fecha, hora) {
        try {
            return new Date(`${fecha}T${hora}:00`) <= new Date();
        } catch (e) {
            console.error('esTurnoPasado:', e);
            return false;
        }
    }

    function hoy() {
        return new Date().toISOString().split('T')[0];
    }

    function formatearFechaTitulo(fechaStr) {
        const d = new Date(fechaStr + 'T12:00:00');
        return d.toLocaleDateString('es-AR', {
            weekday: 'long',
            year:    'numeric',
            month:   'long',
            day:     'numeric',
        });
    }

    function escHtml(str) {
        if (!str) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }
});
