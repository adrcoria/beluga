<?php

declare(strict_types=1);

namespace Beluga\Domain\Ausencia;

/**
 * Faltas avisadas de un alumno a una clase concreta.
 *
 * Regla de negocio (cambiada en oct-2026 a petición del cliente): si el alumno
 * avisa con suficiente anticipación, la falta DA DERECHO a reposición y el
 * comodín se genera solo. Si avisa tarde se registra igual, sin derecho, para
 * que quede constancia y pueda reclamarse.
 *
 * Antes ninguna falta individual generaba reposición: solo los días inhábiles
 * declarados por la escuela repartían comodines.
 */
interface AusenciaRepository
{
    /**
     * Registra la falta y, si procede, genera la reposición ligada.
     *
     * @param array $datos alumnoFolio, sucursalId, fecha, horaClase,
     *                     horarioId?, motivo?, usuarioId?
     * @throws \RuntimeException si la clase ya pasó o ya estaba registrada
     */
    public function registrar(array $datos): array;

    /** Deshace una falta mal capturada y cancela su comodín si lo generó. */
    public function anular(string $id): void;

    public function porId(string $id): ?array;

    /** Faltas de una semana, para pintarlas en el pizarrón. */
    public function enSemana(string $sucursalId, string $lunes, string $sabado): array;

    /**
     * Faltas de un periodo para el reporte, separando con y sin derecho.
     *
     * @param array $filtros sucursalId, desde, hasta, conDerecho?
     */
    public function listar(array $filtros): array;

    /** Horas de anticipación con las que una falta da derecho (configurable). */
    public function umbralHoras(): float;
}
