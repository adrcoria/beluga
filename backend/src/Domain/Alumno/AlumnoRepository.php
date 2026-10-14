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
    /** Agrega un programa (modalidad) adicional a un alumno. */
    public function crearPrograma(string $folio, array $datos): array;
    /** Actualiza un programa existente. */
    public function actualizarPrograma(string $programaId, array $datos): array;
    /** Da de baja un programa (conserva historial). */
    public function bajaPrograma(string $programaId): array;
    /**
     * Cambia el día de pago de un programa. Aplica a partir de la SIGUIENTE
     * mensualidad: los cobros ya generados conservan sus fechas.
     */
    public function cambiarDiaPago(string $programaId, int $diaPago): array;
    /**
     * Reactiva un programa dado de baja, anclando su ciclo a la fecha de pago
     * que se confirme. Reactiva también al alumno si estaba de baja.
     */
    public function reactivarPrograma(string $programaId, string $fechaPago): array;
}
