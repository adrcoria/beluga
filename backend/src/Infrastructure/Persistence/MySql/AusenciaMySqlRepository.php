<?php

declare(strict_types=1);

namespace Beluga\Infrastructure\Persistence\MySql;

use Beluga\Domain\Ausencia\AusenciaRepository;
use Beluga\Domain\Reposicion\ReposicionRepository;
use PDO;
use RuntimeException;

class AusenciaMySqlRepository implements AusenciaRepository
{
    private const UMBRAL_POR_DEFECTO = 2.0;

    public function __construct(
        private readonly PDO $pdo,
        private readonly ReposicionRepository $reposiciones
    ) {}

    public function umbralHoras(): float
    {
        $stmt = $this->pdo->query("SELECT valor FROM configuracion WHERE clave = 'ausencia_horas_aviso'");
        $v = $stmt->fetchColumn();
        return $v !== false ? (float) $v : self::UMBRAL_POR_DEFECTO;
    }

    public function registrar(array $datos): array
    {
        $fecha     = (string) $datos['fecha'];
        $horaClase = substr((string) $datos['horaClase'], 0, 5);
        $folio     = (string) $datos['alumnoFolio'];

        $inicioClase = strtotime("$fecha $horaClase");
        if ($inicioClase === false) {
            throw new RuntimeException('La fecha u hora de la clase no son válidas');
        }

        // Avisar después de que la clase empezó no es avisar: esa clase ya se
        // perdió y no puede dar derecho a nada.
        $horasAviso = round(($inicioClase - time()) / 3600, 2);
        if ($horasAviso < 0) {
            $horasAviso = 0.0;
        }

        $umbral     = $this->umbralHoras();
        $conDerecho = $horasAviso >= $umbral;

        $yaExiste = $this->pdo->prepare(
            'SELECT id FROM ausencias WHERE alumno_folio = ? AND fecha = ? AND hora_clase = ?'
        );
        $yaExiste->execute([$folio, $fecha, $horaClase]);
        if ($yaExiste->fetchColumn()) {
            throw new RuntimeException('Esa falta ya estaba registrada');
        }

        $id = $this->uuid();

        $this->pdo->beginTransaction();
        try {
            $reposicionId = null;

            if ($conDerecho) {
                // El comodín queda ligado a la falta que lo originó
                $rep = $this->reposiciones->crearManual([
                    'alumnoFolio' => $folio,
                    'sucursalId'  => $datos['sucursalId'],
                    'motivo'      => $datos['motivo'] ?? 'Falta avisada con anticipación',
                    'fechaFalta'  => $fecha,
                    'origen'      => 'ausencia',
                ]);
                $reposicionId = $rep['id'];
            }

            $this->pdo->prepare(
                'INSERT INTO ausencias
                    (id, alumno_folio, horario_id, sucursal_id, fecha, hora_clase,
                     horas_aviso, con_derecho, umbral_horas, motivo, reposicion_id, registrado_por)
                 VALUES (:id, :folio, :hid, :suc, :fecha, :hora,
                         :horas, :derecho, :umbral, :motivo, :rep, :usuario)'
            )->execute([
                ':id'      => $id,
                ':folio'   => $folio,
                ':hid'     => $datos['horarioId'] ?? null,
                ':suc'     => $datos['sucursalId'],
                ':fecha'   => $fecha,
                ':hora'    => $horaClase,
                ':horas'   => $horasAviso,
                ':derecho' => $conDerecho ? 1 : 0,
                // Se guarda el umbral vigente: si mañana se cambia el parámetro,
                // esta falta conserva el criterio con el que se resolvió.
                ':umbral'  => $umbral,
                ':motivo'  => $datos['motivo'] ?? null,
                ':rep'     => $reposicionId,
                ':usuario' => $datos['usuarioId'] ?? null,
            ]);

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        return $this->porId($id);
    }

    public function anular(string $id): void
    {
        $a = $this->porId($id);
        if (!$a) {
            throw new RuntimeException('Falta no encontrada');
        }

        $this->pdo->beginTransaction();
        try {
            // Si el comodín ya se usó no se puede deshacer: el alumno ya tomó
            // la clase de reposición.
            if ($a['reposicionId']) {
                $estado = $this->pdo->prepare('SELECT estado FROM reposiciones WHERE id = ?');
                $estado->execute([$a['reposicionId']]);
                $e = $estado->fetchColumn();

                if ($e === 'tomada') {
                    throw new RuntimeException(
                        'No se puede anular: el alumno ya tomó la clase de reposición que generó esta falta.'
                    );
                }
                $this->reposiciones->cancelar($a['reposicionId']);
            }

            $this->pdo->prepare('DELETE FROM ausencias WHERE id = ?')->execute([$id]);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function porId(string $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT a.*, al.nombre AS alumno_nombre, r.estado AS reposicion_estado
             FROM ausencias a
             JOIN alumnos al ON al.folio = a.alumno_folio
             LEFT JOIN reposiciones r ON r.id = a.reposicion_id
             WHERE a.id = ?'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ? $this->mapear($row) : null;
    }

    public function enSemana(string $sucursalId, string $lunes, string $sabado): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT a.*, al.nombre AS alumno_nombre, r.estado AS reposicion_estado
             FROM ausencias a
             JOIN alumnos al ON al.folio = a.alumno_folio
             LEFT JOIN reposiciones r ON r.id = a.reposicion_id
             WHERE a.sucursal_id = :suc AND a.fecha BETWEEN :lunes AND :sabado
             ORDER BY a.fecha, a.hora_clase'
        );
        $stmt->execute([':suc' => $sucursalId, ':lunes' => $lunes, ':sabado' => $sabado]);
        return array_map($this->mapear(...), $stmt->fetchAll());
    }

    public function listar(array $filtros): array
    {
        $where  = ['1=1'];
        $params = [];

        if (!empty($filtros['sucursalId'])) {
            $where[] = 'a.sucursal_id = :suc';
            $params[':suc'] = $filtros['sucursalId'];
        }
        if (!empty($filtros['desde'])) {
            $where[] = 'a.fecha >= :desde';
            $params[':desde'] = $filtros['desde'];
        }
        if (!empty($filtros['hasta'])) {
            $where[] = 'a.fecha <= :hasta';
            $params[':hasta'] = $filtros['hasta'];
        }
        if (isset($filtros['conDerecho']) && $filtros['conDerecho'] !== null) {
            $where[] = 'a.con_derecho = :derecho';
            $params[':derecho'] = $filtros['conDerecho'] ? 1 : 0;
        }

        $stmt = $this->pdo->prepare(
            'SELECT a.*, al.nombre AS alumno_nombre, r.estado AS reposicion_estado
             FROM ausencias a
             JOIN alumnos al ON al.folio = a.alumno_folio
             LEFT JOIN reposiciones r ON r.id = a.reposicion_id
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY a.fecha DESC, a.hora_clase'
        );
        $stmt->execute($params);
        return array_map($this->mapear(...), $stmt->fetchAll());
    }

    private function mapear(array $r): array
    {
        return [
            'id'               => $r['id'],
            'alumnoFolio'      => $r['alumno_folio'],
            'alumnoNombre'     => $r['alumno_nombre'] ?? null,
            'horarioId'        => $r['horario_id'],
            'sucursalId'       => $r['sucursal_id'],
            'fecha'            => $r['fecha'],
            'horaClase'        => substr((string) $r['hora_clase'], 0, 5),
            'horasAviso'       => (float) $r['horas_aviso'],
            'conDerecho'       => (bool) $r['con_derecho'],
            'umbralHoras'      => (float) $r['umbral_horas'],
            'motivo'           => $r['motivo'],
            'reposicionId'     => $r['reposicion_id'],
            'reposicionEstado' => $r['reposicion_estado'] ?? null,
            'creadoEn'         => $r['creado_en'],
            'dia'              => $this->diaDeFecha((string) $r['fecha']),
        ];
    }

    private function diaDeFecha(string $fecha): string
    {
        $n = (int) date('N', strtotime($fecha));
        return ['L', 'M', 'X', 'J', 'V', 'S', 'D'][$n - 1] ?? 'L';
    }

    private function uuid(): string
    {
        $d = random_bytes(16);
        $d[6] = chr(ord($d[6]) & 0x0f | 0x40);
        $d[8] = chr(ord($d[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($d), 4));
    }
}
