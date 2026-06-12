<?php

declare(strict_types=1);

namespace Beluga\Domain\Asistencia;

interface AsistenciaRepository
{
    /**
     * Registra la asistencia del alumno en la fecha de hoy.
     * Idempotente: si ya estaba registrado, lo devuelve con yaRegistrado=true.
     * Lanza RuntimeException(404) si el folio no existe.
     */
    public function registrar(string $alumnoFolio, ?string $sucursalId, ?string $usuarioId, string $metodo): array;

    /** Lista las asistencias de una fecha (por defecto hoy). */
    public function listar(?string $sucursalId, string $fecha): array;
}
