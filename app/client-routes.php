<?php

declare(strict_types=1);

use App\Controllers\Client\V1\AuthController;
use App\Controllers\Client\V1\BrowserController;
use App\Controllers\Client\V1\CommunityController;
use App\Controllers\Client\V1\StoreController;
use App\Controllers\Client\V1\UserController;
use App\Middleware\ClientApi;
use Slim\Routing\RouteCollectorProxy;

return static function (Slim\App $app): void {
    $app->group('/client/v1', static function (RouteCollectorProxy $routes): void {
        foreach (['/config' => 'config', '/captcha' => 'captcha', '/payment-return' => 'paymentReturn'] as $path => $method) {
            $routes->get($path, AuthController::class . ':' . $method);
        }
        foreach (['/auth/sessions' => 'login', '/auth/refresh' => 'refresh', '/auth/registrations' => 'register',
            '/auth/email-verifications' => 'email', '/auth/password-reset-requests' => 'resetRequest',
            '/auth/password-resets' => 'reset',
        ] as $path => $method) {
            $routes->post($path, AuthController::class . ':' . $method);
        }
        $routes->post('/mfa/challenges/{id:[A-Za-z0-9_-]{43}}/verification', AuthController::class . ':mfa');
        $routes->get('/mfa/challenges/{id:[A-Za-z0-9_-]{43}}/browser', BrowserController::class . ':page');
        $routes->post('/mfa/challenges/{id:[A-Za-z0-9_-]{43}}/browser', BrowserController::class . ':verify');
        $routes->get('/captcha/browser', BrowserController::class . ':captcha');
        $routes->group('', static function (RouteCollectorProxy $authenticated): void {
            $authenticated->get('/announcements', CommunityController::class . ':announcements');
            $authenticated->get('/docs', CommunityController::class . ':docs');
            $authenticated->get('/docs/{id:[0-9]+}', CommunityController::class . ':doc');
            $authenticated->post('/me/checkins', CommunityController::class . ':checkin');
            foreach (['/me' => 'profile', '/me/sessions' => 'sessions', '/me/traffic' => 'traffic',
                '/me/subscription' => 'subscription', '/me/subscription/content' => 'content', '/nodes' => 'nodes',
            ] as $path => $method) {
                $authenticated->get($path, UserController::class . ':' . $method);
            }
            $authenticated->patch('/me', UserController::class . ':update');
            $authenticated->put('/me/password', UserController::class . ':password');
            $authenticated->post('/me/email-verifications', UserController::class . ':emailRequest');
            $authenticated->put('/me/email', UserController::class . ':email');
            $authenticated->delete('/me/sessions', UserController::class . ':revoke');
            $authenticated->delete('/me/sessions/{id:[A-Za-z0-9_-]{43}}', UserController::class . ':revoke');
            $authenticated->post('/me/subscription/rotations', UserController::class . ':rotate');
            foreach (['/products' => 'products', '/products/{id:[0-9]+}' => 'product', '/orders' => 'orders',
                '/orders/{id:[0-9]+}' => 'orderDetail', '/invoices' => 'invoices', '/invoices/{id:[0-9]+}' => 'invoice',
                '/payment-methods' => 'methods', '/payments/{id:[0-9]+}' => 'payment',
                '/me/wallet' => 'wallet', '/me/wallet/transactions' => 'transactions',
            ] as $path => $method) {
                $authenticated->get($path, StoreController::class . ':' . $method);
            }
            foreach (['/order-quotes' => 'quote', '/orders' => 'order', '/topups' => 'topup',
                '/gift-card-redemptions' => 'gift', '/invoices/{id:[0-9]+}/payments' => 'pay',
            ] as $path => $method) {
                $authenticated->post($path, StoreController::class . ':' . $method);
            }
            $authenticated->delete('/orders/{id:[0-9]+}', StoreController::class . ':cancel');
        })->add(new ClientApi(true));
    })->add(new ClientApi());
};
