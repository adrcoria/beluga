<?php

declare(strict_types=1);

namespace Beluga\Application\Actions\Configuracion;

use Beluga\Application\Actions\Action;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Log\LoggerInterface;

class ActualizarFolioPagoAction extends Action
{
    public function __construct(
        LoggerInterface $logger,
        private readonly PDO $pdo
    ) {
        parent::__construct($logger);
    }

    protected function action(): Response
    {
        $b          = $this->getBody();
        $sucursalId = (string) ($b['sucursalId'] ?? '');
        $nuevo      = (int) ($b['siguiente'] ?? 0);

        if ($sucursalId === '') {
            return $this->error('Falta la sucursal', 422);
        }
        if ($nuevo < 1) {
            return $this->error('El folio debe ser un número mayor a cero', 422);
        }

        $this->pdo->prepare('INSERT IGNORE INTO folio_pago_seq (sucursal_id, siguiente) VALUES (?, 1)')
                  ->execute([$sucursalId]);

        $stmt = $this->pdo->prepare('SELECT siguiente FROM folio_pago_seq WHERE sucursal_id = ?');
        $stmt->execute([$sucursalId]);
        $actual = (int) $stmt->fetchColumn();

        // Solo se permite subir el contador, nunca repetir folios ya emitidos
        if ($nuevo < $actual) {
            return $this->error("Solo se puede aumentar el folio. El próximo actual es $actual.", 409);
        }

        $this->pdo->prepare('UPDATE folio_pago_seq SET siguiente = :n WHERE sucursal_id = :suc')
                  ->execute([':n' => $nuevo, ':suc' => $sucursalId]);

        return $this->json(['siguiente' => $nuevo]);
    }
}
