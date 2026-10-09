<?php

declare(strict_types=1);

namespace App\Controllers\Client\V1;

use App\Models\Ann;
use App\Models\Config;
use App\Models\Docs;
use App\Services\Client\Accounts;
use App\Services\Client\ApiException;
use App\Services\Client\Idempotency;
use App\Services\Client\Sessions;
use App\Services\Reward;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class CommunityController extends Controller
{
    public function announcements(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->list(
            $request,
            $response,
            Ann::where('status', '>', 0)->orderBy('status', 'desc')->orderBy('sort')->orderBy('date', 'desc'),
            ['id', 'status', 'date', 'content']
        );
    }

    public function docs(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $this->docsPermission($request);
        return $this->list($request, $response, Docs::where('status', 1)->orderBy('sort')->orderBy('id', 'desc'), ['id', 'date', 'title']);
    }

    public function doc(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $this->docsPermission($request);
        $doc = Docs::where('status', 1)->where('id', $args['id'])->first(['id', 'date', 'title', 'content']);
        if ($doc === null) {
            throw new ApiException(404, 'document_not_found', 'Document not found');
        }
        return $this->json($request, $response, $doc->toArray());
    }

    public function checkin(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $this->user($request);
        $input = $this->input($request);
        $data = Idempotency::run($user->id, $request->getHeaderLine('Idempotency-Key'), 'checkin', $input, static function () use ($user, $input): array {
            Accounts::captcha($input, 'enable_checkin_captcha');
            $current = Sessions::current($user);
            if (! Config::obtain('enable_checkin') || ! $current->isAbleToCheckin()) {
                throw new ApiException(409, 'checkin_unavailable', 'Check-in unavailable or already completed today');
            }
            $traffic = Reward::issueCheckinReward($user->id);
            if (! $traffic) {
                throw new ApiException(409, 'checkin_unavailable', 'Check-in reward unavailable');
            }
            return ['reward_mb' => $traffic, 'checked_in_at' => gmdate('c')];
        });
        return $this->json($request, $response, $data, 201);
    }

    private function docsPermission(ServerRequestInterface $request): void
    {
        if (! Config::obtain('display_docs') || Config::obtain('display_docs_only_for_paid_user') && (int) $this->user($request)->class === 0) {
            throw new ApiException(403, 'documents_unavailable', 'Documents unavailable for this account');
        }
    }

    private function list(ServerRequestInterface $request, ResponseInterface $response, mixed $query, array $fields): ResponseInterface
    {
        $params = $request->getQueryParams();
        $page = filter_var($params['page'] ?? 1, FILTER_VALIDATE_INT);
        $limit = filter_var($params['per_page'] ?? 20, FILTER_VALIDATE_INT);
        if ($page === false || $page < 1 || $page > 100000 || $limit === false || $limit < 1 || $limit > 100) {
            throw new ApiException(422, 'invalid_pagination', 'Invalid page or per_page');
        }
        $total = $query->count();
        return $this->json($request, $response, ['items' => $query->offset(($page - 1) * $limit)->limit($limit)->get($fields)->toArray(),
            'page' => $page, 'per_page' => $limit, 'total' => $total,
        ]);
    }
}
