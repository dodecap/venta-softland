/**
 * De quién es un documento — la regla, escrita una sola vez.
 *
 * El panel la usaba en cuatro sitios con cuatro copias idénticas —las tres
 * métricas y el resumen de compromisos—, y la lista de documentos no la usaba
 * en ninguno: por eso el panel podía decir «5 sin próximo paso» y la lista que
 * abría enseñar 89. Medido en INNOVAGES: de 90 cotizaciones abiertas, 85 son de
 * un vendedor y 5 de otra.
 *
 * `null` es «todos», no «ninguno». Es el ámbito empresa o equipo: enseña todo
 * lo que el servidor dejó bajar a este teléfono, que **ya viene acotado por el
 * alcance del usuario**. Filtrar aquí acota dentro de eso; no abre nada que el
 * servidor no haya dado.
 *
 * Sin archivo propio esto tendría que vivir en `panel/metricas.js`, que es puro
 * a propósito —no lee de IndexedDB, y por eso se puede probar sin navegador—, o
 * en `documentos.js`, que sí lee. Ni lo uno ni lo otro: es una regla de una
 * línea que usan los dos lados.
 *
 * @param {string[]|null} vendedores  códigos de `cwtvend`, o `null` para todos
 * @returns {(fila: {vendedor?: string}) => boolean}
 */
export function deVendedores(vendedores) {
    if (! vendedores) return () => true;

    const suyos = vendedores.map((v) => String(v ?? '').trim());

    return (fila) => suyos.includes(String(fila?.vendedor ?? '').trim());
}
