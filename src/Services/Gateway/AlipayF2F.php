<?php

declare(strict_types=1);

namespace App\Services\Gateway;

use Alipay\OpenAPISDK\Api\AlipayTradeApi;
use Alipay\OpenAPISDK\ApiException;
use Alipay\OpenAPISDK\Util\AlipayConfigUtil;
use Alipay\OpenAPISDK\Util\AlipayLogger;
use Alipay\OpenAPISDK\Util\AlipaySignature;
use Alipay\OpenAPISDK\Util\Model\AlipayConfig;
use App\Models\Config;
use App\Services\Auth;
use App\Services\Billing\Settlement;
use App\Services\View;
use Exception;
use GuzzleHttp\Client;
use Psr\Http\Message\ResponseInterface;
use Slim\Http\Response;
use Slim\Http\ServerRequest;
use voku\helper\AntiXSS;

final class AlipayF2F extends Base
{
    private AlipayConfig $alipayConfig;

    public function __construct()
    {
        $this->antiXss = new AntiXSS();
        AlipayLogger::setNeedEnableLogger(false);
        $this->alipayConfig = new AlipayConfig();
        $this->alipayConfig->setAppid(Config::obtain('f2f_pay_app_id'));
        $this->alipayConfig->setPrivateKey(Config::obtain('f2f_pay_private_key'));
        $this->alipayConfig->setAlipayPublicKey(Config::obtain('f2f_pay_public_key'));
    }

    public static function _name(): string
    {
        return 'f2f';
    }

    public static function _readableName(): string
    {
        return 'Alipay F2F';
    }

    public static function _enable(): bool
    {
        return self::getActiveGateway('f2f');
    }

    /**
     * @throws Exception
     */
    public static function getPurchaseHTML(): string
    {
        return View::getSmarty()->fetch('gateway/f2f.tpl');
    }

    /**
     * @throws ApiException
     */
    public function purchase(ServerRequest $request, Response $response, array $args): ResponseInterface
    {
        $user = Auth::getUser();
        try {
            $prepared = \App\Services\DB::connection()->transaction(static function () use ($user, $request): array {
                \App\Models\User::where('id', $user->id)->lockForUpdate()->first();
                return \App\Services\Client\Payments::prepare(
                    $user,
                    (int) $request->getParam('invoice_id'),
                    ['gateway' => 'f2f', 'method' => 'alipay']
                );
            });
            $result = \App\Services\Client\Payments::start($user, $prepared['payment_id'], (string) ($request->getServerParams()['REMOTE_ADDR'] ?? ''));
            if ($result['status'] !== 'ready') {
                return $response->withJson(['ret' => 0, 'msg' => '支付请求处理中或待核实，请勿重复付款']);
            }
            return $response->withJson(['ret' => 1, 'qrcode' => $result['action']['content']]);
        } catch (\App\Services\Client\ApiException $error) {
            return $response->withJson(['ret' => 0, 'msg' => $error->getMessage()]);
        }
    }

    /**
     * @throws ApiException
     */
    public function notify($request, $response, $args): ResponseInterface
    {
        $params = (array) $request->getParsedBody();
        foreach ($params as $value) {
            if (! is_string($value)) {
                return $response->write('failed');
            }
        }
        try {
            if (($params['app_id'] ?? '') !== Config::obtain('f2f_pay_app_id') ||
                ($params['sign_type'] ?? '') !== 'RSA2' ||
                ! AlipaySignature::rsaCheckV1($params, Config::obtain('f2f_pay_public_key'), 'RSA2') ||
                ! in_array($params['trade_status'] ?? '', ['TRADE_SUCCESS', 'TRADE_FINISHED'], true)) {
                return $response->write('failed');
            }
            Settlement::complete((string) ($params['out_trade_no'] ?? ''), (string) ($params['total_amount'] ?? ''), self::_readableName());
            return $response->write('success');
        } catch (\Throwable) {
            return $response->write('failed');
        }
    }

    private function createApi(): AlipayTradeApi
    {
        $alipayTradeApi = new AlipayTradeApi(new Client(['timeout' => 10, 'connect_timeout' => 3]));
        $alipayConfigUtil = new AlipayConfigUtil($this->alipayConfig);
        $alipayTradeApi->setAlipayConfigUtil($alipayConfigUtil);

        return $alipayTradeApi;
    }
}
