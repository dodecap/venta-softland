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
- **El icono de la app es otra cosa y vive aparte.** La fuente es
  `mobile/recursos/icono.png`, una sola, y
  `python3 mobile/scripts/icono-app.py` escribe las 26 imágenes que pide
  Android —los tres iconos en cinco densidades y las once pantallas de
  arranque—. Nunca se editan los PNG de `mipmap-*` a mano.
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
- **Una emisión es de un documento, no de un número.** `documento_emision`
  lleva `creado_en` igual que `documento_app`, y todas las consultas piden las
  dos cosas. Sin eso, borrar un documento entregado y que el correlativo
  reparta su número otra vez le pasaba el historial de entregas al siguiente.
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
- **A quién se le factura una nota de venta es configuración, no código.** Por
  omisión el receptor se hereda de la nota de venta; cambiarlo exige encender la
  llave `receptor_editable` en `ventas.config`, y eso es lo que habilita el ciclo
  de distribuidor de INNOVAGES. La regla se comprueba en `Facturacion`, no en el
  controlador: una pantalla nueva que no supiera de ella escribiría facturas al
  cliente equivocado, y eso se corrige con nota de crédito, no con un `UPDATE`.
- **La app sólo emite notas de crédito de anulación completa.** Las líneas las
  arma el servidor desde la factura, no el teléfono, y `devuelveTodo()`
  comprueba línea a línea que la devuelvan entera antes de pedir folio. El SII
  distingue `CodRef 1` —anula— de `2` y `3`, que corrigen texto y montos;
  devolver una parte es otro documento y no se emite desde aquí.
- **La glosa de la referencia va en la columna `Glosa`**, no en la que se llama
  `RazonRef`: el `RazonRef` del DTE sale de la primera, y la segunda está vacía
  en los 209 documentos reales.
- **Contar folios no es contar los que no están usados.** El repartidor de
  Softland va hacia adelante y no rellena huecos: hay 33 folios de rangos viejos
  que no va a entregar jamás. Se cuenta avanzando desde el último usado dentro
  de algún CAF.
- **La cotización no estrena letra.** «Convertida a medias» es una lectura de la
  app, no un quinto `CtEstado`. Está en `V` mientras le quede una nota de venta
  viva; si no queda ninguna, vuelve a `P`.

## La factura electrónica

Desde la fase 4 la app emite el DTE. Lo que hay que saber antes de tocar nada de
esto está en `docs/dte.md`. Cuatro cosas que se olvidan:

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
Versión **0.21.1**. Fases 1, 2 y 3 terminadas, más el motor de documentos, el
panel comercial hasta el paso 4 y la fase 4 hasta el paso 3b: el timbre
comprobado contra 615 documentos emitidos, la escritura en inventario
contrastada columna por columna contra 199, el XML del DTE regenerado y firmado
idéntico al de los 209 que el SII ya aceptó, y el sobre reproducido igual en los
210 envíos guardados. Contra palena, en producción, el SII ya devuelve token y
contesta las consultas de estado. **Falta el primer envío de verdad**, que es lo
único que no se deshace. Ver `STATE.md`. El mapa de
tablas del flujo de ventas está en `docs/flujo-ventas-softland.md`, el motor de
documentos en `docs/motor-documentos.md`, la auditoría del panel comercial en
`docs/panel-comercial.md`, la emisión de DTE en `docs/dte.md`, el historial de
versiones en `docs/versiones.md` y el plan por fases en `docs/roadmap.md`.
