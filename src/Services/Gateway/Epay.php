<?php

declare(strict_types=1);

/**
 * Copyright (c) 2019.
 * Author:Alone88
 * Github:https://github.com/anhao
 */

namespace App\Services\Gateway;

use App\Models\Config;
use App\Services\Auth;
use App\Services\Billing\Settlement;
use App\Services\Gateway\Epay\EpayNotify;
use App\Services\View;
use Exception;
use Psr\Http\Message\ResponseInterface;
use Slim\Http\Response;
use Slim\Http\ServerRequest;
use voku\helper\AntiXSS;
use function trim;

final class Epay extends Base
{
    protected array $epay = [];

    public function __construct()
    {
        $this->antiXss = new AntiXSS();
        $this->epay['apiurl'] = Config::obtain('epay_url');//易支付API地址
        $this->epay['partner'] = Config::obtain('epay_pid');//易支付商户pid
        $this->epay['key'] = Config::obtain('epay_key');//易支付商户Key
        $this->epay['sign_type'] = strtoupper(Config::obtain('epay_sign_type')); //签名方式
        $this->epay['input_charset'] = strtolower('utf-8');//字符编码
        $this->epay['transport'] = 'https';//协议 http 或者https
    }

    public static function _name(): string
    {
        return 'epay';
    }

    public static function _enable(): bool
    {
        return self::getActiveGateway('epay');
    }

    public static function _readableName(): string
    {
        return 'EPay';
    }

    public function purchase(ServerRequest $request, Response $response, array $args): ResponseInterface
    {
        $user = Auth::getUser();
        try {
            $prepared = \App\Services\DB::connection()->transaction(static function () use ($user, $request): array {
                \App\Models\User::where('id', $user->id)->lockForUpdate()->first();
                return \App\Services\Client\Payments::prepare(
                    $user,
                    (int) $request->getParam('invoice_id'),
                    ['gateway' => 'epay', 'method' => (string) $request->getParam('type')]
                );
            });
            // Website returns to its owned invoice even when the independent
            // client API is disabled; never accept an arbitrary return URL.
            $returnUrl = rtrim($_ENV['baseUrl'], '/') . '/user/invoice/' . (int) $request->getParam('invoice_id') . '/view';
            $result = \App\Services\Client\Payments::start($user, $prepared['payment_id'], (string) ($request->getServerParams()['REMOTE_ADDR'] ?? ''), null, $returnUrl);
            if ($result['status'] !== 'ready') {
                return $response->withJson(['ret' => 0, 'msg' => '支付请求处理中或待核实，请勿重复付款']);
            }
            return $response->withHeader('HX-Redirect', $result['action']['url']);
        } catch (\App\Services\Client\ApiException $error) {
            return $response->withJson(['ret' => 0, 'msg' => $error->getMessage()]);
        }
    }

    public function notify($request, $response, $args): ResponseInterface
    {
        $epayNotify = new EpayNotify($this->epay);
        $params = $request->getQueryParams();
        foreach ($params as $value) {
            if (! is_string($value)) {
                return $response->write('failed');
            }
        }
        if (($params['trade_status'] ?? '') === 'TRADE_SUCCESS' && is_string($params['sign'] ?? null) &&
            (string) ($params['pid'] ?? '') === trim((string) $this->epay['partner']) &&
            $epayNotify->getSignVeryfy($params, $params['sign'])) {
            try {
                Settlement::complete((string) ($params['out_trade_no'] ?? ''), (string) ($params['money'] ?? ''), self::_readableName());
                return $response->write('success');
            } catch (\Throwable) {
                return $response->write('failed');
            }
        }

        return $response->write('failed');
    }

    /**
     * @throws Exception
     */
    public static function getPurchaseHTML(): string
    {
        return View::getSmarty()->fetch('gateway/epay.tpl');
    }
}
