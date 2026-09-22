/**
 * Comparar dos versiones del proyecto.
 *
 * La versión vive en un solo archivo, `VERSION`, y de ahí la leen la SPA, el
 * APK y la API. Aquí sólo se comparan, y hace falta compararlas de verdad
 * —número a número— y no como texto: `'0.9.0' < '0.41.0'` es cierto en
 * castellano y mentira en JavaScript, donde `'4'` va antes que `'9'`.
 *
 * Que el servidor tenga una versión distinta y que tenga una **más nueva** son
 * dos cosas distintas, y la pantalla Cuenta dice cosas distintas de cada una:
 * lo primero avisa de un desfase —que también pasa con un APK nuevo contra una
 * API vieja, y entonces lo que hay que hacer es desplegar—, y lo segundo se
 * puede resolver ahí mismo bajando el instalable.
 */

/** −1, 0 o 1. Lo que no parezca una versión se trata como la más vieja. */
export function comparar(a, b) {
    const t = (v) => String(v ?? '').trim().split('.').map((n) => parseInt(n, 10) || 0);
    const x = t(a);
    const y = t(b);

    for (let i = 0; i < Math.max(x.length, y.length, 3); i++) {
        const d = (x[i] || 0) - (y[i] || 0);
        if (d) return d > 0 ? 1 : -1;
    }
    return 0;
}

/** Si `candidata` es posterior a `actual`. Sin las dos, no se sabe y no se ofrece. */
export function esMasNueva(candidata, actual) {
    if (! candidata || ! actual) return false;
    return comparar(candidata, actual) > 0;
}

/**
 * Si el punto rojo de la campana tiene que encenderse por una versión nueva.
 *
 * Dos condiciones, y las dos hacen falta. Que la publicada sea **posterior** a
 * la de este teléfono —distinta no basta: un APK nuevo contra una API vieja
 * también desfasa, y ahí lo que falta es desplegar—, y que no sea la misma de
 * la que ya se avisó. Sin lo segundo el buzón se quedaría con un punto rojo
 * que no se apaga nunca; y como se guarda **la versión** y no un «ya lo vi»,
 * la siguiente vuelve a encenderlo sola.
 */
export function avisaDeVersion(publicada, actual, avisada) {
    return esMasNueva(publicada, actual) && publicada !== avisada;
}
