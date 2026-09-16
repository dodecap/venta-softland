package cl.netdomain.ventasoftland;

import android.content.ActivityNotFoundException;
import android.content.Intent;
import android.provider.CalendarContract;

import com.getcapacitor.Plugin;
import com.getcapacitor.PluginCall;
import com.getcapacitor.PluginMethod;
import com.getcapacitor.annotation.CapacitorPlugin;

/**
 * Pasa un compromiso al calendario del teléfono.
 *
 * <p>No escribe en el calendario: lanza la intención estándar de Android para
 * crear un evento, y la atiende la app de calendario que el vendedor ya usa —
 * Google, Outlook, la de Samsung, la que tenga por defecto. Él ve la pantalla
 * con todo relleno, puede cambiar la hora o ponerle un recordatorio, y guarda.
 *
 * <p>La consecuencia buena es que <b>no hace falta ningún permiso</b>: pedir
 * acceso de lectura y escritura al calendario para esto sería desproporcionado,
 * y escribir en silencio dejaría al vendedor sin saber dónde quedó su
 * compromiso.
 *
 * <p>Existe porque esto es una intención, no una dirección web: ni
 * {@code openUrl} ni una navegación del WebView saben lanzar
 * {@code ACTION_INSERT} — las dos acaban en {@code ACTION_VIEW}, que abre el
 * calendario pero sin el evento.
 */
@CapacitorPlugin(name = "Calendario")
public class CalendarioPlugin extends Plugin {

    @PluginMethod
    public void agendar(PluginCall call) {
        long inicio = call.getLong("inicio", 0L);
        long fin = call.getLong("fin", 0L);

        if (inicio <= 0) {
            call.reject("Un compromiso sin fecha no se puede agendar.");
            return;
        }

        Intent intent = new Intent(Intent.ACTION_INSERT)
            .setData(CalendarContract.Events.CONTENT_URI)
            .putExtra(CalendarContract.Events.TITLE, call.getString("titulo", ""))
            .putExtra(CalendarContract.Events.DESCRIPTION, call.getString("descripcion", ""))
            .putExtra(CalendarContract.Events.EVENT_LOCATION, call.getString("lugar", ""))
            .putExtra(CalendarContract.EXTRA_EVENT_BEGIN_TIME, inicio)
            .putExtra(CalendarContract.EXTRA_EVENT_END_TIME, fin > inicio ? fin : inicio + 1800000L)
            .putExtra(CalendarContract.EXTRA_EVENT_ALL_DAY, Boolean.TRUE.equals(call.getBoolean("todoElDia", false)));

        try {
            getActivity().startActivity(intent);
            call.resolve();
        } catch (ActivityNotFoundException e) {
            // Un teléfono sin app de calendario. Raro, pero el que llama tiene
            // que poder decirlo en castellano en vez de quedarse callado.
            call.reject("No hay ninguna aplicación de calendario instalada.");
        }
    }
}
