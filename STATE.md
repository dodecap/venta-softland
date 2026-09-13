# Estado del proyecto — Venta Softland

> Se actualiza al final de cada sesión. Es lo primero que hay que leer al
> retomar el proyecto, desde este u otro computador.

## Última actualización
2026-09-13

## Resumen del estado actual
**Fase 1 terminada, desplegada e instalada.** El servidor (API Laravel) está
en `srv:C:\xampp\htdocs\venta-softland`, publicado por Apache en
`http://172.30.205.106:8086/venta-softland` y ya instalado: el esquema `ventas`
existe en INNOVAGES, hay un administrador y las 12 reglas de notificación
sembradas. El APK compila, entra y lista usuarios contra la base real.

Comprobado contra INNOVAGES con `ventas:probe`: 2.350 cotizaciones, 800 notas
de venta, 3.824 clientes, 1.229 productos, 21 vendedores, 594 centros de costo
y folios CAF para factura (33) y nota de crédito (61).

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

## Pendiente / próximos pasos
- [ ] **Publicar la API en Apache** (ver «Problemas conocidos»).
- [ ] Correr `/setup` en el navegador y crear los primeros vendedores.
- [ ] Configurar el SMTP desde la app y mandar un correo de prueba.
- [ ] Empezar la fase 2 (catálogos offline). Ver `docs/roadmap.md`.
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
- **594 centros de costo activos** en un desplegable simple. Funciona, pero en
  la fase 2 conviene un buscador en vez de un `<select>`.
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
