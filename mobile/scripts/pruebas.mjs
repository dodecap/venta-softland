/*
 * Pruebas de lo que se puede probar sin navegador: el formato del dinero y los
 * indicadores del panel. Se corren con `npm run pruebas`, sin dependencias.
 */
import assert from 'node:assert/strict';
import {
    dinero, dineroExacto, leyenda, variacion, puntos, porcentaje, dias, diferenciaDias, hayDato, SIN_DATO,
} from '../src/dinero.js';
import { rango, anterior, largoEnDias, dia } from '../src/panel/periodo.js';
import { calcular, pendientes, situacion, ultimos } from '../src/panel/metricas.js';
import { calcularSaldo } from '../src/saldo.js';
import { estado as estadoCompromiso, cuando, hora, sumarDias } from '../src/seguimiento.js';

let hechas = 0;
const es = (a, b, que) => { assert.equal(a, b, `${que}: esperaba «${b}» y salió «${a}»`); hechas++; };

// ---- dinero: los ejemplos exactos del encargo
es(dinero(840), '$840', 'menos de mil');
es(dinero(84500), '$84.500', 'decenas de miles, entero');
es(dinero(850000), '$850 mil', 'cientos de miles');
es(dinero(1500000), '$1,5 MM', 'un millón y medio');
es(dinero(12800000), '$12,8 MM', 'decenas de millones');
es(dinero(127400000), '$127,4 MM', 'cientos de millones');
es(dinero(1250000000), '$1.250 MM', 'miles de millones, sin decimal');

// ---- bordes
es(dinero(0), '$0', 'cero es un dato');
es(dinero(99999), '$99.999', 'justo antes del corte');
es(dinero(100000), '$100 mil', 'justo en el corte');
es(dinero(999499), '$999 mil', 'el último que es miles');
es(dinero(999999), '$1 MM', 'redondea a un millón, no a «$1.000 mil»');
es(dinero(1000000), '$1 MM', 'millón redondo, sin «,0»');
es(dinero(-1755212), '-$1,8 MM', 'nota de crédito');
es(dinero(null), SIN_DATO, 'sin dato');
es(dinero(undefined), SIN_DATO, 'sin dato');
es(dinero(''), SIN_DATO, 'vacío');
es(dinero(NaN), SIN_DATO, 'NaN');
es(dinero('44098540'), '$44,1 MM', 'texto numérico, como llega de SQL Server');

// ---- exacto
es(dineroExacto(127412880), '$127.412.880', 'exacto');
es(dineroExacto(-1755212), '-$1.755.212', 'exacto negativo');
es(dineroExacto(null), SIN_DATO, 'exacto sin dato');

// ---- leyenda
es(leyenda([44098540, 11708468]), 'MM = millones de pesos', 'hay millones');
es(leyenda([840000, 120]), 'mil = miles de pesos', 'hay miles');
es(leyenda([840, 0]), '', 'no hace falta leyenda');
es(leyenda([null, undefined]), '', 'sin datos no hay leyenda');

// ---- variación
es(variacion(114, 100).texto, '14,0%', 'sube');
es(variacion(114, 100).direccion, 'sube', 'dirección arriba');
es(variacion(88, 100).texto, '12,0%', 'baja');
es(variacion(88, 100).direccion, 'baja', 'dirección abajo');
es(variacion(100, 100).direccion, 'igual', 'sin cambio');
es(variacion(10, 0), null, 'división por cero: no se dibuja');
es(variacion(10, null), null, 'sin período anterior');
es(variacion(-5, 10).texto, '150,0%', 'cae por debajo de cero');

// ---- puntos porcentuales
es(puntos(65, 60.9).texto, '4,1 pp', 'pp, no %');
es(puntos(65, 60.9).direccion, 'sube', 'pp hacia arriba');
es(puntos(60, 65).texto, '5,0 pp', 'pp a la baja');
es(puntos(65, null), null, 'sin comparación');

// ---- porcentaje y días
es(porcentaje(26.5), '26,5%', 'porcentaje con decimal');
es(porcentaje(65), '65%', 'porcentaje redondo sin decimal');
es(porcentaje(null), SIN_DATO, 'porcentaje sin dato');
es(dias(3.7), '3,7 d', 'días con decimal');
es(dias(2), '2 d', 'días redondos');
es(dias(null), SIN_DATO, 'días sin dato');

// ---- hayDato
es(hayDato(0), true, 'cero sí es dato');
es(hayDato(null), false, 'null no');
es(hayDato('abc'), false, 'texto no numérico no');


// ============================================================ período

// Un lunes: 2026-09-14. Se fija la referencia para que la prueba no dependa
// del día en que se corra.
const LUN = new Date(2026, 8, 14);
const DOM = new Date(2026, 8, 20);

es(rango('hoy', LUN).desde, '2026-09-14', 'hoy empieza hoy');
es(rango('hoy', LUN).hasta, '2026-09-14', 'hoy termina hoy');
es(rango('semana', LUN).desde, '2026-09-14', 'la semana empieza el lunes');
es(rango('semana', LUN).hasta, '2026-09-20', 'y termina el domingo');
es(rango('semana', DOM).desde, '2026-09-14', 'el domingo pertenece a su semana, no a la siguiente');
es(rango('mes', LUN).desde, '2026-09-01', 'el mes empieza el día 1');
es(rango('mes', LUN).hasta, '2026-09-30', 'septiembre tiene 30');
es(rango('mes', new Date(2024, 1, 5)).hasta, '2024-02-29', 'febrero bisiesto');
es(rango('trimestre', LUN).desde, '2026-07-01', 'trimestre 3 empieza en julio');
es(rango('trimestre', LUN).hasta, '2026-09-30', 'y termina en septiembre');
es(rango('trimestre', LUN).etiqueta, 'Trim. 3 · 2026', 'etiqueta del trimestre');

// El rótulo de «hoy» y de «esta semana» es relativo al día de verdad, no a la
// fecha de referencia: la comparación se arma corriendo esa fecha, y el panel
// llegó a decir «vs. hoy» al comparar contra ayer.
const HOY = new Date();
const AYER = new Date(Date.now() - 86400000);
es(rango('hoy', HOY).etiqueta, 'Hoy', 'hoy es hoy');
es(rango('hoy', AYER).etiqueta, 'Ayer', 'y ayer es ayer, no «hoy»');
es(rango('hoy', new Date(2026, 6, 13)).etiqueta, '13 de julio', 'un día cualquiera lleva su fecha');
es(anterior(rango('hoy', HOY), HOY).etiqueta, 'Ayer', 'el período anterior a hoy es ayer');
es(rango('semana', HOY).etiqueta, 'Esta semana', 'la semana en curso');
es(anterior(rango('semana', HOY), HOY).etiqueta.startsWith('Semana del'), true,
   'la anterior lleva su fecha, no «esta semana»');
es(rango('ano', LUN).desde, '2026-01-01', 'el año empieza el 1 de enero');
es(rango('ano', LUN).hasta, '2026-12-31', 'y termina el 31 de diciembre');
es(rango('lo-que-sea', LUN).id, 'mes', 'lo desconocido cae en mes');

es(anterior(rango('mes', LUN), LUN).desde, '2026-08-01', 'mes anterior completo');
es(anterior(rango('mes', LUN), LUN).hasta, '2026-08-31', 'agosto entero, no 30 días atrás');
es(anterior(rango('mes', new Date(2026, 0, 15)), new Date(2026, 0, 15)).desde, '2025-12-01', 'enero mira a diciembre del año pasado');
es(anterior(rango('trimestre', LUN), LUN).desde, '2026-04-01', 'trimestre anterior');
es(anterior(rango('ano', LUN), LUN).desde, '2025-01-01', 'año anterior');
es(anterior(rango('semana', LUN), LUN).desde, '2026-09-07', 'semana anterior');
es(anterior(rango('hoy', LUN), LUN).desde, '2026-09-13', 'ayer');

es(largoEnDias(rango('mes', LUN)), 30, 'septiembre son 30 días');
es(largoEnDias(rango('ano', LUN)), 365, '2026 no es bisiesto');
es(dia(new Date(2026, 0, 5)), '2026-01-05', 'fecha local, sin pasar por UTC');

// ============================================================ métricas

const R = rango('mes', LUN);

const COT = [
    { numero: 1, vendedor: '2', estado: 'P', fecha: '2026-09-02T00:00:00', neto: 800000, exento: 200000 },
    { numero: 2, vendedor: '2', estado: 'V', fecha: '2026-09-05T00:00:00', neto: 1600000, exento: 400000 },
    { numero: 3, vendedor: '2', estado: 'R', fecha: '2026-09-08T00:00:00', neto: 500000, exento: 0 },
    { numero: 4, vendedor: '2', estado: 'N', fecha: '2026-09-09T00:00:00', neto: 9999999, exento: 0 },
    { numero: 5, vendedor: '19', estado: 'V', fecha: '2026-09-10T00:00:00', neto: 3200000, exento: 800000 },
    { numero: 6, vendedor: '2', estado: 'P', fecha: '2026-08-20T00:00:00', neto: 5600000, exento: 1400000 },
    { numero: 7, vendedor: '2', estado: 'A', fecha: '2026-09-11T00:00:00', neto: 100000, exento: 0 },
];
const NV = [
    { numero: 900, cotizacion: 2, vendedor: '2', estado: 'A', fecha: '2026-09-09T00:00:00', neto: 1600000, exento: 400000 },
    { numero: 901, cotizacion: 5, vendedor: '19', estado: 'A', fecha: '2026-09-12T00:00:00', neto: 3200000, exento: 800000 },
    { numero: 902, cotizacion: 0, vendedor: '2', estado: 'N', fecha: '2026-09-12T00:00:00', neto: 8888888, exento: 0 },
    { numero: 903, cotizacion: 0, vendedor: '2', estado: 'A', fecha: '2026-08-03T00:00:00', neto: 2400000, exento: 600000 },
    // Escrita y sin aprobar: no es venta. Y su cotización sí convirtió.
    { numero: 904, cotizacion: 1, vendedor: '2', estado: 'P', fecha: '2026-09-13T00:00:00', neto: 700000, exento: 300000 },
    // Concluida: terminó su vida, despachada y facturada. Venta de las buenas.
    { numero: 905, cotizacion: 0, vendedor: '2', estado: 'C', fecha: '2026-09-06T00:00:00', neto: 2400000, exento: 600000 },
];

// El panel suma neto + exento y NO `total`, que lleva IVA. Estas dos filas
// traen un `total` disparatado a propósito: si alguien vuelve a sumarlo, las
// comprobaciones de abajo se caen.
COT[0].total = 999999999;
NV[0].total = 999999999;
NV[4].total = 999999999;

const todos = calcular({ cotizaciones: COT, notas: NV, rango: R });
es(todos.cotizado.n, 5, 'cotizado del mes: sin la anulada y sin la de agosto');
es(todos.cotizado.monto, 7600000, 'monto cotizado, la perdida incluida');
es(todos.vendido.n, 3, 'vendido del mes: la aprobada y la concluida, sin la anulada, sin la pendiente y sin la de agosto');
es(todos.vendido.monto, 9000000, 'monto vendido');
es(todos.perdidas.n, 1, 'una perdida');
es(Math.round(todos.conversion.pct), 60, 'conversión: 3 de 5');
es(todos.ticket, 3000000, 'ticket promedio');

// Venta es la NV aprobada o concluida. La pendiente no suma, pero tampoco
// desaparece: se informa aparte, que es lo que explica la diferencia entre lo
// que el vendedor cuenta en su lista y lo que dice el panel.
es(todos.esperando.n, 1, 'una nota de venta esperando aprobación');
es(todos.esperando.monto, 1000000, 'y su monto, que no está en lo vendido');
es(todos.vendido.monto + todos.esperando.monto, 10000000, 'las dos cifras no se solapan');
// Pero sí convirtió: la cotización 1 llegó a nota de venta, aunque esa nota
// todavía no esté autorizada. La lista la muestra «en nota de venta» y el
// panel no puede decir lo contrario.
es(todos.conversion.n, 3, 'la cotización de la NV pendiente cuenta como convertida');

const mio = calcular({ cotizaciones: COT, notas: NV, rango: R, vendedores: ['2'] });
es(mio.cotizado.n, 4, 'ámbito yo: sin las del vendedor 19');
es(mio.vendido.monto, 5000000, 'ámbito yo: la aprobada y la concluida');
es(mio.esperando.monto, 1000000, 'ámbito yo: lo que espera aprobación');
es(Math.round(mio.conversion.pct), 50, 'conversión del ámbito yo: 2 de 4');

// cierre: cot 2 (05-09) → NV 900 (09-09) = 4 días; cot 5 (10-09) → NV 901 (12-09) = 2
es(todos.cierre, 3, 'cierre: mediana de 4 y 2');
es(todos.cierre_n, 2, 'sólo las notas con cotización bajada');

const vacio = calcular({ cotizaciones: [], notas: [], rango: R });
es(vacio.conversion, null, 'sin cotizaciones no hay conversión, no 0 %');
es(vacio.ticket, null, 'sin notas no hay ticket, no $0');
es(vacio.cierre, null, 'sin cierres no hay mediana');
es(vacio.cotizado.monto, 0, 'pero el monto sí es cero: cero es un dato');

// una NV fechada antes que su cotización no se corrige: se descarta
const alReves = calcular({
    cotizaciones: [{ numero: 1, estado: 'P', fecha: '2026-09-20T00:00:00', neto: 1, exento: 0 }],
    notas: [{ numero: 9, cotizacion: 1, estado: 'A', fecha: '2026-09-05T00:00:00', neto: 1, exento: 0 }],
    rango: R,
});
es(alReves.cierre, null, 'días negativos fuera');

// ---- pendientes
const P = pendientes({ cotizaciones: COT, hoy: '2026-09-14', vigencia: 30, avisoDias: 7 });
es(P.total.n, 2, 'dos pendientes, de cualquier mes');
es(P.total.monto, 8000000, 'y su monto');
es(P.reciente.n, 0, 'ninguna de menos de 7 días');
es(P.mes.n, 2, 'las dos están entre 8 y 30 días');
es(P.viejas.n, 0, 'ninguna de más de 90');
es(P.por_vencer.n, 1, 'la del 20-08 vence en 5 días');
es(P.vencidas.n, 0, 'ninguna vencida todavía');
es(pendientes({ cotizaciones: COT, hoy: '2026-09-14', vendedores: ['19'] }).total.n, 0,
   'el vendedor 19 no tiene pendientes');

es(P.abiertas.n, 1, 'la otra sigue abierta y con tiempo');
es(P.por_vencer.n + P.vencidas.n + P.abiertas.n, P.total.n, 'cada pendiente cae en un grupo y en uno solo');

// ---- situación: la misma regla que usa la lista para filtrar
const REGLA = { hoy: '2026-09-14', vigencia: 30 };
es(situacion({ estado: 'P', fecha: '2026-09-12T00:00:00' }, REGLA), 'abierta', 'recién hecha');
es(situacion({ estado: 'P', fecha: '2026-08-20T00:00:00' }, REGLA), 'por_vencer', 'le quedan 5 días');
es(situacion({ estado: 'P', fecha: '2026-07-01T00:00:00' }, REGLA), 'vencida', 'pasó la vigencia');
es(situacion({ estado: 'P', fecha: '2026-08-15T00:00:00' }, REGLA), 'por_vencer', 'el día 30 vence hoy, todavía no está vencida');
es(situacion({ estado: 'P', fecha: '2026-08-14T00:00:00' }, REGLA), 'vencida', 'el día 31 sí');
es(situacion({ estado: 'V', fecha: '2026-07-01T00:00:00' }, REGLA), null, 'una vendida no está pendiente');
es(situacion({ estado: 'R', fecha: '2026-07-01T00:00:00' }, REGLA), null, 'una perdida tampoco');
es(situacion({ estado: 'P', fecha: null }, REGLA), null, 'sin fecha no se puede saber');
es(situacion({ estado: 'P', fecha: '2026-09-12T00:00:00' }, { hoy: '2026-09-14', vigencia: 3 }),
   'por_vencer', 'la vigencia manda: con 3 días, la de anteayer ya avisa');

const Pviejo = pendientes({ cotizaciones: COT, hoy: '2026-12-31', vigencia: 30 });
es(Pviejo.viejas.n, 2, 'en diciembre las dos pasan de 90 días');
es(Pviejo.vencidas.n, 2, 'y las dos están vencidas');


// ---- días: el valor grande se escribe largo, la comparación corta
es(dias(12), '12 d', 'la abreviatura por defecto');
es(dias(12, true), '12 días', 'en largo cuando es el número protagonista');
es(dias(1, true), '1 día', 'y concuerda en singular');
es(dias(3.5, true), '3,5 días', 'una decimal, con coma');
es(dias(null), SIN_DATO, 'sin dato no se inventa un cero');

es(diferenciaDias(11, 14).texto, '3 d', 'de 14 a 11 son 3 días menos');
es(diferenciaDias(11, 14).direccion, 'baja', 'y la flecha va hacia abajo');
es(diferenciaDias(14, 11).direccion, 'sube', 'al revés, hacia arriba');
es(diferenciaDias(12, 12).direccion, 'igual', 'sin movimiento no hay flecha');
es(diferenciaDias(12, null), null, 'sin período anterior no hay comparación');
es(diferenciaDias(null, 12), null, 'ni sin período actual');
// El cero manda: de 14 días a 0 la comparación existe, a diferencia de
// `variacion`, que no puede dividir por cero.
es(diferenciaDias(0, 14).texto, '14 d', 'bajar a cero sí se puede comparar');

// ---- actividad reciente
const ACT = [
    { numero: 10, fecha: '2026-09-01', vendedor: '2' },
    { numero: 11, fecha: '2026-09-10', vendedor: '2' },
    { numero: 12, fecha: '2026-09-10', vendedor: '7' },
    { numero: 13, fecha: '2026-08-30', vendedor: '2', estado: 'N' },
];
es(ultimos(ACT, { cuantos: 5 }).map((f) => f.numero).join(','), '12,11,10,13',
   'lo más nuevo primero, y a igual fecha manda el correlativo');
es(ultimos(ACT, { cuantos: 2 }).length, 2, 'corta donde se le pide');
es(ultimos(ACT, { vendedores: ['2'] }).map((f) => f.numero).join(','), '11,10,13',
   'el ámbito filtra también la actividad');
es(ultimos(ACT).some((f) => f.estado === 'N'), true,
   'lo anulado sale: anular es algo que se hizo');
es(ultimos([]).length, 0, 'sin documentos, lista vacía y no un error');


// ---- el saldo de una cotización: la copia de `Saldo.php` que corre en el
//      teléfono. Si estas dos reglas se separan, la ficha dice una cosa y el
//      servidor escribe otra.
const COT8 = { numero: 8, creado: '2026-01-10 09:00:00' };
const LIN8 = [
    { cotizacion: 8, linea: 1, cantidad: 12, producto: 'A' },
    { cotizacion: 8, linea: 2, cantidad: 5, producto: 'B' },
    { cotizacion: 8, linea: 3, cantidad: 1, producto: 'C' },
];
const nv = (numero, estado) => ({ numero, cotizacion: 8, estado, creado: `2026-02-0${numero} 10:00:00` });
const enl = (notaVenta, linea, cotLinea, cantidad) => ({
    nota_venta: notaVenta, linea, cotizacion: 8, cotizacion_linea: cotLinea, cantidad,
    nota_venta_creada: `2026-02-0${notaVenta} 10:00:00`, cotizacion_creada: COT8.creado,
});

const sinConvertir = calcularSaldo({ cotizacion: COT8, lineas: LIN8, notas: [], enlaces: [] });
es(sinConvertir.parcial, false, 'sin nota de venta no está a medias');
es(sinConvertir.pendientes, 3, 'sin convertir, las tres líneas pendientes');
es(sinConvertir.conocible, true, 'sin notas de venta el saldo se sabe');

const aMedias = calcularSaldo({
    cotizacion: COT8, lineas: LIN8,
    notas: [nv(1, 'A')],
    enlaces: [enl(1, 1, 1, 5), enl(1, 2, 2, 5)],
});
es(aMedias.parcial, true, 'convertida en parte está a medias');
es(aMedias.pendientes, 2, 'quedan dos líneas con saldo');
es(aMedias.lineas[0].saldo, 7, 'de doce convertidas cinco quedan siete');
es(aMedias.lineas[1].saldo, 0, 'la línea convertida entera queda en cero');
es(aMedias.lineas[2].saldo, 1, 'la que no se tocó queda entera');

const entera = calcularSaldo({
    cotizacion: COT8, lineas: LIN8,
    notas: [nv(1, 'A'), nv(2, 'A')],
    enlaces: [enl(1, 1, 1, 5), enl(1, 2, 2, 5), enl(2, 1, 1, 7), enl(2, 2, 3, 1)],
});
es(entera.parcial, false, 'convertida del todo ya no está a medias');
es(entera.pendientes, 0, 'sin líneas pendientes');

// Anular devuelve el saldo: es la misma regla del servidor.
const conAnulada = calcularSaldo({
    cotizacion: COT8, lineas: LIN8,
    notas: [nv(1, 'A'), nv(2, 'N')],
    enlaces: [enl(1, 1, 1, 5), enl(1, 2, 2, 5), enl(2, 1, 1, 7), enl(2, 2, 3, 1)],
});
es(conAnulada.lineas[0].saldo, 7, 'la nota de venta anulada devuelve lo suyo');
es(conAnulada.parcial, true, 'y la cotización vuelve a estar a medias');

// Convertida fuera de la app: hay nota de venta viva y ningún enlace.
const ciega = calcularSaldo({
    cotizacion: COT8, lineas: LIN8, notas: [nv(1, 'A')], enlaces: [],
});
es(ciega.conocible, false, 'sin enlace de línea el saldo no se sabe');
es(ciega.parcial, false, 'y no se marca a medias, que sería inventarlo');

// Un enlace cuyo número volvió a repartirse no cuenta.
const zombi = calcularSaldo({
    cotizacion: COT8, lineas: LIN8,
    notas: [{ numero: 1, cotizacion: 8, estado: 'A', creado: '2026-05-05 08:00:00' }],
    enlaces: [enl(1, 1, 1, 5)],
});
es(zombi.conocible, false, 'un enlace con otra marca de creación no vale');


/* ---------------------------------------------------------- seguimiento
 *
 * El compromiso con el cliente. La regla la usan el panel para contar y la
 * lista para filtrar, así que una sola copia y aquí queda fijada: dos copias
 * son un panel que dice «4» y una lista que muestra 5.
 */
const DIA_COMPROMISO = '2026-09-16';
const abierta = { estado: 'P' };

es(estadoCompromiso(abierta, null, DIA_COMPROMISO), 'sin_compromiso',
    'abierta y sin nada prometido: es la que se enfría sola');
es(estadoCompromiso(abierta, { cuando: '2026-09-15' }, DIA_COMPROMISO), 'atrasado', 'la fecha ya pasó');
es(estadoCompromiso(abierta, { cuando: '2026-09-16' }, DIA_COMPROMISO), 'hoy', 'toca hoy');
es(estadoCompromiso(abierta, { cuando: '2026-09-18' }, DIA_COMPROMISO), 'proximo', 'dentro de la semana');
es(estadoCompromiso(abierta, { cuando: '2026-11-30' }, DIA_COMPROMISO), null,
    'muy lejos todavía: no es trabajo de esta semana');

// La hora no puede cambiar el día: un compromiso a las 23:00 de hoy es de hoy.
es(estadoCompromiso(abierta, { cuando: '2026-09-16 23:00:00' }, DIA_COMPROMISO), 'hoy',
    'con hora sigue siendo hoy');

// Una cotización vendida o perdida no espera ninguna llamada, y contarla sería
// inflar la lista con trabajo que no existe.
es(estadoCompromiso({ estado: 'V' }, { cuando: '2026-09-15' }, DIA_COMPROMISO), null, 'vendida no cuenta');
es(estadoCompromiso({ estado: 'R' }, null, DIA_COMPROMISO), null, 'perdida no cuenta');
// Las cotizaciones viejas de INNOVAGES no tienen estado escrito, y están
// abiertas: tratarlas como cerradas las escondería.
es(estadoCompromiso({ estado: '' }, null, DIA_COMPROMISO), 'sin_compromiso', 'sin estado es abierta');

es(cuando('2026-09-16', DIA_COMPROMISO), 'hoy', 'hoy se dice «hoy»');
es(cuando('2026-09-17', DIA_COMPROMISO), 'mañana', 'mañana');
es(cuando('2026-09-15', DIA_COMPROMISO), 'ayer', 'ayer');
es(cuando('2026-09-19', DIA_COMPROMISO), 'en 3 días', 'lo que viene');
es(cuando('2026-09-12', DIA_COMPROMISO), 'hace 4 días', 'lo que se pasó');

es(hora('2026-09-16 10:30:00'), '10:30', 'la hora del compromiso');
es(hora('2026-09-16 00:00:00'), '', 'las 00:00 son «sin hora», no medianoche');
es(hora('2026-09-16'), '', 'sin hora');

es(sumarDias('2026-09-30', 1), '2026-10-01', 'cruza de mes');
es(sumarDias('2026-12-31', 1), '2027-01-01', 'cruza de año');
es(sumarDias('2026-09-16', -1), '2026-09-15', 'hacia atrás');


/* ------------------------------------------------------------- facturado
 *
 * Lo emitido en el período. No es la continuación de lo vendido —aquí se
 * factura por suscripción— pero es una cifra legítima por sí sola.
 */
const RANGO = { desde: '2026-09-01', hasta: '2026-09-30' };

const facturasPrueba = [
    { tipo: 'F', fecha: '2026-09-10', neto: 1000, exento: 0, estado: 'V', vendedor: '2' },
    { tipo: 'F', fecha: '2026-09-20', neto: 500, exento: 200, estado: 'V', vendedor: '2' },
    // La nota de crédito viene en negativo desde Softland y resta sola.
    { tipo: 'N', fecha: '2026-09-25', neto: -300, exento: 0, estado: 'V', vendedor: '2' },
    // Anulada: no se facturó nada.
    { tipo: 'F', fecha: '2026-09-15', neto: 9999, exento: 0, estado: 'N', vendedor: '2' },
    // De otro mes.
    { tipo: 'F', fecha: '2026-08-15', neto: 7777, exento: 0, estado: 'V', vendedor: '2' },
    // De otro vendedor.
    { tipo: 'F', fecha: '2026-09-18', neto: 4444, exento: 0, estado: 'V', vendedor: '5' },
];

const conFacturas = calcular({
    cotizaciones: [], notas: [], facturas: facturasPrueba, rango: RANGO, vendedores: ['2'],
});

es(conFacturas.facturado.monto, 1400, 'facturado: 1000 + 700 − 300, neto y sin lo ajeno');
es(conFacturas.facturado.n, 3, 'cuenta los tres documentos vivos del período');

const sinFiltro = calcular({
    cotizaciones: [], notas: [], facturas: facturasPrueba, rango: RANGO, vendedores: null,
});
es(sinFiltro.facturado.monto, 5844, 'sin filtro de vendedor entra también la del otro');

es(calcular({ cotizaciones: [], notas: [], rango: RANGO }).facturado.monto, 0,
    'sin facturas la cifra es cero, no un error');

console.log(`OK — ${hechas} comprobaciones`);
