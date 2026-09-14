# Versiones

La versión vive en **un solo archivo**: `VERSION`, en la raíz del repositorio.
De ahí la leen los tres sitios donde tiene que aparecer, sin que nadie la
escriba a mano en ninguno:

| Quién | Cómo la lee |
|---|---|
| La SPA | `mobile/vite.config.js` la inyecta como `__VERSION__` |
| El APK | `mobile/android/app/build.gradle` arma `versionName` y `versionCode` |
| La API | `config/app.php`, y la devuelve en `/api/ping` y en el bootstrap |

En **Cuenta → Acerca de** salen las dos: la del teléfono y la del servidor. Si
no coinciden, la pantalla lo dice. Es la primera pregunta de cualquier soporte
—«¿qué versión tienes?»— y hasta ahora la respuesta era `0.1.0` para todo,
porque nadie la subía nunca.

## Cómo se sube

```bash
bin/version.sh menor "Panel comercial: rendimiento y actividad real"
```

El primer argumento es `mayor`, `menor` o `parche`; el segundo, el título que
va a quedar en este archivo. El script escribe `VERSION`, sincroniza
`mobile/package.json`, abre la entrada aquí arriba y deja escritos los comandos
de commit y de etiqueta. No hace commit ni etiqueta solo: eso lo decide quien
está trabajando.

Qué es cada tramo, en este proyecto:

- **mayor** — una fase completa que cambia lo que la app es capaz de hacer.
  Se reserva para el `1.0.0`: el día que el ciclo llegue a factura electrónica
  emitida al SII.
- **menor** — una funcionalidad nueva visible para el vendedor.
- **parche** — correcciones y ajustes sin funcionalidad nueva.

El `versionCode` del APK, que Android exige que sea un entero que sólo sube, se
calcula: `mayor × 10000 + menor × 100 + parche`. El `0.5.0` es el `500`.

## Historial

<!-- nuevas entradas arriba -->

### 0.6.0 — Venta es la nota aprobada, y cada lista se actualiza sola
*2026-09-14*

- **Venta es la nota de venta aprobada (`A`) o concluida (`C`).** La pendiente
  no suma al KPI ni al embudo ni al ticket; se informa aparte, bajo el número
  grande, y desde ahí se abre la lista con esas notas. En septiembre del
  vendedor 2 la diferencia son $2,4 MM de 6 notas, de las que 4 están
  aprobadas.
- **La nota de venta nace aprobada donde el ERP no exige aprobación.** Estaba
  al revés: con `nwparam.CheckApruebaNv = N` la app la escribía en `P`, así que
  la venta del vendedor no aparecía en su propio panel. El Softland de
  escritorio las escribe en `A` —736 de 800, con `nvFeAprob` vacío—, y ahora la
  app hace lo mismo. Corregirla y anularla dejaron de mirar sólo el estado:
  miran si **alguien la aprobó** y si ya avanzó a factura, picking o compra.
- **Tirar hacia abajo para actualizar** en cotizaciones, notas de venta,
  clientes y productos, con su botón al lado por si el gesto no se descubre.
  Baja sólo esa lista —las cotizaciones con su detalle son 1.096 filas de las
  14.184 del teléfono— y cada pantalla dice cuándo se actualizó ella, no la app
  entera.
- **Buscar en Softland un documento más viejo que los 12 meses que se
  descargan.** Se escribe el número en el buscador de la lista y, si no está en
  el teléfono y hay señal, se ofrece traerlo. Se ve, se duplica y no se guarda:
  si se guardara, el panel contaría cotizaciones de 2024 entre las vencidas. El
  alcance por vendedor no se toca — la de otro sigue siendo 404.
- **Las emisiones dejaron de heredarse entre documentos con el mismo número.**
  `ventas.documento_emision` guardaba el número y nada más: la cotización 8553
  se entregó por WhatsApp, la borraron desde el escritorio de Softland, y la
  8553 siguiente —otro cliente, otro vendedor— nacía diciendo «ya se le entregó
  al cliente» y no se podía borrar. Ahora cada versión lleva `creado_en`, la
  misma huella que ya usaba `documento_app`.

### 0.5.0 — Panel de control comercial
*2026-09-14*

- El panel deja de informar del estado técnico y empieza a repartir trabajo:
  ámbito (yo / equipo / empresa), período, KPI protagonista de venta y embudo
  comercial.
- «Requiere tu atención» con las cotizaciones por vencer y vencidas, cada fila
  abriendo su lista ya filtrada.
- «Mi rendimiento»: conversión, tiempo de cierre y ticket promedio, cada uno
  contra el período anterior.
- La actividad reciente pasa a ser comercial: las últimas cinco cotizaciones y
  las últimas cinco notas de venta, desde el teléfono y sin señal.
- Botón de duplicar en la cotización y en la nota de venta.
- Borrar la nota de venta devuelve su cotización a pendiente.
- Este sistema de versiones.

### 0.4.0 — Motor de documentos comerciales
*2026-09-13*

- Cotización y nota de venta en PDF A4 con la identidad de la empresa, logo
  configurable y multipágina de verdad.
- Tres caminos al cliente: verlo, correo con el PDF adjunto y WhatsApp por la
  hoja de compartir de Android.
- Lo emitido queda congelado y versionado en `ventas.documento_emision`.
- Anular y eliminar documentos, y el correlativo que se repartía dos veces.

### 0.3.0 — Fase 3: escribir en Softland
*2026-09-13*

- Se crean y corrigen cotizaciones y notas de venta, se les hace seguimiento,
  se cierran por pérdida y se convierten.
- Aprobación del jefe cuando la venta pasa el tope del vendedor: una figura que
  Softland no tiene y que aporta la app.
- Aritmética de totales reproducida contra 200 cotizaciones reales.

### 0.2.0 — Fase 2: el teléfono trabaja sin señal
*2026-09-13*

- 21 maestros a IndexedDB, consulta de clientes y productos sin red.
- Alta y edición de clientes con sus contactos.
- Bandeja de salida: lo escrito sin señal sale solo cuando vuelve.

### 0.1.0 — Fase 1: cimientos
*2026-09-13*

- API Laravel sobre la base Softland con esquema propio `ventas`, instalador
  `/setup`, usuarios y tokens.
- Notificador con 12 eventos del flujo y su bitácora.
- App Android con la paleta Softland, sistema de iconografía propio y
  navegación por pestañas.
