/*
 * Pruebas de lo que se puede probar sin navegador: el formato del dinero y los
 * indicadores del panel. Se corren con `npm run pruebas`, sin dependencias.
 */
import assert from 'node:assert/strict';
import {
    dinero, dineroExacto, leyenda, variacion, puntos, porcentaje, dias, hayDato, SIN_DATO,
} from '../src/dinero.js';

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

console.log(`OK — ${hechas} comprobaciones`);
