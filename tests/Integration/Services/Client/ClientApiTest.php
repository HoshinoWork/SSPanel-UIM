<?php

declare(strict_types=1);

namespace Tests\Integration\Services\Client;

use App\Models\User;
use App\Services\Billing\Settlement;
use App\Services\Client\Accounts;
use App\Services\Client\ApiException;
use App\Services\Client\BrowserMfa;
use App\Services\Client\Challenges;
use App\Services\Client\Commerce;
use App\Services\Client\Idempotency;
use App\Services\Client\Input;
use App\Services\Client\Payments;
use App\Services\Client\Sessions;
use App\Services\Cron;
use App\Services\DB;
use App\Services\Gateway\AlipayF2F;
use App\Services\Gateway\Epay;
use App\Services\Gateway\Epay\EpaySubmit;
use App\Services\Gateway\Epay\EpayTool;
use App\Utils\Hash;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\ServerRequest;
use Illuminate\Database\Schema\Blueprint;
use PHPUnit\Framework\TestCase;
use Slim\Factory\AppFactory;
use Slim\Http\Factory\DecoratedResponseFactory;

final class ClientApiTest extends TestCase
{
    private array $environment;

    private ?string $mysqlDatabase = null;

    protected function setUp(): void
    {
        $this->environment = $_ENV;
        $_ENV = array_replace($_ENV, ['key' => 'test-only-site-secret', 'client_api_enabled' => true,
            'baseUrl' => 'https://panel.example.test', 'appName' => 'Test panel', 'enable_password_security' => true,
            'pwdMethod' => 'argon2id', 'salt' => 'test-salt', 'keep_connect' => false,
        ]);
        $db = new DB();
        if (getenv('CLIENT_TEST_MYSQL') === '1') {
            // Never reuse the panel's configured database: each test owns a
            // fresh random schema, removed only after its child workers exit.
            $config = ['driver' => 'mariadb', 'host' => getenv('CLIENT_TEST_MYSQL_HOST') ?: '127.0.0.1',
                'port' => getenv('CLIENT_TEST_MYSQL_PORT') ?: 3306, 'unix_socket' => getenv('CLIENT_TEST_MYSQL_SOCKET') ?: '',
                'username' => getenv('CLIENT_TEST_MYSQL_USER') ?: 'root', 'password' => getenv('CLIENT_TEST_MYSQL_PASSWORD') ?: '',
                'database' => 'mysql', 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci',
            ];
            $db->addConnection($config);
            $this->mysqlDatabase = 'sspanel_client_test_' . bin2hex(random_bytes(8));
            $db->getConnection()->statement('CREATE DATABASE `' . $this->mysqlDatabase . '`');
            $db->getDatabaseManager()->purge('default');
            $config['database'] = $this->mysqlDatabase;
            $db->addConnection($config);
        } else {
            $db->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        }
        $db->setAsGlobal();
        $db->bootEloquent();
        $schema = $db->schema();
        $schema->create('user', static function (Blueprint $t): void {
            $t->increments('id');
            $t->string('email')->unique();
            $t->string('pass');
            $t->string('user_name')->default('Test');
            $t->string('uuid')->default('123e4567-e89b-42d3-a456-426614174000');
            $t->string('money')->default('20.00');
            foreach (['is_banned', 'is_shadow_banned', 'class', 'node_group', 'ref_by', 'last_login_time', 'node_speedlimit', 'node_iplimit'] as $field) {
                $t->bigInteger($field)->default(0);
            }
            $t->string('class_expire')->default('2099-01-01 00:00:00');
            $t->string('locale')->default('en');
            foreach (['u', 'd', 'transfer_enable', 'transfer_today', 'is_admin', 'port', 'auto_reset_day',
                'auto_reset_bandwidth', 'daily_mail_enable', 'im_type', 'last_check_in_time',
            ] as $field) {
                $t->bigInteger($field)->default(0);
            }
            foreach (['remark', 'passwd', 'api_token', 'method', 'im_value', 'reg_date', 'reg_ip', 'theme'] as $field) {
                $t->string($field)->default('');
            }
        });
        $schema->create('config', static function (Blueprint $t): void {
            $t->increments('id');
            $t->string('item');
            $t->text('value');
            $t->string('type')->default('string');
            $t->string('class')->default('');
        });
        $schema->create('mfa_devices', static function (Blueprint $t): void {
            $t->increments('id');
            $t->integer('userid');
            $t->string('type');
            $t->text('body');
            $t->string('rawid')->default('');
            $t->string('used_at')->nullable();
        });
        $schema->create('product', static function (Blueprint $t): void {
            $t->increments('id');
            $t->string('name');
            $t->string('type');
            $t->string('price');
            $t->text('content');
            $t->text('limit');
            $t->integer('status')->default(1);
            $t->integer('stock')->default(2);
            $t->integer('sale_count')->default(0);
        });
        $schema->create('user_coupon', static function (Blueprint $t): void {
            $t->increments('id');
            $t->string('code');
            $t->text('content');
            $t->text('limit');
            $t->integer('expire_time')->default(0);
            $t->integer('use_count')->default(0);
        });
        $schema->create('order', static function (Blueprint $t): void {
            $t->increments('id');
            $t->integer('user_id');
            $t->integer('product_id');
            foreach (['product_type', 'product_name', 'product_content', 'coupon', 'price', 'status'] as $field) {
                $t->text($field);
            }
            $t->integer('create_time');
            $t->integer('update_time');
        });
        $schema->create('invoice', static function (Blueprint $t): void {
            $t->increments('id');
            $t->integer('user_id');
            $t->integer('order_id');
            foreach (['content', 'price', 'status', 'type'] as $field) {
                $t->text($field);
            }
            foreach (['create_time', 'update_time', 'pay_time'] as $field) {
                $t->integer($field);
            }
        });
        $schema->create('paylist', static function (Blueprint $t): void {
            $t->increments('id');
            $t->integer('userid');
            $t->integer('invoice_id');
            $t->string('total');
            $t->string('tradeno')->unique();
            $t->string('gateway');
            $t->integer('status')->default(0);
            $t->integer('datetime')->default(0);
        });
        $schema->create('user_money_log', static function (Blueprint $t): void {
            $t->increments('id');
            $t->integer('user_id');
            foreach (['before', 'after', 'amount', 'remark'] as $field) {
                $t->string($field);
            }
            $t->integer('create_time');
        });
        $schema->create('gift_card', static function (Blueprint $t): void {
            $t->increments('id');
            $t->string('card')->unique();
            $t->string('balance');
            foreach (['status', 'use_time', 'use_user'] as $field) {
                $t->integer($field)->default(0);
            }
        });
        $schema->create('link', static function (Blueprint $t): void {
            $t->increments('id');
            $t->integer('userid');
            $t->string('token');
        });
        $schema->create('node', static function (Blueprint $t): void {
            $t->increments('id');
            $t->integer('type')->default(1);
            $t->integer('sort')->default(11);
            $t->string('name');
            $t->string('server');
            $t->text('custom_config');
            foreach (['node_class', 'node_group', 'node_bandwidth_limit', 'node_bandwidth'] as $field) {
                $t->integer($field)->default(0);
            }
        });
        $schema->create('announcement', static function (Blueprint $t): void {
            $t->increments('id');
            $t->integer('status');
            $t->integer('sort')->default(0);
            $t->string('date');
            $t->text('content');
        });
        $schema->create('docs', static function (Blueprint $t): void {
            $t->increments('id');
            $t->integer('status');
            $t->integer('sort')->default(0);
            $t->string('date');
            $t->text('content');
            $t->string('title');
        });
        (require dirname(__DIR__, 4) . '/db/migrations/2026100600-add_client_api.php')->up();
        User::create(['email' => 'user@example.test', 'pass' => Hash::passwordHash('test-password')]);
        User::create(['email' => 'other@example.test', 'pass' => Hash::passwordHash('test-password')]);
        DB::table('product')->insert(['name' => 'Test product', 'type' => 'bandwidth', 'price' => '10.99',
            'content' => '{"bandwidth":10}', 'limit' => '{}',
        ]);
    }

    protected function tearDown(): void
    {
        if ($this->mysqlDatabase !== null) {
            DB::connection()->statement('DROP DATABASE `' . $this->mysqlDatabase . '`');
            $this->mysqlDatabase = null;
        }
        $_ENV = $this->environment;
    }

    public function testLoginRefreshAndReplayRevocation(): void
    {
        [$status, $response] = $this->call('POST', '/auth/sessions', ['email' => 'user@example.test', 'password' => 'test-password', 'device_name' => 'Flutter']);
        self::assertSame(201, $status);
        $session = $response['data'];
        self::assertSame(200, $this->call('GET', '/me', [], $session['access_token'])[0]);
        [$status, $result] = $this->call('POST', '/auth/refresh', ['refresh_token' => $session['refresh_token']]);
        self::assertSame(200, $status);
        self::assertSame(401, $this->call('GET', '/me', [], $session['access_token'])[0]);
        self::assertSame(401, $this->call('POST', '/auth/refresh', ['refresh_token' => $session['refresh_token']])[0]);
        self::assertSame(401, $this->call('GET', '/me', [], $result['data']['access_token'])[0]);
    }

    public function testBearerRequiredAndPasswordChangeInvalidatesSession(): void
    {
        self::assertSame(401, $this->call('GET', '/me')[0]);
        $session = Sessions::issue(User::find(1), 'test');
        User::where('id', 1)->update(['pass' => Hash::passwordHash('changed-password')]);
        self::assertSame(401, $this->call('GET', '/me', [], $session['access_token'])[0]);
    }

    public function testMfaCannotBeSkippedOrApprovedWithBrowserFlag(): void
    {
        DB::table('mfa_devices')->insert(['userid' => 1, 'type' => 'passkey', 'body' => '{}']);
        [$status, $result] = $this->call('POST', '/auth/sessions', ['email' => 'user@example.test', 'password' => 'test-password', 'device_name' => 'Flutter']);
        self::assertSame(202, $status);
        self::assertArrayNotHasKey('access_token', $result['data']);
        self::assertSame(0, DB::table('client_sessions')->count());
        self::assertSame(409, $this->call(
            'POST',
            '/mfa/challenges/' . $result['data']['challenge_id'] . '/verification',
            ['challenge_secret' => $result['data']['challenge_secret'], 'method' => 'browser', 'approved' => true]
        )[0]);
    }

    public function testIdempotentOrderAndOwnership(): void
    {
        $session = Sessions::issue(User::find(1), 'test');
        $request = ['product_id' => 1];
        $first = $this->call('POST', '/orders', $request, $session['access_token'], 'order-key-001');
        self::assertSame(201, $first[0]);
        $second = $this->call('POST', '/orders', $request, $session['access_token'], 'order-key-001');
        self::assertSame($first[1]['data'], $second[1]['data']);
        self::assertSame(1, DB::table('order')->count());
        self::assertSame(1, DB::table('product')->value('stock'));
        self::assertSame(409, $this->call('POST', '/orders', ['product_id' => 2], $session['access_token'], 'order-key-001')[0]);
        $other = Sessions::issue(User::find(2), 'other');
        self::assertSame(404, $this->call('GET', '/invoices/' . $first[1]['data']['invoice_id'], [], $other['access_token'])[0]);
    }

    public function testBalancePaymentDoesNotDoubleDebit(): void
    {
        $user = User::find(1);
        $order = Idempotency::run(1, 'create-order-01', 'order', [], static fn (): array => Commerce::create($user, ['product_id' => 1]));
        $pay = static fn (): array => Commerce::balance($user, $order['invoice_id']);
        $first = Idempotency::run(1, 'pay-invoice-01', 'payment', [], $pay);
        self::assertSame('paid_balance', $first['status']);
        self::assertSame('0.00', $first['remaining_amount']);
        self::assertSame($first, Idempotency::run(1, 'pay-invoice-01', 'payment', [], $pay));
        self::assertSame('9.01', User::find(1)->getRawOriginal('money'));
        self::assertSame(1, DB::table('user_money_log')->count());
    }

    public function testSettlementUsesExactCentsAndIsIdempotent(): void
    {
        $order = DB::connection()->transaction(static fn (): array => Commerce::create(User::find(1), ['product_id' => 1]));
        DB::table('paylist')->insert(['userid' => 1, 'invoice_id' => $order['invoice_id'], 'total' => '10.99', 'tradeno' => 'test-trade', 'gateway' => 'EPay alipay']);
        try {
            Settlement::complete('test-trade', '10.01', 'EPay');
            self::fail('Wrong cents accepted');
        } catch (\RuntimeException) {
            self::assertSame(0, DB::table('paylist')->value('status'));
        }
        Settlement::complete('test-trade', '10.99', 'EPay');
        Settlement::complete('test-trade', '10.99', 'EPay');
        self::assertSame('paid_gateway', DB::table('invoice')->value('status'));
        self::assertSame('20.00', User::find(1)->getRawOriginal('money'));
    }

    public function testTopupCreditsOnceAndGiftCardRedeemsOnce(): void
    {
        $user = User::find(1);
        $order = DB::connection()->transaction(static fn (): array => Commerce::create($user, ['amount' => '5.01'], true));
        DB::table('paylist')->insert(['userid' => 1, 'invoice_id' => $order['invoice_id'], 'total' => '5.01', 'tradeno' => 'topup', 'gateway' => 'Alipay F2F']);
        Settlement::complete('topup', '5.01', 'Alipay F2F');
        DB::table('order')->where('id', $order['order_id'])->update(['status' => 'pending_activation']);
        Settlement::activateTopup($order['order_id']);
        Settlement::activateTopup($order['order_id']);
        self::assertSame('25.01', User::find(1)->getRawOriginal('money'));
        DB::table('gift_card')->insert(['card' => 'gift-code', 'balance' => '1.99']);
        DB::connection()->transaction(static fn (): array => Commerce::gift($user, ['code' => 'gift-code']));
        self::assertSame('27.00', User::find(1)->getRawOriginal('money'));
        $this->expectException(ApiException::class);
        DB::connection()->transaction(static fn (): array => Commerce::gift(User::find(2), ['code' => 'gift-code']));
    }

    public function testWrongVerificationAttemptsPersistAndCodeIsOneTime(): void
    {
        $id = Challenges::create('registration', [], '123456');
        for ($i = 0; $i < 5; $i++) {
            try {
                Challenges::consume($id, '000000', 'registration');
            } catch (ApiException $error) {
                self::assertSame('invalid_challenge', $error->errorCode);
            }
        }
        self::assertSame(5, DB::table('client_challenges')->where('id', $id)->value('attempts'));
        $this->expectException(ApiException::class);
        Challenges::consume($id, '123456', 'registration');
    }

    public function testMoneyRejectsFloatsAndAcceptsDecimalString(): void
    {
        self::assertSame('1.20', Input::money('1.2'));
        $this->expectException(ApiException::class);
        Input::money(1.2);
    }

    public function testRegistrationAndPasswordResetUsePanelUser(): void
    {
        $_ENV['theme'] = 'tabler';
        $_ENV['locale'] = 'en';
        $this->configure(['reg_mode' => 'open']);
        [$status, $result] = $this->call('POST', '/auth/registrations', ['name' => 'New user', 'email' => 'new@example.test',
            'password' => 'new-password', 'tos_accepted' => true,
        ]);
        self::assertSame(201, $status);
        $user = User::find($result['data']['id']);
        self::assertTrue(Hash::checkPassword($user->pass, 'new-password'));
        $session = Sessions::issue($user, 'test');
        $proof = Challenges::create('password_reset', ['eligible' => true, 'user_id' => $user->id], '123456', $user->email);
        self::assertSame(200, $this->call('POST', '/auth/password-resets', ['verification_id' => $proof, 'code' => '123456', 'password' => 'changed-password'])[0]);
        self::assertSame(401, $this->call('GET', '/me', [], $session['access_token'])[0]);
        self::assertSame(422, $this->call('POST', '/auth/password-resets', ['verification_id' => $proof, 'code' => '123456', 'password' => 'changed-password'])[0]);
    }

    public function testTotpCompletesMfaAndRejectsReuse(): void
    {
        $secret = 'JBSWY3DPEHPK3PXP';
        DB::table('mfa_devices')->insert(['userid' => 1, 'type' => 'totp', 'body' => json_encode(['token' => $secret])]);
        $input = ['email' => 'user@example.test', 'password' => 'test-password', 'device_name' => 'Flutter'];
        $challenge = Accounts::login($input, '127.0.0.1');
        $code = (new \Vectorface\GoogleAuthenticator())->getCode($secret);
        $tokens = Accounts::mfa($challenge['challenge_id'], ['method' => 'totp', 'code' => $code, 'challenge_secret' => $challenge['challenge_secret']]);
        self::assertNotEmpty($tokens['access_token']);
        $second = Accounts::login($input, '127.0.0.1');
        $this->expectException(ApiException::class);
        Accounts::mfa($second['challenge_id'], ['method' => 'totp', 'code' => $code, 'challenge_secret' => $second['challenge_secret']]);
    }

    public function testWebauthnProofIsBoundToUserAndChallenge(): void
    {
        require_once dirname(__DIR__, 3) . '/Fixtures/WebAuthnV524Fixture.php';
        $fixture = \Tests\Fixtures\WebAuthnV524Fixture::class;
        $_ENV['baseUrl'] = 'https://localhost:8443';
        User::where('id', 1)->update(['uuid' => 'foo']);
        DB::table('mfa_devices')->insert(['userid' => 1, 'type' => 'passkey', 'rawid' => $fixture::LEGACY_CREDENTIAL_ID,
            'body' => $fixture::LEGACY_CREDENTIAL_SOURCE_JSON,
        ]);
        $ticket = Input::token();
        $secret = Input::token();
        $id = Challenges::create('mfa', ['user_id' => 1, 'password_hash' => hash('sha256', User::find(1)->pass),
            'device_name' => 'test', 'methods' => ['passkey'], 'browser_hash' => Challenges::digest($ticket), 'approved' => false,
            'options' => $fixture::ASSERTION_REQUEST_OPTIONS_JSON,
        ], $secret);
        BrowserMfa::verify($id, $ticket, json_decode($fixture::ASSERTION_RESPONSE_JSON, true));
        $session = Accounts::mfa($id, ['method' => 'browser', 'challenge_secret' => $secret]);
        self::assertNotEmpty($session['access_token']);
        $this->expectException(ApiException::class);
        BrowserMfa::verify($id, $ticket, json_decode($fixture::ASSERTION_RESPONSE_JSON, true));
    }

    public function testSubscriptionUsesExistingGeneratorWithoutPrivateConfig(): void
    {
        $_ENV['Subscribe'] = true;
        $_ENV['subUrl'] = $_ENV['baseUrl'];
        $_ENV['sub_token_len'] = 32;
        $this->configure(['enable_v2_sub' => '1']);
        DB::table('node')->insert(['name' => 'VLESS test', 'server' => 'node.example.test',
            'custom_config' => '{"enable_vless":1,"network":"tcp","offset_port_user":8443}',
        ]);
        $tokens = Sessions::issue(User::find(1), 'test');
        self::assertSame(200, $this->call('GET', '/me/subscription', [], $tokens['access_token'])[0]);
        $app = AppFactory::create(new HttpFactory());
        (require dirname(__DIR__, 4) . '/app/client-routes.php')($app);
        $request = (new ServerRequest('GET', $_ENV['baseUrl'] . '/client/v1/me/subscription/content'))
            ->withQueryParams(['format' => 'v2ray'])->withHeader('Authorization', 'Bearer ' . $tokens['access_token']);
        $response = $app->handle($request);
        self::assertSame(200, $response->getStatusCode());
        self::assertStringStartsWith('vless://123e4567-e89b-42d3-a456-426614174000@node.example.test:8443?', (string) $response->getBody());
        self::assertStringNotContainsString('private_key', (string) $response->getBody());
    }

    public function testEpayInitiationIsOnceAndSignedCallbackRejectsTampering(): void
    {
        $this->configure(['payment_gateway' => '["epay"]', 'epay_url' => 'https://gateway.example.test/',
            'epay_alipay' => '1', 'epay_pid' => '123', 'epay_key' => 'test-merchant-key', 'epay_sign_type' => 'md5',
        ]);
        $user = User::find(1);
        $order = DB::connection()->transaction(static fn (): array => Commerce::create($user, ['product_id' => 1]));
        $prepared = DB::connection()->transaction(static fn (): array => Payments::prepare($user, $order['invoice_id'], ['gateway' => 'epay']));
        $mock = new MockHandler([new Response(200, [], '{"code":1,"payurl":"https://gateway.example.test/pay/1"}')]);
        $client = new Client(['handler' => HandlerStack::create($mock)]);
        self::assertSame('ready', Payments::start($user, $prepared['payment_id'], '127.0.0.1', $client)['status']);
        self::assertSame('ready', Payments::start($user, $prepared['payment_id'], '127.0.0.1', $client)['status']);
        self::assertCount(0, $mock);
        $params = ['pid' => '123', 'trade_status' => 'TRADE_SUCCESS', 'out_trade_no' => DB::table('paylist')->value('tradeno'), 'money' => '10.99'];
        $params['sign'] = (new EpaySubmit(['apiurl' => 'https://gateway.example.test/', 'key' => 'test-merchant-key', 'sign_type' => 'MD5']))->buildRequestMysign(EpayTool::argSort($params));
        $params['sign_type'] = 'MD5';
        $factory = new DecoratedResponseFactory(new HttpFactory(), new HttpFactory());
        $gateway = new Epay();
        $bad = $params;
        $bad['money'] = '10.01';
        self::assertSame('failed', (string) $gateway->notify((new ServerRequest('GET', 'https://panel.example.test'))->withQueryParams($bad), $factory->createResponse(), [])->getBody());
        self::assertSame('success', (string) $gateway->notify((new ServerRequest('GET', 'https://panel.example.test'))->withQueryParams($params), $factory->createResponse(), [])->getBody());
        self::assertSame('success', (string) $gateway->notify((new ServerRequest('GET', 'https://panel.example.test'))->withQueryParams($params), $factory->createResponse(), [])->getBody());
    }

    public function testAlipayCallbackVerifiesRsaAmountAndAppId(): void
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $public = openssl_pkey_get_details($key)['key'];
        $public = str_replace(['-----BEGIN PUBLIC KEY-----', '-----END PUBLIC KEY-----', "\n"], '', $public);
        $this->configure(['f2f_pay_app_id' => 'test-app', 'f2f_pay_public_key' => $public, 'f2f_pay_private_key' => '']);
        $order = DB::connection()->transaction(static fn (): array => Commerce::create(User::find(1), ['product_id' => 1]));
        DB::table('paylist')->insert(['userid' => 1, 'invoice_id' => $order['invoice_id'], 'total' => '10.99', 'tradeno' => 'alipay-trade', 'gateway' => 'Alipay F2F']);
        $params = ['app_id' => 'test-app', 'trade_status' => 'TRADE_SUCCESS', 'out_trade_no' => 'alipay-trade', 'total_amount' => '10.99'];
        ksort($params);
        openssl_sign(urldecode(http_build_query($params)), $signature, $key, OPENSSL_ALGO_SHA256);
        $params['sign'] = base64_encode($signature);
        $params['sign_type'] = 'RSA2';
        $factory = new DecoratedResponseFactory(new HttpFactory(), new HttpFactory());
        $gateway = new AlipayF2F();
        $bad = $params;
        $bad['app_id'] = 'other-app';
        self::assertSame('failed', (string) $gateway->notify((new ServerRequest('POST', 'https://panel.example.test'))->withParsedBody($bad), $factory->createResponse(), [])->getBody());
        self::assertSame('success', (string) $gateway->notify((new ServerRequest('POST', 'https://panel.example.test'))->withParsedBody($params), $factory->createResponse(), [])->getBody());
        self::assertSame('success', (string) $gateway->notify((new ServerRequest('POST', 'https://panel.example.test'))->withParsedBody($params), $factory->createResponse(), [])->getBody());
    }

    public function testBandwidthOrderActivationIsRepeatSafe(): void
    {
        $user = User::find(1);
        $order = DB::connection()->transaction(static fn (): array => Commerce::create($user, ['product_id' => 1]));
        DB::connection()->transaction(static fn (): array => Commerce::balance($user, $order['invoice_id']));
        ob_start();
        try {
            Cron::processPendingOrder();
            Cron::processBandwidthOrderActivation();
            Cron::processBandwidthOrderActivation();
        } finally {
            ob_end_clean();
        }
        self::assertSame(10 * 1024 ** 3, User::find(1)->transfer_enable);
        self::assertSame('activated', DB::table('order')->value('status'));
    }

    public function testOpenapiContainsEveryRegisteredRoute(): void
    {
        $app = AppFactory::create(new HttpFactory());
        (require dirname(__DIR__, 4) . '/app/client-routes.php')($app);
        $spec = json_decode(file_get_contents(dirname(__DIR__, 4) . '/docs/client-v1.openapi.json'), true, 512, JSON_THROW_ON_ERROR);
        $operations = 0;
        foreach ($app->getRouteCollector()->getRoutes() as $route) {
            $path = preg_replace('/\{id:.*\}/', '{id}', substr($route->getPattern(), strlen('/client/v1')));
            foreach ($route->getMethods() as $method) {
                self::assertArrayHasKey(strtolower($method), $spec['paths'][$path] ?? [], $method . ' ' . $path);
                $operations++;
            }
        }
        self::assertSame(46, $operations);
    }

    public function testCouponRoundingAndPartialPaymentRules(): void
    {
        DB::table('user_coupon')->insert(['code' => 'one-percent', 'content' => '{"type":"percentage","value":1}',
            'limit' => '{"total_use_time":1}',
        ]);
        $user = User::find(1);
        $quote = Commerce::quote($user, ['product_id' => 1, 'coupon' => 'one-percent']);
        self::assertSame('10.88', $quote['total']);
        self::assertSame('0.11', $quote['discount']);
        $order = DB::connection()->transaction(static fn (): array => Commerce::create($user, ['product_id' => 1, 'coupon' => 'one-percent']));
        User::where('id', 1)->update(['money' => '3.01']);
        $result = DB::connection()->transaction(static fn (): array => Commerce::balance(User::find(1), $order['invoice_id']));
        self::assertSame('partially_paid', $result['status']);
        self::assertSame('7.87', $result['remaining_amount']);
        self::assertSame('0.00', User::find(1)->getRawOriginal('money'));
        $this->expectException(ApiException::class);
        Commerce::quote(User::find(2), ['product_id' => 1, 'coupon' => 'one-percent']);
    }

    public function testTimeoutDoesNotInitiateSecondPayment(): void
    {
        $this->configure(['payment_gateway' => '["epay"]', 'epay_url' => 'https://gateway.example.test/',
            'epay_alipay' => '1', 'epay_pid' => '123', 'epay_key' => 'test-merchant-key', 'epay_sign_type' => 'md5',
        ]);
        $user = User::find(1);
        $order = DB::connection()->transaction(static fn (): array => Commerce::create($user, ['product_id' => 1]));
        $prepared = DB::connection()->transaction(static fn (): array => Payments::prepare($user, $order['invoice_id'], ['gateway' => 'epay']));
        $mock = new MockHandler([new \GuzzleHttp\Exception\ConnectException('Test timeout', new \GuzzleHttp\Psr7\Request('POST', 'https://gateway.example.test'))]);
        $client = new Client(['handler' => HandlerStack::create($mock)]);
        self::assertSame('indeterminate', Payments::start($user, $prepared['payment_id'], '127.0.0.1', $client)['status']);
        self::assertSame('indeterminate', Payments::start($user, $prepared['payment_id'], '127.0.0.1', $client)['status']);
        self::assertCount(0, $mock);
        self::assertSame(1, DB::table('paylist')->count());
    }

    public function testCheckinAndPublishedContentPermissions(): void
    {
        $this->configure(['enable_checkin' => '1', 'checkin_min' => '10', 'checkin_max' => '10',
            'display_docs' => '1', 'display_docs_only_for_paid_user' => '1',
        ]);
        DB::table('config')->whereIn('item', ['checkin_min', 'checkin_max'])->update(['type' => 'int']);
        DB::table('announcement')->insert(['status' => 0, 'date' => '2026-10-06', 'content' => 'Draft']);
        DB::table('announcement')->insert(['status' => 2, 'date' => '2026-10-06', 'content' => 'Published']);
        $session = Sessions::issue(User::find(1), 'test');
        $first = $this->call('POST', '/me/checkins', [], $session['access_token'], 'checkin-key-001');
        self::assertSame(201, $first[0]);
        self::assertSame($first[1]['data'], $this->call('POST', '/me/checkins', [], $session['access_token'], 'checkin-key-001')[1]['data']);
        self::assertSame(409, $this->call('POST', '/me/checkins', [], $session['access_token'], 'checkin-key-002')[0]);
        self::assertSame(10 * 1024 ** 2, User::find(1)->transfer_enable);
        self::assertSame(1, $this->call('GET', '/announcements', [], $session['access_token'])[1]['data']['total']);
        self::assertSame(403, $this->call('GET', '/docs', [], $session['access_token'])[0]);
    }

    public function testDisabledApiAndJsonBodyValidation(): void
    {
        $_ENV['client_api_enabled'] = false;
        self::assertSame(404, $this->call('GET', '/config')[0]);
        $_ENV['client_api_enabled'] = true;
        $app = AppFactory::create(new HttpFactory());
        (require dirname(__DIR__, 4) . '/app/client-routes.php')($app);
        self::assertSame(400, $app->handle(new ServerRequest('GET', 'http://panel.example.test/client/v1/config'))->getStatusCode());
        self::assertSame(422, $app->handle(new ServerRequest(
            'POST',
            'https://panel.example.test/client/v1/auth/sessions',
            ['Content-Type' => 'application/json'],
            '[]'
        ))->getStatusCode());
        self::assertSame(415, $app->handle(new ServerRequest(
            'POST',
            'https://panel.example.test/client/v1/auth/sessions',
            ['Content-Type' => 'text/plain'],
            '{}'
        ))->getStatusCode());
        self::assertSame(413, $app->handle(new ServerRequest(
            'POST',
            'https://panel.example.test/client/v1/auth/sessions',
            ['Content-Type' => 'application/json'],
            str_repeat('x', 65537)
        ))->getStatusCode());
    }

    public function testF2fPrecreateUsesSdkAndDoesNotEchoPaymentDetails(): void
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $private);
        $public = openssl_pkey_get_details($key)['key'];
        $private = preg_replace('/-----[^-]+-----|\s/', '', $private);
        $public = preg_replace('/-----[^-]+-----|\s/', '', $public);
        $this->configure(['payment_gateway' => '["f2f"]', 'f2f_pay_app_id' => 'test-app',
            'f2f_pay_private_key' => $private, 'f2f_pay_public_key' => $public,
        ]);
        $user = User::find(1);
        $order = DB::connection()->transaction(static fn (): array => Commerce::create($user, ['product_id' => 1]));
        $prepared = DB::connection()->transaction(static fn (): array => Payments::prepare($user, $order['invoice_id'], ['gateway' => 'f2f']));
        $body = '{"qr_code":"https://qr.alipay.com/test-only"}';
        $timestamp = (string) time();
        $nonce = 'test-nonce';
        openssl_sign($timestamp . "\n" . $nonce . "\n" . $body . "\n", $signature, $key, OPENSSL_ALGO_SHA256);
        $mock = new MockHandler([new Response(200, ['Content-Type' => 'application/json', 'alipay-timestamp' => $timestamp,
            'alipay-nonce' => $nonce, 'alipay-signature' => base64_encode($signature),
        ], $body),
        ]);
        ob_start();
        try {
            $result = Payments::start($user, $prepared['payment_id'], '127.0.0.1', new Client(['handler' => HandlerStack::create($mock)]));
            $output = ob_get_contents();
        } finally {
            ob_end_clean();
        }
        self::assertSame('', $output);
        self::assertSame('ready', $result['status']);
        self::assertSame(['type' => 'qr_code', 'content' => 'https://qr.alipay.com/test-only'], $result['action']);
    }

    public function testRateLimitAndMigrationRollback(): void
    {
        \App\Services\Client\Limits::check('test-limit', 'test-identity', 2, 3600);
        \App\Services\Client\Limits::check('test-limit', 'test-identity', 2, 3600);
        try {
            \App\Services\Client\Limits::check('test-limit', 'test-identity', 2, 3600);
            self::fail('Missing rate limit');
        } catch (ApiException $error) {
            self::assertSame(429, $error->status);
        }
        self::assertSame(2026091300, (require dirname(__DIR__, 4) . '/db/migrations/2026100600-add_client_api.php')->down());
        self::assertFalse(DB::schema()->hasTable('client_sessions'));
        self::assertTrue(DB::schema()->hasTable('user'));
        self::assertTrue(DB::schema()->hasTable('invoice'));
    }

    public function testEveryProtectedRouteRejectsMissingAuthentication(): void
    {
        $app = AppFactory::create(new HttpFactory());
        (require dirname(__DIR__, 4) . '/app/client-routes.php')($app);
        foreach ($app->getRouteCollector()->getRoutes() as $route) {
            $path = substr($route->getPattern(), strlen('/client/v1'));
            if (preg_match('#^/(?:auth/|mfa/|captcha(?:/|$)|config$|payment-return$)#', $path)) {
                continue;
            }
            $path = preg_replace('/\{id:.*\}/', '1', $path);
            if (str_starts_with($path, '/me/sessions/')) {
                $path = '/me/sessions/' . str_repeat('a', 43);
            }
            foreach ($route->getMethods() as $method) {
                self::assertSame(401, $this->call($method, $path)[0], $method . ' ' . $path);
            }
        }
        self::assertSame(0, DB::table('order')->count());
        self::assertSame('20.00', User::find(1)->getRawOriginal('money'));
    }

    public function testProductionErrorMiddlewareDoesNotUseCookieAuthOrLeakDebugHtml(): void
    {
        $app = AppFactory::create(new HttpFactory());
        $app->add(new \App\Middleware\ErrorHandler());
        (require dirname(__DIR__, 4) . '/app/client-routes.php')($app);
        $previous = $GLOBALS['user'] ?? null;
        $GLOBALS['user'] = 'not-a-cookie-user';
        $_ENV['debug'] = true;
        try {
            foreach (['GET' => ['/config', 200], 'POST' => ['/config', 405], 'DELETE' => ['/missing/admin', 404]] as $method => [$path, $status]) {
                $response = $app->handle(new ServerRequest($method, 'https://panel.example.test/client/v1' . $path));
                self::assertSame($status, $response->getStatusCode());
                self::assertSame('application/json', $response->getHeaderLine('Content-Type'));
                self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
                self::assertStringNotContainsString('Slim Application Error', (string) $response->getBody());
                self::assertSame($response->getHeaderLine('X-Request-ID'), json_decode((string) $response->getBody(), true)['request_id']);
            }
            self::assertSame(3, (int) DB::table('client_rate_limits')->sum('attempts'));
        } finally {
            $GLOBALS['user'] = $previous;
        }
    }

    public function testFinancialServicesRejectCallsWithoutTransaction(): void
    {
        $this->expectException(\LogicException::class);
        Commerce::create(User::find(1), ['product_id' => 1]);
    }

    public function testExpiredBannedAndRevokedSessionsFailClosed(): void
    {
        $tokens = Sessions::issue(User::find(1), 'test');
        DB::table('client_sessions')->where('id', $tokens['session_id'])->update(['expires_at' => time() - 1]);
        self::assertSame(401, $this->call('GET', '/me', [], $tokens['access_token'])[0]);
        self::assertSame(401, $this->call('POST', '/auth/refresh', ['refresh_token' => $tokens['refresh_token']])[0]);
        $tokens = Sessions::issue(User::find(1), 'test');
        User::where('id', 1)->update(['is_shadow_banned' => 1]);
        self::assertSame(401, $this->call('GET', '/me', [], $tokens['access_token'])[0]);
        User::where('id', 1)->update(['is_shadow_banned' => 0]);
        Sessions::revoke(1);
        self::assertSame(401, $this->call('GET', '/me', [], $tokens['access_token'])[0]);
    }

    public function testCrossUserResourcesAndMassAssignmentAreRejected(): void
    {
        $this->configure(['payment_gateway' => '["epay"]', 'epay_alipay' => '1']);
        $user = User::find(1);
        $order = DB::connection()->transaction(static fn (): array => Commerce::create($user, ['product_id' => 1]));
        $payment = DB::connection()->transaction(static fn (): array => Payments::prepare($user, $order['invoice_id'], ['gateway' => 'epay']));
        $owner = Sessions::issue($user, 'owner');
        $other = Sessions::issue(User::find(2), 'other');
        foreach (['/orders/' . $order['order_id'], '/invoices/' . $order['invoice_id'], '/payments/' . $payment['payment_id']] as $path) {
            self::assertSame(404, $this->call('GET', $path, [], $other['access_token'])[0]);
        }
        self::assertSame(404, $this->call('DELETE', '/orders/' . $order['order_id'], [], $other['access_token'], 'cross-cancel-001')[0]);
        self::assertSame(404, $this->call('POST', '/invoices/' . $order['invoice_id'] . '/payments', ['gateway' => 'balance'], $other['access_token'], 'cross-pay-001')[0]);
        self::assertSame(422, $this->call('PATCH', '/me', ['name' => 'Changed', 'money' => '999.00', 'is_admin' => 1], $other['access_token'])[0]);
        self::assertSame(200, $this->call('DELETE', '/me/sessions/' . $owner['session_id'], [], $other['access_token'])[0]);
        self::assertSame(200, $this->call('GET', '/me', [], $owner['access_token'])[0]);
        self::assertSame('20.00', User::find(2)->getRawOriginal('money'));
        self::assertSame(0, DB::table('client_idempotency')->count());
    }

    public function testFinancialCreationRollsBackAllWritesOnFailure(): void
    {
        DB::schema()->drop('invoice');
        $session = Sessions::issue(User::find(1), 'test');
        self::assertSame(503, $this->call('POST', '/orders', ['product_id' => 1], $session['access_token'], 'rollback-order-01')[0]);
        self::assertSame(0, DB::table('order')->count());
        self::assertSame(2, DB::table('product')->value('stock'));
        self::assertSame(0, DB::table('client_idempotency')->count());
    }

    public function testBalanceAndGiftRollBackWhenLedgerWriteFails(): void
    {
        $user = User::find(1);
        $order = DB::connection()->transaction(static fn (): array => Commerce::create($user, ['product_id' => 1]));
        DB::table('gift_card')->insert(['card' => 'gift-rollback', 'balance' => '5.00']);
        DB::schema()->drop('user_money_log');
        $session = Sessions::issue($user, 'test');
        self::assertSame(503, $this->call('POST', '/invoices/' . $order['invoice_id'] . '/payments', ['gateway' => 'balance'], $session['access_token'], 'rollback-balance-01')[0]);
        self::assertSame(503, $this->call('POST', '/gift-card-redemptions', ['code' => 'gift-rollback'], $session['access_token'], 'rollback-gift-001')[0]);
        self::assertSame('20.00', User::find(1)->getRawOriginal('money'));
        self::assertSame('unpaid', DB::table('invoice')->value('status'));
        self::assertSame(0, DB::table('gift_card')->value('status'));
        self::assertSame(0, DB::table('client_idempotency')->count());
    }

    public function testValidChallengeAndBusinessWriteRollBackTogether(): void
    {
        $tokens = Sessions::issue(User::find(1), 'test');
        $id = Challenges::create('password_reset', ['eligible' => true, 'user_id' => 1], '123456', 'user@example.test');
        DB::schema()->drop('client_sessions');
        self::assertSame(503, $this->call('POST', '/auth/password-resets', ['verification_id' => $id, 'code' => '123456', 'password' => 'changed-password'])[0]);
        self::assertTrue(Hash::checkPassword(User::find(1)->pass, 'test-password'));
        self::assertSame(0, DB::table('client_challenges')->where('id', $id)->value('consumed'));
        self::assertSame(0, DB::table('client_challenges')->where('id', $id)->value('attempts'));
        self::assertNotEmpty($tokens['session_id']);
    }

    public function testEmailProofOwnershipPurposeAndReplay(): void
    {
        $_ENV['enable_change_email'] = true;
        $session = Sessions::issue(User::find(1), 'test');
        $other = Sessions::issue(User::find(2), 'test');
        $id = Challenges::create('email_change', ['eligible' => true, 'user_id' => 1], '123456', 'changed@example.test');
        $input = ['verification_id' => $id, 'code' => '123456'];
        self::assertSame(422, $this->call('PUT', '/me/email', $input, $other['access_token'])[0]);
        self::assertSame(0, DB::table('client_challenges')->where('id', $id)->value('consumed'));
        self::assertSame(422, $this->call('POST', '/auth/password-resets', $input + ['password' => 'changed-password'])[0]);
        self::assertSame(200, $this->call('PUT', '/me/email', $input, $session['access_token'])[0]);
        self::assertSame('changed@example.test', User::find(1)->email);
        self::assertSame(401, $this->call('GET', '/me', [], $session['access_token'])[0]);
        $fresh = Sessions::issue(User::find(1), 'test');
        self::assertSame(422, $this->call('PUT', '/me/email', $input, $fresh['access_token'])[0]);
    }

    public function testRegistrationVerificationRollsBackWithRejectedInvitation(): void
    {
        $_ENV['theme'] = 'tabler';
        $_ENV['locale'] = 'en';
        $this->configure(['reg_mode' => 'open', 'reg_email_verify' => '1']);
        DB::schema()->create('user_invite_code', static function (Blueprint $table): void {
            $table->increments('id');
            $table->string('code');
            $table->integer('user_id');
        });
        $id = Challenges::create('registration', ['eligible' => true], '123456', 'new@example.test');
        $input = ['name' => 'New', 'email' => 'new@example.test', 'password' => 'new-password', 'tos_accepted' => true,
            'verification_id' => $id, 'code' => '123456', 'invite_code' => 'missing',
        ];
        self::assertSame(422, $this->call('POST', '/auth/registrations', $input)[0]);
        self::assertSame(0, DB::table('client_challenges')->where('id', $id)->value('consumed'));
        unset($input['invite_code']);
        self::assertSame(201, $this->call('POST', '/auth/registrations', $input)[0]);
        self::assertSame(1, DB::table('client_challenges')->where('id', $id)->value('consumed'));
        self::assertSame(3, User::count());
    }

    public function testCaptchaConfigurationDoesNotExposeSecretsAndMissingProofFails(): void
    {
        $this->configure(['captcha_provider' => 'turnstile', 'turnstile_sitekey' => 'public-site-key',
            'turnstile_secret' => 'PRIVATE-CAPTCHA-SECRET', 'enable_login_captcha' => '1',
        ]);
        [$status, $result] = $this->call('GET', '/captcha');
        self::assertSame(200, $status);
        self::assertSame(['turnstile_sitekey' => 'public-site-key'], $result['data']['public_config']);
        self::assertStringNotContainsString('PRIVATE-CAPTCHA-SECRET', json_encode($result));
        self::assertSame(422, $this->call('POST', '/auth/sessions', ['email' => 'user@example.test', 'password' => 'test-password', 'device_name' => 'test'])[0]);
        self::assertSame(0, DB::table('client_sessions')->count());
    }

    public function testExpiredAndWrongPurposeChallengesCannotAuthorize(): void
    {
        $id = Challenges::create('registration', ['eligible' => true, 'user_id' => 1], '123456', 'user@example.test');
        self::assertSame(422, $this->call('POST', '/auth/password-resets', ['verification_id' => $id, 'code' => '123456', 'password' => 'changed-password'])[0]);
        DB::table('client_challenges')->where('id', $id)->update(['purpose' => 'password_reset', 'expires_at' => time() - 1]);
        self::assertSame(422, $this->call('POST', '/auth/password-resets', ['verification_id' => $id, 'code' => '123456', 'password' => 'changed-password'])[0]);
        self::assertTrue(Hash::checkPassword(User::find(1)->pass, 'test-password'));
        self::assertSame(0, DB::table('client_challenges')->value('consumed'));
    }

    public function testCallbacksRejectNestedParametersWithoutWarnings(): void
    {
        $this->configure(['epay_sign_type' => 'md5', 'epay_pid' => '123', 'epay_key' => 'key',
            'f2f_pay_app_id' => 'app', 'f2f_pay_public_key' => '', 'f2f_pay_private_key' => '',
        ]);
        $factory = new DecoratedResponseFactory(new HttpFactory(), new HttpFactory());
        self::assertSame('failed', (string) (new Epay())->notify((new ServerRequest('GET', 'https://panel.example.test'))->withQueryParams(['pid' => ['123']]), $factory->createResponse(), [])->getBody());
        self::assertSame('failed', (string) (new AlipayF2F())->notify((new ServerRequest('POST', 'https://panel.example.test'))->withParsedBody(['app_id' => ['app']]), $factory->createResponse(), [])->getBody());
        self::assertSame(0, DB::table('paylist')->count());
    }

    public function testLatePaymentCreditsWalletOnceWithoutActivatingCancelledOrder(): void
    {
        $order = DB::connection()->transaction(static fn (): array => Commerce::create(User::find(1), ['product_id' => 1]));
        DB::table('order')->where('id', $order['order_id'])->update(['status' => 'cancelled']);
        DB::table('invoice')->where('id', $order['invoice_id'])->update(['status' => 'cancelled']);
        DB::table('paylist')->insert(['userid' => 1, 'invoice_id' => $order['invoice_id'], 'total' => '10.99', 'tradeno' => 'late-trade', 'gateway' => 'EPay alipay']);
        Settlement::complete('late-trade', '10.99', 'EPay');
        Settlement::complete('late-trade', '10.99', 'EPay');
        self::assertSame('30.99', User::find(1)->getRawOriginal('money'));
        self::assertSame('cancelled', DB::table('order')->value('status'));
        self::assertSame('cancelled', DB::table('invoice')->value('status'));
        self::assertSame(1, DB::table('user_money_log')->count());
    }

    public function testTopupActivationRollsBackWithLedgerFailure(): void
    {
        $order = DB::connection()->transaction(static fn (): array => Commerce::create(User::find(1), ['amount' => '5.00'], true));
        DB::table('order')->where('id', $order['order_id'])->update(['status' => 'pending_activation']);
        DB::table('invoice')->where('id', $order['invoice_id'])->update(['status' => 'paid_gateway']);
        DB::schema()->drop('user_money_log');
        try {
            Settlement::activateTopup($order['order_id']);
            self::fail('Ledger failure did not abort activation');
        } catch (\Illuminate\Database\QueryException) {
            self::assertSame('pending_activation', DB::table('order')->value('status'));
            self::assertSame('20.00', User::find(1)->getRawOriginal('money'));
        }
    }

    public function testConcurrentSameKeyCreatesOnlyOneOrder(): void
    {
        $results = $this->race(static fn (): array => Idempotency::run(1, 'concurrent-order-01', 'order', ['product_id' => 1],
            static fn (): array => Commerce::create(User::find(1), ['product_id' => 1])));
        self::assertSame($results[0], $results[1]);
        self::assertSame(1, DB::table('order')->count());
        self::assertSame(1, DB::table('client_idempotency')->count());
        self::assertSame(1, DB::table('product')->value('stock'));
    }

    public function testConcurrentLastStockIsNotOversold(): void
    {
        DB::table('product')->where('id', 1)->update(['stock' => 1]);
        $results = $this->race(static fn (int $worker): array => Idempotency::run($worker + 1, 'concurrent-stock-01', 'order', [],
            static fn (): array => Commerce::create(User::find($worker + 1), ['product_id' => 1])));
        self::assertCount(1, array_filter($results, static fn (array $result): bool => isset($result['order_id'])));
        self::assertSame(1, DB::table('order')->count());
        self::assertSame(0, DB::table('product')->value('stock'));
    }

    public function testConcurrentGlobalCouponLimitIsEnforced(): void
    {
        DB::table('user_coupon')->insert(['code' => 'race-coupon', 'content' => '{"type":"percentage","value":10}',
            'limit' => '{"total_use_time":1}',
        ]);
        $results = $this->race(static fn (int $worker): array => Idempotency::run($worker + 1, 'concurrent-coupon-01', 'order', [],
            static fn (): array => Commerce::create(User::find($worker + 1), ['product_id' => 1, 'coupon' => 'race-coupon'])));
        self::assertCount(1, array_filter($results, static fn (array $result): bool => isset($result['order_id'])));
        self::assertSame(1, DB::table('user_coupon')->value('use_count'));
        self::assertSame(1, DB::table('order')->count());
    }

    public function testConcurrentBalanceDoesNotDoubleDebit(): void
    {
        $order = DB::connection()->transaction(static fn (): array => Commerce::create(User::find(1), ['product_id' => 1]));
        $results = $this->race(static fn (int $worker): array => Idempotency::run(1, 'concurrent-balance-' . $worker, 'payment', [],
            static fn (): array => Commerce::balance(User::find(1), $order['invoice_id'])));
        self::assertCount(1, array_filter($results, static fn (array $result): bool => isset($result['paid_amount'])));
        self::assertSame('9.01', User::find(1)->getRawOriginal('money'));
        self::assertSame(1, DB::table('user_money_log')->count());
    }

    public function testConcurrentGiftHasOnlyOneOwner(): void
    {
        DB::table('gift_card')->insert(['card' => 'concurrent-gift', 'balance' => '5.00']);
        $results = $this->race(static fn (int $worker): array => Idempotency::run($worker + 1, 'concurrent-gift-01', 'gift', [],
            static fn (): array => Commerce::gift(User::find($worker + 1), ['code' => 'concurrent-gift'])));
        self::assertCount(1, array_filter($results, static fn (array $result): bool => isset($result['amount'])));
        self::assertSame('45.00', bcadd(User::find(1)->getRawOriginal('money'), User::find(2)->getRawOriginal('money'), 2));
        self::assertSame(1, DB::table('user_money_log')->count());
    }

    public function testConcurrentRefreshRejectsReplayAndRevokesFamily(): void
    {
        $tokens = Sessions::issue(User::find(1), 'concurrent');
        $results = $this->race(static fn (): array => Sessions::refresh($tokens['refresh_token']));
        self::assertCount(1, array_filter($results, static fn (array $result): bool => isset($result['access_token'])));
        self::assertSame(1, DB::table('client_sessions')->where('id', $tokens['session_id'])->value('revoked'));
        self::assertSame(2, DB::table('client_refresh_tokens')->count());
    }

    public function testConcurrentDuplicateNotificationAndTopupCreditOnce(): void
    {
        $order = DB::connection()->transaction(static fn (): array => Commerce::create(User::find(1), ['amount' => '5.00'], true));
        DB::table('paylist')->insert(['userid' => 1, 'invoice_id' => $order['invoice_id'], 'total' => '5.00', 'tradeno' => 'race-trade', 'gateway' => 'EPay alipay']);
        $results = $this->race(static function (): array {
            Settlement::complete('race-trade', '5.00', 'EPay');
            return ['settled' => true];
        });
        self::assertSame([['settled' => true], ['settled' => true]], $results);
        DB::table('order')->where('id', $order['order_id'])->update(['status' => 'pending_activation']);
        $this->race(static function () use ($order): array {
            Settlement::activateTopup($order['order_id']);
            return ['activated' => true];
        });
        self::assertSame('25.00', User::find(1)->getRawOriginal('money'));
        self::assertSame(1, DB::table('user_money_log')->count());
        self::assertSame('activated', DB::table('order')->value('status'));
    }

    public function testConcurrentChallengeConsumptionRunsBusinessWriteOnce(): void
    {
        $id = Challenges::create('registration', ['eligible' => true], '123456');
        $results = $this->race(static fn (): array => Challenges::consume($id, '123456', 'registration', static function (): array {
            DB::table('user')->where('id', 1)->increment('money', 1);
            return ['verified' => true];
        }));
        self::assertCount(1, array_filter($results, static fn (array $result): bool => isset($result['verified'])));
        self::assertSame('21.00', Input::storedMoney(User::find(1)->getRawOriginal('money')));
        self::assertSame(1, DB::table('client_challenges')->value('consumed'));
    }

    public function testConcurrentGatewayInitiationSendsOnlyOneRequest(): void
    {
        $this->configure(['payment_gateway' => '["epay"]', 'epay_url' => 'https://gateway.example.test/',
            'epay_alipay' => '1', 'epay_pid' => '123', 'epay_key' => 'key', 'epay_sign_type' => 'md5',
        ]);
        $user = User::find(1);
        $order = DB::connection()->transaction(static fn (): array => Commerce::create($user, ['product_id' => 1]));
        $prepared = DB::connection()->transaction(static fn (): array => Payments::prepare($user, $order['invoice_id'], ['gateway' => 'epay']));
        $results = $this->race(static function () use ($prepared): array {
            $mock = new MockHandler([new Response(200, [], '{"code":1,"payurl":"https://gateway.example.test/pay/1"}')]);
            Payments::start(User::find(1), $prepared['payment_id'], '127.0.0.1', new Client(['handler' => HandlerStack::create($mock)]));
            return ['requests' => 1 - count($mock)];
        });
        self::assertSame(1, array_sum(array_column($results, 'requests')));
        self::assertSame('ready', DB::table('client_payments')->value('state'));
    }

    public function testConcurrentReferralSettlementDoesNotLoseWalletCredits(): void
    {
        DB::schema()->create('payback', static function (Blueprint $table): void {
            $table->increments('id');
            foreach (['userid', 'ref_by', 'invoice_id', 'datetime'] as $field) {
                $table->integer($field);
            }
            $table->decimal('total', 12, 2);
            $table->decimal('ref_get', 12, 2);
        });
        $this->configure(['invite_mode' => 'reward', 'invite_reward_mode' => 'reward_count',
            'invite_reward_count_limit' => '100', 'invite_reward_rate' => '0.2',
        ]);
        User::where('id', 1)->update(['ref_by' => 2]);
        for ($i = 0; $i < 2; $i++) {
            $order = DB::connection()->transaction(static fn (): array => Commerce::create(User::find(1), ['product_id' => 1]));
            DB::table('paylist')->insert(['userid' => 1, 'invoice_id' => $order['invoice_id'], 'total' => '10.99', 'tradeno' => 'referral-' . $i, 'gateway' => 'EPay alipay']);
        }
        $this->race(static function (int $worker): array {
            Settlement::complete('referral-' . $worker, '10.99', 'EPay');
            return ['settled' => true];
        });
        self::assertSame('24.40', Input::storedMoney(User::find(2)->getRawOriginal('money')));
        self::assertSame(2, DB::table('payback')->count());
        self::assertSame(2, DB::table('user_money_log')->count());
    }

    private function race(callable $worker): array
    {
        if ($this->mysqlDatabase === null || ! function_exists('pcntl_fork')) {
            self::markTestSkipped('Requires CLIENT_TEST_MYSQL=1 and pcntl for real InnoDB races');
        }
        $config = DB::connection()->getConfig();
        DB::connection()->disconnect();
        $workers = [];
        for ($index = 0; $index < 2; $index++) {
            $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
            $pid = pcntl_fork();
            if ($pid === -1) {
                self::fail('Unable to fork concurrency worker');
            }
            if ($pid === 0) {
                fclose($sockets[0]);
                fread($sockets[1], 1);
                $database = new DB();
                $database->addConnection($config);
                $database->setAsGlobal();
                $database->bootEloquent();
                try {
                    $result = $worker($index);
                } catch (ApiException $error) {
                    $result = ['error' => $error->errorCode, 'status' => $error->status];
                } catch (\Throwable $error) {
                    $result = ['unexpected' => $error::class, 'message' => $error->getMessage()];
                }
                fwrite($sockets[1], json_encode($result, JSON_THROW_ON_ERROR));
                fclose($sockets[1]);
                exit(0);
            }
            fclose($sockets[1]);
            stream_set_timeout($sockets[0], 15);
            $workers[] = [$pid, $sockets[0]];
        }
        foreach ($workers as [$pid, $socket]) {
            fwrite($socket, 's');
        }
        $results = [];
        foreach ($workers as [$pid, $socket]) {
            $result = json_decode(stream_get_contents($socket), true, 512, JSON_THROW_ON_ERROR);
            fclose($socket);
            pcntl_waitpid($pid, $status);
            self::assertSame(0, pcntl_wexitstatus($status));
            self::assertIsArray($result);
            self::assertArrayNotHasKey('unexpected', $result, json_encode($result));
            $results[] = $result;
        }
        return $results;
    }

    private function call(string $method, string $path, array $data = [], ?string $token = null, ?string $key = null): array
    {
        $app = AppFactory::create(new HttpFactory());
        (require dirname(__DIR__, 4) . '/app/client-routes.php')($app);
        $request = new ServerRequest(
            $method,
            'https://panel.example.test/client/v1' . $path,
            ['Content-Type' => 'application/json'],
            $data === [] ? '' : json_encode($data),
            '1.1',
            ['REMOTE_ADDR' => '127.0.0.1']
        );
        if ($token !== null) {
            $request = $request->withHeader('Authorization', 'Bearer ' . $token);
        }
        if ($key !== null) {
            $request = $request->withHeader('Idempotency-Key', $key);
        }
        $response = $app->handle($request);
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        self::assertSame($response->getHeaderLine('X-Request-ID'), $body['request_id'] ?? '');
        return [$response->getStatusCode(), $body];
    }

    private function configure(array $values): void
    {
        foreach ($values as $key => $value) {
            DB::table('config')->updateOrInsert(['item' => $key], ['value' => is_string($value) ? $value : json_encode($value), 'type' => 'string']);
        }
    }
}
