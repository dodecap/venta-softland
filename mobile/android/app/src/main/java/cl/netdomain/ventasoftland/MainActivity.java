package cl.netdomain.ventasoftland;

import com.getcapacitor.BridgeActivity;

public class MainActivity extends BridgeActivity {
    @Override
    public void onCreate(android.os.Bundle savedInstanceState) {
        // El plugin del calendario se registra a mano porque vive en este
        // proyecto y no en un paquete de npm: son cuarenta líneas para lanzar
        // una intención de Android, y traer una dependencia entera para eso
        // sería pagar de más.
        registerPlugin(CalendarioPlugin.class);
        super.onCreate(savedInstanceState);
    }
}
