document.addEventListener('DOMContentLoaded', () => {

    const contenedor     = document.getElementById('rdt-agenda-publica');
    const servicioSelect = document.getElementById('rdt-servicio');
    const horariosSelect = document.getElementById('rdt-horarios');
    const formTurno      = document.getElementById('rdt-form-turno');
    const mensaje        = document.getElementById('rdt-mensaje');
    const horaInput      = document.getElementById('rdt-hora');
    const codAreaInput   = document.getElementById('rdt-cod-area');
    const numeroInput    = document.getElementById('rdt-numero');
    const preview        = document.getElementById('rdt-telefono-preview');

    if (!servicioSelect || !horariosSelect) return;

    // ─── Prefijo internacional fijo para Argentina ────────────────────────────
    // WhatsApp requiere: código país (54) + 9 + código de área + número
    // El 9 es obligatorio para números celulares en Argentina en wa.me
    const PREFIJO_INTERNACIONAL = '549';

    // ─── Preview del número completo mientras el usuario tipea ───────────────
    // Muestra el número armado en tiempo real para que la clienta pueda
    // verificar que el formato es correcto antes de enviar.
    function actualizarPreview() {
        const cod    = codAreaInput.value.replace(/\D/g, '');
        const num    = numeroInput.value.replace(/\D/g, '');
        const cuenta = (cod + num).length;

        if (!cod && !num) {
            preview.textContent = '';
            preview.className   = 'rdt-telefono-preview';
            return;
        }

        const numero_armado = `+${PREFIJO_INTERNACIONAL} ${cod} ${num}`;

        // Validación visual: Argentina tiene 10 dígitos en total (cod + número)
        const esValido = cod.length >= 2 && num.length >= 6 && cuenta <= 10;

        preview.textContent = `Tu número de WhatsApp: ${numero_armado}`;
        preview.className   = esValido
            ? 'rdt-telefono-preview rdt-telefono-preview--valido'
            : 'rdt-telefono-preview rdt-telefono-preview--incompleto';
    }

    // Permitir solo dígitos en los campos de teléfono
    [codAreaInput, numeroInput].forEach(input => {
        input.addEventListener('input', (e) => {
            e.target.value = e.target.value.replace(/\D/g, '');
            actualizarPreview();
        });
    });

    // ─── Cambio de servicio ───────────────────────────────────────────────────
    servicioSelect.addEventListener('change', async () => {
        formTurno.style.display  = 'none';
        horariosSelect.disabled  = false;
        horariosSelect.innerHTML = '<option value="">Seleccioná un horario</option>';
        mensaje.textContent      = '';
        mensaje.className        = '';
        ocultarToast();

        const servicioId = servicioSelect.value;
        if (!servicioId) {
            horariosSelect.disabled = true;
            return;
        }

        try {
            await cargarHorarios(servicioId);
        } catch (e) {
            console.error(e);
            mostrarMensaje('Error al cargar los horarios disponibles.', 'error');
        }
    });

    // ─── Selección de horario ─────────────────────────────────────────────────
    horariosSelect.addEventListener('change', () => {
        horaInput.value = horariosSelect.value;
    });

    // ─── Envío del turno ──────────────────────────────────────────────────────
    formTurno.addEventListener('submit', async (e) => {
        e.preventDefault();
        mensaje.textContent = '';

        const codArea = codAreaInput.value.replace(/\D/g, '');
        const numero  = numeroInput.value.replace(/\D/g, '');

        // Validación del teléfono antes de enviar
        if (codArea.length < 2 || numero.length < 6) {
            mostrarMensaje(
                'Ingresá el código de área (sin el 0) y tu número (sin el 15).',
                'error'
            );
            return;
        }

        // Armamos el número en formato internacional para wa.me
        // Formato: 549 + código de área + número (sin espacios ni símbolos)
        const telefono_completo = PREFIJO_INTERNACIONAL + codArea + numero;

        const submitBtn = formTurno.querySelector('button[type="submit"]');
        submitBtn.disabled    = true;
        submitBtn.textContent = 'Confirmando...';

        const payload = {
            token:            RDTAgenda.token,
            servicio_id:      parseInt(servicioSelect.value),
            hora_inicio:      horaInput.value,
            nombre_cliente:   document.getElementById('rdt-nombre').value.trim(),
            email_cliente:    document.getElementById('rdt-email').value.trim(),
            telefono_cliente: telefono_completo,
        };

        try {
            const res  = await fetch(RDTAgenda.apiUrlTurno, {
                method:  'POST',
                headers: { 'Content-Type': 'application/json' },
                body:    JSON.stringify(payload),
            });

            const data = await res.json();

            if (!res.ok || data.error) {
                mostrarMensaje(data.error || 'Error al confirmar el turno.', 'error');
                submitBtn.disabled    = false;
                submitBtn.textContent = 'Confirmar turno';
                return;
            }

            inhabilitarFormulario();
            mostrarToast();

        } catch (e) {
            console.error(e);
            mostrarMensaje('Error al confirmar el turno.', 'error');
            submitBtn.disabled    = false;
            submitBtn.textContent = 'Confirmar turno';
        }
    });

    // ─── Toast de éxito ───────────────────────────────────────────────────────

    function mostrarToast() {
        ocultarToast();

        const toast = document.createElement('div');
        toast.id        = 'rdt-toast-exito';
        toast.className = 'rdt-toast';
        toast.innerHTML = `
            <p class="rdt-toast__mensaje">
                ✓ Turno reservado. Recibirás un correo con las instrucciones.
            </p>
            <div class="rdt-toast__acciones">
                <button type="button" id="rdt-toast-otro" class="rdt-toast__btn rdt-toast__btn--otro">Reservar otro turno</button>
            </div>
        `;

        mensaje.insertAdjacentElement('afterend', toast);
        requestAnimationFrame(() => toast.classList.add('rdt-toast--visible'));

        document.getElementById('rdt-toast-otro').addEventListener('click', () => {
            window.location.reload();
        });
    }

    function ocultarToast() {
        const existing = document.getElementById('rdt-toast-exito');
        if (existing) existing.remove();
    }

    // ─── Inhabilitar formulario tras reserva exitosa ──────────────────────────

    function inhabilitarFormulario() {
        servicioSelect.disabled = true;
        horariosSelect.disabled = true;

        const submitBtn = formTurno.querySelector('button[type="submit"]');
        submitBtn.style.display = 'none';

        formTurno.querySelectorAll('input, button').forEach(el => {
            el.disabled = true;
        });
    }

    // ─── Helpers de mensaje ───────────────────────────────────────────────────

    function mostrarMensaje(texto, tipo) {
        mensaje.textContent = texto;
        mensaje.className   = tipo === 'error' ? 'rdt-mensaje--error' : 'rdt-mensaje--exito';
    }

    // ─── Carga de horarios disponibles ───────────────────────────────────────

    async function cargarHorarios(tratamientoId) {
        horariosSelect.innerHTML = '<option value="">Cargando horarios...</option>';
        horariosSelect.disabled  = true;

        const params = new URLSearchParams({
            token:   RDTAgenda.token,
            servicio: tratamientoId,
        });

        const res  = await fetch(`${RDTAgenda.apiUrlHorarios}?${params.toString()}`);
        const data = await res.json();

        horariosSelect.innerHTML = '<option value="">Seleccioná un horario</option>';

        if (!res.ok || data.error) {
            mostrarMensaje(data.error || 'No hay horarios disponibles.', 'error');
            horariosSelect.disabled = true;
            formTurno.style.display = 'none';
            return;
        }

        if (!Array.isArray(data.horarios) || data.horarios.length === 0) {
            mostrarMensaje('No hay horarios disponibles para este servicio.', 'error');
            horariosSelect.disabled = true;
            formTurno.style.display = 'none';
            return;
        }

        data.horarios.forEach(hora => {
            const opt       = document.createElement('option');
            opt.value       = hora;
            opt.textContent = hora;
            horariosSelect.appendChild(opt);
        });

        horariosSelect.disabled = false;
        formTurno.style.display = 'block';

        formTurno.reset();
        preview.textContent = '';
        preview.className   = 'rdt-telefono-preview';
        formTurno.querySelector('button[type="submit"]').disabled    = false;
        formTurno.querySelector('button[type="submit"]').textContent = 'Confirmar turno';
    }
});
