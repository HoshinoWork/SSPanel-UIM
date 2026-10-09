<?php

declare(strict_types=1);

namespace App\Controllers\Client\V1;

use App\Models\Config;
use App\Models\Link;
use App\Models\User;
use App\Services\Client\Accounts;
use App\Services\Client\ApiException;
use App\Services\Client\Challenges;
use App\Services\Client\Input;
use App\Services\Client\Sessions;
use App\Services\DB;
use App\Services\Filter;
use App\Services\Subscribe;
use App\Utils\Hash;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class UserController extends Controller
{
    public function profile(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $this->user($request);
        return $this->json($request, $response, ['id' => $user->id, 'name' => $user->user_name, 'email' => $user->email,
            'uuid' => $user->uuid, 'class' => $user->class, 'class_expires_at' => gmdate('c', strtotime($user->class_expire)),
            'node_speedlimit_mbps' => $user->node_speedlimit, 'node_iplimit' => $user->node_iplimit,
            'locale' => $user->locale, 'balance' => Input::storedMoney($user->getRawOriginal('money')), 'currency' => 'CNY',
        ]);
    }

    public function update(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $input = $this->input($request);
        if (array_diff(array_keys($input), ['name']) !== []) {
            throw new ApiException(422, 'invalid_fields', 'Only name can be changed with this endpoint');
        }
        $owner = $this->user($request);
        $user = DB::connection()->transaction(static function () use ($owner, $input): User {
            $current = Sessions::current($owner);
            $current->user_name = Input::name($input);
            $current->save();
            return $current;
        });
        return $this->profile($request->withAttribute('client.user', $user), $response);
    }

    public function password(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $this->user($request);
        $input = $this->input($request);
        if (! Hash::checkPassword($user->pass, Input::existingPassword($input, 'old_password'))) {
            throw new ApiException(422, 'invalid_password', 'Old password is incorrect');
        }
        $new = Input::password($input);
        DB::connection()->transaction(static function () use ($user, $new): void {
            $current = User::where('id', $user->id)->lockForUpdate()->first();
            if (! Sessions::validUser($current, hash('sha256', $user->pass))) {
                throw new ApiException(401, 'unauthorized', 'Account credentials changed');
            }
            $user->pass = Hash::passwordHash($new);
            $user->save();
            Sessions::revoke($user->id);
            if (Config::obtain('enable_forced_replacement')) {
                $user->removeLink();
            }
        });
        return $this->json($request, $response, ['password_changed' => true, 'reauthentication_required' => true]);
    }

    public function emailRequest(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $this->user($request);
        $input = $this->input($request);
        if (! ($_ENV['enable_change_email'] ?? false) || ! Hash::checkPassword($user->pass, Input::existingPassword($input))) {
            throw new ApiException(403, 'email_change_forbidden', 'Email change unavailable');
        }
        return $this->json($request, $response, Accounts::sendVerification($input, $this->ip($request), 'email_change', $user), 202);
    }

    public function email(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $this->user($request);
        $input = $this->input($request);
        if (! ($_ENV['enable_change_email'] ?? false)) {
            throw new ApiException(403, 'email_change_forbidden', 'Email change unavailable');
        }
        Challenges::consume(Input::text($input, 'verification_id'), Input::text($input, 'code'), 'email_change', static function (array $proof) use ($user): array {
            if (! $proof['eligible'] || (int) $proof['user_id'] !== (int) $user->id || ! Filter::checkEmailFilter($proof['email'])) {
                throw new ApiException(422, 'invalid_challenge', 'Email verification invalid');
            }
            $current = Sessions::current($user);
            if (User::where('email', $proof['email'])->exists()) {
                throw new ApiException(409, 'email_unavailable', 'Email unavailable');
            }
            $current->email = $proof['email'];
            $current->save();
            Sessions::revoke($current->id);
            return ['email_changed' => true];
        });
        return $this->json($request, $response, ['email_changed' => true, 'reauthentication_required' => true]);
    }

    public function sessions(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $rows = DB::table('client_sessions')->where('user_id', $this->user($request)->id)->where('revoked', false)
            ->where('expires_at', '>', time())->get(['id', 'device_name', 'created_at', 'expires_at']);
        return $this->json($request, $response, $rows->map(static fn ($row): array => ['id' => $row->id,
            'device_name' => $row->device_name, 'created_at' => gmdate('c', $row->created_at), 'expires_at' => gmdate('c', $row->expires_at),
        ])->all());
    }

    public function revoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        Sessions::revoke($this->user($request)->id, $args['id'] ?? null);
        return $this->json($request, $response, ['revoked' => true]);
    }

    public function traffic(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $this->user($request);
        return $this->json($request, $response, ['upload_bytes' => (int) $user->u, 'download_bytes' => (int) $user->d,
            'total_bytes' => (int) $user->transfer_enable, 'remaining_bytes' => max(0, $user->transfer_enable - $user->u - $user->d),
            'today_bytes' => (int) $user->transfer_today, 'keep_connect' => (bool) ($_ENV['keep_connect'] ?? false),
        ]);
    }

    public function subscription(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $this->user($request);
        $data = DB::connection()->transaction(static function () use ($user): array {
            $current = Sessions::current($user);
            $base = Subscribe::getUniversalSubLink($current);
            $formats = ['json', 'clash', 'singbox', 'v2rayjson', 'v2ray', 'hysteria2', 'sip002', 'sip008', 'ss', 'trojan'];
            $links = [];
            foreach ($formats as $format) {
                $links[$format] = $base . '/' . $format;
            }
            return ['enabled' => (bool) ($_ENV['Subscribe'] ?? false), 'formats' => $formats, 'urls' => $links];
        });
        return $this->json($request, $response, $data);
    }

    public function content(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $format = $request->getQueryParams()['format'] ?? 'clash';
        if (! ($_ENV['Subscribe'] ?? false) || ! in_array($format, ['json', 'clash', 'singbox', 'v2rayjson', 'v2ray', 'hysteria2', 'sip002', 'sip008', 'ss', 'trojan'], true)) {
            throw new ApiException(422, 'subscription_unavailable', 'Unsupported subscription format or subscriptions disabled');
        }
        $user = $this->user($request);
        $response->getBody()->write(Subscribe::getContent($user, $format));
        return $response->withHeader('Content-Type', match ($format) {
            'clash' => 'application/yaml', 'json', 'singbox', 'v2rayjson', 'sip008' => 'application/json', default => 'text/plain',
        })->withHeader('Subscription-Userinfo', 'upload=' . $user->u . '; download=' . $user->d . '; total=' . $user->transfer_enable . '; expire=' . strtotime($user->class_expire));
    }

    public function rotate(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $this->user($request);
        DB::connection()->transaction(static function () use ($user): void {
            $current = Sessions::current($user);
            Link::where('userid', $user->id)->delete();
            Subscribe::getUniversalSubLink($current);
        });
        return $this->subscription($request, $response);
    }

    public function nodes(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $nodes = Subscribe::getUserNodes($this->user($request));
        return $this->json($request, $response, $nodes->map(static fn ($node): array => ['id' => $node->id,
            'name' => $node->name, 'sort' => $node->sort, 'class' => $node->node_class,
        ])->all());
    }
}
