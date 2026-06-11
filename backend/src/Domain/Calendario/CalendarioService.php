<?php
declare(strict_types=1);
namespace Beluga\Domain\Calendario;
interface CalendarioService
{
    public function obtenerPizarron(string $sucursalId, string $dia, string $fecha): array;
    public function asignar(string $folio, string $slotId, string $fecha, string $estado, string $instructorId): array;
    public function actualizarAsignacion(string $id, array $cambios): ?array;
    public function eliminarAsignacion(string $id): bool;
    public function horarioPorAlumno(string $folio): array;
}