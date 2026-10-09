<?php

declare(strict_types=1);

namespace App\Controllers\Client\V1;

use App\Models\User;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

abstract class Controller
{
    protected function json(ServerRequestInterface $request, ResponseInterface $response, mixed $data = null, int $status = 200): ResponseInterface
    {
        $response->getBody()->write(json_encode(['data' => $data, 'error' => null,
            'request_id' => $request->getAttribute('client.request_id'),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        return $response->withStatus($status)->withHeader('Content-Type', 'application/json');
    }

    protected function input(ServerRequestInterface $request): array
    {
        return (array) ($request->getParsedBody() ?? []);
    }

    protected function user(ServerRequestInterface $request): User
    {
        return $request->getAttribute('client.user');
    }

    protected function ip(ServerRequestInterface $request): string
    {
        return (string) ($request->getServerParams()['REMOTE_ADDR'] ?? 'unknown');
    }
}
