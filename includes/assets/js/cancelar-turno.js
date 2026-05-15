document.addEventListener('DOMContentLoaded', () => {
    const msg = document.getElementById('rdt-msg');
    if (!msg){
        console.log('cancelar-turno script: No se encontro el elemento #rdt-msg');
        return;
    } 
    const cancelBtn = document.getElementById('rdt-cancelar');
    if (!cancelBtn){
        console.log('cancelar-turno script: No se encontro el elemento #rdt-cancelar');
        return;
    }

    cancelBtn.addEventListener('click', async () => {
        const msg = document.getElementById('rdt-msg');

    try {
        const res = await fetch(RDTTurno.apiUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                token_turno: RDTTurno.token
            })
        });

        const data = await res.json();

        if (!res.ok) {
            msg.textContent = data.error || 'No se pudo cancelar.';
            msg.classList.add('rdt-ct-msg--error');
            return;
        }

        msg.classList.remove('rdt-ct-msg--error');
        msg.textContent = 'Tu turno fue cancelado correctamente.';
        cancelBtn.disabled = true;
    } catch (e) {
        msg.textContent = 'Error de conexión.';
    }

   }); //getElementById('rdt-cancelar')
}); //DOMContentLoaded
