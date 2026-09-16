# El ciclo normal de venta

> Plan de la fase 5. Las reglas de aquí están acordadas con el cliente y
> apoyadas en los datos de las dos bases; lo que todavía no se sabe está dicho
> como tal, al final.

## De qué va esto

INNOVAGES es distribuidor de Softland en la octava región. Cotiza al cliente
final y le hace la nota de venta, pero **quien le factura al cliente es Softland
Santiago**, que es otro RUT. INNOVAGES emite aparte una factura chica de
comisión, contra Softland, y la cuelga de esa nota de venta.

Ese ciclo es real y hay que seguir sirviéndolo, pero **no es el que la app viene
a resolver**. Lo que se construye es el ciclo normal:

```
cotización ──┬─→ nota de venta ──┬─→ factura      el mismo RUT en los tres
             │                   └─→ factura
             └─→ nota de venta ──→ factura
```

Uno a varios en los dos saltos, y **parcial** en los dos: se convierte parte de
la cotización, se factura parte de la nota de venta.

La pregunta que hay detrás de todo esto es siempre la misma, hecha dos veces:
**de esta línea, ¿cuánto queda?**

## Lo que Softland trae, y lo que no

Esto se averiguó antes de diseñar nada, y cambió el diseño dos veces.

### Las columnas de avance están muertas

`nw_detnv` lleva siete columnas para el avance línea a línea — `nvCantFact`,
`nvCantDesp`, `nvCantProd`, `nvCantOC`, `nvCantBoleta`, `nvCantNC`,
`nvCantDevuelto`—. **Ninguna se usa.**

| | INNOVAGES | NETDOMAIN |
|---|---|---|
| Líneas de nota de venta | 3.824 | 3.824 |
| Con cualquiera de las siete > 0 | 0 | **0** |

Y no es que NETDOMAIN no facturara desde nota de venta: **941 de sus facturas
nacen de una**, con facturación parcial evidente. Un caso de diciembre de 2023:

```
NV 1874, cliente 76125084:  línea 1, producto 70700002, 12 unidades a $265.000
factura folio 1581, mismo cliente:  1 unidad a $265.000, nvCorrela = 1
                                    nvCantFact de la NV: 0
```

Doce meses vendidos, uno facturado, el contador sin moverse. Con 18.150
documentos y cero registros, la conclusión es firme: **el ERP no las mantiene**.

### El enlace de línea sí existe, y es `nvCorrela`

En la factura hay dos columnas que se confunden:

- **`iw_gmovi.Linea`** — el número de línea *de la factura*, su numeración propia.
- **`iw_gmovi.nvCorrela`** — el puntero *a la línea de la nota de venta*, o sea a
  `nw_detnv.nvLinea`.

Lo escribe Softland: **2.558 de 2.620** líneas de factura nacidas de NV lo
traen. Cruzando las 1.017 que se pueden cruzar de las dos puntas:

| De 1.017 líneas | |
|---|---|
| Mismo producto que la línea de NV apuntada | 1.015 |
| Mismo precio que la NV | 987 |
| Cantidad menor que la NV (facturación parcial) | 140 |
| Cantidad igual | 877 |
| Cantidad mayor | 0 |

En INNOVAGES va en cero porque sus líneas de comisión no vienen de ninguna línea
de NV. No es que el ERP no lo escriba: es que ahí no hay de dónde.

### Para el otro salto no hay nada

`nwdetcot` no tiene ninguna columna de cantidad consumida, y `nwcotiza` solo
tiene el estado. **`CtEstado` pasa a `V` con la primera nota de venta y ahí se
queda**, dé lo mismo si se convirtió entera o una línea de ocho.

Y el reparto ocurre. De las cotizaciones con más de una nota de venta:

| Qué pasó | INNOVAGES | NETDOMAIN |
|---|---|---|
| **Reparto real** — varias NV vivas que se dividen los productos | 22 | 123 |
| **Reparto por cantidad** — NV vivas que comparten algún producto | 12 | 21 |
| **Rehacer** — una viva y el resto anuladas | 17 | 26 |

Las 221 están en `V`, las repartidas igual que las enteras. Hoy el dato de qué
faltaba vive en la memoria del vendedor.

Un reparto real, de enero de 2024:

```
cotización 7952, 8 líneas, $2.881.318
   NV 1908  líneas 1-7   $1.698.354   ┐ el mismo día,
   NV 1909  línea  8     $1.182.965   ┘ y suman la cotización entera
```

Y un rehacer, que importa por otra razón:

```
cotización 8035, 2 líneas
   NV 1934  anulada (estado N)   mismas 2 líneas
   NV 1939  vigente (estado A)   mismas 2 líneas
```

Si el saldo contara las anuladas, esta cotización aparecería consumida dos veces
y no dejaría hacer nada más.

## Las reglas

Acordadas con el cliente. Cada una está aquí porque su contraria produce un
error concreto, y el error está dicho.

1. **El saldo se calcula, no se guarda.** Un contador se desincroniza en cuanto
   alguien borra, anula o corrige. Una resta sobre los documentos que existen no
   puede mentir, y devuelve el saldo sola cuando un documento desaparece.

2. **Solo cuentan los documentos vivos.** Una nota de venta en `N` no consume
   cotización; una factura anulada por nota de crédito no consume nota de venta.
   **Anular devuelve el saldo igual que borrar** — los 43 casos de «rehacer» son
   exactamente eso.

   Los estados `P`, `A` y `C` consumen. Una NV pendiente de aprobación está
   viva: si no consumiera, dos personas convertirían la misma cotización
   mientras el jefe decide.

3. **El precio y el factor se heredan de la nota de venta y no se cambian.** El
   monto en pesos es `nvPrecio × nvEquiv × cantidad`, con el `nvEquiv` de la NV.
   La consecuencia, que hay que tener clara: **el valor en pesos se congela el
   día de la nota de venta**. Facturar en marzo una NV de enero se hace a la UF
   de enero.

   Cuidado: `iw_gmovi.Equivalencia` **no** sirve para guardar el factor — va en
   cero en 1.000 de las 1.017 líneas cruzadas. El precio ya convertido va en
   `PreUniMB`.

4. **El saldo sugiere, no limita.** Se puede facturar de más y agregar productos
   que no estaban en la nota de venta. Esas líneas no cuelgan de ninguna línea
   de NV y simplemente no consumen saldo. La app avisa; no bloquea.

5. **La cotización no estrena letra.** Son cuatro estados y son el vocabulario
   del ERP. «Convertida a medias» es una lectura de la app, calculada del saldo,
   no un quinto valor de `CtEstado`. Un estado inventado la haría invisible en
   las ventanas de Softland, que es el error que ya costó tres meses.

6. **`V` mientras quede una nota de venta viva.** Si no queda ninguna —borradas
   o anuladas—, la cotización vuelve a `P`. Esto generaliza la regla que ya
   existía: la de hoy dice «borrar la NV devuelve la cotización a `P`», y con
   dos notas de venta eso dejaría en `P` una cotización que sí está vendida.

7. **Lo convertido antes de la app se da por consumido entero.** No hay enlace
   de línea para esas, y suponer que les queda todo llevaría a convertirlas dos
   veces. Es justo lo que significa el `V` de Softland.

8. **El receptor de la factura es el cliente de la nota de venta.** Cambiarlo
   exige una llave de configuración, apagada por omisión. Encendida, habilita el
   ciclo de comisión: la NV entra como referencia y como sugerencia, no como
   fuente obligatoria.

9. **Las siete columnas de avance se quedan en cero**, como las deja el ERP.
   Escribirlas nos convertiría en el único proceso que las mantiene, y las
   ventanas de Softland pasarían a decir la verdad para los documentos de la app
   y mentira para los demás. Hoy callan de forma pareja, que es más honesto.

## Dónde vive cada cosa

| Salto | Dónde vive el enlace |
|---|---|
| Cotización → nota de venta | **`ventas.linea_origen`**, nuestro. Softland no tiene columna y no se inventa una en su esquema |
| Nota de venta → factura | **`iw_gmovi.nvCorrela` → `nw_detnv.nvLinea`**, nativo |
| Factura → nota de crédito | **`iw_gsaen.AuxDocNum`**, nativo. Las 227 notas de crédito de NETDOMAIN lo traen |

Que el segundo salto sea nativo compra algo que una tabla nuestra no puede: el
saldo sale bien **también cuando factura el Softland de escritorio**, porque el
ERP escribe la misma columna. Un registro propio solo sabría de lo que hizo la
app.

## Cómo se calcula el saldo

De una línea de cotización:

```
CtCant − Σ nvCant de las líneas de nota de venta VIVAS que la citan
```

De una línea de nota de venta:

```
nvCant − Σ CantFacturada de las líneas de factura VIVAS con nvCorrela = nvLinea
       + Σ lo acreditado por notas de crédito de esas facturas
```

«Viva» es, en los dos casos, la regla 2.

## El plan, por pasos

### Paso 1 — El saldo ✅ hecho (0.14.0)

`ventas.linea_origen` y el servicio `Saldo`, con `ventas:verifica-saldo` para
contrastarlo. Sin interfaz todavía.

Lo que se aprendió haciéndolo, que no estaba en el plan:

- **La nota de crédito dice qué acredita en `IW_GSaEn_RefDTE`** —`CodRefSII` con
  el tipo del SII y `FolioRef` con el folio—, **no en `AuxDocNum`**. Ahí
  coincidía en INNOVAGES por casualidad; 5.317 facturas de NETDOMAIN también lo
  llevan relleno, o sea que es un número auxiliar cualquiera. Cruzar por ahí
  emparejaba notas de crédito con notas de venta ajenas. Hay que comparar el
  **tipo además del folio**: los folios se repiten entre tipos.
- **La línea de la nota de crédito viene en negativo** (`esDevolucion = -1`,
  `CantFacturada = -1.0`): es la misma línea de la factura con el signo
  cambiado. Sumarla tal cual restaba dos veces, y una línea pedida 1 y facturada
  1 daba saldo -1, que no es un número posible.
- **Una nota de crédito de una factura que nunca consumió no devuelve nada.** Si
  todas las líneas de la factura van sin `nvCorrela` —el caso de la comisión—,
  su nota de crédito no tiene saldo que devolver. Sin ese filtro, las diez notas
  de crédito de comisión de INNOVAGES salían como «no atribuibles».

**Lo que dio la comprobación:**

```
INNOVAGES    136 notas de venta con factura, 572 líneas
             134 sin ninguna línea enlazada (son las comisiones)
             670 cotizaciones convertidas: 659 dicen «no se sabe», 11 ya no existen
             0 inventan saldo, 0 acreditado por encima de lo facturado

NETDOMAIN    769 notas de venta con factura, 1.018 líneas
             397 facturadas del todo, 64 con saldo, 6 facturadas de más
             308 notas de venta que la factura cita y ya no existen
             800 cotizaciones convertidas: 800 dicen «no se sabe»
             0 inventan saldo, 0 acreditado por encima de lo facturado
```

**El invariante que se exige no es que el saldo sea positivo.** Facturar de más
está permitido —regla 4— y en NETDOMAIN pasa de verdad: seis líneas, entre ellas
notas de venta de doce mensualidades que acabaron con catorce facturas. Lo
imposible es **acreditar más de lo facturado**, que significaría estar
emparejando notas de crédito que no son de esta nota de venta. Eso sale en cero
en las dos empresas.

### Paso 2 — Convertir parte de la cotización

El editor de conversión deja elegir líneas y cantidades. Al guardar, escribe las
filas de `linea_origen`. La cotización pasa a `V` con lo primero que se
convierta, y vuelve a `P` si se queda sin notas de venta vivas.

**Prueba:** en la base de pruebas, reproducir la cotización 7952 —ocho líneas,
siete a una NV y una a otra— y que el saldo quede en cero. Después anular una y
que vuelva a aparecer.

### Paso 3 — Facturar parte de la nota de venta

`Facturacion` escribe `nvCorrela` en cada línea que venga de la NV, y
`iw_gsaen.nvnumero` en el encabezado. El precio y el factor se heredan y el
campo de precio no se puede editar. Las líneas agregadas a mano van sin
`nvCorrela`.

**Prueba:** reproducir la NV 1874 de NETDOMAIN —12 unidades, factura de una— y
que el saldo diga 11. Después, doce facturas de una unidad y que termine en
cero.

### Paso 4 — Devolver el saldo al anular

Anular una nota de venta (`nvEstado = 'N'`) y anular una factura por nota de
crédito tienen que dejar el saldo como estaba. Aquí hay que resolver **cómo se
cruza la línea de la nota de crédito con la línea de la factura**: 84 de las 320
líneas de NC de NETDOMAIN traen `nvCorrela`, o sea que no siempre.

**Prueba:** el ciclo completo —convertir, facturar, anular, volver a facturar—
sin que el saldo se pierda ni se duplique.

### Paso 5 — La llave del receptor

`ventas.config`, apagada por omisión. Apagada, la factura hereda el cliente de
la NV y el campo ni se muestra. Encendida, se puede cambiar y la NV queda como
referencia. Es lo que mantiene vivo el ciclo de comisión.

**Prueba:** con la llave apagada, reproducir una factura normal de NETDOMAIN;
con la llave encendida, reproducir una comisión de INNOVAGES.

### Paso 6 — Las pantallas

Un solo concepto nuevo, en tres sitios:

- ficha de la cotización: «convertida a medias — quedan 1 de 8 líneas», con el
  detalle, y botón **«nota de venta por el saldo»** que abre el editor cargado
  con lo que quedó fuera;
- ficha de la nota de venta: botón de facturar con las cantidades pendientes
  precargadas y editables;
- listas: marca de parcial, para que se vea sin entrar.

**Prueba:** la de siempre — 360×640, las tres escalas de densidad, y sin señal.

## Lo que todavía no se sabe

- **El cruce línea a línea de la nota de crédito.** 84 de 320 traen
  `nvCorrela`; hay que ver qué hacen las otras 236 antes de escribir el paso 4.
- **La herencia del factor en UF no tiene precedente.** NETDOMAIN tiene
  `nvEquiv = 1` en sus 3.820 líneas —nunca vendió en UF— y las facturas de
  INNOVAGES son comisiones, que no heredan nada. La regla 3 la estrenamos
  nosotros: conviene confirmarla con contabilidad antes de la primera factura
  en UF de verdad.
- **Qué hace el Softland de escritorio si alguien convierte o factura por su
  lado mientras la app lleva la cuenta.** Para el salto NV → factura no
  preocupa, porque el ERP escribe `nvCorrela` y el cálculo lo ve. Para el salto
  cotización → NV sí: una NV hecha desde el ERP no dejará fila en
  `linea_origen`, y su consumo será invisible. Hay que decidir si eso se detecta
  —comparando el total de la cotización con lo convertido— o se acepta.
