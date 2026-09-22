<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ $titulo ?? 'Instalación' }} — {{ config('app.name') }}</title>
<style>
    /* Paleta Softland: índigo #1d1060 con acento cian #26bdef. */
    :root {
        --indigo: #1d1060; --indigo-claro: #2f236d; --cian: #26bdef;
        --gris-fondo: #f4f4f4; --gris-borde: #e0e0e0; --gris-texto: #545454;
        --gris-suave: #919191; --rojo: #e13838; --verde: #2ec95c;
    }
    * { box-sizing: border-box; }
    body {
        margin: 0; min-height: 100vh; background: var(--gris-fondo);
        font-family: system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif;
        color: #2b2b2b; display: flex; align-items: flex-start; justify-content: center;
        padding: 32px 16px;
    }
    .tarjeta {
        width: 100%; max-width: 560px; background: #fff; border-radius: 10px;
        overflow: hidden; box-shadow: 0 2px 10px rgba(0,0,0,.12);
    }
    .cabecera {
        background: var(--indigo); color: #fff; padding: 22px 26px;
        border-bottom: 5px solid var(--cian);
    }
    .cabecera h1 { margin: 0; font-size: 19px; letter-spacing: .2px; }
    .cabecera p { margin: 6px 0 0; font-size: 13px; opacity: .8; }
    .cuerpo { padding: 26px; }
    fieldset { border: 1px solid var(--gris-borde); border-radius: 8px; padding: 16px; margin: 0 0 20px; }
    legend { color: var(--indigo); font-weight: 700; font-size: 13px; padding: 0 6px; }
    label { display: block; font-size: 13px; color: var(--gris-texto); margin: 12px 0 5px; font-weight: 600; }
    label:first-of-type { margin-top: 0; }
    input {
        width: 100%; padding: 10px 12px; font-size: 15px; color: #2b2b2b;
        border: 1px solid var(--gris-suave); border-radius: 6px; background: #fff;
    }
    input:focus { outline: none; border-color: var(--cian); box-shadow: 0 0 0 3px rgba(38,189,239,.18); }
    .ayuda { font-size: 12px; color: var(--gris-suave); margin: 5px 0 0; line-height: 1.5; }
    .fila { display: flex; gap: 12px; }
    .fila > div { flex: 1; }
    .fila > div.angosto { flex: 0 0 120px; }
    button {
        width: 100%; padding: 13px; font-size: 15px; font-weight: 700; color: #fff;
        background: var(--indigo); border: 0; border-bottom: 4px solid var(--cian);
        border-radius: 6px; cursor: pointer;
    }
    button:hover { background: var(--indigo-claro); }
    .errores {
        background: #fdecec; border-left: 4px solid var(--rojo); color: #8a1f1f;
        padding: 12px 14px; border-radius: 6px; margin-bottom: 20px; font-size: 13px;
    }
    .errores ul { margin: 0; padding-left: 18px; }
    .ok {
        background: #eafaf0; border-left: 4px solid var(--verde); color: #1a6b38;
        padding: 14px; border-radius: 6px; font-size: 14px; line-height: 1.6;
    }
    code {
        background: var(--gris-fondo); padding: 2px 6px; border-radius: 4px;
        font-size: 13px; color: var(--indigo);
    }
    .pasos { font-size: 14px; color: var(--gris-texto); line-height: 1.8; padding-left: 20px; }
    .aviso {
        background: #fff6e5; border-left: 4px solid #e08a00; color: #7a4b00;
        padding: 12px 14px; border-radius: 6px; margin-bottom: 20px; font-size: 13px; line-height: 1.6;
    }
    /* La comprobación del servidor. El color no es la única señal: cada fila
       lleva su marca, que se lee igual en blanco y negro o sin distinguirlos. */
    .requisitos { list-style: none; margin: 0 0 22px; padding: 0; }
    .requisitos li {
        display: flex; gap: 10px; align-items: flex-start;
        padding: 10px 0; border-bottom: 1px solid var(--gris-borde);
    }
    .requisitos li:last-child { border-bottom: 0; }
    .requisitos .marca { font-weight: 700; font-size: 15px; line-height: 1.4; width: 16px; flex: 0 0 16px; }
    .requisitos li.si .marca { color: var(--verde); }
    .requisitos li.no .marca { color: var(--rojo); }
    .requisitos li.no strong { color: var(--rojo); }
    .requisitos strong { font-size: 14px; }
    .requisitos .ayuda { margin-top: 3px; }
    .requisitos .arreglo { color: var(--gris-texto); font-family: ui-monospace, Consolas, monospace; }
    /* El botón grande que lleva al código QR al terminar de instalar. */
    .boton-enlace {
        display: block; text-align: center; text-decoration: none;
        padding: 13px; font-size: 15px; font-weight: 700; color: #fff;
        background: var(--indigo); border-bottom: 4px solid var(--cian);
        border-radius: 6px; margin: 22px 0 0;
    }
    .boton-enlace:hover { background: var(--indigo-claro); }
</style>
</head>
<body>
    <div class="tarjeta">
        <div class="cabecera">
            <h1>{{ config('app.name') }}</h1>
            <p>{{ $subtitulo ?? 'Instalación del servidor' }}</p>
        </div>
        <div class="cuerpo">
            @yield('contenido')
        </div>
    </div>
</body>
</html>
