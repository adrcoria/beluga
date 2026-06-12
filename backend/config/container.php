<?php

declare(strict_types=1);

use Beluga\Domain\Alumno\AlumnoRepository;
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

    // ── Repositorios e implementaciones de dominio ────────────────────────
    AlumnoRepository::class    => \DI\autowire(AlumnoMySqlRepository::class),
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
