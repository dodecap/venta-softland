# Montar este proyecto en un computador nuevo

## 1. Clonar

```bash
git clone git@github.com:dodecap/venta-softland.git
cd venta-softland
```

## 2. Acceso SSH al servidor

En `~/.ssh/config`:

```
Host srv
    HostName 172.30.205.106
    User ddecap
```

Comprobar con `ssh srv`. (La clave privada la traes tú; no está en el repo.)

## 3. Herramientas

Aquí **no se instala PHP**: PHP, Composer y artisan corren en `srv`, que es la
única máquina con acceso al SQL Server. En esta máquina solo hace falta lo de
compilar la app:

- **Node 22**
- **JDK 21 completo** (con `javac`) en `~/jdk21`
- **Android SDK** en `~/android-sdk` (`platform-tools`, `platforms;android-34`,
  `build-tools;34.0.0`)

## 4. Compilar la app

```bash
bash mobile/build-apk.sh
```

Deja el APK en `venta-softland.apk`, en la raíz del repo.

Para probar solo la interfaz en el navegador, sin compilar nada:

```bash
cd mobile && npm install && npm run dev
```

## 5. Desplegar el servidor

```bash
bin/deploy.sh
```

Sube el código a `srv:C:\xampp\htdocs\venta-softland` y reconstruye las cachés.
No toca `vendor/` ni `.env`, que viven en el servidor.

La primera vez, en el servidor:

```
cd C:\xampp\htdocs\venta-softland
C:\xampp\php\composer.bat install --no-interaction
copy .env.example .env
C:\xampp\php\php.exe artisan key:generate
```

Y publicar en Apache: copiar `deploy/apache-venta-softland.conf` a
`C:\xampp\apache\conf\extra\venta-softland.conf`, agregar
`Include conf/extra/venta-softland.conf` en `httpd.conf` y recargar Apache.

## 6. Instalar

Abrir `http://<servidor>:8086/venta-softland/setup` en el navegador y completar
la conexión a SQL Server más la contraseña del usuario `softland`.

## 7. Empezar

1. Instalar el APK en el teléfono (activando «orígenes desconocidos»).
2. En **Servidor**, escribir la dirección de la instalación.
3. Entrar con `softland`.
4. Crear los vendedores en **Usuarios** y enlazarlos con su código de Softland.
5. Configurar el SMTP en **Configuración → Correo** y mandar una prueba.

## 8. Retomar el trabajo

Lee `STATE.md` y `CLAUDE.md`. El mapa de las tablas de Softland está en
`docs/flujo-ventas-softland.md` y el plan por fases en `docs/roadmap.md`.
