<?php
/**
 * Diagnóstico temporal del backend en producción.
 *
 * Subir a:  backend/public/diag.php
 * Abrir:    https://acuaticabeluga.com/admin/backend/public/diag.php
 *           (o la ruta equivalente según dónde quedó el backend)
 *
 * >>> BORRARLO DEL SERVIDOR EN CUANTO TERMINES <<<
 *
 * Va imprimiendo cada paso ANTES de intentarlo: si la página se corta,
 * el último renglón que alcanzaste a ver es el paso que truena.
 */

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

header('Content-Type: text/plain; charset=utf-8');
while (ob_get_level()) ob_end_flush();

function paso(string $texto): void {
    echo "\n>>> $texto\n";
    flush();
}
function ok(string $texto): void  { echo "    OK    $texto\n"; flush(); }
function mal(string $texto): void { echo "    MAL   $texto\n"; flush(); }

$raiz = dirname(__DIR__);

echo "=========================================================\n";
echo " Diagnóstico Beluga\n";
echo "=========================================================\n";
echo "PHP:        " . PHP_VERSION . "\n";
echo "SAPI:       " . PHP_SAPI . "\n";
echo "Raíz:       $raiz\n";
echo "Script:     " . __FILE__ . "\n";

// ── 1. Versión de PHP ──────────────────────────────────────────────────────
paso('Versión de PHP (el proyecto necesita 8.1 o superior)');
PHP_VERSION_ID >= 80100
    ? ok('PHP ' . PHP_VERSION)
    : mal('PHP ' . PHP_VERSION . ' es demasiado viejo. Cámbialo en cPanel → Select PHP Version.');

// ── 2. Extensiones ─────────────────────────────────────────────────────────
paso('Extensiones de PHP');
foreach (['pdo_mysql', 'mbstring', 'json', 'openssl', 'curl'] as $ext) {
    extension_loaded($ext) ? ok($ext) : mal("$ext NO está cargada");
}

// ── 3. Archivos que se cargan en cada request ──────────────────────────────
paso('Archivos indispensables');
$necesarios = [
    'vendor/autoload.php',
    'config/container.php',
    'config/middleware.php',
    'config/routes.php',
    'config/permisos.php',
    'public/index.php',
    '.env',
];
$faltan = 0;
foreach ($necesarios as $rel) {
    $ruta = "$raiz/$rel";
    if (!is_file($ruta)) { mal("$rel  NO EXISTE"); $faltan++; continue; }
    $bytes = filesize($ruta);
    if ($bytes === 0) { mal("$rel  está VACÍO (subida cortada)"); $faltan++; continue; }
    ok(sprintf('%-28s %8s bytes', $rel, number_format($bytes)));
}

// ── 4. Sintaxis de los .php propios (detecta subidas truncadas) ────────────
paso('Archivos PHP truncados o corruptos en src/ y config/');
$malos = 0;
$revisados = 0;
foreach (['src', 'config'] as $dir) {
    if (!is_dir("$raiz/$dir")) { mal("falta el directorio $dir/"); continue; }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$raiz/$dir"));
    foreach ($it as $f) {
        if ($f->getExtension() !== 'php') continue;
        $revisados++;
        $codigo = file_get_contents($f->getPathname());
        if ($codigo === false || $codigo === '') {
            mal('VACÍO: ' . str_replace($raiz . '/', '', $f->getPathname()));
            $malos++;
            continue;
        }
        // Compila sin ejecutar
        $tmp = @token_get_all($codigo, TOKEN_PARSE);
        if ($tmp === false) {
            mal('SINTAXIS: ' . str_replace($raiz . '/', '', $f->getPathname()));
            $malos++;
        }
    }
}
echo "    ($revisados archivos revisados, $malos con problema)\n";

// ── 5. Autoload ────────────────────────────────────────────────────────────
paso('Cargando vendor/autoload.php');
require $raiz . '/vendor/autoload.php';
ok('autoload cargado');

paso('Clases de las librerías');
foreach ([
    'Slim\\Factory\\AppFactory',
    'DI\\ContainerBuilder',
    'Dotenv\\Dotenv',
    'Firebase\\JWT\\JWT',
    'Monolog\\Logger',
    'Respect\\Validation\\Validator',
    'PHPMailer\\PHPMailer\\PHPMailer',
] as $clase) {
    class_exists($clase) ? ok($clase) : mal("$clase NO se encuentra (falta esa librería en vendor/)");
}

// ── 6. Variables de entorno ────────────────────────────────────────────────
paso('Archivo .env');
Dotenv\Dotenv::createImmutable($raiz)->safeLoad();
foreach (['DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'APP_BASE_PATH', 'APP_TIMEZONE', 'CORS_ORIGIN'] as $k) {
    isset($_ENV[$k]) && $_ENV[$k] !== ''
        ? ok(sprintf('%-15s = %s', $k, $_ENV[$k]))
        : mal("$k sin valor");
}
isset($_ENV['DB_PASSWORD']) && $_ENV['DB_PASSWORD'] !== ''
    ? ok('DB_PASSWORD     = (definida)')
    : mal('DB_PASSWORD sin valor');
isset($_ENV['JWT_SECRET']) && strlen((string) $_ENV['JWT_SECRET']) >= 16
    ? ok('JWT_SECRET      = (definida)')
    : mal('JWT_SECRET falta o es muy corta');

// ── 7. Base de datos ───────────────────────────────────────────────────────
paso('Conexión a la base de datos');
try {
    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            $_ENV['DB_HOST'] ?? '', $_ENV['DB_PORT'] ?? '3306', $_ENV['DB_DATABASE'] ?? ''),
        $_ENV['DB_USERNAME'] ?? '', $_ENV['DB_PASSWORD'] ?? '',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 8]
    );
    ok('conectado a ' . ($_ENV['DB_DATABASE'] ?? '?'));
    $n = $pdo->query('SELECT COUNT(*) FROM usuarios')->fetchColumn();
    ok("tabla usuarios: $n registros");
    $hoy = $pdo->query('SELECT CURDATE()')->fetchColumn();
    ok("hoy según MySQL: $hoy · según PHP: " . date('Y-m-d'));
} catch (Throwable $e) {
    mal('NO conecta: ' . $e->getMessage());
}

// ── 8. Permisos de escritura ───────────────────────────────────────────────
paso('Directorio de logs');
$logDir = "$raiz/logs";
if (!is_dir($logDir)) {
    @mkdir($logDir, 0755, true);
}
is_dir($logDir) && is_writable($logDir)
    ? ok("$logDir se puede escribir")
    : mal("$logDir no existe o no se puede escribir (Monolog truena al arrancar)");

// ── 9. Arranque completo ───────────────────────────────────────────────────
paso('Construyendo el contenedor y la app (el arranque real)');
try {
    $cb = new DI\ContainerBuilder();
    $cb->addDefinitions($raiz . '/config/container.php');
    $container = $cb->build();
    ok('contenedor construido');

    Slim\Factory\AppFactory::setContainer($container);
    $app = Slim\Factory\AppFactory::create();
    $app->setBasePath($_ENV['APP_BASE_PATH'] ?? '');
    ok('app creada, basePath = "' . ($_ENV['APP_BASE_PATH'] ?? '') . '"');

    (require $raiz . '/config/middleware.php')($app);
    ok('middleware registrado');

    (require $raiz . '/config/routes.php')($app);
    ok('rutas registradas');

    $rutas = $app->getRouteCollector()->getRoutes();
    ok(count($rutas) . ' rutas en total');

    $login = null;
    foreach ($rutas as $r) {
        if (str_contains($r->getPattern(), '/auth/login')) $login = $r;
    }
    $login
        ? ok('ruta de login: ' . implode('|', $login->getMethods()) . ' ' . $login->getPattern())
        : mal('NO existe la ruta de login');
} catch (Throwable $e) {
    mal(get_class($e) . ': ' . $e->getMessage());
    mal('en ' . $e->getFile() . ':' . $e->getLine());
}

echo "\n=========================================================\n";
echo " Si llegaste hasta aquí, el backend arranca bien.\n";
echo " BORRA ESTE ARCHIVO DEL SERVIDOR.\n";
echo "=========================================================\n";
