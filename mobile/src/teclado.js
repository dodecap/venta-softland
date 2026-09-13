import { ref } from 'vue';
import { Capacitor } from '@capacitor/core';

/**
 * Si el teclado del teléfono está a la vista.
 *
 * Importa para todo lo que flota al pie: con el teclado abierto, un botón fijo
 * abajo queda montado sobre las teclas o tapando el campo que se está
 * escribiendo. Lo que flota se esconde mientras se escribe y vuelve al cerrar.
 */
export const tecladoAbierto = ref(false);

if (Capacitor.isNativePlatform()) {
    // Import diferido: en el navegador el plugin nativo no tiene nada que hacer.
    import('@capacitor/keyboard').then(({ Keyboard }) => {
        // `will` y no `did`: el botón se va antes de que suban las teclas, sin
        // el parpadeo de verlo saltar por encima del teclado.
        Keyboard.addListener('keyboardWillShow', () => { tecladoAbierto.value = true; });
        Keyboard.addListener('keyboardWillHide', () => { tecladoAbierto.value = false; });
    }).catch(() => {});
} else if (window.visualViewport) {
    // En `npm run dev` no hay plugin: se deduce del alto que queda visible.
    const vv = window.visualViewport;
    const revisar = () => { tecladoAbierto.value = vv.height < window.innerHeight * 0.75; };
    vv.addEventListener('resize', revisar);
}
