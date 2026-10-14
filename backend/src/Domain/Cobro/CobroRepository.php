<?php

declare(strict_types=1);

namespace Beluga\Domain\Cobro;

interface CobroRepository
{
    public function listar(array $filtros = []): array;
    public function porId(string $id): ?array;
    public function registrarAbono(string $cobroId, float $monto, string $metodo, string $usuarioId, string $sucursalId, bool $requiereFactura = false, int $meses = 1): array;
    /** Registra un pago adelantado (N meses) de un programa, aunque no tenga cobros pendientes. */
    public function registrarPagoAdelantado(string $programaId, int $meses, string $metodo, string $usuarioId, string $sucursalId, bool $requiereFactura = false): array;
    /** Actualiza el estado de facturación de un cobro (solo los campos provistos). */
    public function actualizarFactura(string $cobroId, ?bool $requiere, ?bool $realizada): array;
    /**
     * Marca un cobro como "no se cobra" con su motivo. El mes se da por cubierto
     * y el vencimiento del programa avanza.
     */
    public function condonar(string $cobroId, string $motivo, string $usuarioId): array;
    public function crearInscripcion(string $alumnoFolio, float $monto, string $fecha): array;
    /** Si ya se le generó inscripción alguna vez (pagada o no): es un cobro único por vida. */
    public function tieneInscripcion(string $alumnoFolio): bool;
    /** Crea la primera mensualidad de un alumno recién dado de alta (cobrable de inmediato). */
    public function crearMensualidadInicial(string $folio): void;
    /** Genera cobros de mensualidad para todos los alumnos activos sin cobro en su periodo actual. */
    public function generarManual(): int;
    /** Pagos (abonos) registrados de un cobro, del más reciente al más antiguo. */
    public function pagosDe(string $cobroId): array;
}
