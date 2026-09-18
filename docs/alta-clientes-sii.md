# El alta de clientes desde el SII

> Plan y evidencia. Todo lo que aquí se afirma está medido contra el padrón
> público del SII y contra `INNOVAGES`; lo que no se sabe está dicho como tal.

## De qué va esto

Dar de alta un cliente en la app es escribir a mano razón social, dirección,
comuna, ciudad, giro y correo para documentos tributarios. Son seis campos, tres
de ellos códigos de maestros grandes, y el vendedor los está tecleando de pie en
la oficina del cliente.

El SII publica todo eso. Hay una API propia —`sii-aux.netdomain.cl`— montada
sobre los padrones públicos, y la idea es que se escriba el RUT y el formulario
aparezca lleno, para que la persona **complete y corrija** en vez de transcribir.

No es sólo comodidad al dar de alta. De los 3.373 clientes de INNOVAGES:

| Campo vacío | Clientes |
|---|---|
| Correo DTE | **1.211** |
| Ciudad | 780 |
| Comuna | 721 |
| Giro | 631 |
| Dirección | 527 |

## La API

```
GET https://sii-aux.netdomain.cl/api.php?rut=76469595-k&key=…
```

Responde en ~0,15 s, con códigos HTTP honestos: `200` con `encontrado:true`,
`404` con `encontrado:false`, `401` si la llave está mal y `400` si falta el
RUT. Acepta el RUT con o sin dígito verificador.

Devuelve `razon_social`, `direccion`, `comuna`, `ciudad`, `region`, `giro`,
`giro_codigo`, `giros_todos_detalle[]`, `correo_dte` y `fecha_actualizacion`.

Tres cosas que hace bien y que no son evidentes:

- **El acteco viaja como texto.** Hay 94 códigos que empiezan por cero
  (`011101`…). Como número, `011101` se convierte en `11101`, que existe y
  significa otra cosa en la lista anterior del SII.
- **Filtra por vigencia.** 1.150.832 filas del padrón de direcciones están en
  `VIGENCIA=N`. Probado con RUT que sólo tienen direcciones no vigentes:
  devuelve `null`, no la dirección vieja.
- **Prefiere `DOMICILIO` sobre `SUCURSAL`.** Es lo que tiene que ir como
  receptor en el DTE. Probado con el 77079927, que tiene las dos.

### El teléfono no la llama

La llama el servidor, con un `GET /clientes/sii/{rut}` propio. Cuatro razones y
la primera basta:

1. **La llave no puede ir en el APK**, que se descompila. Va en el `.env` de
   `srv`, con el resto.
2. El servidor es **el único que puede traducir** el texto del SII a códigos de
   Softland: es quien tiene `cwtgiro`, `cwtcomu` y `cwtciud`.
3. Si la API cambia o se cae, se arregla en un sitio y sin repartir APK.
4. El WebView tendría que resolver CORS y certificado contra un tercer dominio.

### Lo que le falta

Arma la dirección con `CALLE + NUMERO` y **descarta `DEPARTAMENTO`, `BLOQUE` y
`VILLA_POBLACION`**, que son columnas propias del padrón: para un cliente en un
edificio, la dirección llega sin el departamento.

Dos detalles menores: `razon_social` puede venir `null` con el resto lleno (pasa
en el 53336044), y el padrón de direcciones trae una **línea de encabezado
repetida dentro de los datos**.

## Lo que no se puede arreglar: el SII recorta en origen

No es cosa de la API. El padrón guarda los campos ya cortados:

- `CIUDAD` a **15 caracteres**: «ESTACIÓN CENTRA», «SAN PEDRO DE LA».
- `CALLE` admite 69, pero hay direcciones guardadas cortas de fábrica: la del
  76469595 dice literalmente `KM 1  CAMINO   SANTA  BAR` — es Santa Bárbara.
- `razon_social` llega hasta 80, y `cwtauxi.NomAux` admite 60. En una muestra
  de 53 clientes, 2 no caben.

Por eso **lo que trae el SII se propone y lo confirma una persona**, y por eso
la pantalla tiene que **enseñar el recorte** en vez de hacerlo callada.

## La traducción, que es el trabajo de verdad

El SII habla en castellano y `cwtauxi` guarda claves foráneas. Vive entero en
`app/Services/Sii/Traduccion.php`, y `ventas:verifica-sii` lo contrasta.

### La comuna: calza, con once excepciones

De los **347** nombres de comuna del padrón vigente, **331 calzan** con
`cwtcomu` — el **98,48 %** de los 3.604.762 domicilios vigentes.

De las 16 que no: once son la misma comuna escrita de otra manera y están en la
constante `Traduccion::COMUNAS`; «Sin Comuna» es un marcador y no un sitio; y
**Cholchol no está en Softland**, así que devuelve `null` y la elige el
vendedor.

Tres de esos alias son faltas de ortografía **de Softland**, no del SII —«Ista
de Pascua», «Quelén», «Treguaco»— y se respetan: corregir un maestro del ERP es
otra conversación.

Dos casos se resuelven sin alias, por cómo normaliza: «TIL-TIL» contra «Tiltil»
y «OHIGGINS» contra «O'Higgins». La normalización **quita** la puntuación en vez
de cambiarla por un espacio, precisamente por esos dos.

### Los duplicados a mano, que son la trampa

`cwtcomu` no es sólo la lista oficial: tiene ocho filas añadidas a mano, con
código inventado y el nombre mal escrito.

```
CPN      «CONCPECION»        VITACUR  «VITAVURA»
CCC      «CONDES»            PUDAHUE  «PDH»
HUA      «HUALPEN»           PLC      «PADRE LAS CASA»
ESTACIO  «ESTACION CENTRAL»  ALTO     «ALTO BIO BIO»
```

Estación Central está **dos veces**: `13106` y `ESTACIO`. Por eso, cuando dos
filas se llaman igual, **gana la del código numérico**, que es el del INE. Sin
esa regla los clientes nuevos se repartirían entre la comuna buena y su
duplicado, y los informes de Softland que agrupan por comuna dejarían de sumar.

Las otras siete no colisionan, porque están mal escritas de otra manera. Y en
dos casos la fila a mano es **la única que hay** —Hualpén y Alto Biobío no están
en la lista oficial de `cwtcomu`—, así que usarla es lo correcto:

```
VITACURA    -> 13132 «Vitacura»          HUALPEN      -> HUA  «HUALPEN»
CONCEPCION  -> 08101 «Concepción»        ALTO BIOBIO  -> ALTO «ALTO BIO BIO»
EST CENTRAL -> 13106 «Estación Central»  CHOLCHOL     -> null
```

### La ciudad: falta un tercio de las veces

El SII la manda vacía en **18 de cada 53** fichas. Cuando no hay, se prueba con
**el nombre de la comuna**, que en Chile suele ser el mismo y así está en
Softland: comuna «Los Angeles» → ciudad `LANGE`, comuna «Santiago» → `STGO`.

Para desempatar nombres repetidos se usa la **región de la comuna ya resuelta**;
si sigue habiendo dos, se devuelve `null` en vez de elegir a cara o cruz. En
`INNOVAGES` resulta que no hace falta: las 937 ciudades tienen nombre único.

### El giro: por código, nunca por texto

Éste es el que más cuidado pide y el que menos código tiene.

**Hay dos listas de ACTECO, y Softland trae la vieja.** `sii_tacteco` son 698
códigos y **696 figuran como `ActEcoAntigua`** en
`dte_siicodigosactecohomologados`: es la lista anterior a la renumeración. Las
dos comparten números con significados distintos:

```
702000  sii_tacteco (Softland)  ->  CORREDORES DE PROPIEDADES
702000  lista nueva             ->  Actividades de consultoría de gestión
```

Por eso **`sii_tacteco` no se consulta nunca**. El catálogo vigente son 674
códigos de seis dígitos y vive en `resources/sii/actecos.tsv`, extraído de
`PUB_NOM_ACTECOS`.

Y por eso el mapa es del **código** y no del texto: el padrón reescribe
descripciones de una tanda a otra sin tocar el código, y una búsqueda por texto
que deja de encontrar no falla — acierta otra cosa, y esa otra cosa se imprime
en el `GiroRecep` del DTE.

> La correspondencia texto ↔ código es uno a uno (0 descripciones repetidas, 0
> códigos repetidos en los 674), así que traducir por texto **funcionaría** hoy.
> No se hace por lo de arriba: funciona hasta que deja de hacerlo en silencio.

## La decisión que queda: cargar `cwtgiro`

De 35 actecos que el SII devolvió para una muestra de clientes reales, **sólo 5
existían en `cwtgiro`**. A escala completa es peor: **16 de 674**, el 2,4 %.

Sin cargar el catálogo, seis de cada siete clientes nuevos entran con el giro
vacío.

Hay dos vías y se usan las dos:

1. **Cargar los 674 actecos en `cwtgiro`** con el código como `GirCod`
   (`varchar(6)`, y los actecos son de seis: entra justo). Es lo que alguien ya
   empezó a mano: los **16** giros de seis dígitos que hay hoy son actecos, y
   los 16 son de la lista nueva.
2. **`ventas.giro_sii`**, para cuando el acteco tenga que apuntar a un giro
   histórico de los 1.041 que ya usan clientes, en vez de estrenar fila. Manda
   sobre lo anterior.

La carga toca `cwtgiro`, que es un maestro de Softland compartido con el
escritorio, así que **es una migración deliberada y revisada, no un efecto
colateral** de que un vendedor teclee un RUT. El alta nunca escribe en
`cwtgiro`: sólo busca.

Ojo con la descripción: **239 de los 674 no caben en `GirDes(60)`**. Se recortan
cortando en palabra y **quitando la conjunción que queda colgando**: «…TRIGO,
MAIZ, AVENA Y» se lee como si faltara texto por un fallo, y «…TRIGO, MAIZ,
AVENA» se lee como lo que es, una frase cortada. Y aguas abajo, `dte_doccab.GiroRecep` es
`varchar(40)`: **462 de 674** se pasan. Eso ya ocurre hoy sin nosotros — el giro
más largo de los 286 DTE emitidos está cortado en los 40 exactos.

## El flujo

1. Se escribe el RUT. **El DV se valida en el teléfono**: un tecleo mal no gasta
   una consulta.
2. **Primero se mira Softland, no el SII.** Si el RUT ya es cliente se abre su
   ficha. Eso mata el alta duplicada antes de empezar.
3. Si no existe, botón **«Buscar en el SII»**. Sin señal sale apagado y el
   formulario se llena a mano como hoy: **la búsqueda nunca es requisito para
   dar de alta**, y la bandeja de salida sigue funcionando con el RUT como
   clave.
4. Llega la ficha: nombre, dirección, comuna, ciudad y correo DTE llenos; el
   giro, propuesto. Todo editable, y lo que vino del SII **marcado**, para que
   se distinga de lo que escribió la persona.
5. `encontrado:false` no es un error: es «el SII no lo tiene» —personas
   naturales y empresas nuevas no están— y se dice así.
6. Contactos y días de plazo se escriben como hoy: eso el SII no lo sabe.

La respuesta se guarda en `ventas.sii_auxiliar` con su `consultado_en`: el
segundo intento no vuelve a salir a internet, queda rastro de qué propuso el SII
frente a qué se guardó, y si la API está caída se responde con lo último
conocido, diciéndolo.

## Lo que no se hace

- **No se rellena `dias_plazo`** ni se marca `esReceptorDTE` sin que alguien lo
  mire.
- **No se da de alta solo.** La API propone, la persona da de alta. El padrón
  traerá un dato viejo algún día —se actualiza por tandas— y ese día quieres que
  hubiera un humano mirando.
- **No se actualiza en masa lo que ya existe.** Ocho clientes de la muestra
  tienen comuna distinta y el SII tiene razón: donde Softland dice «Concepción»,
  el SII dice Hualpén, Chiguayante, Talcahuano, Coelemu. Pero la dirección de un
  cliente antiguo puede ser **la oficina comercial puesta a propósito**, y el
  SII da el domicilio tributario. Es la misma trampa de la identidad de la
  empresa, un nivel más abajo: proponer y que alguien mire.
- **No se usa `afecta_iva`.** La API lo manda, pero aquí los impuestos los
  decide el maestro de productos en el servidor, nunca la ficha del cliente.

## Medido

Muestra de 60 clientes reales de `INNOVAGES`, contra la API:

| | |
|---|---|
| Encontrados en el SII | **53 de 60** (88 %) |
| Con `giro_codigo` | 40 de 53 — y **0 con texto sin código** |
| Sin giro ninguno | 13, casi todos personas naturales |
| Correo DTE que aporta | 46 de 53, **6 nuevos** para Softland |
| Razón social que no cabe en `NomAux` | 2 de 53 |
| Comuna distinta de la que tiene Softland | 8 (y el SII tiene razón) |

`ventas:verifica-sii` sobre `INNOVAGES`:

```
Alias de comuna                                  11 de 11
Comunas que se traducen a sí mismas             352 de 352
Ciudades que se traducen a sí mismas            937 de 937
Catálogo ACTECO                                 674 de 674
Cobertura del giro                              674 de 674 (100 %)
```

## El plan, por pasos

### Paso 1 — La traducción ✅ hecho (0.36.0)

`app/Services/Sii/Traduccion.php`, el catálogo en `resources/sii/actecos.tsv`,
la tabla `ventas.giro_sii` y `ventas:verifica-sii`.

### Paso 2 — Cargar `cwtgiro` ✅ hecho (0.37.0)

`ventas:carga-giros`. Ensayado en `INNOVAGES_DTE` y después corrido en
`INNOVAGES`: **2.009 → 2.667 giros**, y la cobertura del **2,4 % al 100 %**.

Tres reglas del comando:

- **No escribe si no se lo piden.** Sin `--escribir` enseña lo que haría y se
  va. `cwtgiro` lo ve el administrativo en su desplegable del escritorio: que
  crezca tiene que ser una decisión, no un efecto colateral.
- **No toca ni una fila que ya exista, ni su descripción.** Los 16 actecos que
  ya estaban se quedaron como estaban — comprobado: 0 filas modificadas. Un giro
  que ya usan clientes tiene el texto que esa gente reconoce, y que sale impreso
  en el `GiroRecep` de sus DTE.
- **No borra nunca**: `cwtauxi.GirAux` tiene clave foránea contra esta tabla.

Comprobado después de cargar, en la copia y en producción: **0 auxiliares con
`GirAux` huérfano**, los 94 códigos con cero a la izquierda guardados con sus
seis caracteres, y `GirDes` en 60 como máximo.

### Paso 3 — La consulta

`app/Services/Sii/Auxiliar.php` (cliente HTTP, timeout corto, llave en el
`.env`), la caché en `ventas.sii_auxiliar` y `GET /clientes/sii/{rut}` en
`ClienteController`, que es quien ya manda en el alta.

### Paso 4 — La pantalla

Botón «Buscar en el SII», sellos de procedencia por campo, aviso de recorte del
nombre y elección entre los `giros_todos_detalle` de la empresa.

### Paso 5 — Los clientes que ya existen

«Actualizar desde el SII» sobre la ficha abierta, para los 1.211 sin correo DTE.
Proponiendo, nunca pisando lo que escribió una persona.

## Lo que no se sabe todavía

- **Cuántas empresas quedan fuera.** 53 de 60 en la muestra, pero no está medido
  qué parte de los 7 que faltaron son personas naturales (a las que el padrón de
  personas jurídicas no llega) y qué parte son empresas que deberían estar.
- **Si la API sobrevive a un padrón nuevo.** `fecha_actualizacion` cambió de
  2026-09-17 a 2026-09-18 durante esta misma revisión, así que se recarga; lo
  que no se ha visto es un cambio de formato del SII.
- **Cómo le sienta al Softland de escritorio** tener 2.667 giros en el
  desplegable en vez de 2.009. Se cargaron los 674 enteros porque cargar «sólo
  los que se usen» exige saber de antemano a quién se le va a vender, que es
  justo lo que no se sabe. Si estorba, se revierte borrando los actecos que
  ningún auxiliar use.
