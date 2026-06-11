<?php

declare(strict_types=1);

namespace Beluga\Application\Actions\Configuracion;

use Beluga\Application\Actions\Action;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Log\LoggerInterface;

class ObtenerConfiguracionAction extends Action
{
    public function __construct(
        LoggerInterface $logger,
        private readonly PDO $pdo
    ) {
        parent::__construct($logger);
    }

    protected function action(): Response
    {
        $rows = $this->pdo->query('SELECT clave, valor FROM configuracion')->fetchAll();
        $map  = [];
        foreach ($rows as $r) {
            $map[$r['clave']] = $r['valor'];
        }
        return $this->json($map);
    }
}
