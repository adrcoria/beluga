<?php

declare(strict_types=1);

namespace Beluga\Application\Actions\Horarios;

use Beluga\Application\Actions\Action;
use Beluga\Domain\Horario\HorarioRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Log\LoggerInterface;
use RuntimeException;

class CrearAsignacionAction extends Action
{
    public function __construct(
        LoggerInterface $logger,
        private readonly HorarioRepository $repo
    ) {
        parent::__construct($logger);
    }

    protected function action(): Response
    {
        $b = $this->getBody();

        foreach (['sucursalId', 'instructorId', 'dia', 'horaInicio', 'modalidad', 'vigenteDesde'] as $req) {
            if (empty($b[$req])) {
                return $this->error("Falta el campo: $req", 422);
            }
        }

        try {
            $celda = $this->repo->crear([
                'sucursalId'   => $b['sucursalId'],
                'instructorId' => $b['instructorId'],
                'dia'          => $b['dia'],
                'horaInicio'   => $b['horaInicio'],
                'duracionMin'  => (int) ($b['duracionMin'] ?? 30),
                'modalidad'    => $b['modalidad'],
                'alumnoFolio'  => $b['alumnoFolio'] ?? null,
                'grupoId'      => $b['grupoId'] ?? null,
                'vigenteDesde' => $b['vigenteDesde'],
                'vigenteHasta' => $b['vigenteHasta'] ?? null,
            ]);
        } catch (RuntimeException $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 400);
        }

        return $this->json($celda, 201);
    }
}
