<?php

declare(strict_types=1);

namespace App\Controllers\Client\V1;

use App\Models\Config;
use App\Services\Captcha;
use App\Services\Client\Accounts;
use App\Services\Client\Input;
use App\Services\Client\Sessions;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class AuthController extends Controller
{
    public function config(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->json($request, $response, ['name' => $_ENV['appName'], 'api_version' => 'v1',
            'registration_mode' => Config::obtain('reg_mode'), 'email_verification' => (bool) Config::obtain('reg_email_verify'),
            'tos_url' => rtrim($_ENV['baseUrl'], '/') . '/tos', 'currency' => 'CNY',
            'subscriptions_enabled' => (bool) ($_ENV['Subscribe'] ?? false),
            'checkin_enabled' => (bool) Config::obtain('enable_checkin'),
        ]);
    }

    public function captcha(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->json($request, $response, ['provider' => Config::obtain('captcha_provider'),
            'verification_url' => rtrim($_ENV['baseUrl'], '/') . '/client/v1/captcha/browser',
            'public_config' => Captcha::generate(), 'required' => [
                'login' => (bool) Config::obtain('enable_login_captcha'), 'registration' => (bool) Config::obtain('enable_reg_captcha'),
                'password_reset' => (bool) Config::obtain('enable_reset_password_captcha'),
                'checkin' => (bool) Config::obtain('enable_checkin_captcha'),
            ],
        ]);
    }

    public function login(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $data = Accounts::login($this->input($request), $this->ip($request));
        return $this->json($request, $response, $data, isset($data['mfa_required']) ? 202 : 201);
    }

    public function mfa(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        return $this->json($request, $response, Accounts::mfa($args['id'], $this->input($request)), 201);
    }

    public function refresh(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->json($request, $response, Sessions::refresh(Input::text($this->input($request), 'refresh_token')));
    }

    public function register(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->json($request, $response, Accounts::register($this->input($request), $this->ip($request)), 201);
    }

    public function email(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $input = $this->input($request);
        return $this->json($request, $response, Accounts::sendVerification(
            $input,
            $this->ip($request),
            isset($input['purpose']) ? Input::text($input, 'purpose', 32) : 'registration'
        ), 202);
    }

    public function resetRequest(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->json($request, $response, Accounts::sendVerification($this->input($request), $this->ip($request), 'password_reset'), 202);
    }

    public function reset(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        Accounts::reset($this->input($request));
        return $this->json($request, $response, ['password_reset' => true]);
    }

    public function paymentReturn(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $response->getBody()->write('<!doctype html><html lang="en"><meta charset="utf-8"><title>Return to application</title><p>Return to the application to check your payment. This page does not confirm payment.</p><a href="/user/invoice">View panel invoices</a></html>');
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }
}
