/**
 * woo-account.js
 * Scripts de Mi Cuenta para centros estéticos.
 *
 * Con el nuevo flujo que respeta el patrón de WooCommerce:
 *  - /my-account          → solo lectura, sin JS necesario
 *  - /my-account/edit-account → formulario nativo de WooCommerce
 *                               + campos del centro procesados por PHP/WooCommerce
 *
 * Este archivo queda disponible para mejoras futuras de UX
 * (validaciones inline, feedback visual, etc.)
 */

document.addEventListener('DOMContentLoaded', () => {

    // Resaltar la sección de datos del centro al cargar edit-account
    const seccionCentro = document.querySelector('.rdt-edicion-centro');
    if (seccionCentro) {
        // Scroll suave hacia la sección si viene con hash #datos-centro
        if (window.location.hash === '#datos-centro') {
            seccionCentro.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    }
});
