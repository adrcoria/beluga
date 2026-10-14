<?php

declare(strict_types=1);

namespace Beluga\Application\Actions\Ausencias;

use Beluga\Application\Actions\Action;
use Beluga\Domain\Ausencia\AusenciaRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Log\LoggerInterface;

/** Deshace una falta mal capturada y cancela el comodín que haya generado. */
class AnularAusenciaAction extends Action
{
    public function __construct(
        LoggerInterface $logger,
        private readonly AusenciaRepository $ausencias
    ) {
        parent::__construct($logger);
    }

    protected function action(): Response
    {
        try {
            $this->ausencias->anular((string) $this->resolveArg('id'));
            return $this->json(['anulada' => true]);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), 409);
        }
    }
}
