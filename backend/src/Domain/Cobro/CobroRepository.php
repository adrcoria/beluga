<?php

declare(strict_types=1);

namespace Beluga\Domain\Cobro;

interface CobroRepository
{
    public function listar(array $filtros = []): array;
    public function porId(string $id): ?array;
    public function registrarAbono(string $cobroId, float $monto, string $metodo, string $usuarioId, string $sucursalId, bool $requiereFactura = false): array;
    /** Actualiza el estado de facturación de un cobro (solo los campos provistos). */
    public function actualizarFactura(string $cobroId, ?bool $requiere, ?bool $realizada): array;
    public function crearInscripcion(string $alumnoFolio, float $monto, string $fecha): array;
    /** Genera cobros de mensualidad para todos los alumnos activos sin cobro en su periodo actual. */
    public function generarManual(): int;
    /** Pagos (abonos) registrados de un cobro, del más reciente al más antiguo. */
    public function pagosDe(string $cobroId): array;
}
