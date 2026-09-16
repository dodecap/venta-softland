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

### 0.28.0 — Envío automático o manual, y borrar lo que no viajó
*2026-09-16*

- **El envío al SII se puede poner en manual**, desde Configuración. Encendido
  —como nace— emitir manda el documento en el mismo acto; apagado, queda escrito
  esperando a que alguien lo mande desde su ficha.
- **La llave es nuestra, no la de Softland, y es una decisión.** El ERP tiene las
  suyas en `soempre` (`DTEFacturaLote`, `DTEFacturaLinea`, `TipoEnvio*`) y no se
  leen: describen cómo manda el **escritorio**, hoy dirían que no mandáramos
  nunca —INNOVAGES tiene `DTEFacturaLote = 1`— y su significado se deduce, no se
  sabe. Se obedece al ERP donde manda sobre el documento; esto es comportamiento
  de esta app. Lo que sí se hace es **enseñar lo que dice Softland** al lado de
  la llave, para que nadie elija a ciegas.
- **`dte:pendientes` respeta el modo.** En manual no manda nada y sigue
  recogiendo veredictos: si mandara, la llave no serviría de nada.
- **«Sin enviar» cambia de color según el modo.** En automático es una avería y
  va en rojo; en manual es la tarea de alguien y va en ámbar. Es el mismo hecho
  con dos lecturas, y la lista no puede gritar por algo que se decidió así.
- **Una factura que nunca llegó al SII se puede borrar**, y **el folio vuelve**.
  Comprobado contra el repartidor de Softland dentro de una transacción que se
  deshizo: con la reserva del folio puesta devolvía −1, y sin ella devolvió 235.
- **Dos limpiezas son nuestras.** El trigger `IW_GSaEn_IW_GMOVI_DTRIG` se lleva
  líneas, referencias y la fila de seguimiento **enlazada**; quedan el XML
  timbrado de `dte_archivos` y **la fila de reserva del folio**, que nunca llega
  a enlazarse —el repartidor la deja con `Tipo` nulo y quien la enlaza es un
  trigger de *UPDATE*, y nosotros insertamos—. Sin borrarla, el folio no vuelve.
- **Borrar y anular no se ofrecen a la vez.** Antes de viajar se borra; después
  se anula. Enseñar las dos salidas invita a gastar un folio de nota de crédito
  cuando la barata todavía existe.
- **Lo borrable es lo que escribió la app**, y eso lo dice `Proceso = 'Venta
  Softland'` en la propia fila — la misma marca que usa `dte:pendientes`. No
  sirve el mapa de `client_uuid`: responde a otra pregunta, y las facturas
  anteriores a que la app dejara huella son nuestras igual. Lo vimos ensayando
  con la 235.
- El saldo de la nota de venta vuelve solo al borrar, porque se calcula y no se
  guarda.

### 0.27.0 — Los atributos de la nota de venta, que los define cada empresa
*2026-09-16*

- **Softland tiene un mecanismo de campos definidos por la empresa**, y no lo
  estábamos usando. Cada maestro puede llevar atributos declarados **en la
  base**: la nota de venta es el `IdMaestro = 4`, y de ahí salen dos de las
  líneas de la orden de compra al distribuidor — «TIPO DE VENTA» y la
  «OBSERVACIÓN».
- **No hay ningún atributo garantizado, y eso manda sobre el diseño.**
  INNOVAGES declara cuatro y NETDOMAIN uno. Así que en ninguna parte del código
  se nombra un atributo: el editor dibuja los que declare la base, con el
  control que pida su tipo, y si la empresa no define ninguno la sección no
  aparece.
- **El tipo dice dónde vive el valor**: 4 es una lista y va a `...TVAtrT`, 3 es
  fecha y va a `...TVAtrF`, 1 y 2 son número y sí/no y van a `...TVAtrV`. Está
  comprobado mirando dónde acaban los valores de los nueve atributos definidos
  entre las dos empresas, no leyendo documentación.
- **Una vista en nuestro esquema** (`ventas.nv_atributo_valor`) une las tres
  tablas de valores en la forma que el teléfono necesita. El esquema de Softland
  no se toca ni para añadir una vista.
- **Escribir un atributo es borrar y volver a poner.** La clave primaria admite
  dos valores para el mismo atributo y Softland nunca lo usa así: insertar sin
  borrar dejaría la nota de venta con dos «Tipo de Venta» y el papel eligiendo
  uno al azar.
- **Pero se comprueba antes de borrar.** El ensayo contra la 2036 destapó que
  una opción inválida **borraba la que había**: el valor bueno se perdía por
  mandar uno malo. Ahora un valor que no se puede escribir deja el atributo como
  estaba.
- **Al borrar la nota de venta no hay que limpiar nada**: lo hacen tres triggers
  de Softland. Comprobado — no hay un solo valor huérfano en las dos empresas.
- Todo ensayado contra la nota de venta 2036 real, dentro de una transacción que
  se deshace: cambiar una lista, cambiar una fecha, borrar un atributo, y colar
  un atributo y una opción que no existen.

### 0.26.1 — La ficha de la factura se lee como las demás
*2026-09-16*

- **La ficha de la factura quedó calcada de la cotización y la nota de venta**:
  total grande con sus etiquetas, persiana que cerrada dice qué documento es y
  de quién —«Factura Nº 235 — NETDOMAIN EIRL»— y abierta enseña el resto.
- **Los botones suben.** Estaban debajo del detalle, así que había que recorrer
  el documento entero para llegar a lo que uno venía a hacer.
- **La tarjeta de «Nota de venta Nº 2065» era media pantalla para decir un
  número.** Ahora es el renglón «Viene de» dentro de la persiana de totales, el
  mismo sitio donde la nota de venta enseña su cotización de origen.
- **El panel lista las cinco últimas facturas**, junto a cotizaciones y notas de
  venta. Con dos cuidados: la fila se abre por tipo + número interno, porque el
  folio sólo es único dentro de su tipo; y el estado que enseña es **el del
  SII**, leído de su almacén y no de `FechaGenDTE`, que se escribe al timbrar —
  antes de que el documento viaje.
- Las notas de crédito no entran en esa lista: son el desenlace de una factura y
  contarían dos veces la misma operación.

### 0.26.0 — El papel de la factura, con su timbre
*2026-09-16*

- **La factura, la boleta y la nota de crédito salen en PDF**, reproduciendo la
  representación impresa que el cliente lleva años recibiendo desde Softland:
  recuadro rojo con el folio, caja del receptor, rejilla de 27 renglones, timbre
  abajo a la izquierda, totales y «Son:» a la derecha, y el acuse de recibo de
  la ley 19.983 al pie.
- **El timbre es de verdad y está comprobado.** Se dibuja el PDF417 del TED
  guardado, y la comprobación no fue mirar el papel: se extrajo el código de
  barras del PDF que generamos y se decodificó. Devuelve **los mismos 777 bytes**
  que el que Softland imprimió en la factura 234. No se parece: dice lo mismo.
- **El timbre no se regenera nunca.** Sale de `dte_doccab.FirmaDTE`, que es donde
  quedó el que viajó al SII. Uno nuevo sería válido y distinto, y un papel que no
  dice lo mismo que el XML es un papel que no cuadra.
- **Carta, no A4**, que es el papel en que Softland los imprime. El tamaño pasa a
  ser un dato del tipo documental, como los bloques.
- La hoja legal es **otra hoja, no otro motor**: el tipo sigue declarando sus
  bloques y la plantilla los recorre. La rejilla parte la lista en dos —lo que va
  antes se dibuja en la primera página, lo que va después en la última—, sacado
  del orden declarado y no de una lista de nombres escrita en la hoja.
- **Pagina de verdad**: con 40 líneas salen dos hojas, la segunda sigue en el
  renglón 28 y el cierre va sólo en la última. Comprobado.
- **El «Son:»** se escribe en letras con sus trampas resueltas —«cien» a solas y
  «ciento uno» acompañado, «veintiún mil» y no «veintiuno mil», el millón con
  plural y el mil sin él—, y nueve pruebas lo fijan. La primera comprueba el
  monto exacto de la factura 234, letra por letra.
- **La oficina del SII y la resolución las tiene Softland** (`soempre.Ciud`,
  `DTENumeroResol`, `DTEFechaResol`): no hay nada que configurar el día uno, y se
  pueden corregir en Identidad como el resto.
- El pie dice **«FACTURA ELECTRÓNICA CREADA POR INNOVAGES»**, no por Softland:
  este papel no lo crea Softland.
- Desde la ficha del documento se abre y se manda al cliente por la hoja de
  compartir, igual que la cotización. Y se guarda en el teléfono, así que la
  segunda vez se abre sin señal.

### 0.25.0 — Emitir y enviar son un solo acto, con cola a los dos lados
*2026-09-16*

- **Emitir una factura la manda al SII en la misma petición.** Antes eran dos
  pasos y el segundo dependía de que alguien se acordara: así es como una
  factura del día 30 termina emitida el 2, en otro mes tributario. Si el SII no
  contesta, el documento queda escrito y en rojo, y el servidor lo reintenta
  solo — la petición no se cae, porque el folio ya se gastó y decir «falló» a
  secas sería mentir.
- **El permiso se corrió de sitio.** Mandar al SII ya no exige rol de
  facturación: quien puede emitir, manda, porque son el mismo acto. El permiso
  que tiene sentido es «quién puede emitir», y se aplica antes.
- **El timbre se guarda antes de enviar, no después.** Es el cambio más fino de
  esta versión: el timbre lleva dentro la hora exacta en que se timbró, y el
  código de barras del papel tiene que decir lo mismo que el XML que recibió el
  SII. Generándolo dos veces salen dos timbres. Ahora se genera una vez, se
  guarda, y se reusa para reintentar y para imprimir.
- **`dte_doccab.Proceso` ya no se escribe.** Es `varchar(10)` —«Venta Softland»
  no cabe— y está en NULL en las 4.798 filas de las dos empresas. Habría
  reventado en el primer envío de verdad, que es justo donde este camino se
  estrena.
- **Cola en el teléfono**: sin señal, facturar deja el documento en la bandeja
  de salida y sale solo al volver la red. Lo guardado **no es una factura** —sin
  folio y sin timbre, los dos los pone el servidor— y la pantalla lo dice con
  esas palabras, porque no hay número que darle al cliente.
- **Cola en el servidor**: `dte:pendientes` manda lo escrito que no viajó y
  recoge los veredictos de lo que viajó. Esa segunda mitad es la que hace que el
  verde aparezca solo: el SII no avisa de nada.
- **Se emite sola cuando el mundo sigue igual; se pregunta cuando cambió.** Una
  factura que esperaba en la bandeja y llega cuando su nota de venta ya se
  facturó entera no se emite a ciegas: vuelve preguntando, y la bandeja ofrece
  «emitir igual». La nota de venta anulada no tiene salida y se dice.
- **La factura es idempotente por `client_uuid`**, como la cotización y la nota
  de venta. Aquí protege de algo más caro: dos envíos serían dos folios.
- **La lista de facturas quedó como las otras listas.** Estaba hecha con un
  `button`, que trae los estilos del navegador y la dejaba con pinta de recuadro
  de otra app. Y la franja del SII pasó a rojo/ámbar/verde: desde que emitir y
  enviar son lo mismo, «sin enviar» es una avería, no un paso del camino.

### 0.24.0 — Facturar no exige ser vendedor, y lo emitido tiene su lista
*2026-09-16*

- **El vendedor de una factura es el de la venta, no el de quien la emite.** El
  servidor estampaba el `ven_cod` del usuario conectado, y eso hacía dos cosas
  mal: facturación y administración no podían emitir nada —no son vendedores,
  no tienen código, y son justamente quienes facturan—, y un vendedor que
  emitiera la factura de otro le quedaba con la venta sin que se notara en
  ninguna pantalla. Ahora la factura hereda el vendedor de su nota de venta y la
  nota de crédito el de la factura que anula. Lo respaldan los datos: 181 de las
  204 facturas de INNOVAGES nacidas de una nota de venta llevan el vendedor de
  su nota de venta, y las 12 notas de crédito llevan el de la factura que
  anulan; en NETDOMAIN, 625 de 649.
- **La factura sin nota de venta pregunta de quién es la venta**, porque ahí no
  hay de dónde heredarla. Mismo selector y misma regla que la cotización: el
  tuyo, o el de tu gente si eres supervisor.
- **Lista de facturas emitidas**, con su ficha. Hasta ahora una factura sólo se
  veía desde la nota de venta de la que salió, y la que nace sin nota de venta
  —el 88 % de las de NETDOMAIN— no aparecía en ninguna parte, así que tampoco
  había desde dónde mandarla al SII. Se llega desde la cola de facturación.
- La franja derecha de esa lista dice el estado **ante el SII**, no la
  sincronización: escrito y sin enviar es lo único que no se arregla solo.
- **En `iw_gsaen.Usuario` se escribía vacío.** La propiedad se llama
  `softland_user`, no `usuario`, y nadie se dio cuenta porque el documento sale
  igual de correcto. El ERP pone ahí el usuario de Softland, y es por quién se
  pregunta cuando alguien cuadra el mes.
- **Un error al emitir ya no se lleva por delante el documento tecleado.** En
  Facturar el aviso reemplazaba la pantalla entera: había que escribirlo todo
  otra vez y encima sin ver qué se iba a emitir.
- La regla de «a nombre de quién queda el documento» se escribe una vez
  (`AlcancePorVendedor`). Estaba copiada en dos controladores y sólo una de las
  copias sabía qué hacer cuando quien opera no es vendedor.

### 0.23.1 — La versión del servidor se pregunta, no se recuerda del login
*2026-09-16*

- **Cuenta enseñaba la versión del servidor del día en que el vendedor entró.**
  Se guardaba sólo en el login, así que después de cualquier despliegue decía
  que la app y el servidor no coincidían aunque coincidieran — y tapaba los
  desfases de verdad. Un aviso que se equivoca es peor que no tenerlo, porque
  enseña a no hacerle caso. Ahora se pregunta al abrir la pantalla; sin señal se
  queda con lo último que se supo.
- **`bin/version.sh` recuerda volver a desplegar y compilar.** Los dos leen
  `VERSION`, así que un despliegue anterior al cambio deja el servidor atrás — que
  es exactamente lo que había pasado dos veces seguidas.

### 0.23.0 — Factura sin nota de venta, desde el teléfono
*2026-09-16*

- **La factura libre**, que resultó ser la normal: en NETDOMAIN **el 88 % de las
  facturas y el 100 % de las boletas** nacieron sin nota de venta detrás, y en
  INNOVAGES hay cinco —dos de ellas a NETDOMAIN por el mismo concepto—.
- No es una versión degradada de la otra: es el mismo documento sin un origen
  que le dicte los datos. Aquí el precio **sí** lo escribe quien factura, porque
  no hay documento anterior que lo mande, y las líneas se eligen a mano.
- Se llega desde la cola de facturación, con el botón flotante: ahí no se está
  mirando un catálogo de notas de venta, se está facturando.
- Mismos guardianes que el resto: los folios se dicen al abrir —hay un endpoint
  para preguntarlo sin documento de por medio—, la confirmación nombra el folio,
  y necesita señal.
- Se reusan los selectores del editor de documentos, incluido el turno que evita
  que la carga inicial pise la búsqueda que el vendedor ya hizo.

### 0.22.1 — El botón Facturar lleva a lo que falta por facturar
*2026-09-16*

- **El acceso «Facturar» del panel seguía apagado y con «Fase 4» encima.** La
  fase estaba hecha hace tres versiones y el botón no se había enterado. Ahora
  lleva a las notas de venta **que todavía tienen algo por facturar**, que es la
  cola de trabajo de quien factura y no un catálogo de documentos.
- El filtro se ve y se quita, como el que llega desde el panel: un filtro que no
  se ve es una lista incompleta sin explicación.
- **El embudo ya no dice «no sincronizado»** en su tercera etapa — las facturas
  se sincronizan desde la 0.20.0—. Sigue apagada, pero por la razón de verdad:
  aquí se factura por suscripción, una nota de venta genera varias facturas a lo
  largo de meses, y poner «vendido» y «facturado» del mismo período uno al lado
  del otro invita a restarlos.

### 0.22.0 — Mandar el documento al SII desde la app, y preguntar en qué quedó
*2026-09-16*

- **El envío al SII deja de ser un comando de consola.** Desde la ficha de la
  nota de venta, cada factura tiene su estado y su acción: «Enviar al SII»
  cuando no ha viajado, «Ver qué dijo el SII» cuando sí.
- **Tres barreras**, comprobadas contra el servidor real: lo hace **facturación
  o administración** —escribir la factura es del vendedor, mandarla al fisco es
  un acto de la empresa—; no se manda dos veces, y el 409 nombra el `TrackID`
  que ya tiene; y un documento anulado no se manda.
- **Enviado y aceptado son distintos**, y se dicen distinto. Lo primero es que
  viajó; lo segundo, que el SII lo miró y lo dio por bueno. El veredicto tarda
  minutos, así que es una consulta aparte y no algo que se espere dentro del
  envío.
- Maestro `dte_estado`: la ficha dice «enviada» o «sin enviar» **sin señal**.
  Preguntarle al SII sí la necesita, pero eso sólo pasa cuando alguien pregunta.
- Sin señal o con el SII caído, la consulta devuelve lo guardado —que se mandó y
  cuándo— en vez de un error: es la mitad de la respuesta y sirve igual.

### 0.21.1 — La nota de crédito sólo anula, y se comprueba que anule entera
*2026-09-16*

- **Decisión del cliente, ahora escrita como regla**: la app sólo emite notas de
  crédito de **anulación completa**. `Facturacion::devuelveTodo()` lo comprueba
  línea a línea antes de pedir folio, y cuatro pruebas lo fijan. Que las líneas
  salgan de `propuestaNotaCredito()` lo hacía cierto hoy; esto lo deja cierto
  mañana.
- **Se compara línea a línea, no por el total**: dos líneas intercambiadas suman
  lo mismo y no son la misma devolución.
- **La razón iba a la columna equivocada.** El `RazonRef` del DTE sale de la
  columna **`Glosa`** de Softland; la que se llama `RazonRef` está vacía en los
  209 documentos reales. Escribir en la que se llama igual habría funcionado por
  casualidad y dejado el ERP diciendo otra cosa.

### 0.21.0 — Anular una factura con nota de crédito, desde la nota de venta
*2026-09-16*

- **La ficha de la nota de venta enseña lo facturado.** Una factura es el
  desenlace de una venta, no un documento suelto: se ve donde alguien se
  pregunta qué salió y qué falta. Las notas de crédito no se listan aparte —son
  el desenlace de una factura— y aparecen como «Anulada con la NC Nº 16».
- **Anular con nota de crédito** desde ahí. **Las líneas no las manda el
  teléfono**: las arma el servidor desde la factura. Anular es devolver lo que se
  facturó, todo y tal cual; proponerlas desde el cliente dejaría abierta la
  puerta a una nota de crédito que no cuadra con lo que anula.
- **Anulación entera, no devolución parcial.** El SII distingue: `CodRef 1`
  anula, `2` corrige el texto y `3` corrige los montos. Devolver tres de diez
  unidades no es anular — es otro documento, con otra referencia.
- **No se anula dos veces.** Si ya hay una nota de crédito vigente que la
  referencia, responde 409 nombrándola.
- Maestro `factura_referencias`: la ficha sabe sin señal qué factura ya está
  acreditada. La referencia vive en `IW_GSaEn_RefDTE`, no en `AuxDocNum`.
- Los folios de nota de crédito se dicen antes, como los de factura.

### 0.20.0 — Facturar desde el teléfono, diciendo antes cuántos folios quedan
*2026-09-16*

- **La pantalla de facturación**, que cierra la fase 4.5. Desde la nota de venta
  se factura lo pendiente, con las cantidades precargadas y editables.
- **Los folios se dicen antes.** La propuesta trae cuántos quedan y cuál sería
  el siguiente, y la confirmación nombra el folio que va a gastar. Enterarse de
  que no hay después de teclear el documento es la peor forma de enterarse.
- **Contar folios no es contar los que no están usados.** El repartidor de
  Softland va hacia adelante y no rellena huecos: contar los libres daba **38**
  donde la realidad es **uno**. Se cuenta lo que el repartidor va a entregar.
- **El precio no tiene campo.** Lo pone la nota de venta; un campo desactivado
  invita a pelearse con él, no tenerlo dice mejor que no es una decisión de
  quien factura.
- **Facturar necesita señal**, a propósito: un documento tributario no se guarda
  en una bandeja de salida.
- **`FacturaController`** con la propuesta, la emisión y la consulta; maestros
  `facturas` y `factura_lineas` para que el teléfono los tenga sin señal.
- **«Facturado 5 de 12» ya dice la verdad.** Se calculaba desde `nvCantFact`,
  que está en cero en las 3.824 líneas de cada empresa: decía «0 de 12» siempre.
  Ahora sale de las líneas de factura vigentes, menos lo acreditado.

### 0.19.0 — El saldo se ve: convertida a medias y nota de venta por lo que queda
*2026-09-16*

- **Paso 6 de la fase 4.5**, la parte de la cotización. La ficha dice
  «convertida a medias: quedan 2 de 3 líneas» con el detalle de lo que falta, y
  el botón pasa a llamarse **«Nota de venta por el saldo»**, que convierte sólo
  lo pendiente y con la cantidad pendiente.
- **La lista marca «A medias»**, que es lo que Softland no sabe distinguir: su
  estado `V` dice «tiene nota de venta» y nada más.
- **Se calcula en el teléfono, sin señal.** `linea_origen` baja como un maestro
  más y `mobile/src/saldo.js` repite la regla de `Saldo.php`. Se repite porque
  esto se mira en terreno; un dato que sólo aparece con cobertura no sirve.
- **Convertir ahora manda `cot_linea`** en cada línea. Sin eso, ni una
  conversión completa dejaba enlace y toda cotización quedaba en «no se sabe».
- **Y dice cuándo no lo sabe**: una cotización convertida desde el Softland de
  escritorio no tiene enlace de línea, y la ficha lo avisa en vez de inventar
  un saldo.
- `npm run pruebas` cubre la regla del teléfono con doce comprobaciones más,
  incluidas las dos que duelen: anular devuelve el saldo, y un enlace cuyo
  número volvió a repartirse no cuenta.

### 0.18.0 — La llave del receptor: el ciclo normal por omisión, la comisión bajo llave
*2026-09-16*

- **Paso 5 de la fase 4.5.** `ReglasFactura`, en `ventas.config`: con la llave
  apagada —como nace— el receptor de la factura se hereda de la nota de venta.
  Encendida, se puede cambiar, y es lo que habilita el ciclo de distribuidor.
- **La regla vive en el escritor, no en el controlador.** Un camino nuevo que no
  supiera de ella escribiría facturas al cliente equivocado sin enterarse, y eso
  no se corrige con un `UPDATE` sino con una nota de crédito.
- **La nota de crédito queda fuera de la regla**: su receptor lo manda la factura
  que acredita, no la nota de venta. Por eso la comprobación corre antes de
  heredar, que es cuando la nota de crédito adopta su nota de venta.
- `GET /configuracion` la informa y `PUT /configuracion/facturacion` la cambia.
- `dte:verifica-documento` la enciende a la fuerza: reproduce los documentos
  históricos de INNOVAGES, que son comisiones, y con la llave como nace no se
  podrían reescribir. Comprueba el escritor, no la configuración de la empresa.

### 0.17.0 — La nota de crédito devuelve el saldo, por los dos caminos
*2026-09-16*

- **Paso 4 de la fase 4.5.** La nota de crédito hereda del documento que
  corrige el producto, el precio, el descuento y —lo que importa— el
  `nvCorrela`: si la línea de la factura consumía saldo, la que lo devuelve dice
  de cuál.
- **Dos columnas se reparten el trabajo sin ponerse de acuerdo.** De las 320
  líneas de nota de crédito de NETDOMAIN, 84 traen `nvCorrela` y sólo 13
  `FactNumLin`; en INNOVAGES es al revés, las 12 traen `FactNumLin` y sólo 2
  `nvCorrela`. El lector prueba las dos: si no está la primera, salta por
  `FactNumLin` a la línea de la factura y toma de ahí el enlace.
- **Y no se inventa la tercera.** Las dos únicas líneas que no se pueden
  atribuir llevan un producto distinto del de la factura que acreditan: no están
  devolviendo esa línea. Un respaldo por producto habría acertado a equivocarse
  justo ahí.
- `Facturacion::propuestaNotaCredito()`: las líneas que anulan un documento
  entero, con los dos enlaces puestos.
- El ciclo entero comprobado: convertir, facturar en parte, acreditar, anular la
  nota de crédito y anular la factura. El saldo vuelve y se va sin perderse ni
  duplicarse en ninguno de los cinco pasos.

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
