# Estado del proyecto — Venta Softland

> Se actualiza al final de cada sesión. Es lo primero que hay que leer al
> retomar el proyecto, desde este u otro computador.

## Última actualización
2026-09-16 — versión **0.21.0**

## Resumen del estado actual
**Versión 0.7.0. Fases 1, 2 y 3 terminadas, el motor de documentos comerciales
y el panel de control comercial hasta el paso 4 de su plan.** El servidor (API Laravel) está en
`srv:C:\xampp\htdocs\venta-softland`, publicado por Apache en
`http://172.30.205.106:8086/venta-softland` y ya instalado: el esquema `ventas`
existe en INNOVAGES, hay un administrador y las 12 reglas de notificación
sembradas.

Con la fase 2 el teléfono **trabaja sin señal**: se baja 21 maestros a
IndexedDB (14.175 registros, menos de 10 s por WiFi) y desde ahí se buscan
clientes y productos, se leen cotizaciones y notas de venta, y se dan de alta y
se editan clientes con sus contactos. Lo que se escribe sin red queda en una
bandeja de salida y sale solo cuando vuelve.

Con la fase 3 la app **escribe el flujo de venta**: se crean y corrigen
cotizaciones y notas de venta, se les hace seguimiento, se cierran por pérdida,
se mandan al cliente en PDF y se convierten en nota de venta. Cuando la venta
pasa el tope del vendedor, espera el visto bueno del jefe — una aprobación que
Softland no tiene y que aporta la app.

Desde el motor de documentos, la cotización y la nota de venta salen en **PDF
A4 con la identidad de la empresa**: logo configurable, datos heredados de
Softland y corregibles, multipágina de verdad, y tres caminos para llegarle al
cliente — verlo, mandarlo por correo con el PDF adjunto o entregarlo por
WhatsApp con la hoja de compartir de Android. Lo emitido queda congelado y
versionado. Ver `docs/motor-documentos.md`.

Comprobado contra INNOVAGES con `ventas:probe`: 2.350 cotizaciones, 800 notas
de venta, 3.824 clientes, 1.229 productos, 21 vendedores, 594 centros de costo
y folios CAF para factura (33) y nota de crédito (61). Lo que efectivamente
baja el teléfono es menos porque va filtrado (solo clientes, solo productos
vendibles, 12 meses de documentos y solo los del vendedor).

## Hecho
- [x] Relevado el flujo de ventas completo en la base `INNOVAGES` — tablas,
      estados reales y volúmenes (`docs/flujo-ventas-softland.md`).
- [x] Revisado el APK del practicante (Ionic 3 + Cordova) y su servicio Node en
      `srv:E:\Servicio`: se hereda la paleta y los patrones de UI, no el código.
- [x] Esquema `ventas` dentro de la base Softland: usuarios, tokens,
      configuración, reglas de notificación y bitácora.
- [x] Instalador `/setup` con validación de conexión y de la clave de `softland`.
- [x] API: ping, login con token Bearer, bootstrap con maestros, logout;
      administración de usuarios, configuración y notificaciones bajo `rol:admin`.
- [x] Notificador central con 12 eventos del flujo, destinatarios por regla
      (dueño / jefe / cliente / roles / copia fija) y bitácora.
- [x] App Android (Capacitor + Vue 3) con la paleta Softland y las pantallas
      de servidor, login, inicio y administración. APK compilado y verificado.
- [x] Desplegado a `srv`: `composer install`, `APP_KEY` generada, rutas OK.
- [x] Publicado en Apache bajo `/venta-softland` e instalado con `/setup`;
      maestros de Softland verificados uno a uno contra INNOVAGES.
- [x] Botón y gesto «atrás» de Android conectados: cierran la capa abierta,
      si no retroceden de pantalla, y en la raíz piden confirmación para salir.
- [x] Sistema de iconografía propio: una sola familia (Lucide), un componente
      (`AppIcon`), un mapa central de concepto → icono (`mobile/src/iconos.js`)
      y un guardia que falla la compilación si vuelve a entrar un emoji.
      Cero emojis en la app.
- [x] Panel de inicio rediseñado como dashboard: encabezado claro con saludo,
      tira de KPIs desplazable, rejilla de acciones rápidas con el icono en su
      recuadro suave, rejilla compacta de administración y actividad reciente
      (los últimos correos enviados, dato real de la bitácora).
- [x] Avisos con icono de estado (`Aviso.vue`), en las siete pantallas. El
      color solo no basta a pleno sol ni para quien no distingue rojo y verde.
- [x] Navegación inferior por pestañas: **Panel · Avisos · Cuenta**, píldora
      flotante con el nombre solo en la activa y contador de avisos sin leer.
      Se esconde con el teclado y con una hoja encima, y sube `--pie-flotante`
      para que el botón de crear quede sobre ella, no detrás.
- [x] Pantalla **Cuenta**: ficha del usuario, datos descargados, tamaño de la
      interfaz, el bloque de administración (que salió del panel) y el «acerca
      de» con servidor, base, RUT emisor y versión. Cerrar sesión vive aquí, en
      rojo y con confirmación: en el encabezado del panel estaba a un dedazo
      del botón de sincronizar.
- [x] Buzón de avisos del vendedor (`GET /api/avisos`): lo que le llegó a él,
      no toda la bitácora. Pestañas por familia de evento, que las manda el
      servidor (`Eventos::familias()`). Lo leído se guarda en el teléfono.
- [x] Escala de interfaz con tres tamaños (compacta · normal · amplia), una
      sola variable CSS `--d`. Probado en 360×640: las seis acciones rápidas
      caben sobre la barra sin desplazar en las tres escalas.
- [x] Estados vacíos con icono (`Vacio.vue`) y «Ver todo» en la cabecera de
      actividad reciente.
- [x] Botón flotante de crear, abajo y movible a tres anclas (izquierda,
      centro, derecha) con presión larga y arrastre. La posición se guarda en
      el teléfono. Es uno solo para toda la app: cada pantalla solo declara su
      acción (`useAccionCrear`), así que cotizaciones, notas de venta y
      facturación lo heredan sin volver a dibujar nada.

### Fase 2 — consulta y catálogos offline
- [x] `Maestros.php`: un solo servicio declarativo sirve los **21 maestros**
      por páginas. Cada recurso se describe con tabla, clave, mapa de campos y
      filtro; agregar uno nuevo es agregar un arreglo, no un controlador.
- [x] Paginación por **cursor** (clave siguiente), no por `OFFSET`: con 3.373
      clientes el `OFFSET` de la última página vuelve a leer todas las
      anteriores. Soporta claves compuestas (contactos, precios, líneas).
- [x] Alcance por vendedor en el propio maestro: un vendedor solo baja sus
      cotizaciones y notas de venta; supervisor ve las de su gente; admin y
      facturación, todas. **Sin contexto no se abre nada** (`1 = 0`), para que
      un camino nuevo que no sepa de permisos falle cerrado y no abierto.
- [x] Almacenamiento en **IndexedDB** (`mobile/src/idb.js`), sin librería
      envoltorio. Búsqueda por índice `multiEntry` de palabras normalizadas:
      «compania» y «COMPAÑÍA» dan las mismas 43 fichas, en 6 ms.
- [x] Sincronización **incremental** por `FechaUlMod` donde la columna sirve
      (clientes y productos), completa en el resto. Reloj del servidor, nunca
      del teléfono.
- [x] **Sello de corrida**: la descarga completa marca cada fila con la hora de
      la corrida y al final borra las que quedaron con sello viejo. Es la única
      forma de enterarse de lo que se borró en Softland. El almacén no se vacía
      al empezar: una descarga cortada no puede dejar al vendedor con medio
      catálogo.
- [x] Reconciliación por cuenta: si tras una corrida incremental lo local no
      cuadra con el total del servidor, se repite completa sola.
- [x] Sincronización **reanudable**: cursor, sello y fecha se guardan después
      de cada página.
- [x] Pantallas nuevas: **Clientes** (4ª pestaña), ficha de cliente con alta y
      edición, **Productos** con filtro por grupo y hoja de precios, y
      **Cotizaciones** y **Notas de venta** de solo lectura con su detalle.
- [x] **Bandeja de salida** (`pendientes.js`): lo que se guarda sin señal sale
      solo al volver la red. Idempotente porque la clave del cliente es el RUT:
      un reintento choca con la clave primaria y el 409 se toma como éxito.
- [x] `ClienteController`: alta y edición contra `cwtauxi`/`cwtaxco`, único
      lugar que escribe en tablas nativas de Softland. Edición **parcial** (de
      62 columnas se tocan las diez que la app conoce), claves foráneas
      verificadas antes de escribir para devolver un 422 legible en vez de un
      500 con el nombre del constraint, y auditoría como la escribe el ERP.
- [x] `Selector.vue`: elegir un código de un maestro largo con filtro de texto
      — giros (2.009), ciudades (937), cargos (606), comunas (352). De paso
      resuelve el `<select>` de 594 centros de costo que estaba en el backlog.
- [x] Probado de punta a punta contra INNOVAGES: alta sin señal, envío al
      volver la red, edición parcial, descarte, 409 por RUT repetido y borrado
      del cliente de prueba. La base quedó con sus 3.373 clientes y 2.816
      contactos originales.

### Fase 3 — cotización y nota de venta
- [x] **Aritmética de Softland reproducida columna por columna**
      (`app/Services/Softland/Totales.php`), sacada de recalcular 200
      cotizaciones reales: subtotal por línea, descuento a peso entero, reparto
      proporcional del descuento de pie entre lo afecto y lo exento, IVA sobre
      lo afecto y `CtMonto = CtSubTotal − CtTotalDesc + Σ Impto`. Se comprobó
      con documentos con y sin exento y con y sin descuento de encabezado.
- [x] **Correlativo resuelto.** `CotNum` y `NVNumero` **no son IDENTITY** y en
      toda la base no existe tabla de correlativos: se buscó en `nwparam`,
      `cwfoliossueltos`, `iw_ultcorrelptovta`, `SO_Numeros` y en todo lo que se
      llamara «corr», «folio» o «numer». Softland de escritorio lo calcula solo,
      y la app hace lo mismo: máximo bajo `UPDLOCK, HOLDLOCK` dentro de la
      transacción, con reintento si la clave primaria choca.
- [x] **Idempotencia por `client_uuid`** (`ventas.documento_app`): reenviar un
      documento que ya se escribió devuelve su número, no crea otro. A
      diferencia del cliente, aquí no hay clave natural con que chocar.
- [x] La **moneda de la línea** se resuelve en el servidor: el vendedor escribe
      el precio en la moneda del documento y `Equivalencia.php` lo devuelve a la
      del producto con la UF del día (`softland.so_UF`).
- [x] Cotización: alta, corrección, **seguimiento** (`nwtsegui`), **cierre por
      pérdida** con motivo (`nwperdida`) y **envío al cliente con PDF adjunto**.
- [x] Nota de venta: alta directa o **por conversión**, dejando la cotización en
      `V`. Centro de costo obligatorio, como manda `nwparam.CheckExigeCCostoN`.
- [x] **Aprobación del jefe por topes** (`ventas.aprobacion`), que no existe en
      Softland: la NV que pasa el tope de descuento o de monto nace en `P` y
      espera; aprobada pasa a `A` con `nvFeAprob`, rechazada a `C`. Corregirla
      por debajo del tope retira la solicitud sola.
- [x] Pantallas: **editor** de documento (una sola para los dos tipos), acciones
      en la ficha, **Aprobaciones** para el jefe y aviso en el panel.
- [x] **Se cotiza sin señal**: el teléfono calcula el mismo total que el
      servidor y lo que se crea sin red va a la bandeja de salida y sale solo.
      Corregir un documento que ya está en Softland sí exige señal, a propósito.
- [x] **Mensajes de validación en castellano** (`lang/es/validation.php`): antes
      el vendedor veía «validation.required».
- [x] Probado de punta a punta contra INNOVAGES desde la app: alta, corrección,
      seguimiento, conversión, aprobación, rechazo y alta sin señal con envío al
      volver la red. La base quedó con sus 2.350 cotizaciones y 800 notas de
      venta originales; los documentos de prueba se borraron.

### Motor de documentos comerciales
- [x] **Un solo motor para todos los tipos** (`app/Services/Documentos/`): el
      tipo documental es un `case` de enum que declara título y bloques, no una
      plantilla propia. Factura, boleta, guía, nota de crédito, orden de
      servicio y comprobante de cobranza ya están declarados; sus bloques
      legales llegan con la fase 4.
- [x] **Identidad corporativa configurable** (`ventas.config` clave
      `identidad`): razón social, RUT, giro, dirección, comuna, fono, correo,
      web, color, condiciones comerciales, datos bancarios, pie, vigencia de la
      cotización y modelo de negocio. Cada campo **hereda de `soempre`** y el
      override es opcional, así que una instalación nueva funciona sin
      configurar nada.
- [x] **Logo subible desde la app**, con la validación que sirve: se decodifica
      la imagen y se guarda **otra**, generada a partir de sus píxeles. Vive en
      `storage/app/private/identidad`, fuera de git y fuera de `public/`.
- [x] **PDF A4 multipágina**: cabecera y pie fijos, `<thead>` repetido, ninguna
      fila partida, totales que no se quedan solos y «Página 1 de 3» correcto.
      Probado con una cotización de 28 líneas (tres páginas).
- [x] **Columnas que se adaptan**: la de conversión aparece sola cuando alguna
      línea viene en otra moneda que el documento; el código y la unidad se van
      cuando la empresa vende servicios.
- [x] **Snapshot versionado** (`ventas.documento_emision`): lo entregado no se
      toca; corregirlo crea la versión siguiente. Si el documento no cambió, no
      hay versión nueva.
- [x] **Envío de la nota de venta al cliente**, que antes sólo tenía la
      cotización, con su evento propio (`nv_enviada`).
- [x] **PDF en el teléfono** (`mobile/src/pdf.js`): se guarda lo que bajó y se
      abre y se comparte sin señal. Tope de 50 documentos por uso.
- [x] **Envío por WhatsApp** con la hoja de compartir de Android
      (`@capacitor/share` + `@capacitor/filesystem`), con el mensaje ya escrito.
- [x] Pantalla **Identidad** en administración, con vista previa del logo sobre
      tablero de cuadros para que se note la transparencia.

## Pendiente / próximos pasos
- [ ] Crear los primeros vendedores y probar la app con un usuario que no sea
      admin: el alcance por vendedor está probado contra la base, pero no con
      alguien usando el teléfono.
- [ ] Configurar el SMTP desde la app y mandar un correo de prueba.
- [ ] **Configurar el SMTP y probar el envío de la cotización al cliente.** El
      correo con PDF está escrito y el PDF se comprobó generado; lo que no se
      pudo probar es que salga, porque no hay servidor de correo configurado.
- [ ] **Completar la identidad de INNOVAGES desde la app**: el logo ya está
      cargado, pero la dirección comercial, las condiciones de pago y los datos
      bancarios siguen heredando de `soempre`. Son datos del negocio: los tiene
      que escribir quien los sepa, no yo.
- [ ] **Cargar cargo y teléfono de los vendedores** (`ventas.usuario.cargo` y
      `.fono`, columnas nuevas): la firma del PDF sale sin ellos hasta que se
      llenen.
- [ ] **Fase 4, paso 2b**: el camino de conversión NV → factura línea por
      línea. Solo hay 2 casos reales contra los que contrastarlo, y arrastran
      los decimales de la NV en vez de redondear a peso.
- [ ] **Decidir la llave de configuración** que permite cambiar el cliente a
      facturar (el caso de la comisión). Va en `ventas.config`.
- [ ] **Fase 4.5 — el ciclo normal de venta**: plan en `docs/ciclo-normal.md`.
      **Terminada**: los seis pasos, con la pantalla de facturación incluida.
- [ ] **Fase 4, paso 4**: el primer envío de verdad. Todo el camino está
      probado menos el último paso, que no se deshace. De factura queda **un
      solo folio libre, el 235**.
- [ ] **Fase 4, paso 3c**: el espejo del documento en `dte_doccab` y
      `dte_docdet` —las setenta columnas que replican el XML—. El SII no lo
      necesita y la app no lo lee; hace falta para las ventanas de DTE del
      Softland de escritorio.
- [ ] **Escribir el bloque `<DscRcgGlobal>`** si alguna vez hace falta descuento
      de pie. Hoy el generador falla antes de emitir un documento así.
- [ ] **Solicitar al SII los folios CAF de boleta electrónica (DTE 39 y 41)** a
      nombre de 77828631-9 — es el bloqueo de plazo más largo del proyecto, y
      es lo único que falta del lado de la boleta: el código ya está probado.
      Los CAF de NETDOMAIN son de otro RUT y no se prestan.
- [ ] **Renovar el certificado digital antes del 26 de diciembre de 2026.** Es
      el mismo que usa Softland; cuando venza, deja de emitir la app y el ERP.

## Decisiones importantes tomadas
- **Solo móvil, sin panel web.** El servidor es API pura; la administración se
  hace desde la app con rol admin. La única página HTML es `/setup`, porque la
  conexión a SQL tiene que existir antes que cualquier usuario o dispositivo.
- **Esquema propio `ventas` dentro de la base Softland**, no una base aparte:
  así el respaldo de la empresa incluye los datos de la app y no hay que cruzar
  bases (las colaciones difieren entre bases y los JOIN revientan con error 468).
- **El teléfono nunca habla con SQL Server.** El motor no escucha en la red;
  además, exponerlo obligaría a repartir credenciales de base en los teléfonos.
- **Paleta heredada** de la app anterior (índigo `#1d1060` + cian `#26bdef`):
  los vendedores ya la reconocen, y es el color corporativo de Softland.
- **Nada de `MAX(id)+1` para correlativos ni SQL por concatenación**: son los
  dos defectos del servicio anterior que no se heredan.
- **Almacenamiento local con Preferences en fase 1**, IndexedDB desde la fase 2:
  Preferences guarda un string por clave y no sirve para buscar entre miles de
  productos, pero para token y maestros chicos alcanza y evita una dependencia.
- **El orquestador de la sincronización vive en el teléfono.** El servidor
  sirve páginas y no recuerda qué bajó quién: no hay estado por dispositivo que
  mantener, y un teléfono que se formatea no deja basura en la base.
- **El reloj de lo incremental es el del servidor** (`servidor_at` de cada
  respuesta), nunca el del teléfono. Un aparato con la hora corrida se saltaría
  cambios para siempre y nadie se enteraría.
- **La búsqueda es por principio de palabra, no por trozo suelto.** «rojas»
  encuentra a CLAUDIA ROJAS y «mauricio rojas» exige las dos, en cualquier
  orden; «auricio» no encuentra nada. Es como busca la gente y es la diferencia
  entre responder en 3 ms y recorrer 3.373 fichas en cada tecla.
- **Un 409 al reintentar un alta es éxito, no error.** La clave del cliente es
  el RUT: si el teléfono mandó el alta y se cortó antes de la respuesta, el
  reintento choca con la clave primaria y eso significa que ya está creado.
- **La edición de cliente es parcial de verdad.** Solo se escriben las columnas
  que vinieron en la petición. `cwtauxi` tiene 62 columnas y la app conoce
  diez: escribirlas todas dejaría en NULL el giro y la dirección de alguien que
  solo quiso corregir un teléfono.
- **El precio de una línea de documento está en la moneda del producto**, no en
  la del documento; `CtEquiv`/`nvEquiv` es el factor entre las dos. Un tercio
  de las líneas de INNOVAGES lo tiene distinto de 1 (producto en UF, documento
  en pesos). Mostrar el precio a secas escribe «1 UNIDAD × $ 5 = $ 214.735».

- **`public/.htaccess` lleva `RewriteBase /venta-softland/` y es obligatorio**:
  el archivo se copió de rinde-caja con su `RewriteBase /rinde-caja/`, y como
  las dos apps comparten el vhost del 8086, el rewrite entregaba las peticiones
  al `index.php` de rinde-caja. El síntoma era un 404 de Laravel en vez de uno
  de Apache, que confunde: parecía un problema de rutas y era de Apache.

- **La IP de `srv` en la LAN es `192.168.1.55`, no `172.30.205.106`.** La
  segunda es de ZeroTier: la usan las máquinas de desarrollo, pero un teléfono
  en el WiFi de la oficina no la alcanza y la app dice «no se pudo llegar al
  servidor». Para los vendedores la dirección es
  `http://192.168.1.55:8086/venta-softland`. Apache escucha en `0.0.0.0:8086`
  y el firewall de Windows está desactivado en los tres perfiles, así que no
  hay nada más que abrir.

- **Queda arriba la flecha de volver, no el ＋.** Crear ya se hace desde el
  botón flotante del pie y retroceder tiene el gesto nativo.
- **Tres pestañas y no más, y se navegan con `replace`.** Una pestaña es un
  lugar al que se vuelve, no una acción: por eso cotizaciones y productos son
  acciones del panel y no pestañas. Con `push` en vez de `replace`, «atrás»
  recorrería el historial de saltos entre pestañas en vez de salir de la app,
  que es lo que espera la mano. Clientes entra como cuarta con la fase 2.
- **La pantalla de reglas se llamaba «notificaciones».** Ese nombre pasó a ser
  el del buzón del vendedor, así que ahora son `/reglas` (qué correo sale y a
  quién) y `/avisos` (lo que me llegó). `/notificaciones` queda como redirección.
- **Lo leído del buzón se guarda en el teléfono, no en la base.** Así el
  contador funciona sin señal y abrir la pestaña no escribe en SQL Server cada
  vez. Lo que sí es del servidor es a quién le corresponde cada aviso.
- **El tamaño de la interfaz lo elige el vendedor, no nosotros.** Una sola
  variable (`--d`) y tres escalas. No se escalan nunca los 44 px de área
  pulsable, los 16 px de los campos ni el texto de 12 px o menos.
- **El pie de la pantalla tiene dueño: `--pie-flotante`.** Todo lo que flote
  abajo (botón de crear, avisos) cuelga de esa variable, que levanta 28 px
  sobre el área segura. La razón no es estética: en los teléfonos con barra de
  tres botones, «atrás» queda justo debajo del borde de la app, y un pulgar
  que apunta al botón flotante y se queda corto se saldría de la pantalla. Si
  alguna vez se baja ese valor, vuelve el problema.
- **Lo que flota se esconde con el teclado abierto** (`teclado.js`, evento
  `keyboardWillShow`). Si no, el botón queda montado sobre las teclas. Vale
  para cualquier control que se agregue al pie más adelante.
- **`enableOnBackInvokedCallback` debe seguir ausente** del `AndroidManifest`.
  Si se activa el «atrás predictivo» de Android 13, el oyente `backButton` de
  Capacitor deja de dispararse y la navegación vuelve a romperse.

- **El correlativo de los documentos se calcula, porque Softland no lo guarda.**
  `CotNum` y `NVNumero` no son IDENTITY y no hay tabla de correlativos en toda
  la base. Se toma el máximo bajo `UPDLOCK, HOLDLOCK` dentro de la transacción y
  se reintenta si la clave primaria choca — que es lo que pasa si Softland de
  escritorio graba en el mismo instante, porque él no toma ese candado.
- **El estado `P` de las cotizaciones era el valor por defecto de la columna.**
  `CtEstado` tiene `DEFAULT ('P')`: las 189 cotizaciones en `P` son las que se
  grabaron sin fijar el estado. La app escribe `N` explícito al crear.
- **El precio se escribe en la moneda del documento y se guarda en la del
  producto.** El vendedor negocia en pesos aunque el producto esté tarifado en
  UF; la división por la UF del día pasa en el servidor (`Equivalencia.php`) y
  en ningún otro lado. El teléfono nunca guarda un factor de cambio.
- **Guardar no es enviar.** La cotización no le manda correo al cliente al
  grabarla: el envío es un camino aparte (`POST /cotizaciones/{n}/enviar`) con
  su propio botón. Una cotización se corrige tres veces antes de mandarla.
- **La aprobación por topes es de la app, no de Softland.**
  `nwparam.CheckApruebaNv = N`: el ERP no la pide. Se refleja igual en
  `nvEstado` y `nvFeAprob`, que son columnas suyas, para que la nota de venta se
  vea pendiente también desde el escritorio.

- **El tipo de documento es un dato, no una plantilla.** Siete tipos comparten
  el noventa por ciento del papel; duplicar la plantilla por cada uno es
  garantizar que un día la dirección de la empresa salga distinta en la factura
  y en la guía.
- **El PDF se dibuja en el servidor y el teléfono lo archiva.** El número del
  documento lo pone el servidor: una cotización sin señal todavía no lo tiene, y
  un PDF que dice «Cotización N° —» es un borrador, no un documento comercial.
  Dibujarlo en el teléfono obligaría además a mantener una segunda plantilla en
  JavaScript, que es el problema que `Totales.php` ya obliga a vigilar a mano.
- **Softland propone y la configuración dispone.** Casi toda la ficha de la
  empresa está en `soempre` y se hereda; el override existe porque las dos
  direcciones son verdad — el ERP guarda la tributaria y el documento muestra la
  comercial.
- **El logo se guarda decodificado y vuelto a codificar.** Comprobar extensión o
  MIME no protege de nada: lo que se almacena es un PNG nuevo dibujado con los
  píxeles del original, así que nada escondido en los metadatos sobrevive.
- **La huella del snapshot es del HTML, no del PDF.** Dompdf estampa la fecha de
  creación dentro del archivo: dos PDF del mismo documento tienen bytes
  distintos, y un hash de los bytes nunca coincidiría consigo mismo.
- **El paginado se escribe después de `render()`.** Dentro del HTML sólo se
  puede con un bloque `<script type="text/php">`, que corre mientras dompdf
  maqueta y todavía no sabe cuántas páginas van a salir: el resultado era
  «Página 1 de 1» en un documento de dos, y sólo en la primera hoja.
- **`wa.me` sólo transporta texto.** No hay forma de adjuntar un archivo por un
  enlace de WhatsApp. El PDF va por la hoja de compartir del sistema, donde el
  vendedor elige el chat. Y un enlace al PDF del servidor no sirve:
  `192.168.1.55:8086` no existe fuera de la oficina.

### Documentos que Softland sí reconoce
- [x] **Arreglado el estado con que nacen los documentos.** Se escribía `N`
      leyendo el estado más frecuente de la base como «nueva». En Softland `N`
      es **nula**: la app llevaba desde la fase 3 creando documentos anulados.
      La cotización nace en `P`, y el estado de la nota de venta lo decide el
      ERP (`nwparam.CheckApruebaNv`: `S` → `A`, `N` → `P`).
- [x] **El vendedor nunca queda en nulo.** Era la causa de que la cotización y
      la nota de venta no aparecieran en las ventanas de búsqueda del Softland
      de escritorio: de las 2.351 cotizaciones de la base, las únicas con
      `VenCod` nulo eran las que había escrito esta app, grabadas por un
      administrador que no tiene vendedor asociado. Ahora el editor lleva su
      propio campo **Vendedor**, y si no hay ninguno que poner el servidor
      devuelve un 422 en vez de escribir un documento invisible.
- [x] **La auditoría va donde la pone el ERP**: quien crea el documento en
      `UsuarioGeneraDocto` y `Usuario` vacío, no al revés.
- [x] **Campos del detalle que faltaban**: `CtFecCompr` / `nvFecCompr` con la
      fecha del documento, `nvCorrela` en cero y `DetProd` siempre lleno — lo
      que escriba el vendedor o, si no escribe, la descripción del maestro. El
      detalle de cada línea es **editable** desde el teléfono, que es como
      trabaja el ERP: en INNOVAGES hay líneas que dicen «ADV» donde el maestro
      dice «Business».
- [x] **Los filtros de las listas son los cuatro estados de Softland** y no
      otros: pendiente · en nota de venta · nula · perdida en la cotización;
      pendiente · aprobada · concluida · nula en la nota de venta.
- [x] **El papel muestra el estado.** Quien recibe la cotización por correo no
      tiene la app delante para distinguir una vigente de una perdida.
- [x] **La fecha de entrega y la orden de compra se arrastran** de la cotización
      a la nota de venta. Sin fecha pactada va la del documento: en las 2.351
      cotizaciones de INNOVAGES no hay una sola con `CtFeEnt` nulo.

### Anular, eliminar y el número que se repartía dos veces
- [x] **Anular** (`CtEstado`/`nvEstado` = `N`): el documento se queda, conserva
      su número y deja de contar. Es lo que corresponde si el papel ya salió —
      el cliente tiene un PDF con ese número. Sólo desde pendiente.
- [x] **Eliminar**: borra la fila de Softland. **Casi todo el barrido lo hacen
      los triggers del ERP** — detalle, impuestos, adjuntos, aprobaciones y el
      evento `Elimina` en la bitácora, que sobrevive al borrado. Lo único que
      hay que barrer a mano en la cotización son los seguimientos y los
      adjuntos, que tienen FK `NO_ACTION` y si no bloquean el borrado.
- [x] **Cuatro condiciones para eliminar**: que la haya creado la app, que nunca
      saliera al cliente, que no haya avanzado a nada (nota de venta, factura,
      picking, compra) y que esté pendiente o anulada. El 409 devuelve **todas**
      las razones y si todavía se puede anular. En la pantalla los dos botones
      sólo aparecen donde van a funcionar: una cotización perdida o ya
      convertida no los muestra.
- [x] **El mapa de idempotencia ya no puede apuntar al documento de otro.**
      `MAX + 1` reparte de nuevo el número de lo que se borró — en INNOVAGES hay
      4.453 huecos en las cotizaciones, y en las pruebas el 8553 llegó a estar
      asignado a tres documentos seguidos. `documento_app.creado_en` guarda el
      mismo instante que `FechaHoraCreacion`; si no coinciden, la fila está
      muerta y el documento se escribe de nuevo con número nuevo. Probado: el
      `client_uuid` de un documento borrado escribe uno nuevo (201) y el de uno
      vivo devuelve el mismo sin duplicar (200).
- [x] **Corregir ya no le cambia la fecha de nacimiento al documento.** Las
      columnas de creación se escribían también al actualizar, así que cada
      corrección le ponía `FechaHoraCreacion` de hoy y reasignaba el autor.

### Borrar una nota de venta deshace la conversión
- [x] Eliminar la NV **devuelve su cotización a pendiente**. `V` no es un
      desenlace de la cotización: quiere decir «tiene nota de venta». Si la NV
      desaparece y la cotización se queda en `V`, miente — aparece vendida, no
      se puede corregir y no se puede volver a convertir. Sólo se devuelve la
      que está en `V` y sólo si no le queda otra NV apuntando; una perdida o
      anulada tuvo su propio desenlace. El servidor responde
      `cotizacion_liberada` y el teléfono corrige su copia en IndexedDB.
      Probado de punta a punta: cotizar → convertir (`V`) → borrar NV → `P`.

### Control de versiones
- [x] **`VERSION` en la raíz**, una sola fuente: la SPA la inyecta desde
      `vite.config.js`, Gradle arma con ella el `versionName` y calcula el
      `versionCode` (`mayor × 10000 + menor × 100 + parche`), y la API la
      devuelve en `/api/ping` y en el bootstrap desde `config('app.version')`.
      Antes el APK decía `1.0`, `package.json` decía `0.1.0` y nadie los subía.
- [x] **`docs/versiones.md`** con el historial reconstruido del repositorio
      (0.1.0 fase 1 · 0.2.0 fase 2 · 0.3.0 fase 3 · 0.4.0 motor de documentos ·
      0.5.0 panel comercial) y **`bin/version.sh mayor|menor|parche "Título"`**,
      que sube el número, sincroniza `package.json` y abre la entrada. No hace
      commit ni etiqueta solo.
- [x] **Cuenta muestra las dos versiones**, la del teléfono y la del servidor,
      y avisa cuando no coinciden. Comprobado forzando el desajuste.

### Panel de control comercial — auditoría
- [x] **`docs/panel-comercial.md`**: auditoría del panel actual, inventario de
      datos, 24 KPI clasificados por fuente, disponibilidad offline, ámbito y
      fase, fórmulas exactas, arquitectura de widgets, endpoint, wireframes de
      YO / EQUIPO / EMPRESA, once inconsistencias encontradas en Softland y un
      plan de diez pasos. Todo contrastado contra INNOVAGES el 2026-09-14.
- [x] **`mobile/src/dinero.js`** (paso 1 del plan): `$84.500` · `$850 mil` ·
      `$12,6 MM` · `$1.250 MM`, variación en porcentaje y en puntos
      porcentuales, y el «sin dato» que no se confunde con `$0`. La flecha no
      va en el texto: va la dirección y la dibuja `<AppIcon>`. 47
      comprobaciones en `npm run pruebas`, sin dependencias nuevas.
- [x] **Paso 1 — el motor** (`mobile/src/panel/`): `periodo.js` (hoy · semana ·
      mes · trimestre · año, con su período anterior del mismo largo) y
      `metricas.js`, funciones puras sin una sola lectura dentro: reciben
      arreglos y devuelven cotizado, vendido, conversión de cohorte, mediana
      de cierre, ticket, pérdidas y la antigüedad de lo pendiente. Quien va a
      IndexedDB es `datos.js`. 98 comprobaciones en `npm run pruebas`.
- [x] **Paso 2 — el panel**: encabezado con **ámbito** (Yo · Equipo/Empresa,
      sólo cuando hay diferencia entre los dos) y **período**, KPI protagonista
      con la variación contra el período anterior, y **embudo comercial** de
      tres etapas. La tercera, «Facturado», está declarada y apagada con
      «No sincronizado»: un embudo que termina en vendido haría creer que ahí
      se acaba el negocio. Los tres KPI técnicos salieron del panel —ya
      estaban en Cuenta— y quedó una línea al pie: «Actualizado hoy 05:20».
      Todo se calcula **en el teléfono**, sin una petición.
      Comprobado contra el ERP: 2026 · empresa da 134 cotizaciones y 34 notas
      de venta por $79,3 MM, y el ámbito «Yo» del vendedor 2 da 126 y 6. Sin
      desplazamiento horizontal en 360×640 en las tres escalas de interfaz, con
      montos de hasta `$1.250 MM`.
- [x] **Paso 3 — «Requiere tu atención»**: el panel deja de informar y empieza
      a repartir trabajo. Una fila por cosa que hacer, ordenadas por lo que
      pasa si nadie las toca —el visto bueno que detiene una venta, lo que
      está escrito en el teléfono y todavía no en Softland, lo que vence en
      días y todavía se puede cerrar, lo que ya venció— con la franja izquierda
      diciendo cuánto corre y el texto diciendo lo mismo con palabras. Sólo
      aparece lo que existe, y cuando no hay nada lo dice: «Nada pendiente».
      **El corte de vencimiento no es un número inventado**: es
      `identidad.vigencia_cotizacion_dias`, el mismo que sale impreso en el
      PDF, que ahora viaja en el bootstrap junto al IVA y la UF.
      Cada fila **abre la lista ya filtrada** (`?atencion=por_vencer`), y la
      regla que decide cuál es cuál está en una sola función —`situacion()`—
      que usan el panel para contar y la lista para filtrar. Comprobado: el
      panel dice 6 por vencer y 77 vencidas, y la lista muestra 6 y 77.
- [x] Diagnosticado por qué «Actividad reciente» se veía vacía: leía los correos
      enviados y `ventas.notificacion` tiene 0 filas. La fuente buena para el
      *movimiento* sigue siendo la bitácora de Softland — `nw_lognwcotiza`
      (15.795 filas) y `nw_lognwnventa` (3.139), con eventos ya redactados:
      «Estado En Nota de Venta», «Estado Perdida», «Elimina» —, que es el paso
      5 del plan y necesita red.
- [x] **Paso 4 — «Mi rendimiento»**: conversión, tiempo de cierre y ticket
      promedio, cada uno con su comparación al período anterior y su frase de
      dónde sale el número. La comparación del cierre va **en días**, no en
      porcentaje: «bajó 3 d» se entiende y «bajó un 21 %» hay que deshacerlo.
      Y el color dice si la noticia es buena mientras la flecha dice hacia
      dónde se movió el número: cerrar antes es flecha abajo y chip verde.
      Cada medida se cae sola cuando no hay con qué calcularla y lo dice en su
      sitio, en vez de mostrar un cero que parece un dato.
- [x] **Actividad reciente comercial**: las últimas cinco cotizaciones y las
      últimas cinco notas de venta, con cliente, monto, fecha y estado, cada
      una abriendo su documento. Sale de IndexedDB —funciona sin señal—, no
      mira el período (un panel de septiembre con actividad vacía parece roto
      cuando lo que hay que ver es agosto) y sí muestra lo anulado, porque
      anular es algo que se hizo. Dejó de ser una sección de administrador.
- [x] **Duplicar** cotización y nota de venta: abre el alta con el documento ya
      cargado (`/cotizaciones/nuevo?desde=8550`), con `client_uuid` nuevo, así
      que lo que se guarda es un documento nuevo y la idempotencia y el
      guardado sin señal siguen funcionando sin saber que vienen de una copia.
      Duplicar no toca el servidor: la copia vive en el formulario hasta que se
      guarda. La fecha de entrega no se arrastra si ya pasó. Probado de punta a
      punta: copia de la 8550 → cotización 8554 idéntica → eliminada, y la base
      volvió a 2.351 cotizaciones con máximo 8553.
- [x] **Corregido el rótulo del período anterior**: `rango('hoy', ayer)`
      devolvía la etiqueta «Hoy», y el panel comparaba «vs. hoy». Ahora «Hoy» y
      «Esta semana» sólo se llaman así cuando contienen el día de verdad.
- [x] **El panel mide en neto**, no en el total con IVA: neto afecto + neto
      exento. El IVA no es venta y además no infla parejo, porque lo exento no
      lo lleva. Lo dice escrito en dos sitios del panel, y dos filas de las
      pruebas traen un `total` disparatado para que la suite se caiga si
      alguien vuelve a sumar esa columna.

### Venta es la nota de venta aprobada
- [x] **Sólo `A` y `C` cuentan como venta.** La pendiente (`P`) está escrita y
      sin autorizar: no entra en el KPI, ni en el embudo, ni en el ticket, ni
      en el tiempo de cierre. Se informa **aparte**, bajo el número grande —«2
      notas más esperan aprobación · $2,4 MM que todavía no cuenta»— y desde
      ahí se abre la lista filtrada (`/notas-venta?estado=P`). Sin eso, el
      vendedor cuenta seis notas en su lista, el panel dice cuatro y la
      diferencia parece un error de la app.
      La **conversión** sí las cuenta: la pregunta ahí es «¿llegó a nota de
      venta?», y la cotización ya quedó en `V` en Softland.
- [x] **Arreglado el estado con que nace la nota de venta.** Estaba invertido:
      `estadoInicialNotaVenta()` devolvía `P` cuando `nwparam.CheckApruebaNv`
      valía `N`, o sea que en INNOVAGES —que lo tiene en `N`— la app escribía
      todas sus notas de venta pendientes de una aprobación que el ERP no pide.
      Los datos lo dicen: de las 800 notas de la base **736 están en `A` y sólo
      8 tienen `nvFeAprob`**, así que el Softland de escritorio las escribe
      aprobadas de entrada. Con la corrección de arriba el efecto era doble: la
      venta del vendedor no aparecía en su propio panel.
- [x] **Corregir y anular una NV dejaron de mirar sólo `nvEstado`.** Si `A`
      cerrara el documento, el vendedor no podría tocar la que acaba de
      escribir. La regla completa está en `Ventas::corregibleNotaVenta()`: que
      nadie la haya aprobado (`nvFeAprob` vacío) y que no haya avanzado a
      factura, picking o compra. El teléfono repite la mitad que puede ver y el
      servidor contesta 409 con el resto.

### Tirar hacia abajo para actualizar
- [x] **Refresco por lista** en cotizaciones, notas de venta, clientes y
      productos (`mobile/src/refresco.js`, `sync.js` → `GRUPOS`,
      `GET /api/catalogo?solo=…`). Baja sólo esa pantalla: cotizaciones con su
      detalle son 1.096 filas de las 14.184 del teléfono, notas de venta 227.
      Cada lista dice **cuándo se actualizó ella**, no la app entera, y lleva
      un botón «Actualizar» al lado, porque un gesto que no se ve no existe
      para quien no lo descubre.
- [x] **Es descarga completa del maestro, a propósito.** No hay forma de
      preguntarle a Softland qué cambió: `nwcotiza` sólo tiene
      `FechaHoraCreacion`, que no se mueve cuando la cotización pasa a vendida
      o a perdida, y `nw_nventa.FechaUlMod` está lleno en 15 de 800 filas —las
      que escribió esta app— porque el escritorio no lo toca. Como es completa,
      el barrido por sello se entera también de **lo borrado**. Comprobado
      borrando la NV 2063 del teléfono e inventando una 999999: tras el tirón,
      la 2063 volvió y la inventada desapareció, sin tocar clientes ni
      cotizaciones.
- [x] El gesto sólo arranca con la lista arriba del todo, tiene resistencia de
      un medio, tope de 104 px y umbral de 68. Probado con eventos táctiles:
      tirón corto, lista desplazada y arrastre hacia arriba no disparan nada.

### La app detrás del proxy HTTPS
- [x] **`venta.netdomain.cl` (IIS/ARR en el 443) hacia
      `http://172.30.205.106:8086/venta-softland/`.** La dirección para la app
      es `https://venta.netdomain.cl`, **sin la carpeta**: la pone el proxy.
      Comprobado de punta a punta: `Authorization` pasa, el inventario de 22
      maestros tarda 0,13 s, una página de 500 clientes son 160 KB, el PDF
      llega entero con sus cabeceras y la sincronización completa baja los
      14.139 registros.
- [x] **Arreglado por qué la app no llegaba y el navegador sí.** La pantalla
      Servidor suponía `http://` cuando no se escribía el esquema. El puerto 80
      del proxy responde **301 a https**, y ahí se rompen dos cosas a la vez: la
      respuesta de redirección no lleva `Access-Control-Allow-Origin`, así que
      ni el ping pasa; y **una redirección en la comprobación previa de CORS no
      se sigue nunca** («Redirect is not allowed for a preflight request»), así
      que el login habría muerto igual. Ahora se prueban los dos esquemas —https
      primero si la dirección parece un dominio público, http primero si es una
      IP o lleva puerto— y se guarda `res.url`, la dirección ya resuelta.
      Probado escribiendo `venta.netdomain.cl` a secas: queda guardada
      `https://venta.netdomain.cl` y el login responde.
- [x] **Las cabeceras propias del PDF volvieron a leerse.** `config/cors.php`
      tenía `exposed_headers` vacío, y con `allow-origin: *` el navegador sólo
      entrega las siete de la lista segura: `X-Documento-Version` y
      `X-Documento-Hash` llegaban nulas desde siempre, así que el teléfono
      archivaba todos los PDF como «versión 1, sin huella». No daba error, sólo
      dejaba de saber si el PDF guardado seguía siendo el vigente.

### El icono y la pantalla de arranque
- [x] **La app dejó de instalarse con el icono de Capacitor.** El logo de la
      empresa —el mundo azul— está en `mobile/recursos/icono.png` y de ahí
      salen las 26 imágenes que pide Android: cinco densidades por tres formas
      de icono y once pantallas de arranque, con
      `python3 mobile/scripts/icono-app.py`.
- [x] **El icono adaptable está a la medida de la máscara.** La lámina es de
      108 dp pero sólo se ven los 72 centrales; el resto lo usa el lanzador
      para el efecto de movimiento. El logo se dibuja justo de esos 72 dp: en
      máscara redonda queda a ras y en cuadrada el blanco del fondo
      (`@color/ic_launcher_background`, ya estaba en `#FFFFFF`) le hace marco.
      Comprobado abriendo el APK y recortándolo con las dos máscaras.
- [x] **Fuera los recursos por defecto de Android Studio.**
      `drawable/ic_launcher_background.xml` (el vector verde azulado) y
      `drawable-v24/ic_launcher_foreground.xml` (el robot) no los referenciaba
      nadie —el icono adaptable apunta a `@color/…` y `@mipmap/…`— pero seguían
      viajando en el APK y eran el archivo equivocado que alguien iba a editar.

### La primera descarga se veía no terminar
- [x] **El panel no se enteraba de que la sincronización había acabado.** Al
      entrar por primera vez, la descarga arranca sola desde el login y tarda
      medio minuto; el panel se dibuja antes y cuenta el almacén una sola vez,
      cuando todavía está vacío. Resultado: «Todavía no te has traído los
      datos» y «Actualizado nunca» encima de un teléfono con 14.139 filas
      dentro. Parecía que la descarga no terminaba nunca y había que
      sincronizar otra vez —la manual sí releía— para que aparecieran.
      Reproducido de cero en el navegador contra el proxy: 14.139 registros
      bajados, `sincronizado_at` escrito, panel en blanco.
- [x] **`sync.js` avisa al terminar** con `corridas`, un contador reactivo que
      sube una vez por corrida completada. Panel y Cuenta lo miran y vuelven a
      leer solos, venga la descarga del login, del botón o de la otra pantalla.
- [x] **Una corrida a la vez, pero esperable.** `sincronizar()` devolvía `null`
      si ya había otra en curso: la pantalla lo tomaba por «listo» sin haber
      bajado nada, y la tarjeta de «cambios sin enviar» —que no está
      deshabilitada durante la descarga— reventaba al leer `r.errores` de un
      `null`. Ahora el segundo espera a la primera y recibe su mismo resumen.
- [x] **Los traductores de código a nombre se recargan con los datos.**
      `cargarCatalogos()` pasó del login a `sync.js`: se cargaban en memoria
      antes de que existieran las filas y el panel decía «vendedor 2» donde va
      el nombre hasta reiniciar la app.
- [x] **Ninguna petición espera para siempre.** 30 s las normales y 60 s el PDF
      y la subida del logo, en `mobile/src/api.js`. Un `fetch` colgado —el
      teléfono pasa del WiFi de la oficina a datos móviles a mitad de descarga—
      dejaba la sincronización detenida esperando una página que no iba a
      llegar. El plazo agotado se distingue de la falta de red en el mensaje.
- [x] Comprobado tres veces de cero contra `https://venta.netdomain.cl` con un
      usuario de prueba del vendedor 2 (1.874 cotizaciones): login, descarga de
      14.139 registros en ~35 s y el panel llenándose solo, sin tocar nada.

### Buscar en Softland un documento más viejo
- [x] **La ventana de 12 meses y el alcance por vendedor se separaron.** Eran
      el mismo `filtro` del maestro y hacían dos trabajos distintos: uno es
      equipaje y el otro es permiso. `Maestros::uno()` y `varios()` aceptan
      `ventana: false`, y `GET /cotizaciones/{n}` y `/notas-venta/{n}` la
      levantan. El alcance **no** se toca: comprobado contra la API con tres
      tokens — el vendedor 2 obtiene la 8000 (de 2024, suya) y 404 en la 8360
      (del vendedor 19); el supervisor, 404; el administrador, 200.
- [x] **Se llega escribiendo el número en el buscador de la lista.** Si no hay
      nada con él en el teléfono y hay señal, aparece «Buscar la Nº 8000 en
      Softland». La ficha se abre igual que cualquier otra, con un aviso de que
      viene del servidor y **no queda guardada**: en IndexedDB ensuciaría el
      panel, donde entraría a contarse entre las vencidas de hace dos años.
- [x] **Las emisiones dejaron de heredarse entre documentos con el mismo
      número.** Salió probando lo de arriba: al crear una cotización de prueba
      le tocó el número 8553, que ya había tenido otra —de otro cliente y otro
      vendedor— entregada por WhatsApp el 13-09 y borrada después desde el
      Softland de escritorio. La nueva nacía con el historial de entregas de la
      muerta, así que decía «ya se le entregó al cliente» y no se podía borrar.
      `ventas.documento_emision` guardaba sólo el número; ahora lleva
      `creado_en`, la misma huella que ya usaba `documento_app`, y la comprueban
      `Emision::versiones()` y `Ventas::entregado()`. La migración se llevó la
      única fila que había —la de esa 8553 muerta— con su PDF.
- [x] **Duplicar también funciona con esas.** Volver a cotizarle a un cliente
      lo de 2024 es el caso bueno de la copia. Probado: la 8000 se abre como
      cotización nueva con su cliente, su línea y sus $300.196 exentos.

### Búsqueda instantánea, centro de costo buscable y el panel que se pone al día solo
- [x] **El buscador de cliente y de producto se congelaba a medio escribir.**
      `Editor.vue` abre la ficha con una carga inicial sin filtro (los primeros
      30) y, aparte, una búsqueda por cada letra. Si el vendedor escribía
      rápido, la búsqueda tecleada podía resolver antes que esa carga inicial
      —las dos escriben al mismo `ref`— y cuando la inicial llegaba después la
      pisaba, dejando la lista mostrando clientes sin relación con lo escrito,
      sin aviso ni el «Ningún cliente con eso» que debería aparecer.
      Reproducido escribiendo «netdomain» y viendo cuatro clientes al azar
      quedarse pegados un minuto. Cada búsqueda lleva ahora un número de
      turno y sólo se pinta la más reciente pedida, gane quien gane la
      carrera. Mismo resguardo en las listas de Clientes y Productos.
- [x] **Centro de costo se busca como un cliente.** Eran 594 en un `<select>`
      nativo — el mismo problema que resolvió `Selector.vue` para los giros,
      pero que no alcanza cuando hay menos de 40 opciones bajo el filtro y el
      campo se queda en un `<select>` pelado. Ahora es una ficha con buscador
      arriba y tarjetas tocables abajo, calcada de cliente y producto. Como es
      un maestro chico —vive entero en memoria vía `cargarCatalogos()`— el
      filtro es sincrónico, sin pasar por IndexedDB ni arrastrar la condición
      de carrera de arriba.
- [x] **El panel recuerda el período y el ámbito.** `periodo` y `ambito` nacían
      siempre en «mes» y en «yo» en cada visita a Inicio, sin mirar lo que el
      vendedor había dejado elegido. Se guardan ahora en Preferences
      (`panel_periodo`, `panel_ambito`) — del aparato, no de la sesión, como la
      densidad — y se validan contra las opciones vigentes al leerlos: un
      ámbito guardado que ya no aplica (el jefe dejó de serlo) cae al de
      siempre en vez de dejar el panel sin nada elegido.
- [x] **Al volver del segundo plano, sincroniza sola.** Un teléfono que queda
      abierto sin cerrarse mientras se trabaja desde el escritorio no se
      enteraba de los cambios hasta que alguien tocaba sincronizar a mano.
      `App.vue` escucha `appStateChange` de `@capacitor/app` —ya estaba entre
      las dependencias, no hizo falta agregar nada— y dispara la misma
      sincronización incremental de siempre cuando Android trae la app de
      vuelta a primer plano, con un plazo de 5 minutos para no repetirla a
      cada rato ni pisarse con la que ya arrancó el login. Comprobado en el
      navegador simulando `visibilitychange`: la sincronización se dispara al
      volver y el plazo la frena si se repite antes de los 5 minutos.

### El autocorrector de Android bloqueaba la búsqueda instantánea
- [x] **`v-model` ignora a propósito las teclas mientras se compone una
      palabra, y el autocorrector de Android compone hasta en español.** Un
      video de pantalla mostró que el texto se ve escrito letra por letra
      —eso lo pinta el navegador igual, escuche Vue o no— pero la lista sólo
      se actualiza cuando se toca la lupa del teclado. Primer intento:
      cambiar `type="search"` por `type="text"` — no era eso, seguía igual en
      la 0.7.1. La causa de verdad es más profunda: `v-model` en un `<input>`
      nativo trae un guardia contra IME de chino o japonés (`if
      (target.composing) return` en el runtime de Vue) que descarta los
      eventos `input` disparados mientras el navegador está componiendo. El
      teclado predictivo de Android usa esa misma composición para el
      autocorrector en cualquier idioma: escribir «netdomain» de corrido es
      una sola composición de principio a fin, y no se suelta hasta un
      espacio, una puntuación o la lupa del teclado —que es justo lo que
      parecía «haber que apretar»—. Se reprodujo también en centro de costo,
      que filtra en memoria sin tocar IndexedDB, lo que descartó la base de
      datos y la sincronización de fondo como culpables y apuntó al `<input>`
      mismo. Y explica por qué nunca se vio antes: ninguna prueba en el
      navegador de escritorio —ni escribiendo directo, ni fijando el valor
      por JavaScript— dispara `compositionstart`/`compositionend`, así que el
      bug era invisible desde ahí. Se confirmó recién simulando esa secuencia
      exacta de eventos. `Buscador.vue` es el único componente detrás de
      cliente, producto, centro de costo, cotizaciones, notas de venta y
      clientes/productos en lista, así que arreglarlo ahí alcanza para los
      seis: el `<input>` ya no lleva `v-model`, lee `value` y escribe en
      `@input` a mano, sin mirar si está componiendo. Mismo cambio en el
      campo propio de `Usuarios.vue`. Sin poder probarlo en un WebView de
      Android real desde aquí, la confirmación definitiva queda pendiente de
      que el vendedor lo pruebe en el teléfono.

### Aprobar la nota de venta desde su propia ficha
- [x] **Switch de aprobar en `Documento.vue`, sobre el endpoint que ya
      existía.** La aprobación del jefe es de la fase 3 —`NotaVentaController`,
      `ventas.aprobacion`, el endpoint `resolver()`— pero sólo se usaba desde
      `Aprobaciones.vue`, la cola aparte. Se agregó un switch en la tarjeta de
      Aprobación de la ficha, visible sólo cuando `aprobacion.estado ===
      'pendiente'` y quien mira es el `jefe_id` de esa aprobación o un admin
      (`puedeAprobar`, `Documento.vue`) — la misma regla que ya exige
      `resolver()` en el servidor con 403. `aprobacionDe()` en
      `NotaVentaController.php` ahora devuelve también `jefe_id`, que antes no
      viajaba al teléfono y sin él no había cómo decidir a quién mostrárselo.
      Confirma con `confirm()` (número y monto), llama a
      `resolverAprobacion()` y **guarda el documento que devuelve el servidor
      en IndexedDB**, igual que `anular()` — sin eso `doc.value` se quedaba con
      el `P` viejo de IndexedDB hasta la próxima sincronización, y «Corregir»
      seguía apareciendo aunque la tarjeta ya dijera «aprobada» (se encontró
      así en la primera prueba, con la escritura a `idb` faltando). Probado
      con `fetch` interceptado en el navegador: el jefe ve el switch y lo
      autorizado se aplica solo, cancelar no cambia nada y no queda marcado, y
      un vendedor mirando su propia nota con otro `jefe_id` no ve el switch en
      absoluto — sólo la tarjeta de sólo lectura. Sin poder probarlo con el
      backend real desde aquí, contra `ventas.aprobacion` de verdad.

### Aprobar notas de venta pendientes que llegaron sin pasar por la app
- [x] **`ventas.aprobacion` está vacía en INNOVAGES — 0 filas — y hay 16 notas
      de venta en `P`, todas de 2020.** Se descubrió porque el switch de la
      0.8.0 no aparecía ni para admin: exigía una fila de `ventas.aprobacion`
      que resolver, y esas 16 no tienen ninguna — quedaron en `P` desde
      Softland de escritorio, de antes de que existiera esta app o su
      mecanismo de tope. `resolver()` ahora crea la fila al vuelo
      (`aprobacionManual()`) cuando no hay una pendiente, sólo si quien pide
      es admin o el supervisor de ese vendedor puntual
      (`puedeAprobarSinSolicitud()`, por `subordinadosIds()` — nunca el
      vendedor mismo). `solicitante_id` no admite nulo y no hay quién pidió
      nada: se usa el usuario de la app del vendedor de la nota si existe, si
      no quien la resuelve. En el teléfono, `Usuario::payload()` manda ahora
      `subordinados_ven_cod` —sin el propio código, que es para
      «¿puede ver lo suyo?», no para «¿puede aprobarle a otro?»— y
      `puedeAprobar` en `Documento.vue` decide por dos caminos: con
      `jefe_id` asignado (el camino de siempre, por tope) o sin él, mirando el
      organigrama. Probado en el navegador con las tres combinaciones: admin
      ve el switch, supervisor con ese vendedor en su equipo también,
      supervisor sin él no ve ni la tarjeta. Sin poder probarlo contra
      `ventas.aprobacion` real ni tocar las 16 notas de 2020 —eso queda para
      que lo decida quien tiene el rol, no para probarlo desde aquí.
- **El buzón de avisos está vacío en la práctica.** `ventas.notificacion` no
  tiene filas porque el SMTP todavía no está configurado, y el único usuario
  creado no tiene correo. La consulta está probada de punta a punta contra
  INNOVAGES con filas de prueba (insertadas y borradas), incluido que el
  escape del `_` en el LIKE impide que a alguien le lleguen avisos ajenos.
- **`cwtccos` no sigue el prefijo de tres letras.** Sus columnas son
  `CodiCC`/`DescCC`, no `CcCod`/`CcDes` como en el resto de los maestros. Es la
  excepción; conviene verificar el nombre real en `sys.columns` antes de
  escribir cualquier consulta nueva a un maestro de Softland.
- **Hay descripciones vacías en los maestros** (la lista de precios `01` de
  INNOVAGES). `Catalogos::etiqueta()` cae al código cuando el nombre viene en
  blanco, para que no salgan opciones invisibles en los desplegables.
- **La lista de precios de INNOVAGES no sirve.** `iw_tlprprod` tiene 838 filas
  y **ninguno** de sus 835 códigos de producto existe en `iw_tprod`: quedó de
  una carga vieja que nadie mantiene. Se sigue descargando porque otra empresa
  Softland sí puede tenerla al día; en la hoja del producto simplemente no
  aparece ninguna fila de lista. Si alguna vez hay que arreglarla, es un
  problema de datos del cliente, no de la app.
- **La bandeja de salida no resuelve conflictos: gana el último que llega.** Si
  dos vendedores editan al mismo cliente sin señal, el segundo pisa al primero
  sin avisar. Con tres personas en terreno y 3.373 clientes es teórico; el día
  que deje de serlo, hay que mirar `mobile/src/pendientes.js`.
- **Descartar una edición sin señal deja el dato editado en el teléfono** hasta
  la próxima descarga completa. Con señal se vuelve a pedir la ficha al
  servidor y queda correcta al instante.
- **`->delete()` de Laravel informa 0 filas en las tablas de Softland** aunque
  borre. Pasa al menos en `cwtaxco`. El borrado ocurre — se comprobó contando
  antes y después — pero el número que devuelve no se puede usar para decidir
  nada.
- **La auditoría de `cwtauxi` del cliente 79528870** (COMPAÑIA NACIONAL DE
  CUEROS) quedó con `Usuario=softland / Proceso=App de ventas` y la fecha del
  13-09-2026 por una prueba de la fase 2. El dato de negocio se restauró (su
  teléfono volvió a quedar vacío, como estaba); lo que no se pudo devolver es
  quién lo había tocado antes, porque la columna se sobrescribe.
- **Softland no avisa de lo que cambia, y no hay forma de preguntárselo.**
  `nwcotiza` sólo guarda `FechaHoraCreacion`, que no se mueve al cambiar de
  estado; `nw_nventa.FechaUlMod` existe pero lo escribe esta app, no el ERP (15
  de 800 filas, todas desde 2026-07-22). Por eso el refresco de una lista baja
  el maestro entero. La alternativa buena el día que duela son las bitácoras
  `nw_lognwcotiza` (15.795 filas) y `nw_lognwnventa` (3.139), que sí registran
  eventos del escritorio con fecha —«Estado En Nota de Venta», «Elimina»— y son
  además la fuente del paso 5 del panel. Lo que no está comprobado es que
  registren *toda* modificación, por ejemplo un cambio de precio en una línea.
- **El panel suma montos sin convertir la moneda.** Hoy no se nota: las 185
  cotizaciones y las 51 notas de venta de los 12 meses están todas en `CodMon`
  `01`. El día que alguien cotice en UF o en dólares, el KPI sumará peras con
  manzanas. La conversión existe (`Equivalencia.php`), pero en el servidor.
- **INNOVAGES factura por suscripción, no por nota de venta.** La NV 2003 tiene
  10 facturas entre 2025-09 y 2026-03. Cualquier «conversión NV → factura»
  contada en documentos daría más de 100 %. El panel mide monto facturado del
  período, no conversión. Ver `docs/panel-comercial.md` §15.
- **`nvCantFact`, `nvCantDesp` y los cuatro flags `nvEst*` están en cero** en
  las 2.242 líneas y las 800 cabeceras. Las columnas existen, tienen el nombre
  correcto y nadie las llena: un KPI construido sobre ellas daría cero para
  siempre sin dar error.
- **No existe ninguna tabla de metas de venta en Softland.** `ND_Presupuesto`
  tiene 36 filas todas en cero y `WG_Presup` es presupuesto contable. La meta
  tiene que ser un dato de la app (`ventas.meta`, todavía sin crear).
- **La cobranza no tiene fuente utilizable.** `xwcobranza` está vacía y
  `cwmovim` tiene movimientos de 9 clientes. Es fase 5 de verdad, no un widget
  que falte encender.
- **Hay un solo vendedor activo** (`VenCod` 2, con 173 de las 185 cotizaciones
  de 12 meses). El ámbito EQUIPO se construye igual, pero aquí muestra una fila
  con dato y tres vacías.
- **La bitácora de Softland nombra al creador, no a quien borró.** El trigger
  `Elimina` copia `UsuarioGeneraDocto` de la fila que desaparece. Si alguna vez
  hace falta saber quién apretó el botón, hay que anotarlo aparte en `ventas`.
- **El usuario administrador no tiene vendedor** (`ven_cod` en blanco), así que
  desde él no se puede grabar sin elegir vendedor a mano en cada documento. Es
  correcto que sea así — un administrador no vende — pero conviene tenerlo
  presente al probar.
- **Flete y embalaje no se calculan.** Las columnas existen y se escriben en
  cero: de las 2.350 cotizaciones de INNOVAGES, **ninguna** los usa, así que no
  hay un solo caso real contra el que comprobar cómo entran en el total. El día
  que haga falta, hay que sacar la fórmula del Softland de escritorio antes de
  escribir una línea.
- **Solo se calcula el IVA.** `NWCtImpto` tiene 4 filas de ILA (impuesto a los
  líquidos, 8 %) entre 2.051, y en el maestro de productos no hay ninguna
  columna que diga qué producto lo paga: `iw_tprod` solo tiene el flag
  `Impuesto`, que es «afecto sí o no». Un documento con un producto afecto a ILA
  quedaría con el impuesto de menos.
- **Los descuentos 2 a 5 se escriben en cero.** Existen en las tablas y no los
  usa ninguna de las 2.350 cotizaciones, ni en la línea ni en el encabezado.
  Inventar una cascada sin un caso con que comprobarla sería adivinar sobre el
  precio que se le cobra a un cliente.
- **La tasa de IVA se copia del último documento de Softland.** No está en
  ningún maestro: Softland la estampa documento a documento en
  `NWCtImpto.valpctIni`. Si la base no tuviera ninguno, queda el 19 % de
  respaldo de `Totales::IVA_POR_DEFECTO`.
- **El envío de la cotización por correo no se ha podido probar de verdad.** El
  PDF se generó y se revisó; lo que falta es el SMTP configurado para ver salir
  el correo con el adjunto.
- **`datetime` de SQL Server redondea a 3,33 ms.** `now()->endOfDay()` son las
  23:59:59.999 y el motor las guarda como las 00:00 del día siguiente: la
  consulta del valor de la UF devolvía el de mañana. El corte va al segundo.
- **La firma del PDF sale sin cargo ni teléfono.** Las columnas existen
  (`ventas.usuario.cargo` y `.fono`) y la plantilla las dibuja si están, pero
  ningún usuario las tiene cargadas todavía.
- **`soempre` de INNOVAGES trae el domicilio tributario, no el comercial.** El
  PDF que usaban los vendedores decía «Avda. Los Carreras 1865, Concepción» y el
  ERP dice «Ensenada 2332, Los Ángeles». Hasta que alguien escriba el override
  en la pantalla de Identidad, el pie del documento muestra el del ERP.
- **Una línea con el producto comodín `*` se dibuja como comentario**: sin
  código, sin unidad y sin precios. Mostrarle «$ 0» la disfrazaba de artículo
  gratis, que es lo único que un cliente no debería poder leer en una
  cotización. Si alguna vez `*` se usa para vender algo de verdad, esto hay que
  revisarlo.
- **Boleta electrónica sin folios.** No hay CAF para el DTE 39 ni el 41 en
  `dte_siicaf`. El tipo `BE` existe en `cwttdoc`, así que Softland está
  preparado, pero sin folios no se puede emitir.
- **Mapeo tipo Softland → tipo SII para ventas, sin resolver.** En `cwttdoc`
  los códigos de venta (`EL`, `NL`, `BE`) traen `DTEDocSII` vacío y
  `iw_gsaen.DTE_SiiTDoc` está en 0 en las 209 filas existentes.
- **Contraseña de `sa` en texto plano** en `srv:E:\Servicio\Config\Config.js`
  (proyecto heredado, ya en su historial de git). Si se rota la clave, hay que
  acordarse de ese archivo.

### Persianas: panel, cliente y datos; una sola fila de acciones
- [x] **Cuatro pedidos de reordenamiento visual, todos por espacio.** El panel
      tenía dos párrafos sueltos bajo el embudo comercial y "Tiempo de cierre"
      / "Ticket promedio" sin explicación propia; la ficha de un documento
      saltaba a otra pantalla para ver al cliente y siempre mostraba la
      tarjeta "Datos" entera; el selector de producto cortaba el nombre a una
      línea justo donde estaba la parte que distingue una variante de otra; y
      el switch de aprobar, con su tarjeta propia, pesaba más que el resto de
      la fila de acciones junta. Se resolvió con un componente nuevo,
      `Persiana.vue` (cabecera + `<AppIcon name="desplegar">` que gira +
      cuerpo que se pliega), usado en cuatro lugares: la frase de "Conversión"
      y la de "cómo leer este panel" en `Inicio.vue`; las mismas dos medidas
      de rendimiento que no tenían explicación plegable; el nombre del
      cliente y la tarjeta "Datos" en `Documento.vue`; nada nuevo en
      `Editor.vue`, ahí sólo bajó la letra del nombre del producto a 13px y
      pasó a dos líneas (`-webkit-line-clamp`) en vez de cortarlo con «…».
      El switch de aprobar se volvió un botón más (`chip-accion`) dentro de la
      única fila de acciones que quedó — antes eran tres filas separadas — en
      el orden Corregir → Duplicar → Aprobar → Anular → Eliminar, y la
      tarjeta de Aprobación se redujo a un `<Aviso>` de una línea. Probado en
      el navegador de escritorio sembrando IndexedDB a mano (sin API real
      disponible desde aquí): las cuatro persianas abren y cierran, el orden
      de la fila de acciones sale exacto, y el selector de producto limita el
      nombre a dos líneas. `npm run build` y `npm run pruebas` (137
      comprobaciones) pasan.

### Fase 4, paso 1: el timbre electrónico
- [x] **Averiguado antes de diseñar nada, y el resultado cambió el plan.** No
      hay API REST oficial de Softland en esta instalación; `IWSerDTE.exe` no
      emite sino que **recibe** DTE por correo (lo dice su propio `.ini`); el
      emisor son formularios VB6 dentro de `IWS.EXE` que alguien abre a mano; y
      la cola por carpeta de `Softland.DteEncolar.config` no está montada. No
      hay proceso que disparar: si la app va a facturar, emite ella.
- [x] **El mapeo Softland → SII estaba en otra tabla.** No en
      `cwttdoc.DTEDocSII`, que viene vacío justo para los documentos de venta,
      sino en `dte_siitdoc` por `(Tipo, SubTipoDocto)`. Escrito en `TipoDte`.
- [x] **El folio lo reparte Softland**, con el procedimiento almacenado
      `DTE_pdblEntregaFolioDTE`. Llamándolo no se le disputa el número al ERP.
- [x] `Caf` + `Timbre` + `dte:verifica-timbre`: recalcular el timbre de
      documentos ya emitidos y compararlo con el guardado, sin emitir ni gastar
      un folio. **615 documentos, cinco tipos, dos empresas con RUT y
      certificado distintos, de 2009 a 2026: todos idénticos.**
- [x] **El camino de la boleta queda preparado y probado** contra la única
      boleta electrónica real que existe, en NETDOMAIN (folio 2 del 2021-07-09).
      Falta el transporte REST y los folios; el timbre ya calza.
- [x] Alcance acordado con el cliente: la app llega **hasta inventario y
      facturación con el DTE emitido**. La centralización a contabilidad,
      registro de ventas y cuenta corriente es un procedimiento aparte que se
      corre desde Softland. No se reproduce.
- [x] Doce pruebas unitarias fijan las reglas que costó descubrir: ISO-8859-1,
      el `<CAF>` sin espacios, el `MNT` sin signo, los recortes a 40. Todas
      pasan (`phpunit`, 22 pruebas).

### Fase 4, paso 2: escribir el documento en inventario y facturación
- [x] **Hallazgo que cambió el diseño: la factura no es la nota de venta.** De
      192 facturas enlazadas a una NV, **190 van a un cliente distinto** —188 a
      Softland Ingeniería—, 196 de 197 tienen una sola línea de comisión, y
      `nvCantFact` está en cero en todas las líneas de todas las notas de venta.
      Es un negocio de distribuidor: la NV registra la venta al cliente final y
      la factura le cobra la comisión a Softland. El monto no se calcula —del
      0,5 % al 77 %— y lo escribe quien factura.
- [x] Alcance acordado con el cliente: una sola cañería para los tres casos —la
      comisión, la factura al cliente de la NV y la factura suelta—, con el
      receptor cambiable bajo llave de configuración.
- [x] `Facturacion`: escribe `iw_gsaen` + `iw_gmovi` + `IW_GSaEn_RefDTE`, pide
      el folio a `DTE_pdblEntregaFolioDTE` y **no centraliza**.
- [x] `dte:base-de-pruebas`: copia INNOVAGES entera a `INNOVAGES_DTE`, con sus
      1.905 tablas y 24 triggers. **`INNOVAGES_TEST` no se usa: es de otro
      proyecto** (una app de rendiciones, esquema `rinde`).
- [x] `dte:verifica-documento`: **199 documentos reescritos y comparados columna
      por columna** —189 facturas y 10 notas de crédito—. Los que no salen
      idénticos difieren solo en `CodiCC` de la línea y en el `NVCorrelaOC` que
      Softland dejó de escribir en agosto de 2024.
- [x] Escribe dentro de una transacción y la deshace: de factura queda **un solo
      folio libre**, el 235, y sin deshacer la primera corrida se lo comía.
- [x] `Totales` no se tocó. Su reparto se salta cuando el bruto no es positivo
      —caso de toda nota de crédito—, así que se calcula en positivo y se aplica
      el signo al final.

### Fase 4, paso 3: generar y firmar el XML
- [x] **Maullin descartado, y con razón**: el SII cierra el ambiente de
      certificación cuando el contribuyente firma su declaración de
      cumplimiento. INNOVAGES lo cerró. En su lugar se reproducen los **209
      documentos que el SII ya aceptó en producción**, que es evidencia más
      fuerte que un envío a un ambiente de juguete.
- [x] `Documento` + `FirmaXml` + `Certificado`, y `dte:verifica-xml` para
      contrastar. **Facturas 188 de 188, notas de crédito 12 de 12; la firma sale
      idéntica en los 209.**
- [x] La clave de la canonicalización, que es de lo que cuelga todo: la firma
      cubre la **forma canónica**, no el texto. Los comentarios no se firman pero
      los saltos que los rodean sí; el espacio entre elementos sí; y todo va en
      ISO-8859-1.
- [x] El certificado quedó instalado en `srv`, reconvertido a AES-256 porque el
      original usaba un cifrado que OpenSSL 3 ya no abre, y su clave salió del
      nombre del archivo y se fue al `.env`.
- [x] Boleta y factura exenta probadas contra NETDOMAIN, que sí las emitió.
- [x] El generador **se niega** a emitir un documento con descuento de pie: ese
      bloque no está escrito y no hay caso real contra el que comprobarlo.

### Fase 4.5, paso 1: el saldo
- [x] `ventas.linea_origen`: de qué línea de cotización salió cada línea de nota
      de venta. Es el **único** enlace del ciclo que hay que guardar por nuestra
      cuenta — el de nota de venta a factura ya lo tiene Softland en
      `iw_gmovi.nvCorrela`, y usar el suyo hace que el saldo salga bien también
      cuando factura el ERP.
- [x] `Saldo`: el saldo **se calcula, no se guarda**. Una resta sobre los
      documentos vivos no puede desincronizarse.
- [x] **La nota de crédito dice qué acredita en `IW_GSaEn_RefDTE`**, no en
      `AuxDocNum` —5.317 facturas de NETDOMAIN también lo llevan relleno—, y hay
      que comparar el tipo además del folio, que se repiten entre tipos.
- [x] **La línea de la nota de crédito viene en negativo** y se devuelve en
      positivo. Sumarla tal cual daba saldo -1 en una línea pedida 1, facturada 1.
- [x] `ventas:verifica-saldo`: 905 notas de venta con factura y 1.470
      cotizaciones convertidas de las dos empresas. Cero saldos inventados y
      cero acreditado por encima de lo facturado.

### Fase 4.5, paso 2: convertir parte de la cotización
- [x] La conversión acepta **líneas y cantidades sueltas** y escribe
      `ventas.linea_origen`. Una línea agregada a mano no deja fila: no consume
      saldo de nada.
- [x] **`V` ya no cierra la puerta.** Se puede volver a convertir mientras quede
      saldo; el 409 llega cuando no queda, y nombra todas sus notas de venta.
- [x] **`devolverCotizacion` contaba las anuladas.** Miraba si existía *alguna*
      nota de venta con ese `CotNum`. Con reparto parcial eso dejaba
      cotizaciones vendidas sin estarlo.
- [x] **Anular devuelve el saldo igual que borrar**, y la respuesta lleva
      `cotizacion_liberada` como ya hacía el borrado.
- [x] Corregir una nota de venta **rehace sus enlaces** junto con el detalle.
- [x] `GET /cotizaciones/{n}/saldo`.
- [x] `ventas:verifica-conversion`: el ciclo entero contra Softland dentro de
      una transacción que se deshace. Seis comprobaciones, todas en verde, y ni
      un número gastado.

### Fase 4.5, paso 3: facturar parte de la nota de venta
- [x] La factura **hereda** de la nota de venta producto, precio, factor y
      descuento de línea, y los **sobrescribe**: si el teléfono manda otro
      precio, gana la nota de venta. La cantidad y las líneas nuevas sí las
      elige quien factura.
- [x] **`PreUniMB` iba en la moneda del producto, y tiene que ir en la del
      documento.** De ahí sale el `PrcItem` del DTE y el SII comprueba que
      `PrcItem × QtyItem` cuadre con `MontoItem`, que va en pesos: con un
      producto en UF el documento no cuadraba consigo mismo. No cambia ninguno
      de los 199 contrastados, que tienen equivalencia 1.
- [x] Dos rechazos **antes** de pedir folio: línea que dice venir de una nota de
      venta sin decir de cuál, y línea que cita una que no existe.
- [x] `Facturacion::propuesta()`: lo pendiente, listo para precargar.
- [x] `ventas:verifica-facturacion`: doce unidades, factura de cinco, saldo
      siete, anular y vuelven las doce. Doce comprobaciones, dentro de una
      transacción que se deshace. **Queda un solo folio de factura, el 235**, y
      por eso la prueba factura una vez: el caso de muchas facturas por nota de
      venta lo demuestra la historia de NETDOMAIN.

### Fase 4.5, paso 4: devolver el saldo al anular
- [x] La nota de crédito **hereda del documento que corrige** producto, precio,
      descuento y `nvCorrela`: si la línea de la factura consumía saldo, la que
      lo devuelve dice de cuál.
- [x] **Dos columnas y ninguna alcanza sola.** `nvCorrela` apunta a la línea de
      nota de venta y `FactNumLin` a la de factura. En INNOVAGES las 12 líneas
      de nota de crédito traen `FactNumLin` y sólo 2 `nvCorrela`; en NETDOMAIN
      es al revés. El lector prueba las dos.
- [x] **No se inventa una tercera.** Las dos líneas que no se pueden atribuir
      llevan un producto distinto del de la factura que acreditan: no devuelven
      esa línea. Un respaldo por producto se habría equivocado justo ahí.
- [x] `Facturacion::propuestaNotaCredito()`.
- [x] Ciclo completo comprobado en `ventas:verifica-facturacion`: convertir,
      facturar en parte, acreditar, anular la nota de crédito y anular la
      factura. 16 comprobaciones, sin gastar folio.

### Fase 4.5, paso 5: la llave del receptor
- [x] `ReglasFactura` en `ventas.config`: apagada, el receptor de la factura se
      hereda de la nota de venta; encendida, se puede cambiar. Es lo que
      habilita el ciclo de distribuidor sin que sea lo que pasa por omisión.
- [x] **La regla vive en el escritor**, no en el controlador: una pantalla nueva
      que no supiera de ella escribiría facturas al cliente equivocado.
- [x] **La nota de crédito queda fuera**: su receptor lo manda la factura que
      acredita. Por eso la comprobación corre antes de heredar.
- [x] `GET /configuracion` la informa; `PUT /configuracion/facturacion` la cambia.
- [x] `dte:verifica-documento` la enciende a la fuerza: los 199 históricos de
      INNOVAGES son comisiones y con la llave apagada no se reescribirían.
      Siguen saliendo igual que antes.

### Fase 4.5, paso 6: las pantallas del saldo
- [x] Ficha de la cotización: «convertida a medias: quedan 2 de 3 líneas» con el
      detalle, y el botón **«Nota de venta por el saldo»**, que convierte sólo
      lo pendiente y con la cantidad pendiente.
- [x] Lista de cotizaciones: etiqueta **«A medias»**.
- [x] **Se calcula sin señal**: `linea_origen` baja como maestro y
      `mobile/src/saldo.js` repite la regla de `Saldo.php`. Las dos copias se
      comprueban en `npm run pruebas` (152 comprobaciones).
- [x] **Convertir no mandaba de qué línea venía cada línea.** Sin eso ni una
      conversión completa dejaba enlace y toda cotización quedaba en «no se
      sabe». Ahora `cot_linea` viaja siempre.
- [x] Comprobado a 360 px en las tres densidades.
- [x] **La pantalla de facturación** (0.20.0): `FacturaController`, los maestros
      `facturas` y `factura_lineas`, y `Facturar.vue`. Los folios se dicen antes
      de teclear, el precio no tiene campo y la confirmación nombra el folio.
- [x] **Contar folios no es contar los que no están usados.** El repartidor no
      rellena huecos: la primera cuenta daba **38** donde la realidad es **uno**.
- [x] **«Facturado 5 de 12» decía «0 de 12» desde siempre**, porque se leía de
      `nvCantFact`. Ahora se calcula desde las líneas de factura vigentes.

### La nota de crédito desde el teléfono
- [x] La ficha de la nota de venta enseña **lo facturado**, con el estado de cada
      factura: vigente, anulada, o anulada con su nota de crédito.
- [x] **Anular con nota de crédito** desde ahí. Las líneas las arma el servidor
      desde la factura: anular es devolver lo facturado, todo y tal cual.
- [x] **Anulación entera, no devolución parcial.** `CodRef 1` anula; devolver
      parte es otro documento con otra referencia, y no lo emite esta pantalla.
- [x] No se anula dos veces: 409 nombrando la nota de crédito que ya existe.
- [x] Maestro `factura_referencias`, para saberlo sin señal. La referencia vive
      en `IW_GSaEn_RefDTE`, no en `AuxDocNum`.
- [x] Comprobado contra la API: factura 235 → saldo 6, nota de crédito 16 →
      saldo 10, segunda nota de crédito rechazada. Sin gastar folio.

### Fase 4, paso 3b: el sobre, la autenticación y el seguimiento
- [x] `Sobre`: el `<EnvioDTE>` con su carátula y su **segunda firma**. Los tres
      RUT que el SII distingue —la empresa, la persona que firma y el propio
      SII— y ninguno es el cliente.
- [x] **Hallazgo que habría hecho rechazar todos los envíos**: la forma canónica
      arrastra los espacios de nombres heredados. El **documento** se firma
      suelto; el **sobre**, con los de `<EnvioDTE>`. Salió de probar las cuatro
      combinaciones contra los sobres guardados hasta que una dio el resumen que
      el SII aceptó.
- [x] El `<RutEnvia>` sale del `subjectAltName` del certificado, bajo un OID que
      PHP no sabe leer. Antes salía el de la entidad certificadora, que ahí es
      rechazo inmediato.
- [x] `Sii`: semilla, token, subida multiparte y las dos consultas de estado.
      SOAP escrito a mano —la extensión no está en el servidor— y el envío por
      formulario, que es lo que es: un CGI, no una API.
- [x] `Emision`: arma, manda y **recién entonces** deja constancia. Si el envío
      ocurre y la constancia falla, el error lleva el `TrackID` delante y dice
      que no se reenvíe.
- [x] `dte:verifica-sobre`: **210 sobres reproducidos**, mismo resumen y misma
      firma en todos (198 facturas y 12 notas de crédito).
- [x] **Probado contra palena, en producción, sin emitir nada**: `dte:token`
      devuelve token; `dte:estado --track=` devuelve «EPR, 1 aceptado»;
      `dte:estado --folio=` devuelve «DOK».
