<?php
declare(strict_types=1);
namespace Beluga\Application\Actions\Calendario;
use Beluga\Application\Actions\Action;
use Beluga\Domain\Calendario\CalendarioService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Log\LoggerInterface;
class AsignarAlumnoAction extends Action
{
    public function __construct(LoggerInterface $logger, private readonly CalendarioService $servicio) { parent::__construct($logger); }
    protected function action(): Response
    {
        $b = $this->getBody();
        $asignacion = $this->servicio->asignar(
            folio:        $b['alumnoFolio']  ?? '',
            slotId:       $b['slotId']       ?? '',
            fecha:        $b['fecha']        ?? '',
            estado:       $b['estado']       ?? 'regular',
            instructorId: $b['instructorId'] ?? ''
        );
        return $this->json($asignacion, 201);
    }
}