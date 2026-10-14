<?php

declare(strict_types=1);

namespace Beluga\Infrastructure\Persistence\MySql;

use Beluga\Domain\Instructor\InstructorRepository;
use PDO;
use RuntimeException;

class InstructorMySqlRepository implements InstructorRepository
{
    private const DIAS = ['L' => 'lunes', 'M' => 'martes', 'X' => 'miércoles',
                          'J' => 'jueves', 'V' => 'viernes', 'S' => 'sábado'];

    public function __construct(private readonly PDO $pdo) {}

    public function disponibleEn(string $instructorId, string $dia, string $horaInicio, int $duracionMin): void
    {
        $stmt = $this->pdo->prepare('SELECT dia, hora_inicio, hora_fin FROM instructor_horario WHERE instructor_id = ?');
        $stmt->execute([$instructorId]);
        $horario = $stmt->fetchAll();

        // Sin horario configurado no se restringe nada: es como trabajaba el
        // sistema antes de que existiera esta tabla.
        if (!$horario) {
            return;
        }

        $delDia = null;
        foreach ($horario as $h) {
            if ($h['dia'] === $dia) { $delDia = $h; break; }
        }

        $nombre = $this->nombreDe($instructorId);

        if ($delDia === null) {
            $dias = array_map(fn($h) => self::DIAS[$h['dia']] ?? $h['dia'], $horario);
            throw new RuntimeException(
                "$nombre no trabaja los " . (self::DIAS[$dia] ?? $dia) . '. Solo viene: ' . implode(', ', $dias) . '.',
                409
            );
        }

        $ini = substr((string) $delDia['hora_inicio'], 0, 5);
        $fin = substr((string) $delDia['hora_fin'], 0, 5);
        $hf  = date('H:i', (int) strtotime($horaInicio) + $duracionMin * 60);

        if ($horaInicio < $ini || $hf > $fin) {
            throw new RuntimeException(
                "$nombre solo está disponible los " . (self::DIAS[$dia] ?? $dia) . " de $ini a $fin.",
                409
            );
        }
    }

    private function nombreDe(string $instructorId): string
    {
        $stmt = $this->pdo->prepare('SELECT nombre FROM instructores WHERE id = ?');
        $stmt->execute([$instructorId]);
        return (string) ($stmt->fetchColumn() ?: 'El instructor');
    }

    /** Horario por día del instructor, como mapa dia => {horaInicio, horaFin}. */
    private function horarioPorDia(string $instructorId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT dia, hora_inicio, hora_fin FROM instructor_horario
             WHERE instructor_id = ? ORDER BY FIELD(dia,'L','M','X','J','V','S')"
        );
        $stmt->execute([$instructorId]);

        $out = [];
        foreach ($stmt->fetchAll() as $h) {
            $out[$h['dia']] = [
                'horaInicio' => substr((string) $h['hora_inicio'], 0, 5),
                'horaFin'    => substr((string) $h['hora_fin'], 0, 5),
            ];
        }
        return $out;
    }

    /** Reemplaza el horario del instructor por el que venga. */
    private function guardarHorario(string $instructorId, array $horario): void
    {
        $this->pdo->prepare('DELETE FROM instructor_horario WHERE instructor_id = ?')->execute([$instructorId]);

        $ins = $this->pdo->prepare(
            'INSERT INTO instructor_horario (instructor_id, dia, hora_inicio, hora_fin) VALUES (?, ?, ?, ?)'
        );
        $dias = [];
        $desde = null;
        $hasta = null;

        foreach ($horario as $dia => $rango) {
            if (empty($rango['horaInicio']) || empty($rango['horaFin'])) continue;
            if (!isset(self::DIAS[$dia]))                                continue;
            if ($rango['horaFin'] <= $rango['horaInicio']) {
                throw new RuntimeException(
                    'En ' . self::DIAS[$dia] . ', la hora de salida debe ser posterior a la de entrada.',
                    422
                );
            }
            $ins->execute([$instructorId, $dia, $rango['horaInicio'], $rango['horaFin']]);

            $dias[] = $dia;
            $desde  = $desde === null ? $rango['horaInicio'] : min($desde, $rango['horaInicio']);
            $hasta  = $hasta === null ? $rango['horaFin']    : max($hasta, $rango['horaFin']);
        }

        $this->sincronizarColumnasViejas($instructorId, $dias, $desde, $hasta);
    }

    /**
     * Mantiene al día `disp_hora_inicio`/`disp_hora_fin`/`dias_asiste` como el
     * resumen del horario por día (la franja que abarca y los días que viene).
     *
     * Ya nada del backend las lee, pero el frontend que está desplegado sí:
     * mientras no se suba la versión nueva, sin esto un maestro editado con el
     * horario por día se vería como disponible a toda hora allá.
     */
    private function sincronizarColumnasViejas(string $instructorId, array $dias, ?string $desde, ?string $hasta): void
    {
        $this->pdo->prepare(
            'UPDATE instructores SET disp_hora_inicio = :dhi, disp_hora_fin = :dhf, dias_asiste = :dias WHERE id = :id'
        )->execute([
            ':dhi'  => $desde,
            ':dhf'  => $hasta,
            ':dias' => json_encode($dias),
            ':id'   => $instructorId,
        ]);
    }

    public function listar(?string $sucursalId = null, bool $todos = false): array
    {
        $where  = [];
        $params = [];

        if (!$todos) {
            $where[] = 'i.activo = 1';
        }
        if ($sucursalId) {
            $where[] = 'JSON_CONTAINS(i.sucursales, :sid)';
            $params[':sid'] = json_encode($sucursalId);
        }

        $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $sql = "SELECT i.id, i.nombre, i.iniciales, i.telefono, i.email, i.sucursales, i.activo,
                       i.disp_hora_inicio, i.disp_hora_fin, i.dias_asiste,
                       GROUP_CONCAT(im.modalidad_clave ORDER BY im.modalidad_clave SEPARATOR ',') AS modalidades_raw
                FROM instructores i
                LEFT JOIN instructor_modalidades im ON im.instructor_id = i.id
                {$whereClause}
                GROUP BY i.id
                ORDER BY i.nombre";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return array_map($this->mapear(...), $stmt->fetchAll());
    }

    public function porId(string $id): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT i.id, i.nombre, i.iniciales, i.telefono, i.email, i.sucursales, i.activo,
                    i.disp_hora_inicio, i.disp_hora_fin, i.dias_asiste,
                    GROUP_CONCAT(im.modalidad_clave ORDER BY im.modalidad_clave SEPARATOR ',') AS modalidades_raw
             FROM instructores i
             LEFT JOIN instructor_modalidades im ON im.instructor_id = i.id
             WHERE i.id = ?
             GROUP BY i.id"
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ? $this->mapear($row) : null;
    }

    public function crear(array $datos): array
    {
        $id = $this->uuid();

        $this->pdo->beginTransaction();
        try {
            // Las columnas del rango único ya no se reciben: las deriva
            // guardarHorario() a partir del horario por día.
            $this->pdo->prepare(
                'INSERT INTO instructores (id, nombre, iniciales, telefono, email, sucursales)
                 VALUES (:id, :nombre, :iniciales, :telefono, :email, :sucursales)'
            )->execute([
                ':id'         => $id,
                ':nombre'     => $datos['nombre'],
                ':iniciales'  => $datos['iniciales'],
                ':telefono'   => $datos['telefono']  ?? '',
                ':email'      => $datos['email']     ?? null,
                ':sucursales' => json_encode($datos['sucursales'] ?? []),
            ]);

            $this->sincronizarModalidades($id, $datos['niveles'] ?? []);
            $this->guardarHorario($id, $datos['horarioPorDia'] ?? []);

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        return $this->porId($id);
    }

    public function actualizar(string $id, array $datos): array
    {
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare(
                'UPDATE instructores
                 SET nombre=:nombre, iniciales=:iniciales, telefono=:telefono,
                     email=:email, sucursales=:sucursales, activo=:activo
                 WHERE id=:id'
            )->execute([
                ':id'         => $id,
                ':nombre'     => $datos['nombre'],
                ':iniciales'  => $datos['iniciales'],
                ':telefono'   => $datos['telefono']  ?? '',
                ':email'      => $datos['email']     ?? null,
                ':sucursales' => json_encode($datos['sucursales'] ?? []),
                ':activo'     => ($datos['activo'] ?? true) ? 1 : 0,
            ]);

            $this->sincronizarModalidades($id, $datos['niveles'] ?? []);
            // Solo se toca si viene en la petición: así una actualización parcial
            // (p. ej. desactivar al maestro) no le borra el horario sin querer.
            if (array_key_exists('horarioPorDia', $datos)) {
                $this->guardarHorario($id, $datos['horarioPorDia']);
            }

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        return $this->porId($id);
    }

    public function eliminar(string $id): void
    {
        $this->pdo->prepare('DELETE FROM instructor_modalidades WHERE instructor_id = ?')->execute([$id]);
        $this->pdo->prepare('DELETE FROM instructores WHERE id = ?')->execute([$id]);
    }

    private function sincronizarModalidades(string $instructorId, array $modalidades): void
    {
        $this->pdo->prepare('DELETE FROM instructor_modalidades WHERE instructor_id = ?')
                  ->execute([$instructorId]);

        if (empty($modalidades)) return;

        $stmt = $this->pdo->prepare(
            'INSERT INTO instructor_modalidades (instructor_id, modalidad_clave) VALUES (?, ?)'
        );
        foreach ($modalidades as $clave) {
            $stmt->execute([$instructorId, $clave]);
        }
    }

    private function mapear(array $row): array
    {
        return [
            'id'         => $row['id'],
            'nombre'     => $row['nombre'],
            'iniciales'  => $row['iniciales'],
            'telefono'   => $row['telefono'],
            'email'      => $row['email'],
            'niveles'    => $row['modalidades_raw'] ? explode(',', $row['modalidades_raw']) : [],
            'sucursales' => json_decode($row['sucursales'], true),
            'activo'     => (bool) $row['activo'],
            // Horario real: un rango por día. Vacío = sin restricción.
            'horarioPorDia'  => $this->horarioPorDia($row['id']),
            // Campos de la etapa anterior (rango único + días). Se siguen
            // devolviendo mientras haya pantallas viejas leyéndolos; la fuente
            // de verdad es `horarioPorDia`.
            'dispHoraInicio' => $row['disp_hora_inicio'] ? substr((string) $row['disp_hora_inicio'], 0, 5) : null,
            'dispHoraFin'    => $row['disp_hora_fin']    ? substr((string) $row['disp_hora_fin'], 0, 5)    : null,
            'diasAsiste'     => !empty($row['dias_asiste']) ? json_decode($row['dias_asiste'], true) : [],
        ];
    }

    private function uuid(): string
    {
        $data    = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
