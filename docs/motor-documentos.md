# Motor de documentos comerciales

Cómo se dibuja el papel que ve el cliente: cotización y nota de venta hoy,
factura, boleta, guía y nota de crédito cuando llegue la fase 4.

La regla que ordena todo lo demás: **un tipo documental es un dato, no una
plantilla**. Siete tipos comparten el noventa por ciento del papel y se
distinguen en dos o tres bloques; duplicar la plantilla siete veces es
garantizar que un día la dirección de la empresa salga distinta en la factura y
en la guía.

## Las piezas

```
app/Services/Documentos/
  TipoDocumento.php    enum: cómo se titula, qué bloques lleva, si emite DTE
  Motor.php            arma el contexto, elige los bloques y dibuja
  Identidad.php        la empresa emisora: datos, logo, color, condiciones
  Emision.php          congela lo emitido y lo versiona

resources/views/documentos/
  base.blade.php       la hoja A4: márgenes, cabecera fija, pie fijo
  bloques/             cliente · detalle · totales · condiciones · notas
                       vendedor · despacho
```

Agregar un tipo de documento es agregar un `case` al enum y, si trae un bloque
que no existe, una plantilla en `bloques/`. No hay controlador nuevo ni
plantilla completa nueva.

## Dónde se dibuja, y por qué ahí

**En el servidor, con caché en el teléfono.** No es una preferencia:

1. El número del documento lo asigna el servidor. Una cotización creada sin
   señal todavía no tiene `CotNum`, y un PDF que dice «Cotización N° —» no es un
   documento comercial, es un borrador.
2. Dibujarlo en el teléfono obligaría a mantener una segunda plantilla en
   JavaScript. Es el riesgo que `Totales.php` y su copia en `documentos.js` ya
   obligan a vigilar a mano — y allá la duplicación era inevitable, porque el
   vendedor tiene que ver el total antes de grabar. Aquí no lo es.

El teléfono (`mobile/src/pdf.js`) **guarda los bytes que le llegaron** en
IndexedDB y los vuelve a abrir sin señal. Nunca dibuja: archiva. Se queda con
los últimos 50 por uso, unos 3 MB.

## Identidad corporativa: Softland propone, la configuración dispone

Casi toda la ficha ya está en `softland.soempre` — RUT, razón social, giro,
dirección, comuna, ciudad, fono y sitio web. Cada campo tiene ese valor heredado
y un **override opcional** en `ventas.config`, clave `identidad`. Campo vacío =
manda Softland.

El override no es capricho: el PDF que los vendedores usaban lleva en el pie
«Avda. Los Carreras 1865, Concepción» y `soempre.Dire` dice «Ensenada 2332, Los
Ángeles». Las dos son ciertas — una es el domicilio tributario y la otra la
oficina comercial.

La consecuencia buena es que **una instalación nueva funciona sin configurar
nada**: el día uno el documento sale con el RUT y la razón social correctos, sin
logo. Eso es lo que hace replicable esto a otra empresa Softland.

### El logo

Vive en `storage/app/private/identidad/logo.png`: fuera de git, fuera de
`public/` y fuera de Softland. Se sirve por `GET /api/identidad/logo`, con
token, y nunca por Apache — un archivo subido por un usuario que además quede en
una ruta que el servidor web interpreta es la receta clásica de la ejecución
remota.

La validación que de verdad cierra el caso no es la extensión ni el MIME: es que
**se decodifica la imagen y se guarda otra**. Lo que se almacena es un PNG nuevo
dibujado a partir de los píxeles del original, así que lo que venga escondido en
los metadatos, en un comentario del PNG o pegado después del fin de la imagen no
sobrevive al viaje. De paso se normaliza el formato y se reduce a 600 px de
ancho, que es lo que cabe en A4.

Entra al PDF incrustado en base64, porque dompdf corre con
`isRemoteEnabled = false` a propósito: un `<img src="http://…">` que no responde
bloquearía el envío del correo.

## Snapshot: el archivo es la memoria

`ventas.documento_emision` guarda, por versión: el PDF tal cual se entregó, el
contexto con que se dibujó (en JSON), la huella, la fecha y quién.

**La regla que evita la complejidad: una versión que ya salió no se toca nunca
más.** Corregir un documento emitido crea la siguiente; la anterior queda. Una
versión que se generó y nunca se envió — la vista previa, el vendedor que miró y
cerró — sí se reemplaza: nadie la tiene.

Y si el documento no cambió, no hay versión nueva: abrir tres veces la vista
previa de la misma cotización deja una sola fila.

> **La huella es del HTML, no del PDF.** Dompdf estampa la fecha de creación
> dentro del archivo, así que dos PDF del mismo documento generados con un
> segundo de diferencia tienen bytes distintos. El HTML sí es estable: si dice
> lo mismo, es el mismo documento.

## Multipágina

Dompdf es un motor de CSS 2.1 — no entiende flex ni grid — pero sí entiende las
cuatro cosas que hacen falta:

| Necesidad | Cómo |
|---|---|
| Cabecera y pie en todas las páginas | `position: fixed` dentro del margen de `@page` |
| Encabezado de tabla repetido | `<thead>`: dompdf lo repite al partir la tabla |
| «Página 1 de 3» | `Canvas::page_text` **después** de `render()` |
| Que una fila no se parta | `page-break-inside: avoid` en cada `<tr>` |

El paginado va después de `render()` y no en la plantilla a propósito: dentro
del HTML sólo se puede escribir con un bloque `<script type="text/php">`, que se
ejecuta mientras se maqueta, cuando dompdf todavía no sabe cuántas páginas
saldrán. El resultado era un «Página 1 de 1» en un documento de dos, y sólo en
la primera hoja. De paso, así no hace falta `isPhpEnabled`.

**La letra no se achica para que quepa.** Si hay sesenta líneas, hay tres
páginas. Si hay que ganar espacio se gana en la separación, no en el cuerpo.

## Cómo llega el documento al cliente

| Camino | Cómo |
|---|---|
| **Correo** | `POST /{documento}/{n}/enviar`. El PDF va **adjunto**, no enlazado |
| **WhatsApp** | Hoja de compartir de Android con el archivo y el texto escrito |
| **Impresión, Drive, Bluetooth** | La misma hoja de compartir |

> **`wa.me` sólo transporta texto.** No existe forma de adjuntar un archivo por
> un enlace de WhatsApp; lo que hace `soporte.js` — abrir el chat con un mensaje
> escrito — es todo lo que ese camino permite. Para que el PDF viaje hay que
> pasarle el archivo al sistema y dejar que el vendedor elija WhatsApp y el
> contacto. Son dos toques y es una limitación de WhatsApp, no de la app.

Enviar un **enlace** al PDF del servidor queda descartado, y conviene que se
sepa por qué: `192.168.1.55:8086` no existe fuera de la oficina, y el cliente
está fuera de la oficina. Es el mismo razonamiento por el que el correo lleva el
PDF adjunto.

Cuando el documento sale por un camino que el servidor no ve, el teléfono manda
un acuse (`POST /{documento}/{n}/compartido`). No es telemetría: es lo que deja
esa versión marcada como entregada, y con eso una corrección posterior genera
una versión nueva en vez de pisar la que tiene el cliente.

### Si algún día hace falta mandarlo desde el servidor

WhatsApp Business Cloud API, de Meta. Exige cuenta de empresa verificada, un
número dedicado que deja de poder usarse en la app normal, plantillas aprobadas
y pago por conversación. Es una decisión de negocio, no técnica.

## Factura, boleta, guía y nota de crédito

Están declaradas en el enum y `emiteDte()` devuelve `true`. Sus bloques propios
— el timbre PDF417, la leyenda de la resolución — no están escritos, y es a
propósito.

**Este PDF no reemplaza al DTE.** Para cotización y nota de venta, lo que genera
el motor es el documento y punto. Para los otros cuatro, el documento legal es
el XML firmado y aceptado por el SII, y el PDF es su representación impresa: el
timbre sale de ese XML. Cuando llegue la fase 4, el motor dibujará el PDF **a
partir del DTE ya emitido**, nunca al revés.

## Lo que el motor todavía no resuelve

- **Sólo dibuja el IVA porque sólo se calcula el IVA.** El bloque de totales
  pinta los impuestos que vengan en `NWCtImpto`/`NW_Impto`, así que el día que
  se calcule el ILA entra como una fila más sin tocar la plantilla. Lo que falta
  es el dato, no el papel.
- **Flete y embalaje no aparecen** por lo mismo: se escriben en cero y ninguna
  de las 2.350 cotizaciones de INNOVAGES los usa.
- **El envío por correo no se ha visto salir**: no hay SMTP configurado. El PDF
  se genera, se adjunta y queda en la bitácora; lo que falta es el último tramo.
