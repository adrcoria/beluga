<?php

declare(strict_types=1);

namespace Beluga\Application\Middleware;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as Handler;

/**
 * Hace valer la matriz de permisos del cliente (config/permisos.php).
 *
 * Corre después de AuthMiddleware, que ya dejó el rol en el atributo
 * `usuario_rol`. Resuelve a qué módulo pertenece la ruta y compara:
 *
 *   GET / HEAD                      → basta nivel 'ver'
 *   POST / PUT / PATCH / DELETE     → exige nivel 'total'
 *
 * Esconder el menú en el frontend no protege nada: cualquiera con el token
 * puede llamar al API a mano. Esta es la validación que cuenta.
 */
class PermisosMiddleware implements MiddlewareInterface
{
    private const SOLO_LECTURA = ['GET', 'HEAD', 'OPTIONS'];

    public function __construct(
        private readonly ResponseFactoryInterface $responseFactory,
        private readonly array $permisos
    ) {}

    public function process(Request $request, Handler $handler): Response
    {
        if (in_array($request->getMethod(), ['OPTIONS'], true)) {
            return $handler->handle($request);
        }

        $ruta = $this->rutaApi($request);

        // Catálogos que casi toda pantalla necesita para armarse
        foreach ($this->permisos['libres'] as $libre) {
            if ($this->coincide($ruta, $libre)) {
                return $handler->handle($request);
            }
        }

        $modulo = $this->moduloDe($ruta);
        if ($modulo === null) {
            // Ruta sin módulo asignado: se deja pasar en vez de romper una
            // pantalla por un olvido del mapa. Queda en el log para revisarla.
            return $handler->handle($request);
        }

        $rol = (string) $request->getAttribute('usuario_rol');
        if (!isset($this->permisos['matriz'][$modulo][$rol])) {
            return $this->negar("Tu perfil no tiene permisos configurados para esta sección.");
        }

        $nivel    = $this->permisos['matriz'][$modulo][$rol];
        $esLectura = in_array($request->getMethod(), self::SOLO_LECTURA, true);

        if ($esLectura) {
            if ($nivel !== 'no' || $this->puedeLeerPorDependencia($modulo, $rol)) {
                return $handler->handle($request);
            }
            return $this->negar('No tienes acceso a esta sección.');
        }

        if ($nivel === 'total') {
            return $handler->handle($request);
        }

        return $this->negar($nivel === 'ver'
            ? 'Tu perfil puede consultar esta sección, pero no modificarla.'
            : 'No tienes acceso a esta sección.');
    }

    /**
     * ¿Algún módulo que el rol SÍ puede ver declara que necesita leer este?
     * Es lo que permite que Cumpleañeros liste alumnos sin dar acceso al
     * módulo de Alumnos.
     */
    private function puedeLeerPorDependencia(string $modulo, string $rol): bool
    {
        foreach ($this->permisos['modulos'] as $otro => $cfg) {
            if ($otro === $modulo) continue;
            if (!in_array($modulo, $cfg['lee'], true)) continue;
            if (($this->permisos['matriz'][$otro][$rol] ?? 'no') !== 'no') {
                return true;
            }
        }
        return false;
    }

    /** Ruta sin el prefijo /api y sin el base path, con '/' inicial. */
    private function rutaApi(Request $request): string
    {
        $ruta = $request->getUri()->getPath();

        $base = (string) ($_ENV['APP_BASE_PATH'] ?? '');
        if ($base !== '' && str_starts_with($ruta, $base)) {
            $ruta = substr($ruta, strlen($base));
        }
        if (str_starts_with($ruta, '/api')) {
            $ruta = substr($ruta, 4);
        }

        return '/' . ltrim($ruta, '/');
    }

    private function moduloDe(string $ruta): ?string
    {
        foreach ($this->permisos['modulos'] as $modulo => $cfg) {
            foreach ($cfg['rutas'] as $prefijo) {
                if ($this->coincide($ruta, $prefijo)) {
                    return $modulo;
                }
            }
        }
        return null;
    }

    /** `/cobros/123/abonos` cae bajo `/cobros`, pero `/cobros-x` no. */
    private function coincide(string $ruta, string $prefijo): bool
    {
        return $ruta === $prefijo || str_starts_with($ruta, $prefijo . '/');
    }

    private function negar(string $mensaje): Response
    {
        $response = $this->responseFactory->createResponse(403);
        $response->getBody()->write(json_encode(['error' => $mensaje], JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type', 'application/json');
    }
}
