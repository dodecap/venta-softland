# Panel de control comercial — auditoría y plan

> Primera entrega de la fase «panel». Todo lo de aquí está contrastado contra
> la base `INNOVAGES` el 2026-09-14; cada cifra sale de una consulta, no de
> una estimación.
>
> **Pasos 1, 2, 3 y 4 hechos** (§16), más la actividad reciente en su versión
> del teléfono: el motor de métricas con sus pruebas, el panel con ámbito,
> período, KPI protagonista y embudo, «Requiere tu atención» con su
> drill-down, «Mi rendimiento» con la comparación al período anterior y los
> últimos cinco documentos de cada tipo. Comprobado contra el ERP: el año 2026
> del ámbito empresa da 134 cotizaciones y 34 notas de venta por $79,3 MM,
> exactamente lo que devuelve SQL Server.

> **Corrección 2026-09-14 (a)**: los montos del panel son **neto afecto + neto
> exento**, no `CtMonto`/`nvMonto`. El IVA no es venta, y además no infla
> parejo: lo afecto sube 19 % y lo exento no. Septiembre del vendedor 2 son
> $43,8 MM con IVA y **$37,9 MM netos**. La lista y la ficha siguen mostrando
> el total con IVA, que es lo que paga el cliente y lo que sale impreso.

> **Corrección 2026-09-14 (b)**: **venta es la nota de venta aprobada (`A`) o
> concluida (`C`)**. La pendiente (`P`) está escrita y sin autorizar; contarla
> es anunciar plata que puede no entrar. Afecta a la venta, al embudo, al
> ticket y al tiempo de cierre — **no** a la conversión, que pregunta si la
> cotización llegó a nota de venta y ahí una pendiente cuenta, porque la
> cotización ya quedó en `V`.
>
> El reparto real: de las 800 notas de venta de INNOVAGES, **736 en `A`**, 45
> nulas, 16 pendientes y 3 concluidas (las tres de 2020). Septiembre del
> vendedor 2 son 6 notas por $9,6 MM netos, de las que **4 están aprobadas por
> $7,2 MM**; las otras dos, $2,4 MM, salen en el panel bajo el KPI con su
> propia línea y su enlace a la lista filtrada.
>
> De paso apareció un defecto que venía de la fase 3:
> `Ventas::estadoInicialNotaVenta()` estaba **invertido** y la app escribía sus
> notas de venta en `P` justo donde el ERP no pide aprobación. Con esta regla
> el efecto habría sido que la venta del vendedor no aparece en su panel.

## 0. La conclusión, antes del detalle

Se pueden calcular **hoy y con datos reales** el embudo completo hasta
facturación, la conversión, el tiempo de ciclo, el ticket promedio, la
antigüedad de lo pendiente y la actividad reciente. Lo que **no** tiene con
qué calcularse en esta instalación es la meta (no existe en Softland), la
cobranza (el módulo está prácticamente vacío) y la ejecución de servicios (no
hay fuente).

Y hay un hallazgo que cambia el diseño del embudo antes de escribirlo:
**INNOVAGES factura por suscripción, no por nota de venta**. La NV 2003 lleva
**10 facturas** repartidas entre 2025-09 y 2026-03. Una «conversión
NV → factura» contada en documentos daría 1.000 %. Ver §15.

---

## 1. Auditoría del panel actual

`mobile/src/views/Inicio.vue`, 267 líneas. De arriba abajo:

| Bloque | Qué muestra | Veredicto |
|---|---|---|
| Encabezado | saludo, rol, `VenCod`, botón sincronizar | **se conserva**, se le agrega el selector de ámbito y período |
| Barra de descarga | maestro en curso y progreso | se conserva, sólo visible mientras sincroniza |
| KPI 1 | «En línea / Sin señal» | **fuera del panel** → Cuenta |
| KPI 2 | «Última sincronización» | **degradado** a una línea de pie: `Actualizado 16:15` |
| KPI 3 | «Registros en el teléfono» | **fuera del panel** → Cuenta |
| KPI 4 | «Cambios por enviar» | **se queda**, pero como aviso de acción, no como KPI |
| Banda de aprobaciones | NV que esperan al jefe | **se queda tal cual**: ya es el patrón «requiere tu atención» bien hecho |
| Acciones rápidas | 6 botones, 2 apagados con su fase | **se conservan**, sin tocar tamaños (§26 del encargo) |
| Actividad reciente | los últimos correos enviados, sólo admin | **se reemplaza** |

Los tres KPI de arriba son de diagnóstico, no de negocio: ninguno lleva un
peso. El panel no responde ninguna de las diez preguntas del encargo.

### Por qué «Actividad reciente» no muestra nada

No es una falla: es que **no hay nada que mostrar**. La sección lee
`GET /admin/notificaciones/bitacora`, o sea los correos que salieron, y
`ventas.notificacion` tiene **0 filas** porque el SMTP todavía no está
configurado. Además está dentro de `v-if="esAdmin"`, así que un vendedor no la
ve nunca.

El arreglo no es rellenarla con correos: es cambiarle la fuente. Softland lleva
su propia bitácora de eventos de negocio y está llena — ver §3.

---

## 2. Qué se reutiliza

Todo lo que sigue se usa **sin modificar**:

- `AppIcon` + `iconos.js` — un icono nuevo es una entrada más en el mapa.
- `Aviso.vue`, `Vacio.vue`, `Selector.vue`.
- `densidad.js` y la variable `--d`, con sus tres cosas que no escalan.
- `nav.js` y la barra inferior de cuatro pestañas — **no se agrega una quinta**.
- `idb.js`, `sync.js`, `pendientes.js`, `catalogos.js`: el panel es un lector
  de lo que ya está bajado, no una fuente nueva de sincronización.
- `crear.js` y el botón flotante.
- Las clases `.kpi`, `.rejilla`, `.accion`, `.seccion` de `style.css`: cambia
  lo que llevan dentro, no la retícula.
- `Identidad.php` → `modelo_negocio` (`PRODUCTOS` · `SERVICIOS` · `MIXTO`) y
  `vigencia_cotizacion_dias`, **que ya existen y ya se editan desde la app**.
  El encargo pedía crear esa propiedad; está hecha desde el motor de
  documentos.

Se reutiliza además el patrón de la banda de aprobaciones como molde de
«Requiere tu atención»: icono en recuadro, texto de dos líneas, chevrón, toda
la fila pulsable, y **sólo aparece cuando hay algo**.

---

## 3. Inventario de datos

### Ya en el teléfono (IndexedDB, `Maestros.php`)

| Almacén | Filas en INNOVAGES | Alcance |
|---|---|---|
| `cotizaciones` | 185 (12 meses) | sólo las del vendedor; `estado`, `fecha`, `total`, `cliente`, `vendedor`, `motivo_perdida` |
| `cotizacion_lineas` | de esas cabeceras | producto, cantidad, precio, descuento |
| `notas_venta` | 51 (12 meses) | + `cotizacion`, `fecha_aprobacion`, `oc` |
| `nota_venta_lineas` | ídem | |
| `clientes` | 3.824 | + `contactos` |
| `productos`, `precios` | 1.229 / 2.222 | |
| 15 maestros chicos | | vendedores, motivos de pérdida, centros de costo… |

Con esto el embudo **hasta nota de venta**, la conversión, el tiempo de cierre,
el ticket, la antigüedad y el ranking de vendedores se calculan **sin señal y
sin una sola consulta nueva al servidor**.

### En Softland pero no en el teléfono

| Tabla | Filas | Qué aporta |
|---|---|---|
| `iw_gsaen` + `iw_gmovi` | 209 / 211 | facturación: `Fecha`, `Total`, `CodVendedor`, `CodAux`, `nvnumero`, `Tipo` (`F` factura / `N` nota de crédito) |
| `nw_lognwcotiza` | 15.795 | **bitácora de eventos de la cotización**: `CotNum`, `FechaEvento`, `CodAux`, `Usuario`, `Evento` |
| `nw_lognwnventa` | 3.139 | lo mismo para la NV |
| `nwtsegui` | **34** | seguimientos. Prácticamente sin uso |
| `dte_doccab` | 283 | estado del DTE en el SII |

El vocabulario de `Evento` es limpio y sirve tal cual de actividad reciente:
`Agrega Estado Pendiente` (6.143), `Elimina` (4.353), `Estado Nula` (2.207),
`Estado En Nota de Venta` (1.398), `Estado Perdida` (647), `Mod.Auxiliar`,
`Mod.Fecha`, `Mod.Vendedor->2`.

### No existe en esta instalación

- **Metas de venta.** Se buscó en todo lo que se llamara meta, presupuesto,
  cuota, objetivo o cartera. `ND_Presupuesto` tiene 36 filas **todas en cero**;
  `WG_Presup` (7 filas) es presupuesto contable por mes, no por vendedor.
  → La meta tiene que ser un dato de la app.
- **Cobranza.** `xwcobranza` está vacía. `cwmovim` tiene 594 movimientos con
  cliente, pero de **9 clientes** en total. No hay saldo por documento ni
  antigüedad de cartera que valga la pena mostrar.
- **Ejecución de servicios.** `otproyectos`: 1 fila. `nd_otmovi`: 111 filas sin
  relación con la nota de venta. No hay OT, ni horas, ni recursos, ni agenda.
- **Despachos.** `iw_encpicking`: 1 fila. `nvCantDesp` en cero en las 2.242
  líneas de detalle.
- **Stock.** Existe el maestro de productos, pero INNOVAGES vende servicios;
  el widget se declara y no se enciende.

---

## 4. Tabla de KPI

`O` = calculable offline con lo ya sincronizado. `S` = necesita servidor.

| # | KPI | Fuente | Tabla · campos | Offline | Ámbito | Fase | ¿Ahora? |
|---|---|---|---|---|---|---|---|
| 1 | Cotizado del período | Softland | `nwcotiza.CtFem`, `CtMonto`, `CtEstado`, `VenCod` | **O** | yo·equipo·empresa | ya | **SÍ** |
| 2 | Vendido (NV) del período | Softland | `nw_nventa.nvFem`, `nvMonto`, `nvEstado` | **O** | los tres | ya | **SÍ** |
| 3 | Conversión cotización→NV | Softland | `nw_nventa.CotNum` | **O** | los tres | ya | **SÍ** |
| 4 | Tiempo de cierre | Softland | `CtFem` → `nvFem` | **O** | los tres | ya | **SÍ** |
| 5 | Ticket promedio | Softland | `nvMonto` / nº NV | **O** | los tres | ya | **SÍ** |
| 6 | Pendientes por antigüedad | Softland | `CtEstado='P'`, `CtFem` | **O** | los tres | ya | **SÍ** |
| 7 | Cotizaciones por vencer | Softland + config | `CtFem` + `vigencia_cotizacion_dias` | **O** | los tres | ya | **SÍ** |
| 8 | Sin seguimiento | Softland | `CtFem` vs hoy (no `nwtsegui`: 34 filas) | **O** | los tres | ya | **SÍ** |
| 9 | Tasa de pérdida y motivo | Softland | `CtEstado='R'`, `CodPerd` | **O** | los tres | ya | **SÍ** |
| 10 | Comparación con período anterior | derivado de 1-5 | — | **O** | los tres | ya | **SÍ** |
| 11 | Actividad reciente | Softland | `nw_lognwcotiza`, `nw_lognwnventa` | **S** | los tres | ya | **SÍ** |
| 12 | NV esperando aprobación | app | `ventas.aprobacion` | S | yo·equipo | ya | **SÍ** (ya está) |
| 13 | Cambios sin enviar | app | `pendientes` | **O** | yo | ya | **SÍ** (ya está) |
| 14 | Facturado del período | Softland | `iw_gsaen.Fecha`, `Total`, `Tipo`, `CodVendedor` | S | los tres | ya | **SÍ, con maestro nuevo** |
| 15 | NV sin facturar | Softland | `nw_nventa` ∖ `iw_gsaen.nvnumero` | S | los tres | ya | **SÍ, con reserva** (§15) |
| 16 | Clientes activos / nuevos / dormidos | Softland | `iw_gsaen.CodAux`, `Fecha` | S | los tres | ya | **SÍ, sin valor aquí** (2 clientes en 12 meses) |
| 17 | Meta y avance | **app** | `ventas.meta` (no existe) | O una vez bajada | yo·equipo·empresa | **panel** | NO — hay que crear la tabla |
| 18 | Ranking del equipo | Softland | `VenCod` + 1,2,3,5 | **O** | equipo·empresa | panel | SÍ, pero hoy es una fila (§15) |
| 19 | Cobrado / deuda vencida | — | no hay fuente | — | — | **fase 5** | NO |
| 20 | Stock crítico / sin stock | Softland | `iwsaldos` | S | los tres | fase 4 | NO aplica a INNOVAGES |
| 21 | Despachos | Softland | `iw_encpicking` (1 fila) | S | — | fase 4+ | NO |
| 22 | Servicios comprometidos | aprox. de 15 | — | S | los tres | panel | **sólo como «vendido no facturado»** |
| 23 | Ejecución / conformidad | — | no hay fuente | — | — | **fase 5+** | NO |
| 24 | Capacidad del equipo | — | no hay fuente | — | — | **fase 5+** | NO |

---

## 5. Implementables ahora (sin fuente nueva)

1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 13 y 18 — **todos offline**, desde IndexedDB.
Más 11 (actividad) y 12 (aprobaciones), que ya tienen servidor.

## 6. Requieren un maestro nuevo, no una fase nueva

14, 15, 16 y 22: todo sale de `iw_gsaen`, que ya está poblada. Es **un arreglo
más en `Maestros.php`** (`facturas`, filtrado por `CodVendedor` y 12 meses) más
un almacén en `idb.js`. Con eso el embudo entero pasa a ser offline.

## 7. Requieren fase 4 (facturación desde la app)

Nada del panel. La fase 4 agrega *emitir*, no *medir*: lo ya facturado desde el
escritorio se puede medir hoy.

Sí dependen de la fase 4 los widgets de **stock** (20) y **despachos** (21), y
sólo para instalaciones `PRODUCTOS` o `MIXTO`.

## 8. Requieren fase 5

19 (cobranza) y 23-24 (ejecución y capacidad). Ninguno tiene fuente hoy, ni
siquiera incompleta.

## 9. Qué falta para «Servicios / Ejecución»

Para responder «¿qué vendí que todavía debo ejecutar?» hace falta un estado de
ejecución por línea o por documento. En INNOVAGES:

- `nvEstFact`, `nvEstDesp`, `nvEstRese`, `nvEstConc`: **cero en las 800 filas**.
- `nw_detnv.nvCantFact`, `nvCantDesp`, `nvCantBoleta`: **cero en las 2.242
  líneas**. Es la trampa más cara del relevamiento: las columnas existen, tienen
  el nombre correcto y están todas vacías.
- `otproyectos`: 1 fila. No hay módulo de OT en uso.

Lo único honesto que se puede mostrar hoy es **«vendido y todavía no
facturado»** = NV sin fila en `iw_gsaen` — 15 notas por $19,5 MM en 12 meses —
y **con la reserva de §15**, porque la facturación es recurrente.

Lo que haría falta para el widget de verdad: que la NV se cierre (`nvEstado='C'`
concluida) cuando el servicio se entrega, o una tabla propia de la app
(`ventas.ejecucion`) con estado y fecha comprometida. Eso es una decisión
comercial, no técnica, y no se inventa desde acá.

---

## 10. Arquitectura de widgets

Un motor, no una pantalla:

```
mobile/src/panel/
  metricas.js     ← calcula TODO desde IndexedDB. Sin Vue dentro.
  periodo.js      ← hoy/semana/mes/trimestre/año + período anterior
  dinero.js       ← $84.500 · $850 mil · $12,6 MM   (§5 del encargo)
  widgets.js      ← catálogo: id, título, ámbitos, modelos, permiso, orden
  componentes/
    KpiMeta.vue        KpiEmbudo.vue      KpiAtencion.vue
    KpiRendimiento.vue KpiCiclo.vue       KpiActividad.vue
    KpiClientes.vue    KpiRiesgo.vue
    KpiStock.vue       KpiDespachos.vue        (modelo PRODUCTOS/MIXTO)
    KpiComprometido.vue KpiEjecucion.vue       (modelo SERVICIOS/MIXTO)
```

`widgets.js` declara y `Inicio.vue` recorre. Un widget nuevo es una entrada,
igual que un maestro en `Maestros.php` o un icono en `iconos.js` — el patrón
del proyecto, repetido.

Cada entrada declara:

```js
{
  id: 'embudo',
  titulo: 'Embudo comercial',
  ambitos: ['yo', 'equipo', 'empresa'],
  modelos: ['PRODUCTOS', 'SERVICIOS', 'MIXTO'],
  permiso: null,                  // o 'supervisor'
  necesita: ['cotizaciones', 'notas_venta'],   // almacenes de IndexedDB
  orden: 20,
  componente: KpiEmbudo,
}
```

`necesita` es lo que resuelve el estado vacío de verdad: si el almacén está
vacío el widget dice «todavía no te has traído esto», no «$0».

**Cuatro estados obligatorios por widget**, como pide el encargo: cargando ·
vacío · sin dato disponible · error. `$0` y «no lo sé» no se dibujan igual —
ése es el error clásico de un panel y aquí sería peor, porque el vendedor
decide con esto.

---

## 11. Configuración

Clave nueva `panel` en `ventas.config` (la tabla ya existe, hoy con 0 filas):

```json
{
  "widgets": { "embudo": true, "riesgo": true, "stock": false },
  "orden": ["meta", "embudo", "atencion", "rendimiento", "actividad"],
  "riesgo": { "dias_atencion": 3, "dias_critico": 6 },
  "cliente_dormido_dias": 30,
  "vencimiento_desde_dias": 7
}
```

El modelo de negocio **no se duplica aquí**: se lee de `identidad.modelo_negocio`,
que ya existe, ya hereda de `soempre` y ya se edita en Administración. Un
widget declara para qué modelos sirve y el motor lo filtra. En ninguna parte
aparece el nombre de la empresa.

`AUTOMÁTICO` (que el encargo pide como opción) se puede deducir: si más del 80 %
de las líneas de 12 meses son de productos con `esParaVenta` y unidad física →
`PRODUCTOS`. Propongo **dejarlo fuera** de la primera entrega: adivinar el
modelo de negocio para luego mostrar widgets equivocados es peor que
preguntarlo una vez.

---

## 12. Endpoint

**Uno solo**, y sólo para lo que no está en el teléfono:

```
GET /api/panel?ambito=yo|equipo|empresa&desde=2026-09-01&hasta=2026-09-30
```

devuelve `{ facturado, actividad, aprobaciones, meta, servidor_ahora }`.

Lo demás —embudo hasta NV, conversión, ciclo, ticket, atención, ranking— lo
calcula `metricas.js` **en el teléfono**, sobre IndexedDB. Razones:

1. Funciona sin señal, que es el punto de la app.
2. No hay una consulta agregada por tarjeta: hay **una** petición por apertura,
   y el panel se dibuja antes de que conteste.
3. Los 185 documentos de 12 meses de un vendedor se recorren en milisegundos.
   Si algún día son 10.000, el corte sigue siendo 12 meses.

El período y el ámbito viajan sólo al servidor; el cálculo local usa los mismos
límites, calculados con `periodo.js`.

El calendario del período es **el del teléfono**. Las fechas de los documentos
son del servidor —ésas no se discuten—, pero «este mes» es el mes del vendedor,
que es quien mira la pantalla. Hoy no se guarda el reloj del servidor en ninguna
parte: `db.setSincronizado()` anota la hora del aparato. Si alguna vez hace
falta, el endpoint del panel devuelve `servidor_ahora` y de ahí sale el desfase.

---

## 13. Wireframes

### YO — vendedor (360 px)

```
┌──────────────────────────────────────────┐
│ DD   Hola, Domingo                    ↻  │
│      vendedor 2                          │
├──────────────────────────────────────────┤
│  ( YO )  EQUIPO                  MES ▾   │   ámbito + período
│                                          │
│  MI VENTA                                │
│  $11,7 MM                          ↑ 23% │   NV del mes
│  ▓▓▓▓▓▓▓▓▓▓▓▓░░░░░░░  65% de meta        │   la barra sólo si hay meta
│  Faltan $6,3 MM · 12 días hábiles        │
│                                          │
│  EMBUDO                            MES ▾ │
│   Cotizado   →   Vendido   →  Facturado  │
│   $44,1 MM      $11,7 MM        $0       │
│      15             7             0      │
│         26,5%           0%               │
│                                          │
│  REQUIERE TU ATENCIÓN                    │
│  ┌────────────────────────────────────┐  │
│  │ ⬤ 146 cotizaciones sin respuesta   │  │   toca → lista filtrada
│  │   hace más de 90 días · $729,6 MM  │  │
│  ├────────────────────────────────────┤  │
│  │ ⬤ 15 notas de venta sin facturar   │  │
│  │   $19,5 MM                         │  │
│  ├────────────────────────────────────┤  │
│  │ ⬤ 2 esperan tu visto bueno         │  │   la banda que ya existe
│  └────────────────────────────────────┘  │
│                                          │
│  MI RENDIMIENTO                          │
│  Conversión   Cierre      Ticket         │
│  26,5%        7,7 d       $1,67 MM       │
│  ↑ 3,1 pp     ↓ 0,4 d     ↑ 12%          │
│                                          │
│  ACCIONES RÁPIDAS       (las seis de hoy)│
│  Cotizar  N.Venta  Clientes  Servicios   │
│  Facturar(F4)  Cobranza(F5)              │
│                                          │
│  ACTIVIDAD RECIENTE            Ver todo →│
│  01:40  Cot. 8554 anulada                │
│  01:38  Cot. 8554 creada      $10.000    │
│  ayer   Cot. 8551 → NV 2062   $1,4 MM    │
│                                          │
│  Actualizado 16:15 · 14.175 registros    │   ← lo técnico, aquí
└──────────────────────────────────────────┘
      [ Panel ] Clientes  Avisos  Cuenta
```

### EQUIPO — supervisor

Mismo encabezado con `EQUIPO` activo. Cambian tres cosas:

```
│  VENTA DEL EQUIPO                        │
│  $11,7 MM          4 vendedores activos  │
│                                          │
│  EMBUDO  (agregado, idéntico en forma)   │
│                                          │
│  POR VENDEDOR                    MES ▾   │
│  ┌────────────────────────────────────┐  │
│  │ Rodrigo (2)   $10,9 MM  ▓▓▓▓▓▓▓░ 93%│ │
│  │   conv. 27%  cierre 7,4 d           │ │
│  │ Marcela (19)  $0,8 MM   ▓░░░░░░░ 11%│ │
│  │   conv. 10%  cierre 21 d            │ │
│  └────────────────────────────────────┘  │
│                                          │
│  REQUIERE TU ATENCIÓN                    │
│  2 notas de venta esperan tu visto bueno │
│  5 cotizaciones sin dueño activo         │
```

El ranking **no** ordena sólo por monto: monto, % de meta, conversión,
velocidad y ticket, con el criterio elegible. Ordenar por monto a secas premia
al que tiene la cartera grande, no al que trabaja mejor.

### EMPRESA — gerencia

```
│  CICLO COMERCIAL             AÑO 2026 ▾  │
│  Cotizado      $687,1 MM        135      │
│     ↓ 11,7%                              │
│  Vendido        $79,3 MM         34      │
│     ↓ 37,7%                              │
│  Facturado      $29,9 MM         41      │
│     ↓ —                                  │
│  Cobrado        sin datos                │   ← se dice, no se inventa
│                                          │
│  POR VENDEDOR · POR CLIENTE · POR MES    │
│  PÉRDIDAS: 2 cotizaciones, $8,0 MM       │
│  motivo más frecuente: …                 │
```

«Cobrado — sin datos» es deliberado: un embudo al que le falta el último paso
tiene que decirlo, no terminar en «Facturado» como si ése fuera el final.

---

## 14. Fórmulas

Todas sobre el período `[desde, hasta]` y el ámbito, que filtra por `VenCod`:
ámbito **yo** = el `ven_cod` del usuario; **equipo** = los `ven_cod` a su
cargo; **empresa** = sin filtro.

**Documentos que cuentan.** Se excluyen siempre los anulados: `CtEstado <> 'N'`
y `nvEstado <> 'N'`. Los perdidos (`R`) **sí** cuentan como cotizado — se
cotizaron — pero no como vendido.

```
cotizado        = Σ CtMonto   donde CtFem ∈ [d,h] y CtEstado <> 'N'
cotizado_n      = nº de esas cotizaciones

vendido         = Σ nvMonto   donde nvFem ∈ [d,h] y nvEstado <> 'N'
vendido_n       = nº de esas notas de venta

facturado       = Σ Total     donde Fecha ∈ [d,h] y Tipo = 'F'
notas_credito   = Σ Total     donde Fecha ∈ [d,h] y Tipo = 'N'   (negativo)
facturado_neto  = facturado + notas_credito
```

**Conversión.** Se mide sobre la **cohorte de cotizaciones del período**, no
sobre las NV del período: si no, una NV de una cotización de hace un año
infla el mes.

```
conversion_pct  = 100 × nº cotizaciones del período con NV
                        ─────────────────────────────────
                        cotizado_n
```

Una cotización tiene NV si existe `nw_nventa.CotNum = CotNum`. Se toma de
IndexedDB con el índice `cotizacion` del almacén `notas_venta`, que ya existe.

**Conversión NV → factura: NO se calcula como porcentaje de documentos.** Ver
§15. En su lugar:

```
facturacion_del_periodo = facturado_neto        (monto, sin denominador)
por_facturar            = Σ nvMonto de NV sin ninguna fila en iw_gsaen
                          y con nvFem anterior a hoy − 30 días
```

**Tiempo de ciclo.** Mediana, no promedio: con `n = 89` y un extremo de 141
días, el promedio (7,7 d) miente. Se descartan los negativos (§15).

```
cierre      = mediana( nvFem − CtFem )   sobre NV del período con CotNum > 0
              y nvFem ≥ CtFem
nv_factura  = mediana( primera Fecha de iw_gsaen − nvFem )
```

**Ticket.** `vendido / vendido_n`. Con `vendido_n = 0` → no se dibuja (no «$0»).

**Comparación con el período anterior.** Mismo largo, corrido hacia atrás:
mes → mes anterior; trimestre → trimestre anterior. En **porcentaje** para
montos (`↑ 14,2%`) y en **puntos porcentuales** para tasas (`↑ 4,1 pp`). Con
denominador 0 no hay comparación: se omite el indicador, no se muestra `∞`.

**Antigüedad de lo pendiente** (el corazón de «Requiere tu atención»):

```
pendiente       = CtEstado = 'P'
vence_en        = CtFem + identidad.vigencia_cotizacion_dias   (hoy 30)
por_vencer      = pendiente y 0 ≤ vence_en − hoy ≤ 7
vencida         = pendiente y vence_en < hoy
abierta         = pendiente y el resto
```

**El corte no es un número inventado**: es la vigencia que la empresa le pone a
su propia cotización, la misma que sale impresa en el PDF y que el
administrador ya edita en Identidad. Pasada esa fecha la cotización dejó de
estar en pie; seguir contándola como oportunidad abierta sería contarse un
cuento. Lo único elegido a mano son los 7 días de aviso previo, que pasan a
`ventas.config` en el paso 9.

La regla vive en **una sola función**, `situacion()` de `metricas.js`, y la
usan el panel para contar y la lista para filtrar. Con una copia en cada sitio,
el panel diría «6 por vencer» y al tocarlo saldrían siete.

**Meta** (cuando exista `ventas.meta`):

```
avance_pct      = 100 × vendido / meta
falta           = meta − vendido
por_dia_habil   = falta / días hábiles que quedan en el período
```

Sin fila de meta el widget **no aparece**. No hay meta por defecto.

**Dinero — el formateador.** Una sola función, en `dinero.js`:

| Valor | Sale |
|---|---|
| < 1.000 | `$840` |
| < 1.000.000 | `$84.500` o `$850 mil` según el espacio |
| < 1.000.000.000 | `$1,5 MM` · `$12,6 MM` · `$127,4 MM` |
| ≥ 1.000.000.000 | `$1.250 MM` |

Una decimal en `MM` hasta 99,9; sin decimal de ahí arriba. El valor exacto
viaja igual y se ve al entrar al detalle. **El formato no toca el cálculo**:
`dinero.js` recibe un número y devuelve un texto, nada más.

---

## 15. Riesgos e inconsistencias encontradas en Softland

**a. La facturación es recurrente.** La NV 2003 tiene **10 facturas** entre
2025-09 y 2026-03; la 1925, siete; la 1974, siete. El promedio NV → factura da
93 días justamente por eso. Consecuencias:

- «Conversión NV → factura» en documentos **no se implementa**. Si se pusiera,
  marcaría más de 100 % y nadie volvería a creerle al panel.
- «Vendido» y «facturado» del mismo mes **no son comparables**: se factura
  contra notas de venta de meses anteriores. El embudo los muestra como tres
  medidas del período, con el % sólo donde significa algo.

**b. `nvCantFact` y los flags de estado están todos en cero.** 0 de 2.242
líneas con cantidad facturada; `nvEstFact/Desp/Rese/Conc` en 0 en las 800
cabeceras. Las columnas existen y no se llenan: cualquier KPI construido sobre
ellas daría cero para siempre sin dar error.

**c. Hay un solo vendedor activo.** En 12 meses: `VenCod` 2 → 173
cotizaciones; 19 → 10; 15 → 1; 5 → 1. El ámbito EQUIPO y el ranking se
construyen igual (la arquitectura es para todos los clientes Softland), pero en
INNOVAGES el widget va a mostrar una fila con dato y tres con nada. Conviene
esconder el ámbito EQUIPO cuando hay menos de dos vendedores con actividad.

**d. Sólo 2 clientes facturados en 12 meses.** «Clientes activos / nuevos /
dormidos» es correcto como cálculo y vacío como información aquí. Se declara,
se deja apagado por configuración.

**e. Fechas incoherentes.** El ciclo cotización→NV tiene mínimo **−89 días**:
hay notas de venta fechadas antes que su cotización. Por eso la fórmula
descarta los negativos y usa mediana.

**f. Un estado fuera del vocabulario.** Una cotización de 2022 tiene
`CtEstado = 'A'`, que no está entre los cuatro documentados. El panel tiene que
tratar lo desconocido como «otro», no romperse ni contarlo como pendiente.

**g. 146 cotizaciones pendientes de más de 90 días**, por $729,6 MM, la más
vieja de 2024-04-29. Un widget «pendientes» sin corte de antigüedad es ruido:
son documentos que nadie va a cerrar. El corte va en la configuración.

**h. La facturación electrónica empieza en 2024.** 209 documentos, ninguno
antes. Comparar «año anterior» hacia atrás de 2024 no tiene contra qué.

**i. 5 facturas de 209 no tienen nota de venta**, y 6 no tienen vendedor
(`CodVendedor` nulo). En el ámbito YO esas seis **no aparecen**, y la suma por
vendedor no cuadra con el total de la empresa. Hay que decirlo en el widget de
empresa, no cuadrarlo a la fuerza.

**j. `nwtsegui` tiene 34 filas.** «Sin seguimiento» no se puede medir con la
tabla de seguimientos: se mide con la antigüedad del documento, y se dice así.

**k. La cotización no tiene fecha de vencimiento propia.** No existe columna;
se calcula con `vigencia_cotizacion_dias` de la identidad (30 por defecto). Si
mañana se quiere por documento, hay que agregarlo en el esquema `ventas`.

---

## 16. Plan por pasos

Cada paso deja la app funcionando y demostrable. Nada de esto toca las fases
1-3 ni la arquitectura offline.

| Paso | Qué | Riesgo |
|---|---|---|
| ~~**1**~~ | ~~`dinero.js` + `periodo.js` + `metricas.js`, con pruebas. Sin UI.~~ **Hecho**, 132 comprobaciones en `npm run pruebas`. | nulo: código nuevo, nadie lo llama todavía |
| ~~**2**~~ | ~~Encabezado con ámbito y período. KPI protagonista + embudo hasta NV, todo offline. Los tres KPI técnicos se van a Cuenta.~~ **Hecho.** | medio: cambia lo primero que ve el vendedor |
| ~~**3**~~ | ~~«Requiere tu atención» con drill-down a las listas ya existentes, filtradas.~~ **Hecho.** | bajo |
| ~~**4**~~ | ~~«Mi rendimiento» (conversión, cierre, ticket) con comparación al período anterior.~~ **Hecho.** | bajo |
| **5** | Actividad reciente **desde el ERP**: `GET /api/panel/actividad` sobre `nw_lognwcotiza` + `nw_lognwnventa`. **Ya no es urgente**: la sección dejó de estar vacía con los últimos cinco documentos de cada tipo leídos de IndexedDB (`ultimos()` en `metricas.js`), que además funcionan sin señal. Lo que añadiría el log es el *movimiento* —quién cambió el estado, quién reasignó el vendedor— y eso necesita red. | bajo |
| **6** | Maestro `facturas` (`iw_gsaen`) en `Maestros.php` + almacén en `idb.js` → el embudo llega a «Facturado» **sin señal**. | bajo: un maestro más, patrón conocido |
| **7** | `ventas.meta` (vendedor, período, monto) + administración desde la app + widget de meta. | bajo |
| **8** | Ámbitos EQUIPO y EMPRESA con el mismo motor y los permisos que ya existen. | bajo |
| **9** | `widgets.js` configurable desde `ventas.config` y los widgets apagados por modelo de negocio. | bajo |
| **10** | Declarar (apagados) stock, despachos, comprometido, ejecución y capacidad, cada uno con su estado «sin fuente». | nulo |

Los pasos 1 a 5 se hacen **sin tocar el servidor** salvo un endpoint de lectura.
El paso 6 es el que convierte el panel en el centro de control completo.
