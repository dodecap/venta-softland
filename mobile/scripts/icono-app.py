#!/usr/bin/env python3
"""
El icono de la app, en los tamaños que pide Android, desde un solo archivo.

Android no quiere «un icono»: quiere cinco densidades del icono cuadrado
antiguo, cinco del redondo y cinco de la capa de adelante del icono adaptable.
Dibujarlos a mano es la forma segura de que dentro de un año haya tres versiones
distintas del logo en el mismo teléfono. Aquí la fuente es una sola:

    mobile/recursos/icono.png

## Por qué el icono adaptable va más chico

Desde Android 8 el lanzador recorta el icono con la forma que el fabricante
quiera: círculo, cuadrado redondeado, gota. La lámina mide 108 dp pero sólo se
ve el centro —72 dp, dos tercios—, y lo de afuera se usa para el efecto de
parallax al mover la pantalla de inicio. Un logo que llene los 108 dp aparece
mordido en medio teléfono del mercado.

Como el logo es un círculo, se dibuja exactamente del tamaño de esa zona
segura: en un lanzador de máscara redonda queda a ras, sin borde blanco, y en
uno de máscara cuadrada el blanco del fondo le hace el marco.

## La pantalla de arranque va del mismo archivo

Venía la de Capacitor —su logo celeste sobre blanco— y se veía cada vez que el
vendedor abría la app. Son once imágenes más, vertical y horizontal en cinco
densidades, y salen de aquí mismo para que no haya dos logos distintos en el
mismo teléfono.

Uso:
    python3 mobile/scripts/icono-app.py
"""
import sys
from pathlib import Path

try:
    from PIL import Image
except ImportError:
    sys.exit('Falta Pillow: pip install Pillow')

RAIZ = Path(__file__).resolve().parents[2]
FUENTE = RAIZ / 'mobile/recursos/icono.png'
RES = RAIZ / 'mobile/android/app/src/main/res'

# Las cinco densidades de Android y el lado del icono en cada una. El icono
# adaptable va siempre a 108 dp, o sea 2,25 veces el cuadrado antiguo.
DENSIDADES = {'mdpi': 48, 'hdpi': 72, 'xhdpi': 96, 'xxhdpi': 144, 'xxxhdpi': 192}

# Cuánto del lienzo ocupa el logo. El adaptable es la zona segura de Android
# —72 de 108— y no se toca. El antiguo deja una pizca de aire para que el
# círculo no quede pegado al borde exacto del archivo.
ZONA_SEGURA = 72 / 108
AIRE_ANTIGUO = 0.96

# Cuánto del lado corto ocupa el logo en la pantalla de arranque. Un tercio:
# más grande parece un error de escala y más chico no se ve en un teléfono al
# sol.
LOGO_ARRANQUE = 1 / 3

# El fondo cuando el icono trae las esquinas transparentes — es decir, cuando
# el logo es una figura suelta y hace falta un color detrás.
FONDO_POR_OMISION = (255, 255, 255)


def fondo(logo: Image.Image) -> tuple:
    """El color que va detrás del logo, deducido del propio archivo.

    Se mira una esquina. Si es transparente, el logo es una figura suelta y
    detrás va blanco, que es lo que había siempre. Si es opaca, el icono **ya
    trae su propio fondo** —un logo claro sobre negro, por ejemplo— y ése es el
    color que tiene que seguir alrededor: con blanco detrás aparecería un
    cuadrado negro flotando en una lámina blanca, tanto en el lanzador como en
    la pantalla de arranque.

    Deducirlo en vez de configurarlo evita el fallo clásico de cambiar el icono
    y olvidar el color, que no se nota hasta que alguien mira el teléfono.
    """
    esquina = logo.getpixel((1, 1))

    return esquina[:3] if esquina[3] > 250 else FONDO_POR_OMISION


def encuadrar(logo: Image.Image, lado: int, proporcion: float) -> Image.Image:
    """El logo centrado en un lienzo transparente de `lado` píxeles."""
    dentro = round(lado * proporcion)
    lienzo = Image.new('RGBA', (lado, lado), (0, 0, 0, 0))
    lienzo.paste(logo.resize((dentro, dentro), Image.LANCZOS),
                 ((lado - dentro) // 2, (lado - dentro) // 2))
    return lienzo


def arranque(logo: Image.Image, color: tuple) -> int:
    """La pantalla de arranque, en los tamaños que ya existen en el proyecto."""
    escritos = 0
    for archivo in sorted(RES.glob('drawable*/splash.png')):
        ancho, alto = Image.open(archivo).size
        lado = round(min(ancho, alto) * LOGO_ARRANQUE)

        lienzo = Image.new('RGB', (ancho, alto), color)
        dibujo = logo.resize((lado, lado), Image.LANCZOS)
        lienzo.paste(dibujo, ((ancho - lado) // 2, (alto - lado) // 2), dibujo)
        lienzo.save(archivo)
        escritos += 1

    return escritos


def escribir_fondo(color: tuple) -> int:
    """El color de fondo del icono adaptable, que es un recurso de Android.

    Se escribe desde aquí y no a mano por lo mismo que los PNG: cambiar el
    icono y dejar el color viejo es un fallo que no se ve en el repositorio, se
    ve en el teléfono de otro.
    """
    archivo = RES / 'values/ic_launcher_background.xml'
    archivo.parent.mkdir(parents=True, exist_ok=True)
    archivo.write_text(
        '<?xml version="1.0" encoding="utf-8"?>\n'
        '<resources>\n'
        f'    <color name="ic_launcher_background">#{color[0]:02X}{color[1]:02X}{color[2]:02X}</color>\n'
        '</resources>\n',
        encoding='utf-8',
    )

    return 1


def main() -> None:
    if not FUENTE.exists():
        sys.exit(f'No está {FUENTE.relative_to(RAIZ)}')

    logo = Image.open(FUENTE).convert('RGBA')
    if logo.width != logo.height:
        sys.exit(f'El icono tiene que ser cuadrado, y es {logo.width}x{logo.height}.')

    color = fondo(logo)

    escritos = 0
    for densidad, lado in DENSIDADES.items():
        carpeta = RES / f'mipmap-{densidad}'
        carpeta.mkdir(parents=True, exist_ok=True)

        antiguo = encuadrar(logo, lado, AIRE_ANTIGUO)
        # El cuadrado y el redondo son el mismo dibujo: el logo ya es un
        # círculo, así que recortarlo otra vez sólo le comería el borde.
        for nombre in ('ic_launcher.png', 'ic_launcher_round.png'):
            antiguo.save(carpeta / nombre)
            escritos += 1

        adaptable = encuadrar(logo, round(lado * 2.25), ZONA_SEGURA)
        adaptable.save(carpeta / 'ic_launcher_foreground.png')
        escritos += 1

        print(f'{densidad:<8} {lado}x{lado} y {adaptable.width}x{adaptable.width}')

    arrancadas = arranque(logo, color)
    print(f'arranque  {arrancadas} pantallas, logo a un tercio del lado corto')

    escritos += escribir_fondo(color)

    print(f'\n{escritos + arrancadas} archivos escritos desde {FUENTE.relative_to(RAIZ)}.')
    print(f'Fondo deducido del icono: #{color[0]:02X}{color[1]:02X}{color[2]:02X}')


if __name__ == '__main__':
    main()
