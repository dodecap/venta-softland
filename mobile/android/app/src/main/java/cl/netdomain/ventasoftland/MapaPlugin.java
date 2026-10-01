package cl.netdomain.ventasoftland;

import android.content.ActivityNotFoundException;
import android.content.Intent;
import android.net.Uri;

import com.getcapacitor.JSObject;
import com.getcapacitor.Plugin;
import com.getcapacitor.PluginCall;
import com.getcapacitor.PluginMethod;
import com.getcapacitor.annotation.CapacitorPlugin;

/**
 * Lleva al vendedor hasta la puerta del cliente.
 *
 * <p>No dibuja ningún mapa: le pasa la dirección a la aplicación de mapas que
 * el vendedor ya tiene instalada. Así no hace falta ninguna llave de Google
 * dentro del APK —que se descompila—, ni cuenta de facturación por empresa, ni
 * un permiso de ubicación: quien pide la ubicación es la app de mapas, a la que
 * el vendedor ya se la dio.
 *
 * <p><b>Dos intenciones, y el orden importa.</b> {@code google.navigation:}
 * arranca la guía paso a paso, que es lo que significa «cómo llegar» para quien
 * está en la calle, pero sólo la atienden algunas aplicaciones. Si no la
 * atiende ninguna se cae a {@code geo:}, que la atienden todas —Waze, Maps, las
 * de mapas sin conexión— y deja el punto en el mapa con el botón de ir al lado.
 * Un toque más, pero nunca un callejón sin salida.
 *
 * <p>Las dos son {@code ACTION_VIEW}, así que bastaría un {@code openUrl}; el
 * plugin existe para no traer un paquete de npm entero por una línea —el mismo
 * criterio de {@code CalendarioPlugin}— y porque la cadena de respaldo tiene
 * que resolverse aquí: desde JavaScript no hay forma de saber si la intención
 * encontró a alguien que la atendiera.
 */
@CapacitorPlugin(name = "Mapa")
public class MapaPlugin extends Plugin {

    @PluginMethod
    public void comoLlegar(PluginCall call) {
        String destino = call.getString("destino", "");

        if (destino == null || destino.trim().isEmpty()) {
            call.reject("Sin dirección no hay a dónde ir.");
            return;
        }

        String q = Uri.encode(destino.trim());

        if (lanzar("google.navigation:q=" + q)) {
            responder(call, true);
            return;
        }

        if (lanzar("geo:0,0?q=" + q)) {
            responder(call, false);
            return;
        }

        // Un teléfono sin ninguna aplicación de mapas. Raro, pero quien llama
        // tiene que poder decirlo en castellano en vez de quedarse callado.
        call.reject("No hay ninguna aplicación de mapas instalada.");
    }

    private boolean lanzar(String uri) {
        try {
            getActivity().startActivity(new Intent(Intent.ACTION_VIEW, Uri.parse(uri)));
            return true;
        } catch (ActivityNotFoundException e) {
            return false;
        }
    }

    private void responder(PluginCall call, boolean navegando) {
        JSObject r = new JSObject();
        r.put("navegando", navegando);
        call.resolve(r);
    }
}
