<?php

declare(strict_types=1);

use Beluga\Domain\Alumno\AlumnoRepository;
use Beluga\Domain\Alumno\DatosFiscalesRepository;
use Beluga\Domain\Ausencia\AusenciaRepository;
use Beluga\Infrastructure\Persistence\MySql\AusenciaMySqlRepository;
use Beluga\Infrastructure\Persistence\MySql\DatosFiscalesMySqlRepository;
use Beluga\Domain\Usuario\UsuarioRepository;
use Beluga\Infrastructure\Mail\Mailer;
use Beluga\Infrastructure\Persistence\MySql\UsuarioMySqlRepository;
use Beluga\Domain\Calendario\CalendarioService;
use Beluga\Domain\Inhabil\InhabilService;
use Beluga\Domain\Instructor\InstructorRepository;
use Beluga\Domain\Pago\PagoService;
use Beluga\Domain\Cobro\CobroRepository;
use Beluga\Domain\Horario\HorarioRepository;
use Beluga\Domain\Grupo\GrupoRepository;
use Beluga\Domain\Reposicion\ReposicionRepository;
use Beluga\Domain\Asistencia\AsistenciaRepository;
use Beluga\Domain\Plan\PlanRepository;
use Beluga\Infrastructure\Persistence\MySql\AlumnoMySqlRepository;
use Beluga\Infrastructure\Persistence\MySql\HorarioMySqlRepository;
use Beluga\Infrastructure\Persistence\MySql\GrupoMySqlRepository;
use Beluga\Infrastructure\Persistence\MySql\ReposicionMySqlRepository;
use Beluga\Infrastructure\Persistence\MySql\AsistenciaMySqlRepository;
use Beluga\Infrastructure\Persistence\MySql\CalendarioMySqlService;
use Beluga\Infrastructure\Persistence\MySql\CobroMySqlRepository;
use Beluga\Infrastructure\Persistence\MySql\InhabilMySqlService;
use Beluga\Infrastructure\Persistence\MySql\InstructorMySqlRepository;
use Beluga\Infrastructure\Persistence\MySql\PagoMySqlService;
use Beluga\Infrastructure\Persistence\MySql\PlanMySqlRepository;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Beluga\Application\Actions\Auth\MisPermisosAction;
use Beluga\Application\Middleware\PermisosMiddleware;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Log\LoggerInterface;
use Slim\Psr7\Factory\ResponseFactory;

return [
    // ── HTTP ──────────────────────────────────────────────────────────────
    ResponseFactoryInterface::class => \DI\create(ResponseFactory::class),

    // ── Logger ────────────────────────────────────────────────────────────
    LoggerInterface::class => function () {
        $logDir = __DIR__ . '/../logs';
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
        $logger = new Logger('beluga');
        $logger->pushHandler(new StreamHandler($logDir . '/app.log', Logger::DEBUG));
        return $logger;
    },

    // ── PDO ───────────────────────────────────────────────────────────────
    PDO::class => function () {
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            $_ENV['DB_HOST']     ?? 'localhost',
            $_ENV['DB_PORT']     ?? '3306',
            $_ENV['DB_DATABASE'] ?? 'joseadr3_beluga'
        );
        return new PDO(
            $dsn,
            $_ENV['DB_USERNAME'] ?? 'joseadr3__root',
            $_ENV['DB_PASSWORD'] ?? '',
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]
        );
    },

    // El ticket de cobro se imprime en el navegador (frontend utils/ticket58.ts).
    // Aquí no se registra ningún servicio de impresión: mandarle ESC/POS a la
    // térmica exigiría un programa instalado en cada caja, y se descartó.

    // ── Permisos por rol ──────────────────────────────────────────────────
    // La matriz la llena el cliente en docs/matriz_accesos_por_rol.xlsx y de
    // ahí se genera permisos.php (ver scripts/generar_permisos.py).
    'permisos' => fn() => require __DIR__ . '/permisos.php',

    PermisosMiddleware::class => fn(ContainerInterface $c) => new PermisosMiddleware(
        $c->get(ResponseFactoryInterface::class),
        $c->get('permisos')
    ),

    MisPermisosAction::class => fn(ContainerInterface $c) => new MisPermisosAction(
        $c->get(LoggerInterface::class),
        $c->get('permisos')
    ),

    // ── Repositorios e implementaciones de dominio ────────────────────────
    AlumnoRepository::class    => \DI\autowire(AlumnoMySqlRepository::class),
    AusenciaRepository::class  => \DI\autowire(AusenciaMySqlRepository::class),
    DatosFiscalesRepository::class => \DI\autowire(DatosFiscalesMySqlRepository::class),
    UsuarioRepository::class   => \DI\autowire(UsuarioMySqlRepository::class),
    Mailer::class              => \DI\autowire(Mailer::class),
    InstructorRepository::class=> \DI\autowire(InstructorMySqlRepository::class),
    CalendarioService::class   => \DI\autowire(CalendarioMySqlService::class),
    PagoService::class         => \DI\autowire(PagoMySqlService::class),
    InhabilService::class      => \DI\autowire(InhabilMySqlService::class),
    PlanRepository::class      => \DI\autowire(PlanMySqlRepository::class),
    CobroRepository::class     => \DI\autowire(CobroMySqlRepository::class),
    HorarioRepository::class   => \DI\autowire(HorarioMySqlRepository::class),
    GrupoRepository::class     => \DI\autowire(GrupoMySqlRepository::class),
    ReposicionRepository::class=> \DI\autowire(ReposicionMySqlRepository::class),
    AsistenciaRepository::class=> \DI\autowire(AsistenciaMySqlRepository::class),
];
