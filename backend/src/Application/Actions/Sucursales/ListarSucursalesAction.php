<?php

declare(strict_types=1);

namespace Beluga\Application\Actions\Sucursales;

use Beluga\Application\Actions\Action;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Log\LoggerInterface;

class ListarSucursalesAction extends Action
{
    public function __construct(
        LoggerInterface $logger,
        private readonly PDO $pdo
    ) {
        parent::__construct($logger);
    }

    protected function action(): Response
    {
        $usuarioId = $this->request->getAttribute('usuario_id');

        $stmt = $this->pdo->prepare('SELECT sucursales FROM usuarios WHERE id = ?');
        $stmt->execute([$usuarioId]);
        $sucursalesIds = json_decode($stmt->fetchColumn() ?: '[]', true);

        if ($sucursalesIds === ['*']) {
            $stmt = $this->pdo->query('SELECT id, nombre, direccion FROM sucursales ORDER BY nombre');
        } else {
            $placeholders = implode(',', array_fill(0, count($sucursalesIds), '?'));
            $stmt = $this->pdo->prepare("SELECT id, nombre, direccion FROM sucursales WHERE id IN ($placeholders) ORDER BY nombre");
            $stmt->execute($sucursalesIds);
        }

        $rows = $stmt->fetchAll();
        return $this->json(array_map(fn($r) => [
            'id'        => $r['id'],
            'nombre'    => $r['nombre'],
            'direccion' => $r['direccion'],
        ], $rows));
    }
}
