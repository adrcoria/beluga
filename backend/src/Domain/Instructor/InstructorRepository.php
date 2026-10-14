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

    /**
     * Verifica que el instructor trabaje ese día y que la clase quepa dentro de
     * su horario. Lanza RuntimeException(409) con un mensaje entendible si no.
     *
     * Es el único lugar donde vive esta regla: la usan tanto la asignación
     * recurrente del pizarrón como el agendado de reposiciones, que antes
     * tenían cada una su copia.
     *
     * Sin filas de horario configuradas el instructor no tiene restricción,
     * igual que antes de que existiera el horario por día.
     */
    public function disponibleEn(string $instructorId, string $dia, string $horaInicio, int $duracionMin): void;
}
