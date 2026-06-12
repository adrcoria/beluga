<?php

declare(strict_types=1);

namespace Beluga\Application\Actions\Asistencia;

use Beluga\Application\Actions\Action;
use Beluga\Domain\Asistencia\AsistenciaRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Log\LoggerInterface;
use RuntimeException;

class RegistrarAsistenciaAction extends Action
{
    public function __construct(
        LoggerInterface $logger,
        private readonly AsistenciaRepository $repo
    ) {
        parent::__construct($logger);
    }

    protected function action(): Response
    {
        $b = $this->getBody();
        $folio = trim((string) ($b['alumnoFolio'] ?? ''));
        if ($folio === '') {
            return $this->error('Falta el folio del alumno', 422);
        }

        try {
            $res = $this->repo->registrar(
                $folio,
                $b['sucursalId'] ?? null,
                $this->request->getAttribute('usuario_id'),
                (string) ($b['metodo'] ?? 'qr'),
            );
        } catch (RuntimeException $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 400);
        }

        return $this->json($res, $res['yaRegistrado'] ? 200 : 201);
    }
}
