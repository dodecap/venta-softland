# Estado del proyecto — Venta Softland

> Se actualiza al final de cada sesión. Es lo primero que hay que leer al
> retomar el proyecto, desde este u otro computador.

## Última actualización
2026-09-24 — versión **0.47.2**

## Resumen del estado actual
**Versión 0.47.2. Fases 1, 2 y 3 terminadas, el motor de documentos comerciales
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

### 0.47.2 — La huella en la base se puede enseñar (2026-09-23)

La pregunta salió al revisar qué había quedado escrito en `INNOVAGES`: **¿se
modificó alguna tabla, se creó alguna tabla nueva?** La respuesta estaba, pero
repartida entre diez migraciones y la memoria de quien las escribió — y es la
primera que hace quien administra el SQL Server de un cliente donde se factura.
Instalar dentro de esa base y no poder contestarla en treinta segundos es pedir
un acto de fe.

`ventas:huella` la contesta con la base delante: las 13 tablas y la vista del
esquema `ventas` con sus columnas y sus filas, si las migraciones están todas
puestas, si hay alguna clave foránea que cruce a `softland` —tiene que haber
cero— y cuántas filas ha escrito la app en tablas del ERP. No escribe nada.

Dos decisiones que importan:

- **Lee `sys.objects`, no una lista escrita en el comando.** Una lista a mano
  se queda corta en la primera migración que alguien añada, y entonces el
  comando miente justo en lo que vino a contestar. Es el error que ya tiene
  `ventas:probe`, que enseña 5 de las 13 tablas.
- **No intenta demostrar que nadie tocó el ERP.** Eso no se deduce de la base:
  cualquiera pudo hacerlo desde el escritorio, y Softland crea tablas suyas de
  nombre raro sin parar —22 con el mismo patrón, entre 2009 y 2026—. Se deduce
  del código, y ahí está escrito una vez: ninguna migración hace `ALTER` sobre
  `softland`. Lo que la base **sí** contesta es la consecuencia práctica: sin
  claves foráneas cruzadas, quitar la app es borrar un esquema.

Y un cero que parecía una respuesta: contar lo escrito en el ERP por
`Proceso = 'Venta Softland'` daba 0 cotizaciones y 0 notas de venta, porque esa
columna sólo la estampa `Facturacion`. La cuenta buena es `ventas.documento_app`,
que es el mapa de idempotencia. Medido hoy en INNOVAGES: 3 cotizaciones, 1 nota
de venta, 0 facturas, y 674 de 674 actecos cargados en `cwtgiro` sobre 2.667
filas.

En el manual queda el apéndice **«La huella en la base»**, anunciado arriba del
todo para que se lea antes de que el cliente pregunte.

### 0.47.1 — El servidor se trae su propio instalable (2026-09-23)

Escribiendo el manual de puesta en marcha apareció un hueco que no se ve desde
aquí: **una instalación recién hecha no tiene APK**. El servidor acaba de
instalarse, así que está en la última versión y no tiene nada que actualizar;
pero `/app` está vacío, porque el instalable no viaja en el repositorio sino en
la publicación. Y lo que decía la página era «se sube desde el equipo de
desarrollo con `bin/publicar-apk.sh`» — una máquina que quien instala en casa
de un cliente no tiene.

`ventas:actualizar --apk` trae el APK **de la versión que está puesta**, no el
último: repartir un APK más nuevo que la API es el desfase del que Cuenta se
pasa el día avisando. La página de «Instalación completada» lo pide antes del
código QR.

De paso, dos rutas mal nombradas en esa misma página: **Identidad y Usuarios
cuelgan de Cuenta**, no de Configuración.

Y **`docs/instalacion.md`**, el manual de principio a fin: qué pedirle al
cliente antes de ir, los ocho pasos, las comprobaciones que no gastan folio,
una tabla de síntoma → causa → arreglo, y lo que la app no hace, que conviene
decir el día uno.

### 0.47.0 — Actualizarse desde GitHub Releases (2026-09-22)

Fase E, y con ella el círculo se cierra: instalar ya no pedía editar archivos
(fase A), ni adivinar si la base servía (B), ni copiar el certificado a mano
(C). Faltaba **la segunda vez y todas las siguientes**, que hasta ahora eran
clonar el repositorio o copiar carpetas por escritorio remoto.

`ventas:actualizar` y Configuración → Versión del servidor bajan la última
publicación de GitHub y la ponen. Tres piezas por publicación:

| Pieza | Pesa | Cuándo se baja |
|---|---|---|
| `venta-softland-<v>-servidor.tar.gz` | 1,4 MB | siempre |
| `vendor-<sha256 del composer.lock>.tgz` | 17 MB | sólo si el `composer.lock` cambió |
| `venta-softland-<v>.apk` | 5,0 MB | siempre, y queda listo en `/app` |

El sha256 del `composer.lock` **va dentro del nombre** del paquete de
dependencias: así el servidor decide sin una segunda llamada ni un manifiesto
que pueda mentir. La actualización corriente son 6,4 MB de 24.

Lo que da forma al resto:

- **Nada se escribe hasta que está todo bajado y comprobado**, con el sha256
  que calcula GitHub al subir cada archivo. Sin eso, la página de error de un
  proxy con código 200 acabaría descomprimida encima del servidor.
- **`PharData` y no `tar`**, para no exigirle `exec` a un cliente. Cuesta un
  requisito en el empaquetado: `PharData` se niega a extraer la entrada «.»,
  así que `bin/publicar-version.sh` nombra las carpetas una a una.
- **El remate va en un PHP recién arrancado.** El proceso que descomprimió
  lleva en memoria el cargador de clases de la versión vieja y no sabría
  encontrar un paquete recién llegado.
- **Volver atrás es parte de actualizar.** `--a=0.46.0` instala la anterior,
  que sigue publicada. (Es `--a` porque `--version` lo tiene cogido artisan.)
- **Lo que tarda se sigue preguntando, no esperando.** El estado se anota en
  `storage/app/private/actualizacion.json` y la pantalla lo relee: si el plazo
  del teléfono se acaba, el servidor sigue trabajando y se ve en qué quedó.

Ensayado de punta a punta contra `srv`, con una publicación de mentira servida
por el propio Apache: bajada de las tres piezas, sha256 comprobado, salto de
las dependencias cuando el hash coincide, descompresión encima del árbol vivo,
`migrate`, APK guardado, y vuelta atrás de la 0.99.0 a la 0.46.0. Después se
borró todo rastro del ensayo y el servidor quedó como estaba.

**Publicado de verdad el 2026-09-23**, con la 0.47.2: `bin/publicar-version.sh`
subió las tres piezas —código 358 KB, `vendor-9ea5350edab5.tgz` 17 MB y el APK
de 5 MB— y `ventas:actualizar --comprobar` en `srv` ya contesta «ya está en la
última» leyendo GitHub. Hasta ese día el camino estaba escrito y ensayado pero
la línea del `gh release create` no se había ejecutado nunca.

### 0.46.0 — El certificado digital se sube desde la app (2026-09-22)

Fase C, y con ella se acaban los secretos que obligaban a abrir una sesión en
el servidor. El certificado digital se copiaba a mano y su clave se escribía en
el `.env`; y no es cosa de una vez, porque **se renueva todos los años**.

`AlmacenCertificado` guarda el `.pfx` en `certificado-app.pfx` y **la clave
cifrada con `APP_KEY`** en `certificado-app.json` — el mismo trato que la
conexión a SQL Server en `softland.json`, y por la misma razón.

Tres reglas que dan forma a todo:

- **Nada vuelve a bajar.** No hay ruta que devuelva el archivo ni la clave. La
  ficha que la app enseña —quién firma, RUT, vencimiento— **se deduce del
  archivo**: un dato tecleado puede discrepar del certificado, y el día que
  discrepa nadie se entera.
- **Se comprueba abriéndolo**, no mirando la extensión, y **antes de escribir
  nada**. Probado: con la clave cambiada, el que estaba funcionando sigue
  emitiendo.
- **El nombre lleva `-app`.** El del `.env` suele llamarse `certificado.pfx` en
  esa misma carpeta; compartiendo nombre, «quitar el subido» borraba el archivo
  al que apunta `DTE_CERT_RUTA` — el respaldo desaparecía justo cuando hace
  falta. Lo encontró la prueba que lo comprueba.

El `.env` queda de respaldo y `srv` sigue sobre él a propósito: subirlo desde la
app es la única parte que no puedo probar yo —hay que entrar con contraseña— y
es la que hay que estrenar. `php artisan dte:certificado` lo hace desde el
servidor cuando la app todavía no se puede abrir; la clave se pregunta, no se
pasa como argumento, para que no quede en el historial.

Medido contra producción: con el certificado importado al almacén, `dte:token`
sigue trayendo token de palena y el SII lo reconoce.

**Y en la campana**: el buzón avisa de que hay una versión nueva, que antes sólo
sabía quien entrara a Cuenta. No es una notificación del servidor y no podría
serlo —el servidor no sabe qué versión tiene cada aparato—, así que es un aviso
sintético que calcula el teléfono. Se apunta **la versión** de la que se avisó y
no un «ya lo vi»: así la siguiente vuelve a encender el punto rojo sola.

### 0.45.0 — Comprobar que la base sirva antes de instalar (2026-09-22)

Fase B. `/setup` ya comprobaba el servidor y que la base fuera de Softland; lo
que no comprobaba era si esa base **tiene lo que la app usa**. Cada empresa
corre la versión de Softland que le tocó.

`App\Services\Softland\Compatibilidad` mira `INFORMATION_SCHEMA` en tres
consultas —tablas, columnas, procedimientos—, sin importar cuántas tablas sean:
**46 tablas, un procedimiento y 731 columnas**.

Dos decisiones que explican su forma:

- **Lo que la app lee no se escribe aquí, se deduce de `Maestros::recursos()`.**
  Los treinta maestros ya declaran tabla, clave y columnas; una segunda lista
  serían dos verdades esperando a diferenciarse. Un maestro nuevo queda
  comprobado sin tocar el archivo. Sólo se escribe a mano lo que el catálogo no
  nombra: las tablas que se leen fuera de él (`wisusuarios`, `soempre`, los
  permisos, `nwparam`…) y las columnas que se **escriben** pero nunca se leen.
- **Lo que falta no siempre es fatal.** Cada tabla cuelga de un grupo y el grupo
  dice qué se pierde: entrar y leer la empresa · cotizar y vender · maestros del
  catálogo · seguimiento · facturar · DTE. Sólo los dos primeros impiden
  instalar. Negarse a instalar porque falta `iw_encpicking` sería mentira; una
  base sin las tablas del DTE sirve para cotizar y vender, y eso se dice en vez
  de descubrirse facturando.

Dónde se usa: en `/setup`, **antes de guardar la conexión** y contra la
conexión de prueba —guardar una conexión a una base incompleta deja el servidor
instalado y roto a la vez—, y a mano con `ventas:compatibilidad`.

Medido: contra `INNOVAGES`, los 47 objetos presentes. Contra `master` —que no es
una base Softland—, los 47 ausentes y los seis grupos caídos. De paso, los ocho
nombres de columna que yo había deducido mal del código (`soempre.EmpRut` es
`RutE`, los permisos van por `Formulario`/`Control`, el número de nota de venta
en los atributos es `Codigo`) los encontró la primera corrida contra la base
real, que es exactamente para lo que sirve esto.

También: la redirección a `/setup/listo` era la última absoluta que quedaba, y
detrás del proxy salía con el esquema y la carpeta equivocados. Ahora usa
`Rutas`.

### 0.44.0 — Instalar en otra empresa sin tocar archivos (2026-09-22)

Fase A del plan para replicar el sistema a cualquier empresa Softland. La
decisión de fondo: **un repositorio y N instalaciones**, no una copia del
repositorio por cliente. Medido antes de decidirlo — `INNOVAGES` no decidía nada
en el código, sólo aparecía en comentarios de evidencia y en cuatro valores por
omisión, que se quitaron.

Lo que se hizo:

- `bin/instalar.cmd`: arranque de un servidor Windows recién clonado
  (dependencias, `.env`, clave, carpetas, Alias de Apache). Idempotente,
  probado en `srv` corriéndolo sobre una instalación ya hecha.
- `App\Support\Requisitos` + la comprobación en `/setup` **antes** del
  formulario: PHP, ocho extensiones, `APP_KEY` y dos carpetas escribibles.
- `App\Support\Rutas`: redirecciones en `Location` relativo.
- `/setup` se reabre cuando la conexión guardada no responde.
- `/setup/listo` lleva al código QR.

**El defecto que apareció midiendo**, y que se llevaba por delante cualquier
instalación detrás de un proxy:

```
antes:  https://venta.netdomain.cl/setup  ->  302  ->  .../venta-softland  ->  404
ahora:  https://venta.netdomain.cl/setup  ->  302  ->  https://venta.netdomain.cl/  ->  200
        http://localhost:8086/venta-softland/setup -> 302 -> /venta-softland/ -> 200
```

El proxy pone la carpeta al reenviar, así que la carpeta que Laravel ve por
dentro no existe por fuera. Es la misma trampa que ya obligó a que `/app`
generara su QR con la dirección del navegador, un nivel más abajo: **ahora
alcanza también a las redirecciones**.

Pendiente de las fases siguientes: la actualización desde GitHub Releases
(Fase E, medida: 6,4 MB la actualización típica, 17,4 MB el paquete de
dependencias cuando cambia `composer.lock`). **Hecha en la 0.47.0**, y las dos
medidas se confirmaron.

### 0.43.2 — El compromiso sobrevive a la conversión (2026-09-22)

La cotización **8555** tiene dos anotaciones —`PRO` sin fecha y `LLA` para el
2026-09-24 a las 12:00— y su compromiso no salía por ninguna parte. No era la
descarga: el seguimiento está en el teléfono. Era `estado()`, que empezaba
descartando toda cotización que no estuviera en `P`, y la 8555 está en `V`.

Reproducido con el código real sobre los datos reales de `ddecap`:

```
cot 8552  estado=P  prox=2026-09-17 09:00  ->  atrasado
cot 8553  estado=N  prox=—                 ->  NO SALE
cot 8554  estado=V  prox=2026-09-27 12:00  ->  NO SALE   <-- escondido
cot 8555  estado=V  prox=2026-09-24 12:00  ->  NO SALE   <-- el reportado
```

La regla estaba contestando dos preguntas con una sola condición:

- **«Sin próximo paso» es de las abiertas.** A una vendida o perdida no hay que
  inventarle una llamada; contarlas sería inflar la lista.
- **Un compromiso anotado vale esté la cotización como esté.** Lo escribió una
  persona, con fecha y hora, y la ficha hasta lo ofrece al calendario de Android:
  aceptarlo y luego no enseñarlo es quedarse con la promesa. La excepción es la
  **nula**, donde se cayó el documento entero.

Con eso el equipo de `ddecap` pasa de `{atrasado 1, próximo 0}` a
`{atrasado 1, próximo 2}`. La regla sigue viviendo en un solo sitio
(`mobile/src/seguimiento.js`), así que el panel, el filtro de la lista y el chip
de cola cambian a la vez.

### 0.43.1 — El panel de un jefe abre en su equipo (2026-09-22)

`ddecap` es supervisor y no veía ningún compromiso de sus vendedores. No era el
alcance: el servidor le sirve **186 cotizaciones** y **5 seguimientos** —lo suyo
más lo de los vendedores 2 y 19—, medido llamando a `Maestros::pagina()` con su
propio contexto. Era la pantalla.

Su código de vendedor es el **5**, y el 5 no tiene **ninguna** cotización en doce
meses. El panel abría en «Yo», los cuatro contadores daban cero, y el bloque de
compromisos se esconde cuando está vacío —para no ocupar sitio con ceros—, así
que desaparecía entero. Sin bloque no hay pista de que al otro lado del selector
había 1 compromiso atrasado y 89 cotizaciones sin próximo paso. Reproducido con
el código real sobre los datos reales:

```
YO (ven 5)  : {atrasado:0, hoy:0, proximo:0, sin_compromiso:0}   bloque visible=false
EQUIPO      : {atrasado:1, hoy:0, proximo:0, sin_compromiso:89}  bloque visible=true
```

Dos cambios:

- **Un jefe arranca mirando a su equipo.** Sin ámbito guardado, `admin` y
  `supervisor` abren en «todos»; el guardado sigue mandando.
- **El bloque distingue «no hay ninguno» de «no hay ninguno tuyo».** Si lo propio
  está vacío y el almacén no, se queda con sus ceros y debajo dice qué hay al
  otro lado —«Tu equipo tiene 1 atrasado y 89 sin próximo paso»— con un enlace
  que cambia el ámbito. Las dos cuentas salen de una sola lectura de
  `seguimientos`: `resumen()` ahora acepta el mapa de compromisos vivos ya hecho.

### 0.43.0 — Los compromisos también cambian con el ámbito (2026-09-22)

El selector Yo · Equipo · Empresa mandaba sobre la venta y el rendimiento, pero
los compromisos se quedaban a medias: los números sí se filtraban, el rótulo
decía «Mis compromisos» siempre, y las tarjetas abrían la lista sin filtrar. De
90 cotizaciones abiertas en INNOVAGES, 85 son de un vendedor y 5 de otra, así
que tocar «5 sin próximo paso» y ver 89 no era un matiz.

Ahora el ámbito viaja en la dirección (`?ambito=yo`), la lista lo aplica y lo
enseña en un chip que se quita, y el chip de cola de la propia lista cuenta con
el mismo ámbito. La regla de «¿de quién es esto?», que estaba copiada cinco
veces, vive ahora en `mobile/src/alcance.js`.

### 0.42.0 — Repartir la app (2026-09-22)

Hasta ahora el APK se pasaba a mano. Ahora el servidor lo reparte:

- **`/app`** — versión, código QR y botón, los dos al mismo archivo. La
  dirección no lleva la versión dentro: entrega siempre el último publicado.
- **`bin/publicar-apk.sh`** — sube el APK compilado a
  `storage/app/private/apk` en `srv`. Se compila y se publica en dos pasos
  distintos a propósito: no todo lo que se compila se reparte.
- **Cuenta** ofrece «Actualizar la app» cuando el servidor tiene una versión
  **posterior** a la del teléfono, y abre la descarga en el navegador del
  sistema: instalar un APK es cosa de Android, no de la vista web.

Lo que costó medir: **detrás del proxy, ninguna dirección que escriba el
servidor sirve**. `url()` genera `http://venta.netdomain.cl/venta-softland`, con
el esquema y la carpeta equivocados, porque el proxy termina el TLS y quita la
carpeta. La página se apaña con rutas relativas —que salen bien por los dos
caminos mientras se pida sin barra final, que es como está declarada— y le pasa
al servidor, desde `location`, la dirección que tiene que meter dentro del
código QR.

**`/app` es público**: cualquiera que alcance `venta.netdomain.cl` puede bajar
el APK. Es una decisión, no un descuido — el instalable no lleva credenciales
dentro, la dirección del servidor se escribe al abrirlo por primera vez, y
exigir sesión para descargarlo haría imposible lo que esto viene a resolver:
que un vendedor nuevo, que todavía no tiene cuenta, lo instale escaneando un
código.

### 0.41.2 — El alta de usuarios tenía el botón, pero no se dibujaba (2026-09-22)

Buscando dónde dar de alta a `preyes` se descubrió que no se podía: el botón de
crear de `Usuarios.vue` estaba declarado con `useAccionCrear()`, que lo delega
en el flotante, y el flotante sólo aparece en las pestañas. `/usuarios` es
pantalla de adentro. Ahora el «+» va en la barra, junto al título.

Era la única pantalla con ese desajuste: Clientes, Documentos y Facturas, que
también usan `useAccionCrear()`, son las tres pestañas.

De paso, los botones de las barras pasan de 36 a 44 px de área pulsable sin que
la barra cambie de alto: el relleno vertical baja de 10 a 7 px y la barra sigue
en 62. Comprobado en 360×640 en las tres escalas.

### 0.41.1 — Quien está en Softland pero no en la app, se entera (2026-09-22)

`preyes` —Priscila Reyes, vendedora 19— no podía entrar y el mensaje culpaba a
la clave. La clave estaba bien: le faltaba la ficha en `ventas.usuario`, que
sólo tenía tres filas (`softland`, `jpalomin`, `ddecap`) frente a los 15
usuarios de `softland.wisusuarios`. El alta la hace un administrador desde
**Cuenta → Usuarios**, eligiendo el usuario de Softland del desplegable.

Lo que se arregló en el código es el mensaje, que hacía perder el tiempo
cambiando una clave que no estaba mal. `AuthController::sinAlta()` se pregunta
**sólo después de que el login falla** y **sólo contesta que sí con la clave de
Softland correcta**: a quien no la sabe se le sigue diciendo «usuario o
contraseña incorrectos», así que no se puede averiguar quién existe probando
nombres. Comprobado contra la base real en sus cuatro ramas — usuario del ERP
sin ficha con su clave buena, el mismo con una clave inventada, un usuario que
sí tiene ficha, y un nombre que no existe.

Descartado por medición, para que no se vuelva a mirar ahí: no hay ningún
límite de 8 caracteres en el nombre de usuario. `softland.wisusuarios.Usuario`
es `varchar(8)` y por eso ningún nombre del ERP pasa de 8, pero `preyes` tiene
6; `ventas.usuario.softland_user` admite 20 y el login, 60. El 8 que se veía
era el **largo de su contraseña**, que es lo que guarda el primer byte del
cifrado de Softland.

### 0.41.0 — La orden de compra viaja de la venta a la factura (2026-09-22)

Dos cosas que se pedían juntas y resultaron ser tres. Todo medido antes de
escribir: el detalle está en `docs/ciclo-normal.md`, paso 7, y en `docs/dte.md`.

- [x] **La orden de compra se escribe también en la cotización.** La da el
      cliente al aceptar, que suele ser antes de convertir, y ya viajaba sola de
      la cotización a la nota de venta. El campo estaba sólo en la NV.
- [x] **18 caracteres, no 15.** `numOC` y `FolioRef` son los dos `varchar(18)` y
      hay OC reales de 18; la validación del servidor estaba en 15 y devolvía un
      422 que no decía por qué.
- [x] **La factura la nombra en el DTE con el código 801**, no con el 802. El
      maestro del ERP declara 801 «Orden de Compra/Orden de Servicio» y 802
      «Nota de Pedido/Hes/Has»; en INNOVAGES las **188** referencias 802 llevan
      el número de la nota de venta y **ninguna** coincide con un `NumOC`, y las
      **6** con 801 llevan la orden de compra y coinciden con el `NumOC` de su
      nota de venta en las 6. Se pidió el 802 y habría chocado con una costumbre
      de 188 documentos.
- [x] **Van las dos a la vez.** La factura 232 lleva la 801 con `1368` en la
      línea 1 y la 802 con `2046` en la 2. `Facturacion::referencia()` escribía
      una sola fila con `LineaRef` fijo en 1; ahora es `referencias()`.
- [x] **`AuxDocNum` dejó de ser «la primera referencia».** Es la primera que
      nombra un documento nuestro: con la orden de compra delante, la primera
      puede ser un papel que no existe en `iw_gsaen`, y dejarla ahí diría que
      esta factura corrige una factura número `U36401`.
- [x] **`iw_gsaen.Orden` no era el sitio**, aunque se llame así. Es un `int` y
      está en `'0'` en las 200 facturas: no podría guardar `272-OC00008216` ni
      queriendo. Se deja como lo deja el ERP.
- [x] **Facturar la venta y facturar una comisión son dos documentos, y ahora se
      elige cuál.** Se deducía del receptor, y eso hacía imposible un caso
      corriente: los mismos productos facturados a otro RUT porque quien paga no
      es quien recibe. La diferencia que importa es el saldo.
- [x] **La factura hereda lo que describe la venta**: condición de pago, bodega,
      centro de costo, observación y orden de compra. La observación y la OC se
      pueden corregir antes de emitir; el resto no, que son datos de la venta.
- [x] **La observación no cabe entera y se dice.** 4.000 caracteres en la nota
      de venta, 255 en la glosa de la factura. Se enseña el recorte antes de
      emitir, como con lo que el SII recorta en el alta de clientes.
- [x] **Dos llaves nuevas por empresa** (`referencia_orden_compra` y
      `referencia_nota_venta`), las dos encendidas al nacer. Publicar el número
      de la nota de venta en un documento que lee el cliente es una costumbre de
      INNOVAGES, no una regla del SII.

**Comprobado** escribiendo los tres documentos contra la nota de venta 2045
dentro de una transacción que se deshizo: la venta al cliente de la NV, la venta
a otro RUT y la comisión. Las tres con sus dos referencias en el orden del ERP y
con la glosa del maestro. La venta a otro RUT escribió `nvCorrela 1` y bajó el
saldo de 1 a 0; la comisión escribió `nvCorrela 0` y lo dejó en 1. Deshecho
todo, el saldo vuelve a 1 y el folio 238 sigue libre. Las 58 pruebas de PHPUnit
y las 176 del teléfono siguen pasando.

**Medido a 360 px en las tres escalas** (0,92 · 1 · 1,1): las filas de elección
van de 103 a 128 px de alto —muy por encima de los 44—, el rótulo no baja de 12
px (otra vez el 13 por 0,92 dando 11,96, que el `max()` atrapa), el detalle va a
12 clavados y los campos a 16, que es lo que evita el zoom de Android. Nada
desborda de 360. **No hay captura**: el panel del navegador no compone imagen en
esta máquina, así que esto son medidas del DOM, no una revisión visual.

## Incidencias

### 2026-09-24 · La API dejó de conectar a SQL Server, y el depurador estaba encendido

A las 13:21:14 la app empezó a contestar `SQLSTATE[08001] … Encryption not
supported on the client` en toda petición que tocara la base. **Sólo por
Apache**: desde la consola `ventas:probe` conectaba sin problema, por memoria
compartida y con `encrypt_option = TRUE`.

Descartado, con la máquina delante: `ForceEncryption` está en 0; ningún DLL de
`System32` se tocó ese día; no hubo actualizaciones de Windows; SQL Server lleva
en marcha desde el 21/08; y el 8086 lo sirve el Apache de XAMPP y no el
`wamp64` que también está instalado. Tampoco fue un cambio nuestro: el último
despliegue había sido el día anterior a las 03:44 y la app funcionó toda la
jornada.

Lo que quedaba: el proceso trabajador de Apache llevaba vivo desde el 23/09 a
las 18:10 y dejó de poder levantar el contexto de cifrado a media vida —el
paquete de login va cifrado aunque `ForceEncryption` esté apagado—, con la
máquina a 1,4 GB libres de 24. **Reiniciar `Apache2.4` lo arregló**, y no ha
vuelto a salir. **No hay causa probada**: con una sola observación no da para
más. Si reaparece, hay un segundo punto para trazar la línea, y la mitigación
conocida es reciclar el trabajador (`MaxConnectionsPerChild`).

Lo que sí quedó demostrado y arreglado es otra cosa: el servidor corría con
`APP_ENV=local` y `APP_DEBUG=true`, así que el error salió **en la pantalla del
teléfono con el `select` y el hash del token dentro**. Viene de
`.env.example`, que `bin\instalar.cmd` copia tal cual: toda instalación nacía
con el depurador encendido. Corregido en el origen, en `srv` (con respaldo en
`C:\xampp\htdocs\.env.respaldo-20260924`) y en el manual. Y `.env.example`
se añadió a los paquetes de `deploy.sh` y de la publicación, donde no estaba:
sin eso el arreglo no llegaba a un servidor instalado desde el tar.

## Pendiente / próximos pasos
- [x] **Publicar la primera versión en GitHub.** Hecha el 2026-09-23: la
      `v0.47.2`, con las tres piezas. `gh release create` devolvió un 422
      —«Release.tag_name already exists»— al publicar su borrador, porque la
      release ya había quedado creada; se comprobó que no quedara ningún
      borrador suelto y que los tres adjuntos coincidieran byte a byte con los
      locales. Si vuelve a pasar, mirar antes `gh release list` que repetir.
- [ ] **Fase D** — `bin/deploy.sh` con la máquina de destino como argumento, y
      un `appId` por empresa sólo si hace falta de verdad.
- [ ] Crear los primeros vendedores y probar la app con un usuario que no sea
      admin: el alcance por vendedor está probado contra la base, pero no con
      alguien usando el teléfono. El usuario `softland` no tiene `ven_cod`
      —es administración— y desde 0.24.0 eso ya no le impide facturar: el
      vendedor lo hereda el documento de su nota de venta.
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
- [ ] **Probar el flujo entero de facturación con un vendedor de verdad.** Los
      dos modos —la venta y la comisión— están comprobados contra la base en una
      transacción deshecha, pero no con alguien usando el teléfono.
- [ ] **Fase 4.5 — el ciclo normal de venta**: plan en `docs/ciclo-normal.md`.
      **Terminada**: los seis pasos, con la pantalla de facturación incluida.
- [ ] **Fase 4, paso 4**: el primer envío de verdad. Todo el camino está
      probado menos el último paso, que no se deshace. La **factura folio 235 ya
      está escrita** —NroInt 203, a NETDOMAIN EIRL, 1.000 con IVA, de la nota de
      venta 2065— y **no ha viajado al SII**: no tiene fila en `dte_doccab`.
      Mandarla es lo que queda.
- [ ] **Programar `dte:pendientes` en el Task Scheduler de `srv`**, cada 5
      minutos. **A propósito no está creada todavía**: en cuanto corra, manda
      sola la factura 235, y ése es el primer envío de verdad — el que hay que
      apretar a mano, mirándolo.
- [ ] **No quedan folios de factura.** El 235 era el último del CAF cargado; de
      nota de crédito queda uno, el 16. Hasta que se cargue un CAF nuevo en
      Softland no se puede emitir otra factura, ni desde la app ni desde el ERP.
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

### El vendedor de una factura es el de la venta

El servidor estampaba en el documento el `ven_cod` del usuario conectado. Dos
consecuencias, y las dos estaban ocurriendo:

- **facturación y administración no podían emitir nada.** No son vendedores, no
  tienen código, y el documento se rechazaba con «un documento de venta sin
  vendedor no existe para Softland». Es el fallo que apareció en terreno al
  facturar con el usuario `softland`;
- un vendedor que emitiera la factura de otro **se quedaba con la venta**, y con
  la comisión, sin que se notara en ninguna pantalla.

La factura hereda ahora el vendedor de su nota de venta y la nota de crédito el
de la factura que anula. Los datos respaldan la regla: de las 204 facturas de
INNOVAGES nacidas de una nota de venta, 181 llevan el vendedor de su nota de
venta —6 de las 23 restantes van sin vendedor—, y las 12 notas de crédito
llevan, las 12, el vendedor de la factura que anulan. En NETDOMAIN, 625 de 649.

Se **sobrescribe**, no se rellena: heredar es la regla, no el valor por
omisión. Sólo la factura sin nota de venta detrás pregunta de quién es la venta,
con la misma regla que la cotización —el tuyo, o el de tu gente si eres
supervisor—, y esa regla vive ahora en un solo sitio (`AlcancePorVendedor`).

De paso: en `iw_gsaen.Usuario` se escribía vacío, porque la propiedad se llama
`softland_user` y no `usuario`. El documento salía igual de correcto, así que no
se notaba; el ERP pone ahí el usuario de Softland y es por quién se pregunta
cuando alguien cuadra el mes.

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
- [x] **Anulación entera, no devolución parcial** — decisión del cliente.
      `CodRef 1` anula; devolver parte es otro documento con otra referencia.
      `Facturacion::devuelveTodo()` lo comprueba línea a línea **antes de pedir
      folio**, y cuatro pruebas lo fijan.
- [x] **La razón va en la columna `Glosa`**, no en `RazonRef`: el `RazonRef` del
      DTE sale de la primera, y la que se llama igual está vacía en los 209
      documentos reales.
- [x] No se anula dos veces: 409 nombrando la nota de crédito que ya existe.
- [x] Maestro `factura_referencias`, para saberlo sin señal. La referencia vive
      en `IW_GSaEn_RefDTE`, no en `AuxDocNum`.
- [x] Comprobado contra la API: factura 235 → saldo 6, nota de crédito 16 →
      saldo 10, segunda nota de crédito rechazada. Sin gastar folio.

### La factura sin nota de venta
- [x] `FacturaNueva.vue`: elegir cliente, agregar productos con su precio y
      emitir. Se llega desde la cola de facturación, con el botón flotante.
- [x] **Resultó ser la forma normal, no la excepción**: en NETDOMAIN el 88 % de
      las facturas y el 100 % de las boletas no tienen nota de venta detrás.
- [x] `GET /facturas/folios`, para poder decir cuántos quedan sin documento de
      por medio.

### El envío al SII desde la app
- [x] Cada factura de la ficha lleva su estado ante el SII y su acción: enviar
      cuando no ha viajado, consultar cuando sí.
- [x] **Tres barreras**: lo hace facturación o administración; no se manda dos
      veces —el 409 nombra el `TrackID`—; y un documento anulado no se manda.
- [x] **Enviado y aceptado se dicen distinto.** El veredicto tarda minutos, así
      que preguntar es una acción aparte.
- [x] Maestro `dte_estado`: la ficha lo dice sin señal. Sin red, la consulta
      devuelve lo guardado en vez de un error.
- [x] Comprobado contra el servidor real: 403 al vendedor, 409 sobre la factura
      234 con su TrackID, y el SII contestó «EPR — Envio Procesado, aceptados 1».

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

### La navegación de dos niveles, y los documentos enlazados (0.32.0)
- [x] **Avisos y Cuenta se fueron de la barra a la cabecera.** La barra de abajo
      es *dónde estoy*, no quién soy: se llevaban la mitad del sitio más valioso
      con dos cosas que se visitan una vez al día. `BarraSuperior.vue` las pone
      en el mismo píxel de todas las pestañas — iniciales a la izquierda,
      campana con su contador a la derecha.
- [x] **La barra pasa a Panel · Documentos · Clientes · Cobranza**, y ahí se
      cierra: en 360 px, con la activa desplegada, una quinta no cabe sin bajar
      de los 44 px de área pulsable.
- [x] **«Documentos» es un destino con tres caras.** Cotizaciones, notas de
      venta y facturas son el mismo documento en tres momentos de su vida:
      hermanas, se cambian con `PestanasDocumento.vue` y `replace`, y la barra
      abre por la última que se miró (`ultimaLista` en `nav.js`). El de terreno
      y el de facturación no viven en la misma lista.
- [x] **El «+» siempre crea; la pestaña siempre lista.** Eran el mismo gesto, y
      por eso *ver* una lista obligaba a pasar por el panel. El botón flotante
      abre una hoja con los tres documentos —la acción de la pantalla primero—
      y vive sólo en las pestañas (`route.meta.tab`): en la ficha de un
      documento ofrecía empezar otro justo cuando no tocaba.
- [x] La flecha de las tres listas vuelve **al panel**, no «atrás»: un retroceso
      ahí desharía el zigzag entre hermanas. Avisos y Cuenta, que ya no son
      pestañas, estrenan franja índigo y su propia flecha.
- [x] **Los documentos enlazados, y abribles.** Las fichas *contaban* sus
      relaciones —«viene de la nota de venta 812», «anulada con la NC 45»— y
      había que volver a la lista y buscar el número a mano.
- [x] Quién cuelga de quién se decide en **un solo sitio**
      (`mobile/src/relaciones.js`) y se dibuja en **uno solo**
      (`Relacionados.vue`). Tres fichas contestando la misma pregunta por su
      cuenta acaban diciendo cosas distintas de lo mismo.
- [x] Los dos enlaces que se usan para navegar son **de Softland**:
      `nw_nventa.CotNum` y `iw_gsaen.nvnumero`. El nuestro, el de línea a línea
      en `ventas.linea_origen`, es para el saldo — aquí basta con saber que el
      documento existe.
- [x] Todo se lee del teléfono: una ficha que sólo enseña sus relaciones con
      señal no las enseña cuando hacen falta. Un documento fuera de la ventana
      de doce meses se enlaza igual y se trae al abrirlo.
- [x] **Cobranza queda declarada como fase 5**, con su pantalla diciendo qué va
      a haber ahí y un atajo a Facturas mientras tanto. Un botón que no hace
      nada y no explica por qué parece una avería.
- [x] **El «atrás» de Android cerraba la app desde las tres listas** (0.32.1).
      Al entrar en la barra pasaron a ser raíz, y ahí el gesto significa salir;
      como se llega a ellas con `replace`, detrás no había historial suyo. Ahora
      **el panel es el destino inicial** y la única pestaña desde la que se
      sale: las demás vuelven al panel, igual que la flecha de su cabecera.
- [x] **Deslizar la lista de lado cambia de pestaña** (0.33.0). El orden es el
      del ciclo de venta, así que hacia la izquierda se avanza; en los extremos
      la lista cede apenas y vuelve sola. La lista se arrastra con el dedo y la
      siguiente entra desde el lado del que vino.
- [x] **Los dos gestos de la lista se ceden el paso.** Tirar para refrescar y
      deslizar miran el mismo dedo: nada ocurre hasta que un eje le saca
      ventaja clara al otro. El desplazamiento vertical normal no se cancela
      nunca — comprobado con toques sintéticos: 0 `preventDefault` en un
      desplazamiento hacia arriba de 200 px.
- [x] El orden de las tres listas vive en `LISTAS_DOCUMENTO` (`nav.js`), y lo
      leen las pestañas y el gesto. Escrito dos veces sería un gesto que lleva
      a otra pestaña que la encendida.

### El permiso de Softland para facturar a otro cliente (0.34.0)
- [x] **Encontrado en la base, no inventado**: `IW · Iw_FacLin · NVOtroAuxiliar`,
      «Permite que la Factura quede asociada a una Nota de Venta de otro
      Cliente». Definido también para `IW_FACMONEXT` y `x_FaLiEx`.
- [x] En INNOVAGES lo tiene **sólo el perfil `IW/001`**; `IW/vend` no, y ningún
      usuario a título individual. La base ya decía «los vendedores hacen el
      ciclo normal».
- [x] `app/Services/Softland/Permisos.php`: los `wisrest*` son **concesiones**
      (fila = puede) y se **suman** por perfil (`wisrestperfil` vía
      `wisperfilusuario`) y por usuario (`wisrestusuario`). Un usuario puede
      tener varios perfiles del mismo sistema — `jpalomin` tiene `IW/001` y
      `IW/vend` —, así que preguntar por uno solo da que no a quien sí puede.
- [x] `receptorEditable($usuario)` pasa a ser el **Y** del permiso y la llave de
      empresa; `receptorEditableEnLaEmpresa()` para la pantalla de configuración.
      La llave sólo apaga, nunca enciende lo que el ERP negó.
- [x] Ensayado en la base de pruebas, los tres casos en verde, **buscando los
      usuarios en la base** en vez de escribir un nombre. `dte:verifica-documento`
      sigue igual: usa `ReglasFactura(true)` y corta antes de mirar permisos.
- [x] **La Liquidación-Factura (DTE 43) queda fuera del alcance, y medido por
      qué.** Softland la trae entera —`frmLiqFac`, `IW_Liquidacion`, las cuentas
      `Cta*LFDTE` de `iwparam`, `iw_gsaen_comislf`— e INNOVAGES no la usa: esas
      columnas en `NULL`, `PorcMandatorio = 0`, `iw_gsaen_comislf` con 0 filas y
      **ni un DTE 43** entre 270 del 33, 2 del 34 y 14 del 61.
- [x] La forma del negocio, medida: de **193 facturas atadas a una nota de venta,
      191 van a otro cliente** (189 a Softland Ingeniería) y **2 al cliente de su
      propia nota de venta**.

### La factura de comisión, y la cantidad con más y menos (0.35.0)
- [x] **La pantalla que faltaba.** El permiso y la regla del servidor estaban
      desde 0.34.0, pero `Facturar.vue` mandaba siempre el cliente de la nota de
      venta: no había forma de emitir la factura del mandante desde la app.
- [x] **Lo que decide el documento es el receptor**, no un interruptor aparte.
      Al cambiarlo, las líneas de la NV dejan de servir y se escriben a mano. Un
      modo aparte dejaba posible el estado incoherente —otro receptor con las
      líneas de la venta—, que es el documento que no se quiere emitir.
- [x] **Enlazada a la NV y sin consumirle saldo**, las dos a la vez: va en
      `iw_gsaen.nvnumero` y sus líneas no llevan `nv_linea`. Ensayado en la base
      de pruebas: receptor correcto, `nvnumero` correcto, `nvCorrela` en cero y
      el saldo intacto en 12. El folio se gasta y vuelve al borrarla.
- [x] El precio nace en **cero**: la comisión no se calcula, la escribe quien
      factura.
- [x] **Sin señal también**: el permiso viaja en el arranque y se guarda. Entra
      a `propuestaLocal()` por la puerta, no leyéndolo ahí, para que `saldo.js`
      siga sin saber de plataforma — es el que corre en Node con `npm run pruebas`.
- [x] `Cantidad.vue`: menos a la izquierda y más a la derecha. Arriba y abajo
      partiría el área pulsable en dos de 22 px. Medido en 375 px: botones de
      44×44, campo de 231 px a 16 px de letra, sin desplazamiento horizontal.
- [x] La cantidad **se lleva la fila entera**: compartiéndola con precio y
      descuento el campo quedaba en **16 px de ancho**.
- [x] **Fallo corregido**: `FacturaController` no validaba `lineas.*.descuento_pct`
      y `validate()` devuelve sólo lo validado, así que el descuento tecleado en
      la factura suelta se caía en silencio — la pantalla enseñaba un total y se
      emitía otro, en un documento tributario.

### Alta de clientes desde el SII, paso 1: la traducción (0.36.0)
- [x] **Plan y evidencia en `docs/alta-clientes-sii.md`.** Lo que se construye
      es: se escribe el RUT y el formulario de alta aparece lleno con lo que el
      SII publica, para completar y corregir en vez de transcribir. No es sólo
      comodidad: de los 3.373 clientes, **1.211 no tienen correo DTE**, 780 no
      tienen ciudad y 631 no tienen giro.
- [x] **El teléfono no llama a la API del SII; la llama el servidor.** La llave
      no puede ir en el APK, que se descompila, y además el servidor es el único
      que puede traducir el texto a códigos: es quien tiene los maestros.
- [x] **`app/Services/Sii/Traduccion.php`**, único sitio donde el castellano del
      padrón se vuelve `ComCod`, `CiuCod` y `GirCod`.
- [x] **La comuna calza**: 331 de los 347 nombres del padrón vigente, el
      **98,48 %** de 3.604.762 domicilios. Las once que no son la misma comuna
      escrita de otra manera y están en `Traduccion::COMUNAS`; «Sin Comuna» es
      un marcador; Cholchol no está en Softland y devuelve `null`.
- [x] **Ante dos comunas con el mismo nombre gana la del código del INE.**
      `cwtcomu` tiene ocho filas puestas a mano con el nombre mal escrito
      —`CPN` «CONCPECION», `VITACUR` «VITAVURA»— y Estación Central está dos
      veces. Sin esa regla los clientes nuevos se reparten entre la buena y su
      duplicado, y los informes que agrupan por comuna dejan de sumar.
- [x] **La ciudad se deduce de la comuna** cuando el SII no la manda, que es
      **18 de cada 53** fichas. Se desempata con la región de la comuna ya
      resuelta; si quedan dos, no se elige.
- [x] **El giro va por código ACTECO y nunca por texto.** Hay dos listas y la de
      Softland (`sii_tacteco`) es la vieja: 696 de sus 698 códigos figuran como
      `ActEcoAntigua`. Comparten números con significados distintos — `702000`
      es «Corredores de propiedades» en una y «Actividades de consultoría de
      gestión» en la otra. **`sii_tacteco` no se consulta nunca.**
- [x] **Catálogo vigente en el repo**: `resources/sii/actecos.tsv`, 674 códigos
      de seis dígitos. **94 empiezan por cero**, así que el acteco es texto en
      todas partes: como número, `011101` se vuelve `11101`, que existe y es
      otra cosa, y acabaría impreso en el `GiroRecep` del DTE.
- [x] **`ventas.giro_sii`** para apuntar un acteco a un giro histórico en vez de
      estrenar fila.
- [x] **`ventas:verifica-sii`** contrasta sin escribir ni salir a internet:
      11/11 alias, 352/352 comunas, 937/937 ciudades, 674/674 actecos.
- [x] **La carga de `cwtgiro` está hecha** (0.37.0). Ver abajo.
- [x] **La API ya devuelve el código** (`giro_codigo`, `giros_todos_detalle`),
      filtra por `VIGENCIA` y prefiere `DOMICILIO` sobre `SUCURSAL` — las tres
      comprobadas. Le falta usar `DEPARTAMENTO`, `BLOQUE` y `VILLA_POBLACION`,
      que descarta: un cliente en un edificio llega sin el departamento.
- [ ] **Lo que el SII recorta en origen no tiene arreglo**: `CIUDAD` a 15
      caracteres, y razón social hasta 80 contra los 60 de `NomAux`. Por eso la
      pantalla tendrá que **enseñar el recorte**, no hacerlo callada.

### Alta de clientes desde el SII, paso 2: el maestro de giros (0.37.0)
- [x] **`cwtgiro` cargado con los 674 ACTECO vigentes**: de 2.009 a 2.667
      giros, y la cobertura del giro del **2,4 % al 100 %**. Sin esto, seis de
      cada siete clientes nuevos entraban con el giro vacío.
- [x] **`ventas:carga-giros`, que no escribe sin `--escribir`.** `cwtgiro` lo ve
      el administrativo en su desplegable del escritorio: que crezca tiene que
      ser una decisión mirando la lista, no un efecto colateral del alta.
- [x] **No toca una fila que ya exista, ni su descripción.** Los 16 actecos que
      ya estaban se quedaron igual. Un giro en uso tiene el texto que sus
      clientes reconocen y que sale impreso en el `GiroRecep` de sus DTE.
- [x] **No borra nunca**: `cwtauxi.GirAux` tiene clave foránea contra la tabla.
- [x] **Ensayado en `INNOVAGES_DTE` antes de producción**, como manda la regla 9.
      Comprobado en las dos: 0 filas existentes modificadas, 0 auxiliares con
      `GirAux` huérfano, los 94 códigos con cero a la izquierda con sus seis
      caracteres, y `GirDes` en 60 como tope.
- [x] **Descripciones recortadas a 60 cortando en palabra** —239 de 674 no
      caben— y sin la conjunción colgando.
- [x] **Fallo corregido**: `Traduccion::catalogo()` devolvía el código como
      clave de array y PHP convierte a entero toda clave que parezca un entero
      canónico. El array acababa con `'011101'` de texto y `474100` de número, y
      un `whereIn` así manda enteros a una columna `varchar`: SQL Server intenta
      convertir la columna y revienta contra el giro codificado `'..3'`. Ahora
      devuelve una lista.
- [x] **`.gitignore`**: los padrones del SII (331 MB con datos personales de
      cientos de miles de personas) y los vídeos ya no pueden colarse con un
      `git add -A`. `resources/sii/actecos.tsv` sigue versionado.

### Alta de clientes desde el SII, paso 3: la consulta (0.38.0)
- [x] **`GET /clientes/sii/{rut}`** devuelve la ficha de una empresa ya
      traducida a códigos de Softland. Es una **propuesta**: quien da de alta
      sigue siendo `store()`, con una persona de por medio.
- [x] **Si el RUT ya es cliente, contesta `ya_existe`** con su ficha y sus
      contactos, y no sale a internet. Probado con 76469595, que sí lo es.
- [x] **`app/Services/Sii/Auxiliar.php` es el único sitio que conoce la URL y la
      llave.** La llave está en el `.env` de `srv`, nunca en el repo ni en el
      APK. `config/services.php` la expone como `services.sii_aux`.
- [x] **La URL no se escribe en ningún registro, porque lleva la llave dentro.**
      `Auxiliar` **nunca** llama a `$respuesta->throw()`: la excepción HTTP de
      Laravel trae la URL en el mensaje y ese mensaje acaba en `laravel.log`.
      Los estados se miran a mano —404 no encontrado, 401 llave inválida, el
      resto genérico— y del `catch` de Guzzle sale un mensaje limpio.
- [x] **Caché en `ventas.sii_auxiliar`, con la respuesta cruda.** No la ficha
      traducida: así la traducción se rehace cada vez con las reglas de hoy, y
      un giro nuevo en `cwtgiro` o un alias de comuna añadido después mejoran
      también lo que ya estaba guardado. 30 días lo hallado, 7 lo no hallado.
- [x] **Si el servicio no contesta y hay algo guardado, se devuelve aunque esté
      viejo**, marcado `cache-vieja`. El vendedor está de pie delante del
      cliente: una ficha de hace cinco semanas vale más que una pantalla roja.
      Tiempos cortos: 3 s de conexión, 6 s de respuesta.
- [x] **`sii_disponible` en el bootstrap del login**, para que la pantalla sepa
      si ofrecer el botón.
- [x] **Fallo corregido en la primera prueba**: el controlador reducía el RUT a
      su cuerpo y se lo pasaba al servicio, que lo reducía otra vez.
      `Rut::cuerpo()` no puede ser idempotente —«76469596» es a la vez el cuerpo
      de 76.469.596-8 y el RUT 7.646.959-6 completo—, así que la respuesta era
      la de otra empresa **sin que nada fallara**. Ahora `consultar()` exige el
      RUT entero y comprueba el dígito: el error es ruidoso, no silencioso.
- [x] **`FacturacionMIPYME@sii.cl` no se filtra.** Parece basura y es el correo
      de intercambio de quien factura por el portal gratuito del SII, que es el
      correcto para mandarle el XML. Son **1.376.048 de 1.755.724** facturadores
      electrónicos del país, el **78,4 %**.
- [x] **Probado de punta a punta contra el SII de verdad**: 76469596-8 (HYTEC
      SPA) vuelve con comuna `08307`, ciudad `NEGRE`, giro `479909` y sus tres
      actecos; la segunda consulta del mismo RUT tarda 31 ms y dice `cache`.

### Alta de clientes desde el SII, paso 4: la pantalla (0.39.0)
- [x] **Botón «Buscar en el SII»** en el alta de `Cliente.vue`, bajo el RUT.
      Llena razón social, dirección, comuna, ciudad, giro y correo de
      intercambio, ya traducidos a códigos de Softland.
- [x] **No se dibuja si el servidor no sabe consultar** (`sii_disponible` viene
      en el arranque de la sesión). Ofrecer un botón que falla al apretarlo es
      peor que no ofrecerlo.
- [x] **Sin señal sale apagado, y lo dice.** La búsqueda **nunca** es requisito:
      el formulario se llena a mano y sale de la bandeja al volver la red.
- [x] **Sello «del SII» por campo, en el rótulo, y se cae solo.** No hay nada
      que vigilar: `deSii()` compara lo escrito con lo propuesto. Cambiar el RUT
      tira la propuesta entera — dejar los sellos sería decir que los datos de
      otra empresa vienen del SII.
- [x] **El recorte se enseña.** `NomAux` guarda 60 caracteres y el SII publica
      razones sociales de hasta 80: debajo del campo va el texto entero, en
      ámbar. El dato no está mal, pero alguien tiene que mirarlo.
- [x] **Los varios giros se eligen tocando, una fila cada uno.** Son de hasta 60
      caracteres: en 360 px no se reparten en dos columnas sin bajar de los
      44 px de área pulsable, así que cede la rejilla.
- [x] **Si el RUT ya es cliente se ofrece abrir su ficha**, con sus contactos
      guardados en IndexedDB para que no aparezca vacía. Mismo camino que el
      409 del alta repetida.
- [x] **`FacturacionMIPYME@sii.cl` lleva su explicación bajo el campo**, para
      que nadie lo borre creyendo que es basura.
- [x] **Medido en las tres escalas de densidad sobre 360 px**: nada desborda a
      lo ancho, ninguna fila de giro baja de 44 px de alto y el texto del giro
      no baja de 12 px. 13 px por 0,92 —la escala compacta— daba 11,96 y se
      colaba bajo el piso de lectura; va con `max(12px, calc(13px * var(--d)))`.

### Alta de clientes desde el SII, paso 5: los clientes que ya existen (0.40.0)
- [x] **«Actualizar desde el SII» en la ficha de un cliente.** Trae lo que
      publica el padrón, lo compara con lo que hay y enseña **sólo lo que
      cambia**. Una lista de seis filas iguales es una lista que nadie mira.
- [x] **No escribe nada.** Lo marcado cae en el formulario de edición y sale a
      Softland por el camino de siempre, el de Guardar, que ya sabe encolar sin
      señal. Un segundo camino de escritura sería un segundo sitio donde
      equivocarse.
- [x] **Lo vacío viene marcado; lo que pisa, no.** Rellenar un hueco casi
      siempre está bien y es a lo que vino esto; pisar lo que escribió una
      persona es la trampa del domicilio tributario. Una propuesta que viene
      aceptada es una propuesta que nadie lee.
- [x] **La dirección lleva su aviso debajo**, con todas las letras: el SII da el
      domicilio tributario y una ficha antigua puede llevar la oficina comercial
      puesta a propósito.
- [x] **`?con_sii=1`** hace que el endpoint devuelva las dos fichas. Sin el
      parámetro sigue cortando en cuanto ve que el RUT ya es cliente, que es lo
      que quiere el alta. Y si el SII no contesta, la ficha del cliente se
      devuelve igual: el fallo va como un campo más.
- [x] **La fila entera es pulsable**, no sólo la casilla: la casilla nativa de
      Android mide 20 px y pedir esa puntería seis veces seguidas es pedir
      errores. Comprobado en las tres escalas sobre 360 px: nada desborda, las
      filas van de 89 a 232 px y el rótulo no baja de 12 px —otra vez el 13 por
      0,92 dando 11,96—.

**Cuánto arregla esto de verdad, que es menos de lo que decía el plan.** De los
3.373 clientes, 1.211 no tienen correo para el DTE; de ésos **1.014 tienen RUT
de empresa** y 197 son personas naturales o códigos que no son RUT, a los que el
padrón de personas jurídicas no llega nunca. Sobre una muestra al azar de 40
empresas: 29 están en el padrón (72,5 %) y **sólo 10 traen correo de
intercambio** (34,5 %). O sea unos **250 de los 1.211**, uno de cada cinco.

Lo que el padrón sí trae casi siempre es la ubicación —dirección, comuna y
ciudad en 29 de 29—, y razón social y giro en 17 de 29. El grueso de lo que
esta pantalla propone son justo los campos donde pisar es peligroso, y por eso
el valor por omisión de las casillas no es un detalle de interfaz: es la
decisión principal del paso.

### El historial, auditado antes de subirlo (2026-09-19)
La rama lleva **59 commits sin subir** a `origin/main` y las etiquetas `v0.36.0`
a `v0.40.0` tampoco están subidas. Antes de empujar nada se revisó el historial
entero, porque lo que entra en un `git push` ya no se saca:

- [x] **Ningún padrón del SII entró nunca.** El blob más grande que ha existido
      son 1,2 MB (`mobile/recursos/icono_vs.png`); los padrones son 331 MB con
      datos personales de cientos de miles de personas. El `.gitignore` de la
      0.37.0 llegó después de descargarlos, así que había que comprobarlo y no
      suponerlo.
- [x] **Ningún secreto, en ninguna versión de ningún archivo.** Rastreado el
      contenido de los 59 commits buscando la llave del SII, contraseñas de base
      de datos, `APP_KEY`, certificados y llaves privadas. Lo único que aparece
      son cadenas literales de cabecera PEM en `Caf.php` y variables `$clave`
      que son claves de caché.
- [x] **Las cuatro versiones del `.env.example` van con los valores vacíos.**
      `APP_KEY` y `SOFTLAND_DB_PASSWORD` sin rellenar en las cuatro.
- [x] **La llave de `TimbreTest.php` es de juguete**: RSA de 512 bits generada
      para la prueba, inservible fuera de ella, y así está documentada.
- [x] **Los APK no están rastreados**, los dos de la raíz caen en `.gitignore`.

Dos cosas que sí se publican y conviene decidir a sabiendas, no descubrir
después. Ninguna es una filtración: son datos de forma, no llaves.

- `.env.example` lleva `SOFTLAND_DB_USERNAME=sa`, el nombre de instancia y el de
  la base. La contraseña no, pero el nombre de usuario administrador sí.
- `docs/alta-clientes-sii.md` nombra el servidor de la API (`sii-aux.netdomain.cl`).
  La llave va elidida con `key=…` en los dos sitios donde aparece la URL.

Comprobado además, el mismo día: sintaxis PHP de los nueve archivos tocados en
0.36.0–0.40.0, las diez migraciones corridas en producción, la versión que sirve
el servidor igual a la del repo (0.40.0), la ruta nueva contestando 401 por el
proxy —el guardia corre antes que la validación del RUT, que es el orden bueno—
y ningún script de sondeo olvidado en el web root.
