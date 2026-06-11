<?php
declare(strict_types=1);
namespace Beluga\Application\Actions\Slots;
use Beluga\Application\Actions\Action;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Log\LoggerInterface;
use PDO;
class EliminarSlotAction extends Action
{
    public function __construct(LoggerInterface $logger, private readonly PDO $pdo) { parent::__construct($logger); }
    protected function action(): Response
    {
        $id = $this->resolveArg('id');
        $this->pdo->prepare('DELETE FROM asignaciones WHERE slot_id = ?')->execute([$id]);
        $stmt = $this->pdo->prepare('DELETE FROM slots WHERE id = ?');
        $stmt->execute([$id]);
        if (!$stmt->rowCount()) return $this->error('Slot no encontrado', 404);
        return $this->json(['ok' => true]);
    }
}