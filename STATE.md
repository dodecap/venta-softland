# Estado del proyecto — Venta Softland

> Se actualiza al final de cada sesión. Es lo primero que hay que leer al
> retomar el proyecto, desde este u otro computador.

## Última actualización
2026-09-13

## Resumen del estado actual
**Fase 1 terminada y desplegada.** El servidor (API Laravel) está en
`srv:C:\xampp\htdocs\venta-softland` con las dependencias instaladas y las 25
rutas registradas. El APK compila y está en `venta-softland.apk`.
Falta un paso manual para que la API quede accesible: publicarla en Apache.

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

## Pendiente / próximos pasos
- [ ] **Publicar la API en Apache** (ver «Problemas conocidos»).
- [ ] Correr `/setup` en el navegador y crear los primeros vendedores.
- [ ] Configurar el SMTP desde la app y mandar un correo de prueba.
- [ ] Empezar la fase 2 (catálogos offline). Ver `docs/roadmap.md`.
- [ ] **Solicitar al SII los folios CAF de boleta electrónica (DTE 39)** — es
      el bloqueo de plazo más largo del proyecto, conviene iniciarlo ya.
- [ ] Averiguar si la API REST oficial de Softland (`Softland.DteClient`) está
      disponible para esta instalación, antes de diseñar la fase 4.

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

## Problemas conocidos / bloqueos
- **La API todavía no se sirve por HTTP.** El archivo
  `conf/extra/venta-softland.conf` ya está subido a `srv`, pero falta agregar
  `Include conf/extra/venta-softland.conf` en `httpd.conf` (junto a la línea
  de `rinde-caja.conf`) y recargar Apache. No lo hice porque ese Apache también
  sirve SEMCO-SGC, rinde-caja y dte-xml, y tocarlo es decisión del usuario.
- **Boleta electrónica sin folios.** No hay CAF para el DTE 39 ni el 41 en
  `dte_siicaf`. El tipo `BE` existe en `cwttdoc`, así que Softland está
  preparado, pero sin folios no se puede emitir.
- **Mapeo tipo Softland → tipo SII para ventas, sin resolver.** En `cwttdoc`
  los códigos de venta (`EL`, `NL`, `BE`) traen `DTEDocSII` vacío y
  `iw_gsaen.DTE_SiiTDoc` está en 0 en las 209 filas existentes.
- **Correlativo de `NVNumero`: origen desconocido.** No está en `nwparam` ni
  aparece una tabla de correlativos de ventas. Hasta aclararlo, cualquier
  inserción debe tomarlo bajo `UPDLOCK, HOLDLOCK` dentro de una transacción.
- **Estado `P` de cotización sin explicar**: 189 filas, ninguna con motivo de
  pérdida ni con nota de venta asociada.
- **Contraseña de `sa` en texto plano** en `srv:E:\Servicio\Config\Config.js`
  (proyecto heredado, ya en su historial de git). Si se rota la clave, hay que
  acordarse de ese archivo.
