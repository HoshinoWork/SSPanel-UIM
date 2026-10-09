<?php

declare(strict_types=1);

namespace App\Services\Client;

use App\Models\Config;
use App\Models\InviteCode;
use App\Models\MFADevice;
use App\Models\User;
use App\Services\Captcha;
use App\Services\DB;
use App\Services\Filter;
use App\Services\Mail;
use App\Services\MFA\TOTP;
use App\Services\Reward;
use App\Utils\Hash;
use App\Utils\Tools;
use Ramsey\Uuid\Uuid;

final class Accounts
{
    public static function captcha(array $input, string $setting): void
    {
        if (! Config::obtain($setting)) {
            return;
        }
        $proof = $input['captcha'] ?? [];
        $provider = Config::obtain('captcha_provider');
        if (! is_array($proof) || ! in_array($provider, ['turnstile', 'geetest', 'hcaptcha', 'recaptcha_enterprise'], true)) {
            throw new ApiException(422, 'captcha_failed', 'Invalid captcha proof');
        }
        if ($provider === 'geetest') {
            if (! is_array($proof['geetest'] ?? null)) {
                throw new ApiException(422, 'captcha_failed', 'Invalid captcha proof');
            }
            foreach (['lot_number', 'captcha_output', 'pass_token', 'gen_time'] as $field) {
                Input::text($proof['geetest'], $field, 8192);
            }
        } else {
            Input::text($proof, $provider, 8192);
        }
        if (! Captcha::verify($proof)) {
            throw new ApiException(422, 'captcha_failed', 'Captcha verification failed');
        }
    }

    public static function login(array $input, string $ip): array
    {
        $email = Input::email($input);
        Limits::check('login_ip', $ip, 20);
        Limits::check('login_email', $email, 10);
        self::captcha($input, 'enable_login_captcha');
        $password = $input['password'] ?? '';
        $user = User::where('email', $email)->first();
        if (! is_string($password) || strlen($password) > 1024 || $user === null || ! Hash::checkPassword($user->pass, $password) || ! Sessions::validUser($user, hash('sha256', $user->pass))) {
            throw new ApiException(401, 'invalid_credentials', 'Invalid email or password');
        }
        $device = Input::text($input, 'device_name', 120);
        // Include passkeys as well as the legacy FIDO/TOTP device types.
        $methods = MFADevice::where('userid', $user->id)->pluck('type')->unique()->values()->all();
        if ($methods !== []) {
            $secret = Input::token();
            $browser = Input::token();
            $id = Challenges::create('mfa', ['user_id' => $user->id, 'password_hash' => hash('sha256', $user->pass),
                'device_name' => $device, 'methods' => $methods, 'browser_hash' => Challenges::digest($browser), 'approved' => false,
            ], $secret);
            return ['mfa_required' => true, 'challenge_id' => $id, 'challenge_secret' => $secret,
                'methods' => $methods, 'expires_in' => 300,
                'verification_url' => rtrim($_ENV['baseUrl'], '/') . '/client/v1/mfa/challenges/' . $id . '/browser?ticket=' . $browser,
            ];
        }
        return self::finishLogin($user, $device);
    }

    public static function finishLogin(User $user, string $device): array
    {
        return DB::connection()->transaction(static function () use ($user, $device): array {
            $current = Sessions::current($user);
            $current->last_login_time = time();
            $current->save();
            return Sessions::issue($current, $device);
        });
    }

    public static function mfa(string $id, array $input): array
    {
        Limits::check('mfa_challenge', $id, 5, 300);
        $row = DB::table('client_challenges')->where('id', $id)->first();
        if ($row === null || $row->purpose !== 'mfa' || $row->expires_at <= time() || $row->consumed ||
            ! hash_equals($row->secret_hash, Challenges::digest(Input::text($input, 'challenge_secret')))) {
            throw new ApiException(422, 'invalid_challenge', 'MFA challenge invalid');
        }
        $initial = json_decode($row->payload, true, 512, JSON_THROW_ON_ERROR);
        if (($input['method'] ?? '') === 'totp') {
            Limits::check('totp_user', (string) $initial['user_id'], 5, 300);
        }
        return Challenges::consume($id, $input['challenge_secret'], 'mfa', static function (array $payload) use ($input): array {
            $user = User::where('id', $payload['user_id'])->lockForUpdate()->first();
            if (! Sessions::validUser($user, $payload['password_hash'])) {
                throw new ApiException(401, 'invalid_credentials', 'Account unavailable');
            }
            self::verifyMfa($user, $payload, $input);
            return self::finishLogin($user, $payload['device_name']);
        });
    }

    public static function sendVerification(array $input, string $ip, string $purpose, ?User $current = null): array
    {
        $email = Input::email($input);
        if (! in_array($purpose, ['registration', 'password_reset', 'email_change'], true)) {
            throw new ApiException(422, 'invalid_purpose', 'Invalid verification purpose');
        }
        if ($purpose === 'email_change' && $current === null) {
            throw new ApiException(401, 'unauthorized', 'Authentication required');
        }
        Limits::check('email_ip', $ip, 5, 600);
        Limits::check('email_address', $email, 3, 600);
        self::captcha($input, $purpose === 'password_reset' ? 'enable_reset_password_captcha' : 'enable_reg_captcha');
        $user = User::where('email', $email)->first();
        $eligible = $purpose === 'password_reset' ? $user !== null : $user === null;
        if ($purpose === 'registration') {
            $eligible = $eligible && Config::obtain('reg_mode') !== 'close' && Filter::checkEmailFilter($email);
        }
        $code = (string) random_int(100000, 999999);
        $ttl = max(60, min(900, (int) (Config::obtain('email_verify_code_ttl') ?: 300)));
        $id = Challenges::create($purpose, ['eligible' => $eligible,
            'user_id' => $current?->id ?? $user?->id ?? 0,
        ], $code, $email, $ttl);
        if ($eligible) {
            try {
                Mail::send(
                    $email,
                    $_ENV['appName'] . ' - Verification',
                    'verify_code.tpl',
                    ['code' => $code, 'expire' => date('Y-m-d H:i:s', time() + $ttl)]
                );
            } catch (\Throwable $error) {
                // Same public response for unknown accounts and delivery failures.
                error_log('Client verification delivery failed: ' . $error::class);
            }
        }
        return ['verification_id' => $id, 'expires_in' => $ttl,
            'message' => 'If this request is eligible, a verification email will be sent',
        ];
    }

    public static function register(array $input, string $ip): array
    {
        Limits::check('registration_ip', $ip, 5, 600);
        if (Config::obtain('reg_mode') === 'close') {
            throw new ApiException(403, 'registration_closed', 'Registration is closed');
        }
        self::captcha($input, 'enable_reg_captcha');
        $email = Input::email($input);
        $password = Input::password($input);
        $name = Input::name($input);
        if (($input['tos_accepted'] ?? false) !== true || ! Filter::checkEmailFilter($email)) {
            throw new ApiException(422, 'registration_invalid', 'Terms acceptance and an eligible email are required');
        }
        $create = static function () use ($input, $email, $password, $name, $ip): User {
            if (User::where('email', $email)->exists()) {
                throw new ApiException(409, 'registration_unavailable', 'Unable to register with these details');
            }
            $inviteCode = $input['invite_code'] ?? '';
            if (! is_string($inviteCode) || strlen($inviteCode) > 255) {
                throw new ApiException(422, 'invalid_invite', 'Invalid invitation');
            }
            $invite = $inviteCode === '' ? null : InviteCode::where('code', $inviteCode)->first();
            if ((Config::obtain('reg_mode') === 'invite' || $inviteCode !== '') && ($invite === null || User::find($invite->user_id) === null)) {
                throw new ApiException(422, 'invalid_invite', 'A valid invitation is required');
            }
            $config = Config::getClass('reg');
            $groups = array_filter(explode(',', (string) Config::obtain('random_group')));
            $user = new User();
            $user->fill(['user_name' => $name, 'email' => $email, 'remark' => '', 'pass' => Hash::passwordHash($password),
                'passwd' => Tools::genRandomChar(16), 'uuid' => (string) Uuid::uuid4(), 'api_token' => Tools::genRandomChar(32),
                'port' => Tools::getSsPort(), 'u' => 0, 'd' => 0, 'method' => $config['reg_method'] ?? 'aes-128-gcm',
                'im_type' => 0, 'im_value' => '', 'transfer_enable' => Tools::gbToB($config['reg_traffic'] ?? 0),
                'auto_reset_day' => Config::obtain('free_user_reset_day') ?: 0,
                'auto_reset_bandwidth' => Config::obtain('free_user_reset_bandwidth') ?: 0,
                'daily_mail_enable' => $config['reg_daily_report'] ?? 0, 'money' => 0, 'ref_by' => $invite?->user_id ?? 0,
                'class' => $config['reg_class'] ?? 0,
                'class_expire' => date('Y-m-d H:i:s', time() + (int) ($config['reg_class_time'] ?? 0) * 86400),
                'node_iplimit' => $config['reg_ip_limit'] ?? 0, 'node_speedlimit' => $config['reg_speed_limit'] ?? 0,
                'reg_date' => date('Y-m-d H:i:s'), 'reg_ip' => $ip, 'theme' => $_ENV['theme'], 'locale' => $_ENV['locale'],
                'node_group' => $groups === [] ? 0 : (int) $groups[array_rand($groups)], 'last_login_time' => 0,
            ]);
            $user->save();
            if ($user->ref_by !== 0) {
                User::where('id', $user->ref_by)->lockForUpdate()->first();
                Reward::issueRegReward($user->id, $user->ref_by);
            }
            return $user;
        };
        if (Config::obtain('reg_email_verify')) {
            $result = Challenges::consume(Input::text($input, 'verification_id'), Input::text($input, 'code'), 'registration', static function (array $proof) use ($email, $create): array {
                if ($proof['email'] !== $email || ! $proof['eligible']) {
                    throw new ApiException(422, 'invalid_challenge', 'Invalid registration verification');
                }
                return ['user' => $create()];
            });
            $user = $result['user'];
        } else {
            $user = DB::connection()->transaction($create);
        }
        return ['id' => $user->id, 'email' => $user->email];
    }

    public static function reset(array $input): void
    {
        $password = Input::password($input);
        Challenges::consume(Input::text($input, 'verification_id'), Input::text($input, 'code'), 'password_reset', static function (array $proof) use ($password): array {
            if (! $proof['eligible']) {
                throw new ApiException(422, 'invalid_challenge', 'Invalid password verification');
            }
            $user = User::where('id', $proof['user_id'])->where('email', $proof['email'])->lockForUpdate()->first();
            if ($user === null) {
                throw new ApiException(422, 'invalid_challenge', 'Invalid password verification');
            }
            $user->pass = Hash::passwordHash($password);
            $user->save();
            Sessions::revoke($user->id);
            if (Config::obtain('enable_forced_replacement')) {
                $user->removeLink();
            }
            return ['password_reset' => true];
        });
    }

    private static function verifyMfa(User $user, array $payload, array $input): void
    {
        $method = $input['method'] ?? '';
        if ($method === 'totp' && in_array('totp', $payload['methods'], true)) {
            $code = Input::text($input, 'code', 8);
            if (! preg_match('/^[0-9]{6}$/D', $code)) {
                throw new ApiException(422, 'mfa_failed', 'MFA verification failed');
            }
            $result = TOTP::assertHandle($user, $code);
            if (($result['ret'] ?? 0) !== 1) {
                throw new ApiException(422, 'mfa_failed', 'MFA verification failed');
            }
            $claim = DB::table('client_rate_limits')->insertOrIgnore(['id' => Challenges::digest('totp:' . $user->id . ':' . $code),
                'attempts' => 1, 'expires_at' => time() + 150,
            ]);
            if ($claim !== 1) {
                throw new ApiException(422, 'mfa_failed', 'TOTP code already used');
            }
        } elseif ($method !== 'browser' || ! $payload['approved']) {
            throw new ApiException(409, 'mfa_pending', 'MFA verification has not completed');
        }
    }
}
