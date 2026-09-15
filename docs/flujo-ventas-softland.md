# El flujo de ventas dentro de Softland

Mapa de las tablas reales que hay que tocar para replicar el flujo
cotización → nota de venta → factura/boleta electrónica.

Todo lo de aquí está **verificado contra la base `INNOVAGES`** el 2026-09-13,
leyendo el esquema y contando filas. Lo que no pude confirmar está marcado
como **pendiente de confirmar**.

> Recordatorio que cuesta un intento fallido cada vez que se olvida: las tablas
> de Softland **no están en `dbo`**, están en el esquema `softland`. Un
> `SELECT * FROM nwcotiza` falla con «Invalid object name».

## El encadenamiento

```
nwcotiza ──CotNum──> nw_nventa ──nvnumero──> iw_gsaen ──(RUTEmisor,TipoDTE,Folio)──> dte_doccab
(cotización)         (nota de venta)        (documento de venta)                    (DTE al SII)
   │                      │                       │                                      │
nwdetcot              nw_detnv                iw_gmovi                               dte_docdet
(detalle)             (detalle)               (detalle)                              (detalle)
```

Volúmenes en INNOVAGES: 2.350 cotizaciones (9.583 líneas), 800 notas de venta
(2.242 líneas), 209 documentos de venta, 283 DTE. El flujo completo está en uso
real; no hay que inventar nada, hay que **calzar** con lo que ya existe.

## 1. Cotización — `nwcotiza` + `nwdetcot`

Encabezado (`nwcotiza`, 50 columnas). Las que importan:

| Columna | Qué es |
|---|---|
| `CotNum` | PK, entero. Número de cotización. |
| `CodAux` | Cliente (→ `cwtauxi.CodAux`). |
| `NomCon` | Nombre del contacto (→ `cwtaxco`, por nombre, no por id). |
| `VenCod` | Vendedor (→ `cwtvend`). |
| `CodMon` | Moneda (→ `cwtmone`). |
| `CodLista` | Lista de precios (→ `iw_tlispre`). |
| `CveCod` | Condición de venta (→ `cwtconv`). |
| `CodiCC` | Centro de costo (→ `cwtccos`). |
| `CtEstado` | Estado, 1 carácter. Ver abajo. |
| `CtFem` / `CtFeEnt` | Fecha de emisión / de entrega. |
| `CodPerd` / `ObsPerd` / `CtFePerd` | Motivo de pérdida (→ `nwperdida`). |
| `CtSubTotal`, `CtMonto`, `CtNetoAfecto`, `CtNetoExento`, `CtTotalDesc` | Totales. |
| `CtDscto01..05` / `CtPorcDesc01..05` | Cinco niveles de descuento, en monto y en %. |
| `CtValflete`, `CtValEmb` | Flete y embalaje. |
| `Usuario`, `FechaHoraCreacion` | Auditoría. |

Detalle (`nwdetcot`): `CotNum` + `CtLinea`, `CodProd`, `CtCant`, `CtPrecio`,
`CtTotLinea`, los mismos cinco descuentos por línea, `CodUMed`, `DetProd`
(descripción libre, campo `text`).

### Estados de la cotización (`CtEstado`)

Son **cuatro y no hay más**. No es una lectura de los datos: es el vocabulario
del ERP, confirmado por el cliente.

| Estado | Filas | Qué es |
|---|---|---|
| `P` | 189 | **Pendiente.** Con este nace una cotización |
| `V` | 679 | En nota de venta: se convirtió |
| `N` | 1.000 | **Nula.** Anulada, no «nueva» |
| `R` | 482 | Perdida (casi todas con `CodPerd`) |

> **`N` es «nula».** Es el error que costó tres meses de documentos
> invisibles: la app nacía las cotizaciones en `N` leyendo el estado más
> frecuente como «nueva». Un documento anulado no se lista en el ERP.

La correlación de `V` es perfecta — las 679 cotizaciones en `V` son exactamente
las que tienen nota de venta —: **`V` es la marca de que la cotización se
convirtió**. Al generar la NV hay que dejar la cotización en `V`.

### Lo que un documento necesita para que Softland lo liste

Se descubrió por diferencia: una cotización y una nota de venta escritas por la
app no aparecían en las ventanas de búsqueda del Softland de escritorio.
Comparadas columna a columna contra un documento escrito por el ERP, lo único
que las distinguía era lo que la app dejaba en nulo — y eran, literalmente, las
únicas filas nulas de toda la base.

| Columna | Nulos en la base | Qué va |
|---|---|---|
| `VenCod` | 1 de 2.351 (la de la app) | El vendedor. **Sin él el documento no se lista** |
| `UsuarioGeneraDocto` | 1 de 2.351 (la de la app) | Quien lo creó, `varchar(8)` como `wisusuarios.Usuario` |
| `Usuario` | 1.679 de 2.351 | **Se deja vacío**: el autor va en la columna de arriba |
| `CtFeEnt` / `nvFeEnt` | 0 | Si no hay entrega pactada, la fecha del documento |
| `numOC` / `NumOC` | 0 | Vacío cuando no hay OC. Un `0` se lee como «OC número cero» |
| `nwdetcot.CtFecCompr` | 2 (las de la app) | La fecha del documento |
| `nw_detnv.nvFecCompr` | 2 (las de la app) | La fecha del documento |
| `nw_detnv.nvCorrela` | — | **Cero**, no el número de línea: lo mueve el despacho |
| `DetProd` | 2 (las de la app) | Lo que lee el cliente. Si el vendedor no escribe, la descripción del maestro |

`nvCanalNV` sí puede ir en nulo — está así en 1.308 de 2.351 — porque
`nwparam.CheckCanalCot` y `CheckCanalNv` están en `N`.

Motivos de pérdida (`nwperdida`): `01` compra competencia, `02` cliente se
retracta *(marcado «no usar»)*, `03` sin interés, `04` no contesta, `05` no
compra por caro, `06` no cumple funcionalidades.

### Anular y eliminar

Son dos cosas distintas y el ERP admite las dos.

**Anular** es poner el estado en `N`. El documento se queda, conserva su número
y deja de contar. Es lo único correcto si el papel ya salió: el cliente tiene un
PDF que dice «Cotización N° 8551», y que ese número no exista después es peor
que que exista anulado.

**Eliminar** es borrar la fila. Softland de escritorio lo hace — la bitácora
`nw_lognwcotiza` guarda el evento `Elimina`, y en INNOVAGES hay **4.453 huecos**
en la numeración de cotizaciones y 906 en la de notas de venta. No es raro: es
práctica normal en esta instalación.

#### Lo que se borra solo, y lo que no

La base trae triggers `FOR DELETE`. **El barrido lo hace Softland**, y repetirlo
a mano es mantener dos veces la misma lógica:

| Al borrar | Se llevan los triggers | Hay que borrarlo antes |
|---|---|---|
| `nwcotiza` | `nwdetcot`, `NWCtImpto`, `SoCtDocAsociado`, y escribe `Elimina` en `nw_lognwcotiza` | `nwtsegui`, `nwctdoctos` — tienen FK `NO_ACTION` y bloquean el borrado |
| `nw_nventa` | `nw_detnv`, `NW_Impto`, `nw_nvdoctos`, `SoNwDocAsociado`, `NWSoliApr`, `NW_aprobDetalle`, los tres `NW_NventaTVAtr*`, y la bitácora | nada: no tiene ninguna FK apuntándole |

Las bitácoras `nw_lognwcotiza` y `nw_lognwnventa` **no se tocan nunca**: tienen
10.397 y 1.829 filas de documentos que ya no existen, y así debe seguir. El
`Usuario` de la fila `Elimina` lo copia el trigger de `UsuarioGeneraDocto`, o
sea que nombra a quien creó el documento, no a quien lo borró.

#### Las cuatro condiciones que pone la app

Softland deja borrar más de lo que conviene — hay 14 notas de venta apuntando a
una cotización que ya no existe. La app es más estricta: sólo elimina si

1. **la creó la app** — hay fila viva en `ventas.documento_app`;
2. **nunca salió al cliente** — ninguna emisión con `enviado_at`;
3. **no avanzó a nada** — la cotización, que no tenga nota de venta; la nota de
   venta, que no esté en `iw_gsaen` (facturada), `iw_encpicking`, `owordencom`
   ni `owrequisicion`;
4. **está pendiente o ya anulada** (`P` o `N`), y quien lo pide la tiene a su
   alcance por vendedor.

Si algo falla, el servidor responde 409 con **todas** las razones, no la
primera, y dice si todavía se puede anular.

### El número vuelve al pozo

`MAX(numero) + 1` sobre una tabla con huecos **reparte de nuevo el número del
documento borrado**. Durante las pruebas de este proyecto el 8553 llegó a estar
asignado a tres documentos seguidos.

Por eso el mapa `client_uuid` → número no basta: un número no identifica nada de
forma permanente. `ventas.documento_app.creado_en` guarda el mismo instante que
se escribió en `FechaHoraCreacion`, y `Ventas::yaEscrito()` compara los dos. Si
no coinciden, la fila del mapa está muerta: se borra y el documento se escribe
de nuevo, con número nuevo.

Sin esa comprobación, un teléfono que estuvo un día sin red y reintenta un
`client_uuid` viejo recibía «ya está escrita, es la 8553» y se traía a la
pantalla la cotización de otra persona.

Se declara muerto **sólo lo que se puede demostrar muerto** — o el documento ya
no está, o las dos huellas existen y difieren. Equivocarse por exceso escribiría
el documento dos veces, que es peor que un puntero viejo.

## 2. Nota de venta — `nw_nventa` + `nw_detnv`

Encabezado (`nw_nventa`, 67 columnas):

| Columna | Qué es |
|---|---|
| `NVNumero` | PK, entero. |
| `CotNum` | Cotización de origen (0 o NULL si nació directa). |
| `nvEstado` | Estado: `P` pendiente, `A` aprobada, `N` **nula**, `C` concluida. Ver abajo. |
| `nvEstFact`, `nvEstDesp`, `nvEstRese`, `nvEstConc` | Flags. **Están todos en 0 en las 800 filas**: Softland no los usa aquí. |
| `CodAux`, `VenCod`, `CodMon`, `CodLista`, `CveCod`, `CodiCC`, `CodBode` | Mismos maestros que la cotización, más bodega. |
| `NumOC` | Orden de compra del cliente. `NOT NULL`. |
| `nvFem`, `nvFeEnt`, `nvFeAprob` | Emisión, entrega, aprobación. |
| `nvSubTotal`, `nvMonto`, `nvNetoAfecto`, `nvNetoExento`, `nvTotalDesc` | Totales. |
| `nvPorcDesc01..05` / `nvDescto01..05` | Descuentos de encabezado. |
| `Usuario`, `FechaHoraCreacion`, `FechaUlMod` | Auditoría. |

Detalle (`nw_detnv`): además de precio y cantidad, lleva el **avance del
despacho y la facturación** línea a línea — `nvCantDesp`, `nvCantFact`,
`nvCantBoleta`, `nvCantNC`, `nvCantDevuelto`. Es ahí donde se ve qué falta por
facturar de una NV, no en los flags del encabezado.

#### Con qué estado nace una nota de venta

Lo decide el ERP, no la app: **`nwparam.CheckApruebaNv`**. Con `S` la NV nace
aprobada (`A`); con `N`, pendiente (`P`), esperando que alguien la apruebe en
Softland. En INNOVAGES está en `N`, así que toda nota de venta nace en `P`.

Eso convive con la aprobación por topes que aporta la app: una NV que pasa el
tope del vendedor queda en `P` pase lo que pase, y el visto bueno del jefe la
deja en el estado que el ERP le habría dado de entrada. El freno es de la app;
la aprobación sigue siendo de Softland.

Aprobaciones: `nw_aprob` (nombre/cargo/email de quien aprueba) y
`NW_aprobDetalle` (`NvNumero`, `FechaHora`, `Usuario`, `Ap_Desap`, `Comentario`).

## 3. Documento de venta — `iw_gsaen` + `iw_gmovi`

Es la tabla del módulo de Existencias/Facturación, 168 columnas. PK compuesta
`Tipo` + `NroInt`.

Lo que hay hoy en INNOVAGES:

| `Tipo` | `TtdCod` | Estado | Filas | Folios | Con NV |
|---|---|---|---|---|---|
| `F` | `EL` (factura venta electrónica) | `V` | 197 | 1–234 | 192 |
| `N` | `NL` (nota de crédito electrónica) | `V` | 12 | 1–15 | 12 |

**No hay boletas.** Ver la sección de bloqueos.

Columnas clave: `Folio`, `TtdCod` (tipo de documento Softland → `cwttdoc`),
`nvnumero` (**el enlace de vuelta a la nota de venta**), `CodAux`,
`CodVendedor`, `CondPago`, `Fecha`, `NetoAfecto`, `NetoExento`, `IVA`, `Total`,
y toda la ficha del cliente copiada al documento (`NomAux`, `RutAux`, `DirAux`,
`GirAux`…) — Softland **congela** los datos del cliente en el documento, no
los resuelve por join.

También trae las columnas de centralización contable: `CpbAnoVentas` /
`CpbNumVentas`, `CpbAnoCostos`, `CpbAnoConsumos`, `ContabVenta`, `ContabCosto`.
Ahí se anota el comprobante que Softland generó en `cwcpbte`/`cwmovim`.

Detalle (`iw_gmovi`): `Tipo` + `NroInt` + `Linea`, `CodProd`, `CodBode`,
`CantFacturada`, `CantDespachada`, `PreUniMB`, `TotLinea`, `nvCorrela`
(línea de la NV que origina), `CuentaConsumo`, `CodiCC`.

Referencias a otros documentos: `IW_GSaEn_RefDTE` (`CodRefSII`, `FolioRef`,
`FechaRef`, `RazonRef`) — es lo que hace que una NC apunte a su factura.

## 4. DTE electrónico — `dte_doccab` + `dte_docdet`

PK `(RUTEmisor, TipoDTE, Folio)`. Se enlaza con el documento de venta por las
columnas `Tipo` y `NroInt` que `dte_doccab` también guarda.

153 columnas, el XML del SII entero aplanado. Las que gobiernan el ciclo:
`EnviadoSII`, `FechaEnvioSII`, `AceptadoSII`, `TrackID`, `FechaRecepSII`,
`Rechazado`, `Archivo`, `FirmaDTE`, `IDXMLDoc`, `FechaGenDTE`.

Tablas de apoyo:

- `dte_docref` — referencias entre documentos (`TpoDocRef`, `FolioRef`, `CodRef`).
- `dte_siicaf` — **los folios autorizados (CAF)**, con el XML del CAF y las claves RSA.
- `dte_siitdoc` — catálogo de los 50 tipos de documento del SII.
- `dte_estadosii` — respuesta del SII por documento.
- `dte_archivos` — los XML generados.
- `dte_foliosanulados` — folios dados de baja.

### Folios CAF disponibles (RUT emisor 77828631-9)

| Tipo SII | Documento | Folios | Último usado |
|---|---|---|---|
| 33 | Factura electrónica | 1–235 | 234 |
| 61 | Nota de crédito electrónica | 1–16 | 15 |
| 52 | Guía de despacho electrónica | 1–1 | — |
| **39** | **Boleta afecta electrónica** | **ninguno** | — |
| **41** | **Boleta exenta electrónica** | **ninguno** | — |

## Maestros

| Tabla | Qué es | Filas |
|---|---|---|
| `cwtauxi` | Clientes/auxiliares (62 col.: RUT, giro, dirección, `EMail`, `eMailDTE`, `DiaPlazo`, `Bloqueado`) | 3.824 |
| `cwtaxco` | Contactos del cliente | — |
| `cwtvend` | Vendedores (`VenCod`, `VenDes`, `EMail`) | 21 |
| `iw_tprod` | Productos (72 col.) | 1.229 |
| `iw_tlispre` / `iw_tlprprod` | Listas de precio / precio por producto | 6 / 2.222 |
| `iw_tbode` | Bodegas | 3 |
| `cwtccos` | Centros de costo | — |
| `cwtconv` | Condiciones de venta (`CveDias`) | — |
| `cwtmone` | Monedas | — |
| `cwttdoc` | Tipos de documento Softland | — |
| `cwtgiro`, `cwtcomu`, `cwtciud` | Giros, comunas, ciudades | — |
| `wisusuarios` | Usuarios de Softland (contraseña con cifrado propio) | 15 |
| `iw_stock` | Stock | **0 — no se lleva stock** |

## Reglas de negocio configuradas (`nwparam`)

Una sola fila, y manda sobre todo el módulo. Valores actuales de INNOVAGES:

| Parámetro | Valor | Consecuencia |
|---|---|---|
| `CheckApruebaNv` | `N` | Softland **no** exige aprobación de notas de venta |
| `CheckExigeCCostoN` | `S` | **El centro de costo es obligatorio en la nota de venta** |
| `CheckExigeCCostoC` | `N` | En la cotización no |
| `CentroCostoDefecto` | `ADM-023` | CC por defecto |
| `NvPorcDsctoLin` / `NvPorcDsctoTot` | `0.0` | Sin tope de descuento configurado |
| `InformaStock` / `IngresaSobreStock` | `S` / `S` | Avisa stock pero deja vender igual |
| `CheckCondVtaEfectivo` | `S` | Controla la condición de venta al contado |

Que `CheckApruebaNv = N` es relevante: la aprobación por jefe que pide este
proyecto **no existe hoy en Softland**, la aporta la app (topes por vendedor).

## Precedente: el servicio del practicante (`E:\Servicio` en srv)

API REST en Node/Express que ya hacía parte de esto. Vale leerla, pero **no
copiar su enfoque**:

- Endpoints `/Clientes/Listar_Clientes`, `/Notas_Venta/Guardar_Nota_Venta`, etc.
- La app móvil (Ionic 3 + Cordova) espejaba las tablas en SQLite con sufijo
  `_Local` y una columna `Estado_Subida` para la cola de sincronización.
- Solo cubría clientes y notas de venta. No hacía cotización ni facturación.

Tres problemas concretos que este proyecto no debe heredar:

1. **El correlativo se saca con `MAX(NVNumero) + 1`** sin bloqueo. Dos
   vendedores sincronizando a la vez generan el mismo número.
   *(Dónde toma Softland el correlativo de NV: **pendiente de confirmar**;
   no está en `nwparam` ni hay tabla de correlativos de ventas evidente.
   Mientras no se aclare, hay que tomarlo dentro de una transacción con
   `UPDLOCK, HOLDLOCK`.)*
2. **SQL armado por concatenación** de `req.body` en todos los endpoints:
   inyección SQL en cada uno.
3. **Contraseña de `sa` escrita en el código** (`Config/Config.js`), ya en el
   historial de git.

## Precedente: la app oficial de Softland

En `~/scrmmobile` hay un APK de `cl.softland.ventascrmmobile`, la app móvil de
ventas que Softland distribuye. Es Xamarin.Forms (Prism + Refit + Syncfusion) e
incluye ensamblados **`Softland.ECommerceClient`** y **`Softland.DteClient`**.

Que use Refit implica que habla con una **API REST oficial de Softland**, no con
la base directamente. **Averiguado: esa API no está disponible aquí** — en `srv`
no hay ningún componente de servidor de Softland escuchando. Ver «Quién emite el
DTE». No pude extraer sus endpoints (los ensamblados vienen comprimidos en un
blob), y da igual: no habría a quién llamar.

## Quién emite el DTE — y no es esta app

Averiguado en `srv` el 2026-09-15, porque de esto depende toda la fase 4.

**No hay API REST oficial de Softland en esta instalación.** El emisor de DTE
es `C:\SOFTLAND\PROGRAMA\IWSERDTE\IWSerDTE.exe`, un **programa de escritorio,
no un servicio**: no está registrado en servicios de Windows ni corre solo.
Hoy factura una persona (`jpalomin`) desde `IWS.EXE`, y el DTE sale entre 1 y 5
minutos después — ese hueco es alguien haciendo clic, no un proceso.

Consecuencia: si esta app insertara una fila en `iw_gsaen`, **nadie emitiría el
DTE detrás**. Y `iw_gsaen` tiene 21 triggers encima y arrastra centralización
contable, kardex, comisiones, cuotas y libro de ventas: una fila escrita a mano
es una factura coja dentro del ERP, con riesgo de quemar un folio CAF — y un
folio quemado ante el SII no se deshace.

## Mapeo tipo Softland → tipo SII — resuelto

No está en `cwttdoc.DTEDocSII` (por eso salía vacío para los documentos de
venta). Está en **`dte_siitdoc`, por la pareja `(Tipo, SubTipoDocto)`**, que son
las mismas dos columnas de `iw_gsaen`:

| `Tipo` | `SubTipoDocto` | `DocCod` | Documento |
|---|---|---|---|
| `F` | `T` | 33 | Factura electrónica |
| `F` | `S` | 34 | Factura exenta electrónica |
| `N` | `T` | 61 | Nota de crédito electrónica |
| `B` | `T` | 39 | Boleta afecta electrónica |
| `B` | `S` | 41 | Boleta exenta electrónica |

`dte_siitdoc.Electronico` dice si el tipo es electrónico. INNOVAGES usa hoy
`F`+`T` (197 facturas) y `N`+`T` (12 notas de crédito), bodega `GEN`.

## Se factura por suscripción, y se nota

Una nota de venta genera **varias** facturas a lo largo de meses: la NV 2003
tiene 9, la 1925 y la 1974 tienen 6 cada una. Y **799 de las 800 notas de venta
tienen saldo por facturar** (`nw_detnv.nvCantFact < nvCant`). Por eso «saldo por
facturar» no es una anomalía a resolver, es el estado normal de casi todo.

## La base NETDOMAIN, como referencia

En la misma instancia está `NETDOMAIN`, la matriz. Es **sólo lectura y está
dormida** — su último documento es de enero de 2024 —, pero sirve de modelo
para lo que INNOVAGES nunca ha emitido.

- **No destraba la boleta.** Su CAF del DTE 39 (folios 1–100, autorizado el
  2020-11-28) está a nombre del RUT **76469595-K**; INNOVAGES es
  **77828631-9**. El CAF lo da el SII por RUT: no se presta entre empresas.
- **Sí da el modelo.** Tiene una boleta electrónica real, emitida y aceptada:
  folio 2 del 2021-07-09, nacida de la nota de venta 1534, TrackID
  `1292919449`. La cadena completa está ahí — `iw_gsaen` (`B`+`T`) →
  `dte_doccab` → el XML firmado en `dte_archivos` con su `<TED>` entero.
  De ahí salen las diferencias de la boleta frente a la factura, que no se
  deducen: receptor genérico `66666666-6` cuando no hay cliente, `<Totales>`
  con `MntTotal` bruto y **sin desglose de IVA**, `IndServicio`, `FmaPago`.
- También tiene 673 facturas exentas (DTE 34), 356 guías de despacho, notas de
  crédito no electrónicas y 8.986 boletas manuales, por si hace falta el camino
  exento o la guía.

## El timbre PDF417 se lee, no se calcula

El XML firmado de cada DTE queda guardado en `dte_archivos` (599 en INNOVAGES),
con `TipoXML` = `D` para el documento y `SS` para el sobre de envío al SII.
Dentro viene el `<TED>` completo. La representación impresa se dibuja **leyendo
ese TED**, sin emitir nada ni consumir un folio.

## Bloqueos identificados

1. **Boleta electrónica: INNOVAGES no tiene folios.** No hay CAF para el DTE 39
   ni el 41, y `dte_boletas` está vacía. El tipo `BE` sí existe en `cwttdoc`,
   así que Softland está preparado, pero faltan los folios. Hay que **pedirle al
   SII los CAF a nombre de 77828631-9**; los de NETDOMAIN no sirven. Es trámite,
   no código.

2. ~~Mapeo tipo Softland → tipo SII~~ — **resuelto**, ver arriba: `dte_siitdoc`
   por `(Tipo, SubTipoDocto)`.

3. **Correlativo de nota de venta**, ver arriba.

4. ~~¿Hay API REST oficial de Softland?~~ — **resuelto: no la hay** en esta
   instalación, ver arriba. La emisión del DTE es de `IWSerDTE.exe` y de la
   persona que lo abre.
