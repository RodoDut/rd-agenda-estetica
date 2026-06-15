/**
 * ssa-prefill.js
 *
 * Responsabilidades:
 *   1. Precompletar los campos del formulario SSA con los datos del centro.
 *   2. Detectar la confirmación de la reserva en SSA.
 *   3. Obtener el jornada_id del servidor (transient).
 *   4. Mostrar el popup de oferta de gel (si aplica) y registrar la decisión.
 *   5. Enviar la confirmación al servidor (email + ACF).
 *   6. Mostrar feedback: toast para el centro, card para el admin.
 *
 * FLUJO POST-CONFIRMACIÓN SSA:
 *   SSA muestra "gracias" → espera 3 seg
 *       ↓
 *   GET /jornada/pendiente → obtiene datos de la jornada
 *       ↓
 *   Si hay oferta de gel → popup → usuario decide (bool solicitoGel)
 *   Si no hay oferta     → solicitoGel = false
 *       ↓
 *   POST /jornada/confirmar { solicitoGel, ...datosjornada }
 *   (marca ACF + envía email de confirmación al centro)
 *       ↓
 *   Si esAdmin → muestra card de feedback con link de agenda
 *   Si no      → muestra toast con botón "Completar"
 */
(function () {

    if (typeof RDTSsaPrefill === 'undefined') {
        //console.log('ssa-prefill: RDTSsaPrefill no definido, saliendo');
        return;
    }

    //console.log('ssa-prefill: Script cargado, RDTSsaPrefill:', RDTSsaPrefill);

    const datos = {
        'name':      RDTSsaPrefill.nombre,
        'email':     RDTSsaPrefill.email,
        'telephone': RDTSsaPrefill.telefono,
        'address':   RDTSsaPrefill.direccion,
        'city':      RDTSsaPrefill.localidad,
        'state':     RDTSsaPrefill.provincia,
    };

    const redirectUrl         = RDTSsaPrefill.redirectUrl         || null;
    const oferta              = RDTSsaPrefill.oferta              || null;
    const apiJornadaConfirmar = RDTSsaPrefill.apiJornadaConfirmar || null;
    const apiJornadaPendiente = RDTSsaPrefill.apiJornadaPendiente || null;
    const nonce               = RDTSsaPrefill.nonce               || '';
    const esAdmin             = RDTSsaPrefill.esAdmin             === true;

    // ─── Helper ──────────────────────────────────────────────────────────────

    function escHtml(str) {
        if (!str) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    // ─── Feedback: toast (centro) o card (admin) ──────────────────────────────

    /**
     * Muestra el toast de confirmación para el usuario centro estético.
     * Incluye botón "Completar" que redirige a la URL configurada.
     */
    function mostrarToastCompletado() {
        if (document.getElementById('rdt-toast-completado')) return;

        const toast = document.createElement('div');
        toast.id = 'rdt-toast-completado';
        toast.innerHTML = `
            <div style="
                position: fixed; bottom: 32px; left: 50%;
                transform: translateX(-50%);
                background: #ffffff; border: 1px solid #e6e0e2;
                border-left: 4px solid #4A7A84; border-radius: 12px;
                box-shadow: 0 8px 32px rgba(0,0,0,0.15);
                padding: 20px 28px; display: flex; align-items: center;
                gap: 20px; z-index: 99999;
                font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
                min-width: 300px; max-width: 90vw; animation: rdt-slide-up 0.3s ease;
            ">
                <div style="flex: 1;">
                    <p style="margin: 0 0 4px; font-weight: 700; color: #0d141a; font-size: 0.95rem;">
                        ✓ Jornada reservada correctamente
                    </p>
                    <p style="margin: 0; color: #6b6067; font-size: 0.84rem;">
                        Se envió el email de confirmación. Podés volver a la página de reservas.
                    </p>
                </div>
                <div style="display: flex; gap: 10px;">
                    <button id="rdt-btn-completar" style="
                        background: #4A7A84; color: #ffffff; border: none;
                        border-radius: 8px; padding: 10px 20px; font-size: 0.9rem;
                        font-weight: 600; cursor: pointer; white-space: nowrap; font-family: inherit;
                    ">Completar</button>
                    <button id="rdt-btn-cancelar-toast" style="
                        background: transparent; color: #6b6067; border: 1.5px solid #e6e0e2;
                        border-radius: 8px; padding: 10px 16px; font-size: 0.9rem;
                        font-weight: 600; cursor: pointer; white-space: nowrap; font-family: inherit;
                    ">Cerrar</button>
                </div>
            </div>
            <style>
                @keyframes rdt-slide-up {
                    from { opacity: 0; transform: translateX(-50%) translateY(20px); }
                    to   { opacity: 1; transform: translateX(-50%) translateY(0); }
                }
            </style>
        `;

        document.body.appendChild(toast);

        document.getElementById('rdt-btn-completar').addEventListener('click', () => {
            if (redirectUrl) window.location.href = redirectUrl;
        });
        document.getElementById('rdt-btn-cancelar-toast').addEventListener('click', () => {
            toast.remove();
        });
    }

    /**
     * Muestra el card de feedback para el administrador.
     * Incluye el link de agenda del centro para copiar y compartir.
     * Se inyecta en #rdt-admin-feedback que el shortcode renderiza en Paso 2.
     *
     * @param {object} datosJornada — respuesta de /jornada/confirmar
     */
    function mostrarCardAdmin(datosJornada) {
        const feedbackEl = document.getElementById('rdt-admin-feedback');
        if (!feedbackEl) return;

        const urlAgenda  = datosJornada.url_agenda  || '';
        const centro     = datosJornada.centro      || '';
        const fecha      = datosJornada.fecha       || '';
        const horaInicio = datosJornada.hora_inicio || '';
        const horaFin    = datosJornada.hora_fin    || '';

        const fechaFmt = fecha
            ? new Date(fecha + 'T12:00:00').toLocaleDateString('es-AR', {
                weekday: 'long', year: 'numeric', month: 'long', day: 'numeric'
              })
            : '';

        feedbackEl.innerHTML = `
            <div class="rdt-admin-feedback__card">
                <div class="rdt-admin-feedback__icono">✓</div>
                <h3 class="rdt-admin-feedback__titulo">Jornada creada correctamente</h3>
                <p class="rdt-admin-feedback__detalle">
                    <strong>${escHtml(centro)}</strong><br>
                    ${escHtml(fechaFmt)} · ${escHtml(horaInicio)} a ${escHtml(horaFin)} hs
                </p>
                <p class="rdt-admin-feedback__nota">
                    El email de confirmación fue enviado al centro.<br>
                    Enlace de agenda para compartir con clientes:
                </p>
                ${urlAgenda ? `
                <div class="rdt-admin-feedback__url-wrap">
                    <a href="${escHtml(urlAgenda)}"
                       class="rdt-admin-feedback__url"
                       target="_blank" rel="noopener noreferrer">
                        ${escHtml(urlAgenda)}
                    </a>
                    <button type="button" class="rdt-admin-feedback__copiar" id="rdt-btn-copiar-url">
                        Copiar enlace
                    </button>
                </div>
                ` : ''}
                <a href="${escHtml(window.location.pathname)}" class="rdt-admin-btn rdt-admin-btn--secundario" style="text-decoration:none;display:block;text-align:center;margin-top:8px;">
                    ← Crear otra jornada
                </a>
            </div>
        `;

        feedbackEl.style.display = 'block';

        // Smooth scroll al card para que el admin lo vea
        feedbackEl.scrollIntoView({ behavior: 'smooth', block: 'start' });

        // Botón copiar enlace
        if (urlAgenda) {
            document.getElementById('rdt-btn-copiar-url')?.addEventListener('click', async () => {
                try {
                    await navigator.clipboard.writeText(urlAgenda);
                    document.getElementById('rdt-btn-copiar-url').textContent = '✓ Copiado';
                } catch {
                    document.getElementById('rdt-btn-copiar-url').textContent = 'Error al copiar';
                }
            });
        }
    }

    // ─── Popup de oferta de gel ───────────────────────────────────────────────

    /**
     * Muestra el popup de oferta.
     * Retorna Promise<boolean>: true si aceptó, false si rechazó o cerró.
     */
    function mostrarPopupOferta() {
        return new Promise((resolve) => {
            const popup = document.createElement('div');
            popup.id        = 'rdt-oferta-popup';
            popup.className = 'rdt-oferta-overlay';
            popup.setAttribute('role', 'dialog');
            popup.setAttribute('aria-modal', 'true');
            popup.setAttribute('aria-labelledby', 'rdt-oferta-titulo');

            popup.innerHTML = `
                <div class="rdt-oferta-dialog">
                    <h2 id="rdt-oferta-titulo" class="rdt-oferta-titulo">¡Oferta especial!</h2>
                    <p class="rdt-oferta-mensaje">
                        ¿Deseás agregar a tu reserva un pote de
                        <strong>${escHtml(oferta.product_name)}</strong>
                        por tan solo <strong>${oferta.product_price}</strong>?
                    </p>
                    <div class="rdt-oferta-acciones">
                        <button class="rdt-oferta-btn rdt-oferta-btn--rechazar" type="button">No, gracias</button>
                        <button class="rdt-oferta-btn rdt-oferta-btn--aceptar" type="button">Sí, lo quiero</button>
                    </div>
                    <p id="rdt-oferta-feedback" class="rdt-oferta-feedback" aria-live="polite"></p>
                </div>
            `;

            document.body.appendChild(popup);
            requestAnimationFrame(() => popup.classList.add('rdt-oferta-overlay--visible'));

            const btnAceptar  = popup.querySelector('.rdt-oferta-btn--aceptar');
            const btnRechazar = popup.querySelector('.rdt-oferta-btn--rechazar');

            function cerrar(solicitoGel) {
                popup.classList.remove('rdt-oferta-overlay--visible');
                setTimeout(() => { popup.remove(); resolve(solicitoGel); }, 220);
            }

            btnRechazar.addEventListener('click', () => cerrar(false));
            btnAceptar.addEventListener('click', () => {
                btnAceptar.disabled  = true;
                btnRechazar.disabled = true;
                const fb = popup.querySelector('#rdt-oferta-feedback');
                fb.textContent = '✓ ¡Listo! Se registrará en tu confirmación.';
                fb.className   = 'rdt-oferta-feedback rdt-oferta-feedback--exito';
                setTimeout(() => cerrar(true), 900);
            });

            function onKeyDown(e) {
                if (e.key === 'Escape') { document.removeEventListener('keydown', onKeyDown); cerrar(false); }
            }
            document.addEventListener('keydown', onKeyDown);
            popup.addEventListener('click', (e) => { if (e.target === popup) cerrar(false); });
            btnAceptar.focus();
        });
    }

    // ─── API calls ────────────────────────────────────────────────────────────

    /**
     * Obtiene los datos de la jornada recién creada desde el transient del servidor.
     */
    async function obtenerJornadaPendiente() {
        const res = await fetch(apiJornadaPendiente, {
            headers: { 'X-WP-Nonce': nonce },
        });
        if (!res.ok) throw new Error(`Error al obtener jornada (HTTP ${res.status})`);
        const data = await res.json();
        return data.jornada_id ? data : null;
    }

    /**
     * Cierra el flujo: marca solicito_gel en ACF y envía el email de confirmación.
     * Devuelve la respuesta del servidor con url_agenda, centro, fecha, etc.
     */
    async function confirmarJornada(jornadaDatos, solicitoGel) {
        const res = await fetch(apiJornadaConfirmar, {
            method:  'POST',
            headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
            body: JSON.stringify({
                jornada_id:   jornadaDatos.jornada_id,
                solicito_gel: solicitoGel,
                wp_user_id:   jornadaDatos.wp_user_id,
                fecha:        jornadaDatos.fecha,
                hora_inicio:  jornadaDatos.hora_inicio,
                hora_fin:     jornadaDatos.hora_fin,
                token:        jornadaDatos.token,
            }),
        });

        if (!res.ok) {
            const err = await res.json().catch(() => ({}));
            throw new Error(err.error || `HTTP ${res.status}`);
        }

        return res.json();
    }

    // ─── Orquestador principal ────────────────────────────────────────────────

    async function onReservaConfirmada() {
        //console.log('ssa-prefill: onReservaConfirmada - Inicio. oferta:', oferta, 'esAdmin:', esAdmin);

        let jornadaDatos = null;
        let solicitoGel  = false;

        try {
            jornadaDatos = await obtenerJornadaPendiente();
            //console.log('ssa-prefill: jornadaDatos obtenidos:', jornadaDatos);
        } catch (e) {
            console.error('ssa-prefill: error obteniendo jornada pendiente:', e);
        }

        // Mostrar popup solo si hay oferta Y hay datos de jornada
        // (el popup no tiene sentido si no tenemos el jornada_id para confirmar)
        if (oferta && jornadaDatos) {
            //console.log('ssa-prefill: Mostrando popup de oferta');
            solicitoGel = await mostrarPopupOferta();
            //console.log('ssa-prefill: Usuario decidió solicitoGel:', solicitoGel);
        } else {
            //console.log('ssa-prefill: No se muestra popup - oferta:', !!oferta, 'jornadaDatos:', !!jornadaDatos);
        }

        let respuestaConfirmar = null;
        if (jornadaDatos) {
            try {
                respuestaConfirmar = await confirmarJornada(jornadaDatos, solicitoGel);
                //console.log('ssa-prefill: Jornada confirmada:', respuestaConfirmar);
            } catch (e) {
                console.error('ssa-prefill: error al confirmar jornada:', e);
            }
        }

        // Feedback diferenciado según el tipo de usuario
        if (esAdmin) {
            //console.log('ssa-prefill: Mostrando card admin');
            mostrarCardAdmin(respuestaConfirmar || {});
        } else {
            //console.log('ssa-prefill: Mostrando toast centro');
            mostrarToastCompletado();
        }
    }

    // ─── Precarga del formulario SSA ──────────────────────────────────────────

    function precompletar(iframeDoc) {
        let rellenados = 0;
        Object.entries(datos).forEach(([name, valor]) => {
            if (!valor) return;
            const campo = iframeDoc.querySelector(`input[name="${name}"], textarea[name="${name}"]`);
            if (!campo || campo.value !== '') return;
            const proto  = campo.tagName === 'TEXTAREA'
                ? iframeDoc.defaultView.HTMLTextAreaElement.prototype
                : iframeDoc.defaultView.HTMLInputElement.prototype;
            const setter = Object.getOwnPropertyDescriptor(proto, 'value')?.set;
            setter ? setter.call(campo, valor) : (campo.value = valor);
            campo.dispatchEvent(new Event('input',  { bubbles: true }));
            campo.dispatchEvent(new Event('change', { bubbles: true }));
            rellenados++;
        });
        return rellenados;
    }

    function iniciarPolling(iframe) {
        const inicio = Date.now();
        const id = setInterval(() => {
            if (Date.now() - inicio > 600000) { clearInterval(id); return; }
            const doc = iframe.contentWindow?.document;
            if (!doc?.body) return;
            if (!doc.querySelector('input[name="name"]')) return;
            if (precompletar(doc) > 0) clearInterval(id);
        }, 300);
    }

    function iniciarPollingConfirmacion(iframe) {
        // Solo arrancamos si hay algo que hacer después de la confirmación
        if (!redirectUrl && !oferta && !esAdmin && !apiJornadaConfirmar) return;

        const inicio = Date.now();
        let yaConfirmado = false;
        const id = setInterval(() => {
            if (Date.now() - inicio > 600000) { clearInterval(id); return; }
            const doc = iframe.contentWindow?.document;
            if (!doc?.body) return;
            const pantalla = doc.querySelector('div.confirm.cust-info.current');
            if (!pantalla) return;
            if (!pantalla.textContent.toLowerCase().includes('gracias')) return;
            if (yaConfirmado) return;
            yaConfirmado = true;
            clearInterval(id);
            setTimeout(() => onReservaConfirmada(), 3000);
        }, 500);
    }

    function buscarIframe() {
        const iframe = document.querySelector('iframe.ssa_booking_iframe');
        if (iframe) {
            iniciarPolling(iframe);
            iniciarPollingConfirmacion(iframe);
            return;
        }
        const obs = new MutationObserver((_, o) => {
            const f = document.querySelector('iframe.ssa_booking_iframe');
            if (!f) return;
            o.disconnect();
            iniciarPolling(f);
            iniciarPollingConfirmacion(f);
        });
        obs.observe(document.body, { childList: true, subtree: true });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', buscarIframe);
    } else {
        buscarIframe();
    }

})();
