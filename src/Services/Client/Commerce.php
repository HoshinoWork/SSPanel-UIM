<?php

declare(strict_types=1);

namespace App\Services\Client;

use App\Models\GiftCard;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Paylist;
use App\Models\Product;
use App\Models\User;
use App\Models\UserCoupon;
use App\Models\UserMoneyLog;
use App\Services\DB;
use LogicException;

final class Commerce
{
    public static function quote(User $user, array $input, bool $lock = false): array
    {
        $id = $input['product_id'] ?? null;
        if (! is_int($id) || $id < 1) {
            throw new ApiException(422, 'invalid_product', 'product_id must be a positive integer');
        }
        $query = Product::where('id', $id)->where('status', 1);
        $product = ($lock ? $query->lockForUpdate() : $query)->first();
        if ($product === null || (int) $product->stock === 0) {
            throw new ApiException(409, 'product_unavailable', 'Product is unavailable');
        }
        $limit = json_decode($product->limit, true, 512, JSON_THROW_ON_ERROR);
        if (($limit['class_required'] ?? '') !== '' && $user->class < (int) $limit['class_required'] ||
            ($limit['node_group_required'] ?? '') !== '' && (int) $user->node_group !== (int) $limit['node_group_required'] ||
            (int) ($limit['new_user_required'] ?? 0) !== 0 && Order::where('user_id', $user->id)->exists()) {
            throw new ApiException(403, 'product_restricted', 'You are not eligible for this product');
        }
        $price = Input::storedMoney($product->getRawOriginal('price'));
        $discount = '0.00';
        $code = $input['coupon'] ?? '';
        if (! is_string($code) || strlen($code) > 255) {
            throw new ApiException(422, 'invalid_coupon', 'Invalid coupon');
        }
        $coupon = null;
        if ($code !== '') {
            $query = UserCoupon::where('code', $code);
            $coupon = ($lock ? $query->lockForUpdate() : $query)->first();
            if ($coupon === null || (int) $coupon->expire_time !== 0 && $coupon->expire_time < time()) {
                throw new ApiException(422, 'invalid_coupon', 'Coupon unavailable');
            }
            $rules = json_decode($coupon->limit, true, 512, JSON_THROW_ON_ERROR);
            if (($rules['disabled'] ?? false) || ($rules['product_id'] ?? '') !== '' && ! in_array((string) $id, explode(',', (string) $rules['product_id']), true) ||
                (int) ($rules['use_time'] ?? 0) > 0 && Order::where('user_id', $user->id)->where('coupon', $code)->count() >= (int) $rules['use_time'] ||
                (int) ($rules['total_use_time'] ?? 0) > 0 && $coupon->use_count >= (int) $rules['total_use_time']) {
                throw new ApiException(422, 'invalid_coupon', 'Coupon unavailable');
            }
            $content = json_decode($coupon->content, true, 512, JSON_THROW_ON_ERROR);
            $value = Input::storedMoney($content['value']);
            $discount = ($content['type'] ?? '') === 'percentage' ? bcdiv(bcmul($price, $value, 6), '100', 6) : $value;
            if (bccomp($discount, $price, 6) > 0) {
                $discount = $price;
            }
        }
        // Round the final invoice amount, not an intermediate percentage.
        $total = bcadd(bcsub($price, $discount, 6), '0.005', 2);
        $discount = bcsub($price, $total, 2);
        return ['product' => $product, 'coupon_model' => $coupon, 'product_id' => $id,
            'currency' => 'CNY', 'subtotal' => $price, 'discount' => $discount,
            'total' => $total, 'coupon' => $code,
        ];
    }

    public static function create(User $owner, array $input, bool $topup = false): array
    {
        self::requireTransaction();
        $user = User::where('id', $owner->id)->lockForUpdate()->first();
        if (! Sessions::validUser($user, hash('sha256', $owner->pass))) {
            throw new ApiException(403, 'account_unavailable', 'Account unavailable');
        }
        if ($topup) {
            $amount = Input::money($input['amount'] ?? null);
            if (bccomp($amount, '0', 2) <= 0) {
                throw new ApiException(422, 'invalid_amount', 'Top-up amount must be positive');
            }
            $quote = ['total' => $amount, 'subtotal' => $amount, 'discount' => '0.00', 'coupon' => ''];
        } else {
            $quote = self::quote($user, $input, true);
        }
        $product = $quote['product'] ?? null;
        $order = new Order();
        $order->fill(['user_id' => $user->id, 'product_id' => $product?->id ?? 0,
            'product_type' => $product?->type ?? 'topup', 'product_name' => $product?->name ?? 'Balance top-up',
            'product_content' => $product !== null ? $product->content : json_encode(['amount' => $quote['total']], JSON_THROW_ON_ERROR),
            'coupon' => $quote['coupon'], 'price' => $quote['total'],
            'status' => $quote['total'] === '0.00' ? 'pending_activation' : 'pending_payment',
            'create_time' => time(), 'update_time' => time(),
        ]);
        $order->save();
        $invoice = new Invoice();
        $items = [['content_id' => 0, 'name' => $order->product_name, 'price' => $quote['subtotal']]];
        if ($quote['discount'] !== '0.00') {
            $items[] = ['content_id' => 1, 'name' => 'Coupon ' . $quote['coupon'], 'price' => '-' . $quote['discount']];
        }
        $invoice->fill(['user_id' => $user->id, 'order_id' => $order->id, 'content' => json_encode($items, JSON_THROW_ON_ERROR),
            'price' => $quote['total'], 'status' => $quote['total'] === '0.00' ? 'paid_gateway' : 'unpaid',
            'create_time' => time(), 'update_time' => time(), 'pay_time' => 0, 'type' => $topup ? 'topup' : 'product',
        ]);
        $invoice->save();
        if ($product !== null) {
            if ($product->stock > 0) {
                $product->stock--;
            }
            $product->sale_count++;
            $product->save();
        }
        if (($quote['coupon_model'] ?? null) !== null) {
            $quote['coupon_model']->increment('use_count');
        }
        return ['order_id' => $order->id, 'invoice_id' => $invoice->id, 'currency' => 'CNY',
            'amount' => $quote['total'], 'order_status' => $order->status, 'invoice_status' => $invoice->status,
        ];
    }

    public static function balance(User $owner, int $invoiceId): array
    {
        self::requireTransaction();
        $user = User::where('id', $owner->id)->lockForUpdate()->first();
        if (! Sessions::validUser($user, hash('sha256', $owner->pass))) {
            throw new ApiException(403, 'account_unavailable', 'Account unavailable');
        }
        $invoice = Invoice::where('id', $invoiceId)->where('user_id', $owner->id)->lockForUpdate()->first();
        if ($invoice === null) {
            throw new ApiException(404, 'invoice_not_found', 'Invoice not found');
        }
        if ($invoice->type === 'topup' || ! in_array($invoice->status, ['unpaid', 'partially_paid'], true)) {
            throw new ApiException(409, 'invoice_not_payable', 'Invoice cannot be paid with balance');
        }
        if (Paylist::where('invoice_id', $invoiceId)->where('status', 0)->exists()) {
            throw new ApiException(409, 'payment_in_progress', 'An existing gateway payment must finish before balance payment');
        }
        $money = Input::storedMoney($user->getRawOriginal('money'));
        $due = Input::storedMoney($invoice->getRawOriginal('price'));
        if (bccomp($money, '0', 2) <= 0) {
            throw new ApiException(409, 'insufficient_balance', 'Insufficient balance');
        }
        $paid = bccomp($money, $due, 2) >= 0 ? $due : $money;
        $user->money = bcsub($money, $paid, 2);
        $user->save();
        $remaining = bcsub($due, $paid, 2);
        $invoice->status = $remaining === '0.00' ? 'paid_balance' : 'partially_paid';
        if ($remaining !== '0.00') {
            $invoice->price = $remaining;
            $items = json_decode($invoice->content, true, 512, JSON_THROW_ON_ERROR);
            $items[] = ['content_id' => count($items), 'name' => 'Balance payment', 'price' => '-' . $paid];
            $invoice->content = json_encode($items, JSON_THROW_ON_ERROR);
        }
        $invoice->update_time = time();
        $invoice->pay_time = time();
        $invoice->save();
        (new UserMoneyLog())->add($user->id, (float) $money, (float) $user->money, -(float) $paid, 'Payment for invoice #' . $invoice->id);
        return ['invoice_id' => $invoice->id, 'status' => $invoice->status, 'paid_amount' => $paid,
            'remaining_amount' => $remaining, 'currency' => 'CNY',
        ];
    }

    public static function gift(User $owner, array $input): array
    {
        self::requireTransaction();
        $user = User::where('id', $owner->id)->lockForUpdate()->first();
        if (! Sessions::validUser($user, hash('sha256', $owner->pass))) {
            throw new ApiException(403, 'account_unavailable', 'Account unavailable');
        }
        $gift = GiftCard::where('card', Input::text($input, 'code'))->lockForUpdate()->first();
        if ($gift === null || (int) $gift->status !== 0) {
            throw new ApiException(409, 'gift_card_unavailable', 'Gift card unavailable');
        }
        $before = Input::storedMoney($user->getRawOriginal('money'));
        $amount = Input::storedMoney($gift->getRawOriginal('balance'));
        $gift->status = 1;
        $gift->use_time = time();
        $gift->use_user = $user->id;
        $gift->save();
        $user->money = bcadd($before, $amount, 2);
        $user->save();
        (new UserMoneyLog())->add($user->id, (float) $before, (float) $user->money, (float) $amount, 'Gift card redemption #' . $gift->id);
        return ['amount' => $amount, 'balance' => Input::storedMoney($user->money), 'currency' => 'CNY'];
    }

    public static function cancel(User $owner, int $id): array
    {
        self::requireTransaction();
        Sessions::current($owner);
        $order = Order::where('user_id', $owner->id)->where('id', $id)->lockForUpdate()->first();
        if ($order === null) {
            throw new ApiException(404, 'order_not_found', 'Order not found');
        }
        $invoice = Invoice::where('order_id', $id)->lockForUpdate()->first();
        if ($order->status !== 'pending_payment' || $invoice?->status !== 'unpaid' || Paylist::where('invoice_id', $invoice->id)->exists()) {
            throw new ApiException(409, 'order_not_cancellable', 'Only unpaid orders without payment attempts can be cancelled');
        }
        $order->status = 'cancelled';
        $order->update_time = time();
        $order->save();
        $invoice->status = 'cancelled';
        $invoice->update_time = time();
        $invoice->save();
        // Preserve existing panel reservation/coupon semantics: cancellation
        // does not silently invent a refund or restore consumed coupon uses.
        return ['order_id' => $id, 'status' => 'cancelled'];
    }

    private static function requireTransaction(): void
    {
        if (DB::connection()->transactionLevel() === 0) {
            throw new LogicException('Financial writes require a transaction');
        }
    }
}
