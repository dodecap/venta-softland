/*
 * Las reglas de un cobro, escritas una vez.
 *
 * Están fuera de la pantalla por lo mismo que `situacion()` está fuera del
 * panel: las usan dos sitios —el botón que se apaga y el cuerpo que se manda—
 * y dos copias de la misma regla son un botón encendido sobre un cobro que el
 * servidor va a rechazar.
 *
 * Aquí no se decide **nada de contabilidad**: ni cuentas, ni fechas de
 * emisión, ni el número del comprobante. Eso es del servidor. Lo de aquí es
 * aritmética de pantalla y el orden en que se dicen los motivos.
 */

/** La clave con la que un documento de la cartera se referencia en el formulario. */
export function clave(doc) {
    return `${doc?.tipo}-${doc?.numero}`;
}

export function saldoDe(doc) {
    return Number(doc?.saldo) || 0;
}

/** Lo que se está abonando en total. */
export function totalAbonado(abonos) {
    return Object.values(abonos || {}).reduce((s, n) => s + (Number(n) || 0), 0);
}

/**
 * Lo que se le está abonando de más a un documento.
 *
 * No es un error por sí solo: Softland concede «Permite pagar más del saldo» y
 * hay empresas que lo usan. Lo que no puede es pasar callado.
 */
export function exceso(doc, abonos) {
    const puesto = (abonos || {})[clave(doc)];

    return puesto === undefined ? 0 : Math.max(0, (Number(puesto) || 0) - saldoDe(doc));
}

/** El cuerpo que se manda: sólo los documentos elegidos y con importe. */
export function aplicaciones(documentos, abonos) {
    return (documentos || [])
        .filter((d) => (abonos || {})[clave(d)] !== undefined)
        .map((d) => ({ tipo: d.tipo, numero: Number(d.numero), monto: Number(abonos[clave(d)]) || 0 }))
        .filter((a) => a.monto > 0);
}

/**
 * Por qué no se puede cobrar, en una frase. Vacío = se puede.
 *
 * **El orden importa**: primero lo que no depende de quien mira —no hay señal,
 * el servidor no contestó, la empresa no tiene cuentas configuradas—, después
 * lo que le falta al usuario, y al final lo que le falta al formulario.
 * Decirle «elige un documento» a quien no tiene permiso para cobrar es
 * hacerle rellenar algo que no va a poder mandar.
 *
 * Y **lo que no se ha podido preguntar no se diagnostica**. Si la propuesta no
 * llegó, no hay medios de pago ni permisos que enseñar, y decir entonces «la
 * empresa no tiene formas de pago configuradas» o «Softland no te deja» es
 * inventarse un motivo: lo que pasó es que no hubo respuesta, y eso es lo que
 * se dice.
 */
export function impedimento(e) {
    if (! e.conectado) return 'Sin señal no se puede cobrar: el número del comprobante lo pone el servidor.';
    if (! e.consultado) return 'El servidor no ha dicho todavía qué se puede cobrar.';
    if (e.problemas?.length) return e.problemas[0];
    if (! e.permisos?.cobrar) return 'Softland no te deja ingresar comprobantes vigentes. Lo cambia el administrador del ERP.';
    if (! e.medios?.length) return 'La empresa no tiene ninguna forma de pago con cuenta contable configurada.';
    if (! e.medio) return 'Elige con qué se paga.';
    if (! (e.total > 0)) return 'Elige al menos un documento.';
    if (e.excede && ! e.permisos?.sobre_saldo) {
        return 'Hay un documento con más abono que saldo, y Softland no te concede «Permite pagar más del saldo».';
    }
    if (! e.fecha) return 'Falta la fecha del pago.';

    return '';
}
