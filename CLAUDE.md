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
Desde la fase 2 las pestañas son cuatro — **Panel · Clientes · Avisos ·
Cuenta** — y ahí se cierra la lista: con la activa desplegada, en 360 px no
cabe una quinta sin bajar de los 44 px de área pulsable.

### Tamaño de la interfaz

Todo lo que crece o se achica cuelga de la variable CSS `--d`, que escribe
`mobile/src/densidad.js` y elige el vendedor en Cuenta (compacta · normal ·
amplia). Los tamaños base están calibrados para `--d: 1` sobre 360 px de ancho.

Tres cosas **no se escalan nunca**: los 44 px de área pulsable, los 16 px de
los campos de formulario (bajo eso Android hace zoom al enfocar) y el texto de
12 px o menos, que ya está en el piso de lectura. Quien quiera un panel más
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

## Comandos habituales

```bash
ssh srv                                  # entrar al servidor
bin/deploy.sh                            # desplegar la API a srv
bash mobile/build-apk.sh                 # compilar el APK
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

- **Un tipo documental es un dato, no una plantilla.** `TipoDocumento` declara
  título y bloques; el motor recorre los bloques. Agregar un documento es
  agregar un `case`, no copiar una plantilla.
- **El PDF se dibuja en el servidor, siempre.** El número lo asigna el servidor:
  un documento creado sin señal todavía no lo tiene. El teléfono guarda los
  bytes que le llegaron (`mobile/src/pdf.js`) y los abre sin señal, pero nunca
  dibuja.
- **La identidad de la empresa hereda de `softland.soempre`** y se corrige en
  `ventas.config`, clave `identidad`. Campo vacío = manda Softland. Nada de
  `if empresa == INNOVAGES` en ninguna parte.
- **El logo se guarda decodificado y vuelto a codificar**, en
  `storage/app/private/identidad`. Comprobar la extensión no protege de nada.
- **Lo entregado al cliente no se toca.** `ventas.documento_emision` guarda cada
  versión; corregir un documento ya enviado crea la siguiente. La huella es del
  HTML, no del PDF: dompdf le estampa la fecha dentro al archivo.
- **La letra no se achica para que quepa.** Si hay sesenta líneas, hay tres
  páginas.
- **`wa.me` sólo transporta texto.** El PDF sale por la hoja de compartir de
  Android, no por un enlace: la dirección del servidor no existe fuera de la
  oficina.

## El panel de control

El panel comercial se está rediseñando. Lo que hay que saber antes de tocar
nada de esto está en `docs/panel-comercial.md`: qué KPI tienen fuente real, qué
se calcula en el teléfono y qué necesita servidor, y las once inconsistencias
de Softland que cambian las fórmulas. Tres que se olvidan:

- **No hay metas en Softland.** Ninguna tabla. La meta es un dato de la app, y
  sin fila de meta el widget no aparece — no hay meta por defecto.
- **Aquí se factura por suscripción**: una nota de venta genera varias facturas
  a lo largo de meses. No se calcula «conversión NV → factura» en documentos.
- **El formato del dinero no toca el cálculo.** `mobile/src/dinero.js` recibe
  un número y devuelve un texto. `npm run pruebas` lo comprueba.
- **La regla se escribe una vez.** `situacion()` decide si una cotización está
  por vencer, y la usan el panel para contar y la lista para filtrar. Dos
  copias de la misma regla es un panel que dice «6» y una lista que muestra 7.
- **El color dice si la noticia es buena; la flecha, hacia dónde se movió el
  número.** No son lo mismo: el tiempo de cierre que baja es una flecha hacia
  abajo y una buena noticia. Esa lectura la pone la pantalla (`tono()`), no el
  icono.

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
Versión **0.5.0**. Fases 1, 2 y 3 terminadas, más el motor de documentos y el
panel comercial hasta el paso 4. Ver `STATE.md`. El mapa de tablas del flujo de
ventas está en `docs/flujo-ventas-softland.md`, el motor de documentos en
`docs/motor-documentos.md`, la auditoría del panel comercial en
`docs/panel-comercial.md`, el historial de versiones en `docs/versiones.md` y
el plan por fases en `docs/roadmap.md`.
