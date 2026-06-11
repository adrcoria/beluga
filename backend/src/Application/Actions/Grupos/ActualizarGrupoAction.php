<?php

declare(strict_types=1);

namespace Beluga\Application\Actions\Grupos;

use Beluga\Application\Actions\Action;
use Beluga\Domain\Grupo\GrupoRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Log\LoggerInterface;

class ActualizarGrupoAction extends Action
{
    public function __construct(
        LoggerInterface $logger,
        private readonly GrupoRepository $repo
    ) {
        parent::__construct($logger);
    }

    protected function action(): Response
    {
        $id = (string) $this->args['id'];
        $b  = $this->getBody();

        $datos = [];
        if (array_key_exists('nombre', $b))  $datos['nombre']  = $b['nombre'];
        if (array_key_exists('alumnos', $b)) $datos['alumnos'] = $b['alumnos'];

        $grupo = $this->repo->actualizar($id, $datos);
        if (!$grupo) {
            return $this->error('Grupo no encontrado', 404);
        }
        return $this->json($grupo);
    }
}
