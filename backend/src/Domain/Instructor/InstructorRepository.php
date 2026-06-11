<?php

declare(strict_types=1);

namespace Beluga\Domain\Instructor;

interface InstructorRepository
{
    public function listar(?string $sucursalId = null, bool $todos = false): array;
    public function porId(string $id): ?array;
    public function crear(array $datos): array;
    public function actualizar(string $id, array $datos): array;
    public function eliminar(string $id): void;
}
