<?php

declare(strict_types=1);

namespace Beluga\Infrastructure\Persistence\MySql;

use Beluga\Domain\Alumno\DatosFiscalesRepository;
use PDO;
use RuntimeException;

class DatosFiscalesMySqlRepository implements DatosFiscalesRepository
{
    public function __construct(private readonly PDO $pdo) {}

    public function porAlumno(string $folio): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM alumno_datos_fiscales WHERE alumno_folio = ?');
        $stmt->execute([$folio]);
        $row = $stmt->fetch();
        return $row ? $this->mapear($row) : null;
    }

    public function guardar(string $folio, array $datos): array
    {
        $razonSocial = trim((string) ($datos['razonSocial'] ?? ''));
        $rfc         = strtoupper(trim((string) ($datos['rfc'] ?? '')));

        if ($razonSocial === '') {
            throw new RuntimeException('La razón social es requerida');
        }
        // 12 para persona moral, 13 para física. No se valida el dígito
        // verificador: si el SAT lo rechaza, se corrige aquí y se vuelve a pedir.
        if (!preg_match('/^[A-ZÑ&]{3,4}\d{6}[A-Z0-9]{3}$/u', $rfc)) {
            throw new RuntimeException('El RFC no tiene un formato válido');
        }

        $this->pdo->prepare(
            'INSERT INTO alumno_datos_fiscales
                (alumno_folio, razon_social, rfc, regimen_fiscal, uso_cfdi, codigo_postal, email)
             VALUES (:folio, :razon, :rfc, :regimen, :uso, :cp, :email)
             ON DUPLICATE KEY UPDATE
                razon_social   = VALUES(razon_social),
                rfc            = VALUES(rfc),
                regimen_fiscal = VALUES(regimen_fiscal),
                uso_cfdi       = VALUES(uso_cfdi),
                codigo_postal  = VALUES(codigo_postal),
                email          = VALUES(email)'
        )->execute([
            ':folio'   => $folio,
            ':razon'   => $razonSocial,
            ':rfc'     => $rfc,
            ':regimen' => $datos['regimenFiscal'] ?: null,
            ':uso'     => $datos['usoCfdi']       ?: null,
            ':cp'      => $datos['codigoPostal']  ?: null,
            ':email'   => $datos['email']         ?: null,
        ]);

        return $this->porAlumno($folio);
    }

    public function eliminar(string $folio): void
    {
        $this->pdo->prepare('DELETE FROM alumno_datos_fiscales WHERE alumno_folio = ?')->execute([$folio]);
    }

    private function mapear(array $r): array
    {
        return [
            'alumnoFolio'   => $r['alumno_folio'],
            'razonSocial'   => $r['razon_social'],
            'rfc'           => $r['rfc'],
            'regimenFiscal' => $r['regimen_fiscal'],
            'usoCfdi'       => $r['uso_cfdi'],
            'codigoPostal'  => $r['codigo_postal'],
            'email'         => $r['email'],
            'actualizadoEn' => $r['actualizado_en'],
        ];
    }
}
