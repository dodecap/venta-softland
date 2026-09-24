# Instalar Venta Softland en una empresa nueva

Manual de puesta en marcha, de principio a fin. Está escrito para quien va a
instalar en el servidor de un cliente **sin este repositorio delante** y sin
poder preguntarle nada a nadie.

Hay **una sola cosa que no se puede deshacer**: emitir un documento tributario.
Todo lo demás de este manual se puede repetir sin miedo — los pasos son
idempotentes a propósito.

> **Un repositorio, N instalaciones.** No se copia el proyecto por cliente. Lo
> de cada empresa vive fuera del código: la conexión en `softland.json`, la
> identidad y las reglas en `ventas.config`, y los folios, atributos y giros en
> la propia base Softland.

> **Qué le hace esto a la base.** La pregunta que hace siempre quien administra
> el SQL Server del cliente, y conviene tener la respuesta antes de que la
> haga: **no se modifica ninguna tabla del ERP.** Ni una columna añadida, ni un
> trigger, ni un índice. La app crea un **esquema propio `ventas`** dentro de
> la misma base y vive ahí. El detalle, con números, está en el
> [apéndice](#apéndice--la-huella-en-la-base).

---

## 0. Antes de ir: lo que hay que pedirle al cliente

La mitad de las instalaciones que se atascan es por un dato que no se pidió a
tiempo. Esta lista se manda por correo **antes** de agendar el día.

### Accesos

| Qué | Para qué | Sin eso |
|---|---|---|
| Sesión en el servidor (consola o escritorio remoto) | Todo | No se empieza |
| Nombre de la **instancia** de SQL Server | Conectar | No se conecta |
| Nombre de la **base de la empresa** | Conectar | No se conecta |
| Usuario y contraseña **de SQL Server** | Conectar | No se conecta |
| Contraseña del usuario **`softland`** del ERP | Autorizar la instalación | `/setup` no deja instalar |
| Quién administra el sistema desde la app | Crear los usuarios | Nadie puede entrar después |

El usuario SQL necesita poder **crear un esquema y tablas** dentro de la base de
la empresa. No toca nada del esquema `softland` fuera del flujo de la
aplicación, pero sí crea el suyo.

Sólo el administrador `softland` puede instalar: se comprueba su contraseña
contra `softland.wisusuarios`. Es lo que impide levantar el sistema sobre la
base de una empresa sin autorización.

### Para facturar (si el cliente va a emitir DTE desde la app)

| Qué | Dónde se usa |
|---|---|
| Certificado digital `.pfx` o `.p12` **y su clave** | Se sube desde la app |
| Folios CAF cargados en Softland | Los reparte el ERP, no la app |
| Resolución del SII: número y fecha | Salen de `softland.soempre` |

El certificado **se renueva todos los años**: conviene anotar la fecha de
vencimiento el mismo día de la instalación. La app la enseña.

### Para que salgan los correos

Servidor SMTP, puerto, cifrado, usuario, contraseña y el remitente. Sin esto la
app funciona igual, pero los avisos se quedan en el registro del servidor.

### Decisiones del cliente

- **Códigos de vendedor** de cada persona que va a usar la app (`cwtvend`).
  Un documento sin vendedor no existe para Softland: no sale en las ventanas de
  búsqueda del ERP.
- **Tope por vendedor**, si quieren que las notas de venta grandes esperen el
  visto bueno del jefe. Es una aprobación que aporta la app; el ERP tiene la
  suya en `nwparam.CheckApruebaNv` y se respeta.
- Si le facturan a alguien distinto del cliente de la nota de venta
  (distribuidor, comisiones). Depende de un permiso del ERP —`IW · Iw_FacLin ·
  NVOtroAuxiliar`— **y** de una llave nuestra: la nuestra sólo apaga, nunca
  enciende lo que Softland negó.

---

## 1. El servidor: lo que tiene que haber

| Requisito | Mínimo | Cómo se comprueba |
|---|---|---|
| Windows con XAMPP (Apache + PHP) | PHP **8.3** | `/setup` lo dice antes de dibujar el formulario |
| Extensiones `pdo_sqlsrv` y `sqlsrv` | — | Idem |
| Extensiones `openssl`, `mbstring`, `fileinfo`, `gd`, `dom`, `curl` | — | Idem |
| Composer | — | `bin\instalar.cmd` lo usa |
| SQL Server accesible **desde el propio servidor** | 2016+ | `/setup` lo prueba |

Dos cosas que conviene entender antes de empezar:

- **SQL Server no necesita escuchar en la red.** La app corre en el mismo
  equipo que la base. El teléfono nunca habla con SQL Server: por eso existe la
  API.
- **Laravel se sirve desde `public/`**, no desde la raíz del proyecto. Sin el
  Alias de Apache la dirección acabaría en `/<carpeta>/public` y, lo que es
  peor, el código quedaría a la vista.

---

## 2. Copiar el código al servidor

Clona o copia el proyecto en `C:\xampp\htdocs\<nombre>`. El nombre de la
carpeta es el que va a salir en la dirección, así que conviene que sea corto y
sin espacios: `venta-softland`, `ventas`, `vs`.

```
C:\xampp\htdocs\venta-softland
```

No hace falta copiar `vendor/`: lo instala el paso siguiente.

---

## 3. `bin\instalar.cmd`

Desde una consola, en la carpeta del proyecto:

```
cd C:\xampp\htdocs\venta-softland
bin\instalar.cmd
```

**No pide ninguna contraseña.** Deja el servidor en condiciones de que `/setup`
funcione y para ahí; las contraseñas se escriben en el navegador, una sola vez.

Hace seis cosas, y todas se pueden repetir sin daño:

1. Comprueba que PHP responde.
2. Instala las dependencias con Composer.
3. Crea el `.env` desde `.env.example` — **si ya existe, no lo toca**.
4. Genera la `APP_KEY` — **si ya está, no la regenera**. Con esa clave se cifra
   la conexión a Softland en disco; si se pierde, hay que reconfigurar.
5. Crea las carpetas de escritura.
6. Escribe el Alias de Apache en `C:\xampp\apache\conf\extra\<carpeta>.conf`.

Si PHP está en otra ruta, se le pasa:

```
bin\instalar.cmd D:\xampp\php\php.exe
```

### El paso que queda a mano, y es a propósito

El script **no** edita `httpd.conf`: tocarlo a ciegas puede dejar Apache sin
arrancar, y entonces no hay página que explique nada. Al terminar te dice la
línea exacta. Añádela al final de `C:\xampp\apache\conf\httpd.conf`:

```
Include conf/extra/venta-softland.conf
```

Y **reinicia Apache** desde el panel de XAMPP.

---

## 4. `/setup`, desde un navegador

Abre, desde el propio servidor o desde la red de la oficina:

```
http://<este-servidor>/venta-softland
```

### Primero la página comprueba el servidor

Antes de dibujar el formulario mira PHP, las extensiones, la `APP_KEY` y las
carpetas. Si falta algo, **no hay formulario**: sale la lista de lo que falta y
cómo se arregla. Rellenar siete campos para que «Instalar» conteste `could not
find driver` es hacerle perder el tiempo a quien está en el servidor de un
cliente sin nada que consultar.

### Después, siete campos

| Campo | Ejemplo | Ojo con |
|---|---|---|
| Servidor SQL | `localhost\MSSQLSERVER2022` | Si la instancia es **nombrada**, va `host\INSTANCIA` y el puerto **se deja en blanco** |
| Base de datos | el nombre de la empresa | Cada empresa de Softland es una base distinta |
| Puerto | vacío | Sólo si la instancia es la de por defecto y escucha en un puerto raro |
| Usuario SQL | `sa` u otro | Tiene que poder crear esquema y tablas |
| Contraseña SQL | — | — |
| Usuario Softland | `softland` | **Sólo `softland`**; cualquier otro se rechaza |
| Contraseña Softland | — | Se valida contra `softland.wisusuarios` |

### Lo que pasa al pulsar «Instalar»

1. Se prueba la conexión a SQL Server.
2. Se comprueba la contraseña del administrador de Softland.
3. **Se mira si esa base tiene lo que la app usa** — 46 tablas, 731 columnas y
   el repartidor de folios. Cada empresa corre la versión de Softland que le
   tocó, y entre versiones cambian tablas y columnas.
4. Se guarda la conexión **cifrada** en `storage/app/private/softland.json`.
5. Se crea el esquema `ventas` y se corren las migraciones.
6. Se registra a `softland` como administrador y se siembran las 12 reglas de
   notificación.

### Si la comprobación de la base falla

Depende de **qué** falte:

- **Entrar/leer la empresa** o **cotizar/vender**: no se instala. Sale la lista
  de lo que falta.
- **Cualquier otra cosa** —el DTE, el seguimiento, algún maestro del catálogo—:
  **sí se instala**, y la página siguiente dice qué va a quedar fuera. Una base
  sin las tablas del DTE sirve perfectamente para vender; lo honesto es decirlo,
  no negarse a instalar.

Desde el servidor, el detalle completo:

```
C:\xampp\php\php.exe artisan ventas:compatibilidad --todo
```

---

## 5. Traer la app al servidor

Un servidor recién instalado **no tiene el APK**: el instalable no viaja en el
repositorio, viaja en la publicación. Desde la consola, en la carpeta del
proyecto:

```
C:\xampp\php\php.exe artisan ventas:actualizar --apk
```

Trae el APK **de esta misma versión** —no el último— y lo deja listo en `/app`.
Repartir un APK más nuevo que la API es exactamente el desfase del que la app
se pasa el día avisando.

Después, `/app` enseña el código QR. Esa dirección **no lleva la versión
dentro**: el QR se puede imprimir y pegar en la pared, y sigue sirviendo cuando
salga la versión siguiente.

`/app` es público a propósito: el instalable no lleva credenciales dentro y la
dirección del servidor se escribe al abrirlo por primera vez. Exigir sesión
haría imposible el caso que esto resuelve, que es el vendedor nuevo que todavía
no tiene cuenta.

---

## 6. El primer teléfono

1. Escanea el QR e instala el APK. Android va a pedir permiso para instalar de
   fuera de la tienda; es normal.
2. Al abrir, en **Servidor**, escribe la dirección de la instalación. Es la
   misma de `/setup`, **sin** `/setup` al final.
3. Entra con `softland` y su contraseña del ERP.

> ### La dirección del servidor: la trampa que mata la instalación
>
> Si el servidor se publica hacia fuera con un proxy inverso, y el puerto 80
> responde con una redirección a `https`, **una dirección guardada con `http://`
> deja la app muerta** aunque el navegador del teléfono llegue perfectamente:
> una redirección en la comprobación previa de CORS no se sigue.
>
> La pantalla Servidor prueba los dos esquemas y guarda el que contestó de
> verdad. Aun así: **escribe la dirección sin `http://` delante** y deja que la
> app la resuelva.

---

## 7. Dejar la empresa configurada, desde la app

Todo esto se hace **desde el teléfono**, con el usuario admin. No hay panel web:
`/setup` y `/app` son las dos únicas páginas HTML del proyecto, y existen
porque ocurren antes de que la app exista.

### 7.1 Usuarios

**Cuenta → Usuarios.** Crea cada vendedor, **enlázalo con su código de
vendedor de Softland** y ponle su tope, si van a usarlo. Sin código de vendedor sus documentos no aparecen en las
ventanas de búsqueda del ERP.

Los roles deciden qué ve cada uno y de quién. Un jefe arranca mirando a su
equipo, no a sí mismo: el código de vendedor de un supervisor casi nunca vende.

### 7.2 Identidad de la empresa

**Cuenta → Identidad.** Lo que quede vacío lo hereda de
`softland.soempre`. Aquí va el logo.

> **La oficina comercial y el domicilio tributario son dos campos distintos.**
> El pie de la cotización lleva la primera —a donde va el cliente— y la
> cabecera de la factura la segunda, que tiene que decir lo mismo que el XML
> que recibió el SII.

### 7.3 Correo saliente

**Cuenta → Configuración → Correo saliente.** Ponlo y **manda la prueba** desde la misma
pantalla. Sin SMTP configurado los avisos no salen: quedan en el registro del
servidor, y nadie mira ahí hasta que alguien pregunta por qué no le llegó nada.

### 7.4 Certificado digital — sólo si van a facturar

**Cuenta → Configuración → Certificado digital.** Se sube el `.pfx` y se escribe su
clave. Se comprueba **abriendo el archivo antes de guardar nada**: si la clave
no es ésa, no se escribe y el que estuviera funcionando sigue como está.

Ni el archivo ni la clave vuelven a salir del servidor. Lo que la pantalla
enseña —quién firma, RUT, vencimiento— se deduce del archivo, no se teclea.

Si la app todavía no se puede abrir, desde el servidor:

```
C:\xampp\php\php.exe artisan dte:certificado --archivo=C:\ruta\firma.pfx
```

La clave se pregunta, no se pasa como argumento: así no queda en el historial
de la consola.

### 7.5 Reglas de facturación

**Cuenta → Configuración → Facturación.** Tres llaves de la empresa:

| Llave | Nace | Qué decide |
|---|---|---|
| Envío automático al SII | encendida | Si al emitir se manda, o se deja para después |
| Receptor editable | apagada | Si se puede facturar a un RUT distinto del de la nota de venta |
| Referencias 801 / 802 | las dos encendidas | Si el DTE nombra la orden de compra y la nota de venta |

### 7.6 Orden de compra al proveedor — si la usan

**Cuenta → Configuración → Orden de compra al proveedor.** A quién se le pide y qué atributo de la
nota de venta llena cada hueco del papel. Los atributos los declara cada
empresa en su base: no hay ninguno garantizado, así que esto se elige mirando
lo que la base traiga.

---

## 8. Comprobar que quedó bien

Todo esto se puede correr tantas veces como haga falta. **Nada de esto emite ni
gasta un folio.**

### Desde el servidor

```
C:\xampp\php\php.exe artisan ventas:probe
```
Cuenta lo que hay en la base: cotizaciones, notas de venta, clientes,
productos, vendedores y folios CAF disponibles. Es la foto de que la conexión
sirve de verdad.

```
C:\xampp\php\php.exe artisan ventas:compatibilidad --todo
```
Tabla por tabla y columna por columna. Tiene que acabar en «Esta base sirve
para todo lo que la app hace».

```
C:\xampp\php\php.exe artisan dte:token
```
Sólo si van a facturar. Recorre el camino entero al SII —conexión,
certificado, firma y autorización— y trae un token. **No emite nada.**

```
C:\xampp\php\php.exe artisan ventas:actualizar --comprobar
```
Confirma que el servidor sabe dónde buscar las versiones nuevas.

```
C:\xampp\php\php.exe artisan ventas:huella
```
Qué creó la app en la base y qué ha escrito en las tablas del ERP. Es la
respuesta imprimible para quien administra el SQL Server, y sirve también para
ver de un vistazo si las migraciones quedaron todas puestas. **No escribe
nada.**

Y una comprobación que no es un comando: que el `.env` diga `APP_ENV=production`
y `APP_DEBUG=false`. Con el depurador encendido, **cualquier error de la API
devuelve la traza entera, las rutas del disco y el SQL de la consulta**, a quien
llame y sin estar logueado. `.env.example` ya viene así, pero una instalación
vieja o un `.env` copiado de otro sitio puede traerlo al revés. Medido el
2026-09-24 en la instalación de referencia: el teléfono enseñó en pantalla el
`select` contra `ventas.api_token` con el hash del token dentro.

### Desde el teléfono

- El panel carga con números que no son cero.
- **Cuenta → Acerca de**: la versión del teléfono y la del servidor coinciden.
  Es la primera pregunta de cualquier soporte.
- Tira hacia abajo en una lista: se refresca y avisa cuando acaba.
- Crea una cotización de prueba y bórrala. El número vuelve al pozo.

---

## 9. Opcionales, según el cliente

### Publicar el servidor hacia fuera

Para que los vendedores trabajen fuera de la oficina hace falta publicar la API
con un proxy inverso (IIS con ARR, nginx, lo que tenga el cliente).

Tres cosas que se pagan caras si se olvidan:

- El proxy suele **añadir la carpeta** al reenviar. El servidor no escribe
  ninguna dirección absoluta por eso, pero conviene abrir `/setup` y `/app` por
  el nombre público antes de dar por buena la publicación.
- El puerto 80 que redirige a `https` **rompe la app** si alguien guardó la
  dirección con `http://`. Ver el recuadro del paso 6.
- El certificado del proxy tiene que ser válido: Android no perdona uno
  autofirmado.

### El catálogo de giros del SII

Si van a dar de alta clientes con el RUT, conviene cargar el catálogo ACTECO
vigente. **Enseña lo que haría y no escribe** hasta que se lo pidan:

```
C:\xampp\php\php.exe artisan ventas:carga-giros
C:\xampp\php\php.exe artisan ventas:carga-giros --escribir
```

**No toca una fila que ya exista**: un giro en uso lleva el texto que sus
clientes reconocen y que sale impreso en el `GiroRecep` de sus DTE.

### El alta de clientes desde el SII

Necesita una llave de la API del padrón, en el `.env` del servidor
(`SII_AUX_URL` y `SII_AUX_KEY`). Sin ellas **el botón no se dibuja**: ofrecerlo
y que falle es peor que no ofrecerlo. El formulario se llena a mano igual, y el
alta sin señal sigue funcionando.

### El repaso de los documentos enviados al SII

`dte:pendientes` consulta el veredicto del SII de lo emitido y reintenta lo que
no salió. Conviene dejarlo en una tarea programada de Windows, cada 15 o 30
minutos.

---

## 10. Actualizar, de aquí en adelante

Desde la app, con rol admin: **Cuenta → Configuración → Versión del servidor**. Dice qué
versión hay puesta, si hay una nueva y qué trae; y la instala.

Desde el servidor, que es el camino para el día en que la app no abra:

```
C:\xampp\php\php.exe artisan ventas:actualizar --comprobar
C:\xampp\php\php.exe artisan ventas:actualizar
C:\xampp\php\php.exe artisan ventas:actualizar --a=0.46.0
```

- **No toca `.env` ni `storage/`.** Ahí viven la conexión cifrada, el
  certificado, los PDF y los APK repartidos.
- **Nunca se actualiza solo.** Siempre lo manda una persona.
- Se puede **volver atrás** a una versión anterior, que sigue publicada.
- Si tarda más que el plazo del teléfono, el servidor sigue trabajando: la
  pantalla vuelve a preguntar por el estado hasta que diga en qué quedó.

---

## 11. Si algo falla

| Lo que se ve | Lo que pasa | Qué hacer |
|---|---|---|
| `/setup` sale sin formulario | Al servidor le falta algo | Está en la lista de la propia página, con el arreglo |
| `could not find driver` | Falta `pdo_sqlsrv` | Copiar el driver de Microsoft para esa versión de PHP y añadir `extension=pdo_sqlsrv` a `php.ini` |
| `Unsupported cipher` | Falta la `APP_KEY` | `php artisan key:generate` |
| «No se pudo conectar a SQL Server» | Instancia, credenciales o TCP | Probar la misma cadena con el Management Studio en el mismo equipo |
| «Solo el usuario administrador `softland`…» | Se escribió otro usuario | Es a propósito: sólo `softland` instala |
| La dirección abre en el navegador pero la app no entra | Se guardó con `http://` y el 80 redirige | Volver a Servidor y escribirla sin esquema |
| `/app` dice que no hay instalable | Instalación recién hecha | `php artisan ventas:actualizar --apk` |
| Android guarda el APK en vez de ofrecer instalar | Se bajó por una ruta que no es `/app/apk` | Usar el QR o el botón de la página |
| Cuenta avisa de versiones distintas | El APK y la API no van a la par | Actualizar el servidor, o repartir el APK que toca |
| No llega ningún correo | SMTP sin configurar | Cuenta → Configuración → Correo saliente, y mandar la prueba |
| Al emitir: «No hay certificado digital…» | No se ha subido | Cuenta → Configuración → Certificado digital |

Cuando la conexión guardada deja de servir —cambió la instancia, se renombró la
base, se rotó la contraseña de SQL—, **`/setup` se reabre solo**. Es la única
salida: para entrar en la app hace falta el token, y el token está en
`ventas.api_token`, justo al otro lado de la conexión rota.

---

## 12. Lo que la app **no** hace

Conviene decirlo el día uno, no el día que alguien lo busca:

- **No centraliza.** Contabilidad, registro de ventas y cuenta corriente del
  cliente son un procedimiento aparte que se corre desde Softland. La app llega
  hasta inventario y facturación, con el DTE emitido, y para ahí.
- **No emite boleta.** Está preparada y probada, pero bloqueada: necesita
  folios CAF de boleta del RUT de la empresa, y la boleta viaja por una
  integración distinta de la factura.
- **No emite Liquidación-Factura (DTE 43).** Softland trae el ciclo del
  mandante como documento propio, con sus formularios y sus cuentas.
- **No hace notas de crédito parciales.** Sólo anulación completa. Corregir
  texto o montos es otro documento y se hace desde el ERP.
- **No tiene metas de venta propias de Softland**, porque Softland no las
  tiene: la meta es un dato de la app, y sin meta cargada el widget no aparece.

---

## Apéndice · La huella en la base

Esto es lo que hay que poder contestar cuando el cliente pregunta qué se le
instaló dentro de la base donde factura. Todo lo de aquí se vuelve a sacar en
cualquier momento, del servidor y sin escribir nada:

```
C:\xampp\php\php.exe artisan ventas:huella
```

### La estructura del ERP no se toca

Ni una tabla de Softland modificada. Ni una columna añadida, ni un trigger, ni
un índice, ni una clave foránea. Las migraciones del proyecto crean objetos
**sólo** en el esquema `ventas`; ninguna hace `ALTER` sobre `softland`.

Eso incluye la única vista del proyecto: `ventas.nv_atributo_valor` **lee**
`softland.nw_nventa` y sus tres tablas de atributos, pero vive en `ventas`. El
esquema del ERP no se toca ni para añadir una vista.

Y no hay **ninguna clave foránea que cruce** entre los dos esquemas, también a
propósito: `ventas.giro_sii` apunta a `softland.cwtgiro` por el código, sin
restricción declarada. La consecuencia es la que importa — quitar la app es
borrar el esquema `ventas`, y eso no puede arrastrar nada del ERP.

### Lo que sí se crea: un esquema propio, 14 tablas y una vista

| Objeto | Cols | Qué guarda |
|---|---|---|
| `ventas.usuario` | 20 | Los vendedores de la app y su código de Softland |
| `ventas.api_token` | 8 | Las sesiones de los teléfonos |
| `ventas.config` | 4 | Identidad de la empresa y reglas de negocio |
| `ventas.notificacion_regla` | 10 | Qué se avisa y a quién |
| `ventas.notificacion` | 11 | El buzón de la campana |
| `ventas.documento_app` | 8 | `client_uuid` → documento. Es la idempotencia |
| `ventas.aprobacion` | 13 | Quién aprobó qué, y cuándo |
| `ventas.documento_emision` | 15 | Cada versión de PDF entregada al cliente |
| `ventas.linea_origen` | 10 | Cotización → nota de venta, línea a línea |
| `ventas.cotizacion_avance` | 7 | El avance comercial, que en Softland no existe |
| `ventas.giro_sii` | 5 | ACTECO del SII → giro histórico de Softland |
| `ventas.sii_auxiliar` | 6 | Caché de las consultas al padrón del SII |
| `ventas.codigo_barras_app` | 5 | Qué códigos de barras aprendió la app, y de quién |
| `ventas.migrations` | 3 | Control de versiones del propio esquema |
| `ventas.nv_atributo_valor` | 7 | **Vista.** Los atributos de la nota de venta, unificados |

Se crean solas al instalar —lo hace `/setup`, paso 4— y volver a correrlo no
duplica nada.

### Filas: la app sí escribe en tablas del ERP

No es una modificación de estructura, pero es lo que de verdad cambia la base
del cliente, y conviene decirlo con el mismo detalle:

| Dónde | Cuándo | Cuenta |
|---|---|---|
| `nwcotiza` · `nwdetcot` | Al crear una cotización | `ventas.documento_app` |
| `nw_nventa` · `nw_detnv` | Al crear una nota de venta | `ventas.documento_app` |
| `nwtsegui` | Al anotar un compromiso | — |
| `iw_gsaen` · `iw_gmovi` · `dte_*` | Al emitir una factura o nota de crédito | `Proceso = 'Venta Softland'` |
| `cwtauxi` | Al dar de alta un cliente | Por RUT |
| `cwtgiro` | Sólo si se corre `ventas:carga-giros --escribir` | Ver abajo |
| `iw_tprod.CodBarra` | Al enseñarle un código de barras leído con la cámara | `ventas.codigo_barras_app` |

Son documentos y maestros de la empresa, escritos como los escribe el Softland
de escritorio: indistinguibles, y se anulan o se borran desde el ERP como
cualquier otro.

La cotización y la nota de venta **no estampan** la columna `Proceso`, así que
filtrar por `Proceso = 'Venta Softland'` sólo sirve para el documento de venta.
Quién creó qué lo dice `ventas.documento_app`, que es el mapa de idempotencia y
la cuenta buena.

`iw_tprod` es el único maestro del que la app **modifica una fila existente**, y
sólo una columna: `CodBarra`, cuando alguien lee con la cámara un código que
todavía no estaba y dice de qué producto es. Cinco reglas lo acotan: sólo si esa
columna está **vacía** —lo que ya tiene código se escanea hoy desde el
escritorio y no se pisa—, sólo si **no lo tiene otro producto** —la columna no
lleva índice único, así que la base aceptaría el duplicado sin avisar—, un
máximo de **20 caracteres**, **ninguna otra columna** (ni `Proceso`, ni
`Usuario`, ni `FechaUlMod`), y **quién y cuándo anotados en `ventas`**, porque
`iw_tprod` no guarda autor. Se puede vivir sin esto: el maestro se llena igual
desde Softland, y basta con no usar el escáner.

### El caso aparte: el catálogo de giros

`ventas:carga-giros --escribir` inserta en `softland.cwtgiro` los ACTECO
vigentes que falten —674 códigos, de los que en una base típica ya están unos
pocos—. Es la **única escritura de la app en un maestro del ERP fuera del flujo
de venta**, y por eso es un comando manual y no parte de la instalación: lo que
se mete ahí lo ve el administrativo en su desplegable del escritorio, y que ese
maestro crezca tiene que ser una decisión de alguien, no el efecto colateral de
que un vendedor tecleara un RUT.

**No pisa ni una fila que ya exista**, ni siquiera la descripción: un giro en
uso lleva el texto que sus clientes reconocen y que sale impreso en el
`GiroRecep` de sus DTE. Y no borra nunca, porque `cwtauxi.GirAux` tiene clave
foránea contra esa tabla.

### Si hubiera que quitarlo

Se borra el esquema `ventas` entero y la base queda como estaba. Los documentos
que la app haya escrito en las tablas del ERP se quedan, que es lo correcto: son
documentos de la empresa.

---

## Resumen en una pantalla

```
1.  Copiar el código a C:\xampp\htdocs\<carpeta>
2.  bin\instalar.cmd
3.  Añadir el Include a httpd.conf  +  reiniciar Apache
4.  Abrir /setup y rellenar los siete campos
5.  php artisan ventas:actualizar --apk
6.  Escanear el QR de /app, instalar, escribir la dirección, entrar
7.  Cuenta → Usuarios · Identidad · Configuración (correo, certificado, facturación)
8.  ventas:probe · ventas:compatibilidad --todo · ventas:huella · dte:token
```

Nada de esto pide editar un archivo en el servidor, y ninguna contraseña se
escribe en disco a mano.
