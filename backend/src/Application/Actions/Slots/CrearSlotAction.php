<?php
declare(strict_types=1);
namespace Beluga\Application\Actions\Slots;
use Beluga\Application\Actions\Action;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Log\LoggerInterface;
use PDO;
class CrearSlotAction extends Action
{
    public function __construct(LoggerInterface $logger, private readonly PDO $pdo) { parent::__construct($logger); }
    protected function action(): Response
    {
        $b = $this->getBody();
        $id = $this->uuid();
        $this->pdo->prepare(
            'INSERT INTO slots (id, sucursal_id, dia, hora_inicio, hora_fin, modalidad, cupo_max)
             VALUES (:id,:suc,:dia,:hi,:hf,:mod,:cupo)'
        )->execute([':id'=>$id,':suc'=>$b['sucursalId']??'',':dia'=>$b['dia']??'',
                    ':hi'=>$b['horaInicio']??'',':hf'=>$b['horaFin']??'',
                    ':mod'=>$b['modalidad']??'',':cupo'=>$b['cupoMax']??1]);
        $stmt = $this->pdo->prepare('SELECT * FROM slots WHERE id = ?');
        $stmt->execute([$id]);
        $r = $stmt->fetch();
        return $this->json(['id'=>$r['id'],'sucursalId'=>$r['sucursal_id'],'dia'=>$r['dia'],'horaInicio'=>$r['hora_inicio'],'horaFin'=>$r['hora_fin'],'modalidad'=>$r['modalidad'],'cupoMax'=>(int)$r['cupo_max'],'activo'=>(bool)$r['activo']], 201);
    }
    private function uuid(): string { return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',mt_rand(0,0xffff),mt_rand(0,0xffff),mt_rand(0,0xffff),mt_rand(0,0x0fff)|0x4000,mt_rand(0,0x3fff)|0x8000,mt_rand(0,0xffff),mt_rand(0,0xffff),mt_rand(0,0xffff)); }
}