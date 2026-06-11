<?php
declare(strict_types=1);
namespace Beluga\Application\Actions\Slots;
use Beluga\Application\Actions\Action;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Log\LoggerInterface;
use PDO;
class ListarSlotsAction extends Action
{
    public function __construct(LoggerInterface $logger, private readonly PDO $pdo) { parent::__construct($logger); }
    protected function action(): Response
    {
        $suc = $this->getQueryParam('sucursal', '');
        $dia = $this->getQueryParam('dia', '');
        $where = []; $params = [];
        if ($suc) { $where[] = 'sucursal_id = :suc'; $params[':suc'] = $suc; }
        if ($dia) { $where[] = 'dia = :dia';         $params[':dia'] = $dia; }
        $wc   = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        $stmt = $this->pdo->prepare("SELECT * FROM slots $wc ORDER BY FIELD(dia,'L','M','X','J','V','S'), hora_inicio");
        $stmt->execute($params);
        return $this->json(array_map(fn($r) => ['id'=>$r['id'],'sucursalId'=>$r['sucursal_id'],'dia'=>$r['dia'],'horaInicio'=>$r['hora_inicio'],'horaFin'=>$r['hora_fin'],'modalidad'=>$r['modalidad'],'cupoMax'=>(int)$r['cupo_max'],'activo'=>(bool)$r['activo']], $stmt->fetchAll()));
    }
}