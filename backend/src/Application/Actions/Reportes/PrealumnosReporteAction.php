<?php

declare(strict_types=1);

namespace Beluga\Application\Actions\Reportes;

use Beluga\Application\Actions\Action;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Log\LoggerInterface;

/**
 * Reporte de prealumnos: quién vino a clase muestra, qué se anotó de él y si
 * terminó inscribiéndose.
 *
 * Un prealumno es un alumno con status 'prueba'. No se creó un catálogo
 * aparte a propósito: el buzón de prospectos es el módulo premium 11 y el
 * cliente no quiso regalarlo.
 *
 *   GET /api/reportes/prealumnos?sucursal=&desde=&hasta=
 */
class PrealumnosReporteAction extends Action
{
    public function __construct(
        LoggerInterface $logger,
        private readonly PDO $pdo
    ) {
        parent::__construct($logger);
    }

    protected function action(): Response
    {
        $where  = ["a.status = 'prueba'"];
        $params = [];

        if ($suc = $this->getQueryParam('sucursal')) {
            $where[] = 'a.sucursal_id = :suc';
            $params[':suc'] = $suc;
        }
        if ($desde = $this->getQueryParam('desde')) {
            $where[] = 'COALESCE(a.fecha_clase_muestra, DATE(a.creado_en)) >= :desde';
            $params[':desde'] = $desde;
        }
        if ($hasta = $this->getQueryParam('hasta')) {
            $where[] = 'COALESCE(a.fecha_clase_muestra, DATE(a.creado_en)) <= :hasta';
            $params[':hasta'] = $hasta;
        }

        $stmt = $this->pdo->prepare(
            'SELECT a.folio, a.nombre, a.telefono, a.email, a.tutor, a.notas,
                    a.fecha_clase_muestra, a.creado_en, a.modalidad,
                    (SELECT COUNT(*) FROM horario_recurrente h
                      WHERE h.alumno_folio = a.folio AND h.es_muestra = 1) AS clases_muestra
             FROM alumnos a
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY COALESCE(a.fecha_clase_muestra, DATE(a.creado_en)) DESC, a.nombre'
        );
        $stmt->execute($params);

        $prealumnos = array_map(fn(array $r): array => [
            'folio'             => $r['folio'],
            'nombre'            => $r['nombre'],
            'telefono'          => $r['telefono'],
            'email'             => $r['email'],
            'tutor'             => $r['tutor'],
            'notas'             => $r['notas'],
            'modalidad'         => $r['modalidad'],
            'fechaClaseMuestra' => $r['fecha_clase_muestra'],
            'registradoEn'      => $r['creado_en'],
            'clasesMuestra'     => (int) $r['clases_muestra'],
            'agendado'          => ((int) $r['clases_muestra']) > 0,
        ], $stmt->fetchAll());

        // Cuántos de los que probaron terminaron inscribiéndose: el dato que
        // de verdad le interesa a la escuela.
        $convertidos = (int) $this->pdo->query(
            "SELECT COUNT(*) FROM alumnos WHERE status = 'activo' AND fecha_clase_muestra IS NOT NULL"
        )->fetchColumn();

        return $this->json([
            'resumen' => [
                'enPrueba'     => count($prealumnos),
                'agendados'    => count(array_filter($prealumnos, fn($p) => $p['agendado'])),
                'sinAgendar'   => count(array_filter($prealumnos, fn($p) => !$p['agendado'])),
                'convertidos'  => $convertidos,
            ],
            'prealumnos' => $prealumnos,
        ]);
    }
}
