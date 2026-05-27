<?php

declare(strict_types=1);

use DI\ContainerBuilder;
use Slim\Factory\AppFactory;

require __DIR__ . '/../vendor/autoload.php';

// Cargar variables de entorno
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->safeLoad();

// Contenedor de dependencias
$containerBuilder = new ContainerBuilder();
$containerBuilder->addDefinitions(__DIR__ . '/../config/container.php');
$container = $containerBuilder->build();

// Crear la app Slim
AppFactory::setContainer($container);
$app = AppFactory::create();
$app->setBasePath($_ENV['APP_BASE_PATH'] ?? '');

// Middleware
(require __DIR__ . '/../config/middleware.php')($app);

// Rutas
(require __DIR__ . '/../config/routes.php')($app);

$app->run();
