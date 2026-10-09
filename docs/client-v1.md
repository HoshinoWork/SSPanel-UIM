# Flutter 原生客户端 API v1

本 API 独立位于 `/client/v1`，面向 Android、iOS、Windows、macOS 原生客户端。不复用旧 `/api/v1` 空壳，也不使用网页 Cookie、管理员 API Token 或 XrayR 的 `/mod_mu` 节点令牌进行客户端认证。

## 部署与启用

先备份数据库，安装锁定的 Composer 依赖，再运行：

```sh
php xcat Migration latest
```

迁移 `2026100600` 新增会话、刷新令牌、验证挑战、限流、幂等与支付尝试六张表，并显式指定 InnoDB。订单、账单、余额、用户、优惠码和礼品卡继续使用面板原有表，这些原表也必须为 InnoDB。不要在生产数据库上运行回归测试。部署步骤和安全边界见 [安全检查记录](client-v1-security.md)。

在 `config/.config.php` 中配置：

```php
$_ENV['client_api_enabled'] = true;
$_ENV['client_api_allow_http'] = false;
$_ENV['client_api_access_ttl'] = 900;       // 60–3600 秒
$_ENV['client_api_refresh_ttl'] = 2592000;  // 3600–7776000 秒，固定会话总寿命
```

默认关闭。正式环境必须 HTTPS。反向代理须正确设置 PHP 的 HTTPS 服务参数；API 不盲信客户端提供的 `X-Forwarded-Proto` 或 `X-Forwarded-For`。真实 IP 应由可信代理配置在服务端恢复。注册、邮箱过滤、验证码、订阅、商品、签到和支付网关的开关继续读取现有面板设置。

使用独立、不可预测的面板 `key`，保持 `debug=false`，不要使用示例密钥。客户端路径在全局错误中间件中独立处理，不读取网页 Cookie；未知路径和不允许的方法也返回无堆栈的 JSON 404/405。访问日志应隐藏 Authorization、订阅令牌、MFA browser ticket；请求体和支付动作不应进入通用日志。

升级后网页的商品下单、余额支付、礼品卡及易支付/当面付发起支付同样使用安全的公共交易服务。因此必须先迁移，不能只复制 PHP 文件。其他支付网关未加入客户端可用列表。

## 通用契约

请求与 JSON 响应：

```http
Content-Type: application/json
Authorization: Bearer ACCESS_TOKEN
Idempotency-Key: a-new-unique-key-per-operation
```

```json
{
  "data": {},
  "error": null,
  "request_id": "server-generated-request-id"
}
```

```json
{
  "data": null,
  "error": {"code": "unauthorized", "message": "Bearer token required"},
  "request_id": "server-generated-request-id"
}
```

除验证码/MFA 网页、支付返回页及原始订阅正文外，均使用上述 JSON 包装。响应 `Cache-Control: no-store`，`X-Request-ID` 与 JSON 一致，附带 `nosniff` 和 `no-referrer`。请求正文最多 64 KiB，非空正文必须为 `application/json` 的对象。错误使用 HTTP 400/401/403/404/405/409/413/415/422/429/503，不靠 `ret` 判断。

金额是 CNY 十进制字符串，例如 `"10.99"`，不能发送浮点数、负数、指数形式或超过两位小数。充值上限为 `99999999.99`。服务端使用 BCMath 计算报价、扣款与核对支付金额。UTC 时间使用 ISO 8601；签到日期仍按面板时区划分。

列表支持 `page=1&per_page=20`，每页 1–100 条，返回 `items/page/per_page/total`。节点、会话和支付方式列表例外，返回数组。

交易写接口必须携带 8–128 字符的 `Idempotency-Key`（字母、数字、`_.:-`）。同一用户、同一键、同一路径操作和同一请求体的重试返回原结果；同键不同请求返回 409。键不能跨操作复用。网络重试必须保留原键和原请求体。财务幂等记录不按短期缓存删除。

## 公共与认证接口

| 方法 | 路径（均以 `/client/v1` 开头） | 请求/用途 |
|---|---|---|
| GET | `/config` | 站点名称、条款地址、注册/订阅/签到设置 |
| GET | `/captcha` | provider、公钥设置、各流程验证码开关、受控验证页 URL |
| GET | `/captcha/browser` | 原生 WebView 验证码页 |
| POST | `/auth/sessions` | `email,password,device_name,captcha?` 登录 |
| POST | `/auth/refresh` | `refresh_token` 单次轮换 |
| POST | `/auth/email-verifications` | `email,purpose="registration",captcha?` |
| POST | `/auth/registrations` | `name,email,password,tos_accepted,invite_code?,verification_id?,code?,captcha?` |
| POST | `/auth/password-reset-requests` | `email,captcha?` |
| POST | `/auth/password-resets` | `verification_id,code,password` |
| POST | `/mfa/challenges/{id}/verification` | `challenge_secret,method,code?` |
| GET/POST | `/mfa/challenges/{id}/browser?ticket=...` | FIDO/Passkey 网页请求与签名验证 |

注册密码至少 8 字节，条款必须传 JSON `true`。邀请注册模式必须提供有效邀请；关闭注册时返回 403。邮箱验证码有效期采用面板设置，最多 5 次错误尝试，绑定邮箱和用途，只能消费一次。错误尝试计数会提交，不因返回错误而回滚；有效验证和注册/重置密码/修改邮箱/MFA 会话签发在同一事务内提交，业务失败不留下已消费的有效验证。邮件请求对不存在的账户返回相同结构，但发送耗时并未统一，不能宣称完全抵抗时序枚举。

登录例：

```json
{"email":"user@example.com","password":"YOUR_PASSWORD","device_name":"Flutter Windows"}
```

无 MFA 时 HTTP 201：

```json
{
  "data": {
    "token_type": "Bearer",
    "access_token": "OPAQUE_ACCESS_TOKEN",
    "refresh_token": "OPAQUE_REFRESH_TOKEN",
    "expires_in": 900,
    "refresh_expires_in": 2592000,
    "session_id": "OPAQUE_SESSION_ID"
  },
  "error": null,
  "request_id": "REQUEST_ID"
}
```

令牌为随机不透明字符串，数据库只保存散列。刷新成功后旧 access token 立即失效，旧 refresh token 已消费；再次使用旧 refresh token 会撤销整个会话家族。Flutter 必须将刷新请求串行化，不能多个并发 401 各自刷新。不要因为网络超时无条件重试已经可能消费的刷新令牌；无法确认结果时重新登录。

密码改变、重置、账户封禁、客户端主动撤销会使会话失效。修改网页密码亦会通过密码摘要绑定使原客户端令牌失效。退出客户端不等于删除网页 Cookie，会话彼此独立。

认证在请求进入时检查；重要写入还会锁定用户、复核密码摘要和封禁状态。撤销不是取消已在执行的请求，也不保证瞬间终止已开始的合法操作。App 不应依赖这种取消语义。

### MFA

登录 HTTP 202 返回 `mfa_required/challenge_id/challenge_secret/methods/expires_in/verification_url`，此时没有任何 access token。

TOTP：

```json
{"challenge_secret":"PRIVATE_APP_CHALLENGE_SECRET","method":"totp","code":"123456"}
```

TOTP 必须为六位数字；同一用户的已使用 TOTP 码在有效重放窗口内不能再次登录。FIDO/Passkey 用系统浏览器打开服务端返回的 `verification_url`，验证成功后回到 App，再 POST `method="browser"` 和 App 私有 `challenge_secret`。浏览器票据与 App 挑战秘密不同，浏览器不能直接获取会话令牌。不能以 App 自报 `approved=true`、网页登录 Cookie 或不同用户的凭据完成验证。

WebAuthn 挑战、RP ID、来源、用户凭据归属和签名计数由现有密码学验证器检查。优先使用系统浏览器，不能假设所有平台的嵌入式 WebView 都支持 Passkey。首版没有客户端 MFA 设备注册/删除、无密码登录或第三方 OAuth；已有 MFA 用户必须完成第二步。

### 验证码原生桥

`/captcha` 只返回 Turnstile/Geetest/hCaptcha/reCAPTCHA Enterprise 的公开设置，不返回私钥。Flutter 原生 WebView 加载返回的验证页，为顶层可信站点提供 `ClientCaptcha.postMessage(message)` JavaScript 通道。消息内容如：

```json
{"turnstile":"PROVIDER_RESPONSE_TOKEN"}
```

将其放入最终请求的 `captcha` 对象，由服务端实际向供应商验证；桥接回调本身不是验证成功凭证。Geetest 对象包含 `lot_number/captcha_output/pass_token/gen_time`。其他供应商使用对应 provider 名称作为键。

只允许配置的 HTTPS 面板来源；不向任何第三方页面注入 access/refresh token、密码或通用原生调用能力。供应商 iframe 与跳转均不能获得 App 敏感通道。桌面平台需由所选 WebView 插件实现同名消息适配；不要假设 Android/iOS 插件直接支持 Windows/macOS。供应商域名白名单和正式域名授权须在其控制台配置。

## 用户、订阅与内容

| 方法 | 路径 | 用途/请求 |
|---|---|---|
| GET/PATCH | `/me` | 查询资料；PATCH 仅 `name` |
| PUT | `/me/password` | `old_password,password`；成功后重新登录 |
| POST | `/me/email-verifications` | `password,email,captcha?`，须开启面板更换邮箱 |
| PUT | `/me/email` | `verification_id,code`；成功后重新登录 |
| GET | `/me/traffic` | 已用、总量、剩余、今日流量及 keep_connect |
| GET/DELETE | `/me/sessions` | 列出/撤销全部客户端会话 |
| DELETE | `/me/sessions/{id}` | 撤销指定本人会话 |
| GET | `/me/subscription` | 格式列表及普通订阅 URL |
| GET | `/me/subscription/content?format=singbox` | Bearer 认证的原始订阅正文 |
| POST | `/me/subscription/rotations` | 重置本人所有旧订阅链接；非幂等，每次调用都更换 |
| GET | `/nodes` | 按原订阅可见性过滤节点，返回 id/name/sort/class |
| GET | `/announcements` | 仅已发布/置顶公告，分页 |
| GET | `/docs`、`/docs/{id}` | 遵循文档开关及付费用户权限 |
| POST | `/me/checkins` | `captcha?`；需要幂等键，使用面板签到奖励规则 |

订阅直接复用现有生成器：`json/clash/singbox/v2rayjson/v2ray/hysteria2/sip002/sip008/ss/trojan`。协议能力和格式内容与普通订阅一致，不新写另一套 VLESS/Hysteria2 参数转换。原始正文保留 `Subscription-Userinfo`，不能按 JSON 包装解析。普通订阅 URL 使用现有 `subUrl`；该域名必须按原 `/sub` 路由配置。App 导入使用 Bearer 正文或格式 URL，不要把客户端 access token 拼入 URL。

节点接口不返回原始 `custom_config`、服务端 REALITY 私钥或商户密钥。资料 UUID 是面板用户 UUID，不是认证令牌。流量耗尽/等级不足仍遵循原节点可见性及后端规则，`keep_connect` 读取面板环境配置。

公告/文档 `content` 是面板原始内容。原生客户端须安全渲染，禁用任意脚本、文件访问及不受控原生桥；不能视为可信可执行 HTML。

## 商店、订单与支付

| 方法 | 路径 | 用途/请求 |
|---|---|---|
| GET | `/products?type=bandwidth`、`/products/{id}` | 商品目录；详情带 eligible |
| POST | `/order-quotes` | `product_id`（整数）、`coupon?`，预览服务端价格 |
| POST | `/orders` | 同报价参数，需要幂等键 |
| GET | `/orders`、`/orders/{id}` | 订单与关联账单状态 |
| DELETE | `/orders/{id}` | 未支付、未部分支付且未发起网关支付时取消，需要幂等键 |
| GET | `/invoices`、`/invoices/{id}` | 本人账单及项目明细 |
| GET | `/payment-methods` | balance、已启用的 epay/f2f |
| POST | `/invoices/{id}/payments` | `gateway,method?`，需要幂等键 |
| GET | `/payments/{id}` | 本人网关支付尝试状态及可用动作 |
| POST | `/topups` | `amount` 十进制字符串，需要幂等键 |
| GET | `/me/wallet`、`/me/wallet/transactions` | 余额与变动记录 |
| POST | `/gift-card-redemptions` | `code`，需要幂等键 |

先报价，再下单，再用响应的 `invoice_id` 付款，不允许客户端指定最终金额或优惠额度。创建时重新锁定/检查商品状态、库存、等级、用户组、新用户条件、优惠码有效期与次数上限。订单/账单/库存/优惠码次数/幂等记录在同一事务提交。保留旧面板的预约语义：取消订单不自动恢复库存或优惠码次数，也不自动退款。

最终应付金额按两位小数舍入。免费或优惠后为 `0.00` 的订单直接等待激活，不需要再发起付款，客户端应轮询订单状态。

```json
{"product_id":123,"coupon":"OPTIONAL_COUPON"}
```

```json
{
  "order_id":456,"invoice_id":789,"currency":"CNY","amount":"10.99",
  "order_status":"pending_payment","invoice_status":"unpaid"
}
```

付款请求示例：

```json
{"gateway":"epay","method":"alipay"}
```

易支付沿用面板的 `epay_alipay/epay_wechat/epay_qq/epay_usdt` 开关，只有开启的方法可调用。支持 `alipay/wxpay/qqpay/usdt/epusdt`（后两者共用 USDT 开关），实际可用方法以 `/payment-methods` 中的 `methods` 数组为准，商户亦需开通。USDT 由易支付处理兑换，面板账单仍是 CNY，不新增加密币网关。当面付仅 `{"gateway":"f2f","method":"alipay"}`。余额支付为 `{"gateway":"balance"}`，充值账单不能使用余额支付。余额不足时沿用组合支付：扣除现有余额、账单变成 `partially_paid`，再对剩余金额发起网关支付；存在未完成网关尝试时禁止再扣余额，避免旧付款金额变动。

网关结果（JSON 包装内的 data）：

```json
{
  "payment_id":100,"invoice_id":789,"amount":"10.99","currency":"CNY",
  "gateway":"epay","status":"ready","expires_at":"2026-10-06T12:15:00+00:00",
  "action":{"type":"redirect","url":"https://PAYMENT_GATEWAY/PAYMENT_PATH"}
}
```

当面付动作是 `{"type":"qr_code","content":"ALIPAY_QR_CONTENT"}`：App 展示二维码/按平台支持的合法方式打开，不能直接当作付款完成。首版不提供支付宝原生 App 支付 SDK order string。

支付动作仅对未支付、未过期尝试返回。状态：`pending/creating/ready/indeterminate/expired/paid`。客户端跳回、扫码成功画面及 `/payment-return` 都不能确认入账；只认服务端签名校验的异步通知和随后查询到的账单状态。通知路径继续使用 `/payment/notify/epay` 与 `/payment/notify/f2f`；不得要求其携带用户 Bearer token。

易支付验证签名、PID、成功状态、交易归属和精确金额；支付宝验证 RSA2、app_id、成功状态、归属和精确金额。账单和交易锁定后幂等结算，重复通知不重复余额或返佣。晚到的合法付款/重复实际付款按既有结算思想进入用户余额，不把取消订单重新激活。

### 网关配置和异常边界

易支付复用 `payment_gateway` 中的 `epay` 和 `epay_url/epay_pid/epay_key/epay_sign_type`，原生客户端 API 要求网关 API URL 和返回付款 URL 使用 HTTPS，使用现有 `mapi.php` 协议。网页入口保留旧 HTTP/HTTPS 网关兼容性，但建议管理员升级为 HTTPS；网页付款返回本人账单，不依赖启用客户端 API，也不接受任意返回地址。支付宝复用 `f2f_pay_app_id/f2f_pay_private_key/f2f_pay_public_key/f2f_pay_notify_url` 和网关 `f2f`。所有密钥只能留在服务端。

外部发起支付不在数据库长事务内。通过原子状态领取，同一交易最多发起一次。网关超时/异常可能已经创建了付款单，标记 `indeterminate`，不能静默新建另一笔收费；进程在领取后崩溃可能留下 `creating`，也不能盲目重发。首版没有供应商通用自动查单/关单适配；这些异常状态、已过期但未确认关闭，以及升级前已存在的支付尝试，需要管理员在网关核实处理，不能仅依据本地超时删除支付证据或释放另一条付款通道。此限制是避免重复收费，不代表已付款成功，也不是数据库与支付供应商的分布式原子提交。

支付完成不等于套餐已生效。继续运行面板原有订单 Cron：`processPendingOrder` 后处理 TABP、流量包、时间包及充值激活。充值在激活事务中仅加一次余额；三类商品激活与订单状态更新按用户加锁。客户端轮询订单的 `activated` 状态，并在激活后重新获取资料、余额和订阅。

## Flutter 实现检查清单

- 使用平台安全存储保存刷新令牌，访问令牌尽量仅留内存；禁止日志输出令牌、密码、二维码内容或完整订阅 URL。
- 全局单一刷新队列，原请求至多重试一次；401 且刷新失败则回登录页。
- 金额保持字符串，显示和计算不要使用二进制 double。
- 写交易前生成 UUID 作为幂等键，并将请求体与键一起保存到请求生命周期结束。
- 区分 202 MFA/邮件请求、409 状态冲突、422 参数/验证码错误、429 限流、503 暂不可用；遵循 Retry-After。
- 查询支付后采用有限轮询/退避，App 恢复前台时再同步；不能据支付返回页更新余额。
- 不把订阅访问权与 App 登录权混为一谈；退出会话不会自动撤销普通订阅 URL，要撤销订阅使用 rotations。
- 浏览器/WebView 桥必须限源、限方法，不提供任意原生执行能力。

## 回归验证

测试位于 `tests/Integration/Services/Client/ClientApiTest.php`。常规安装依赖后，先跑不依赖面板环境的 SQLite 测试：

```sh
vendor/bin/phpunit --no-configuration --bootstrap vendor/autoload.php --fail-on-warning --fail-on-risky tests/Integration/Services/Client/ClientApiTest.php
vendor/bin/pest tests/Integration/Services/Subscribe/VlessSubscriptionTest.php
vendor/bin/phpinsights analyse --no-interaction --min-quality=100 --min-complexity=80 --min-architecture=100 --min-style=100
```

真实 InnoDB 并发测试仅在独立测试数据库实例执行。账户需要创建/删除临时数据库权限，测试会创建随机 `sspanel_client_test_*` 数据库并在结束后删除，仅操作这些随机库，不读取面板生产配置：

```sh
CLIENT_TEST_MYSQL=1 \
CLIENT_TEST_MYSQL_HOST=127.0.0.1 \
CLIENT_TEST_MYSQL_PORT=3306 \
CLIENT_TEST_MYSQL_USER=root \
CLIENT_TEST_MYSQL_PASSWORD=TEST_DATABASE_PASSWORD \
vendor/bin/phpunit --no-configuration --bootstrap vendor/autoload.php --fail-on-warning --fail-on-risky tests/Integration/Services/Client/ClientApiTest.php
```

本地 Unix socket 可用 `CLIENT_TEST_MYSQL_SOCKET` 指定。并发测试依赖 `pcntl`，没有 MariaDB 或 pcntl 时明确跳过，不能把 SQLite 的结果当作并发保证。`Native Client API Security Regression` 工作流在 `feat/hysteria2-sspanel` 推送、相关 PR 和手动触发时运行 PHP 8.2/8.3/8.4 的 SQLite 和 MariaDB 测试，并做依赖审计；PHP 8.3 运行严格 lint。

自动测试使用模拟网关与临时生成的测试 RSA 密钥，不使用生产付款、不使用真实用户数据。正式启用前仍需在测试商户/小额授权场景验证供应商配置、异步通知公网可达、实际回款、四个平台验证码/Passkey 网页及原生支付交互。

最新验证环境、逐项结果及未覆盖边界统一记录在 [安全检查记录](client-v1-security.md)，不以 lint 或有限测试宣称绝对安全。
