<?php

declare(strict_types=1);

namespace Beluga\Domain\Reposicion;

interface ReposicionRepository
{
    /** Lista comodines de una sucursal, opcionalmente por estado. */
    public function listar(string $sucursalId, ?string $estado = null): array;

    public function porId(string $id): ?array;

    /** Crea un comodín manual (estado pendiente, con vigencia configurable). */
    public function crearManual(array $datos): array;

    /** Coloca un comodín en una celda + fecha concreta. Lanza RuntimeException(409) si hay conflicto. */
    public function agendar(string $id, array $cambios): array;

    /** Devuelve una reposición agendada a pendiente (la des-coloca). */
    public function desagendar(string $id): array;

    public function cancelar(string $id): void;

    /** Reposiciones agendadas dentro de una semana, en forma de celda para el pizarrón. */
    public function agendadasEnSemana(string $sucursalId, string $lunes, string $sabado): array;

    /** Marca como 'vencida' los comodines pendientes cuya vigencia ya pasó. */
    public function vencerCaducados(): int;

    /**
     * Marca como 'tomada' las reposiciones agendadas cuya fecha ya pasó: así
     * salen solas del pizarrón sin que nadie tenga que limpiarlas.
     */
    public function marcarTomadas(): int;

    /** Faltas (fecha_falta) de la semana, para pintarlas en el pizarrón. */
    public function faltasEnSemana(string $sucursalId, string $lunes, string $sabado): array;
}
