<?php

declare(strict_types=1);

namespace App\Controllers\Client\V1;

use App\Models\Config;
use App\Services\Captcha;
use App\Services\Client\ApiException;
use App\Services\Client\BrowserMfa;
use App\Services\Client\Input;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class BrowserController extends Controller
{
    public function captcha(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $provider = Config::obtain('captcha_provider');
        $settings = Captcha::generate();
        $key = match ($provider) {
            'turnstile' => $settings['turnstile_sitekey'], 'hcaptcha' => $settings['hcaptcha_sitekey'],
            'geetest' => $settings['geetest_id'], 'recaptcha_enterprise' => $settings['recaptcha_enterprise_key_id'],
            default => throw new ApiException(409, 'captcha_unavailable', 'No supported captcha configured'),
        };
        $keyJson = json_encode($key, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR);
        $providerJson = json_encode($provider, JSON_THROW_ON_ERROR);
        $nonce = bin2hex(random_bytes(16));
        $script = 'const provider=' . $providerJson . ',key=' . $keyJson . ';';
        $script .= <<<'JS'
function done(value){const message=JSON.stringify({[provider]:value});
 if(window.ClientCaptcha&&typeof ClientCaptcha.postMessage==="function"){ClientCaptcha.postMessage(message);}
 else{document.getElementById("status").textContent="Captcha completed; native bridge unavailable.";}}
function initCaptcha(){if(provider==="turnstile"){turnstile.render("#captcha",{sitekey:key,callback:done});}
 else if(provider==="hcaptcha"){hcaptcha.render("captcha",{sitekey:key,callback:done});}
 else if(provider==="recaptcha_enterprise"){grecaptcha.enterprise.render("captcha",{sitekey:key,callback:done});}
 else{initGeetest4({captchaId:key,product:"float"},g=>{g.appendTo("#captcha");g.onSuccess(()=>done(g.getValidate()));});}}
JS;
        $source = match ($provider) {
            'turnstile' => 'https://challenges.cloudflare.com/turnstile/v0/api.js?onload=initCaptcha&render=explicit',
            'hcaptcha' => 'https://js.hcaptcha.com/1/api.js?onload=initCaptcha&render=explicit',
            'recaptcha_enterprise' => 'https://www.recaptcha.net/recaptcha/enterprise.js?onload=initCaptcha&render=explicit',
            'geetest' => 'https://static.geetest.com/v4/gt4.js',
        };
        $response->getBody()->write('<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width"><title>Client captcha</title><div id="captcha"></div><p id="status"></p><script nonce="' . $nonce . '">' . $script . '</script><script src="'
            . htmlspecialchars($source, ENT_QUOTES, 'UTF-8') . '"' . ($provider === 'geetest' ? ' onload="initCaptcha()"' : '') . '></script></html>');
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8')->withHeader('Referrer-Policy', 'no-referrer')
            ->withHeader('X-Frame-Options', 'DENY')->withHeader('X-Content-Type-Options', 'nosniff');
    }

    public function page(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $options = BrowserMfa::options($args['id'], Input::text($request->getQueryParams(), 'ticket'));
        $nonce = bin2hex(random_bytes(16));
        $settings = json_encode($options, JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        $html = '<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width"><title>Verify sign-in</title><h1>Verify client sign-in</h1><button id="verify">Use security key / passkey</button><p id="status"></p><script nonce="' . $nonce . '">const options=' . $settings . ';</script>';
        $script = <<<'JS'
const decode=s=>Uint8Array.from(atob(s.replace(/-/g,'+').replace(/_/g,'/')),c=>c.charCodeAt(0));
const encode=b=>btoa(String.fromCharCode(...new Uint8Array(b))).replace(/\+/g,'-').replace(/\//g,'_').replace(/=+$/,'');
options.challenge=decode(options.challenge);
options.allowCredentials=options.allowCredentials.map(c=>({...c,id:decode(c.id)}));
document.getElementById('verify').onclick=async()=>{const status=document.getElementById('status');try{
 const c=await navigator.credentials.get({publicKey:options});
 const data={id:c.id,rawId:encode(c.rawId),type:c.type,response:{clientDataJSON:encode(c.response.clientDataJSON),
 authenticatorData:encode(c.response.authenticatorData),signature:encode(c.response.signature),
 userHandle:c.response.userHandle?encode(c.response.userHandle):null},clientExtensionResults:c.getClientExtensionResults()};
 const result=await fetch(location.href,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(data)});
 status.textContent=result.ok?'Verified. Return to your app to finish sign-in.':'Verification failed.';
}catch(e){status.textContent='Verification cancelled or unavailable.';}};
JS;
        $response->getBody()->write($html . '<script nonce="' . $nonce . '">' . $script . '</script></html>');
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8')->withHeader('Referrer-Policy', 'no-referrer')
            ->withHeader('Content-Security-Policy', "default-src 'none'; script-src 'nonce-" . $nonce . "'; connect-src 'self'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'");
    }

    public function verify(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        BrowserMfa::verify($args['id'], Input::text($request->getQueryParams(), 'ticket'), $this->input($request));
        return $this->json($request, $response, ['verified' => true]);
    }
}
