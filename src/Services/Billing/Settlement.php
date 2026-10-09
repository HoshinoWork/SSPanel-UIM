<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Models\Config;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Paylist;
use App\Models\User;
use App\Models\UserMoneyLog;
use App\Services\Client\Input;
use App\Services\Reward;
use RuntimeException;

final class Settlement
{
    public static function complete(string $tradeNo, ?string $amount = null, ?string $gateway = null): void
    {
        Transaction::run(static function () use ($tradeNo, $amount, $gateway): void {
            $payment = Paylist::where('tradeno', $tradeNo)->first();
            if ($payment === null) {
                throw new RuntimeException('Unknown payment');
            }
            $user = User::where('id', $payment->userid)->lockForUpdate()->first();
            $invoice = Invoice::where('id', $payment->invoice_id)->lockForUpdate()->first();
            $payment = Paylist::where('id', $payment->id)->lockForUpdate()->first();
            if ($user === null || $invoice === null || (int) $invoice->user_id !== (int) $user->id ||
                $gateway !== null && ! str_starts_with($payment->gateway, $gateway)) {
                throw new RuntimeException('Payment ownership or gateway mismatch');
            }
            $expected = Input::storedMoney($payment->getRawOriginal('total'));
            if ($amount !== null && bccomp(Input::money($amount), $expected, 2) !== 0) {
                throw new RuntimeException('Payment amount mismatch');
            }
            if ((int) $payment->status === 1) {
                return;
            }
            $due = Input::storedMoney($invoice->getRawOriginal('price'));
            $payable = in_array($invoice->status, ['unpaid', 'partially_paid'], true);
            if ($payable && bccomp($expected, $due, 2) < 0) {
                throw new RuntimeException('Underpaid invoice');
            }
            $payment->status = 1;
            $payment->datetime = time();
            $payment->save();
            $credit = $payable ? bcsub($expected, $due, 2) : $expected;
            if ($payable) {
                $invoice->status = 'paid_gateway';
                $invoice->update_time = time();
                $invoice->pay_time = time();
                $invoice->save();
            }
            if (bccomp($credit, '0', 2) > 0) {
                $before = Input::storedMoney($user->getRawOriginal('money'));
                $user->money = bcadd($before, $credit, 2);
                $user->save();
                (new UserMoneyLog())->add($user->id, (float) $before, (float) $user->money, (float) $credit, 'Payment surplus/late payment #' . $invoice->id);
            }
            if ($payable && $user->ref_by > 0 && Config::obtain('invite_mode') === 'reward') {
                User::where('id', $user->ref_by)->lockForUpdate()->first();
                Reward::issuePaybackReward($user->id, $user->ref_by, (float) $due, $invoice->id);
            }
        });
    }

    public static function activateTopup(int $orderId): void
    {
        Transaction::run(static function () use ($orderId): void {
            $record = Order::find($orderId);
            if ($record === null) {
                return;
            }
            $user = User::where('id', $record->user_id)->lockForUpdate()->first();
            $order = Order::where('id', $orderId)->lockForUpdate()->first();
            if ($user === null || $order->status !== 'pending_activation' || $order->product_type !== 'topup') {
                return;
            }
            $invoice = Invoice::where('order_id', $orderId)->first();
            if ($invoice === null || ! in_array($invoice->status, ['paid_gateway', 'paid_admin'], true)) {
                return;
            }
            $content = json_decode($order->product_content, true, 512, JSON_THROW_ON_ERROR);
            $amount = Input::storedMoney($content['amount']);
            $before = Input::storedMoney($user->getRawOriginal('money'));
            $user->money = bcadd($before, $amount, 2);
            $user->save();
            $order->status = 'activated';
            $order->update_time = time();
            $order->save();
            (new UserMoneyLog())->add($user->id, (float) $before, (float) $user->money, (float) $amount, 'Top-up order #' . $order->id);
        });
    }
}
