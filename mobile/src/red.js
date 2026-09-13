import { ref } from 'vue';
import { Network } from '@capacitor/network';

/**
 * Si el teléfono tiene red. Uno solo para toda la app: lo mira la franja de
 * aviso de `App.vue` y el panel de control, y no tiene sentido que cada una
 * abra su propio oyente y respondan cosas distintas.
 */
export const conectado = ref(true);

Network.getStatus()
    .then((s) => { conectado.value = s.connected; })
    .catch(() => { conectado.value = true; });  // en el navegador se asume con red

Network.addListener('networkStatusChange', (s) => { conectado.value = s.connected; })
    .catch(() => {});
