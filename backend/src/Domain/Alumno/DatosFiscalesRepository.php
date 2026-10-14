<?php

declare(strict_types=1);

namespace Beluga\Domain\Alumno;

/**
 * Datos fiscales del alumno para facturar. Uno por alumno: son los vigentes y
 * se reusan en cada factura. La copia histórica de cada factura vive en el
 * cobro (`cobros.fact_*`), no aquí.
 */
interface DatosFiscalesRepository
{
    public function porAlumno(string $folio): ?array;

    /** Alta o actualización (upsert). Devuelve los datos guardados. */
    public function guardar(string $folio, array $datos): array;

    public function eliminar(string $folio): void;
}
