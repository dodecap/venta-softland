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
la base directamente. Antes de escribir a las tablas a mano conviene averiguar
si esa API está disponible para esta instalación: sería el camino soportado.
No pude extraer sus endpoints (los ensamblados vienen comprimidos en un blob).

## Bloqueos identificados

1. **Boleta electrónica: no hay folios.** No hay CAF para el DTE 39 ni el 41, y
   `dte_boletas` está vacía. El tipo `BE` (boleta habitual afecta electrónica)
   sí existe en `cwttdoc`, así que Softland está preparado, pero faltan los
   folios. Hay que **pedir CAF de boleta al SII** antes de poder emitir. Sin
   eso, la parte de boleta del proyecto no se puede terminar, solo dejar lista.

2. **Mapeo tipo Softland → tipo SII, para ventas.** En `cwttdoc` los documentos
   de compra (`FT`, `FL`, `NT`) traen `DTEDocSII` = 33/34/61, pero los de venta
   (`EL`, `NL`, `BE`) lo traen **vacío**, y `iw_gsaen.DTE_SiiTDoc` está en 0 en
   las 209 filas. El mapeo se resuelve en otra parte. **Pendiente de confirmar**
   antes de emitir cualquier DTE.

3. **Correlativo de nota de venta**, ver arriba.
