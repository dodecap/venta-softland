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

## Lo que falta

| Paso | Estado |
|---|---|
| 1. Reconstruir el timbre de documentos ya emitidos | **hecho** |
| 2. Escribir `iw_gsaen` / `iw_gmovi` en la base de pruebas | pendiente |
| 3. Emitir contra `maullin` (certificación) | pendiente |
| 4. Producción, un documento acompañado | pendiente |
| 5. Boleta por la API REST | bloqueado: faltan folios |

**El bloqueo de la boleta es trámite, no código.** INNOVAGES no tiene CAF para
el DTE 39 ni el 41 y hay que pedírselos al SII a nombre de **77828631-9**. Los
de NETDOMAIN son del RUT 76469595-K: un CAF no se presta entre empresas.

## El certificado digital

Firma el documento y el sobre — cosa distinta del CAF, que solo timbra. Es de
**Jorge Palominos Valenzuela**, emitido por E-Certchile, y es el mismo que usa
Softland hoy.

- **Vence el 26 de diciembre de 2026.** El emisor tiene que leerlo de una ruta
  configurable, nunca cableado, para que renovarlo sea copiar un archivo.
- Va en `storage/app/private/`, fuera de git, junto al `softland.json`. Su clave
  va en el `.env` del servidor. **Nunca en el nombre del archivo**, que es donde
  estaba: cualquiera que liste la carpeta la lee.
