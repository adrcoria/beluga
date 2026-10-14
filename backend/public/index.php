<?php

declare(strict_types=1);

use DI\ContainerBuilder;
use Slim\Factory\AppFactory;

require __DIR__ . '/../vendor/autoload.php';

// Cargar variables de entorno
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->safeLoad();

// Zona horaria del negocio, fijada aquí y no en php.ini: el equipo de desarrollo
// y el hosting tienen configuraciones distintas, y de eso depende qué día es
// "hoy" para los cobros, la mora y la limpieza del pizarrón.
//
// Sin esto, PHP tomaba la del servidor (Europe/Berlin en el XAMPP local) mientras
// MySQL respondía en hora de Morelia: se descuadraban en un día y las fechas
// calculadas en PHP no cuadraban con las calculadas con CURDATE().
//
// Morelia es UTC-6 todo el año desde que México eliminó el horario de verano (2022).
date_default_timezone_set($_ENV['APP_TIMEZONE'] ?? 'America/Mexico_City');

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
