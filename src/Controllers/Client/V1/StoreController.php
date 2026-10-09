<?php

declare(strict_types=1);

namespace App\Controllers\Client\V1;

use App\Models\Invoice;
use App\Models\Order;
use App\Models\Product;
use App\Models\UserMoneyLog;
use App\Services\Client\ApiException;
use App\Services\Client\Commerce;
use App\Services\Client\Idempotency;
use App\Services\Client\Input;
use App\Services\Client\Payments;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class StoreController extends Controller
{
    public function products(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $query = Product::where('status', 1);
        $type = $request->getQueryParams()['type'] ?? null;
        if ($type !== null) {
            if (! in_array($type, ['tabp', 'time', 'bandwidth'], true)) {
                throw new ApiException(422, 'invalid_product_type', 'Invalid product type');
            }
            $query->where('type', $type);
        }
        return $this->page($request, $response, $query, ['id', 'name', 'type', 'price', 'stock', 'content']);
    }

    public function product(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $product = Product::where('id', $args['id'])->where('status', 1)->first(['id', 'name', 'type', 'price', 'stock', 'content']);
        if ($product === null) {
            throw new ApiException(404, 'product_not_found', 'Product not found');
        }
        try {
            Commerce::quote($this->user($request), ['product_id' => (int) $product->id]);
            $eligible = true;
        } catch (ApiException) {
            $eligible = false;
        }
        return $this->json($request, $response, $this->serialize($product->toArray()) + ['eligible' => $eligible]);
    }

    public function quote(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $quote = Commerce::quote($this->user($request), $this->input($request));
        unset($quote['product'], $quote['coupon_model']);
        return $this->json($request, $response, $quote);
    }

    public function order(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->write($request, $response, 'order', fn (): array => Commerce::create($this->user($request), $this->input($request)));
    }

    public function topup(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->write($request, $response, 'topup', fn (): array => Commerce::create($this->user($request), $this->input($request), true));
    }

    public function gift(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->write($request, $response, 'gift', fn (): array => Commerce::gift($this->user($request), $this->input($request)));
    }

    public function cancel(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        return $this->write($request, $response, 'cancel:' . $args['id'], fn (): array => Commerce::cancel($this->user($request), (int) $args['id']), 200);
    }

    public function orders(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->page(
            $request,
            $response,
            Order::where('user_id', $this->user($request)->id),
            ['id', 'product_id', 'product_name', 'product_type', 'price', 'status', 'create_time', 'update_time']
        );
    }

    public function orderDetail(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $order = Order::where('user_id', $this->user($request)->id)->where('id', $args['id'])->first();
        if ($order === null || str_contains($request->getUri()->getPath(), '/top-ups/') && $order->product_type !== 'topup') {
            throw new ApiException(404, 'order_not_found', 'Order not found');
        }
        $invoice = Invoice::where('order_id', $order->id)->where('user_id', $this->user($request)->id)->first();
        return $this->json($request, $response, $this->serialize($order->only(['id', 'product_id', 'product_name', 'product_type', 'price', 'status', 'create_time', 'update_time'])) +
            ['invoice_id' => $invoice?->id, 'invoice_status' => $invoice?->status]);
    }

    public function invoices(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->page(
            $request,
            $response,
            Invoice::where('user_id', $this->user($request)->id),
            ['id', 'order_id', 'price', 'status', 'type', 'create_time', 'update_time', 'pay_time']
        );
    }

    public function invoice(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $invoice = Invoice::where('user_id', $this->user($request)->id)->where('id', $args['id'])->first();
        if ($invoice === null) {
            throw new ApiException(404, 'invoice_not_found', 'Invoice not found');
        }
        return $this->json($request, $response, $this->serialize($invoice->only(['id', 'order_id', 'price', 'status', 'type', 'content', 'create_time', 'update_time', 'pay_time'])));
    }

    public function methods(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->json($request, $response, Payments::methods());
    }

    public function pay(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->user($request);
        $input = $this->input($request);
        $id = (int) $args['id'];
        $result = Idempotency::run(
            $user->id,
            $request->getHeaderLine('Idempotency-Key'),
            'payment:' . $id,
            $input,
            static fn (): array => ($input['gateway'] ?? '') === 'balance' ? Commerce::balance($user, $id) : Payments::prepare($user, $id, $input)
        );
        if (isset($result['payment_id'])) {
            $result = Payments::start($user, $result['payment_id'], $this->ip($request));
        }
        return $this->json($request, $response, $result, 201);
    }

    public function payment(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        return $this->json($request, $response, Payments::view($this->user($request), (int) $args['id']));
    }

    public function wallet(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->json($request, $response, ['balance' => Input::storedMoney($this->user($request)->getRawOriginal('money')), 'currency' => 'CNY']);
    }

    public function transactions(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->page(
            $request,
            $response,
            UserMoneyLog::where('user_id', $this->user($request)->id),
            ['id', 'before', 'after', 'amount', 'remark', 'create_time']
        );
    }

    private function write(ServerRequestInterface $request, ResponseInterface $response, string $operation, callable $action, int $status = 201): ResponseInterface
    {
        $data = Idempotency::run($this->user($request)->id, $request->getHeaderLine('Idempotency-Key'), $operation, $this->input($request), $action);
        return $this->json($request, $response, $data, $status);
    }

    private function page(ServerRequestInterface $request, ResponseInterface $response, mixed $query, array $fields): ResponseInterface
    {
        $params = $request->getQueryParams();
        $page = filter_var($params['page'] ?? 1, FILTER_VALIDATE_INT);
        $limit = filter_var($params['per_page'] ?? 20, FILTER_VALIDATE_INT);
        if ($page === false || $page < 1 || $page > 100000 || $limit === false || $limit < 1 || $limit > 100) {
            throw new ApiException(422, 'invalid_pagination', 'Invalid page or per_page');
        }
        $total = $query->count();
        $rows = $query->orderBy('id', 'desc')->offset(($page - 1) * $limit)->limit($limit)->get($fields);
        return $this->json($request, $response, ['items' => $rows->map(fn ($row): array => $this->serialize($row->toArray()))->all(),
            'page' => $page, 'per_page' => $limit, 'total' => $total,
        ]);
    }

    private function serialize(array $row): array
    {
        foreach ($row as $key => &$value) {
            if (in_array($key, ['price', 'before', 'after', 'amount'], true)) {
                $value = bcadd((string) $value, '0', 2);
            } elseif (in_array($key, ['create_time', 'update_time', 'pay_time'], true)) {
                $value = $value ? gmdate('c', (int) $value) : null;
            } elseif ($key === 'content') {
                $value = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
            }
        }
        return $row;
    }
}
