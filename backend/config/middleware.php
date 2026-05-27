<?php

declare(strict_types=1);

use Slim\App;
use Slim\Middleware\ContentLengthMiddleware;

return function (App $app): void {
    // Parseo del body JSON
    $app->addBodyParsingMiddleware();

    // Manejo de errores
    $app->addErrorMiddleware(
        displayErrorDetails: ($_ENV['APP_DEBUG'] ?? 'false') === 'true',
        logErrors:           true,
        logErrorDetails:     true
    );

    // CORS — ajustar origen en producción
    $app->add(function ($request, $handler) {
        $response = $handler->handle($request);
        return $response
            ->withHeader('Access-Control-Allow-Origin',  $_ENV['CORS_ORIGIN'] ?? 'http://localhost:5173')
            ->withHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE, OPTIONS')
            ->withHeader('Access-Control-Allow-Headers', 'Content-Type, Authorization')
            ->withHeader('Access-Control-Allow-Credentials', 'true');
    });

    $app->addRoutingMiddleware();
    $app->add(new ContentLengthMiddleware());
};
