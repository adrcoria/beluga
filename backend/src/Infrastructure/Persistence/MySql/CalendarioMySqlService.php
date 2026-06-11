<?php
declare(strict_types=1);
namespace Beluga\Infrastructure\Persistence\MySql;
use Beluga\Domain\Calendario\CalendarioService;
use PDO;
class CalendarioMySqlService implements CalendarioService
{
    public function __construct(private readonly PDO $pdo) {}
    public function obtenerPizarron(string $sucursalId, string $dia, string $fecha): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM slots WHERE sucursal_id=:suc AND dia=:dia AND activo=1 ORDER BY hora_inicio');
        $stmt->execute([':suc'=>$sucursalId,':dia'=>$dia]);
        $slots = $stmt->fetchAll();
        if (empty($slots)) return [];
        $ids = array_column($slots,'id');
        $in  = implode(',', array_fill(0, count($ids), '?'));
        $aStmt = $this->pdo->prepare("SELECT a.*, al.nombre AS alumno_nombre, al.vencimiento AS alumno_vencimiento, i.nombre AS instructor_nombre, i.iniciales AS instructor_iniciales FROM asignaciones a JOIN alumnos al ON al.folio=a.alumno_folio JOIN instructores i ON i.id=a.instructor_id WHERE a.slot_id IN ($in) AND a.fecha=? ORDER BY al.nombre");
        $aStmt->execute([...$ids, $fecha]);
        $porSlot = [];
        foreach ($aStmt->fetchAll() as $a) { $porSlot[$a['slot_id']][] = $this->mapearAsignacion($a); }
        return array_map(function(array $slot) use ($porSlot): array {
            $asigs = $porSlot[$slot['id']] ?? [];
            return ['slotId'=>$slot['id'],'sucursalId'=>$slot['sucursal_id'],'dia'=>$slot['dia'],'horaInicio'=>$slot['hora_inicio'],'horaFin'=>$slot['hora_fin'],'modalidad'=>$slot['modalidad'],'cupoMax'=>(int)$slot['cupo_max'],'cupoDisponible'=>max(0,(int)$slot['cupo_max']-count($asigs)),'asignaciones'=>$asigs];
        }, $slots);
    }
    public function asignar(string $folio, string $slotId, string $fecha, string $estado, string $instructorId): array
    {
        $sStmt = $this->pdo->prepare('SELECT * FROM slots WHERE id=?'); $sStmt->execute([$slotId]); $slot = $sStmt->fetch();
        if (!$slot) throw new \RuntimeException('Slot no encontrado', 404);
        $cnt = $this->pdo->prepare('SELECT COUNT(*) FROM asignaciones WHERE slot_id=? AND fecha=?'); $cnt->execute([$slotId,$fecha]);
        if ((int)$cnt->fetchColumn() >= $slot['cupo_max']) throw new \RuntimeException('Sin cupo disponible', 409);
        $aStmt = $this->pdo->prepare('SELECT modalidad FROM alumnos WHERE folio=?'); $aStmt->execute([$folio]); $alumno = $aStmt->fetch();
        if (!$alumno) throw new \RuntimeException('Alumno no encontrado', 404);
        if ($alumno['modalidad'] !== $slot['modalidad']) throw new \RuntimeException('Modalidad incompatible', 422);
        $id = $this->uuid();
        $this->pdo->prepare('INSERT INTO asignaciones (id, alumno_folio, slot_id, instructor_id, fecha, estado) VALUES (:id,:folio,:slot,:inst,:fecha,:estado)')->execute([':id'=>$id,':folio'=>$folio,':slot'=>$slotId,':inst'=>$instructorId,':fecha'=>$fecha,':estado'=>$estado]);
        return $this->obtenerAsignacion($id);
    }
    public function actualizarAsignacion(string $id, array $cambios): ?array
    {
        $set=[]; $params=[':id'=>$id];
        if (array_key_exists('estado',$cambios))      { $set[]='estado=:estado';      $params[':estado']=$cambios['estado']; }
        if (array_key_exists('nota',$cambios))         { $set[]='nota=:nota';          $params[':nota']=$cambios['nota']; }
        if (array_key_exists('instructorId',$cambios)) { $set[]='instructor_id=:inst'; $params[':inst']=$cambios['instructorId']; }
        if (!empty($set)) $this->pdo->prepare('UPDATE asignaciones SET '.implode(',',$set).' WHERE id=:id')->execute($params);
        return $this->obtenerAsignacion($id);
    }
    public function eliminarAsignacion(string $id): bool
    { $s=$this->pdo->prepare('DELETE FROM asignaciones WHERE id=?'); $s->execute([$id]); return $s->rowCount()>0; }
    public function horarioPorAlumno(string $folio): array
    {
        try {
            $stmt=$this->pdo->prepare("SELECT DISTINCT s.id AS slot_id, s.dia, s.hora_inicio, s.hora_fin, s.modalidad, i.nombre AS instructor_nombre, i.iniciales AS instructor_iniciales FROM asignaciones a JOIN slots s ON s.id=a.slot_id JOIN instructores i ON i.id=a.instructor_id WHERE a.alumno_folio=:folio AND a.estado IN ('regular','reposicion') ORDER BY FIELD(s.dia,'L','M','X','J','V','S'), s.hora_inicio");
            $stmt->execute([':folio'=>$folio]);
            return array_map(fn($r)=>['slotId'=>$r['slot_id'],'dia'=>$r['dia'],'horaInicio'=>$r['hora_inicio'],'horaFin'=>$r['hora_fin'],'modalidad'=>$r['modalidad'],'instructorNombre'=>$r['instructor_nombre'],'instructorIniciales'=>$r['instructor_iniciales']], $stmt->fetchAll());
        } catch (\PDOException $e) {
            return [];
        }
    }
    private function obtenerAsignacion(string $id): ?array
    {
        $s=$this->pdo->prepare('SELECT a.*, al.nombre AS alumno_nombre, al.vencimiento AS alumno_vencimiento, i.nombre AS instructor_nombre, i.iniciales AS instructor_iniciales FROM asignaciones a JOIN alumnos al ON al.folio=a.alumno_folio JOIN instructores i ON i.id=a.instructor_id WHERE a.id=?');
        $s->execute([$id]); $r=$s->fetch(); return $r?$this->mapearAsignacion($r):null;
    }
    private function mapearAsignacion(array $row): array
    { return ['id'=>$row['id'],'alumnoFolio'=>$row['alumno_folio'],'alumnoNombre'=>$row['alumno_nombre']??null,'alumnoVencimiento'=>$row['alumno_vencimiento']??null,'instructorId'=>$row['instructor_id'],'instructorNombre'=>$row['instructor_nombre']??null,'instructorIniciales'=>$row['instructor_iniciales']??null,'slotId'=>$row['slot_id'],'fecha'=>$row['fecha'],'estado'=>$row['estado'],'nota'=>$row['nota']??null,'tieneNota'=>!empty($row['nota'])]; }
    private function uuid(): string { return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',mt_rand(0,0xffff),mt_rand(0,0xffff),mt_rand(0,0xffff),mt_rand(0,0x0fff)|0x4000,mt_rand(0,0x3fff)|0x8000,mt_rand(0,0xffff),mt_rand(0,0xffff),mt_rand(0,0xffff)); }
}