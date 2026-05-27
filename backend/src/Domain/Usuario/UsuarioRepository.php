<?php

declare(strict_types=1);

namespace Beluga\Domain\Usuario;

interface UsuarioRepository
{
    public function listar(): array;
    public function crear(array $datos): array;
    public function actualizar(string $id, array $datos): ?array;
}
