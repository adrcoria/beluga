<?php

declare(strict_types=1);

namespace Beluga\Application\Actions\Horarios;

use Beluga\Application\Actions\Action;
use Beluga\Domain\Horario\HorarioRepository;
use Beluga\Domain\Instructor\InstructorRepository;
use Beluga\Domain\Reposicion\ReposicionRepository;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Log\LoggerInterface;

class ObtenerPizarronAction extends Action
{
    public function __construct(
        LoggerInterface $logger,
        private readonly HorarioRepository $horarios,
        private readonly InstructorRepository $instructores,
        private readonly ReposicionRepository $reposiciones,
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

        // Lunes de la semana solicitada (por defecto, la semana actual)
        $semana = (string) $this->getQueryParam('semana', date('Y-m-d'));
        $ts     = strtotime($semana) ?: time();
        $dow    = (int) date('N', $ts);                 // 1=Lun … 7=Dom
        $lunes  = date('Y-m-d', strtotime('-' . ($dow - 1) . ' days', $ts));
        $sabado = date('Y-m-d', strtotime($lunes . ' +5 days'));

        $celdas       = $this->horarios->pizarron($sucursalId, $lunes);
        $reposiciones = $this->reposiciones->agendadasEnSemana($sucursalId, $lunes, $sabado);

        return $this->json([
            'semana'       => ['lunes' => $lunes, 'sabado' => $sabado],
            'config'       => $this->config(),
            'instructores' => $this->instructores->listar($sucursalId),
            'celdas'       => array_merge($celdas, $reposiciones),
        ]);
    }

    private function config(): array
    {
        $rows = $this->pdo->query('SELECT clave, valor FROM configuracion')->fetchAll();
        $map  = [];
        foreach ($rows as $r) {
            $map[$r['clave']] = $r['valor'];
        }
        return [
            'horaInicio' => $map['horario_grid_inicio'] ?? '08:00',
            'horaFin'    => $map['horario_grid_fin']    ?? '21:00',
            'cupoGrupal' => (int) ($map['horario_cupo_grupal'] ?? 6),
        ];
    }
}
