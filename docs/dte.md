# Emisión de documentos tributarios electrónicos

Lo que hay que saber antes de tocar nada de `app/Services/Dte/`.

## De dónde salió la decisión de emitir nosotros

Se averiguó primero si había un camino soportado, y no lo hay:

- **No existe una API REST oficial de Softland en esta instalación.** La app
  oficial (`cl.softland.ventascrmmobile`) usa Refit contra una API que aquí no
  está: en `srv` no hay ningún componente de servidor de Softland escuchando.
- **`IWSerDTE.exe` no emite: recibe.** Su propio `.ini` lo dice — captura los
  correos que traen DTE de proveedores. El emisor son formularios VB6 dentro de
  `IWS.EXE` (`soFDTES.exe`, `soSIIEE.exe`), que se abren desde el menú. No hay
  ejecutable que invocar con parámetros.
- **La cola por carpeta no está montada.** `Softland.DteEncolar.config` apunta a
  `ServicioEnvioDTEs`, que en esta instalación no existe.

Hoy factura una persona desde el Softland de escritorio, y el DTE sale entre 1 y
5 minutos después. Ese hueco es alguien haciendo clic.

## Hasta dónde llega esta app

Hasta **inventario y facturación, con el DTE emitido**. Ni un paso más.

La centralización —pasar el documento a contabilidad, al registro de ventas y a
la cuenta corriente del cliente— es un **procedimiento aparte** que se corre
desde el propio Softland, y no ocurre al emitir el DTE. No se reproduce aquí.

## Los tres nombres de un mismo documento

Traducir entre ellos es la mitad del trabajo, y el mapa no está donde uno lo
busca. `cwttdoc.DTEDocSII` trae el código del SII para los documentos de
**compra** y lo deja **vacío** justo para los de venta. El mapa bueno vive en
`dte_siitdoc`, indexado por `(Tipo, SubTipoDocto)` — las mismas dos columnas con
las que `iw_gsaen` guarda el documento. Está escrito en `TipoDte`.

| SII | Softland | Documento | Transporte |
|---|---|---|---|
| 33 | `F` + `T` | Factura electrónica | SOAP |
| 34 | `F` + `S` | Factura exenta electrónica | SOAP |
| 39 | `B` + `T` | Boleta afecta electrónica | **REST** |
| 41 | `B` + `S` | Boleta exenta electrónica | **REST** |
| 61 | `N` + `T` | Nota de crédito electrónica | SOAP |

**La boleta no viaja por donde la factura.** Factura y nota de crédito van por
SOAP a `palena.sii.cl/DTEWS/`; la boleta va por `api.sii.cl/recursos/v1`, con
otra autenticación y otro formato, y además obliga a un reporte diario de
consumo de folios (RCOF) que la factura no pide. Son dos integraciones. Las URLs
de los dos ambientes salieron de `Softland.Sii.config`, en `C:\SOFTLAND\PROGRAMA\IWSERDTE\`.

## El folio lo reparte Softland

No se calcula por nuestra cuenta: se llama al procedimiento almacenado
**`DTE_pdblEntregaFolioDTE(@TipoDTE, @GuardaFolio)`**, que es el mismo que usa
el ERP. Toma el mayor folio entre `dte_doccab` y `iw_gsaen`, recorre los rangos
del CAF en orden, salta los tramos anulados y, con `@GuardaFolio = 1`, **reserva
el folio** insertándolo en `dte_doccab`. Devuelve `-1` si no queda ninguno.

Por dentro es `MAX + 1` sin bloqueo, así que se llama dentro de una transacción
y con reintento ante choque, igual que el correlativo de la nota de venta.

Un folio gastado no se devuelve: ante el SII, lo emitido está emitido.

## El timbre se firma en bytes, no en árboles

Es la trampa de todo el asunto, y la razón de que `Timbre` arme el XML
concatenando en vez de usar `DOMDocument`. La firma del `<FRMT>` cubre el
elemento `<DD>` **tal como está escrito en el archivo**. Un serializador que
reordene un atributo, cambie comillas o agregue saltos de línea produce un
documento que el SII rechaza aunque el contenido sea idéntico.

Las cinco reglas que costaron descubrirse, todas comprobadas contra documentos
reales:

1. **Todo va en ISO-8859-1**, que es lo que declara el XML del SII. Firmar el
   acento en UTF-8 y escribirlo en ISO-8859-1 da una firma que no valida, y el
   error aparece recién cuando contesta el SII.
2. **El `<CAF>` se incrusta pegado.** En `dte_siicaf.CAFXML` está guardado con
   espacios entre elementos; dentro del DTE va sin ellos. Se colapsa solo el
   espacio entre `>` y `<`: el de dentro del texto es contenido.
3. **El `MNT` va sin signo.** En `iw_gsaen` el total de una nota de crédito es
   negativo; en el timbre va positivo. Las 12 notas de crédito de INNOVAGES
   fallaban todas por esto.
4. **`RSR` e `IT1` se recortan a 40 caracteres**, que es el largo que fija el
   SII.
5. **`IT1` sale del detalle del DTE, no del maestro de productos.** En el folio
   62 el producto se llama «COMISION SOFTWARE» en `iw_tprod` y la factura dice
   «Empresa adicional Cloud ERP anual». Quien factura escribe la glosa que el
   cliente necesita leer, y el timbre sella esa glosa.

Y una que no es regla sino dato de origen: **el `TSTED` no se deriva de nada**.
Es el instante en que se timbra.

## La llave privada no sale de la base

`Caf::firmar()` es lo único que se expone. No hay método que devuelva la llave,
y es a propósito: quien la lea puede timbrar documentos a nombre de la empresa.
Vive en `dte_siicaf`, se carga en memoria dentro del servidor y se firma ahí.
Nunca se escribe a un archivo, nunca se registra en un log, nunca viaja al
teléfono.

Un detalle de formato que cuesta una tarde: Softland la guarda como base64
**con restos del PEM pegados** — en INNOVAGES empieza por un guion suelto,
`-MIIBOwIBAAJBA…`. Un guion no es base64 y OpenSSL se planta sin explicar por
qué. `Caf` prueba las tres formas conocidas.

## De dónde sale el cliente

**`iw_gsaen` no copia el nombre ni el RUT del receptor.** Guarda solo su código
en `CodAux` y los resuelve por join contra `cwtauxi`. Es al revés de lo que uno
esperaría de un documento tributario —que suele congelar los datos del
receptor— y explica por qué corregir la ficha de un cliente cambia lo que
muestra una factura vieja.

En boleta sin cliente identificado, el receptor es `66666666-6` y la razón
social el literal **`Cliente Generico`**, sin tilde, que es como lo escribe
Softland y como aparece en los ejemplos del SII. Una tilde de más cambia los
bytes y, con ellos, el timbre.

## Comprobar sin emitir

```bash
ssh srv "cd C:\xampp\htdocs\venta-softland && C:\xampp\php\php.exe artisan dte:verifica-timbre --todos"
```

`dte:verifica-timbre` toma documentos que Softland **ya emitió** y el SII **ya
aceptó**, recalcula su timbre y compara. No emite, no escribe y no gasta un
folio. Responde tres preguntas distintas:

- **firma** — firmando el `<DD>` guardado, ¿da el mismo `<FRMT>`? Prueba la
  criptografía. RSA con relleno PKCS#1 v1.5 es determinista: la misma entrada
  tiene que dar la misma salida.
- **construcción** — armando el `<DD>` desde `iw_gsaen` / `iw_gmovi`, ¿da la
  misma cadena? Prueba el mapeo de campos.
- **verificación** — ¿la firma guardada valida contra la llave pública del
  propio CAF? Control independiente de los otros dos.

Resultado al cerrar el paso 1, el 2026-09-15:

| Tipo | Base | Documentos | Resultado |
|---|---|---|---|
| 33 factura | INNOVAGES | 198 | idénticos |
| 61 nota de crédito | INNOVAGES | 12 | idénticos |
| 34 factura exenta | NETDOMAIN | 326 | idénticos |
| 61 nota de crédito | NETDOMAIN | 73 | idénticos |
| 39 **boleta** | NETDOMAIN | 6 | idénticos |

615 documentos, cinco tipos, **dos empresas con RUT y certificado distintos**,
de 2009 a 2026.

INNOVAGES no ha emitido nunca una boleta. La única contra la que se puede
contrastar ese camino está en **NETDOMAIN**, su matriz: folio 2 del 2021-07-09,
nacida de la nota de venta 1534, TrackID `1292919449`. Por eso el comando acepta
`--base`. Es de **solo lectura**: a NETDOMAIN no se le escribe jamás.

### Dos cosas que el comando descubrió de Softland

- **Hay copias archivadas recodificadas.** El folio 176 de INNOVAGES no valida
  contra su propio timbre: la copia de `dte_archivos` se volvió a codificar a
  UTF-8 después de firmarse. El documento que fue al SII estaba bien. El comando
  lo detecta devolviendo la cadena a ISO-8859-1, y lo dice. **El día que
  emitamos nosotros, ese mismo síntoma significaría otra cosa**: que estamos
  firmando en la codificación equivocada.
- **`dte_archivos` guarda versiones muertas.** Hay archivos con el mismo
  `NroInt` y folios distintos: son borradores anteriores. El folio que manda es
  el del propio timbre (`<F>`), no el de la columna.

## Escribir el documento en IW

`Facturacion` escribe `iw_gsaen` más `iw_gmovi`, y para ahí. **No centraliza**:
no toca `CpbAnoVentas`, `CpbNumVentas` ni ninguna columna `Cpb*` o `Contab*`, ni
`cwcpbte`, `cwmovim` o la cuenta corriente. Eso es otro procedimiento, se corre
desde Softland y ocurre después. De las 197 facturas, 190 tienen el pago
centralizado; ninguna lo tenía al nacer.

### La factura no es la nota de venta

Esto cambió el diseño y conviene que no se olvide. En INNOVAGES la factura casi
nunca es la proyección de su nota de venta:

| | |
|---|---|
| Facturas cuyo cliente **no** es el de la NV | **190 de 192** (188 a Softland Ingeniería) |
| Facturas de una sola línea | 196 de 197 |
| Lo que se factura | COMISION SOFTWARE (133), COMISION SERVICIO ASESORIA (43), COMISION SMS (19) |
| Líneas de NV con `nvCantFact > 0` | **ninguna** |
| Facturas nacidas convirtiendo una NV | **2 de 197** |

Es un negocio de distribuidor: la nota de venta registra la venta al cliente
final, y la factura le cobra la comisión a Softland, que es quien paga. El monto
tampoco se calcula —el 30 % es lo más común, pero el rango va del 0,5 % al 77 %,
y una misma NV genera varias facturas a porcentajes distintos—: viene de una
liquidación que manda Softland, y lo escribe quien factura.

Por eso no hay una función «facturar la nota de venta». Hay una que escribe el
documento que se le pida; la NV entra como **referencia** (`nvnumero`) y como
sugerencia de líneas y receptor, y el receptor se puede cambiar. El mismo camino
sirve para los tres casos: la comisión, la factura al cliente de la NV y la
factura suelta.

### Las reglas de la escritura

Salieron de reescribir 199 documentos reales y comparar las 168 columnas del
encabezado y las 62 de cada línea, una por una:

- **Los netos van redondeados a peso**, y el **IVA se calcula sobre el neto ya
  redondeado**. Con un neto de 2.314.102,5 eso vale un peso en el IVA y otro en
  el total.
- **El signo vive en la cantidad**, no en el precio: la nota de crédito lleva
  cantidad −1 y precio positivo.
- **`Equivalencia` en cero significa uno.** Hay una línea así cuyo total no es
  cero: para Softland un factor vacío es «misma moneda». Tomarlo literal deja la
  línea en cero.
- **`Equivalencia` del encabezado es 0 en la factura y 1 en la nota de crédito.**
  Sin lógica aparente; es lo que escribe.
- **El centro de costo va en la línea de la nota de crédito y no en la de la
  factura** — 9 de 10 contra 5 de 199.
- **`SubTipDocRef` hay que escribirlo nulo a propósito**: la columna tiene `'A'`
  por defecto, así que omitirla no la deja vacía.
- **Una nota de crédito sin referencia no es una nota de crédito.** Va dos
  veces: en `AuxDocNum`/`AuxDocfec`/`TipDocRef`/`SubTipDocRef` para la ventana de
  Softland, y en `IW_GSaEn_RefDTE` —con el código **del SII**, 33— para el XML.
  Más `esDevolucion = -1`, que es lo que la distingue de una venta con el signo
  cambiado.

**`Totales` no se tocó.** Su reparto entre afecto y exento se salta cuando el
bruto no es positivo, y una nota de crédito lo es siempre. Se calcula en positivo
y se aplica el signo al final: es lo mismo y no mueve las 200 cotizaciones contra
las que está contrastado.

### Comprobar sin tocar producción

```bash
ssh srv "cd C:\xampp\htdocs\venta-softland && C:\xampp\php\php.exe artisan dte:base-de-pruebas"
ssh srv "cd C:\xampp\htdocs\venta-softland && C:\xampp\php\php.exe artisan dte:verifica-documento --todos --limite=200"
```

`dte:base-de-pruebas` copia INNOVAGES entera —1.905 tablas con sus 24 triggers—
a `INNOVAGES_DTE`. Se niega a tocar nombres de producción, y `INNOVAGES_TEST`
está en esa lista: existe, pero es de otro proyecto.

`dte:verifica-documento` toma facturas reales, les saca sus datos de entrada,
le pide a nuestro código que las escriba en la copia y compara columna por
columna. **Escribe dentro de una transacción y la deshace**: así no queda el
documento ni —sobre todo— el folio consumido. De factura queda **uno solo
libre**, el 235; sin deshacer, la primera corrida se lo come.

| Tipo | Documentos | Idénticos |
|---|---|---|
| 33 factura | 189 | 141 |
| 61 nota de crédito | 10 | 8 |

Los que no son idénticos difieren **solo** en dos columnas donde Softland es
inconsistente consigo mismo: `CodiCC` de la línea, y el `NVCorrelaOC` en «0» que
dejó de escribir en agosto de 2024. El comando las separa de una regresión de
verdad, y tolera diferencias menores a un peso como lo que son: redondeo.

Quedan fuera las **2 facturas nacidas convirtiendo una nota de venta**, que van
por otro camino —arrastran los decimales de la NV en vez de redondear, y llevan
`Orden` y `nvCorrela`—. Ese camino todavía no está escrito: `--incluir-convertidas`
las muestra.

## Generar el XML del DTE

`Documento` arma el `<DTE>` a partir del documento **ya escrito** en `iw_gsaen`.
Ese orden no se invierte: primero existe el documento y su folio, después se
dibuja su XML.

`FirmaXml` pone la firma electrónica, y `Certificado` guarda el certificado de
la empresa —cosa distinta del CAF: el CAF timbra el folio, el certificado
acredita al emisor—.

### Por qué no se prueba contra maullin

Porque no se puede, y resulta que no hace falta.

El ambiente de certificación del SII **se cierra para el contribuyente** cuando
termina su proceso de certificación y firma la declaración de cumplimiento.
INNOVAGES lo cerró hace años: maullin ya no acepta su RUT, y tampoco habría CAF
de certificación con que timbrar allí.

El sustituto es mejor. En `dte_archivos` están los XML de **209 documentos que
el SII aceptó de verdad, en producción**. Reproducirlos no simula lo que el SII
habría dicho: usa lo que el SII efectivamente dijo.

### La firma cubre la forma canónica, no el texto

Es la idea de la que cuelga todo lo demás. La firma no cubre los bytes del
archivo sino su **forma canónica** (C14N). De ahí tres consecuencias prácticas:

- dos documentos escritos distinto pueden tener la misma forma canónica y por
  tanto la misma firma;
- **los comentarios no se firman** —pero los saltos de línea que los rodean, sí.
  Por eso el generador escribe un comentario de versión, como hace Softland: sin
  él el documento es el mismo y el resumen no;
- **el espacio entre elementos sí se firma**, así que hay que escribir un
  elemento por línea, con `\r\n`, igual que el ERP.

Y todo va en **ISO-8859-1**. Leer un acento como UTF-8 da otra forma canónica,
otra firma, y un rechazo del SII que aparece días después.

### Lo que cambia de un tipo a otro

| | Factura 33 | Exenta 34 | Boleta 39 | Nota de crédito 61 |
|---|---|---|---|---|
| Indicador en `IdDoc` | `TpoTranVenta` | `TpoTranVenta` | `IndServicio` | `TpoTranVenta` |
| Forma de pago y glosa | sí | sí | **no** | sí |
| Emisor | `RznSoc` / `GiroEmis` | igual | `RznSocEmisor` / `GiroEmisor` | igual |
| Receptor | con giro y dirección | igual | admite `66666666-6` | igual |
| Totales | `MntNeto`, `TasaIVA`, `IVA`, `MntTotal` | solo `MntExe` y `MntTotal` | solo `MntTotal` bruto | como la factura |
| Líneas | — | `IndExe` en cada una | — | — |
| Referencia | a la nota de venta, código 802 | igual | — | a la factura, **`CodRef` 1 «Anula Documento»** |

Ese último no está en ninguna columna: Softland lo deduce de que el documento
sea devolución, y aquí se deduce igual.

### Cosas que costaron encontrarse

Todas salieron de comparar contra documentos reales, ninguna de un manual:

- **Giro, comuna y ciudad son códigos en `cwtauxi`**, no nombres. El DTE los
  quiere escritos: «13123» es Providencia.
- **El correo del receptor es `eMailDTE`**, no `EMail`. Son dos columnas
  distintas y el intercambio va por la primera.
- **La empresa puede tener varios giros**: `soempre` guarda hasta cuatro
  `ACTECO` y van todos. INNOVAGES declara dos.
- **`NmbItem` es el nombre del producto** y `DscItem` la glosa de la línea. Los
  saltos de línea de la glosa se vuelven espacios.
- **El folio de referencia es texto**, no un número: hay órdenes de compra como
  «272-OC00008216». Convertirlo a entero daba 272.
- **`RazonRef` vive en la columna `Glosa`** de `IW_GSaEn_RefDTE`; la columna que
  se llama `RazonRef` está vacía en las 209.
- **Una décima se escribe `.1`, no `0.1`.** Los dos valen para el SII; se
  escribe como el ERP para que los documentos sean el mismo.
- **Hay copias archivadas recodificadas**, que no validan contra su propia
  firma. El comando las endereza antes de comparar y lo dice.

### El resultado

```bash
ssh srv "cd C:\xampp\htdocs\venta-softland && C:\xampp\php\php.exe artisan dte:verifica-xml --todos"
```

| Tipo | Base | Dicen lo mismo | Firma reproducida |
|---|---|---|---|
| 33 factura | INNOVAGES | **188 de 188** | **197 de 197** |
| 61 nota de crédito | INNOVAGES | **12 de 12** | **12 de 12** |
| 39 boleta | NETDOMAIN | 2 de 2 | — (otro certificado) |
| 34 factura exenta | NETDOMAIN | 102 de 125 | — (otro certificado) |

Los nueve documentos de INNOVAGES que no entran en la comparación difieren en
cosas conocidas: cinco porque el Softland de entonces no escribía
`CdgVendedor`, y cuatro porque el dato cambió en la base **después** de emitir
—la glosa de una línea, el código de un producto—. El documento que viajó al SII
decía lo que decía; regenerarlo desde la base de hoy no puede devolver lo que ya
no está.

**La firma se comprueba con el resumen guardado**, no con el nuestro: así se
mide una cosa sola —si con la misma entrada sale la misma firma— y no se
confunde un fallo de firma con un espacio de más en el documento. Sale idéntica
en los 209.

Los resúmenes no calzan en ninguno por una razón sola y verificada: Softland le
pega un espacio al final a la dirección del receptor, que en `cwtauxi` no lo
tiene. El SII valida el contenido, no ese espacio.

### Lo que el generador no sabe escribir todavía

- **`<DscRcgGlobal>`**, el descuento o recargo de pie. Ninguno de los 209
  documentos de INNOVAGES lo usa. El generador **falla en vez de ignorarlo**: un
  documento cuyas líneas suman una cosa y cuyo total dice otra es lo que el SII
  rechaza, y para entonces el folio ya se gastó.
- **`<RUTMandante>`**, la venta por cuenta de terceros. NETDOMAIN la usaba;
  INNOVAGES no vende así.
- **Documentos mixtos**, con líneas afectas y exentas a la vez. No hay ninguno
  en las dos bases, así que no hay contra qué comprobarlo. `IndExe` se decide
  hoy por el tipo de documento.

## Mandar el documento al SII

Generar el XML no emite nada. Lo que emite es subirlo, y eso ocurre en un solo
sitio: `Emision::emitir()`.

### El sobre no es el documento

Al SII no se le manda un DTE, se le manda un **envío** que lo contiene:

```
<EnvioDTE>
  <SetDTE ID="SS77828631-9SS033F0000000234">
    <Caratula>  quién manda, a quién, bajo qué resolución, cuántos van
    <DTE>       el documento, ya firmado
  </SetDTE>
  <Signature>   la segunda firma, sobre el <SetDTE> entero
</EnvioDTE>
```

**Tres RUT y ninguno es el cliente:**

| Campo | Quién | Aquí |
|---|---|---|
| `RutEmisor` | la empresa que factura | 77828631-9 |
| `RutEnvia` | la **persona** cuyo certificado firma | 17421371-2 |
| `RutReceptor` | el SII, siempre | 60803000-K |

El cliente va dentro del documento. Confundir los dos primeros es el rechazo más
típico y el mensaje del SII no ayuda a entenderlo.

El `RutEnvia` sale del certificado, y sacarlo cuesta: E-Certchile no lo pone en
un campo estándar sino en un `otherName` del `subjectAltName`, bajo el OID
`1.3.6.1.4.1.8321.1`, que PHP no sabe leer —devuelve literalmente
`othername:<unsupported>`—. Hay que buscar el OID en los bytes del certificado.
Ojo con la codificación: 8321 se escribe **`c1 01`**, no `c1 41`. Con el orden
cambiado no aparece y parece que la extensión no está. Hay un segundo RUT bajo
`8321.2`: es el de la entidad certificadora y no sirve.

### La regla que habría hecho rechazar todos los envíos

La firma cubre la **forma canónica**, y la canonicalización inclusiva arrastra
al elemento firmado **todos los espacios de nombres que tiene en ámbito**,
aunque los declare su abuelo:

| Qué se firma | Cómo se canonicaliza |
|---|---|
| `<Documento>` | **suelto**, sin espacios de nombres |
| `<SetDTE>` | **con** los de `<EnvioDTE>`: el predeterminado del SII y `xsi` |
| `<SignedInfo>` del sobre | con `xsi`, más el de XMLDSig que declara `<Signature>` |

Que el documento se firme suelto suena al revés: dentro del sobre sí hereda. La
explicación es que **el SII saca cada `<DTE>` del sobre y lo valida como
documento aparte**. Es lo que hace Softland y es lo que el SII aceptó 209 veces.

Esto no salió de un manual. Salió de probar las cuatro combinaciones contra los
sobres guardados hasta que una dio el resumen que el SII aceptó.

### El apretón de manos

1. se pide una **semilla**, que caduca en dos minutos;
2. se firma con el certificado —firma **envolvente**, `URI=""` y transformación
   `enveloped-signature`, que no es la del documento— y se canjea por un
   **token**;
3. el token viaja como cookie en cada llamada y dura unos minutos.

Pedir un token es la comprobación más barata que hay: **prueba la conexión, que
el certificado abre, que la firma vale y que quien firma está autorizado ante el
SII para este contribuyente, sin emitir nada ni gastar un folio.**

```bash
ssh srv "cd C:\xampp\htdocs\venta-softland && C:\xampp\php\php.exe artisan dte:token"
```

### Dos protocolos en la misma casa

La autenticación y las consultas van por **SOAP** a unos `.jws` que son
servicios Java de hace veinte años. El envío del sobre **no es una API**: es un
formulario multiparte a un CGI, la misma subida que hace el navegador en la
página del SII. Y la boleta no pasa por ninguno de los dos: va por la API REST.

El SII contesta 200 casi siempre, incluso cuando rechaza. Lo que cambia es el
cuerpo, así que aquí nada se decide mirando el código HTTP.

### El orden al emitir, y por qué es ese

1. se arma y se firma el sobre **antes** de hablar con el SII: si falta un dato,
   se sabe aquí y no a medio envío;
2. se manda;
3. **recién entonces** se escribe en la base.

Si se guardara antes y el envío fallara, quedaría un documento marcado como
enviado que no lo está. Al revés el riesgo es el contrario y es el menos malo:
un envío hecho cuya constancia no se pudo guardar. Para eso el error lleva el
`TrackID` delante — con él se recupera a mano— y dice **no reenviar**.

`dte_doccab` se queda con el `TrackID`, el `IDSetDTESII`, las marcas de enviado
y el `FirmaDTE`, que es el timbre que el ERP imprime como código de barras.
`dte_archivos` se queda con los dos XML: el documento (`D`) y el sobre (`SS`).

### Comprobar sin mandar

`dte:verifica-sobre` hace con el sobre lo que `dte:verifica-xml` hace con el
documento: lo regenera y lo compara con el que el SII ya aceptó. Dentro de
nuestro sobre va **el `<DTE>` original**, no uno regenerado, para medir una cosa
sola: la carátula y el envoltorio.

```
--tipo=33 --todos    dicen lo mismo 198 de 198    firma 198 de 198
--tipo=61 --todos    dicen lo mismo  12 de 12     firma  12 de 12
```

Tres sobres quedan fuera: son versiones muertas del archivo, filas cuyo folio ya
no es el que lleva el documento. Y 74 de los guardados están **recodificados a
UTF-8 después de firmarse**, así que hay que deshacerlo antes de comparar; el
ancla para decidirlo es el resumen del documento, que viene en el propio archivo
y es independiente de lo que se está midiendo.

`dte:envia` sin `--confirmar` arma el sobre, lo enseña y para. Es un ensayo
completo: se recorre todo el camino menos el último paso. Con `--base=` el
ensayo sale de la copia de pruebas, y entonces **crear el documento también es
gratis**: es la única forma de ejercitar la cadena entera —escribir en
`iw_gsaen`, pedir el folio, timbrar, armar el sobre y firmarlo— sobre un
documento que no existía antes, que es justo lo que será el primer envío real.

Ensayado así en `INNOVAGES_DTE`: el repartidor entregó el folio 235, el timbre
valida contra la llave del CAF, los dos resúmenes coinciden con los escritos y
el sobre es XML bien formado en ISO-8859-1. Producción no se tocó.

### Cuántos folios quedan, que es un problema aparte

De factura queda **uno solo, el 235**. El repartidor de Softland no reutiliza
los huecos —hay 33 folios de rangos viejos sin rastro en ninguna tabla y no los
vuelve a entregar—, así que emitir el 235 deja a INNOVAGES **sin folios de
factura** hasta cargar un CAF nuevo. Conviene pedirlo *antes* del primer envío,
no después.

Y una cosa que conviene tener clara antes de emitir: **un DTE no se puede
emitir «de prueba»**. Es un documento tributario real, queda en el registro de
ventas del emisor y le llega al receptor; si sale mal, se corrige con nota de
crédito, no borrándolo. El primer envío tiene que ser una factura que INNOVAGES
necesite emitir igual.

### Desde la app

El envío dejó de ser sólo un comando. En la ficha de la nota de venta cada
factura lleva su estado ante el SII y su acción:

| Estado | Qué se ofrece |
|---|---|
| Sin enviar | **Enviar al SII** |
| Enviada | **Ver qué dijo el SII** |
| Aceptada | lo mismo, y la etiqueta en verde |

**Enviado y aceptado son distintos.** Lo primero es que viajó; lo segundo, que
el SII lo miró y lo dio por bueno. El veredicto tarda minutos, así que preguntar
es una acción aparte y no algo que se espere dentro del envío.

Tres barreras, además de las que ya pone `Emision`:

- **lo hace facturación o administración.** Escribir la factura es trabajo del
  vendedor; mandarla al SII es un acto tributario de la empresa, y quien lo hace
  tiene que ser quien responde por él. Si el cliente prefiere otra cosa, se abre
  en una línea.
- **no se manda dos veces**: si el folio ya tiene `TrackID`, se dice cuál.
- **un documento anulado no se manda.**

El estado guardado baja al teléfono (`dte_estado`, sobre `dte_doccab`), así que
la ficha dice «enviada» o «sin enviar» sin señal. Preguntarle al SII sí la
necesita; sin ella —o con el SII caído— la consulta devuelve lo guardado en vez
de un error, que es la mitad de la respuesta y sirve igual.

## Lo que falta

| Paso | Estado |
|---|---|
| 1. Reconstruir el timbre de documentos ya emitidos | **hecho** — 615 documentos |
| 2. Escribir `iw_gsaen` / `iw_gmovi` en la base de pruebas | **hecho** — 199 documentos |
| 2b. El camino de conversión NV → factura línea por línea | pendiente (2 casos reales) |
| 3. Generar y firmar el XML | **hecho** — 209 documentos, firma idéntica |
| 3b. Sobre, autenticación y consultas de estado | **hecho** — 210 sobres, firma idéntica |
| 3c. El espejo del documento en `dte_doccab` / `dte_docdet` | pendiente |
| 4. Subir un documento de verdad | pendiente — el camino está conectado a la app; falta el primer envío |
| 5. Boleta por la API REST | bloqueado: faltan folios |

**El bloqueo de la boleta es trámite, no código.** INNOVAGES no tiene CAF para
el DTE 39 ni el 41 y hay que pedírselos al SII a nombre de **77828631-9**. Los
de NETDOMAIN son del RUT 76469595-K: un CAF no se presta entre empresas.

## El certificado digital

Firma el documento y el sobre — cosa distinta del CAF, que solo timbra. Es de
**Jorge Palominos Valenzuela**, emitido por E-Certchile, y es el mismo que usa
Softland hoy.

- **Vence el 26 de diciembre de 2026.** `Certificado::avisaVencimiento()` avisa
  desde 60 días antes. Cuando venza deja de emitir esta app **y también el
  Softland de escritorio**, que usa el mismo.
- Va en `storage/app/private/certificado.pfx`, fuera de git y fuera del
  despliegue. Su clave va en `DTE_CERT_CLAVE`, en el `.env` del servidor.
  **Nunca en el nombre del archivo**, que es donde estaba: cualquiera que liste
  la carpeta la lee.
- **Hubo que reconvertirlo.** El `.pfx` original venía cifrado con un algoritmo
  antiguo que OpenSSL 3 ya no abre por defecto, y PHP fallaba con un
  `digital envelope routines::unsupported` que no dice nada. Se reexportó con
  AES-256, con la misma clave. Al renovarlo habrá que hacer lo mismo:

  ```bash
  openssl pkcs12 -legacy -in viejo.pfx -nodes -out paso.pem
  openssl pkcs12 -export -in paso.pem -out certificado.pfx -keypbe AES-256-CBC -certpbe AES-256-CBC -macalg sha256
  ```
