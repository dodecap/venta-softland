@echo off
setlocal enabledelayedexpansion
rem ---------------------------------------------------------------------------
rem  Venta Softland — arranque en un servidor nuevo (Windows + XAMPP).
rem
rem  Se ejecuta UNA vez, desde la carpeta donde se clonó el repositorio:
rem
rem      cd C:\xampp\htdocs\venta-freebar
rem      bin\instalar.cmd
rem
rem  Deja el servidor en condiciones de que /setup funcione: dependencias,
rem  .env, clave de cifrado, carpetas de escritura y el Alias de Apache. Lo que
rem  NO hace es configurar la conexión a Softland: eso se hace desde el
rem  navegador, en /setup, que es donde se pide la instancia, la base y las
rem  contraseñas. Aquí no se teclea ninguna contraseña.
rem
rem  Es idempotente: volver a correrlo no pisa el .env ni regenera la clave.
rem ---------------------------------------------------------------------------

cd /d "%~dp0.."
set "RAIZ=%CD%"
for %%i in ("%RAIZ%") do set "CARPETA=%%~nxi"

echo.
echo   Venta Softland — instalacion del servidor
echo   %RAIZ%
echo.

rem --- 1) PHP -----------------------------------------------------------------
set "PHP=%~1"
if "%PHP%"=="" set "PHP=C:\xampp\php\php.exe"
if not exist "%PHP%" (
    echo   [X] No encuentro PHP en %PHP%
    echo       Pasa la ruta como argumento:  bin\instalar.cmd D:\xampp\php\php.exe
    exit /b 1
)
rem  Por un archivo y no por `for /f`: cmd parte la linea en el primer espacio
rem  de dentro de las comillas y el -r acaba recibiendo medio programa.
"%PHP%" -r "echo PHP_VERSION;" > "%TEMP%\vs-php.txt" 2>nul
set "VPHP="
set /p VPHP=<"%TEMP%\vs-php.txt"
del "%TEMP%\vs-php.txt" 2>nul
if "%VPHP%"=="" (
    echo   [X] %PHP% no responde. Revisa que sea el php.exe de XAMPP.
    exit /b 1
)
echo   [ok] PHP %VPHP%

rem --- 2) Dependencias --------------------------------------------------------
set "COMPOSER=C:\xampp\php\composer.bat"
if not exist "%COMPOSER%" set "COMPOSER=composer"

if exist "%RAIZ%\vendor\autoload.php" (
    echo   [ok] Dependencias ya instaladas
) else (
    echo   [..] Instalando dependencias ^(necesita internet, tarda un par de minutos^)
    call "%COMPOSER%" install --no-dev --optimize-autoloader --no-interaction
    if errorlevel 1 (
        echo   [X] Fallo la instalacion de dependencias.
        echo       Si este servidor no tiene Composer, copia la carpeta vendor
        echo       desde otra instalacion de la MISMA version.
        exit /b 1
    )
    echo   [ok] Dependencias instaladas
)

rem --- 3) .env ----------------------------------------------------------------
if exist "%RAIZ%\.env" (
    echo   [ok] .env ya existe ^(no se toca^)
) else (
    copy /y "%RAIZ%\.env.example" "%RAIZ%\.env" >nul
    echo   [ok] .env creado desde .env.example
)

rem --- 4) Clave de cifrado ----------------------------------------------------
rem  Sin APP_KEY la conexion a Softland no se puede guardar cifrada, y el error
rem  que sale no menciona la clave por ningun lado.
findstr /b /c:"APP_KEY=base64:" "%RAIZ%\.env" >nul 2>&1
if errorlevel 1 (
    "%PHP%" artisan key:generate --force --no-interaction >nul
    if errorlevel 1 (
        echo   [X] No se pudo generar la clave de la aplicacion.
        exit /b 1
    )
    echo   [ok] Clave de la aplicacion generada
) else (
    echo   [ok] Clave de la aplicacion ya estaba ^(no se regenera^)
)

rem --- 5) Carpetas de escritura ----------------------------------------------
for %%d in (
    "storage\app\private"
    "storage\app\private\apk"
    "storage\framework\cache\data"
    "storage\framework\sessions"
    "storage\framework\views"
    "storage\logs"
    "bootstrap\cache"
) do if not exist "%RAIZ%\%%~d" mkdir "%RAIZ%\%%~d" 2>nul
echo   [ok] Carpetas de trabajo

rem --- 6) Cache ---------------------------------------------------------------
rem  Las vistas se compilan; la configuracion NO se cachea a proposito: si
rem  manana se toca el .env —el certificado del DTE, por ejemplo— una config
rem  cacheada seguiria sirviendo la vieja sin avisar.
"%PHP%" artisan config:clear >nul 2>&1
"%PHP%" artisan view:cache >nul 2>&1
echo   [ok] Vistas compiladas

rem --- 7) Apache --------------------------------------------------------------
rem  Laravel se sirve desde public/, no desde la raiz del proyecto. Sin el Alias
rem  la direccion acabaria en /%CARPETA%/public, que ademas deja el codigo a la
rem  vista. El archivo se escribe; la linea del httpd.conf se deja escrita para
rem  que la anada una persona: tocar el httpd.conf a ciegas puede dejar Apache
rem  sin arrancar, y entonces no hay pagina que explique nada.
set "APACHE_EXTRA=C:\xampp\apache\conf\extra"
set "CONF=%APACHE_EXTRA%\%CARPETA%.conf"
set "PUB=%RAIZ:\=/%/public"

if exist "%APACHE_EXTRA%" (
    if exist "%CONF%" (
        echo   [ok] Alias de Apache ya escrito: %CONF%
    ) else (
        >  "%CONF%" echo # %CARPETA% — servir Laravel desde public/
        >> "%CONF%" echo Alias /%CARPETA% "%PUB%"
        >> "%CONF%" echo ^<Directory "%PUB%"^>
        >> "%CONF%" echo     Options -Indexes +FollowSymLinks
        >> "%CONF%" echo     AllowOverride All
        >> "%CONF%" echo     Require all granted
        >> "%CONF%" echo ^</Directory^>
        echo   [ok] Alias de Apache escrito: %CONF%
    )
    findstr /c:"Include conf/extra/%CARPETA%.conf" "C:\xampp\apache\conf\httpd.conf" >nul 2>&1
    if errorlevel 1 (
        set "FALTA_INCLUDE=1"
    ) else (
        echo   [ok] httpd.conf ya lo incluye
    )
) else (
    echo   [!] No encuentro %APACHE_EXTRA% — configura el Alias a mano.
    set "FALTA_INCLUDE=1"
)

echo.
if defined FALTA_INCLUDE (
    echo   FALTA UN PASO, y hay que hacerlo a mano:
    echo.
    echo     1^) Anade esta linea al final de C:\xampp\apache\conf\httpd.conf
    echo.
    echo          Include conf/extra/%CARPETA%.conf
    echo.
    echo     2^) Reinicia Apache desde el panel de XAMPP.
    echo.
) else (
    echo   Reinicia Apache si acabas de cambiar su configuracion.
    echo.
)

echo   Y despues, desde un navegador:
echo.
echo       http://^<este-servidor^>/%CARPETA%
echo.
echo   Ahi se pide la instancia de SQL Server, la base de la empresa y las
echo   contrasenas. Al terminar, la misma pagina lleva al codigo QR de la app.
echo.
endlocal
