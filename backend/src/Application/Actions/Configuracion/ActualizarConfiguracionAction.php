<?php

declare(strict_types=1);

namespace Beluga\Application\Actions\Configuracion;

use Beluga\Application\Actions\Action;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Log\LoggerInterface;

class ActualizarConfiguracionAction extends Action
{
    /** Solo se permiten estas claves desde la API. */
    private const PERMITIDAS = [
        'horario_grid_inicio', 'horario_grid_fin', 'horario_cupo_grupal',
        'reposicion_dias_vigencia', 'ausencia_horas_aviso',
        'asistencia_min_antes', 'asistencia_retardo_max', 'asistencia_desde',
    ];

    /** El cliente lo quiso configurable, pero nunca por debajo de 2 horas. */
    private const AVISO_MINIMO = 2.0;

    public function __construct(
        LoggerInterface $logger,
        private readonly PDO $pdo
    ) {
        parent::__construct($logger);
    }

    protected function action(): Response
    {
        $b    = $this->getBody();
        $stmt = $this->pdo->prepare(
            'INSERT INTO configuracion (clave, valor) VALUES (:c, :v)
             ON DUPLICATE KEY UPDATE valor = :v2'
        );

        foreach ($b as $clave => $valor) {
            if (!in_array($clave, self::PERMITIDAS, true)) {
                continue;
            }

            // El piso se valida aquí, no solo en la UI: bajarlo de 2 horas
            // dejaría pasar avisos de último minuto como si fueran con derecho.
            if ($clave === 'ausencia_horas_aviso') {
                if (!is_numeric($valor)) {
                    return $this->error('El aviso mínimo debe ser un número de horas', 422);
                }
                if ((float) $valor < self::AVISO_MINIMO) {
                    return $this->error(
                        sprintf('El aviso mínimo no puede ser menor a %s horas', self::AVISO_MINIMO),
                        422
                    );
                }
            }

            $stmt->execute([':c' => $clave, ':v' => (string) $valor, ':v2' => (string) $valor]);
        }

        $rows = $this->pdo->query('SELECT clave, valor FROM configuracion')->fetchAll();
        $map  = [];
        foreach ($rows as $r) {
            $map[$r['clave']] = $r['valor'];
        }
        return $this->json($map);
    }
}
