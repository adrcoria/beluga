<?php
declare(strict_types=1);

/**
 * Backfill de periodos faltantes en la cadena de cobros de cada programa.
 *
 * Rellena los meses que faltan ENTRE el primer cobro del programa y su
 * vencimiento actual (es decir, meses YA cubiertos/pagados que nunca se
 * materializaron por el backfill incompleto). Los crea como 'pagado'.
 *
 * - Encadena mes a mes con DATE_ADD de MySQL (respeta el corrimiento de fin
 *   de mes, ej. día 30 -> feb 28), igual que la app. No duplica.
 * - NO crea registros en `pagos` (no inventa dinero): el corte de caja /
 *   ingresos por pago NO se altera. Solo completa el historial de cobros.
 * - Usa la mensualidad ACTUAL del programa (no hay registro del monto histórico).
 *
 * Uso:
 *   php scripts/backfill_periodos.php            -> DRY RUN (solo muestra)
 *   php scripts/backfill_periodos.php --apply    -> inserta de verdad
 *
 * Lee la conexión de backend/.env (asegúrate de apuntar a la BD correcta).
 */

$dry = !in_array('--apply', $argv, true);

// ── Cargar .env del backend ────────────────────────────────────────────────
$envPath = __DIR__ . '/../backend/.env';
$env = [];
if (is_file($envPath)) {
    foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) continue;
        [$k, $v] = explode('=', $line, 2);
        $env[trim($k)] = trim($v, " \t\"'");
    }
}
$g = fn(string $k, string $d = '') => $env[$k] ?? (getenv($k) ?: $d);

$dsn = sprintf(
    'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
    $g('DB_HOST', 'localhost'), $g('DB_PORT', '3306'), $g('DB_DATABASE', 'joseadr3_beluga')
);
$pdo = new PDO($dsn, $g('DB_USERNAME', 'root'), $g('DB_PASSWORD', ''), [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_EMULATE_PREPARES   => false,
]);

echo "BD: " . $g('DB_DATABASE') . " @ " . $g('DB_HOST') . "\n";
echo $dry ? "MODO: DRY RUN (no inserta)\n\n" : "MODO: APLICAR (inserta)\n\n";

// ── Programas activos ───────────────────────────────────────────────────────
$programas = $pdo->query(
    "SELECT pr.id, pr.alumno_folio, pr.mensualidad, pr.vencimiento, pr.modalidad
     FROM alumno_programas pr
     JOIN alumnos a ON a.folio = pr.alumno_folio
     WHERE pr.status = 'activo' AND a.status = 'activo'"
)->fetchAll(PDO::FETCH_ASSOC);

$minStmt   = $pdo->prepare("SELECT MIN(periodo_desde) FROM cobros WHERE programa_id = ? AND concepto = 'mensualidad'");
$existStmt = $pdo->prepare("SELECT 1 FROM cobros WHERE programa_id = ? AND concepto = 'mensualidad' AND periodo_desde = ? LIMIT 1");
$nextStmt  = $pdo->prepare("SELECT DATE_ADD(?, INTERVAL 1 MONTH)");
$insStmt   = $pdo->prepare(
    "INSERT INTO cobros
        (id, alumno_folio, programa_id, concepto, monto_base,
         saldo_favor_aplicado, monto_neto, monto_pagado, estado, periodo_desde, periodo_hasta)
     VALUES (UUID(), ?, ?, 'mensualidad', ?, 0, ?, ?, 'pagado', ?, DATE_ADD(?, INTERVAL 1 MONTH))"
);

$total = 0;
$porPrograma = [];

if (!$dry) $pdo->beginTransaction();
try {
    foreach ($programas as $pr) {
        $minStmt->execute([$pr['id']]);
        $min = $minStmt->fetchColumn();
        if (!$min) continue;                          // sin cobros aún: nada que rellenar

        $mes = $min;
        while ($mes < $pr['vencimiento']) {
            $existStmt->execute([$pr['id'], $mes]);
            if (!$existStmt->fetchColumn()) {
                $total++;
                $porPrograma[$pr['alumno_folio'] . ' (' . $pr['modalidad'] . ')'][] = $mes;
                if (!$dry) {
                    $insStmt->execute([
                        $pr['alumno_folio'], $pr['id'],
                        $pr['mensualidad'], $pr['mensualidad'], $pr['mensualidad'], $mes, $mes,
                    ]);
                }
            }
            $nextStmt->execute([$mes]);
            $mes = $nextStmt->fetchColumn();
        }
    }
    if (!$dry) $pdo->commit();
} catch (\Throwable $e) {
    if (!$dry && $pdo->inTransaction()) $pdo->rollBack();
    fwrite(STDERR, "ERROR: " . $e->getMessage() . "\n");
    exit(1);
}

foreach ($porPrograma as $quien => $meses) {
    echo str_pad($quien, 22) . ' -> ' . implode(', ', $meses) . "\n";
}
echo "\n" . ($dry ? "[DRY RUN] " : "[APLICADO] ") . "Meses faltantes: $total\n";
if ($dry) echo "Para aplicar:  php scripts/backfill_periodos.php --apply\n";
