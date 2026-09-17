import { idb } from './idb';
import { fecha } from './catalogos';
import { estado } from './documentos';
import { facturasEmitidas } from './saldo';

/**
 * Los documentos que cuelgan de otro, para poder saltar entre ellos.
 *
 * ## Por qué está aquí y no en cada ficha
 *
 * Porque «de qué viene» y «en qué terminó» es **una sola pregunta hecha desde
 * tres sitios**: la cotización quiere saber qué notas de venta salieron de
 * ella, la nota de venta de qué cotización viene y qué se facturó, y la factura
 * de qué venta sale y quién la anuló. Si cada ficha la contestara por su cuenta
 * habría tres reglas distintas y un día dirían cosas distintas de lo mismo.
 *
 * ## Los dos enlaces, y sólo uno es nuestro
 *
 * Cotización a nota de venta lo guarda `nw_nventa.CotNum`, que es de Softland;
 * nota de venta a factura, `iw_gsaen.nvnumero`, que también. El nuestro —el de
 * línea a línea, en `ventas.linea_origen`— sirve para el saldo, no para
 * navegar: aquí basta con saber que existe el documento.
 *
 * ## Y por qué se lee todo del teléfono
 *
 * Porque esto se mira en terreno. Una ficha que sólo enseña sus relaciones con
 * señal es una ficha que no las enseña cuando hacen falta.
 *
 * Todas las funciones devuelven filas iguales:
 * `{ clave, icono, rotulo, detalle, ruta, color }`.
 */

/** Tipo de Softland a tipo del SII, que es como se referencian entre ellos. */
const SII = { F: '33', B: '39', N: '61' };

const ROTULO = { F: 'Factura', B: 'Boleta', N: 'Nota de crédito' };

/**
 * Las notas de venta que nacieron de una cotización.
 *
 * Pueden ser varias: desde el reparto parcial, una cotización de ocho líneas
 * puede haberse llevado siete a una nota de venta y tener la octava esperando.
 * Las anuladas se enseñan igual, en gris: que una venta se anulara es parte de
 * lo que le pasó a esta cotización, y esconderla deja la historia con un hueco.
 */
export async function notasDeCotizacion(numero) {
    const notas = await idb.porIndice('notas_venta', 'cotizacion', Number(numero));

    return (notas || [])
        .sort((a, b) => b.numero - a.numero)
        .map((n) => {
            const e = estado('nota_venta', n.estado);

            return {
                clave: `nv-${n.numero}`,
                icono: 'notaVenta',
                rotulo: `Nota de venta Nº ${n.numero}`,
                detalle: [fecha(n.fecha), e.rotulo].filter(Boolean).join(' · '),
                ruta: `/notas-venta/${n.numero}`,
                color: e.color,
            };
        });
}

/**
 * La cotización de la que viene una nota de venta, si viene de alguna.
 *
 * Puede no estar en el teléfono y el enlace se ofrece igual: la ventana de doce
 * meses es equipaje, y la ficha sabe traer del servidor lo que no tiene. Decir
 * «no está» y no dejar entrar sería esconder algo que sí se puede abrir.
 */
export async function cotizacionDe(nota) {
    const numero = Number(nota?.cotizacion || 0);

    if (! numero) return [];

    const cot = await idb.obtener('cotizaciones', numero);
    const e = cot ? estado('cotizacion', cot.estado) : null;

    return [{
        clave: `cot-${numero}`,
        icono: 'cotizacion',
        rotulo: `Cotización Nº ${numero}`,
        detalle: cot
            ? [fecha(cot.fecha), e.rotulo].filter(Boolean).join(' · ')
            : 'No está descargada — se trae al abrirla',
        ruta: `/cotizaciones/${numero}`,
        color: e?.color || 'gris',
    }];
}

/**
 * De dónde viene y en qué terminó un documento emitido.
 *
 * Tres saltos posibles, y ninguno obligatorio: la nota de venta que se facturó
 * —una factura puede no tenerla—, la nota de crédito que la anuló, y, si el que
 * se mira **es** la nota de crédito, la factura que devuelve.
 *
 * El folio no basta para abrir un documento: la clave es el par tipo y número
 * interno, así que se resuelve contra el almacén en vez de armar la ruta con lo
 * que se tiene a mano.
 */
export async function relacionesDeFactura(doc) {
    if (! doc) return [];

    const emitidos = await facturasEmitidas();
    const filas = [];

    if (Number(doc.nota_venta || 0) > 0) {
        const nv = await idb.obtener('notas_venta', Number(doc.nota_venta));
        const e = nv ? estado('nota_venta', nv.estado) : null;

        filas.push({
            clave: `nv-${doc.nota_venta}`,
            icono: 'notaVenta',
            rotulo: `Nota de venta Nº ${doc.nota_venta}`,
            detalle: nv
                ? [fecha(nv.fecha), e.rotulo].filter(Boolean).join(' · ')
                : 'No está descargada — se trae al abrirla',
            ruta: `/notas-venta/${doc.nota_venta}`,
            color: e?.color || 'gris',
        });

        // Y la cotización de esa nota de venta: el vendedor que mira una factura
        // se pregunta por el trato entero, no por el eslabón anterior.
        if (nv) filas.push(...(await cotizacionDe(nv)));
    }

    const enlazar = (folio, tipoSii, rotulo, color) => {
        const otro = (emitidos || []).find(
            (d) => SII[d.tipo] === String(tipoSii) && Number(d.folio) === Number(folio)
        );

        if (! otro) return;

        filas.push({
            clave: `${otro.tipo}-${otro.numero_interno}`,
            icono: otro.tipo === 'N' ? 'notaCredito' : 'factura',
            rotulo: `${rotulo} Nº ${otro.folio}`,
            detalle: fecha(otro.fecha),
            ruta: `/facturas/${otro.tipo}/${otro.numero_interno}`,
            color,
        });
    };

    // Quién la anuló, si alguien lo hizo. Va en gris: es el final de la
    // historia de este documento, no una buena noticia.
    if (doc.acreditada) enlazar(doc.acreditada, SII.N, ROTULO.N, 'gris');

    // Y al revés, mirando una nota de crédito: qué factura devuelve.
    if (doc.anula_a) enlazar(doc.anula_a, SII.F, ROTULO.F, 'gris');

    return filas;
}
