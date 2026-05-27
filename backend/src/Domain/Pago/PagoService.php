<?php

declare(strict_types=1);

namespace Beluga\Domain\Pago;

interface PagoService
{
    public function registrar(array $datos, string $usuarioId): array;
    public function porAlumno(string $folio): array;
    public function recientes(int $limite): array;
}
