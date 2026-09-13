# Estado del proyecto — Venta Softland

> Se actualiza al final de cada sesión. Es lo primero que hay que leer al
> retomar el proyecto, desde este u otro computador.

## Última actualización
2026-09-13

## Resumen del estado actual
**Fases 1 y 2 terminadas.** El servidor (API Laravel) está en
`srv:C:\xampp\htdocs\venta-softland`, publicado por Apache en
`http://172.30.205.106:8086/venta-softland` y ya instalado: el esquema `ventas`
existe en INNOVAGES, hay un administrador y las 12 reglas de notificación
sembradas.

Con la fase 2 el teléfono **trabaja sin señal**: se baja 21 maestros a
IndexedDB (14.175 registros, menos de 10 s por WiFi) y desde ahí se buscan
clientes y productos, se leen cotizaciones y notas de venta, y se dan de alta y
se editan clientes con sus contactos. Lo que se escribe sin red queda en una
bandeja de salida y sale solo cuando vuelve.

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

## Pendiente / próximos pasos
- [ ] Crear los primeros vendedores y probar la app con un usuario que no sea
      admin: el alcance por vendedor está probado contra la base, pero no con
      alguien usando el teléfono.
- [ ] Configurar el SMTP desde la app y mandar un correo de prueba.
- [ ] Empezar la fase 3 (cotización y nota de venta). Ver `docs/roadmap.md`.
- [ ] **Solicitar al SII los folios CAF de boleta electrónica (DTE 39)** — es
      el bloqueo de plazo más largo del proyecto, conviene iniciarlo ya.
- [ ] Averiguar si la API REST oficial de Softland (`Softland.DteClient`) está
      disponible para esta instalación, antes de diseñar la fase 4.

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

## Problemas conocidos / bloqueos
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
- **Boleta electrónica sin folios.** No hay CAF para el DTE 39 ni el 41 en
  `dte_siicaf`. El tipo `BE` existe en `cwttdoc`, así que Softland está
  preparado, pero sin folios no se puede emitir.
- **Mapeo tipo Softland → tipo SII para ventas, sin resolver.** En `cwttdoc`
  los códigos de venta (`EL`, `NL`, `BE`) traen `DTEDocSII` vacío y
  `iw_gsaen.DTE_SiiTDoc` está en 0 en las 209 filas existentes.
- **Correlativo de `NVNumero`: origen desconocido.** No está en `nwparam` ni
  aparece una tabla de correlativos de ventas. Hasta aclararlo, cualquier
  inserción debe tomarlo bajo `UPDLOCK, HOLDLOCK` dentro de una transacción.
- **Estado `P` de cotización sin explicar**: 189 filas, ninguna con motivo de
  pérdida ni con nota de venta asociada.
- **Contraseña de `sa` en texto plano** en `srv:E:\Servicio\Config\Config.js`
  (proyecto heredado, ya en su historial de git). Si se rota la clave, hay que
  acordarse de ese archivo.
