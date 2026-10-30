<?php

declare(strict_types=1);

namespace Beluga\Domain\Asistencia;

interface AsistenciaRepository
{
    /**
     * Las clases que un día tenía programadas la sucursal, ya cruzadas con lo
     * registrado: cada renglón es un alumno en una clase, con su estado.
     *
     * Une las tres fuentes del pizarrón: horario recurrente vigente ese día
     * (expandiendo los grupos a sus miembros), reposiciones agendadas y
     * clases muestra.
     */
    public function clasesDelDia(string $sucursalId, string $fecha): array;

    /**
     * Registra la llegada de un alumno (escaneo de QR o alta manual).
     *
     * Solo vale si llega dentro de la ventana de alguna de sus clases de hoy:
     * desde unos minutos antes de que empiece y hasta que termina. Resuelve
     * sola de cuál clase se trata. Idempotente: si ya estaba registrado en
     * esa clase, lo devuelve con yaRegistrado=true.
     *
     * Lanza RuntimeException(404) si el folio no existe y RuntimeException(409)
     * si llegó fuera de la ventana, con el motivo en el mensaje.
     */
    public function registrar(string $alumnoFolio, ?string $sucursalId, ?string $usuarioId, string $metodo): array;

    /**
     * Pone el estado de un alumno en una clase concreta, a mano.
     * Sirve tanto para corregir un 'no asistió' automático como para marcar
     * la falta antes de que cierre el día.
     */
    public function marcar(
        string $alumnoFolio,
        string $fecha,
        string $horaClase,
        string $estado,
        ?string $sucursalId,
        ?string $usuarioId
    ): array;

    /**
     * Congela un día: a quien tenía clase y no quedó registrado lo marca como
     * 'no asistió'. Solo actúa sobre días que ya terminaron y que no se hayan
     * cerrado antes. Devuelve el resumen del cierre.
     */
    public function cerrarDia(string $sucursalId, string $fecha): array;

    /** Si un día ya fue congelado, con su resumen; null si sigue abierto. */
    public function cierreDe(string $sucursalId, string $fecha): ?array;

    /** Lista las asistencias registradas de una fecha. */
    public function listar(?string $sucursalId, string $fecha): array;

    /** Cuántos minutos antes de su clase puede registrarse el alumno. */
    public function minutosAntes(): int;

    /** Hasta cuántos minutos después de que empieza la clase sigue contando. */
    public function retardoMaximo(): int;

    /** Desde qué fecha se lleva control de asistencia; null si no hay piso. */
    public function controlDesde(): ?string;
}
