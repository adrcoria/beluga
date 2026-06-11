<?php

declare(strict_types=1);

namespace Beluga\Infrastructure\Persistence\MySql;

use Beluga\Domain\Inhabil\InhabilService;
use PDO;

class InhabilMySqlService implements InhabilService
{
    public function __construct(private readonly PDO $pdo) {}

    // ── Declarar (preview o aplicar) ──────────────────────────────────────
    public function declarar(
        string  $fecha,
        string  $motivo,
        ?string $sucursalId,
        string  $usuarioId,
        bool    $soloPreview = false
    ): array {
        // Clases (alumno+clase) afectadas ese día según el patrón recurrente vigente
        $afectadas = $this->clasesAfectadas($fecha, $sucursalId);

        // Alumnos distintos (para el conteo de cabecera)
        $foliosUnicos = array_values(array_unique(array_column($afectadas, 'folio')));

        if ($soloPreview) {
            return [
                'fecha'            => $fecha,
                'alumnosAfectados' => count($foliosUnicos),
                'clasesAfectadas'  => count($afectadas),
                'detalleAlumnos'   => array_map(fn($a) => [
                    'folio'      => $a['folio'],
                    'nombre'     => $a['nombre'],
                    'hora'       => $a['hora'],
                    'instructor' => $a['instructor'],
                ], $afectadas),
            ];
        }

        // Aplicar: registrar inhábil + generar un comodín por cada clase perdida
        $inhabilId = $this->uuid();
        $vence     = date('Y-m-d', strtotime('+' . $this->diasVigencia() . ' days'));

        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare(
                'INSERT INTO inhabiles (id, fecha, motivo, sucursal_id, declarado_por)
                 VALUES (:id, :fecha, :motivo, :suc, :usr)'
            )->execute([
                ':id'     => $inhabilId,
                ':fecha'  => $fecha,
                ':motivo' => $motivo,
                ':suc'    => $sucursalId,
                ':usr'    => $usuarioId,
            ]);

            $insRep = $this->pdo->prepare(
                'INSERT INTO reposiciones
                    (id, alumno_folio, sucursal_id, motivo, origen, generada_por_inhabil_id, estado, vence)
                 VALUES (:id, :folio, :suc, :motivo, \'inhabil\', :inhabil, \'pendiente\', :vence)'
            );

            $generadas = 0;
            foreach ($afectadas as $a) {
                $insRep->execute([
                    ':id'      => $this->uuid(),
                    ':folio'   => $a['folio'],
                    ':suc'     => $a['sucursal_id'],
                    ':motivo'  => 'Día inhábil ' . $fecha . ($motivo ? ' — ' . $motivo : ''),
                    ':inhabil' => $inhabilId,
                    ':vence'   => $vence,
                ]);
                $generadas++;
            }

            $this->pdo->prepare(
                'UPDATE inhabiles SET estado = \'aplicado\', alumnos_afectados = :af, reposiciones_generadas = :rg WHERE id = :id'
            )->execute([':af' => count($foliosUnicos), ':rg' => $generadas, ':id' => $inhabilId]);

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        return [
            'inhabilId'             => $inhabilId,
            'fecha'                 => $fecha,
            'alumnosAfectados'      => count($foliosUnicos),
            'reposicionesGeneradas' => $generadas,
        ];
    }

    // ── Listar inhábiles ──────────────────────────────────────────────────
    public function listar(): array
    {
        $stmt = $this->pdo->query(
            'SELECT i.*, u.nombre AS declarado_por_nombre
             FROM inhabiles i
             JOIN usuarios u ON u.id = i.declarado_por
             ORDER BY i.fecha DESC'
        );
        return array_map(fn($row) => [
            'id'                    => $row['id'],
            'fecha'                 => $row['fecha'],
            'motivo'                => $row['motivo'],
            'sucursalId'            => $row['sucursal_id'],
            'estado'                => $row['estado'],
            'alumnosAfectados'      => (int) $row['alumnos_afectados'],
            'reposicionesGeneradas' => (int) $row['reposiciones_generadas'],
            'declaradoPor'          => $row['declarado_por'],
            'declaradoPorNombre'    => $row['declarado_por_nombre'],
            'declaradoEn'           => $row['declarado_en'],
        ], $stmt->fetchAll());
    }

    // ── Helpers privados ──────────────────────────────────────────────────

    /**
     * Clases afectadas en una fecha: reglas recurrentes vigentes ese día.
     * Para GRUPAL expande a cada miembro del grupo (cada quien pierde su clase).
     * Devuelve una entrada por (alumno × clase perdida).
     */
    private function clasesAfectadas(string $fecha, ?string $sucursalId): array
    {
        $dia = $this->diaDeFecha($fecha);

        $sql = "SELECT hr.alumno_folio, hr.grupo_id, hr.sucursal_id,
                       hr.hora_inicio, i.nombre AS instructor_nombre,
                       al.nombre AS alumno_nombre
                FROM horario_recurrente hr
                JOIN instructores i ON i.id = hr.instructor_id
                LEFT JOIN alumnos al ON al.folio = hr.alumno_folio
                WHERE hr.dia = :dia
                  AND hr.vigente_desde <= :f1
                  AND (hr.vigente_hasta IS NULL OR hr.vigente_hasta >= :f2)";
        $params = [':dia' => $dia, ':f1' => $fecha, ':f2' => $fecha];
        if ($sucursalId) {
            $sql .= ' AND hr.sucursal_id = :suc';
            $params[':suc'] = $sucursalId;
        }
        $sql .= ' ORDER BY hr.hora_inicio';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            if ($r['grupo_id']) {
                // GRUPAL: cada miembro pierde la clase
                $mStmt = $this->pdo->prepare(
                    'SELECT ga.alumno_folio, a.nombre FROM grupo_alumnos ga
                     JOIN alumnos a ON a.folio = ga.alumno_folio WHERE ga.grupo_id = ?'
                );
                $mStmt->execute([$r['grupo_id']]);
                foreach ($mStmt->fetchAll() as $m) {
                    $out[] = [
                        'folio'       => $m['alumno_folio'],
                        'nombre'      => $m['nombre'],
                        'hora'        => substr((string) $r['hora_inicio'], 0, 5),
                        'instructor'  => $r['instructor_nombre'],
                        'sucursal_id' => $r['sucursal_id'],
                    ];
                }
            } elseif ($r['alumno_folio']) {
                $out[] = [
                    'folio'       => $r['alumno_folio'],
                    'nombre'      => $r['alumno_nombre'],
                    'hora'        => substr((string) $r['hora_inicio'], 0, 5),
                    'instructor'  => $r['instructor_nombre'],
                    'sucursal_id' => $r['sucursal_id'],
                ];
            }
        }
        return $out;
    }

    private function diasVigencia(): int
    {
        $v = $this->pdo->query("SELECT valor FROM configuracion WHERE clave = 'reposicion_dias_vigencia'")->fetchColumn();
        return $v !== false ? (int) $v : 30;
    }

    private function diaDeFecha(string $fecha): string
    {
        $n = (int) date('N', strtotime($fecha)); // 1=Lun … 7=Dom
        return ['L', 'M', 'X', 'J', 'V', 'S', 'D'][$n - 1] ?? 'L';
    }

    private function uuid(): string
    {
        $data    = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
