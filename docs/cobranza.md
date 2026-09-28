# Cobranza y recaudación

> Lo medido aquí es contra la base de producción `INNOVAGES`, los días
> 2026-09-27 y 2026-09-28. Lo que no está medido se dice que no lo está.
> La consulta de proceso que originó esto está en `cobranzas-consulta.md`;
> esto es la decisión y el plan.

## 1. Qué es cobrar, aquí

Cobrar es **bajar el saldo de la cuenta corriente del cliente en
contabilidad**. No es un registro propio que después alguien concilia: es el
mismo comprobante de ingreso que hoy teclea contabilidad, escrito por la app.

El paso siguiente —cobrar en el acto al emitir la factura— es otro camino y no
está aquí. Esto es la cartera: documentos emitidos hace semanas que hay que ir
a cobrar.

## 2. Dónde vive el saldo, y por qué no en el módulo de clientes

Softland trae un módulo de clientes y cobranza, las tablas `xw*`. **En
INNOVAGES está instalado y vacío**: `xwcobranza` no tiene ninguna fila. Y no es
un defecto de esta empresa — es el caso normal cuando se factura desde
inventario y se centraliza a contabilidad.

El saldo de verdad está en la **cuenta corriente contable**, en `cwcpbte`
(cabecera del comprobante) y `cwmovim` (sus movimientos). La regla de partida
abierta, medida:

```sql
saldo = SUM(MovDebe - MovHaber)
GROUP BY CodAux, MovTipDocRef, MovNumDocRef
-- y sólo sobre comprobantes con CpbEst = 'V'
```

**El filtro por `CpbEst = 'V'` no es un detalle.** Un comprobante en `P` es un
borrador, y Softland **le conserva los movimientos**: los 8 documentos que
aparecían «pagados de más» —5.868.534 de sobrepago— son exactamente los que
arrastraban dos borradores que nadie borró (`2024-00009000` y `2026-00004009`).
Contando sólo los vigentes, la cartera abierta de INNOVAGES son **27 documentos
y 11.424.249**, sin un solo saldo negativo.

Decisión: **la app escribe siempre en CW y nunca depende del módulo `xw`.** Una
empresa que sí lo tenga configurado seguirá funcionando —el comprobante de
ingreso es el mismo—; una que no, también. Depender de `xw` habría sido
condicionar la cobranza a un módulo que la mitad de las instalaciones no usa.

## 3. La forma del asiento, medida contra 114 comprobantes

Un comprobante de ingreso (`Sistema='CW'`, `CpbTip='I'`) tiene dos clases de
fila:

- **Al haber, una por cada documento que se abona.** Lleva la cuenta corriente
  del cliente (`iwparam.CtaCliente`), el cliente en `CodAux`, el instrumento de
  pago en `TtdCod`/`NumDoc` y **el documento pagado en
  `MovTipDocRef`/`MovNumDocRef`**. Esta última pareja es la que baja el saldo:
  es por la que agrupa la partida abierta.
- **Al debe, una por cada instrumento de pago.** Lleva la cuenta del medio —el
  banco, la caja—, `CodAux` en ceros y el importe igual a **la suma de lo que
  ese instrumento abonó**. El dinero no es de nadie: por eso no lleva auxiliar.

**El haber va delante de su debe**, y los dos se enlazan por `TtdCod`/`NumDoc`
contra `TipDocCb`/`NumDocCb`. De los 82 comprobantes con movimientos, 67 llevan
exactamente ese orden; el resto son egresos y traspasos, que no son esto.

### Las dos fechas de la fila del haber no son la misma fecha

Es el error fácil. `MovFe` es la **emisión del documento que se paga** y
`MovFv` es la **fecha del pago** —la del instrumento, no la del comprobante—.
Medido sobre las 185 filas de abono a factura electrónica: `MovFv` **nunca**
coincide con el vencimiento de la factura (0 de 185) y sí es constante dentro
de cada instrumento (147 de 149). Un cliente que transfiere el día 1 y a quien
contabilidad le arma el comprobante el día 20 tiene que quedar con el día 1.

### El correlativo

`CpbNum` no es IDENTITY y no hay tabla de correlativos. Son ocho caracteres,
`'000' + MM + NNN`, y es **`MAX + 1` dentro del año y del prefijo de mes**. La
serie **la comparten los tres sistemas que escriben ahí** (CW, IW y PW):
contar sólo los nuestros daría un número ya usado. Se toma bajo
`UPDLOCK, HOLDLOCK` dentro de la transacción, como el de la cotización.

Medido: 32 de los 35 grupos van contiguos desde `MM000`, y los tres que no
tienen **huecos** —comprobantes borrados—, nunca repetidos. Reconstruyendo el
número de los 407 comprobantes existentes con esta regla, salen 404 iguales; los
3 restantes llevan uno posterior, que es justo lo que deja un borrado.

Ojo con dos cosas que parecen correlativos y no sirven:

- **`CpbMes` puede no coincidir con el prefijo del número** (5 filas en
  INNOVAGES). Manda el prefijo, que es parte de la clave primaria.
- **`CpbNui` es otro correlativo, por año, mes y sistema, y no es único**: IW y
  PW lo dejan en cero siempre. Se reproduce porque el ERP lo escribe; nada se
  busca por él.

### Lo que hacen los disparadores, y lo que no

- `CWMovim_CWCpbte_ITRIG` **exige que la cabecera exista** antes de insertar un
  movimiento. Primero `cwcpbte`, después `cwmovim`.
- `CWCpbte_CWMovim_DTRIG` **borra los movimientos al borrar la cabecera**. Es la
  misma regla que en el resto del ERP: el barrido lo hacen los triggers.
- `Cwcpbte_LogCwcpbte_ITRIG` anota en `LogCwcpbte` y lee `TipoLog` de la propia
  fila; si no se nombra la columna, registra `'E'`.

## 4. De dónde salen las cuentas: de `iwparam`, no de una pantalla

Casi nada se pregunta. `iwparam` —el parámetro del módulo de facturación, que
existe en cualquier instalación que facture— ya trae el mapa completo de forma
de pago a cuenta contable. En INNOVAGES, las dos cuentas del comprobante real
`2026-00009000` salen de ahí sin tocar nada: `CtaCliente` = 1-01-03-001 al
haber y `CtaPagoTf` = 1-01-02-003 al debe.

| Medio | Columna de `iwparam` | Tipo (`cwttdoc`) | INNOVAGES |
|---|---|---|---|
| Efectivo | `CtaPagoEfec` | `EF` | 1-01-01-001 |
| Cheque al día | `CtaPagoChDia` | `CH` | 1-01-01-001 |
| Cheque a fecha | `CtaPagoSAChFec` | `CH` | 1-01-04-003 |
| Transferencia | `CtaPagoTf` | `TR` | 1-01-02-003 |
| Tarjeta de débito | `CtaPagoTDb` | `TD` | 1-01-04-002 |
| Tarjeta de crédito | `CtaPagoTCr` | `TC` | vacía |
| Depósito | — | `DP` | vacía |
| Pago en línea | — | `PL` | vacía |

Lo que `iwparam` deja vacío se completa en `ventas.config`, clave `cobranza`.
La regla es la de la identidad de la empresa heredando de `soempre`: **el ERP
manda y nosotros sólo rellenamos el hueco**; campo en blanco = vuelve a mandar
`iwparam`. Y no se escribe en `iwparam`: de las tablas de Softland esta app
escribe una columna, `CodBarra`, y ninguna más.

**Nada de esto baja al teléfono.** El teléfono manda «forma de pago =
transferencia» y el servidor resuelve la cuenta — la misma regla que «el
teléfono no decide impuestos». Una cuenta contable dentro de un APK que se
descompila no pinta nada, y guardarla en el aparato obligaría a reconfigurar
cada teléfono cada vez que cambie, y otra vez tras cada reinstalación.

Las cuentas se comprueban contra `cwpctas` antes de cobrar, no después: que
existan, que sean de último nivel y que la del cliente admita auxiliar
(`PCAUXI='S'`). Un asiento contra una cuenta que no acepta auxiliar es un abono
sin dueño.

## 5. Lo que ya está comprobado

`cobranza:verifica-comprobante` arma los comprobantes de ingreso que **ya
existen** y los compara con lo escrito, sin tocar la base:

```
reproducidos idénticos      : 42
idénticos salvo las erratas : 25   (del propio comprobante)
no son un cobro de cartera  : 15
borradores sin movimientos  : 32
DIFERENCIAS NUESTRAS        : 0
```

Las 25 «erratas» son del dato, no del molde, y se comprueban una a una en la
fila real antes de perdonarlas:

- **El número del instrumento tecleado distinto en cada fila.** En
  `2024-00002000` el mismo traspaso aparece como 2223, 223 y 2224 dentro del
  mismo asiento. Es la errata más común, y es exactamente lo que esta app
  elimina: el número se escribe una vez y en un sitio.
- **La fecha del pago distinta entre abonos del mismo instrumento.**
- **Filas con importe cero** que nadie borró, y **borradores con movimientos**.

Los 15 «fuera de molde» no son cobros de cartera: 8 llevan el haber contra otra
cuenta, 4 no tienen las dos mitades, 2 no cuelgan de ningún cliente y 1 es el
asiento de apertura, que abona a seis clientes a la vez.

## 6. El plan

| # | Qué | Estado |
|---|---|---|
| 0 | `cobranza:verifica-comprobante` — reproducir lo existente sin escribir | **hecho** |
| 1 | `Cobranza\Cuentas` — el mapa deducido de `iwparam` y completado en `ventas.config` | **hecho** |
| 2 | La cartera: leer el saldo por documento, sin señal | pendiente |
| 3 | `Recaudacion` — escribir el comprobante en `V`, idempotente por `client_uuid` | pendiente |
| 4 | `Credito` — el tope de `cwtcvcl.MtoCre` y el bloqueo | pendiente |
| 5 | `/setup` en modo reconfigurar | pendiente |
| 6 | Transbank, detrás de su llave de configuración | pendiente |

### Lo que ya está decidido para el paso 3

- **Se escribe en `V`, no en `P`.** Un borrador conserva sus movimientos pero no
  lo ve nadie y descuadra la cartera de quien lo mire mal; los dos borradores
  vivos de INNOVAGES son la prueba. Guardar a medias no es más seguro, es peor.
- **La transacción es una.** Cabecera, movimientos y la fila de
  `ventas.documento_app` que lo enlaza con el `client_uuid`. El correlativo se
  toma dentro, con reintento ante choque de clave primaria.
- **El asiento tiene que cuadrar, y eso se comprueba en el servicio**, no en el
  controlador: un comprobante descuadrado es un problema de contabilidad, no de
  pantalla.
- **Corregir es reversar.** Lo entregado no se edita. Borrar sólo lo que escribió
  esta app, y eso lo dice `cwcpbte.Proceso`.

### Lo del paso 4, que hay que decir claro

El tope de crédito está en **`cwtcvcl.MtoCre`**, no en la ficha del cliente.
Son 2.674 filas y sólo 11 con `MtoCre > 0`. **`parBloqCantDias` no se usa para
nada**: lo mueve un motor de bloqueo automático del ERP y leerlo desde aquí es
meterse en un proceso que no es nuestro.

`nwparam` dice si la empresa quiere que la nota de venta se frene:
`CheckApruebaBloqueado` y `CheckApruebaSobregirado`, hoy los dos en `'N'`.
