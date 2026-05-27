<?php

declare(strict_types=1);

namespace Beluga\Domain\Calendario;

interface CalendarioService
{
    /**
     * Devuelve el pizarrón de un día: slots por instructor con sus asignaciones.
     */
    public function obtenerPizarron(string $sucursalId, string $dia, string $fecha): array;

    /**
     * Asigna un alumno a un slot en una fecha concreta.
     * Valida modalidad, cupo y disponibilidad del alumno.
     */
    public function asignar(string $folio, string $slotId, string $fecha, string $estado): array;

    /**
     * Actualiza estado y/o nota de una asignación existente.
     */
    public function actualizarAsignacion(string $id, array $cambios): ?array;

    /**
     * Devuelve los slots compatibles para un alumno en una semana dada.
     * Filtra por modalidad, disponibilidad y cupo.
     */
    public function slotsSugeridos(string $folio, string $fechaInicio): array;

    /**
     * Devuelve los slots distintos donde el alumno tiene asignaciones activas.
     * Usado para mostrar el horario semanal en el detalle del alumno.
     */
    public function horarioPorAlumno(string $folio): array;
}
