<?php

declare(strict_types=1);

namespace App\Services\Client;

use voku\helper\AntiXSS;

final class Input
{
    public static function name(array $input): string
    {
        // Preserve the website's name sanitization: names are also rendered
        // by existing templates, not exclusively by native clients.
        return self::text(['name' => (new AntiXSS())->xss_clean(self::text($input, 'name', 64))], 'name', 64);
    }

    public static function text(array $input, string $key, int $max = 255): string
    {
        $value = $input[$key] ?? null;
        if (! is_string($value) || trim($value) === '' || strlen($value) > $max) {
            throw new ApiException(422, 'invalid_input', 'Invalid ' . $key);
        }
        return trim($value);
    }

    public static function email(array $input): string
    {
        $email = strtolower(self::text($input, 'email'));
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new ApiException(422, 'invalid_email', 'Invalid email');
        }
        return $email;
    }

    public static function password(array $input, string $key = 'password'): string
    {
        $value = $input[$key] ?? null;
        if (! is_string($value) || strlen($value) < 8 || strlen($value) > 1024) {
            throw new ApiException(422, 'invalid_password', 'Password must contain 8 to 1024 bytes');
        }
        return $value;
    }

    public static function existingPassword(array $input, string $key = 'password'): string
    {
        $value = $input[$key] ?? null;
        if (! is_string($value) || $value === '' || strlen($value) > 1024) {
            throw new ApiException(422, 'invalid_password', 'Invalid password');
        }
        return $value;
    }

    public static function money(mixed $value): string
    {
        if (! is_string($value) || ! preg_match('/^(?:0|[1-9][0-9]{0,7})(?:\.[0-9]{1,2})?$/D', $value)) {
            throw new ApiException(422, 'invalid_amount', 'Amount must be a non-negative decimal string');
        }
        return bcadd($value, '0', 2);
    }

    public static function storedMoney(mixed $value): string
    {
        $amount = is_string($value) ? $value : number_format((float) $value, 2, '.', '');
        // Existing wallets/prices use DECIMAL(12,2); the smaller per-request
        // top-up limit must not reject an accumulated legitimate balance.
        if (! preg_match('/^(?:0|[1-9][0-9]{0,9})(?:\.[0-9]{1,2})?$/D', $amount)) {
            throw new ApiException(422, 'invalid_amount', 'Invalid stored amount');
        }
        return bcadd($amount, '0', 2);
    }

    public static function token(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }
}
