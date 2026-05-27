<?php

declare(strict_types=1);

namespace Beluga\Application\Middleware;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as Handler;

class AuthMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly ResponseFactoryInterface $responseFactory
    ) {}

    public function process(Request $request, Handler $handler): Response
    {
        $auth = $request->getHeaderLine('Authorization');

        if (!str_starts_with($auth, 'Bearer ')) {
            return $this->unauthorized();
        }

        $token = substr($auth, 7);

        try {
            $decoded = JWT::decode($token, new Key($_ENV['JWT_SECRET'], 'HS256'));
            // Rechazar tokens de pre-autenticación (OTP pendiente)
            if (isset($decoded->purpose)) {
                return $this->unauthorized();
            }
            $request = $request->withAttribute('usuario_id',  $decoded->sub);
            $request = $request->withAttribute('usuario_rol', $decoded->rol);
        } catch (\Throwable) {
            return $this->unauthorized();
        }

        return $handler->handle($request);
    }

    private function unauthorized(): Response
    {
        $response = $this->responseFactory->createResponse(401);
        $response->getBody()->write(json_encode(['error' => 'No autorizado']));
        return $response->withHeader('Content-Type', 'application/json');
    }
}
