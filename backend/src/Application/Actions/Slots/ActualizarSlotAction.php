<?php
declare(strict_types=1);
namespace Beluga\Application\Actions\Slots;
use Beluga\Application\Actions\Action;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Log\LoggerInterface;
use PDO;
class ActualizarSlotAction extends Action
{
    public function __construct(LoggerInterface $logger, private readonly PDO $pdo) { parent::__construct($logger); }
    protected function action(): Response
    {
        $id = $this->resolveArg('id');
        $b  = $this->getBody();
        $this->pdo->prepare(
            'UPDATE slots SET dia=:dia, hora_inicio=:hi, hora_fin=:hf, modalidad=:mod, cupo_max=:cupo, activo=:activo WHERE id=:id'
        )->execute([':dia'=>$b['dia'],':hi'=>$b['horaInicio'],':hf'=>$b['horaFin'],':mod'=>$b['modalidad'],':cupo'=>$b['cupoMax'],':activo'=>($b['activo']??true)?1:0,':id'=>$id]);
        $stmt = $this->pdo->prepare('SELECT * FROM slots WHERE id = ?');
        $stmt->execute([$id]);
        $r = $stmt->fetch();
        if (!$r) return $this->error('Slot no encontrado', 404);
        return $this->json(['id'=>$r['id'],'sucursalId'=>$r['sucursal_id'],'dia'=>$r['dia'],'horaInicio'=>$r['hora_inicio'],'horaFin'=>$r['hora_fin'],'modalidad'=>$r['modalidad'],'cupoMax'=>(int)$r['cupo_max'],'activo'=>(bool)$r['activo']]);
    }
}