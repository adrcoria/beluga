<?php

declare(strict_types=1);

namespace Beluga\Infrastructure\Persistence\MySql;

use Beluga\Domain\Cobro\CobroRepository;
use PDO;
use RuntimeException;

class CobroMySqlRepository implements CobroRepository
{
    public function __construct(private readonly PDO $pdo) {}

    public function listar(array $filtros = []): array
    {
        $where  = ['1=1'];
        $params = [];

        if (!empty($filtros['estado'])) {
            $where[]           = 'c.estado = :estado';
            $params[':estado'] = $filtros['estado'];
        }
        if (!empty($filtros['alumno_folio'])) {
            $where[]             = 'c.alumno_folio = :folio';
            $params[':folio']    = $filtros['alumno_folio'];
        }
        if (!empty($filtros['busqueda'])) {
            $where[]       = '(a.nombre LIKE :q1 OR a.folio LIKE :q2)';
            $q             = '%' . $filtros['busqueda'] . '%';
            $params[':q1'] = $q;
            $params[':q2'] = $q;
        }

        $sql = 'SELECT c.*, a.nombre AS alumno_nombre, a.foto AS alumno_foto,
                       a.telefono AS alumno_telefono, a.email AS alumno_email,
                       a.saldo_favor AS alumno_saldo_favor,
                       pr.modalidad AS programa_modalidad,
                       (df.alumno_folio IS NOT NULL) AS tiene_datos_fiscales
                FROM cobros c
                JOIN alumnos a ON a.folio = c.alumno_folio
                LEFT JOIN alumno_programas pr ON pr.id = c.programa_id
                LEFT JOIN alumno_datos_fiscales df ON df.alumno_folio = c.alumno_folio
                WHERE ' . implode(' AND ', $where) . '
                ORDER BY c.generado_en DESC';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return array_map($this->mapear(...), $stmt->fetchAll());
    }

    public function porId(string $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT c.*, a.nombre AS alumno_nombre, a.foto AS alumno_foto,
                    a.telefono AS alumno_telefono, a.email AS alumno_email,
                    a.saldo_favor AS alumno_saldo_favor,
                    pr.modalidad AS programa_modalidad,
                    (df.alumno_folio IS NOT NULL) AS tiene_datos_fiscales
             FROM cobros c
             JOIN alumnos a ON a.folio = c.alumno_folio
             LEFT JOIN alumno_programas pr ON pr.id = c.programa_id
             LEFT JOIN alumno_datos_fiscales df ON df.alumno_folio = c.alumno_folio
             WHERE c.id = ?'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ? $this->mapear($row) : null;
    }

    public function registrarAbono(string $cobroId, float $monto, string $metodo, string $usuarioId, string $sucursalId, bool $requiereFactura = false, int $meses = 1): array
    {
        $cobro = $this->porId($cobroId);
        if (!$cobro) {
            throw new RuntimeException('Cobro no encontrado');
        }
        if ($cobro['estado'] === 'pagado') {
            throw new RuntimeException('Este cobro ya está liquidado');
        }
        if ($monto <= 0) {
            throw new RuntimeException('El monto debe ser mayor a cero');
        }

        $meses = max(1, (int) $meses);
        if ($meses > 1 && $cobro['concepto'] !== 'mensualidad') {
            throw new RuntimeException('Solo las mensualidades se pueden pagar por adelantado');
        }

        // FIFO: no se puede pagar un mes si hay uno MÁS VIEJO pendiente en el mismo programa.
        if ($cobro['concepto'] === 'mensualidad' && !empty($cobro['programaId'])) {
            $stmt = $this->pdo->prepare(
                "SELECT periodo_desde FROM cobros
                 WHERE programa_id = ? AND concepto = 'mensualidad'
                   AND estado NOT IN ('pagado','condonado') AND periodo_desde < ?
                 ORDER BY periodo_desde ASC LIMIT 1"
            );
            $stmt->execute([$cobro['programaId'], $cobro['periodoDesde']]);
            $masViejo = $stmt->fetchColumn();
            if ($masViejo) {
                throw new RuntimeException(
                    "Debes liquidar primero el mes más antiguo pendiente (que inicia el $masViejo) antes de pagar este.",
                    409
                );
            }
        }

        $this->pdo->beginTransaction();
        try {
            // 0. Pago adelantado: expandir el cobro para cubrir N meses
            //    (un solo cobro/recibo que abarca todo el periodo).
            if ($meses > 1) {
                $nuevoBase = round($cobro['montoBase'] * $meses, 2);
                $nuevoNeto = round($nuevoBase - $cobro['saldoFavorAplicado'], 2);
                $this->pdo->prepare(
                    "UPDATE cobros
                     SET monto_base    = :base,
                         monto_neto    = :neto,
                         periodo_hasta = DATE_ADD(periodo_desde, INTERVAL $meses MONTH)
                     WHERE id = :id"
                )->execute([':base' => $nuevoBase, ':neto' => $nuevoNeto, ':id' => $cobroId]);
                $cobro = $this->porId($cobroId);   // recargar montos y periodo actualizados
            }

            // 0.1 Montos a partir del cobro (ya expandido si aplica)
            $pendiente        = round($cobro['montoNeto'] - $cobro['montoPagado'], 2);
            $abonoReal        = min($monto, $pendiente);
            $excedente        = round($monto - $abonoReal, 2);
            $nuevoMontoPagado = round($cobro['montoPagado'] + $abonoReal, 2);
            $nuevoEstado      = $nuevoMontoPagado >= $cobro['montoNeto'] ? 'pagado' : 'parcial';

            // 2. Folio consecutivo de la sucursal (atómico)
            $ticket = (string) $this->siguienteFolioPago($sucursalId);

            // 3. Registrar pago en tabla pagos
            $this->pdo->prepare(
                'INSERT INTO pagos (id, alumno_folio, concepto, monto, metodo, fecha, ticket,
                                   ciclo_desde, ciclo_hasta, cobro_id, usuario_id, sucursal_id)
                 VALUES (UUID(), :folio, :concepto, :monto, :metodo, CURDATE(), :ticket,
                         :desde, :hasta, :cobro_id, :usuario, :sucursal)'
            )->execute([
                ':folio'    => $cobro['alumnoFolio'],
                ':concepto' => 'mensualidad',
                ':monto'    => $monto,
                ':metodo'   => $metodo,
                ':ticket'   => $ticket,
                ':desde'    => $cobro['periodoDesde'],
                ':hasta'    => $cobro['periodoHasta'],
                ':cobro_id' => $cobroId,
                ':usuario'  => $usuarioId,
                ':sucursal' => $sucursalId,
            ]);

            // 2. Actualizar cobro (incluye si requiere factura)
            $this->pdo->prepare(
                'UPDATE cobros SET monto_pagado = :pagado, estado = :estado, requiere_factura = :rf WHERE id = :id'
            )->execute([
                ':pagado' => $nuevoMontoPagado,
                ':estado' => $nuevoEstado,
                ':rf'     => $requiereFactura ? 1 : 0,
                ':id'     => $cobroId,
            ]);

            // 2.1 Si se pidió factura al cobrar, congelar aquí la copia de los
            //     datos fiscales. Es el camino más usado —la cajera marca la
            //     casilla mientras cobra— y sin esto el cobro quedaba marcado
            //     como facturable pero sin RFC.
            if ($requiereFactura) {
                $this->copiarDatosFiscales($cobroId);
            }

            // 3. Si el cobro quedó pagado: recalcular el vencimiento del PROGRAMA
            if ($nuevoEstado === 'pagado' && !empty($cobro['programaId'])) {
                $this->recalcularVencimiento($cobro['programaId'], true);
            }

            // 4. Si hay excedente: acumular saldo a favor
            if ($excedente > 0) {
                $this->pdo->prepare(
                    'UPDATE alumnos SET saldo_favor = saldo_favor + :exc WHERE folio = :folio'
                )->execute([
                    ':exc'   => $excedente,
                    ':folio' => $cobro['alumnoFolio'],
                ]);
            }

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        $cobroActualizado          = $this->porId($cobroId);
        $cobroActualizado['ticket'] = $ticket;
        return $cobroActualizado;
    }

    /**
     * Registra un pago ADELANTADO de un alumno aunque no tenga cobros pendientes.
     * Toma como inicio el vencimiento actual, asegura que exista el cobro de ese
     * periodo y lo liquida por N meses (un solo recibo). El vencimiento avanza N meses.
     */
    public function registrarPagoAdelantado(string $programaId, int $meses, string $metodo, string $usuarioId, string $sucursalId, bool $requiereFactura = false): array
    {
        $meses = max(1, $meses);

        $stmt = $this->pdo->prepare(
            "SELECT pr.alumno_folio, pr.mensualidad, pr.vencimiento, COALESCE(a.saldo_favor, 0) AS saldo_favor
             FROM alumno_programas pr
             JOIN alumnos a ON a.folio = pr.alumno_folio
             WHERE pr.id = ? AND pr.status = 'activo'"
        );
        $stmt->execute([$programaId]);
        $pr = $stmt->fetch();
        if (!$pr) {
            throw new RuntimeException('Programa no encontrado o inactivo');
        }

        // ¿Ya existe el cobro (no pagado) del periodo actual del programa? Reusarlo.
        $stmt = $this->pdo->prepare(
            "SELECT id FROM cobros
             WHERE programa_id = ? AND concepto = 'mensualidad'
               AND periodo_desde = ? AND estado NOT IN ('pagado','condonado')
             ORDER BY generado_en DESC LIMIT 1"
        );
        $stmt->execute([$programaId, $pr['vencimiento']]);
        $cobroId = $stmt->fetchColumn();

        if (!$cobroId) {
            $cobroId = $this->crearCobroMensualidad(
                $pr['alumno_folio'], $programaId, $pr['vencimiento'], (float) $pr['mensualidad'], (float) $pr['saldo_favor']
            );
        }

        $cobro = $this->porId($cobroId);
        $monto = round((float) $pr['mensualidad'] * $meses - $cobro['saldoFavorAplicado'], 2);

        return $this->registrarAbono($cobroId, $monto, $metodo, $usuarioId, $sucursalId, $requiereFactura, $meses);
    }

    /**
     * Crea el cobro de mensualidad (1 mes) para un alumno en un periodo dado.
     * Si el saldo a favor alcanza, queda 'pagado'. Devuelve el id del cobro.
     */
    private function crearCobroMensualidad(string $folio, string $programaId, string $periodoDesde, float $mensualidad, float $saldoFavor): string
    {
        $id            = $this->uuid();
        $saldoAplicado = min($saldoFavor, $mensualidad);
        $neto          = max(0, $mensualidad - $saldoAplicado);
        $estado        = $saldoFavor >= $mensualidad ? 'pagado' : 'pendiente';
        $this->pdo->prepare(
            "INSERT INTO cobros (id, alumno_folio, programa_id, concepto, monto_base,
                                 saldo_favor_aplicado, monto_neto, estado,
                                 periodo_desde, periodo_hasta)
             VALUES (:id, :folio, :pid, 'mensualidad', :base, :sa, :neto, :estado,
                     :desde, DATE_ADD(:desde2, INTERVAL 1 MONTH))"
        )->execute([
            ':id' => $id, ':folio' => $folio, ':pid' => $programaId, ':base' => $mensualidad,
            ':sa' => $saldoAplicado, ':neto' => $neto, ':estado' => $estado,
            ':desde' => $periodoDesde, ':desde2' => $periodoDesde,
        ]);
        return $id;
    }

    /**
     * Crea la primera mensualidad de un alumno recién dado de alta, para que sea
     * cobrable de inmediato (no espera a la generación del login). No duplica.
     */
    public function crearMensualidadInicial(string $folio): void
    {
        $stmt = $this->pdo->prepare(
            "SELECT pr.id, pr.mensualidad, pr.vencimiento, COALESCE(a.saldo_favor, 0) AS saldo_favor
             FROM alumno_programas pr
             JOIN alumnos a ON a.folio = pr.alumno_folio
             WHERE pr.alumno_folio = ? AND pr.status = 'activo'"
        );
        $stmt->execute([$folio]);
        foreach ($stmt->fetchAll() as $pr) {
            $existe = $this->pdo->prepare(
                "SELECT 1 FROM cobros
                 WHERE programa_id = ? AND concepto = 'mensualidad' AND periodo_desde = ? LIMIT 1"
            );
            $existe->execute([$pr['id'], $pr['vencimiento']]);
            if ($existe->fetchColumn()) continue;

            $this->crearCobroMensualidad($folio, $pr['id'], $pr['vencimiento'], (float) $pr['mensualidad'], (float) $pr['saldo_favor']);
        }
    }

    private function uuid(): string
    {
        $d = random_bytes(16);
        $d[6] = chr((ord($d[6]) & 0x0f) | 0x40);
        $d[8] = chr((ord($d[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($d), 4));
    }

    public function crearInscripcion(string $alumnoFolio, float $monto, string $fecha): array
    {
        $this->pdo->prepare(
            'INSERT INTO cobros (id, alumno_folio, concepto, monto_base, saldo_favor_aplicado, monto_neto, estado, periodo_desde, periodo_hasta)
             VALUES (UUID(), :folio, \'inscripcion\', :monto_base, 0, :monto_neto, \'pendiente\', :periodo_desde, :periodo_hasta)'
        )->execute([
            ':folio'         => $alumnoFolio,
            ':monto_base'    => $monto,
            ':monto_neto'    => $monto,
            ':periodo_desde' => $fecha,
            ':periodo_hasta' => $fecha,
        ]);

        $stmt = $this->pdo->prepare(
            'SELECT c.*, a.nombre AS alumno_nombre, a.foto AS alumno_foto,
                    a.telefono AS alumno_telefono, a.saldo_favor AS alumno_saldo_favor
             FROM cobros c JOIN alumnos a ON a.folio = c.alumno_folio
             WHERE c.alumno_folio = :folio AND c.concepto = \'inscripcion\'
             ORDER BY c.generado_en DESC LIMIT 1'
        );
        $stmt->execute([':folio' => $alumnoFolio]);
        return $this->mapear($stmt->fetch());
    }

    public function tieneInscripcion(string $alumnoFolio): bool
    {
        $stmt = $this->pdo->prepare(
            "SELECT 1 FROM cobros WHERE alumno_folio = ? AND concepto = 'inscripcion' LIMIT 1"
        );
        $stmt->execute([$alumnoFolio]);
        return (bool) $stmt->fetchColumn();
    }

    public function generarManual(): int
    {
        // Modelo ACUMULATIVO: por cada programa activo se generan TODOS los meses
        // ya iniciados (encadenados) hasta HOY, aunque no haya pagado los anteriores.
        // Se genera el periodo el día que inicia (no antes), conforme al día de
        // suscripción. Punto de continuación = fin del último cobro (o el vencimiento).
        $limite = date('Y-m-d');

        $programas = $this->pdo->query(
            "SELECT pr.id, pr.alumno_folio, pr.mensualidad,
                    COALESCE(MAX(c.periodo_hasta), pr.vencimiento) AS siguiente
             FROM alumno_programas pr
             JOIN alumnos a ON a.folio = pr.alumno_folio
             LEFT JOIN cobros c ON c.programa_id = pr.id AND c.concepto = 'mensualidad'
             WHERE pr.status = 'activo' AND a.status = 'activo'
             GROUP BY pr.id, pr.alumno_folio, pr.mensualidad, pr.vencimiento"
        )->fetchAll(\PDO::FETCH_ASSOC);

        $existe = $this->pdo->prepare(
            "SELECT 1 FROM cobros WHERE programa_id = ? AND concepto = 'mensualidad' AND periodo_desde = ? LIMIT 1"
        );
        $sig = $this->pdo->prepare("SELECT DATE_ADD(?, INTERVAL 1 MONTH)");
        $ins = $this->pdo->prepare(
            "INSERT INTO cobros
                (id, alumno_folio, programa_id, concepto, monto_base,
                 saldo_favor_aplicado, monto_neto, estado, periodo_desde, periodo_hasta)
             VALUES (:id, :folio, :pid, 'mensualidad', :base, 0, :base2, 'pendiente',
                     :desde, DATE_ADD(:desde2, INTERVAL 1 MONTH))"
        );

        $creados = 0;
        foreach ($programas as $pr) {
            $mes = $pr['siguiente'];
            if (!$mes) continue;
            // Tope de seguridad por si algún programa tuviera cobertura muy vieja
            $guard = 0;
            while ($mes <= $limite && $guard++ < 60) {
                $existe->execute([$pr['id'], $mes]);
                if (!$existe->fetchColumn()) {
                    $ins->execute([
                        ':id' => $this->uuid(), ':folio' => $pr['alumno_folio'], ':pid' => $pr['id'],
                        ':base' => $pr['mensualidad'], ':base2' => $pr['mensualidad'],
                        ':desde' => $mes, ':desde2' => $mes,
                    ]);
                    $creados++;
                }
                $sig->execute([$mes]);
                $mes = $sig->fetchColumn();
            }
        }
        return $creados;
    }

    public function actualizarFactura(string $cobroId, ?bool $requiere, ?bool $realizada): array
    {
        $set = [];
        $params = [':id' => $cobroId];
        if ($requiere !== null) {
            $set[] = 'requiere_factura = :rf';
            $params[':rf'] = $requiere ? 1 : 0;
        }
        if ($realizada !== null) {
            $set[] = 'factura_realizada = :fr';
            $params[':fr'] = $realizada ? 1 : 0;
        }
        if ($set) {
            $this->pdo->prepare('UPDATE cobros SET ' . implode(', ', $set) . ' WHERE id = :id')->execute($params);
        }

        // Al pedir factura se congela una copia de los datos fiscales del alumno.
        // Si mañana corrigen el RFC, esta factura conserva con qué se emitió.
        if ($requiere === true) {
            $this->copiarDatosFiscales($cobroId);
        }

        return $this->porId($cobroId);
    }

    /**
     * Copia al cobro los datos fiscales vigentes del alumno. Si el alumno no
     * los tiene capturados no se bloquea nada: el cobro queda sin copia y la
     * lista de facturas pendientes lo marca como incompleto. No se le detiene
     * el cobro a la cajera por un trámite administrativo.
     */
    private function copiarDatosFiscales(string $cobroId): void
    {
        $this->pdo->prepare(
            'UPDATE cobros c
             JOIN alumno_datos_fiscales f ON f.alumno_folio = c.alumno_folio
             SET c.fact_razon_social   = f.razon_social,
                 c.fact_rfc            = f.rfc,
                 c.fact_regimen_fiscal = f.regimen_fiscal,
                 c.fact_uso_cfdi       = f.uso_cfdi,
                 c.fact_codigo_postal  = f.codigo_postal,
                 c.fact_email          = f.email
             WHERE c.id = ?'
        )->execute([$cobroId]);
    }

    public function pagosDe(string $cobroId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, monto, metodo, fecha, ticket, concepto
             FROM pagos WHERE cobro_id = ? ORDER BY fecha DESC, id DESC'
        );
        $stmt->execute([$cobroId]);
        return array_map(fn($r) => [
            'id'       => $r['id'],
            'monto'    => (float) $r['monto'],
            'metodo'   => $r['metodo'],
            'fecha'    => $r['fecha'],
            'ticket'   => $r['ticket'],
            'concepto' => $r['concepto'],
        ], $stmt->fetchAll());
    }

    /**
     * Marca un cobro como "no se cobra". El mes se da por CUBIERTO: el alumno no
     * lo debe y su vencimiento avanza igual que si hubiera pagado. El motivo es
     * obligatorio porque siempre hay que poder explicar por qué no se cobró.
     */
    public function condonar(string $cobroId, string $motivo, string $usuarioId): array
    {
        $cobro = $this->porId($cobroId);
        if (!$cobro) {
            throw new RuntimeException('Cobro no encontrado');
        }
        if ($cobro['estado'] === 'pagado') {
            throw new RuntimeException('Este cobro ya está liquidado');
        }
        if ($cobro['estado'] === 'condonado') {
            throw new RuntimeException('Este cobro ya está marcado como no cobrable');
        }
        if ($cobro['montoPagado'] > 0) {
            throw new RuntimeException(
                'Este cobro ya tiene abonos registrados. Cancela primero los pagos si quieres condonarlo.'
            );
        }
        if (trim($motivo) === '') {
            throw new RuntimeException('Escribe el motivo por el que no se cobra');
        }

        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare(
                "UPDATE cobros
                 SET estado = 'condonado', motivo_no_cobro = :motivo,
                     condonado_por = :usuario, condonado_en = NOW()
                 WHERE id = :id"
            )->execute([
                ':motivo'  => trim($motivo),
                ':usuario' => $usuarioId,
                ':id'      => $cobroId,
            ]);

            // El ciclo avanza: si no, el mes condonado seguiría anclando el
            // vencimiento y el alumno aparecería vencido para siempre.
            if (!empty($cobro['programaId'])) {
                $this->recalcularVencimiento($cobro['programaId'], false);
            }

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        return $this->porId($cobroId);
    }

    /**
     * Deja el vencimiento del programa en el mes MÁS VIEJO que siga por cobrar
     * (correcto aunque paguen fuera de orden); si ya no queda nada, en el fin de
     * su última cobertura. Los condonados cuentan como cubiertos.
     *
     * @param bool $tocarUltimoPago true al liquidar un cobro (hubo dinero de por
     *                              medio), false al condonar: no hubo pago que registrar.
     */
    private function recalcularVencimiento(string $programaId, bool $tocarUltimoPago): void
    {
        $setUltimoPago = $tocarUltimoPago ? 'ultimo_pago = CURDATE(),' : '';

        $this->pdo->prepare(
            "UPDATE alumno_programas p
             SET $setUltimoPago
                 vencimiento = COALESCE(
                     (SELECT MIN(x.periodo_desde) FROM cobros x
                       WHERE x.programa_id = p.id AND x.concepto = 'mensualidad'
                         AND x.estado NOT IN ('pagado','condonado')),
                     (SELECT MAX(x.periodo_hasta) FROM cobros x
                       WHERE x.programa_id = p.id AND x.concepto = 'mensualidad')
                 )
             WHERE p.id = :pid"
        )->execute([':pid' => $programaId]);
    }

    /** Devuelve el siguiente folio consecutivo de la sucursal e incrementa el contador (atómico). */
    private function siguienteFolioPago(string $sucursalId): int
    {
        $this->pdo->prepare('INSERT IGNORE INTO folio_pago_seq (sucursal_id, siguiente) VALUES (?, 1)')
                  ->execute([$sucursalId]);
        // LAST_INSERT_ID(siguiente) fija el folio a asignar y deja siguiente = folio + 1
        $this->pdo->prepare('UPDATE folio_pago_seq SET siguiente = LAST_INSERT_ID(siguiente) + 1 WHERE sucursal_id = ?')
                  ->execute([$sucursalId]);
        return (int) $this->pdo->lastInsertId();
    }

    private function mapear(array $row): array
    {
        return [
            'id'               => $row['id'],
            'alumnoFolio'      => $row['alumno_folio'],
            'programaId'       => $row['programa_id'] ?? null,
            'programaModalidad'=> $row['programa_modalidad'] ?? null,
            'concepto'         => $row['concepto'],
            'alumnoNombre'     => $row['alumno_nombre'],
            'alumnoFoto'       => $row['alumno_foto'] ?? null,
            'alumnoTelefono'   => $row['alumno_telefono'] ?? null,
            'alumnoEmail'      => $row['alumno_email'] ?? null,
            'alumnoSaldoFavor' => (float) ($row['alumno_saldo_favor'] ?? 0),
            'montoBase'        => (float) $row['monto_base'],
            'saldoFavorAplicado' => (float) $row['saldo_favor_aplicado'],
            'montoNeto'        => (float) $row['monto_neto'],
            'montoPagado'      => (float) $row['monto_pagado'],
            'estado'           => $row['estado'],
            'motivoNoCobro'    => $row['motivo_no_cobro'] ?? null,
            'requiereFactura'  => (bool) ($row['requiere_factura'] ?? false),
            // Para avisar al cobrar que faltan datos, antes de marcar la factura
            'alumnoTieneDatosFiscales' => (bool) ($row['tiene_datos_fiscales'] ?? false),
            'facturaRealizada' => (bool) ($row['factura_realizada'] ?? false),
            // Copia congelada de los datos fiscales con los que se pidió la factura
            'datosFactura'     => empty($row['fact_rfc']) ? null : [
                'razonSocial'   => $row['fact_razon_social'],
                'rfc'           => $row['fact_rfc'],
                'regimenFiscal' => $row['fact_regimen_fiscal'],
                'usoCfdi'       => $row['fact_uso_cfdi'],
                'codigoPostal'  => $row['fact_codigo_postal'],
                'email'         => $row['fact_email'],
            ],
            'periodoDesde'     => $row['periodo_desde'],
            'periodoHasta'     => $row['periodo_hasta'],
            'generadoEn'       => $row['generado_en'],
        ];
    }
}
