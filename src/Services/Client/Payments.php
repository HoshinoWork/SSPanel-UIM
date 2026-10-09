<?php

declare(strict_types=1);

namespace App\Services\Client;

use Alipay\OpenAPISDK\Api\AlipayTradeApi;
use Alipay\OpenAPISDK\Model\AlipayTradePrecreateModel;
use Alipay\OpenAPISDK\Util\AlipayConfigUtil;
use Alipay\OpenAPISDK\Util\AlipayLogger;
use Alipay\OpenAPISDK\Util\Model\AlipayConfig;
use App\Models\Config;
use App\Models\Invoice;
use App\Models\Paylist;
use App\Models\User;
use App\Services\DB;
use App\Services\Gateway\AlipayF2F;
use App\Services\Gateway\Epay;
use App\Services\Gateway\Epay\EpaySubmit;
use App\Services\Gateway\Epay\EpayTool;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use LogicException;

final class Payments
{
    public static function methods(): array
    {
        $methods = [['id' => 'balance', 'name' => 'Account balance', 'type' => 'balance']];
        foreach (['epay' => Epay::class, 'f2f' => AlipayF2F::class] as $id => $class) {
            if ($class::_enable()) {
                $available = $id === 'epay' ? self::epayMethods() : ['alipay'];
                if ($available !== []) {
                    $methods[] = ['id' => $id, 'name' => $class::_readableName(), 'type' => $id === 'epay' ? 'redirect' : 'qr_code', 'methods' => $available];
                }
            }
        }
        return $methods;
    }

    public static function prepare(User $user, int $invoiceId, array $input): array
    {
        if (DB::connection()->transactionLevel() === 0) {
            throw new LogicException('Payment preparation requires a transaction');
        }
        $current = User::where('id', $user->id)->lockForUpdate()->first();
        if (! Sessions::validUser($current, hash('sha256', $user->pass))) {
            throw new ApiException(403, 'account_unavailable', 'Account unavailable');
        }
        $gateway = $input['gateway'] ?? '';
        $method = $input['method'] ?? 'alipay';
        $class = match ($gateway) {
            'epay' => Epay::class, 'f2f' => AlipayF2F::class, default => null
        };
        if ($class === null || ! $class::_enable()) {
            throw new ApiException(422, 'gateway_unavailable', 'Payment gateway unavailable');
        }
        if (! in_array($method, $gateway === 'epay' ? self::epayMethods() : ['alipay'], true)) {
            throw new ApiException(422, 'invalid_payment_method', 'Unsupported payment method');
        }
        $invoice = Invoice::where('id', $invoiceId)->where('user_id', $user->id)->lockForUpdate()->first();
        if ($invoice === null) {
            throw new ApiException(404, 'invoice_not_found', 'Invoice not found');
        }
        if (! in_array($invoice->status, ['unpaid', 'partially_paid'], true) || bccomp(Input::storedMoney($invoice->price), '0', 2) <= 0) {
            throw new ApiException(409, 'invoice_not_payable', 'Invoice is not payable');
        }
        $existing = Paylist::where('invoice_id', $invoiceId)->where('status', 0)->first();
        if ($existing !== null) {
            $metadata = DB::table('client_payments')->where('paylist_id', $existing->id)->first();
            if ($metadata === null || $metadata->gateway !== $gateway || $metadata->method !== $method) {
                throw new ApiException(409, 'payment_in_progress', 'Another payment attempt exists');
            }
            return ['payment_id' => $existing->id];
        }
        $payment = new Paylist();
        $payment->fill(['userid' => $user->id, 'invoice_id' => $invoiceId, 'total' => Input::storedMoney($invoice->price),
            'tradeno' => bin2hex(random_bytes(16)), 'gateway' => $class::_readableName() . ($gateway === 'epay' ? ' ' . $method : ''),
            'status' => 0, 'datetime' => time(),
        ]);
        $payment->save();
        DB::table('client_payments')->insert(['paylist_id' => $payment->id, 'gateway' => $gateway, 'method' => $method,
            'state' => 'pending', 'descriptor' => null, 'expires_at' => time() + 900,
        ]);
        return ['payment_id' => $payment->id];
    }

    public static function start(User $user, int $id, string $ip, ?ClientInterface $client = null, ?string $panelReturnUrl = null): array
    {
        $payment = Paylist::where('id', $id)->where('userid', $user->id)->first();
        if ($payment === null) {
            throw new ApiException(404, 'payment_not_found', 'Payment not found');
        }
        if ((int) $payment->status === 1) {
            return self::view($user, $id);
        }
        $claimed = DB::connection()->transaction(static function () use ($user, $id): int {
            Sessions::current($user);
            $current = Paylist::where('id', $id)->where('userid', $user->id)->lockForUpdate()->first();
            if ($current === null || (int) $current->status !== 0) {
                return 0;
            }
            return DB::table('client_payments')->where('paylist_id', $id)->where('state', 'pending')
                ->where('expires_at', '>', time())->update(['state' => 'creating']);
        });
        if ($claimed === 1) {
            $meta = DB::table('client_payments')->where('paylist_id', $id)->first();
            try {
                $descriptor = $meta->gateway === 'epay' ? self::epay($payment, $meta->method, $ip, $client, $panelReturnUrl) : self::f2f($payment, $client);
                DB::table('client_payments')->where('paylist_id', $id)->update(['state' => 'ready', 'descriptor' => json_encode($descriptor, JSON_THROW_ON_ERROR)]);
            } catch (\Throwable $error) {
                // Do not automatically issue a new charge after an ambiguous
                // timeout. The existing trade number remains authoritative.
                DB::table('client_payments')->where('paylist_id', $id)->update(['state' => 'indeterminate']);
                error_log('Client payment initiation failed: ' . $error::class);
            }
        }
        return self::view($user, $id);
    }

    public static function view(User $user, int $id): array
    {
        $payment = Paylist::where('id', $id)->where('userid', $user->id)->first();
        $meta = DB::table('client_payments')->where('paylist_id', $id)->first();
        if ($payment === null || $meta === null) {
            throw new ApiException(404, 'payment_not_found', 'Payment not found');
        }
        return ['payment_id' => $id, 'invoice_id' => $payment->invoice_id, 'currency' => 'CNY',
            'amount' => Input::storedMoney($payment->total), 'gateway' => $meta->gateway,
            'status' => (int) $payment->status === 1 ? 'paid' : ($meta->expires_at <= time() ? 'expired' : $meta->state),
            'expires_at' => gmdate('c', $meta->expires_at),
            'action' => $meta->descriptor === null || (int) $payment->status === 1 || $meta->expires_at <= time()
                ? null : json_decode($meta->descriptor, true, 512, JSON_THROW_ON_ERROR),
        ];
    }

    private static function epay(Paylist $payment, string $method, string $ip, ?ClientInterface $client, ?string $panelReturnUrl): array
    {
        $config = ['apiurl' => Config::obtain('epay_url'), 'partner' => Config::obtain('epay_pid'),
            'key' => Config::obtain('epay_key'), 'sign_type' => strtoupper(Config::obtain('epay_sign_type')),
            'input_charset' => 'utf-8', 'transport' => 'https',
        ];
        $base = rtrim($_ENV['baseUrl'], '/');
        if ($panelReturnUrl === null && parse_url($config['apiurl'], PHP_URL_SCHEME) !== 'https') {
            throw new ApiException(422, 'gateway_configuration', 'The gateway API URL must use HTTPS');
        }
        $data = ['pid' => trim($config['partner']), 'type' => $method, 'out_trade_no' => $payment->tradeno,
            'notify_url' => $base . '/payment/notify/epay', 'return_url' => $panelReturnUrl ?? $base . '/client/v1/payment-return',
            'name' => $payment->tradeno, 'money' => Input::storedMoney($payment->total), 'sitename' => $_ENV['appName'], 'clientip' => $ip,
        ];
        $data['sign'] = (new EpaySubmit($config))->buildRequestMysign(EpayTool::argSort($data));
        $data['sign_type'] = $config['sign_type'];
        $schemes = $panelReturnUrl === null ? ['https'] : ['https', 'http'];
        $client ??= new Client(['timeout' => 10, 'connect_timeout' => 3]);
        $result = json_decode((string) $client->request(
            'POST',
            rtrim($config['apiurl'], '/') . '/mapi.php',
            ['form_params' => $data, 'allow_redirects' => ['protocols' => $schemes]]
        )->getBody(), true, 512, JSON_THROW_ON_ERROR);
        if ((int) ($result['code'] ?? 0) !== 1 || ! is_string($result['payurl'] ?? null) || ! in_array(parse_url($result['payurl'], PHP_URL_SCHEME), $schemes, true)) {
            throw new ApiException(502, 'gateway_error', 'Gateway did not return a valid HTTPS payment URL');
        }
        return ['type' => 'redirect', 'url' => $result['payurl']];
    }

    private static function epayMethods(): array
    {
        $methods = [];
        foreach (['alipay' => 'epay_alipay', 'wxpay' => 'epay_wechat', 'qqpay' => 'epay_qq', 'usdt' => 'epay_usdt', 'epusdt' => 'epay_usdt'] as $method => $setting) {
            if (Config::obtain($setting)) {
                $methods[] = $method;
            }
        }
        return $methods;
    }

    private static function f2f(Paylist $payment, ?ClientInterface $client): array
    {
        // SDK defaults to echoing request/response bodies; this would leak
        // payment metadata and corrupt the JSON API response.
        AlipayLogger::setNeedEnableLogger(false);
        $config = new AlipayConfig();
        $config->setAppid(Config::obtain('f2f_pay_app_id'));
        $config->setPrivateKey(Config::obtain('f2f_pay_private_key'));
        $config->setAlipayPublicKey(Config::obtain('f2f_pay_public_key'));
        $api = new AlipayTradeApi($client ?? new Client(['timeout' => 10, 'connect_timeout' => 3]));
        $api->setAlipayConfigUtil(new AlipayConfigUtil($config));
        $request = new AlipayTradePrecreateModel();
        $request->setOutTradeNo($payment->tradeno);
        $request->setTotalAmount(Input::storedMoney($payment->total));
        $request->setSubject($payment->tradeno);
        $request->setNotifyUrl(Config::obtain('f2f_pay_notify_url') ?: rtrim($_ENV['baseUrl'], '/') . '/payment/notify/f2f');
        $request->setTimeoutExpress('15m');
        $result = $api->precreate($request);
        if (! $result->getQrCode()) {
            throw new ApiException(502, 'gateway_error', 'Gateway did not return a QR code');
        }
        return ['type' => 'qr_code', 'content' => $result->getQrCode()];
    }
}
