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

### Paso 2 — Convertir parte de la cotización ✅ hecho (0.15.0)

La conversión acepta líneas y cantidades sueltas —cada línea de nota de venta
dice de qué línea de cotización sale— y escribe `linea_origen`. Las pantallas
son el paso 6; esto es la cañería.

Tres cosas cambiaron además de lo previsto:

- **`V` ya no cierra la puerta.** El endpoint de conversión devolvía 409 en
  cuanto la cotización estaba en `V`. Ahora lo que la cierra es que no quede
  saldo, y cuando no queda, el mensaje nombra **todas** sus notas de venta.
- **`devolverCotizacion` contaba las anuladas.** Miraba si existía *alguna* nota
  de venta con ese `CotNum`, sin filtrar por estado. Con una sola nota de venta
  daba igual; con reparto parcial dejaba cotizaciones vendidas sin estarlo.
- **Corregir una nota de venta rehace sus enlaces.** Se borran y se reescriben
  junto con el detalle: dejar los viejos era un saldo que ya no correspondía a
  ninguna línea existente.

**Cómo se probó, y por qué así.** `Ventas` no sabe escribir en otra base —
escribe en la de la instalación—, así que en vez de una copia se usa una
transacción que se deshace al final. Se recorre el camino real, el mismo que usa
el teléfono, y no queda nada: ni documentos, ni enlaces, ni números gastados,
porque el correlativo vuelve atrás con todo lo demás.

```
Cotización 8554: 12 + 5 + 1
   ok recién creada, no se ha convertido nada             saldo [12, 5, 1] estado P
Nota de venta 2065: 5 de la línea 1, las 5 de la línea 2
   ok convertida a medias                                 saldo [7, 0, 1]  estado V
Nota de venta 2066: los 7 que faltaban y la línea 3
   ok convertida del todo                                 saldo [0, 0, 0]  estado V
Anulada la nota de venta 2066
   ok el saldo vuelve y sigue en V porque queda una viva  saldo [7, 0, 1]  estado V
Anulada la nota de venta 2065
   ok sin ninguna nota de venta viva, vuelve a P          saldo [12, 5, 1] estado P

Deshecho: no queda ningún documento ni número gastado.
```

Es el caso de la cotización 7952 —repartida entre dos notas de venta— más lo que
aquélla no tenía: **reparto por cantidad dentro de una línea** (12 = 5 + 7) y la
vuelta atrás al anular.

**Lo que se dejó sin poner:** convertir de más no se bloquea. El saldo sugiere y
no limita, igual que al facturar; si alguien convierte 15 de una línea de 12, el
saldo queda en -3 y se ve. Bloquearlo sería una regla que nadie pidió.

### Paso 3 — Facturar parte de la nota de venta ✅ hecho (0.16.0)

`Facturacion` escribe `nvCorrela` en cada línea que venga de la nota de venta y
`iw_gsaen.nvnumero` en el encabezado, y **hereda** el producto, el precio, el
factor y el descuento de línea. Se sobrescriben, no se rellenan si faltan:
heredar no es un valor por omisión, es una regla. Si el teléfono manda otro
precio, gana la nota de venta.

El descuento de línea se hereda por la misma razón que el precio. Dejarlo
abierto sería dejar abierto el precio por otra puerta: un 20 % cambia lo que
paga el cliente igual que cambiar el número.

**Un fallo latente que salió aquí:** `PreUniMB` se estaba escribiendo en la
moneda del **producto**. De esa columna sale el `PrcItem` del DTE, y el SII
comprueba que `PrcItem × QtyItem` cuadre con `MontoItem`, que es `TotLinea` y va
en pesos. Con un producto en UF eso produce un documento que no cuadra consigo
mismo y el SII lo rechaza. Nunca se vio porque las 199 facturas contrastadas
tienen equivalencia 1 — y por eso mismo corregirlo no cambia ninguna: se
reescriben las 189 exactamente igual que antes.

**Por qué una factura y no doce.** Queda **un solo folio**: el repartidor
entrega el 235 y a la segunda llamada devuelve -1. Así que se factura una vez y
se comprueba lo que esa vez demuestra. El caso de muchas facturas contra una
nota de venta ya lo demuestra la historia: `ventas:verifica-saldo` recorre 397
notas de venta de NETDOMAIN facturadas del todo, algunas en 26 veces.

```
Nota de venta 2065: 12 unidades de 22214004 a 17.500
   ok recién creada, sin facturar
   ok la propuesta ofrece las 12 pendientes
   ok se rechaza una línea que dice venir de una NV sin decir de cuál
   ok se rechaza una línea de la NV que no existe
Factura folio 235: 5 de la línea 1 más una línea agregada a mano
   ok facturada en parte
   ok la línea heredada apunta a la línea 1 de la nota de venta
   ok la línea agregada a mano no apunta a ninguna
   ok el precio lo puso la nota de venta, no quien facturó
   ok el encabezado dice de qué nota de venta viene
   ok lo que queda por facturar son 7
Anulada la factura 235
   ok una factura anulada no consume

Deshecho: no queda documento ni folio gastado.
```

Los dos rechazos ocurren **antes** de pedir folio: una línea que dice venir de
una nota de venta sin decir de cuál, y una que cita una línea inexistente. Un
folio no se gasta para descubrir que la petición estaba mal.

### Paso 4 — Devolver el saldo al anular ✅ hecho (0.17.0)

La parte de la nota de venta ya estaba en el paso 2. Aquí se cerró la de la
nota de crédito, que era el hueco anotado.

**Cómo se cruza la línea de la nota de crédito.** Hay dos columnas y se reparten
el trabajo sin ponerse de acuerdo:

| | `nvCorrela` | `FactNumLin` |
|---|---|---|
| Qué dice | a qué línea de **nota de venta** devuelve | a qué línea de **factura** devuelve |
| INNOVAGES (12 líneas) | 2 | **12** |
| NETDOMAIN (320 líneas) | **84** | 13 |

Mirar una sola deja fuera a la mayoría en una de las dos empresas. El lector
prueba las dos: si no está `nvCorrela`, salta por `FactNumLin` a la línea de la
factura y toma de ahí el enlace. Son dos saltos en vez de uno y llegan al mismo
sitio.

**Y no se inventa una tercera.** Quedan dos líneas en NETDOMAIN que no traen
ninguna de las dos. Se miraron: llevan el producto `70508002` y la factura que
acreditan lleva `70700002`. **No están devolviendo esa línea**, así que
atribuírsela sería inventar. Un respaldo «por producto, si no hay ambigüedad»
habría acertado a equivocarse justo ahí. Se informan y se acabó.

**Lo que se escribe.** La nota de crédito hereda del documento que corrige el
producto, el precio, el descuento y el `nvCorrela`. Así las nuestras dicen a qué
devuelven por las dos vías a la vez, y el daño que se ve en NETDOMAIN —224
líneas que no lo dicen por ninguna— no se repite.

```
Nota de venta 2065: 12 unidades de 22214004 a 17.500
   ok recién creada, sin facturar
   ok la propuesta ofrece las 12 pendientes
   ok se rechaza una línea que dice venir de una NV sin decir de cuál
   ok se rechaza una línea de la NV que no existe
Factura folio 235: 5 de la línea 1 más una línea agregada a mano
   ok facturada en parte
   ok la línea heredada apunta a la línea 1 de la nota de venta
   ok la línea agregada a mano no apunta a ninguna
   ok el precio lo puso la nota de venta, no quien facturó
   ok el encabezado dice de qué nota de venta viene
   ok lo que queda por facturar son 7
Nota de crédito folio 16: devuelve la factura entera
   ok acreditada, el saldo vuelve
   ok la nota de crédito dice qué línea de la factura devuelve
   ok y hereda de ella el enlace a la nota de venta
   ok la cantidad devuelta va en negativo
   ok vuelve a haber 12 por facturar
Anulada la nota de crédito 16
   ok sin la nota de crédito, los 5 vuelven a consumir
Anulada la factura 235
   ok una factura anulada no consume

Deshecho: no queda documento ni folio gastado.
```

El saldo vuelve y se va cinco veces sin perderse ni duplicarse, que es lo que
pedía la prueba.

### Paso 5 — La llave del receptor ✅ hecho (0.18.0)

`ReglasFactura`, en `ventas.config`, clave `facturacion`. Nace apagada: el
receptor de la factura se hereda de la nota de venta y no hay forma de
equivocarse. Encendida, quien factura puede cambiarlo y la nota de venta pasa a
ser referencia y sugerencia, no fuente obligatoria.

**Por qué nace apagada.** Lo normal es que la cotización, la nota de venta y la
factura lleven el mismo RUT. Facturarle a otro es, en una instalación normal, un
documento mal emitido cuya corrección es una nota de crédito. Que el ciclo de
distribuidor sea posible no puede significar que sea lo que pasa por omisión.

**Tres decisiones de dónde ponerla:**

- **En el escritor, no en el controlador.** Es una regla del documento, no de
  una pantalla. Un camino nuevo que no supiera de ella —un comando, una
  importación, otra pantalla— escribiría facturas al cliente equivocado sin
  enterarse.
- **Antes de heredar.** A quién se le factura no depende de las líneas. Y va
  antes también porque la **nota de crédito hereda su nota de venta del
  documento que corrige**, y a ésa no se le aplica la regla: su receptor lo
  manda la factura que acredita.
- **En configuración, nunca en el código.** Ni un `if empresa == INNOVAGES` en
  ninguna parte: la app está hecha para replicarse a otra empresa Softland
  cambiando configuración.

**Un efecto que había que resolver.** `dte:verifica-documento` reproduce los 199
documentos históricos de INNOVAGES, que **son comisiones**: van a un cliente
distinto del de su nota de venta. Con la llave como nace no se podrían
reescribir. El comando la enciende a la fuerza para su corrida —comprueba el
escritor, no la configuración de la empresa— y las 189 facturas y 10 notas de
crédito se siguen reescribiendo igual que antes.

```
   ok con la llave apagada se rechaza facturarle a otro cliente
   ok con la llave encendida el receptor ya no estorba
```

La segunda se comprueba mandando además una línea inválida: si el error que
llega es el de la línea y no el del receptor, la regla dejó pasar. Ninguna de
las dos gasta folio, porque las dos fallan antes de pedirlo.

### Paso 5b — Y Softland ya lo decidía, por usuario ✅ hecho (0.34.0)

La llave de arriba es de empresa. Softland contesta **la misma pregunta por
persona**, y lo hace desde antes que nosotros:

```
IW · Iw_FacLin · NVOtroAuxiliar
"Permite que la Factura quede asociada a una Nota de Venta de otro Cliente"
```

Está definido también para `IW_FACMONEXT` y `x_FaLiEx` —moneda extranjera y
exenta—, y es un control de los `wisrest*`, o sea que se concede por perfil y por
usuario. En INNOVAGES lo tiene **sólo el perfil `IW/001`**; el `IW/vend` no, y
ningún usuario lo tiene a título individual. Eso ya dice, en la base y sin que
nadie lo escribiera, «los vendedores hacen el ciclo normal».

**Se cumplen las dos condiciones.** El ERP se lo concede a ese usuario **y** la
empresa no lo ha apagado aquí. La llave nuestra sólo puede apagar, nunca encender
lo que Softland negó — la misma línea que con `nwparam.CheckApruebaNv`: se obedece
al ERP donde manda sobre el documento, y se decide aquí lo que es comportamiento
de esta app.

**El cambio de fondo es el alcance**: la llave es de empresa y el permiso es de
persona. Dos vendedores de la misma empresa pueden tener respuestas distintas, y
eso está bien. Por eso `receptorEditable()` recibe el usuario, y la pantalla de
configuración —que pregunta «¿está permitido aquí?», no «¿puedo yo?»— usa
`receptorEditableEnLaEmpresa()`.

**Los grants se suman.** Un usuario puede tener varios perfiles del mismo
sistema: en INNOVAGES `jpalomin` tiene `IW/001` **y** `IW/vend` a la vez. Basta
con que uno se lo conceda, así que se pregunta por la unión de
`wisrestperfil` (atado por `wisperfilusuario`) y `wisrestusuario`. Preguntar por
un solo perfil daría que no a alguien que sí puede.

**El error dice cuál de los dos falta**, y con el nombre que el administrador ve
en Softland. Si no, quien tiene que ir a marcarlo no sabe si el sitio es el ERP o
la configuración de la app.

El ensayo ahora prueba los tres casos, y **busca los usuarios en la base** en vez
de escribir un nombre: los perfiles son de cada empresa.

```
   ok con la llave de la empresa apagada se rechaza, aunque el usuario tenga el permiso
   ok con la llave encendida, un usuario sin el permiso de Softland sigue sin poder
   ok con la llave encendida y el permiso concedido, el receptor ya no estorba
```

### La Liquidación-Factura, que es el flujo con nombre propio y no se implementa

Buscando ese permiso apareció que Softland **ya tiene el ciclo del mandante como
documento propio**: la Liquidación-Factura, DTE 43, que es la que emite el
mandatario liquidando a su mandante y reteniendo comisión. Está entero:

- dos formularios, `frmLiqFac` («Liquidación Factura Electrónica») e
  `IW_Liquidacion` («Facturas de Liquidación»), con sus propios controles;
- sus cuentas y códigos en `iwparam`: `CtaMandLFDTE`, `CtaComLFDTE`,
  `CtaVtaLFDTE`, `CtaRecTerLFDTE`, `CodLFDTE`, `PorcMandatorio`;
- la tabla de líneas de comisión `iw_gsaen_comislf`.

**INNOVAGES no usa nada de eso**, y se comprobó: esas columnas están todas en
`NULL`, `PorcMandatorio = 0`, `iw_gsaen_comislf` tiene **0 filas**, `iw_gsaen`
sólo tiene `Tipo` F (200) y N (12) y en `dte_doccab` hay 270 del tipo 33, 2 del 34
y 14 del 61 — **ni un solo 43**. Lo que hacen es una factura 33 normal a Softland
Ingeniería con una línea de comisión.

**No se implementa en esta app.** Es otra integración completa —otro tipo de DTE,
folios CAF propios, las cuentas de mandante y comisión, el informe de comisiones—
para un flujo que el cliente no tiene configurado y nunca ha emitido. Queda
escrito aquí para que nadie vuelva a descubrirlo desde cero.

De paso, la forma del negocio queda medida: de **193 facturas atadas a una nota de
venta, 191 van a otro cliente** —189 a Softland Ingeniería, 2 a Softland Training
Center— y **2 van al cliente de su propia nota de venta**. El ciclo normal existe
en esta base; es la excepción, no el ausente.

### Paso 6 — Las pantallas ✅ hecho (0.19.0 y 0.20.0)

**Hecho: la cotización.** La ficha dice «convertida a medias: quedan 2 de 3
líneas» con el detalle de lo que falta; el botón pasa a llamarse **«Nota de
venta por el saldo»** y convierte sólo lo pendiente, con la cantidad pendiente;
y la lista marca **«A medias»**, que es justo lo que Softland no distingue.

**Y se calcula sin señal**, que era la decisión de fondo. `linea_origen` baja
como un maestro más —son pocas filas, una por línea convertida— y
`mobile/src/saldo.js` repite la regla de `Saldo.php`. Se repite a propósito:
esto se mira en terreno, y un dato que sólo aparece con cobertura no sirve para
el trabajo que hace un vendedor. Las dos copias se comprueban con
`npm run pruebas`, igual que la aritmética de `Totales`.

**Un fallo que salió al conectarlo:** convertir no mandaba de qué línea venía
cada línea. Sin eso, ni una conversión completa dejaba enlace, y **toda**
cotización habría quedado en «no se sabe» para siempre. Ahora `cot_linea` viaja
siempre, se convierta entera o a medias.

**Y la factura** (0.20.0). Desde la ficha de la nota de venta, «Facturar» abre
una pantalla con lo pendiente precargado y editable.

Tres cosas que no están en ninguna otra pantalla, porque ninguna otra gasta algo
que no se recupera:

- **los folios se dicen antes**, no después de teclear el documento, y la
  confirmación nombra el folio que va a gastar;
- **el precio no tiene campo**. Lo pone la nota de venta; un campo desactivado
  invita a pelearse con él, y no tenerlo dice mejor que no es una decisión de
  quien factura;
- **necesita señal**, a propósito. Un documento tributario no se guarda en una
  bandeja de salida: el folio lo reparte Softland y el número tiene que ser el
  mismo para siempre desde que se emite.

**Contar folios no es contar los que no están usados.** El repartidor
(`DTE_pdblEntregaFolioDTE`) va hacia adelante: entrega el siguiente al último
usado y devuelve −1 si ningún CAF lo cubre. No rellena huecos, y en INNOVAGES
hay 33 folios de rangos viejos sin rastro que no va a repartir jamás. Contarlos
daba **38** donde la realidad es **uno** — un número de más que habría hecho
planificar el mes con folios que no existen. Se cuenta avanzando desde el último
usado *dentro de algún CAF*, que es literalmente lo que hará el repartidor.

**Y «facturado 5 de 12» ya dice la verdad.** Ese avance se leía de
`nw_detnv.nvCantFact`, que está en cero en las 3.824 líneas de cada empresa: la
ficha decía «facturado 0 de 12» desde siempre. Ahora sale de sumar las líneas de
factura vigentes que apuntan a cada línea, menos lo que devolvieron las notas de
crédito.

**Comprobado** a 360 px en las tres escalas de densidad (0,92 · 1 · 1,1): el
aviso con su lista, la fila de acciones —que se desplaza, como todas— y la
etiqueta «A medias» junto a la del estado.

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
