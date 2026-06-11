<?php

declare(strict_types=1);

namespace Beluga\Application\Actions\Modalidades;

use Beluga\Application\Actions\Action;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Log\LoggerInterface;

class ListarModalidadesAction extends Action
{
    public function __construct(
        LoggerInterface $logger,
        private readonly PDO $pdo
    ) {
        parent::__construct($logger);
    }

    protected function action(): Response
    {
        $stmt = $this->pdo->query(
            "SELECT clave, label, descripcion FROM modalidades_catalogo WHERE activo = 1 ORDER BY FIELD(clave,'PART','GRUPAL','MATRO')"
        );
        return $this->json($stmt->fetchAll());
    }
}
