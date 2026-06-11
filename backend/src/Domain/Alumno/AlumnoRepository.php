<?php

declare(strict_types=1);

namespace Beluga\Domain\Alumno;

interface AlumnoRepository
{
    public function listar(array $filtros = []): array;
    public function porFolio(string $folio): ?array;
    public function crear(array $datos): array;
    public function actualizar(string $folio, array $datos): ?array;
    public function eliminar(string $folio): void;
}
