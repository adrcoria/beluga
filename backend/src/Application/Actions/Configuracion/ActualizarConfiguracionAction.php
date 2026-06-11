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
    private const PERMITIDAS = ['horario_grid_inicio', 'horario_grid_fin', 'horario_cupo_grupal', 'reposicion_dias_vigencia'];

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
