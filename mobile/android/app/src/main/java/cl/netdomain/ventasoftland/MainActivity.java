package cl.netdomain.ventasoftland;

import com.getcapacitor.BridgeActivity;

public class MainActivity extends BridgeActivity {
    @Override
    public void onCreate(android.os.Bundle savedInstanceState) {
        // Los dos plugins de aquí se registran a mano porque viven en este
        // proyecto y no en un paquete de npm: son cuarenta líneas para lanzar
        // una intención de Android, y traer una dependencia entera para eso
        // sería pagar de más.
        registerPlugin(CalendarioPlugin.class);
        registerPlugin(MapaPlugin.class);
        super.onCreate(savedInstanceState);
    }
}
