/*
 * Sistema de iconografía — única fuente de verdad.
 *
 * Regla dura: en esta app NO se usan emojis ni glifos tipográficos como
 * iconos: ni la cruz de cerrar, ni la comilla angular de volver, ni la flecha
 * circular de actualizar.
 * Un emoji se dibuja distinto en cada teléfono, no hereda el color del texto,
 * no tiene grosor de trazo y le da aire de prototipo a una herramienta que va
 * conectada al ERP de la empresa.
 *
 * Todo icono sale de **Lucide**, una sola familia, sin mezclar. Y ninguna
 * pantalla elige su icono: elige un **concepto** de esta tabla. Así «cliente»
 * se ve igual en el panel, en el buscador y en la cotización, y cambiar el
 * icono de clientes en toda la app es cambiar una línea aquí.
 *
 * Para agregar uno: busca el nombre en https://lucide.dev, impórtalo arriba y
 * agrégalo abajo con la variante que le corresponda por función.
 */
import {
    Bell, BellOff, Boxes, Building2, ChartNoAxesCombined, CheckCheck, ChevronLeft,
    ChevronDown, ChevronRight, CircleAlert, CircleCheck, CircleHelp, CircleUser, ClipboardList,
    CloudDownload, CloudUpload, CreditCard, Database, FileDown, FileText, Image, Inbox, Info,
    LayoutDashboard,
    LogOut, Mail, MailCheck, MailX, Package, Plus, RefreshCw, Route, Search, SearchX,
    LifeBuoy, Server, Settings, ShieldCheck, ShoppingCart, ReceiptText, SlidersHorizontal,
    Share2, Smartphone, Trash2, TriangleAlert, Type, UserCog, UserPlus, Users, WifiOff, X,
} from 'lucide-vue-next';

/*
 * Variantes = familia funcional, no adorno. El color dice de qué se trata:
 *
 *   venta     el flujo del negocio (cotizacion, NV, DTE)      indigo corporativo
 *   catalogo  lo que se consulta: clientes, productos         cian
 *   dinero    plata: cobros, montos, estadística              verde
 *   aviso     lo que interrumpe: notificaciones, correos      ámbar
 *   admin     configuración y usuarios                        gris azulado
 *   peligro   destructivo o en falla                          rojo
 *   neutro    controles de interfaz sin categoría             gris
 */
export const VARIANTES = ['venta', 'catalogo', 'dinero', 'aviso', 'admin', 'peligro', 'neutro'];

export const ICONOS = {
    // ---- Flujo de ventas ----
    cotizacion: { glifo: FileText, variante: 'venta' },
    notaVenta: { glifo: ClipboardList, variante: 'venta' },
    factura: { glifo: ReceiptText, variante: 'venta' },
    pdf: { glifo: FileDown, variante: 'venta' },
    venta: { glifo: ShoppingCart, variante: 'venta' },

    // ---- Catálogos ----
    cliente: { glifo: Users, variante: 'catalogo' },
    nuevoCliente: { glifo: UserPlus, variante: 'catalogo' },
    producto: { glifo: Package, variante: 'catalogo' },
    inventario: { glifo: Boxes, variante: 'catalogo' },
    ruta: { glifo: Route, variante: 'catalogo' },

    // ---- Dinero ----
    cobranza: { glifo: CreditCard, variante: 'dinero' },
    estadistica: { glifo: ChartNoAxesCombined, variante: 'dinero' },

    // ---- Avisos ----
    notificacion: { glifo: Bell, variante: 'aviso' },
    sinNotificaciones: { glifo: BellOff, variante: 'neutro' },
    buzon: { glifo: Inbox, variante: 'aviso' },
    correo: { glifo: Mail, variante: 'aviso' },
    correoEnviado: { glifo: MailCheck, variante: 'aviso' },
    correoFallido: { glifo: MailX, variante: 'peligro' },
    reglaAviso: { glifo: SlidersHorizontal, variante: 'aviso' },

    // ---- Administración ----
    panel: { glifo: LayoutDashboard, variante: 'admin' },
    usuario: { glifo: UserCog, variante: 'admin' },
    configuracion: { glifo: Settings, variante: 'admin' },
    servidor: { glifo: Server, variante: 'admin' },
    permisos: { glifo: ShieldCheck, variante: 'admin' },
    cuenta: { glifo: CircleUser, variante: 'admin' },
    empresa: { glifo: Building2, variante: 'admin' },
    dispositivo: { glifo: Smartphone, variante: 'admin' },
    tamanoTexto: { glifo: Type, variante: 'admin' },
    datos: { glifo: Database, variante: 'admin' },
    // Soporte no es administración: lo usa el vendedor, no el admin. Va con
    // el ámbar de los avisos porque es «algo pasó y necesito que me miren».
    soporte: { glifo: LifeBuoy, variante: 'aviso' },
    // Compartir, no «WhatsApp»: lo que se abre es la hoja del sistema, y ahí el
    // vendedor elige — WhatsApp, correo, la impresora o lo que tenga instalado.
    compartir: { glifo: Share2, variante: 'venta' },
    logo: { glifo: Image, variante: 'admin' },

    // ---- Controles de interfaz ----
    crear: { glifo: Plus },
    cerrar: { glifo: X },
    atras: { glifo: ChevronLeft },
    avanzar: { glifo: ChevronRight },
    desplegar: { glifo: ChevronDown },
    sincronizar: { glifo: RefreshCw },
    // Bajar y subir son cosas distintas y se leen distinto: descargar es traerse
    // los maestros al teléfono; subir es mandar a Softland lo que se hizo sin señal.
    descargar: { glifo: CloudDownload, variante: 'catalogo' },
    subir: { glifo: CloudUpload, variante: 'aviso' },
    salir: { glifo: LogOut },
    buscar: { glifo: Search },
    borrar: { glifo: Trash2, variante: 'peligro' },
    sinResultados: { glifo: SearchX, variante: 'neutro' },

    // ---- Estados ----
    ok: { glifo: CircleCheck, variante: 'dinero' },
    error: { glifo: CircleAlert, variante: 'peligro' },
    alerta: { glifo: TriangleAlert, variante: 'aviso' },
    info: { glifo: Info, variante: 'admin' },
    sinRed: { glifo: WifiOff, variante: 'aviso' },
    alDia: { glifo: CheckCheck, variante: 'dinero' },
};

/** Icono de un concepto. Si no existe, se ve un signo de pregunta — a propósito. */
export function icono(nombre) {
    const i = ICONOS[nombre];
    if (i) return i;

    // Ruidoso queriendo: un icono mal escrito tiene que notarse en desarrollo,
    // no desaparecer en silencio y dejar un hueco en la pantalla.
    console.warn(`[iconos] «${nombre}» no está en el mapa. Agrégalo en src/iconos.js.`);
    return { glifo: CircleHelp, variante: 'neutro' };
}
