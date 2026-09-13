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

## Estado actual
Ver `STATE.md`. El mapa de tablas del flujo de ventas está en
`docs/flujo-ventas-softland.md` y el plan por fases en `docs/roadmap.md`.
