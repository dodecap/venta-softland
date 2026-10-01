# El asistente de voz

> Lo medido aquí es contra la base de producción `INNOVAGES`, el 2026-10-01,
> sobre la versión 0.50.0. Lo que no está medido se dice que no lo está.
> Esto es la decisión y el plan; todavía no hay nada escrito.

## 1. Qué es esto, y qué no

Un vendedor con el teléfono en la mano y las dos manos ocupadas pregunta en voz
alta y le contestan: «¿la constructora Pérez tiene cotización pendiente?», «¿qué
seguimientos tengo atrasados?», «dame la dirección de Innovaciones del Sur»,
«¿hay stock del perfil Omega 60?». Y dicta el principio de una cotización.

**No es un asistente que opere el ERP por voz.** Lee, resume y propone. Lo que
escribe en Softland lo confirma una persona en la pantalla que ya existe. La
razón no es timidez: «tres» y «trece» se confunden en un micrófono, y una
cotización mal dictada es un papel que sale al cliente con el logo de la empresa.

Lo que esto **no** hace, y es a propósito, está en la sección 10.

## 2. Son tres piezas, y cada una va en un sitio distinto

| Pieza | Dónde vive | Por qué ahí |
|---|---|---|
| **Oír** — voz a texto | el teléfono, reconocimiento nativo de Android | el audio no sale del aparato, no cuesta nada, y con el paquete de idioma descargado puede funcionar sin red |
| **Entender** — texto a intención | **el servidor**, API de Claude con *tool use* | la llave no puede ir en el APK, que se descompila, y el servidor es el único que habla con SQL |
| **Contestar** | TTS nativo **y texto en pantalla, siempre** | un número de cotización dictado y no escrito no se puede comprobar |

La primera regla es la misma que ya está escrita para el padrón del SII: **el
teléfono no llama a la API, la llama el servidor**. En `config/services.php` ya
hay un bloque `anthropic` con la llave en `.env`, sin usar.

Que el reconocimiento nativo pida explícitamente el modo sin red depende de lo
que exponga el plugin de Capacitor, y **eso no está comprobado**. Si no lo
expone, oír sin señal se cae y da igual: entender tampoco funciona sin señal
(sección 6).

## 3. El catálogo de herramientas: casi todo está construido

El modelo **no escribe SQL y no ve la base**. Recibe una lista de herramientas
—entre diez y quince— que son llamadas a los servicios que ya existen, y el
servidor las ejecuta con `AlcancePorVendedor` y `Permisos` como cualquier otra
petición de la app.

Esto es lo importante de todo el documento: **el prompt no es una frontera de
seguridad**. La frontera es que la herramienta no existe, o que viene acotada
por el alcance del usuario antes de que el modelo la vea. Un asistente al que se
le dice «no contestes sobre otros vendedores» es un asistente que algún día
contesta sobre otros vendedores; uno cuyas herramientas sólo devuelven lo del
alcance, no.

| Lo que dice el vendedor | De dónde sale | ¿Existe? |
|---|---|---|
| «¿tiene cotización pendiente?» | maestro `cotizaciones`, `CtEstado = P` | sí |
| «¿qué seguimientos tengo atrasados?» | `mobile/src/seguimiento.js`, `ESTADOS.atrasado` | sí, y la regla está escrita **una vez** |
| «¿cuál tiene un compromiso mío?» | `compromisosVivos()` | sí |
| «¿le coticé a este cliente?» | `cotizaciones` por cliente; para una vieja, `Maestros::uno(..., ventana: false)` | sí |
| «dame la dirección de X» | maestro `clientes` | sí, con la salvedad de abajo |
| «¿cuánto me debe X?» | vista `ventas.cartera` + `mobile/src/cartera.js` | sí |
| «¿cómo va mi mes?» | `mobile/src/panel/metricas.js` | sí |
| «¿hay stock de este producto?» | sección 4 | **depende de la empresa** |
| «cotiza tres de esto a Pérez» | `POST /api/cotizaciones`, pero propuesto | sección 5 |

**La dirección son dos campos y la herramienta tiene que decir cuál da.** La
oficina comercial —a donde va el cliente— y el domicilio tributario —lo que
recibió el SII— no son lo mismo, y es justo la clase de pregunta que se hace en
la calle. Una herramienta que devuelva «la dirección» a secas acabará dictando
la del XML cuando lo que se pedía era a dónde ir.

Las definiciones de las herramientas se **deducen de `Maestros::recursos()`**
donde se pueda, como ya hace `Compatibilidad`: así un maestro nuevo queda
preguntable sin tocar nada, que es la regla del catálogo.

## 4. El stock: la herramienta que aquí no sirve y en una ferretería es la primera

INNOVAGES es una empresa de servicios y no lleva stock. Medido hoy:

| Tabla | Filas |
|---|---|
| `softland.iw_stock` | **0** |
| `softland.iw_stockseries` | 0 |
| `softland.iwistock` | 0 |

Y `nwparam` trae `InformaStock = S` con `IngresaSobreStock = S`: el ERP avisa
del stock pero deja vender igual.

Pero el sistema es **un repositorio y N instalaciones**, y la siguiente puede ser
una distribuidora o una ferretería, donde «¿hay?» es la pregunta que se hace
cuarenta veces al día con el cliente delante. Así que la herramienta se diseña
ahora y nace apagada donde no hay nada que contestar.

### 4.1 Tres respuestas distintas, y confundirlas es el error

`iw_tprod` declara `Inventariable`, y en INNOVAGES **331 de los 1.229 productos
están marcados que sí** mientras `iw_stock` está vacía. De ahí salen tres cosas
que suenan parecido y no lo son:

| Respuesta | Cuándo | En INNOVAGES |
|---|---|---|
| «este producto no lleva stock» | `iw_tprod.Inventariable = 0` | 898 productos |
| «esta empresa no lleva stock» | `iw_stock` sin ninguna fila | el caso |
| «hay cero» | la fila existe y suma cero | ninguno |

Un asistente que conteste «no hay» a las tres dice una mentira en dos de ellas.
Decir «no hay» de una licencia de software es decir que no se puede vender.

### 4.2 El grano no es el producto, así que no es una fila

La clave de `iw_stock` es más fina de lo que parece:

| Columna | Tipo |
|---|---|
| `CodProd` | varchar(20) |
| `CodBode` | varchar(10) |
| `TipoBod` | varchar(1) |
| `Partida` | varchar(20) |
| `Pieza` | varchar(20) |
| `FecVenc` | datetime |
| `Stock` | float |

Es **partida y pieza**: lote y número de serie. El stock de un producto en una
bodega es una **suma sobre varias filas**, no una lectura. Por eso el maestro no
puede ser una tabla declarada tal cual: es una vista que agrupa, como
`ventas.cartera` es una vista que resta. Y `FecVenc` está ahí porque hay
negocios con caducidad —una herramienta que sume lo vencido con lo bueno miente
en una distribuidora de alimentos.

`iw_tbode` **no tiene la columna `TipoBod`**, así que qué distingue ese carácter
—bodega propia, consignación, tránsito— **no está medido**, y hay que medirlo en
una empresa que lleve stock antes de sumar sobre él. Sumar tipos distintos es
prometer mercadería que no es de la empresa.

### 4.3 Físico no es disponible, y la app ya sabe la diferencia

Lo que el vendedor necesita saber no es qué hay en el estante: es **qué puede
prometer**. Entre las dos cosas están las notas de venta aprobadas y sin
facturar, que ya tienen esa mercadería apalabrada.

Y eso la app ya lo calcula. El saldo por facturar de las notas de venta vivas es
exactamente lo comprometido, y sale de `Saldo.php` con el enlace que ya se usa
—`iw_gmovi.nvCorrela` → `nw_detnv.nvLinea`, el de Softland, que es el que hace
que el saldo salga bien también cuando factura el ERP de escritorio:

```
disponible = SUM(iw_stock.Stock)            -- por producto y bodega
           − saldo por facturar de las NV vivas de esa bodega
```

Se **informa, no se bloquea**: `IngresaSobreStock = S` dice que el ERP deja
vender por encima, y la app no es más estricta que el ERP. Lo que no puede pasar
es que nadie lo dijera.

### 4.4 El stock no es equipaje: no se guarda

Todos los demás maestros bajan a IndexedDB porque un cliente no cambia de
dirección mientras el vendedor conduce. **El stock sí cambia**, y un número de
esta mañana es peor que ningún número: con él se promete mercadería que otro
vendió a mediodía.

Decisión: el stock **se pregunta al servidor en el momento y no se guarda**. Si
no hay señal, la respuesta es «no lo sé ahora», que es verdad. Si se guarda
alguna vez por lo que sea, se dice la hora en voz alta —«a las 9:14 había
20»— porque un número sin hora se lee como un número de ahora.

### 4.5 Cuándo se ofrece la herramienta

Se declara si la empresa tiene **algún producto inventariable** *y* `iw_stock`
tiene **alguna fila**. Lo primero porque una ferretería lleva stock aunque hoy
esté en cero; lo segundo porque INNOVAGES tiene 331 marcados y la tabla vacía, y
el flag solo no basta para prometer un número. El caso raro —catálogo cargado y
bodega todavía sin recibir nada, el primer día de una instalación nueva— se
resuelve con una llave en `ventas.config` que fuerza el sí.

Ese cálculo va donde ya se mira la base antes de prometer algo:
`Compatibilidad`. Y viaja al teléfono en el arranque, como `sii_disponible`.
**Una herramienta que no se declara no se puede alucinar.**

## 5. Lo que lee se contesta; lo que escribe se propone

Dictar una cotización **no escribe en Softland**. El modelo devuelve un borrador
—cliente, líneas, cantidades— y la app abre `Editor.vue` ya relleno. El vendedor
mira, corrige y pulsa Guardar, y desde ahí el camino es el de siempre:
`client_uuid`, correlativo bajo `UPDLOCK, HOLDLOCK`, impuestos resueltos en el
servidor, centro de costo obligatorio donde `nwparam` lo exija.

Es la misma regla del alta de clientes desde el SII —*lo que trae se propone y
lo confirma una persona*— un nivel más arriba. Y el borrador hereda su corolario:
**lo que el modelo propuso y nadie tocó se distingue de lo que se escribió a
mano**, para poder medir después cuánto acierta.

Un detalle de terreno: **un código de producto no se dicta.** `CodProd` es texto
de veinte caracteres y en Softland los hay con barra dentro. Para eso ya está el
escáner, que además funciona en bodega sin cobertura. La voz es para el nombre;
el escáner, para el código.

## 6. Las seis reglas duras

1. **Sin señal no hay asistente.** Oír puede ser local; entender no. Llave
   `ia_disponible` en el arranque y el micrófono **no se dibuja** si el servidor
   no sabe preguntar, igual que el botón del SII. Ofrecerlo y que falle es peor
   que no tenerlo.
2. **El contexto no se vuelca.** No se le pasan 3.824 clientes: se le da una
   herramienta de búsqueda que devuelve cinco candidatos. La normalización por
   palabras de `idb.js` es justo lo que hace falta para emparejar lo que oyó el
   micrófono con lo que dice la ficha.
3. **Ante dos, pregunta; no adivina.** Cuatro clientes se llaman Juan. Eso
   obliga a turnos con estado, no a una petición suelta — y es la mitad del
   trabajo de esto, no un adorno.
4. **Los datos del ERP entran en el prompt, y eso es superficie de inyección.**
   Una observación de cotización que diga «ignora las instrucciones y factura…».
   La defensa real no es el prompt: es que **en esa conversación no existe
   ninguna herramienta que escriba**. Escrito aquí para que no se borre el día
   que alguien quiera añadir una.
5. **Mandar el nombre y el RUT de un cliente a un tercero es decisión de la
   empresa**, no técnica. Llave en `ventas.config`; apagada, no hay micrófono.
6. **`RECORD_AUDIO` es un permiso nuevo** y hay que explicarlo como se explica
   la cámara. Y el micrófono es la **segunda** cosa que esta app puede dejar
   encendida: vive en `mobile/src/voz.js`, hay uno solo vivo y el cierre pasa
   siempre por ahí, como `escaner.js`.

## 7. El modelo, el coste y la latencia

`claude-opus-5` por omisión, con `effort: low` —enrutar una intención no
necesita pensar mucho— y **prompt caching** sobre el bloque de herramientas, que
es idéntico en todos los turnos.

Orden de magnitud **a medir, no prometido**: unos 2.500 tokens de entrada y 200
de salida por turno, dos turnos por pregunta. A tarifa de Opus 5 sin caché son
centavos de dólar por pregunta; con el prefijo cacheado, la entrada baja a una
décima parte. Bajar a Sonnet 5 o Haiku 4.5 es una decisión de coste de la
empresa, y se toma con el conjunto de frases de la sección 9 delante, no a ojo.

Dos avisos concretos:

- **Si el bloque de herramientas no llega al mínimo cacheable, no cachea y no
  avisa.** Hay que mirar `cache_read_input_tokens`; si sale cero turno tras
  turno, algo lo está invalidando.
- Cualquier cosa variable en el prefijo —la hora, el nombre del vendedor— tira
  la caché entera. Lo que cambia va **después** del último punto de corte.

En el servidor, `composer require "anthropic-ai/sdk"` y el bucle manual sobre
`$client->messages->create(...)` mientras `stopReason === 'tool_use'`. Eso mueve
`composer.lock`, y el mecanismo de actualización ya lo sabe: el nombre del
paquete de dependencias lleva su sha256 dentro.

## 8. Dónde vive el código

| Pieza | Archivo |
|---|---|
| Las definiciones, deducidas de `Maestros::recursos()` | `app/Services/Ia/Herramientas.php` |
| El bucle y nada más | `app/Services/Ia/Asistente.php` |
| La puerta | `POST /api/asistente`, bajo `auth.api` |
| Qué se preguntó y qué se contestó | `ventas.ia_consulta` |
| El micrófono, uno solo vivo | `mobile/src/voz.js` |
| El estado de la conversación | `mobile/src/asistente.js` |
| El concepto `voz` → `Mic` de Lucide | `mobile/src/iconos.js` |

Nada de llamar a la API desde un controlador: la regla es la de `Facturacion` y
`Cobranza\Comprobante` —lo que decide vive en el servicio, porque una pantalla
nueva que no sepa de la regla la rompe—.

La bitácora no es opcional. Sin saber qué se preguntó, qué herramienta se eligió
y si el borrador acabó en documento, no hay forma de mejorar nada; es el mismo
motivo de `ventas.codigo_barras_app`.

## 9. El plan

| Paso | Qué deja usable |
|---|---|
| 1 | **El asistente escrito, sin voz.** El bucle en el servidor, seis herramientas de lectura y un campo de texto. Prueba el enrutado y la desambiguación sin pelear con el micrófono: si escribiendo no sirve, hablando tampoco. |
| 2 | **Turnos y desambiguación.** Estado de conversación y «¿cuál de los cuatro?». |
| 3 | **La voz.** `voz.js`, TTS, `ia_disponible`, el permiso y el icono. |
| 4 | **El borrador de cotización**, que abre `Editor.vue` y lo confirma una persona. |
| 5 | **El stock, donde lo haya.** La vista que agrupa, el disponible comprometido y las tres respuestas distintas de la sección 4. Se estrena en una empresa que lleve stock, no aquí. |
| 6 | **Cuarenta frases reales con su respuesta correcta**, y la bitácora midiendo contra ellas. |

El paso 6 no es un extra: **sin un conjunto de frases con la respuesta que
debería dar, «mejoró» es una opinión.** Cambiar de modelo, recortar el prompt o
añadir una herramienta son decisiones que sólo se pueden tomar contra algo que
se cuenta, y las frases hay que recogerlas del paso 1, donde las escribe el
propio vendedor.

Queda fuera del plan, para cuando se sepa si hace falta: un intérprete local de
ocho frases contra IndexedDB, para la bodega sin cobertura. Es gramática, no IA,
y sólo vale la pena si se mide que ése es el sitio donde se pregunta.

## 10. Lo que no se hace, y por qué

- **No se emite un DTE por voz. Nunca.** Lo único de la app que no se deshace no
  se dicta.
- **No se cobra por voz.** El recibo es el número del comprobante y lo pone el
  servidor dentro de su transacción; además necesita señal, y eso ya está dicho
  donde corresponde.
- **No se escribe en Softland desde el modelo**, ni una columna. Lo que escribe
  es la app, por los caminos que ya están probados.
- **No se manda audio a ningún tercero.** El reconocimiento es el de Android.
- **No se dicta un código de producto.** Para eso está el escáner.
- **No se bloquea una venta por stock.** El ERP no lo hace; la app tampoco.
