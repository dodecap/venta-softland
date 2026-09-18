<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Sii\Auxiliar;
use App\Services\Softland\Maestros;
use App\Support\Rut;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Alta y edición de clientes y de sus contactos.
 *
 * Es el **único** lugar de la app que escribe en una tabla nativa de Softland
 * (`softland.cwtauxi` y `softland.cwtaxco`). Todo lo demás de Softland se lee.
 * Por eso aquí hay más cuidado que en el resto:
 *
 *  - La clave del cliente es el RUT, no un correlativo. `CodAux` es el cuerpo
 *    del RUT sin dígito verificador, como lo tiene INNOVAGES en sus 3.824
 *    clientes. Eso hace el alta idempotente de verdad: reintentar un alta que
 *    se perdió en el camino no puede crear dos clientes, porque la segunda
 *    choca contra la clave primaria.
 *  - Solo se tocan las columnas que la app conoce. `cwtauxi` tiene 62 y las
 *    demás son de contabilidad, campañas y portal web: se dejan como estén.
 *  - Las seis columnas `Cla*` son NOT NULL y clasifican al auxiliar (cliente,
 *    proveedor, empleado…). Se fijan solo al crear: si alguien es además
 *    proveedor, no se lo quitamos al editarlo desde el teléfono.
 */
class ClienteController extends Controller
{
    public function __construct(private Maestros $maestros) {}

    /** Ficha de un cliente con sus contactos. Para abrirla desde una búsqueda. */
    public function show(string $codigo)
    {
        $cliente = $this->buscar($codigo);

        if (! $cliente) {
            return response()->json(['message' => 'Ese cliente no existe.'], 404);
        }

        return response()->json([
            'cliente' => $cliente,
            'contactos' => $this->contactosDe($codigo),
        ]);
    }

    /**
     * Lo que el SII publica de un RUT, para llenar el formulario de alta.
     *
     * **Primero se mira Softland, y sólo después el SII.** Si el RUT ya es
     * cliente no hay nada que proponer: se devuelve su ficha y se acabó. Eso
     * mata el alta duplicada antes de empezar, que es el error caro, y de paso
     * no gasta una consulta.
     *
     * El dígito verificador se comprueba aquí aunque el teléfono ya lo haya
     * comprobado: lo que llega por la red no es de fiar, y un RUT inventado
     * consultaría el padrón para nada.
     *
     * Devuelve una **propuesta**, no un cliente. Quien da de alta es `store()`,
     * con una persona de por medio.
     */
    public function sii(string $rut, Auxiliar $auxiliar)
    {
        if (! Rut::esValido($rut)) {
            return response()->json(['message' => 'El dígito verificador no corresponde.'], 422);
        }

        $codigo = Rut::cuerpo($rut);
        $existente = $this->buscar($codigo);

        if ($existente) {
            return response()->json([
                'ya_existe' => true,
                'cliente' => $existente,
                'contactos' => $this->contactosDe($codigo),
            ]);
        }

        try {
            return response()->json(['ya_existe' => false] + $auxiliar->consultar($rut));
        } catch (\RuntimeException $e) {
            // 503 y no 500: no es que la app esté rota, es que el servicio de
            // fuera no contestó. La pantalla lo dice y deja escribir a mano.
            return response()->json(['message' => $e->getMessage()], 503);
        }
    }

    /**
     * Alta de cliente.
     *
     * Si el RUT ya está en Softland no se crea nada y se responde 409 con la
     * ficha existente: puede ser un reintento después de perder la señal (y
     * entonces está todo bien) o el vendedor intentando dar de alta a alguien
     * que ya es cliente de otro. La app distingue los dos casos mirando la
     * ficha que se devuelve; el servidor no adivina.
     */
    public function store(Request $request)
    {
        $data = $this->validar($request, true);
        $codigo = Rut::cuerpo($data['rut']);

        $existente = $this->buscar($codigo);
        if ($existente) {
            return response()->json([
                'message' => 'Ese RUT ya está registrado como «'.$existente['nombre'].'».',
                'cliente' => $existente,
                'ya_existia' => true,
            ], 409);
        }

        DB::connection('softland')->table('softland.cwtauxi')->insert(
            $this->columnas($data, $request) + [
                'CodAux' => $codigo,
                // NOT NULL las seis. `S` solo en cliente: lo demás se clasifica
                // en Softland, no desde el teléfono.
                'ClaCli' => 'S',
                'ClaPro' => 'N',
                'ClaEmp' => 'N',
                'ClaSoc' => 'N',
                'ClaDis' => 'N',
                'ClaOtr' => 'N',
                'esReceptorDTE' => ! empty($data['email_dte']) ? 'S' : 'N',
                'ActAux' => 'S',
                'Bloqueado' => 'N',
                'ClienteDesde' => now(),
            ]
        );

        $this->guardarContactos($codigo, $data['contactos'] ?? [], $request);

        return response()->json([
            'cliente' => $this->buscar($codigo),
            'contactos' => $this->contactosDe($codigo),
        ], 201);
    }

    /** Edición. El RUT no se toca: es la clave, cambiarlo es crear otro cliente. */
    public function update(Request $request, string $codigo)
    {
        if (! $this->buscar($codigo)) {
            return response()->json(['message' => 'Ese cliente no existe.'], 404);
        }

        $data = $this->validar($request, false);

        DB::connection('softland')->table('softland.cwtauxi')
            ->where('CodAux', $codigo)
            ->update($this->columnas($data, $request));

        if ($request->has('contactos')) {
            $this->guardarContactos($codigo, $data['contactos'] ?? [], $request, true);
        }

        return response()->json([
            'cliente' => $this->buscar($codigo),
            'contactos' => $this->contactosDe($codigo),
        ]);
    }

    // ---------------------------------------------------------------- interno

    private function validar(Request $request, bool $creando): array
    {
        $reglas = [
            'nombre' => 'required|string|max:60',
            'fantasia' => 'nullable|string|max:60',
            'giro' => 'nullable|string|max:6',
            'direccion' => 'nullable|string|max:60',
            'comuna' => 'nullable|string|max:7',
            'ciudad' => 'nullable|string|max:7',
            'fono' => 'nullable|string|max:15',
            'email' => 'nullable|email|max:250',
            'email_dte' => 'nullable|email|max:250',
            'dias_plazo' => 'nullable|integer|min:0|max:99',
            'contactos' => 'nullable|array|max:20',
            'contactos.*.nombre' => 'required|string|max:30',
            'contactos.*.cargo' => 'nullable|string|max:4',
            'contactos.*.fono' => 'nullable|string|max:15',
            'contactos.*.email' => 'nullable|email|max:250',
        ];

        if ($creando) {
            $reglas['rut'] = 'required|string|max:20';
        }

        $data = $request->validate($reglas);

        if ($creando && ! Rut::esValido($data['rut'])) {
            $this->rechazar(['rut' => 'El dígito verificador no corresponde.']);
        }

        $this->verificarCodigos($data);

        return $data;
    }

    /**
     * Giro, comuna, ciudad y cargo son claves foráneas de verdad en Softland.
     * Sin esto, un código que no existe sale como un 500 con el texto del
     * constraint de SQL Server («FK_CWTAuxi_CWTComu») en la pantalla del
     * vendedor. Se comprueban antes para responder qué campo está mal.
     */
    private function verificarCodigos(array $data): void
    {
        $refs = [
            'giro' => ['softland.cwtgiro', 'GirCod', 'Ese giro no existe en Softland.'],
            'comuna' => ['softland.cwtcomu', 'ComCod', 'Esa comuna no existe en Softland.'],
            'ciudad' => ['softland.cwtciud', 'CiuCod', 'Esa ciudad no existe en Softland.'],
        ];

        $errores = [];
        foreach ($refs as $campo => [$tabla, $columna, $mensaje]) {
            $valor = $data[$campo] ?? null;
            if ($valor && ! DB::connection('softland')->table($tabla)->where($columna, $valor)->exists()) {
                $errores[$campo] = $mensaje;
            }
        }

        foreach ($data['contactos'] ?? [] as $i => $c) {
            if (! empty($c['cargo']) && ! DB::connection('softland')->table('softland.cwtcarg')->where('CarCod', $c['cargo'])->exists()) {
                $errores["contactos.$i.cargo"] = 'Ese cargo no existe en Softland.';
            }
        }

        if ($errores) {
            $this->rechazar($errores);
        }
    }

    private function rechazar(array $errores): never
    {
        abort(response()->json([
            'message' => reset($errores),
            'errors' => array_map(fn ($m) => [$m], $errores),
        ], 422));
    }

    /**
     * Columnas de `cwtauxi` que la app mantiene. Nada más, y solo las que vienen
     * en la petición.
     *
     * Esto último no es un detalle: `cwtauxi` tiene 62 columnas y la app conoce
     * diez. Si el que edita manda solo el teléfono y aquí se escribieran todas,
     * el giro, la dirección y la comuna del cliente se irían a NULL sin que
     * nadie los haya tocado. Una edición parcial modifica lo que se mandó y
     * nada más.
     */
    private function columnas(array $data, Request $request): array
    {
        $u = $request->attributes->get('usuario');

        $mapa = [
            'nombre' => 'NomAux',
            'fantasia' => 'NoFAux',
            'giro' => 'GirAux',
            'direccion' => 'DirAux',
            'comuna' => 'ComAux',
            'ciudad' => 'CiuAux',
            'fono' => 'FonAux1',
            'email' => 'EMail',
            'email_dte' => 'eMailDTE',
        ];

        $cols = [];
        foreach ($mapa as $campo => $columna) {
            if (array_key_exists($campo, $data)) {
                $cols[$columna] = $data[$campo] !== '' ? $data[$campo] : null;
            }
        }

        // `DiaPlazo` es varchar(2) en Softland, no un entero: se guarda «07», no «7».
        if (array_key_exists('dias_plazo', $data)) {
            $cols['DiaPlazo'] = $data['dias_plazo'] === null
                ? null
                : str_pad((string) $data['dias_plazo'], 2, '0', STR_PAD_LEFT);
        }

        // `Region` no se pide en el formulario: sale de la comuna, que ya la
        // determina. Si se dejara en blanco, el cliente no aparecería en los
        // informes de Softland que agrupan por región.
        if (! empty($data['comuna'])) {
            $cols['Region'] = DB::connection('softland')->table('softland.cwtcomu')
                ->where('ComCod', $data['comuna'])->value('id_Region');
        }

        // El RUT se escribe una sola vez, al crear. Es la clave del cliente:
        // cambiarlo no es corregir un dato, es hablar de otra persona.
        if (isset($data['rut'])) {
            $cols['RutAux'] = Rut::formatear($data['rut']);
            $cols['NoFAux'] ??= $data['nombre'];
        }

        // Auditoría: los mismos campos que llena Softland. `Usuario` es
        // varchar(8), así que el nombre largo se corta — es lo que hace el ERP.
        return $cols + [
            'Usuario' => substr((string) ($u->softland_user ?: $u->email), 0, 8),
            'Sistema' => 'NW',
            'Proceso' => 'App de ventas',
            'FechaUlMod' => now(),
        ];
    }

    /**
     * Reemplaza los contactos del cliente.
     *
     * `cwtaxco` no tiene id: la clave es cliente + nombre, y así la usa el resto
     * de Softland (`nwcotiza.NomCon` guarda el nombre, no una referencia). Por
     * eso editar contactos es borrar los del cliente y volver a escribirlos:
     * no hay forma estable de decir «este contacto, el que se llamaba así».
     */
    private function guardarContactos(string $codigo, array $contactos, Request $request, bool $reemplazar = false): void
    {
        $conn = DB::connection('softland');
        $u = $request->attributes->get('usuario');

        $conn->transaction(function () use ($conn, $codigo, $contactos, $reemplazar, $u) {
            if ($reemplazar) {
                $conn->table('softland.cwtaxco')->where('CodAuc', $codigo)->delete();
            }

            $vistos = [];
            foreach ($contactos as $c) {
                $nombre = trim($c['nombre']);
                // Dos contactos con el mismo nombre reventarían la clave.
                if ($nombre === '' || in_array($nombre, $vistos, true)) {
                    continue;
                }
                $vistos[] = $nombre;

                $conn->table('softland.cwtaxco')->insert([
                    'CodAuc' => $codigo,
                    'NomCon' => $nombre,
                    'CarCon' => $c['cargo'] ?? null,
                    'FonCon' => $c['fono'] ?? null,
                    'Email' => $c['email'] ?? null,
                    'Usuario' => substr((string) ($u->softland_user ?: $u->email), 0, 10),
                    'Sistema' => 'NW',
                    'Proceso' => 'App de ventas',
                    'FechaUlMod' => now(),
                ]);
            }
        });
    }

    /**
     * La ficha se arma con el mismo mapeo que usa la descarga de maestros. Que
     * el cliente recién creado se vea idéntico al que llegó por sincronización
     * no es prolijidad: si no, la pantalla muestra un dato distinto según de
     * dónde venga y nadie sabe cuál creer.
     */
    private function buscar(string $codigo): ?array
    {
        return $this->maestros->uno('clientes', ['CodAux' => $codigo]);
    }

    private function contactosDe(string $codigo): array
    {
        return $this->maestros->varios('contactos', ['CodAuc' => $codigo]);
    }
}
