<?php

declare(strict_types=1);

namespace Beluga\Domain\Horario;

interface HorarioRepository
{
    /** Celdas vigentes durante la semana del lunes dado (YYYY-MM-DD). */
    public function pizarron(string $sucursalId, string $lunes): array;

    /** Crea una asignación recurrente. Lanza RuntimeException(409) si hay conflicto. */
    public function crear(array $datos): array;

    /** Mueve/edita una asignación con efecto desde el lunes dado (versiona la regla). */
    public function mover(string $id, array $cambios, string $desdeLunes): array;

    /** Elimina la regla por completo, o la cierra desde el lunes dado si ya tuvo vigencia previa. */
    public function eliminar(string $id, ?string $desdeLunes = null): void;

    public function porId(string $id): ?array;

    /** Horario semanal vigente (hoy) de un alumno, directo o vía grupo. */
    public function porAlumno(string $folio): array;

    /**
     * Libera los lugares de un alumno a partir de `$desde`: cierra sus reglas
     * vigentes y borra las que aún no empezaban. Con `$programaId` solo toca las
     * de ese programa; sin él, todas. Devuelve cuántas afectó.
     *
     * Las semanas anteriores conservan el historial: el pizarrón filtra por
     * vigencia, no borra.
     */
    public function liberarDeAlumno(string $folio, string $desde, ?string $programaId = null): int;

    /**
     * Saca al alumno de sus grupos. Con `$modalidad` solo de los grupos que
     * tienen clases de esa modalidad; sin ella, de todos.
     */
    public function quitarDeGrupos(string $folio, ?string $modalidad = null): int;
}
