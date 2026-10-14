<?php

declare(strict_types=1);

namespace Beluga\Domain\Usuario;

interface UsuarioRepository
{
    /** @param bool $incluirInactivos true para ver también a los dados de baja. */
    public function listar(bool $incluirInactivos = false): array;

    public function crear(array $datos): array;

    public function actualizar(string $id, array $datos): ?array;

    /**
     * Qué dejó registrado el usuario en el sistema. Mientras tenga algo no se
     * puede borrar: pagos e inhábiles lo referencian con llave foránea y la
     * contabilidad tiene que seguir diciendo quién cobró.
     *
     * @return array{pagos:int, inhabiles:int, condonaciones:int}
     */
    public function huella(string $id): array;

    /**
     * Borra al usuario de verdad. Solo para inactivos sin huella.
     *
     * @throws \RuntimeException si está activo o dejó movimientos
     */
    public function eliminar(string $id): void;
}
