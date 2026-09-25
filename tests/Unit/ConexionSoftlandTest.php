<?php

namespace Tests\Unit;

use App\Support\ConectorSoftland;
use App\Support\SoftlandConnection;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;

/**
 * Las dos reglas de la conexión a SQL Server, que se escribieron después de
 * dos días de cortes en producción (24 y 25 de septiembre de 2026): cifrar
 * sólo si el tráfico sale de la máquina, y reintentar lo que falla al conectar
 * y se arregla solo.
 */
class ConexionSoftlandTest extends TestCase
{
    #[DataProvider('hosts')]
    public function test_se_cifra_solo_lo_que_sale_de_la_maquina(string $host, string $esperado): void
    {
        $this->assertSame($esperado, SoftlandConnection::cifrado($host));
    }

    public static function hosts(): array
    {
        return [
            'la instancia con nombre, que es la instalación documentada' => ['localhost\\MSSQLSERVER2022', 'no'],
            'el punto, como lo escribe Softland' => ['.\\MSSQLSERVER2022', 'no'],
            'la forma antigua' => ['(local)', 'no'],
            'por dirección, con puerto' => ['127.0.0.1,1433', 'no'],
            'sin nada escrito' => ['', 'no'],
            'otra máquina de la red' => ['192.168.1.55\\SQLEXPRESS', 'yes'],
            'otra máquina por su nombre' => ['sql-softland', 'yes'],
        ];
    }

    /** La llave de la empresa manda sobre la regla, y en los dos sentidos. */
    public function test_la_llave_de_la_empresa_manda(): void
    {
        $this->assertSame('yes', SoftlandConnection::cifrado('localhost\\MSSQLSERVER2022', true));
        $this->assertSame('yes', SoftlandConnection::cifrado('localhost\\MSSQLSERVER2022', 'yes'));
        $this->assertSame('no', SoftlandConnection::cifrado('sql-softland', 'no'));
        $this->assertSame('no', SoftlandConnection::cifrado('sql-softland', false));

        // Vacío no es «no»: es «no hay llave puesta», y decide la regla.
        $this->assertSame('no', SoftlandConnection::cifrado('localhost\\MSSQLSERVER2022', ''));
        $this->assertSame('yes', SoftlandConnection::cifrado('sql-softland', null));
    }

    /**
     * Lo que se reintenta es lo que está medido que se arregla solo. Una
     * contraseña equivocada reintentada tres veces sigue siendo una contraseña
     * equivocada, y encima tarda el triple en decirlo.
     */
    #[DataProvider('fallos')]
    public function test_solo_se_reintenta_lo_transitorio(string $mensaje, bool $esperado): void
    {
        $transitorio = new ReflectionMethod(ConectorSoftland::class, 'transitorio');

        $this->assertSame($esperado, $transitorio->invoke(null, new PDOException($mensaje)));
    }

    public static function fallos(): array
    {
        return [
            'el de los cortes de septiembre' => [
                'SQLSTATE[08001]: [Microsoft][ODBC Driver 17 for SQL Server]Encryption not supported on the client.',
                true,
            ],
            'el arranque de TLS por su otro nombre' => [
                'SQLSTATE[08001]: [Microsoft][ODBC Driver 17 for SQL Server]SSL Provider: An existing connection was forcibly closed',
                true,
            ],
            'el enlace que se cayó a media conexión' => [
                'SQLSTATE[08S01]: [Microsoft][ODBC Driver 17 for SQL Server]Communication link failure',
                true,
            ],
            'la contraseña equivocada, que no se reintenta' => [
                "SQLSTATE[28000]: [Microsoft][ODBC Driver 17 for SQL Server]Login failed for user 'ventas'.",
                false,
            ],
            'la instancia que no existe, que tampoco' => [
                'SQLSTATE[08001]: [Microsoft][ODBC Driver 17 for SQL Server]SQL Server Network Interfaces: Error Locating Server/Instance Specified',
                false,
            ],
            'la base que no existe' => [
                'SQLSTATE[42000]: [Microsoft][ODBC Driver 17 for SQL Server]Cannot open database "INNOVAGES" requested by the login.',
                false,
            ],
        ];
    }

    /**
     * El bucle de verdad: lo transitorio se reintenta hasta que sale, y lo
     * demás sube al primer intento sin hacer esperar a nadie.
     *
     * El doble reemplaza `createPdoConnection`, que es lo único que abre la
     * conexión de verdad; el bucle que se está comprobando es el de arriba.
     */
    #[DataProvider('tandas')]
    public function test_se_reintenta_hasta_que_sale(string $mensaje, int $fallos, int $esperados, bool $sube): void
    {
        $conector = new class($mensaje, $fallos) extends ConectorSoftland
        {
            public int $intentos = 0;

            public function __construct(private string $mensaje, private int $fallos) {}

            protected function createPdoConnection($dsn, $username, #[\SensitiveParameter] $password, $options)
            {
                if (++$this->intentos <= $this->fallos) {
                    throw new PDOException($this->mensaje);
                }

                return new \stdClass;   // basta con que no sea una excepción
            }
        };

        try {
            $conector->createConnection('sqlsrv:Server=.', ['username' => 'u', 'password' => 'c'], []);
            $this->assertFalse($sube, 'tenía que subir la excepción');
        } catch (PDOException) {
            $this->assertTrue($sube, 'no tenía que subir la excepción');
        }

        $this->assertSame($esperados, $conector->intentos);
    }

    public static function tandas(): array
    {
        $tls = 'SQLSTATE[08001]: [Microsoft][ODBC Driver 17 for SQL Server]Encryption not supported on the client.';
        $clave = 'SQLSTATE[28000]: [Microsoft][ODBC Driver 17 for SQL Server]Login failed for user';

        return [
            'sale a la primera' => [$tls, 0, 1, false],
            'una racha corta: sale al segundo intento' => [$tls, 1, 2, false],
            'sale al tercero, que es el último' => [$tls, 2, 3, false],
            'tres fallos y se rinde, sin un cuarto intento' => [$tls, 9, 3, true],
            'la contraseña equivocada no se reintenta' => [$clave, 9, 1, true],
        ];
    }
}
