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

### 0.16.0 — Facturar parte de la nota de venta, con el precio heredado
*2026-09-16*

- **Paso 3 de la fase 4.5.** La factura hereda de la nota de venta el producto,
  el precio, el factor de conversión y el descuento de línea. **Se sobrescriben,
  no se rellenan si faltan**: si el teléfono manda otro precio, gana la nota de
  venta. Lo que sí elige quien factura es la cantidad y qué líneas agrega.
- **`PreUniMB` va en la moneda del documento, no en la del producto.** De ahí
  sale el `PrcItem` del DTE, y el SII comprueba que `PrcItem × QtyItem` cuadre
  con `MontoItem`, que va en pesos. Con un producto en UF, el precio sin
  convertir producía un documento que no cuadra consigo mismo. No cambia nada en
  los 199 contrastados: todos tienen equivalencia 1.
- **Dos rechazos antes de gastar folio**: una línea que dice venir de una nota de
  venta sin decir de cuál, y una que cita una línea que no existe.
- `Facturacion::propuesta()`: lo que queda por facturar, listo para precargar.
- `ventas:verifica-facturacion` recorre el ciclo dentro de una transacción que se
  deshace: doce unidades, factura de cinco, saldo siete, anular y vuelven las
  doce. Ni el folio se gasta.

### 0.15.0 — Convertir parte de la cotización, y que el saldo vuelva al anular
*2026-09-16*

- **Paso 2 de la fase 4.5.** La conversión acepta **líneas y cantidades
  sueltas**: cada línea de la nota de venta puede decir de qué línea de la
  cotización sale y cuánto se lleva. Eso se guarda en `ventas.linea_origen`.
- **`V` ya no cierra la puerta.** Una cotización convertida a medias se puede
  volver a convertir; lo que la cierra es que no quede saldo. Y cuando no queda,
  el 409 nombra todas sus notas de venta, no una.
- **Anular devuelve el saldo igual que borrar.** La nota de venta anulada deja
  de consumir, y si no queda ninguna viva la cotización vuelve a `P`. Antes
  `devolverCotizacion` miraba si existía *alguna* nota de venta, contando las
  anuladas: con reparto parcial eso dejaba cotizaciones vendidas sin estarlo.
- **Corregir una nota de venta rehace sus enlaces**, junto con el detalle. Dejar
  los viejos era un saldo que ya no correspondía a ninguna línea existente.
- `GET /cotizaciones/{n}/saldo`: qué queda por convertir, para precargar el
  editor y para la marca de parcial.
- `ventas:verifica-conversion` recorre el ciclo entero contra Softland **dentro
  de una transacción que se deshace**: no quedan documentos ni números gastados.

### 0.14.0 — El saldo: cuánto queda de una cotización y de una nota de venta
*2026-09-16*

- **Paso 1 de la fase 4.5.** `ventas.linea_origen` —el único enlace del ciclo
  que hay que guardar por nuestra cuenta— y el servicio `Saldo`, que responde
  cuánto queda de una línea de cotización por convertir y de una línea de nota
  de venta por facturar.
- **El saldo se calcula, no se guarda**: es una resta sobre los documentos que
  existen ahora. Borrar, anular o corregir lo cambian solos.
- **La nota de crédito dice qué acredita en `IW_GSaEn_RefDTE`**, no en
  `AuxDocNum`. Ahí coincidía en INNOVAGES por casualidad, pero 5.317 facturas de
  NETDOMAIN también lo llevan relleno: cruzar por ahí emparejaba notas de
  crédito con notas de venta que no tenían nada que ver.
- **La línea de la nota de crédito viene en negativo** y hay que devolverla en
  positivo. Sumarla tal cual daba saldo -1 en una línea pedida 1 y facturada 1.
- **Sin enlace de línea el servicio dice que no lo sabe**, en vez de inventar
  saldo. Las 1.459 cotizaciones convertidas de la historia lo dicen.
- `ventas:verifica-saldo` contrasta el cálculo contra las dos empresas.

### 0.13.0 — Mandar el DTE al SII: sobre, token y seguimiento
*2026-09-15*

- **El sobre `<EnvioDTE>`** (`Sobre`): carátula con los tres RUT que el SII
  distingue —la empresa que factura, la persona que firma y el propio SII— y
  segunda firma sobre el `<SetDTE>`.
- **El transporte** (`Sii`): semilla, token, subida del sobre y las dos
  consultas de estado, la del envío y la del documento. SOAP a mano, porque la
  extensión no está en el servidor y estos servicios son de sobre plano.
- **La emisión** (`Emision`): arma, manda y recién entonces deja constancia. Si
  el envío ocurre y la constancia falla, el error lleva el `TrackID` delante.
- **Hallazgo que habría hecho rechazar todos los envíos**: la forma canónica de
  un elemento arrastra los espacios de nombres que hereda. El **documento** se
  firma suelto; el **sobre**, con los de `<EnvioDTE>`. Son distintos y los dos
  hacen falta.
- **El RUT de quien firma** sale del `subjectAltName` del certificado, bajo un
  OID que PHP no sabe leer. Antes salía el de la entidad certificadora, que en
  el `<RutEnvia>` es rechazo inmediato.
- `dte:verifica-sobre` reproduce los **210 envíos que el SII ya aceptó**: mismo
  resumen y misma firma en todos.
- `dte:token` comprueba el camino entero sin emitir nada. Probado contra palena.
- `dte:estado` consulta un envío o un documento. Probado contra palena.
- `dte:envia` es el único comando que hace algo que no se deshace, y sin
  `--confirmar` solo ensaya.

### 0.12.0 — Generar y firmar el XML del DTE
*2026-09-15*

- **No se prueba contra maullin, y no hace falta.** El SII cierra el ambiente de
  certificación cuando el contribuyente firma su declaración de cumplimiento, e
  INNOVAGES lo cerró hace años. En su lugar se reproducen los **209 documentos
  que el SII ya aceptó en producción**, cuyo XML firmado está guardado.
- `Documento` arma el `<DTE>` desde el documento ya escrito en `iw_gsaen`;
  `FirmaXml` lo firma; `Certificado` guarda el certificado de la empresa.
- `dte:verifica-xml` regenera esos documentos y compara. **Facturas 188 de 188,
  notas de crédito 12 de 12**, y la firma sale **idéntica en los 209**.
- Los nueve que no entran en la comparación difieren por cosas conocidas: cinco
  porque el Softland de entonces no escribía `CdgVendedor`, y cuatro porque el
  dato cambió en la base después de emitir el documento.
- La boleta y la factura exenta quedan probadas contra NETDOMAIN, que sí las
  emitió.
- El certificado se instaló en el servidor. Hubo que **reconvertirlo**: venía
  cifrado con un algoritmo que OpenSSL 3 ya no abre. Su clave salió del nombre
  del archivo y se fue al `.env`, donde corresponde.
- El generador **falla en vez de emitir mal** si el documento lleva descuento de
  pie: ese bloque del DTE no está escrito y no hay ningún caso real contra el que
  comprobarlo. Un documento cuyas líneas no cuadran con su total es un rechazo
  del SII con el folio ya gastado.
- Ocho pruebas nuevas fijan el comportamiento de la canonicalización, que es de
  lo que cuelga todo lo demás (`phpunit`, 36 en total).

### 0.11.0 — Escribir la factura y la nota de crédito en inventario
*2026-09-15*

- **Se descubrió que la factura no es la nota de venta.** De 192 facturas
  enlazadas a una NV, **190 van a otro cliente** —188 a Softland Ingeniería—,
  196 de 197 tienen una sola línea de comisión, y `nvCantFact` está en cero en
  todas las líneas de todas las notas de venta. Es un negocio de distribuidor.
  Programar «facturar la NV línea por línea» habría sido entregar algo que no
  se usa.
- `Facturacion` escribe `iw_gsaen` + `iw_gmovi` + `IW_GSaEn_RefDTE`, pide el
  folio al repartidor de Softland y **para ahí**: no centraliza.
- `dte:base-de-pruebas` copia INNOVAGES entera (1.905 tablas, 24 triggers) a una
  base desechable. Se niega a tocar nombres de producción.
- `dte:verifica-documento` reescribe facturas reales en esa copia y compara las
  168 columnas del encabezado y las 62 de cada línea. **199 documentos, 189
  facturas y 10 notas de crédito**; los que no salen idénticos difieren solo en
  dos columnas donde Softland es inconsistente consigo mismo. Escribe dentro de
  una transacción y la deshace, para no gastar el único folio libre.
- Reglas encontradas comparando, no leyendo: el IVA se calcula sobre el neto ya
  redondeado; el signo vive en la cantidad; una equivalencia en cero significa
  uno; el centro de costo va en la línea de la nota de crédito y no en la de la
  factura; `SubTipDocRef` hay que escribirlo nulo a propósito porque la columna
  trae `'A'` por defecto.
- `Totales` no se tocó: se calcula en positivo y se aplica el signo al final.
- Seis pruebas nuevas fijan esas reglas (`phpunit`, 28 en total).

### 0.10.0 — Primer paso de la factura electrónica: el timbre
*2026-09-15*

- Se averiguó que **no hay camino soportado**: Softland no expone API en esta
  instalación, `IWSerDTE.exe` recibe DTE en vez de emitirlos, y la cola por
  carpeta no está montada. Si la app va a facturar, emite ella.
- Se resolvió el mapeo Softland → SII, que no estaba en `cwttdoc` sino en
  `dte_siitdoc` por `(Tipo, SubTipoDocto)`. Queda escrito en `TipoDte`, con los
  cinco tipos: factura, factura exenta, **boleta, boleta exenta** y nota de
  crédito.
- `Caf` lee los folios autorizados desde `dte_siicaf` y firma sin que la llave
  privada salga nunca de la memoria del servidor. `Timbre` arma el `<TED>`.
- `php artisan dte:verifica-timbre` recalcula el timbre de documentos **ya
  emitidos** y lo compara con el guardado. No emite, no escribe, no gasta un
  folio. **615 documentos, cinco tipos, dos empresas con certificados distintos,
  de 2009 a 2026: todos idénticos.**
- El camino de la boleta queda preparado y probado contra la única boleta real
  que existe, en NETDOMAIN. Emitirla sigue bloqueado por los folios CAF, que son
  trámite con el SII.
- De paso, dos rarezas de Softland quedan documentadas y detectadas: hay copias
  archivadas recodificadas a UTF-8 que no validan contra su propio timbre, y
  `dte_archivos` guarda versiones muertas con folios que ya no corresponden.
- Todo en `docs/dte.md`.

### 0.9.1 — Persianas: panel, cliente y datos; una sola fila de acciones
*2026-09-15*

- Componente `Persiana.vue`: una fila que se despliega en el sitio en vez de
  ocupar espacio fijo o saltar a otra pantalla.
- Panel: la frase de "Conversión" y la de "cómo leer este panel" se pliegan
  bajo su propia flecha; "Tiempo de cierre" y "Ticket promedio" ganan la misma
  explicación plegada que ya tenía Conversión.
- Ficha de documento: el nombre del cliente despliega sus datos ahí mismo
  (ya estaban en el teléfono, no hacía falta saltar a su ficha) y la tarjeta
  "Datos" se puede plegar.
- Selector de producto: nombre en dos líneas y letra más chica, para que no se
  esconda la parte que distingue un producto de otro.
- Las tres filas de acciones de una nota de venta o cotización se juntan en
  una sola, en el orden Corregir → Duplicar → Aprobar → Anular → Eliminar.
  El switch de aprobar pasa a ser un botón más de esa fila — la tarjeta con
  el switch ocupaba más de lo que valía la información que traía.

### 0.9.0 — Aprobar notas de venta pendientes que llegaron sin pasar por la app
*2026-09-14*

- **El switch de la 0.8.0 no alcanzaba a las notas de venta reales.**
  `ventas.aprobacion` está vacía en INNOVAGES — 0 filas — y sin embargo hay 16
  notas de venta en `P`, todas de 2020: quedaron así desde Softland de
  escritorio, de antes de que existiera esta app. El switch sólo sabía
  resolver una fila que ya existiera, así que ni admin ni supervisor podían
  tocarlas. Ahora `resolver()` crea la fila al vuelo cuando no hay una —ya
  resuelta, sin perder el registro de quién la soltó— si quien pide es admin,
  o supervisor de ese vendedor puntual. Sin `jefe_id` asignado de antes que
  reparta el permiso solo, decide el organigrama: `Usuario::payload()` ahora
  manda `subordinados_ven_cod`, y el teléfono compara contra el vendedor de la
  nota. La tarjeta de Aprobación aparece igual aunque no haya fila que
  mostrar, con un texto genérico en vez del motivo técnico.

### 0.8.0 — Aprobar la nota de venta desde su propia ficha
*2026-09-14*

- **Switch de aprobar en la ficha de la nota de venta.** La aprobación del
  jefe ya existía de la fase 3 —la nota que pasa el tope del vendedor queda
  `P` y espera—, pero sólo se resolvía desde la cola aparte de Aprobaciones.
  Ahora también se aprueba mirando la nota misma: un switch pide confirmación
  con el número y el monto, y llama al mismo endpoint de siempre
  (`resolverAprobacion`) — no hay backend nuevo. Sólo lo ve el jefe asignado a
  esa aprobación, o un admin: un vendedor mirando su propia nota pendiente no
  lo ve, porque si lo viera podría soltarse el freno solo y el tope dejaría
  de servir. Una vez aprobada, la ficha se bloquea sola — «Corregir» y
  «Anular» ya miran `fecha_aprobacion`, sin ninguna regla nueva — y el switch
  desaparece: la tarjeta de Aprobación ya dice «aprobada» sin él.

### 0.7.2 — El autocorrector de Android bloqueaba la búsqueda instantánea
*2026-09-14*

- **El arreglo de la 0.7.1 no era la causa — o no toda.** Cambiar `type="search"`
  por `type="text"` no bastó: seguía sin filtrar solo. La causa real es que
  `v-model` en un `<input>` nativo ignora a propósito los eventos mientras el
  navegador está «componiendo» texto —así evita capturar texto a medias de un
  IME de chino o japonés—, y el teclado predictivo de Android usa esa misma
  composición para el autocorrector **en cualquier idioma**: escribir una
  palabra de corrido es una sola composición de principio a fin, y `v-model`
  no la suelta hasta que se confirma con un espacio, una puntuación o la lupa
  del teclado. El campo se veía escrito igual porque eso lo pinta el
  navegador, no Vue. Comprobado simulando la secuencia real de eventos de
  composición de Android —cosa que ninguna prueba anterior en el navegador de
  escritorio hacía, por eso no se había visto—. `Buscador.vue` ya no usa
  `v-model` en el `<input>`: lee `value` y escribe en `@input` a mano, sin
  mirar si está componiendo. Mismo cambio en el buscador propio de
  `Usuarios.vue`.

### 0.7.1 — El teclado de Android impedía que la búsqueda filtrara sola
*2026-09-14*

- **La búsqueda instantánea de 0.7.0 no llegó al teléfono de verdad.** En el
  navegador de escritorio filtraba sola; en Android sólo filtraba al tocar la
  lupa del teclado. La causa: el campo era `type="search"`, y en el WebView
  del teléfono ese tipo no dispara el evento de cada tecla mientras se compone
  la palabra —el texto se ve escrito porque el navegador lo pinta igual, pero
  Vue no se entera hasta que se confirma con la lupa o el campo pierde el
  foco—. Es `Buscador.vue`, así que un solo archivo corrige cliente, producto,
  centro de costo, cotizaciones y notas de venta a la vez. Ahora es
  `type="text"` con `inputmode="search"` y `enterkeyhint="search"`: mismo
  teclado, mismo ícono, sin el problema.

### 0.7.0 — Búsqueda instantánea, centro de costo buscable, el panel recuerda lo elegido y se pone al día solo
*2026-09-14*

- **El buscador de cliente y de producto ya no se congela a medias.** La
  ficha se abre con una carga inicial sin filtro y, si el vendedor escribe
  rápido, la búsqueda tecleada podía terminar antes que esa carga: la
  inicial llegaba después y pisaba el resultado, dejando la lista mostrando
  clientes sin relación con lo escrito, sin aviso ni error. Ahora cada
  búsqueda lleva un número de turno y sólo se pinta la más reciente pedida,
  gane quien gane la carrera. Mismo arreglo en las listas de Clientes y
  Productos.
- **Centro de costo se busca como un cliente.** Eran 594 en un `<select>`
  nativo de Android — el mismo problema que ya tenían los giros y que
  `Selector.vue` no resuelve del todo si hay menos de 40 opciones detrás del
  filtro. Ahora es una ficha con buscador arriba y tarjetas tocables abajo,
  igual que cliente y producto.
- **El panel recuerda el período y el ámbito elegidos.** Nacía siempre en
  «mes» y en «yo», sin importar lo que el vendedor hubiera dejado la vez
  anterior. Ahora queda en el aparato — como la densidad o el lado del botón
  flotante — y sólo cambia cuando el vendedor lo vuelve a tocar.
- **Al volver del segundo plano, se pone al día sola.** Un teléfono que
  queda abierto una hora mientras se trabaja desde el escritorio no se
  enteraba de nada nuevo hasta que alguien tocaba sincronizar. Ahora, cada
  vez que Android trae la app de vuelta a primer plano, se dispara la misma
  sincronización incremental de siempre — con un plazo de cinco minutos para
  no repetirla a cada rato.

### 0.6.3 — El icono del vendedor
*2026-09-14*

- **La app se instala con su icono.** Hasta aquí el teléfono la mostraba con el
  icono por defecto de Capacitor. Ahora lleva el mundo azul de la empresa en
  las cinco densidades de Android, en las tres formas que pide —el cuadrado
  antiguo, el redondo y la capa de adelante del icono adaptable— y recortado
  bien en cualquier lanzador: el logo ocupa exactamente los 72 dp visibles de
  la lámina de 108, así que en máscara redonda queda a ras y en máscara
  cuadrada el blanco le hace de marco.
- **La pantalla de arranque también.** Mostraba el logo celeste de Capacitor
  cada vez que se abría la app. Ahora es el mismo mundo, centrado sobre blanco,
  en las once pantallas de vertical y horizontal.
- **Un icono nuevo es un archivo, no veintiséis.** `mobile/recursos/icono.png`
  es la fuente y `python3 mobile/scripts/icono-app.py` escribe el resto. Se
  fueron también los dos recursos por defecto de Android Studio que nadie
  usaba y que estaban ahí para que alguien editara el archivo equivocado.

### 0.6.2 — La primera descarga se ve terminar
*2026-09-14*

- **El panel se entera de que la descarga terminó.** Al entrar por primera vez,
  la sincronización arranca sola desde el login y tarda medio minuto; el panel,
  mientras tanto, ya había contado el almacén —vacío— y se quedaba diciendo
  «Todavía no te has traído los datos» y «Actualizado nunca» encima de un
  teléfono con 14.139 filas dentro. Parecía que la descarga no terminaba nunca,
  y el vendedor sincronizaba otra vez para que aparecieran. Ahora `sync.js`
  avisa al terminar (`corridas`) y el panel y Cuenta vuelven a leer solos.
- **Sincronizar mientras se sincroniza ya no miente.** Pedir una descarga con
  otra en curso devolvía `null` de inmediato: la pantalla entendía «listo» sin
  que hubiera bajado nada, y en la tarjeta de «cambios sin enviar» reventaba
  con un error de programación. Ahora el segundo espera a la primera y recibe
  su mismo resumen.
- **Los traductores de código a nombre se recargan con los datos.** Se cargan
  en memoria una vez, y en la primera sesión se cargaban vacíos: el panel decía
  «vendedor 2» donde va el nombre hasta reiniciar la app.
- **Ninguna petición espera para siempre.** 30 s las normales, 60 s el PDF y la
  subida del logo. Un `fetch` que se queda colgado —cambio de WiFi a datos
  móviles a mitad de descarga— dejaba la sincronización detenida sin nada que
  decir. El plazo agotado se distingue de la falta de red en el mensaje.

### 0.6.1 — La app resuelve sola http o https, y el proxy deja de romper el login
*2026-09-14*

- **La pantalla Servidor prueba los dos esquemas y guarda el que contestó.**
  Antes suponía `http://` cuando no se escribía ninguno, y eso rompe cualquier
  instalación detrás de un proxy HTTPS: `venta.netdomain.cl` se guardaba como
  `http://venta.netdomain.cl`, IIS responde a eso con un 301 a https, y el
  navegador del teléfono **no sigue una redirección en una comprobación previa
  de CORS**. Consecuencia exacta: por el navegador se llegaba y por la app no.
  Ahora se prueban `https` y `http` —en el orden que corresponde según si la
  dirección parece interna o pública— y se guarda `res.url`, la dirección ya
  resuelta.
- **Las cabeceras del PDF vuelven a leerse** (`exposed_headers` en
  `config/cors.php`). Con `allow-origin: *` el navegador sólo entrega las siete
  cabeceras de la lista segura, así que `X-Documento-Version` y
  `X-Documento-Hash` llegaban vacías y el teléfono archivaba todos los PDF como
  «versión 1, sin huella», sin forma de saber si el que tenía seguía vigente.

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
