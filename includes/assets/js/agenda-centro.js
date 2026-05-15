document.addEventListener('DOMContentLoaded', () => {

    const tratamientoSelect = document.getElementById('rdt-tratamiento');
    const horariosSelect    = document.getElementById('rdt-horarios');
    const mensaje           = document.getElementById('rdt-mensaje');

    if (!tratamientoSelect || !horariosSelect) return;

    tratamientoSelect.addEventListener('change', async () => {

        horariosSelect.innerHTML = '';
        mensaje.textContent = '';

        const tratamientoId = tratamientoSelect.value;
        if (!tratamientoId) return;

        const params = new URLSearchParams({
            centro: RDTAgenda.centroId,
            fecha: RDTAgenda.fecha,
            tratamiento: tratamientoId
        });

        try {
            const res  = await fetch(`${RDTAgenda.apiUrl}?${params.toString()}`);
            const data = await res.json();

            if (!res.ok || data.error) {
                mensaje.textContent = data.error || 'No hay horarios.';
                return;
            }

            if (!Array.isArray(data.horarios)) {
                mensaje.textContent = 'Respuesta inválida.';
                return;
            }

            data.horarios.forEach(hora => {
                const opt = document.createElement('option');
                opt.value = hora;
                opt.textContent = hora;
                horariosSelect.appendChild(opt);
            });

        } catch (e) {
            console.error(e);
            mensaje.textContent = 'Error de conexión.';
        }
    });
});
