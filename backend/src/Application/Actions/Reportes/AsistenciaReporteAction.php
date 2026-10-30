<?php

declare(strict_types=1);

namespace Beluga\Application\Actions\Reportes;

use Beluga\Application\Actions\Action;
use Beluga\Domain\Asistencia\AsistenciaRepository;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Log\LoggerInterface;

/**
 * Reporte de asistencia.
 *
 *   GET /api/reportes/asistencia?sucursal=&desde=&hasta=
 *
 * Antes solo contaba presencias. Ahora mide asistencia de verdad: clases
 * programadas contra clases a las que el alumno se presentó, separando a
 * quien avisó que no venía de quien simplemente no llegó.
 *
 * Los días ya terminados que nadie haya consultado se cierran aquí, para que
 * el reporte no muestre como "pendiente" lo que en realidad fue una falta.
 */
class AsistenciaReporteAction extends Action
{
    /** Tope de días a cerrar en una sola consulta, para no colgar la petición. */
    private const MAX_CIERRES = 60;

    /** Días sin cerrar que se calculan al vuelo: normalmente solo hoy. */
    private const MAX_DIAS_ABIERTOS = 7;

    public function __construct(
        LoggerInterface $logger,
        private readonly PDO $pdo,
        private readonly AsistenciaRepository $asistencias
    ) {
        parent::__construct($logger);
    }

    protected function action(): Response
    {
        $desde = (string) $this->getQueryParam('desde', date('Y-m-01'));
        $hasta = (string) $this->getQueryParam('hasta', date('Y-m-d'));
        $suc   = (string) $this->getQueryParam('sucursal', '');

        if ($suc === '') {
            return $this->error('Falta la sucursal', 422);
        }

        $this->cerrarPendientes($suc, $desde, $hasta);

        // Los días cerrados ya están materializados en `asistencias`: se leen
        // con dos consultas agregadas en vez de recalcular el pizarrón día por
        // día, que con un mes de rango eran más de cien consultas.
        $hastaCerrado = min($hasta, date('Y-m-d', strtotime('-1 day')));

        $porDia    = [];
        $porAlumno = [];
        $tot = ['clases' => 0, 'asistieron' => 0, 'avisaron' => 0, 'faltaron' => 0, 'pendientes' => 0];

        if ($desde <= $hastaCerrado) {
            $porDia    = $this->agregadoPorDia($suc, $desde, $hastaCerrado);
            $porAlumno = $this->agregadoPorAlumno($suc, $desde, $hastaCerrado);

            foreach ($porDia as $d) {
                $tot['clases']     += $d['clases'];
                $tot['asistieron'] += $d['asistieron'];
                $tot['avisaron']   += $d['avisaron'];
                $tot['faltaron']   += $d['faltaron'];
            }
        }

        // Los días que aún no cierran (hoy, y cualquier fecha futura del
        // rango) sí se calculan al vuelo: ahí todavía hay clases pendientes
        // que no existen como registro.
        $cursor = max($desde, date('Y-m-d'));
        $tope   = 0;
        while ($cursor <= $hasta && $tope++ < self::MAX_DIAS_ABIERTOS) {
            $clases = $this->asistencias->clasesDelDia($suc, $cursor);

            if ($clases) {
                $dia = ['fecha' => $cursor, 'clases' => 0, 'asistieron' => 0, 'avisaron' => 0, 'faltaron' => 0, 'pendientes' => 0];

                foreach ($clases as $c) {
                    $folio = $c['alumnoFolio'];
                    $porAlumno[$folio] ??= [
                        'folio' => $folio, 'nombre' => $c['alumnoNombre'], 'telefono' => $c['alumnoTelefono'],
                        'clases' => 0, 'asistio' => 0, 'aviso' => 0, 'falto' => 0, 'pendiente' => 0, 'ultima' => null,
                    ];

                    $dia['clases']++; $tot['clases']++;
                    $porAlumno[$folio]['clases']++;

                    if ($c['estado'] === 'asistio') {
                        $dia['asistieron']++; $tot['asistieron']++;
                        $porAlumno[$folio]['asistio']++;
                        $porAlumno[$folio]['ultima'] = $cursor;
                    } elseif ($c['estado'] === null) {
                        $dia['pendientes']++; $tot['pendientes']++;
                        $porAlumno[$folio]['pendiente']++;
                    } elseif ($c['aviso']) {
                        $dia['avisaron']++; $tot['avisaron']++;
                        $porAlumno[$folio]['aviso']++;
                    } else {
                        $dia['faltaron']++; $tot['faltaron']++;
                        $porAlumno[$folio]['falto']++;
                    }
                }

                $porDia[] = $dia;
            }

            $cursor = date('Y-m-d', strtotime($cursor . ' +1 day'));
        }

        usort($porDia, fn($a, $b) => $a['fecha'] <=> $b['fecha']);

        // % sobre lo ya resuelto: lo pendiente todavía no es ni falta ni asistencia
        $resueltas = $tot['clases'] - $tot['pendientes'];
        $tot['porcentajeAsistencia'] = $resueltas > 0 ? round($tot['asistieron'] * 100 / $resueltas, 1) : null;

        $alumnos = array_values($porAlumno);
        foreach ($alumnos as &$a) {
            $res = $a['clases'] - $a['pendiente'];
            $a['porcentaje'] = $res > 0 ? round($a['asistio'] * 100 / $res, 1) : null;
        }
        unset($a);

        // Los más faltistas primero: es lo que la escuela quiere ver
        usort($alumnos, function ($x, $y) {
            $fx = $x['falto'] + $x['aviso'];
            $fy = $y['falto'] + $y['aviso'];
            return [$fy, $y['falto']] <=> [$fx, $x['falto']];
        });

        return $this->json([
            'desde'     => $desde,
            'hasta'     => $hasta,
            'resumen'   => $tot,
            'porDia'    => $porDia,
            'alumnos'   => $alumnos,
            'ausentes'  => $this->sinVenirNunca($suc, $desde, $hasta),
        ]);
    }

    /**
     * Totales por día de los días ya cerrados.
     *
     * La diferencia entre "faltó avisando" y "faltó sin avisar" sale de cruzar
     * con `ausencias` por alumno + fecha + hora de clase.
     */
    private function agregadoPorDia(string $suc, string $desde, string $hasta): array
    {
        $st = $this->pdo->prepare(
            "SELECT a.fecha,
                    COUNT(*)                                                  AS clases,
                    SUM(a.estado = 'asistio')                                 AS asistieron,
                    SUM(a.estado = 'no_asistio' AND au.id IS NOT NULL)        AS avisaron,
                    SUM(a.estado = 'no_asistio' AND au.id IS NULL)            AS faltaron
             FROM asistencias a
             LEFT JOIN ausencias au
                    ON au.alumno_folio = a.alumno_folio
                   AND au.fecha        = a.fecha
                   AND au.hora_clase   = a.hora_clase
             WHERE a.fecha BETWEEN :d AND :h
               AND (a.sucursal_id = :suc OR a.sucursal_id IS NULL)
             GROUP BY a.fecha
             ORDER BY a.fecha"
        );
        $st->execute([':d' => $desde, ':h' => $hasta, ':suc' => $suc]);

        return array_map(fn(array $r): array => [
            'fecha'      => $r['fecha'],
            'clases'     => (int) $r['clases'],
            'asistieron' => (int) $r['asistieron'],
            'avisaron'   => (int) $r['avisaron'],
            'faltaron'   => (int) $r['faltaron'],
            'pendientes' => 0,   // un día cerrado no deja nada pendiente
        ], $st->fetchAll());
    }

    /** Totales por alumno de los días ya cerrados. */
    private function agregadoPorAlumno(string $suc, string $desde, string $hasta): array
    {
        $st = $this->pdo->prepare(
            "SELECT a.alumno_folio, al.nombre, al.telefono,
                    COUNT(*)                                           AS clases,
                    SUM(a.estado = 'asistio')                          AS asistio,
                    SUM(a.estado = 'no_asistio' AND au.id IS NOT NULL) AS aviso,
                    SUM(a.estado = 'no_asistio' AND au.id IS NULL)     AS falto,
                    MAX(CASE WHEN a.estado = 'asistio' THEN a.fecha END) AS ultima
             FROM asistencias a
             JOIN alumnos al ON al.folio = a.alumno_folio
             LEFT JOIN ausencias au
                    ON au.alumno_folio = a.alumno_folio
                   AND au.fecha        = a.fecha
                   AND au.hora_clase   = a.hora_clase
             WHERE a.fecha BETWEEN :d AND :h
               AND (a.sucursal_id = :suc OR a.sucursal_id IS NULL)
             GROUP BY a.alumno_folio, al.nombre, al.telefono"
        );
        $st->execute([':d' => $desde, ':h' => $hasta, ':suc' => $suc]);

        $out = [];
        foreach ($st->fetchAll() as $r) {
            $out[$r['alumno_folio']] = [
                'folio'     => $r['alumno_folio'],
                'nombre'    => $r['nombre'],
                'telefono'  => $r['telefono'],
                'clases'    => (int) $r['clases'],
                'asistio'   => (int) $r['asistio'],
                'aviso'     => (int) $r['aviso'],
                'falto'     => (int) $r['falto'],
                'pendiente' => 0,
                'ultima'    => $r['ultima'],
            ];
        }
        return $out;
    }

    /**
     * Cierra los días del rango que ya terminaron y seguían abiertos.
     * Con tope: si alguien pide un rango de dos años no se cuelga la petición.
     */
    private function cerrarPendientes(string $suc, string $desde, string $hasta): void
    {
        $limite = min($hasta, date('Y-m-d', strtotime('-1 day')));
        if ($desde > $limite) {
            return;
        }

        // Los días ya cerrados se leen de una sola vez. Preguntar día por día
        // era una consulta por cada fecha del rango.
        $st = $this->pdo->prepare(
            'SELECT fecha FROM asistencia_cierres
             WHERE sucursal_id = :suc AND fecha BETWEEN :d AND :h'
        );
        $st->execute([':suc' => $suc, ':d' => $desde, ':h' => $limite]);
        $cerrados = array_flip(array_column($st->fetchAll(), 'fecha'));

        $cursor = $desde;
        $n = 0;

        while ($cursor <= $limite && $n < self::MAX_CIERRES) {
            if (!isset($cerrados[$cursor])) {
                try {
                    $this->asistencias->cerrarDia($suc, $cursor);
                    $n++;
                } catch (\RuntimeException) {
                    // día sin clases o ya cerrado: se sigue
                }
            }
            $cursor = date('Y-m-d', strtotime($cursor . ' +1 day'));
        }
    }

    /** Alumnos activos que no pisaron la escuela en todo el rango. */
    private function sinVenirNunca(string $suc, string $desde, string $hasta): array
    {
        $st = $this->pdo->prepare(
            "SELECT al.folio, al.nombre, al.telefono,
                    (SELECT MAX(x.fecha) FROM asistencias x
                      WHERE x.alumno_folio = al.folio AND x.estado = 'asistio') AS ultima
             FROM alumnos al
             WHERE al.status = 'activo' AND al.sucursal_id = :suc
               AND al.folio NOT IN (
                   SELECT alumno_folio FROM asistencias
                    WHERE fecha BETWEEN :d AND :h AND estado = 'asistio'
               )
             ORDER BY ultima IS NULL DESC, ultima ASC"
        );
        $st->execute([':suc' => $suc, ':d' => $desde, ':h' => $hasta]);

        return array_map(fn($r) => [
            'folio'    => $r['folio'],
            'nombre'   => $r['nombre'],
            'telefono' => $r['telefono'],
            'ultima'   => $r['ultima'],
        ], $st->fetchAll());
    }
}
