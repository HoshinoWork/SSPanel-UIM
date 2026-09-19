<?php

declare(strict_types=1);

// Dependency-free contract tests. Only the database node lookup is replaced.
namespace App\Services {
    final class Subscribe
    {
        public static array $nodes = [];
        public static function getUserNodes($user): array { return self::$nodes; }
    }
}

namespace {
    $root = dirname(__DIR__, 2);
    foreach (['Base', 'Hysteria2', 'SingBox', 'V2RayJson'] as $class) {
        require $root . '/src/Services/Subscribe/' . $class . '.php';
    }
    require $root . '/src/Controllers/BaseController.php';
    require $root . '/src/Controllers/Admin/NodeController.php';
    function check(bool $condition, string $message): void {
        if (! $condition) { throw new RuntimeException($message); }
    }
    $reflection = new ReflectionClass(App\Controllers\Admin\NodeController::class);
    $controller = $reflection->newInstanceWithoutConstructor();
    $validate = $reflection->getMethod('validateCustomConfig');
    $custom = [
        'offset_port_node' => 443,
        'host' => 'localhost',
        'allow_insecure' => false,
        'hysteria2' => [
            'version' => 2,
            'udpIdleTimeout' => 60,
            'portHopping' => ['enabled' => true, 'ports' => '20000-20010,21000'],
        ],
    ];
    check($validate->invoke($controller, 15, json_encode($custom)) === null, 'numeric port rejected');
    $bad = $custom;
    $bad['hysteria2']['udpIdleTimeout'] = 1;
    check($validate->invoke($controller, 15, json_encode($bad)) !== null, 'invalid timeout accepted');
    $bad = $custom;
    $bad['hysteria2']['finalmask']['udp'] = [['type' => 'salamander', 'settings' => ['password' => '']]];
    check($validate->invoke($controller, 15, json_encode($bad)) !== null, 'empty obfs password accepted');
    foreach (['finalmask' => 'invalid', 'portHopping' => [], 'version' => '2'] as $key => $value) {
        $bad = $custom;
        $bad['hysteria2'][$key] = $value;
        check($validate->invoke($controller, 15, json_encode($bad)) !== null, 'invalid ' . $key . ' type accepted');
    }
    $node = (object) ['sort' => 15, 'name' => 'HY2', 'server' => '127.0.0.1', 'custom_config' => json_encode($custom)];
    $user = (object) ['uuid' => '123e4567-e89b-12d3-a456-426614174000'];
    App\Services\Subscribe::$nodes = [$node];
    $_ENV['V2RayJson_Config'] = ['outbounds' => []];
    $_ENV['SingBox_Config'] = ['outbounds' => [['type' => 'selector', 'outbounds' => []], ['type' => 'urltest', 'outbounds' => []]]];
    $_ENV['appName'] = 'test';
    $sing = json_decode((new App\Services\Subscribe\SingBox())->getContent($user), true);
    check($sing['outbounds'][2]['server_ports'] === ['20000:20010', '21000'], 'sing-box ranges incorrect');
    check(! isset($sing['outbounds'][2]['server_port']), 'sing-box conflicting port fields');
    $xray = json_decode((new App\Services\Subscribe\V2RayJson())->getContent($user), true);
    check($xray['outbounds'][0]['streamSettings']['finalmask']['quicParams']['udpHop']['ports'] === '20000-20010,21000', 'Xray hopping missing');
    check(! isset($xray['outbounds'][0]['streamSettings']['tlsSettings']['allowInsecure']), 'obsolete TLS field exported');
    if (isset($argv[2])) {
        check(file_put_contents($argv[2], json_encode($xray)) !== false, 'failed to write hopping fixture');
    }
    $custom['hysteria2']['portHopping']['enabled'] = false;
    $node->custom_config = json_encode($custom);
    check(! str_contains(App\Services\Subscribe\Hysteria2::buildUri($node, $user), 'mport='), 'disabled hopping published');
    $xray = json_decode((new App\Services\Subscribe\V2RayJson())->getContent($user), true);
    check(! isset($xray['outbounds'][0]['streamSettings']['finalmask']['quicParams']['udpHop']), 'disabled native hopping published');
    if (isset($argv[1])) {
        check(file_put_contents($argv[1], json_encode($xray)) !== false, 'failed to write fixture');
    }
    $custom['allow_insecure'] = 'false';
    $node->custom_config = json_encode($custom);
    check(! App\Services\Subscribe\Hysteria2::config($node)['insecure'], 'string false enabled insecure TLS');
    $custom['allow_insecure'] = true;
    $node->custom_config = json_encode($custom);
    $rejected = false;
    try {
        (new App\Services\Subscribe\V2RayJson())->getContent($user);
    } catch (InvalidArgumentException) {
        $rejected = true;
    }
    check($rejected, 'Xray insecure without pin accepted');
    $custom['pinnedPeerCertSha256'] = str_repeat('ab', 32);
    $node->custom_config = json_encode($custom);
    $xray = json_decode((new App\Services\Subscribe\V2RayJson())->getContent($user), true);
    check($xray['outbounds'][0]['streamSettings']['tlsSettings']['pinnedPeerCertSha256'] === $custom['pinnedPeerCertSha256'], 'certificate pin dropped');
    echo "Hysteria2 validation and subscription contracts passed\n";
}
