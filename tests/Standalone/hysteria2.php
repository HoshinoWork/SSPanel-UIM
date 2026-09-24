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
            'finalmask' => ['udp' => [['type' => 'salamander', 'settings' => ['password' => 'change-this-obfs-password']]]],
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
    check($xray['outbounds'][0]['streamSettings']['finalmask']['udp'][1]['settings']['remotePorts'] === '20000-20010,21000', 'Xray hopping missing');
    check($xray['outbounds'][0]['streamSettings']['finalmask']['udp'][1]['type'] === 'udphop', 'Xray hopping order incorrect');
    check($xray['outbounds'][0]['streamSettings']['finalmask']['udp'][0]['type'] === 'salamander', 'Salamander removed');
    check(! isset($xray['outbounds'][0]['streamSettings']['tlsSettings']['allowInsecure']), 'obsolete TLS field exported');
    if (isset($argv[2])) {
        check(file_put_contents($argv[2], json_encode($xray)) !== false, 'failed to write hopping fixture');
    }
    $custom['hysteria2']['portHopping']['enabled'] = false;
    $node->custom_config = json_encode($custom);
    check(! str_contains(App\Services\Subscribe\Hysteria2::buildUri($node, $user), 'mport='), 'disabled hopping published');
    $xray = json_decode((new App\Services\Subscribe\V2RayJson())->getContent($user), true);
    check(! isset($xray['outbounds'][0]['streamSettings']['finalmask']['quicParams']['udpHop']), 'disabled native hopping published');
    check(count($xray['outbounds'][0]['streamSettings']['finalmask']['udp']) === 1, 'disabled udphop mask published');
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
    unset($custom['hysteria2']['portHopping']);
    $custom['hysteria2']['finalmask']['quicParams']['udpHop'] = ['ports' => '22000-22010', 'interval' => 15];
    $node->custom_config = json_encode($custom);
    $hy2 = App\Services\Subscribe\Hysteria2::config($node);
    $mask = App\Services\Subscribe\Hysteria2::xrayFinalMask($custom['hysteria2'], $hy2);
    check(! isset($mask['quicParams']['udpHop']) && $mask['udp'][1]['settings']['interval'] === 15, 'legacy hop migration failed');
    unset($custom['hysteria2']['finalmask']['quicParams']);
    $custom['hysteria2']['finalmask']['udp'][] = ['type' => 'udphop', 'settings' => ['mode' => 'intervalremote', 'interval' => 20, 'remotePorts' => '23000-23010', 'remoteIPs' => ['192.0.2.1']]];
    $node->custom_config = json_encode($custom);
    $hy2 = App\Services\Subscribe\Hysteria2::config($node);
    $mask = App\Services\Subscribe\Hysteria2::xrayFinalMask($custom['hysteria2'], $hy2);
    check($hy2['ports'] === '23000-23010' && $hy2['hop_interval'] === 20.0, 'native hop not read');
    check($mask['udp'][1]['settings']['remoteIPs'] === ['192.0.2.1'], 'native hop options lost');
    echo "Hysteria2 validation and subscription contracts passed\n";
}
