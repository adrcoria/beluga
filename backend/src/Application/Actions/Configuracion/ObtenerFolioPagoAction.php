<?php

declare(strict_types=1);

namespace Beluga\Application\Actions\Configuracion;

use Beluga\Application\Actions\Action;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Log\LoggerInterface;

class ObtenerFolioPagoAction extends Action
{
    public function __construct(
        LoggerInterface $logger,
        private readonly PDO $pdo
    ) {
        parent::__construct($logger);
    }

    protected function action(): Response
    {
        $sucursalId = (string) $this->getQueryParam('sucursal', '');
        if ($sucursalId === '') {
            return $this->error('Falta la sucursal', 422);
        }

        $this->pdo->prepare('INSERT IGNORE INTO folio_pago_seq (sucursal_id, siguiente) VALUES (?, 1)')
                  ->execute([$sucursalId]);

        $stmt = $this->pdo->prepare('SELECT siguiente FROM folio_pago_seq WHERE sucursal_id = ?');
        $stmt->execute([$sucursalId]);

        return $this->json(['siguiente' => (int) $stmt->fetchColumn()]);
    }
}
