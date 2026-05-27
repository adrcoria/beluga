<?php

declare(strict_types=1);

namespace Beluga\Domain\Plan;

interface PlanRepository
{
    /** @return array<int,array> */
    public function listar(): array;

    public function crear(array $datos): array;

    public function actualizarPorId(string $id, array $datos): ?array;

    public function eliminar(string $id): void;
}
