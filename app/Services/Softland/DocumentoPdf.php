<?php

namespace App\Services\Softland;

use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\View;

/**
 * El PDF de una cotización o de una nota de venta.
 *
 * Es el documento que el cliente imprime, reenvía a su jefe y guarda. Por eso
 * va adjunto al correo y no enlazado: un enlace a `192.168.1.55:8086` no se abre
 * desde fuera de la oficina, y el cliente está fuera de la oficina.
 *
 * Se dibuja con la misma plantilla del correo más una cabecera con los datos de
 * la empresa. Tener dos plantillas distintas — una para el correo y otra para el
 * PDF — es garantizar que un día digan cosas distintas.
 */
class DocumentoPdf
{
    /** @return string el PDF en binario, listo para adjuntar */
    public function generar(string $titulo, array $documento, array $cliente, array $lineas, array $extra = []): string
    {
        $opciones = new Options;
        // Sin acceso a la red: el PDF se arma con lo que hay en el HTML y nada
        // más. Un `<img src="http://…">` que no responde bloquearía el envío.
        $opciones->set('isRemoteEnabled', false);
        $opciones->set('defaultFont', 'DejaVu Sans');   // la que trae acentos y «ñ»

        $dompdf = new Dompdf($opciones);
        $dompdf->setPaper('letter');
        $dompdf->loadHtml(View::make('pdf.documento', [
            'titulo' => $titulo,
            'documento' => $documento,
            'cliente' => $cliente,
            'lineas' => $lineas,
        ] + $extra)->render(), 'UTF-8');
        $dompdf->render();

        return $dompdf->output();
    }

    /** Nombre del archivo. Sin espacios: hay gestores de correo que los parten. */
    public function nombre(string $tipo, int $numero): string
    {
        return ($tipo === 'notas_venta' ? 'nota-de-venta' : 'cotizacion').'-'.$numero.'.pdf';
    }
}
