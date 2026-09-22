# Venta Softland

## Descripción
App **Android** para el flujo de ventas de Softland, de punta a punta:
**cotización → nota de venta → factura o boleta electrónica**. Pensada para el
vendedor en terreno: instalable, y capaz de **trabajar sin señal** y sincronizar
cuando vuelve la red.

No hay interfaz web. El servidor es solo una API; la administración (usuarios,
configuración, notificaciones) se hace desde la propia app con rol admin. La
única página HTML es `/setup`, que se usa una vez al instalar.

Cliente inicial: **INNOVAGES** (base Softland `INNOVAGES`). Diseñado para
replicarse a otra empresa Softland cambiando la configuración de conexión.

## Arquitectura

**Repositorio local:** `/home/ddecap/GIT/Ventas-Softland` (Linux, solo desarrollo)
**Repositorio remoto:** `git@github.com:dodecap/venta-softland.git`

**Dos caminos a la API, y no dan lo mismo:**
- `http://192.168.1.55:8086/venta-softland` — la instalación, desde el WiFi de
  la oficina.
- `https://venta.netdomain.cl` — proxy IIS (ARR) en el 443, hacia la anterior.
  **Sin la carpeta al final**, que la pone el proxy. Es la que sirve fuera de
  la oficina.

El puerto 80 de ese nombre responde 301 a https, y una redirección en la
comprobación previa de CORS **no se sigue**: una dirección guardada con
`http://` deja la app muerta aunque el navegador llegue. Por eso la pantalla
Servidor prueba los dos esquemas y guarda `res.url`, no lo que se escribió.

**Servidores** (usar siempre el alias SSH):
- `srv` — Windows Server 2022, `172.30.205.106`. **Producción y SQL Server 2022**
  en la misma máquina. XAMPP con PHP 8.4.24 (`sqlsrv`/`pdo_sqlsrv` instalados) y
  Composer. La API se despliega en `C:\xampp\htdocs\venta-softland`, servida por
  Apache en el **puerto 8086** bajo `/venta-softland`.

**Base de datos:**
- SQL Server 2022 en `srv`, instancia **`localhost\MSSQLSERVER2022`**, base
  **`INNOVAGES`**. **No escucha en el puerto 1433 hacia la red**: solo se llega
  desde dentro de `srv`. Por eso existe la API: el teléfono nunca habla con SQL.
- La app trabaja sobre la misma base Softland, en un **esquema propio `ventas`**.
  Lo suyo (usuarios, tokens, notificaciones) vive ahí; el flujo de ventas se
  escribe en las tablas nativas de Softland. Nunca se toca el esquema `softland`
  fuera del flujo documentado.

**Modelo de ejecución** — importa, porque las piezas se construyen en máquinas distintas:
- El código se escribe en el repo de esta máquina.
- **PHP, Composer y artisan corren en `srv`**: es la única máquina con acceso al
  SQL Server. Aquí no hay PHP instalado.
- **La SPA y el APK se compilan aquí** (Node 22, JDK 21 en `~/jdk21`, Android SDK
  en `~/android-sdk`).

**Sistemas involucrados:** Softland — ventas (`nwcotiza`, `nw_nventa`),
facturación (`iw_gsaen`, `iw_gmovi`), DTE (`dte_*`), usuarios (`wisusuarios`).

## Convenciones de trabajo

1. **Antes de empezar cualquier tarea**, lee `STATE.md`.
2. **Nunca** escribas contraseñas, IPs con usuario/clave, tokens ni connection
   strings en archivos versionados. Van en `.env` (excluido por `.gitignore`) o
   en el gestor de contraseñas. La conexión real se guarda **cifrada** en
   `storage/app/private/softland.json`, fuera de git.
3. Para conectarte a un servidor usa el alias SSH de `~/.ssh/config`.
4. Al terminar una tarea significativa: **actualiza `STATE.md` y haz commit**.
5. Documentación técnica extensa va en `docs/`, no en este archivo.
6. Si algo queda a medias, anótalo en «Problemas conocidos» de `STATE.md`.
7. **Toda consulta a SQL Server va parametrizada.** El servicio anterior
   (`E:\Servicio` en srv) concatenaba `req.body` en el SQL; ese patrón no se
   repite aquí.
8. **Nada se escribe en Softland sin ser idempotente.** Reenviar el mismo lote
   desde un teléfono que perdió la respuesta no puede duplicar documentos: la
   idempotencia va por `client_uuid`.
9. **Nunca escribir en `INNOVAGES` de verdad sin probar antes.** Hay un script
   de base de pruebas heredado en `srv:E:\Servicio\Scripts\Setup_Test_Database.sql`.
10. Las tablas de Softland **no están en `dbo`**, están en el esquema `softland`.

## Interfaz

Paleta heredada de la app que los vendedores ya usaban: **índigo `#1d1060`**
(el corporativo de Softland) con acento **cian `#26bdef`**. La regla visual:
todo elemento pulsable importante lleva subrayado cian de 4–5 px. Las listas de
documentos usan dos franjas verticales — izquierda el estado del documento,
derecha el estado de sincronización — para leerlas de un vistazo.

El objetivo es un **panel empresarial**: fintech + ERP + CRM. Tarjetas planas
con borde fino y sombra casi nula, mucho aire, densidad alta sin saturación.
El panel de inicio va en este orden: encabezado → KPIs → acciones rápidas →
actividad reciente → barra de navegación inferior.

### Navegación

Dos niveles, no más. Arriba las **pestañas** (`meta.tab` en el router):
**Panel · Avisos · Cuenta**, hermanas entre sí, se saltan con `router.replace`
y llevan la barra inferior flotante. Abajo las pantallas **de adentro**, que se
apilan con `push` sobre una pestaña y se salen con «atrás».

Una pestaña es un lugar al que se vuelve, no una acción que se hace: crear
cuelga del botón flotante y cotizaciones o productos son acciones del panel.
**El flotante es de las pestañas y de nadie más.** Una pantalla de adentro que
también crea —Usuarios— pone su «+» en la barra: declarar la acción con
`useAccionCrear()` fuera de una pestaña es registrarla para un botón que no se
dibuja, y la pantalla se queda sin forma de crear nada.
Desde la fase 2 las pestañas son cuatro — **Panel · Clientes · Avisos ·
Cuenta** — y ahí se cierra la lista: con la activa desplegada, en 360 px no
cabe una quinta sin bajar de los 44 px de área pulsable.

### Tamaño de la interfaz

Todo lo que crece o se achica cuelga de la variable CSS `--d`, que escribe
`mobile/src/densidad.js` y elige el vendedor en Cuenta (compacta · normal ·
amplia). Los tamaños base están calibrados para `--d: 1` sobre 360 px de ancho.

Tres cosas **no se escalan nunca**: los 44 px de área pulsable, los 16 px de
los campos de formulario (bajo eso Android hace zoom al enfocar) y el texto de
12 px o menos, que ya está en el piso de lectura. Cuando algo no cabe, **lo que
cede es la rejilla, no esos números**: la cantidad con sus dos botones de 44 px
se lleva la fila entera en vez de compartirla con el precio. Quien quiera un panel más
apretado gana espacio con la separación y los iconos, no con la letra chica.

La prueba objetiva antes de tocar un tamaño: en 360×640 las seis acciones
rápidas tienen que caber sobre la barra inferior sin desplazar, en las tres
escalas.

### Iconografía — regla dura

**Prohibido usar emojis como iconos.** Tampoco glifos tipográficos haciendo de
icono (la cruz de cerrar, la comilla angular de volver, la flecha circular de
actualizar). Un emoji se dibuja distinto en cada teléfono, no hereda el color
del texto ni el grosor de trazo, y le da aire de prototipo a una herramienta
conectada al ERP.

- Una sola familia: **Lucide** (`lucide-vue-next`). No se mezcla con otras.
- Todo icono se dibuja con `<AppIcon name="…">`. Ninguna pantalla importa de
  `lucide-vue-next` por su cuenta.
- Las pantallas eligen un **concepto** (`cliente`, `factura`, `cobranza`), no
  un icono. El mapa concepto → icono → familia funcional está en
  `mobile/src/iconos.js`, y es el único lugar donde se toca.
- El color del icono dice **de qué familia es** (venta, catálogo, dinero,
  aviso, administración, peligro), no adorna.
- Los iconos de acción van dentro de un recuadro redondeado de fondo suave:
  48–56 px de caja, 22–28 px de glifo, trazo 1.75.
- Los avisos van con `<Aviso tipo="error|ok|info">`, que ya trae su icono de
  estado. El color nunca es la única señal.
- Los estados vacíos van con `<Vacio icono="…" titulo="…">`: nunca texto
  pelado, que se confunde con una pantalla que no cargó.
- **El icono de la app es otra cosa y vive aparte.** La fuente es
  `mobile/recursos/icono.png`, una sola, y
  `python3 mobile/scripts/icono-app.py` escribe las 26 imágenes que pide
  Android —los tres iconos en cinco densidades y las once pantallas de
  arranque— **y el color de fondo**, que deduce de una esquina del archivo: si
  es opaca el icono trae su propio fondo y ése se usa; si es transparente, va
  blanco. Nunca se editan los PNG de `mipmap-*` a mano.
- `npm run build` falla si aparece un emoji, una forma geométrica haciendo de
  icono o un import suelto de Lucide (`mobile/scripts/sin-emojis.mjs`). Para
  revisar sin compilar: `npm run iconos`.

## Datos en el teléfono

Desde la fase 2 el teléfono trabaja sin señal. Lo que hay que saber antes de
tocar nada de esto:

- Los maestros viven en **IndexedDB** (`mobile/src/idb.js`), no en Preferences.
  Preferences guarda lo de una línea: servidor, token, usuario, preferencias.
- El catálogo se describe **una sola vez**, en
  `app/Services/Softland/Maestros.php`: tabla, clave, mapa de campos y filtro.
  Un maestro nuevo es un arreglo más, no un controlador.
- El **orquestador está en el teléfono** (`mobile/src/sync.js`). El servidor
  sirve páginas por cursor y no recuerda qué bajó quién.
- Lo incremental se mide con el **reloj del servidor**, nunca con el del
  aparato.
- Lo que se escribe sin red va a la **bandeja de salida**
  (`mobile/src/pendientes.js`) y sale solo al volver la señal. La idempotencia
  del alta de clientes va por el RUT, que es la clave en Softland.
- Toda traducción de código a nombre pasa por `mobile/src/catalogos.js`.
  Ninguna pantalla lee un maestro chico por su cuenta.
- Para elegir un código de un maestro largo se usa `Selector.vue`, no un
  `<select>` pelado: 2.009 giros en un desplegable de Android no se navegan.
- Toda cantidad se escribe con `Cantidad.vue`, nunca con un `<input number>`
  pelado: las flechitas nativas no salen en Android, así que pasar de 2 a 3
  obligaba a abrir el teclado y teclear. Menos a la izquierda y más a la
  derecha, no arriba y abajo — así los dos botones caben a 44 px.
- **Cada lista se refresca sola, tirando hacia abajo.** El gesto vive en
  `mobile/src/refresco.js` y el mapa pantalla → maestros en `sync.js`
  (`GRUPOS`); el servidor acota el inventario con `?solo=`. Se baja el maestro
  **completo**, no lo que cambió: Softland no tiene columna que sirva —
  `nwcotiza` sólo guarda `FechaHoraCreacion`, que no se mueve al cambiar de
  estado, y el `FechaUlMod` de `nw_nventa` lo escribe esta app, no el ERP.
  Como es completo, el barrido por sello se entera también de lo borrado.
- **La descarga avisa cuando termina, y sólo corre una.** Arranca sola en el
  login, con el panel ya dibujado contando un almacén vacío: por eso `sync.js`
  sube `corridas` al acabar y quien muestre números leídos de IndexedDB vuelve
  a leerlos ahí. Y pedir una descarga con otra en curso **espera a la que va**
  y recibe su resumen; devolver `null` era decirle «listo» a una pantalla que
  no había bajado nada.
- **La ventana de 12 meses y el alcance por vendedor son cosas distintas.** La
  primera es equipaje y se puede levantar (`Maestros::uno(..., ventana: false)`)
  para buscar un documento viejo por su número; el segundo es permiso y no se
  levanta nunca. Lo que se trae así **no se guarda en IndexedDB**: el panel
  cuenta lo que hay en el almacén.
- **Al volver del segundo plano, `App.vue` sincroniza sola.** Escucha
  `appStateChange` de `@capacitor/app` y dispara la incremental de siempre,
  con un plazo de 5 minutos para no repetirla a cada rato. Un teléfono que
  queda abierto sin cerrarse mientras se trabaja desde el escritorio se pone
  al día sin que nadie toque el botón.
- **Dos búsquedas pueden cruzarse, y sin turno gana la que termina última, no
  la que se pidió última.** Pasa en cualquier campo que combine una carga
  inicial con una búsqueda tecleada — el cliente y el producto de
  `Editor.vue`, las listas de Clientes y Productos —: la carga lenta puede
  resolver después de la búsqueda rápida y pisarla en silencio. El resguardo
  es un número de turno por búsqueda; sólo se pinta la del turno más
  reciente.

## Comandos habituales

```bash
ssh srv                                  # entrar al servidor
bin/deploy.sh                            # desplegar la API a srv
bash mobile/build-apk.sh                 # compilar el APK
bin/publicar-apk.sh                      # y repartirlo desde /app
cd mobile && npm run dev                 # probar la UI en el navegador
```

```bash
ssh srv "cd C:\xampp\htdocs\venta-softland && C:\xampp\php\php.exe artisan ventas:probe"
```

## Escribir en Softland

Desde la fase 3 la app escribe el flujo de venta. Lo que hay que saber antes de
tocar nada de esto:

- **La aritmética está en un solo sitio**: `app/Services/Softland/Totales.php`.
  No es una fórmula elegida, es la de Softland reproducida contra 200
  cotizaciones reales. Cambiar una línea ahí obliga a volver a contrastarla, y
  su copia para el teléfono (`mobile/src/documentos.js`) tiene que cambiar igual.
- **El correlativo se calcula.** `CotNum` y `NVNumero` no son IDENTITY y la base
  no tiene tabla de correlativos. Se toma el máximo bajo `UPDLOCK, HOLDLOCK`
  dentro de la transacción, con reintento ante choque de clave primaria.
- **La idempotencia de los documentos va por `client_uuid`**, no por una clave
  natural: el número lo pone el servidor. El mapa está en
  `ventas.documento_app`.
- **El precio viaja en la moneda del documento y se guarda en la del producto.**
  La conversión ocurre en `Equivalencia.php` y en ningún otro lado.
- **El teléfono no decide impuestos.** Si un producto es afecto a IVA lo dice el
  maestro, en el servidor, aunque el teléfono mande otra cosa.
- **Guardar no es enviar**: el correo al cliente es un camino aparte.
- **`N` es «nula», no «nueva»**, en la cotización y en la nota de venta. Los
  estados son los cuatro que admite el ERP y ningún otro — `TipoDocumento`
  los declara y `mobile/src/documentos.js` los repite con su color.
- **La nota de venta nace aprobada si el ERP no exige aprobación.**
  `nwparam.CheckApruebaNv`: `S` → nace en `P` y espera; `N` → nace en `A`, que
  es lo que escribe el Softland de escritorio (736 de 800, con `nvFeAprob`
  vacío). Lo único que la app superpone es su tope por vendedor: la que lo
  pasa nace en `P` aunque el ERP no lo pida.
- **`A` no significa «cerrada».** Desde que la NV nace ahí, corregirla o
  anularla no se decide por el estado sino por `Ventas::corregibleNotaVenta()`:
  que nadie la haya aprobado (`nvFeAprob` vacío) y que no haya avanzado a
  factura, picking o compra.
- **Un documento sin vendedor no existe para Softland**: no sale en las
  ventanas de búsqueda del ERP. `VenCod` nunca va en nulo; antes de escribir
  uno así, el servidor devuelve 422.
- **Quien crea el documento va en `UsuarioGeneraDocto`**, y `Usuario` se deja
  vacío. Es al revés de lo que parece, y es como lo escribe el ERP.
- **Borrar la nota de venta deshace la conversión.** La cotización vuelve a
  `P`. `V` no es un desenlace suyo: significa «tiene nota de venta», y si la
  nota se va y la cotización se queda en `V`, aparece vendida sin estarlo.
- **Anular y eliminar no son lo mismo.** Anular es `N` y conserva el número;
  eliminar borra la fila y **devuelve el número al pozo**, porque el correlativo
  es `MAX + 1`. Lo entregado al cliente se anula, nunca se borra.
- **El barrido del borrado lo hacen los triggers de Softland.** Sólo hay que
  borrar antes lo que tiene clave foránea `NO_ACTION` — en la cotización,
  seguimientos y adjuntos. Las bitácoras `nw_log*` no se tocan nunca.
- **Un número no identifica un documento para siempre.** El mapa
  `ventas.documento_app` lleva `creado_en`, la misma marca que
  `FechaHoraCreacion`; si no coinciden, la fila está muerta. Sin eso, un
  `client_uuid` viejo devolvía el documento de otra persona.

## El papel que ve el cliente

La cotización y la nota de venta salen en PDF por un **motor de documentos**
(`app/Services/Documentos/`, plantillas en `resources/views/documentos/`). Lo
que hay que saber antes de tocar nada de esto:

- **Hay tres hojas, no una**: `base` para lo genérico, `empresa` para la
  cotización, la nota de venta y la orden de compra —el papel que la empresa
  lleva años entregando— y `dte` para los legales. El tipo dice cuál usa y qué
  bloques lleva; el motor recorre bloques y no sabe de tipos.
- **La orden de compra es un tipo documental, no un formato.** Es la nota de
  venta mirada desde el otro lado: mismo número y mismas líneas, dirigidas al
  proveedor, con el cliente final en «FACTURAR A». Se versiona aparte porque se
  entrega a otra persona. A quién se le pide y qué atributo llena cada hueco del
  papel es configuración (`ventas.config`, clave `orden_compra`).
- **El proveedor se guarda por su código**, no copiando su ficha: se lee de
  `cwtauxi` cada vez. Copiarla es tener dos verdades esperando a diferenciarse.
- **Un tipo documental es un dato, no una plantilla.** `TipoDocumento` declara
  título y bloques; el motor recorre los bloques. Agregar un documento es
  agregar un `case`, no copiar una plantilla.
- **El PDF se dibuja en el servidor, siempre.** El número lo asigna el servidor:
  un documento creado sin señal todavía no lo tiene. El teléfono guarda los
  bytes que le llegaron (`mobile/src/pdf.js`) y los abre sin señal, pero nunca
  dibuja.
- **La oficina comercial y el domicilio tributario son dos campos.** El pie de
  la cotización lleva la primera —a donde va el cliente— y la cabecera de la
  factura la segunda, que tiene que decir lo mismo que el XML que recibió el
  SII. Pisar `direccion` cambia las dos a la vez, y en el documento legal eso es
  un error.
- **La identidad de la empresa hereda de `softland.soempre`** y se corrige en
  `ventas.config`, clave `identidad`. Campo vacío = manda Softland. Nada de
  `if empresa == INNOVAGES` en ninguna parte.
- **El logo se guarda decodificado y vuelto a codificar**, en
  `storage/app/private/identidad`. Comprobar la extensión no protege de nada.
- **Lo entregado al cliente no se toca.** `ventas.documento_emision` guarda cada
  versión; corregir un documento ya enviado crea la siguiente. La huella es del
  HTML, no del PDF: dompdf le estampa la fecha dentro al archivo.
- **Una emisión es de un documento, no de un número.** `documento_emision`
  lleva `creado_en` igual que `documento_app`, y todas las consultas piden las
  dos cosas. Sin eso, borrar un documento entregado y que el correlativo
  reparta su número otra vez le pasaba el historial de entregas al siguiente.
- **La letra no se achica para que quepa.** Si hay sesenta líneas, hay tres
  páginas.
- **`wa.me` sólo transporta texto.** El PDF sale por la hoja de compartir de
  Android, no por un enlace: la dirección del servidor no existe fuera de la
  oficina.

### Los atributos, que los define cada empresa

Softland deja que cada empresa declare campos propios por maestro, **en la base
y no en el código**. La nota de venta es el `IdMaestro = 4`.

- **No hay ningún atributo garantizado.** INNOVAGES declara cuatro, NETDOMAIN
  uno, la siguiente empresa puede no declarar ninguno. Ningún atributo se nombra
  en el código: se dibuja lo que declare la base.
- **El tipo dice dónde vive el valor**: 4 lista (`...TVAtrT`), 3 fecha
  (`...TVAtrF`), 1 y 2 número y sí/no (`...TVAtrV`). La vista
  `ventas.nv_atributo_valor` las une para servirlas como un maestro.
- **Escribir es borrar y volver a poner** —la clave primaria admite dos valores
  por atributo y Softland nunca lo usa así—, pero **comprobando antes de
  borrar**: una opción inválida no puede llevarse por delante la que había.
- **Borrar la nota de venta no requiere limpiarlos**: lo hacen tres triggers del
  ERP.

### El papel del documento legal

La factura, la boleta y la nota de crédito salen por el mismo motor, en su
propia hoja (`documentos/dte.blade.php`). Lo que hay que saber:

- **El papel no es el documento.** El documento es el XML firmado que aceptó el
  SII; esto es lo que se le entrega al cliente para que lo lea.
- **El timbre sale de `dte_doccab.FirmaDTE` y no se regenera nunca.** Lleva
  dentro la hora en que se timbró: uno nuevo sería válido y **distinto**, y un
  papel que no dice lo mismo que el XML no cuadra. Se comprobó decodificando el
  PDF417 de nuestro PDF: mismos 777 bytes que el de Softland.
- **Carta, no A4.** El tamaño es un dato del tipo documental, como los bloques.
- **La rejilla es de 27 renglones y se dibuja entera**, vacíos incluidos: en un
  documento tributario el renglón vacío con su raya dice «aquí no se añadió nada
  después».
- **El pie no lleva paginado**: ese sitio es del acuse de recibo de la ley
  19.983, que es texto de ley. El «Página 2 de 2» va bajo el recuadro del folio.

## El seguimiento: lo que se quedó de hacer

- **Un compromiso es una promesa: un verbo y una fecha.** No dice cuán cerca
  está el cierre. Mezclarlo con eso —que es lo que pasa cuando el maestro
  `nwttcomp` se llena de porcentajes— hace que una venta al 90 % «retroceda» al
  acordar una llamada. El compromiso vive en Softland; **el avance**, que es el
  otro eje, en `ventas.cotizacion_avance`.
- **La app no interpreta los códigos de `nwttcomp`**: los lee y los muestra.
  Cuáles se ofrecen lo dice `ventas.config`, y los viejos no se borran nunca —
  `nwtsegui` tiene clave foránea al maestro.
- **No hay columna de «cumplido».** El compromiso vivo de una cotización es el
  de su **última** anotación, y anotar la siguiente cierra la anterior. Una
  anotación sin próxima fecha la deja sin compromiso, que es el «ya está hecho».
- **La cotización nace con su compromiso**, en la misma transacción: la primera
  anotación la deduce la app y la segunda la promete el vendedor. Separarlas
  dejaría cotizaciones sin próximo paso, que es lo que esto vino a evitar.
- **Ninguna fecha viene marcada por omisión.** Un campo obligatorio siempre
  relleno es un campo que nadie lee.
- **El avance no se pisa, se agrega.** Una fila por cambio: sin historia no se
  puede saber cuáles llevan semanas sin moverse, que son las que hay que mirar.
- **Al calendario se va por una intención de Android**, no escribiendo en él:
  sin permisos, sin dependencias y funcionando sin señal.

## El panel de control

El panel comercial se está rediseñando. Lo que hay que saber antes de tocar
nada de esto está en `docs/panel-comercial.md`: qué KPI tienen fuente real, qué
se calcula en el teléfono y qué necesita servidor, y las once inconsistencias
de Softland que cambian las fórmulas. Tres que se olvidan:

- **No hay metas en Softland.** Ninguna tabla. La meta es un dato de la app, y
  sin fila de meta el widget no aparece — no hay meta por defecto.
- **Aquí se factura por suscripción**: una nota de venta genera varias facturas
  a lo largo de meses. No se calcula «conversión NV → factura» en documentos, y
  el embudo enseña lo facturado del período **sin flecha** desde lo vendido: la
  flecha invita a restar, y esa resta no mide nada.
- **El formato del dinero no toca el cálculo.** `mobile/src/dinero.js` recibe
  un número y devuelve un texto. `npm run pruebas` lo comprueba.
- **Venta es la nota de venta aprobada o concluida.** La pendiente está escrita
  y sin autorizar: no entra en el KPI, ni en el embudo, ni en el ticket, ni en
  el tiempo de cierre. Sí entra en la **conversión**, porque la pregunta ahí es
  «¿llegó a nota de venta?» y la cotización ya quedó en `V`.
- **La regla se escribe una vez.** `situacion()` decide si una cotización está
  por vencer, y la usan el panel para contar y la lista para filtrar. Dos
  copias de la misma regla es un panel que dice «6» y una lista que muestra 7.
- **El color dice si la noticia es buena; la flecha, hacia dónde se movió el
  número.** No son lo mismo: el tiempo de cierre que baja es una flecha hacia
  abajo y una buena noticia. Esa lectura la pone la pantalla (`tono()`), no el
  icono.

## El ciclo normal de venta

Desde la fase 4.5 la app lleva la cuenta de lo que queda. El plan y la evidencia
están en `docs/ciclo-normal.md`. Lo que hay que saber antes de tocar nada:

- **El saldo se calcula, no se guarda.** Es una resta sobre los documentos que
  existen ahora: borrar, anular o corregir lo cambian solos. Las siete columnas
  de avance de `nw_detnv` —`nvCantFact` y compañía— **están muertas** y se dejan
  en cero, como las deja el ERP.
- **Sólo cuentan los documentos vivos.** Anular devuelve el saldo igual que
  borrar. Los estados `P`, `A` y `C` consumen; `N` no.
- **Dos saltos, dos enlaces, y sólo uno es nuestro.** Cotización → nota de venta
  va en `ventas.linea_origen`; nota de venta → factura ya lo tiene Softland en
  `iw_gmovi.nvCorrela` → `nw_detnv.nvLinea`, y usar el suyo hace que el saldo
  salga bien también cuando factura el Softland de escritorio.
- **Sin enlace de línea, el saldo no se sabe, y se dice.** Una cotización
  convertida antes de la app que apareciera con «le queda todo» se convertiría
  dos veces.
- **La nota de crédito dice qué acredita en `IW_GSaEn_RefDTE`**, no en
  `AuxDocNum`, y su cantidad viene en negativo.
- **El vendedor de una factura es el de la venta, no el de quien la emite.** La
  factura lo hereda de su nota de venta y la nota de crédito de la factura que
  anula —181 de 204 y 12 de 12 en INNOVAGES lo confirman—, y se sobrescribe, no
  se rellena. Escribir ahí el código de quien opera dejaba a facturación y a
  administración sin poder emitir nada, que son justamente quienes facturan, y
  permitía que un vendedor se quedara con la venta de otro. Sólo la factura sin
  nota de venta detrás lo pregunta: ahí no hay de dónde heredarlo.
- **A quién se le factura una nota de venta lo deciden dos permisos, y se
  cumplen los dos.** Softland lo concede por usuario —`IW · Iw_FacLin ·
  NVOtroAuxiliar`, que en INNOVAGES trae `IW/001` y no `IW/vend`— y la empresa
  puede apagarlo para todos con `receptor_editable` en `ventas.config`. **Nuestra
  llave sólo apaga**, nunca enciende lo que el ERP negó. La regla se comprueba en
  `Facturacion`, no en el controlador: una pantalla nueva que no supiera de ella
  escribiría facturas al cliente equivocado, y eso se corrige con nota de
  crédito, no con un `UPDATE`.
- **La factura de comisión se emite desde la misma pantalla que la normal**, y
  lo que decide cuál es **el receptor**: al cambiarlo, las líneas de la nota de
  venta dejan de servir y se escriben a mano. Facturarle al mandante los
  productos que compró el cliente final es un documento mal emitido. Queda
  **enlazada a la nota de venta** (`iw_gsaen.nvnumero`) y **no le consume
  saldo**: sus líneas no llevan `nv_linea`, porque una comisión no factura nada
  de lo vendido.
- **Los permisos de Softland se preguntan en `Permisos.php`, y son concesiones.**
  La fila existe = puede. Se conceden en dos sitios que **se suman** —por perfil
  (`wisrestperfil` vía `wisperfilusuario`) y por usuario (`wisrestusuario`)—, y un
  usuario puede tener varios perfiles del mismo sistema, así que preguntar por uno
  solo da que no a quien sí puede.
- **La Liquidación-Factura (DTE 43) no se implementa aquí.** Softland trae el
  ciclo del mandante como documento propio, con sus formularios, sus cuentas en
  `iwparam` y su tabla `iw_gsaen_comislf`; INNOVAGES no lo tiene configurado y no
  ha emitido ninguno. Lo suyo es una factura 33 a Softland Ingeniería con una
  línea de comisión. Está todo medido en `docs/ciclo-normal.md`.
- **La app sólo emite notas de crédito de anulación completa.** Las líneas las
  arma el servidor desde la factura, no el teléfono, y `devuelveTodo()`
  comprueba línea a línea que la devuelvan entera antes de pedir folio. El SII
  distingue `CodRef 1` —anula— de `2` y `3`, que corrigen texto y montos;
  devolver una parte es otro documento y no se emite desde aquí.
- **La glosa de la referencia va en la columna `Glosa`**, no en la que se llama
  `RazonRef`: el `RazonRef` del DTE sale de la primera, y la segunda está vacía
  en los 209 documentos reales.
- **Las referencias son varias y `AuxDocNum` no es la primera.** Es la primera
  que nombra un documento nuestro: con la orden de compra delante, la primera
  puede ser un papel que no existe en `iw_gsaen`, y dejarla ahí diría que esta
  factura corrige una factura número `U36401`. El `FolioRef` es **texto** y la
  fecha es la del documento referido, no la de hoy.
- **Contar folios no es contar los que no están usados.** El repartidor de
  Softland va hacia adelante y no rellena huecos: hay 33 folios de rangos viejos
  que no va a entregar jamás. Se cuenta avanzando desde el último usado dentro
  de algún CAF.
- **La cotización no estrena letra.** «Convertida a medias» es una lectura de la
  app, no un quinto `CtEstado`. Está en `V` mientras le quede una nota de venta
  viva; si no queda ninguna, vuelve a `P`.
- **Facturar la venta y facturar una comisión son dos documentos, y se elige
  cuál.** No se deduce del receptor: los mismos productos facturados a otro RUT
  siguen siendo la venta —quien paga no siempre es quien recibe— y **descuentan
  saldo**; la comisión lleva líneas escritas a mano sin `nv_linea` y no lo toca.
  Deducirlo hacía imposible el primer caso, que es corriente.
- **La factura hereda lo que describe la venta**, se le facture a quien se le
  facture: condición de pago, bodega, centro de costo, observación y orden de
  compra. Cambiar el pagador no cambia qué se vendió.
- **La orden de compra del cliente va al DTE como referencia 801, no 802.**
  `DTE_SiiTDocRef` declara 801 «Orden de Compra» y 802 «Nota de Pedido», y en
  INNOVAGES las 188 referencias 802 llevan el número de la nota de venta y las 6
  con 801 la orden de compra. Van **las dos a la vez**, como en la factura 232, y
  cada una se apaga por empresa. **`iw_gsaen.Orden` no es el sitio** aunque se
  llame así: es un `int`, está en `'0'` en las 200 facturas y no podría guardar
  `272-OC00008216`.

## La factura electrónica

Desde la fase 4 la app emite el DTE. Lo que hay que saber antes de tocar nada de
esto está en `docs/dte.md`. Cuatro cosas que se olvidan:

- **Emitir y enviar son un solo acto.** La app escribe el documento y lo manda
  al SII en la misma petición: separarlos hacía que la factura del día 30 se
  emitiera el 2, en otro mes tributario, porque dependía de que alguien se
  acordara. Quien puede emitir, manda; el permiso que tiene sentido es «quién
  puede emitir». Y **enviado no es aceptado**: lo primero es que viajó, lo
  segundo que el SII lo miró, y el veredicto tarda minutos, así que consultarlo
  es una acción aparte — la hace `dte:pendientes`, que también reintenta lo que
  no salió.
- **Emitir manda al SII, salvo que la empresa diga que no.** La llave
  `envio_automatico` de `ventas.config` lo decide, y nace encendida. Es **una
  llave nuestra**: las de `soempre` describen cómo manda el Softland de
  escritorio, hoy dirían que no mandáramos nunca y su significado se deduce. Se
  obedece al ERP donde manda sobre el documento —`nwparam.CheckApruebaNv`— y se
  decide aquí lo que es comportamiento de esta app.
- **Una factura que no viajó al SII se borra, y el folio vuelve.** No es anular:
  anular conserva folio e historia y es lo que se hace con lo entregado. Los
  triggers barren líneas y referencias; **la fila de reserva del folio hay que
  borrarla a mano** —nunca queda enlazada, porque el repartidor la deja con
  `Tipo` nulo y quien la enlaza es un trigger de UPDATE—, y sin eso el folio no
  vuelve. Borrable es sólo lo que escribió la app, y eso lo dice
  `iw_gsaen.Proceso = 'Venta Softland'`.
- **El teléfono nunca elige un folio.** El folio lo reparte Softland dentro del
  servidor y el timbre se firma con una llave que no sale de la base: un
  teléfono sin señal no puede fabricar una factura ni reservarle un número. Lo
  que queda en la bandeja de salida es una **intención**, sin folio y sin
  timbre, y se emite al llegar al servidor — en orden de llegada, no de
  escritura. Por eso dos teléfonos offline no pueden chocar.
- **El timbre se guarda antes de enviar.** Lleva dentro la hora en que se
  timbró, y el código de barras del papel tiene que decir exactamente lo mismo
  que el XML que recibió el SII. Se genera una vez (`Emision::preparar`), se
  guarda, y se reusa para reintentar y para imprimir. Regenerarlo da otro
  timbre.
- **Se emite sola cuando el mundo sigue igual; se pregunta cuando cambió.** Una
  factura que esperaba en la bandeja y llega cuando su nota de venta ya se
  facturó entera vuelve preguntando, no se emite a ciegas. Facturar de más sigue
  permitido: lo que se pregunta es facturar lo que ya no queda.
- **La app llega hasta inventario y facturación, con el DTE emitido, y para
  ahí.** La centralización —contabilidad, registro de ventas, cuenta corriente
  del cliente— es un procedimiento aparte que se corre desde Softland, y no
  ocurre al emitir. Ninguna columna `Cpb*` ni `Contab*` se escribe aquí.
- **La factura no es la nota de venta.** De 192 facturas enlazadas a una NV,
  **190 van a otro cliente** —188 a Softland Ingeniería— con una sola línea de
  comisión, y `nvCantFact` está en cero en todas las notas de venta. Es un
  negocio de distribuidor. `Facturacion` escribe el documento que se le pida; la
  NV entra como referencia y como sugerencia, no como fuente obligatoria, y el
  receptor se puede cambiar. El monto de la comisión no se calcula: lo escribe
  quien factura.
- **El timbre se firma en bytes, no en árboles.** Por eso `Timbre` concatena el
  XML en vez de usar `DOMDocument`: la firma cubre el `<DD>` tal como está
  escrito. Todo va en **ISO-8859-1**, el `<CAF>` se incrusta **pegado** —en
  `dte_siicaf` está guardado con espacios y en el DTE va sin ellos— y el `MNT`
  va **sin signo**, aunque el total de una nota de crédito sea negativo en
  `iw_gsaen`.
- **La firma del documento y la del sobre no se canonicalizan igual.** La forma
  canónica arrastra los espacios de nombres heredados: el **documento** se firma
  suelto y el **sobre**, con los de `<EnvioDTE>`. Suena contradictorio y no lo
  es: el SII saca cada `<DTE>` del sobre y lo valida por separado. Firmar el
  sobre sin su ámbito lo rechaza entero, con los documentos dentro.
- **En el sobre hay tres RUT y ninguno es el del cliente**: la empresa que
  factura, la persona cuyo certificado firma —que sale de un OID del
  `subjectAltName` que PHP no sabe leer— y el SII, que es siempre `60803000-K`.
- **El folio lo reparte Softland**, con `DTE_pdblEntregaFolioDTE`. No se calcula
  por nuestra cuenta: así no se le disputa el número al ERP. Y un folio gastado
  no se devuelve.
- **La firma cubre la forma canónica del XML, no su texto.** De ahí que el
  generador concatene en vez de serializar, que escriba un elemento por línea
  con `\r\n`, que incluya un comentario de versión —los comentarios no se
  firman, pero los saltos que los rodean sí— y que todo vaya en **ISO-8859-1**.
- **La boleta no viaja por donde la factura.** Factura y nota de crédito van por
  SOAP a `palena`; la boleta va por la API REST `api.sii.cl/recursos/v1` y
  además exige reporte diario de consumo de folios. Son dos integraciones. Hoy
  está preparada y probada, pero **bloqueada**: INNOVAGES no tiene folios CAF de
  boleta, y los de NETDOMAIN son de otro RUT.

La llave privada del CAF **no sale de la base**: `Caf::firmar()` es lo único que
se expone. El certificado digital va en `storage/app/private/`, fuera de git, y
su clave en el `.env`.

Para comprobar sin emitir ni gastar un folio:

```bash
ssh srv "cd C:\xampp\htdocs\venta-softland && C:\xampp\php\php.exe artisan dte:verifica-timbre --todos"
```

Y para comprobar el camino al SII entero —conexión, certificado, firma y
autorización— sin emitir ni gastar un folio:

```bash
ssh srv "cd C:\xampp\htdocs\venta-softland && C:\xampp\php\php.exe artisan dte:token"
```

## El alta de clientes desde el SII

Desde la 0.40.0 el alta de un cliente se llena con lo que el SII publica: se
escribe el RUT, el servidor consulta la API y el vendedor completa y corrige. El
plan y las mediciones están en `docs/alta-clientes-sii.md`. Lo que hay que saber
antes de tocar nada:

- **El teléfono no llama a la API del SII.** La llama el servidor. La llave no
  puede ir en el APK, que se descompila, y además el servidor es el único que
  puede traducir: es quien tiene `cwtgiro`, `cwtcomu` y `cwtciud`.
- **Toda traducción vive en `app/Services/Sii/Traduccion.php`**, y sólo ahí.
- **Hay dos listas de ACTECO y la que trae Softland es la vieja.** 696 de los
  698 códigos de `sii_tacteco` figuran como `ActEcoAntigua`. Comparten números
  con significados distintos: `702000` es «Corredores de propiedades» en la
  antigua y «Actividades de consultoría de gestión» en la nueva. **`sii_tacteco`
  no se consulta nunca.** El catálogo vigente son 674 códigos y está en
  `resources/sii/actecos.tsv`. Están **cargados en `cwtgiro`** desde la 0.37.0,
  con `ventas:carga-giros`, que no escribe sin `--escribir` y **no toca una fila
  que ya exista**: un giro en uso lleva el texto que sus clientes reconocen y
  que sale impreso en el `GiroRecep` de sus DTE.
- **El acteco es texto, no número.** 94 empiezan por cero; como entero,
  `011101` se convierte en `11101`, que existe y es otra cosa, y acabaría
  impreso en el `GiroRecep` del DTE.
- **El giro se traduce por código, nunca por texto.** El padrón reescribe
  descripciones sin tocar el código: una búsqueda por texto que deja de
  encontrar no falla, **acierta otra cosa**.
- **Ante dos comunas con el mismo nombre gana la del código del INE.**
  `cwtcomu` tiene ocho filas puestas a mano con el nombre mal escrito, y
  Estación Central está dos veces. Sin esa regla los clientes nuevos se reparten
  entre la comuna buena y su duplicado.
- **Lo que el SII recorta en origen no tiene arreglo.** Guarda `CIUDAD` en 15
  caracteres y hay direcciones cortadas de fábrica. Por eso lo que trae se
  **propone** y lo confirma una persona, y la pantalla **enseña el recorte** en
  vez de hacerlo callada.
- **La búsqueda nunca es requisito para dar de alta.** Sin señal el botón sale
  apagado y el formulario se llena a mano; la bandeja de salida sigue con el RUT
  como clave. El botón **no se dibuja** si el servidor no sabe
  consultar (`sii_disponible` en el arranque): ofrecerlo y que falle es peor.
- **El sello «del SII» se cae solo.** No se vigila nada: `deSii()` compara lo
  escrito con lo propuesto, así que tocar el campo quita el sello. Y tocar el
  RUT tira la propuesta entera — los datos de otra empresa no vienen del SII.
- **No se actualiza en masa lo que ya existe.** El SII da el domicilio
  tributario y la ficha de un cliente antiguo puede llevar la oficina comercial
  puesta a propósito. Es la misma trampa de la identidad de la empresa, un nivel
  más abajo. Se actualiza **de una en una**, comparando: lo que
  está vacío viene marcado y lo que pisa, no. Una propuesta que viene aceptada
  es una propuesta que nadie lee.
- **La URL lleva la llave dentro, así que no puede acabar en un registro.**
  `Auxiliar` **nunca** llama a `$respuesta->throw()`: la excepción HTTP de
  Laravel trae la URL en el mensaje y ese mensaje va a `laravel.log`. Los
  estados se miran a mano y del `catch` sale un mensaje limpio.
- **La caché guarda la respuesta cruda, no la ficha traducida.** Así la
  traducción se rehace con las reglas de hoy, y un giro nuevo o un alias añadido
  después mejoran también lo que ya estaba guardado.
- **Todo RUT que cruce una frontera va entero, con dígito verificador.**
  `Rut::cuerpo()` no puede ser idempotente: «76469596» es a la vez el cuerpo de
  76.469.596-8 y el RUT 7.646.959-6 completo. Un RUT ya reducido que se vuelva a
  reducir contesta por otra empresa **sin que nada falle**.
- **`FacturacionMIPYME@sii.cl` no es basura**: es el correo de intercambio de
  quien factura por el portal gratuito del SII, y es el correcto. Son el 78,4 %
  de los facturadores electrónicos del país. No se filtra.

## Repartir la app

Desde la 0.42.0 el servidor entrega su propio instalable, en `/app` — la
segunda y última página HTML del proyecto, por la misma razón que `/setup`:
instalar la app pasa antes de que la app exista en el teléfono.

- **La dirección no lleva la versión dentro.** `/app/apk` entrega siempre el
  último publicado, para que el código QR impreso no caduque. La versión que se
  ofrece sale del **nombre del archivo que existe** (`Reparto\Apk`), no de
  `VERSION`: el servidor se despliega y el APK se sube aparte, y entre una cosa
  y la otra pasan minutos.
- **Detrás del proxy, ninguna dirección que escriba el servidor sirve.**
  `url()` genera `http://venta.netdomain.cl/venta-softland`: esquema y carpeta
  equivocados. Quien sabe la buena es el navegador, y se la pasa a `qr.svg`;
  esa dirección se comprueba contra el `Host` de la petición, que es lo que
  impide que sea un generador de códigos QR abierto a internet.
- **El APK vive fuera de `public/` y fuera del tar de `deploy.sh`**, en
  `storage/app/private/apk`. Lo primero para que la entrega pase por una ruta
  nuestra, que sabe cuál es el último y le pone el tipo MIME que Android
  necesita —con `application/octet-stream` Android guarda un archivo y no
  ofrece instalar—; lo segundo para que desplegar no se lleve por delante lo
  publicado. Se sube con `bin/publicar-apk.sh`.
- **`/app` es público, a propósito.** El instalable no lleva credenciales
  dentro y la dirección del servidor se escribe al abrirlo por primera vez;
  exigir sesión haría imposible el caso que esto resuelve, que es el vendedor
  nuevo que todavía no tiene cuenta.
- **Distinta no es más nueva.** Cuenta ofrece «Actualizar la app» sólo cuando
  lo publicado es **posterior** a lo que tiene el teléfono (`version.js`): un
  APK nuevo contra una API vieja también desfasa, y ahí lo que falta es
  desplegar, no descargar.

## La versión

Vive en **un solo archivo**, `VERSION`, en la raíz. De ahí la leen la SPA
(`vite.config.js`), el APK (`build.gradle`, que además calcula el `versionCode`)
y la API (`config/app.php`). En Cuenta salen las dos, la del teléfono y la del
servidor, y la pantalla avisa si no coinciden: un APK nuevo contra una API
vieja explica la mitad de los «a mí no me funciona».

Se sube con `bin/version.sh mayor|menor|parche "Título"`, que deja la entrada
abierta en `docs/versiones.md`. **Toda tarea significativa sube la versión**,
igual que actualiza `STATE.md`.

## Estado actual
Versión **0.42.0**. Fases 1, 2 y 3 terminadas, más el motor de documentos, el
panel comercial hasta el paso 4 y la fase 4 hasta el paso 3b: el timbre
comprobado contra 615 documentos emitidos, la escritura en inventario
contrastada columna por columna contra 199, el XML del DTE regenerado y firmado
idéntico al de los 209 que el SII ya aceptó, y el sobre reproducido igual en los
210 envíos guardados. Contra palena, en producción, el SII ya devuelve token y
contesta las consultas de estado. **Falta el primer envío de verdad**, que es lo
único que no se deshace. Ver `STATE.md`. El mapa de
tablas del flujo de ventas está en `docs/flujo-ventas-softland.md`, el motor de
documentos en `docs/motor-documentos.md`, la auditoría del panel comercial en
`docs/panel-comercial.md`, la emisión de DTE en `docs/dte.md`, el alta de clientes
desde el SII en `docs/alta-clientes-sii.md`, el historial de
versiones en `docs/versiones.md` y el plan por fases en `docs/roadmap.md`.
