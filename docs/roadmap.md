# Plan por fases

El orden no es caprichoso: cada fase deja algo usable y las de más abajo
dependen de que las de arriba estén firmes. La fase 4 tiene un bloqueo externo
(folios del SII) que conviene destrabar cuanto antes, en paralelo.

## Fase 1 — Cimientos ✅ hecha

Conexión, configuración, usuarios y notificaciones. Lo transversal, lo que no
se puede improvisar después.

- Instalador `/setup`: valida la conexión SQL, exige la contraseña del usuario
  `softland`, crea el esquema `ventas` y registra al administrador.
- Autenticación doble: contra `wisusuarios` (cifrado propio de Softland) para
  quien tiene licencia, o contraseña propia para el vendedor de terreno.
- Tokens Bearer por dispositivo, revocables uno a uno.
- Usuarios con rol (`vendedor`/`supervisor`/`facturacion`/`admin`), jefe,
  código de vendedor de Softland, bodega, lista de precios, centro de costo y
  topes de descuento/monto.
- Notificaciones: catálogo de los 12 eventos del flujo completo, con
  destinatarios configurables por evento y bitácora de lo que salió.
- App Android con la paleta Softland: servidor, login, inicio y las pantallas
  de administración.

## Fase 2 — Consulta y catálogos offline ✅ hecha

Que el vendedor pueda **salir a terreno con los datos encima**.

- Sincronización de maestros al teléfono: clientes (`cwtauxi` + contactos
  `cwtaxco`), productos (`iw_tprod`), precios por lista (`iw_tlprprod`),
  monedas, condiciones de venta, centros de costo.
- **Cambio de almacenamiento**: los catálogos ya no caben cómodos en
  Preferences (1.229 productos, 3.824 clientes, 2.222 precios). Pasar a
  IndexedDB, que permite buscar y paginar sin cargar todo en memoria.
- Sincronización incremental: traer solo lo cambiado, no los 3.824 clientes
  cada vez.
- Pantallas de consulta de clientes y productos, con buscador.
- Consulta de cotizaciones y notas de venta existentes (solo lectura).
- Alta y edición de clientes y contactos.

## Fase 3 — Cotización y nota de venta ✅ hecha

El corazón del negocio.

- Crear y editar cotizaciones (`nwcotiza` + `nwdetcot`), offline incluido.
- Precio propuesto por lista de precios, descuento por línea y por encabezado,
  y los totales calculados exactamente como los calcula Softland.
- Envío de la cotización al cliente por correo, con PDF.
- Seguimiento (`nwtsegui`) y cierre por pérdida con motivo (`nwperdida`).
- Conversión a nota de venta (`nw_nventa` + `nw_detnv`), dejando la cotización
  en estado `V`.
- **Aprobación por jefe** cuando la NV pasa los topes del vendedor. Ojo: esto
  no existe en Softland (`nwparam.CheckApruebaNv = N`), lo aporta la app.
- Sincronización idempotente por `client_uuid`: reenviar no duplica.
- ~~**Antes de escribir**: resolver de dónde sale el correlativo de
  `NVNumero`~~. Resuelto: no existe tabla de correlativos en la base y Softland
  de escritorio lo calcula solo. La app toma el máximo bajo `UPDLOCK, HOLDLOCK`
  dentro de la transacción y reintenta si la clave primaria choca.

Quedó fuera, con su porqué en `STATE.md`: **flete y embalaje** (ninguna de las
2.350 cotizaciones los usa), los **descuentos 2 a 5** (tampoco) y los
**impuestos que no sean el IVA** (no hay de dónde deducir qué producto paga
ILA).

## Fase 3.5 — El papel ✅ hecha

Lo que el cliente ve. Va antes de la fase 4 porque la factura y la boleta se
dibujan con el mismo motor, y llegar ahí con el motor ya probado ahorra
rehacerlo. Detalle en `docs/motor-documentos.md`.

- **Motor de documentos**: el tipo documental es un `case` de enum que declara
  título y bloques, no una plantilla propia. Los siete tipos futuros ya están
  declarados.
- **Identidad corporativa configurable**, heredada de `softland.soempre` y
  corregible desde la app: logo, datos, color, condiciones, pie.
- **PDF A4 multipágina** con cabecera y pie repetidos y paginado correcto.
- **Snapshot versionado**: lo entregado al cliente no cambia solo.
- **Tres caminos al cliente**: verlo, correo con PDF adjunto, WhatsApp por la
  hoja de compartir de Android.

## Fase 4 — Facturación y boleta electrónica  🔨 en curso

**Alcance acordado**: la app llega **hasta inventario y facturación con el DTE
emitido**. La centralización a contabilidad, registro de ventas y cuenta
corriente es un procedimiento aparte que se corre desde Softland. No se
reproduce.

| Paso | Estado |
|---|---|
| 1. Reconstruir el timbre de documentos ya emitidos | **hecho** — 615 documentos, todos idénticos |
| 2. Escribir `iw_gsaen` / `iw_gmovi` en la base de pruebas | **hecho** — 199 documentos, columna por columna |
| 2b. Conversión NV → factura línea por línea | pendiente (2 casos reales) |
| 3. Generar y firmar el XML | **hecho** — 209 documentos, firma idéntica |
| 3b. Enviar al SII | pendiente |
| 4. Producción, un documento acompañado | pendiente |
| 5. Boleta por la API REST | preparada; bloqueada por los folios |

Detalle en `docs/dte.md`.


- Generar el documento de venta (`iw_gsaen` + `iw_gmovi`) desde la NV,
  respetando `nvCantFact` para las facturaciones parciales.
- Emisión del DTE: `dte_doccab` + `dte_docdet` + `dte_docref`, consumo de folio
  desde `dte_siicaf`, firma y envío al SII, seguimiento por `TrackID`.
- Nota de crédito (DTE 61) referenciando el documento original.
- Envío del documento al cliente por correo. El motor de documentos ya sirve
  para dibujar la representación impresa; lo que falta ahí es el **timbre
  PDF417**, que sale del XML firmado. El PDF se dibuja a partir del DTE ya
  emitido, nunca al revés.

**Bloqueos de esta fase — revisados el 2026-09-15:**

1. **No hay folios CAF de boleta** (DTE 39 ni 41) a nombre de INNOVAGES
   (77828631-9). Hay que solicitarlos al SII y cargarlos en Softland. Los de
   NETDOMAIN son de otro RUT y no se prestan. Sin eso la boleta queda
   construida pero inerte. **Sigue abierto, y es trámite, no código.**
2. ~~El mapeo tipo Softland → tipo SII~~ — **resuelto**: no vive en
   `cwttdoc.DTEDocSII` sino en `dte_siitdoc`, por `(Tipo, SubTipoDocto)`.
   `F`+`T` → 33, `F`+`S` → 34, `N`+`T` → 61, `B`+`T` → 39, `B`+`S` → 41.
3. ~~¿Existe la API REST oficial de Softland?~~ — **resuelto: no**. En `srv` no
   hay componente de servidor de Softland. El emisor de DTE es
   `IWSerDTE.exe`, un programa de escritorio que alguien abre; hoy factura una
   persona desde `IWS.EXE` y el DTE sale 1–5 minutos después.

**Lo que ese hallazgo obliga a decidir**: si la app insertara en `iw_gsaen`,
nadie emitiría el DTE detrás, y esa tabla arrastra 21 triggers, centralización
contable, kardex, comisiones y libro de ventas. Escribir la factura a mano no
es «una fase más»: es asumir riesgo fiscal. El alcance real de la fase 4 está
por decidir — ver `docs/flujo-ventas-softland.md`.

## Fase 5 — Terreno

- Impresión por Bluetooth del comprobante.
- ~~Envío del documento por WhatsApp~~. Hecho en la fase 3.5, por la hoja de
  compartir de Android: `wa.me` sólo lleva texto, así que el archivo lo entrega
  el sistema y el vendedor elige el chat. Mandarlo **desde el servidor** exige
  WhatsApp Business Cloud API — cuenta de empresa verificada, número dedicado,
  plantillas aprobadas y pago por conversación; es decisión de negocio.
- Cobranza: pagos y saldo del cliente.
- Panel del supervisor: avance por vendedor.
