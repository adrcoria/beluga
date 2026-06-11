<?php

declare(strict_types=1);

namespace Beluga\Application\Actions;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;

abstract class Action
{
    protected Request  $request;
    protected Response $response;
    protected array    $args;

    public function __construct(protected readonly LoggerInterface $logger) {}

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $this->request  = $request;
        $this->response = $response;
        $this->args     = $args;

        return $this->action();
    }

    abstract protected function action(): Response;

    protected function resolveArg(string $name): mixed
    {
        return $this->args[$name] ?? null;
    }

    protected function getBody(): array
    {
        return (array) ($this->request->getParsedBody() ?? []);
    }

    protected function getQueryParam(string $key, mixed $default = null): mixed
    {
        return $this->request->getQueryParams()[$key] ?? $default;
    }

    protected function json(mixed $data, int $status = 200): Response
    {
        $payload = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        $this->response->getBody()->write($payload);
        return $this->response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus($status);
    }

    protected function error(string $message, int $status = 400): Response
    {
        return $this->json(['error' => $message], $status);
    }
}
