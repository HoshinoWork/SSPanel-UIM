<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Services\Client\ApiException;
use App\Services\Client\Limits;
use App\Services\Client\Sessions;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Exception\HttpMethodNotAllowedException;
use Slim\Exception\HttpNotFoundException;
use Slim\Factory\AppFactory;
use Throwable;

final class ClientApi implements MiddlewareInterface
{
    public function __construct(private readonly bool $authenticated = false)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $requestId = $request->getAttribute('client.request_id') ?? bin2hex(random_bytes(16));
        try {
            if (! ($_ENV['client_api_enabled'] ?? false)) {
                throw new ApiException(404, 'not_found', 'Client API is disabled');
            }
            if ($request->getUri()->getScheme() !== 'https' && ! ($_ENV['client_api_allow_http'] ?? false)) {
                throw new ApiException(400, 'https_required', 'HTTPS is required');
            }
            $ip = (string) ($request->getServerParams()['REMOTE_ADDR'] ?? 'unknown');
            if (! $this->authenticated && $request->getAttribute('client.request_id') === null) {
                Limits::check('client_ip', $ip, 120);
                $stream = $request->getBody();
                if ($stream->isSeekable()) {
                    $stream->rewind();
                }
                $body = $stream->read(65537);
                if (strlen($body) > 65536) {
                    throw new ApiException(413, 'body_too_large', 'Request body exceeds 64 KiB');
                }
                if ($body !== '' && strtolower(trim(explode(';', $request->getHeaderLine('Content-Type'))[0])) !== 'application/json') {
                    throw new ApiException(415, 'json_required', 'Use application/json');
                }
                if ($body !== '') {
                    $object = json_decode($body);
                    if (! is_object($object)) {
                        throw new ApiException(422, 'invalid_json', 'Request body must be a JSON object');
                    }
                    $request = $request->withParsedBody(json_decode($body, true));
                }
                $request = $request->withAttribute('client.request_id', $requestId);
            } elseif ($this->authenticated) {
                $authorization = $request->getHeaderLine('Authorization');
                if (! preg_match('/^Bearer ([A-Za-z0-9_-]{43})$/D', $authorization, $match)) {
                    throw new ApiException(401, 'unauthorized', 'Bearer token required');
                }
                [$user, $session] = Sessions::authenticate($match[1]);
                Limits::check('client_user', (string) $user->id, 120);
                $request = $request->withAttribute('client.user', $user)->withAttribute('client.session', $session);
            }
            $response = $handler->handle($request);
        } catch (ApiException $error) {
            $response = AppFactory::determineResponseFactory()->createResponse($error->status);
            $response->getBody()->write(json_encode(['data' => null, 'error' => ['code' => $error->errorCode,
                'message' => $error->getMessage(),
            ], 'request_id' => $requestId,
            ], JSON_THROW_ON_ERROR));
            $response = $response->withHeader('Content-Type', 'application/json');
            if ($error->status === 429) {
                $response = $response->withHeader('Retry-After', '60');
            }
        } catch (HttpNotFoundException | HttpMethodNotAllowedException $error) {
            $status = $error instanceof HttpNotFoundException ? 404 : 405;
            $response = AppFactory::determineResponseFactory()->createResponse($status);
            $response->getBody()->write(json_encode(['data' => null, 'error' => ['code' => $status === 404 ? 'not_found' : 'method_not_allowed',
                'message' => $status === 404 ? 'API route not found' : 'HTTP method not allowed',
            ], 'request_id' => $requestId,
            ], JSON_THROW_ON_ERROR));
            $response = $response->withHeader('Content-Type', 'application/json');
            if ($error instanceof HttpMethodNotAllowedException) {
                $response = $response->withHeader('Allow', implode(', ', $error->getAllowedMethods()));
            }
        } catch (Throwable $error) {
            error_log('Client API failure [' . $requestId . '] ' . $error::class);
            $response = AppFactory::determineResponseFactory()->createResponse(503);
            $response->getBody()->write(json_encode(['data' => null, 'error' => ['code' => 'service_unavailable',
                'message' => 'Service temporarily unavailable',
            ], 'request_id' => $requestId,
            ], JSON_THROW_ON_ERROR));
            $response = $response->withHeader('Content-Type', 'application/json');
        }
        return $response->withHeader('Cache-Control', 'no-store')->withHeader('X-Request-ID', $requestId)
            ->withHeader('X-Content-Type-Options', 'nosniff')->withHeader('Referrer-Policy', 'no-referrer');
    }
}
