<?php

namespace Tests\Unit;

use App\Enums\Order\PaymentMethod;
use App\Enums\PaymentTransaction\PaymentMethod as PaymentTransactionPaymentMethod;
use App\Enums\PaymentTransaction\Provider;
use App\Enums\PaymentTransaction\Status;
use App\Events\OrderCreated;
use App\Events\PaymentInitiated;
use App\Listeners\CreatePaymentTransaction;
use App\Models\Order;
use App\Models\PaymentTransaction;
use App\Repositories\OrderRepository;
use App\Repositories\PaymentTransactionRepository;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class CreatePaymentTransactionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * OrderCreated: 建立付款交易應同步執行，避免訂單建立後立即查詢付款 checkout 時查無交易資料。
     */
    public function test_listener_creates_payment_transaction_synchronously(): void
    {
        $this->assertFalse(is_subclass_of(CreatePaymentTransaction::class, ShouldQueue::class));
    }

    /**
     * OrderCreated: 應建立待付款金流交易並 dispatch PaymentInitiated。
     */
    public function test_handle_creates_payment_transaction_and_dispatches_payment_initiated(): void
    {
        Event::fake([PaymentInitiated::class]);
        Carbon::setTestNow('2026-09-30 12:34:56');

        try {
            $order = Order::factory()->create([
                'number' => 'ORD20260905ABCDEFG',
                'total_amount' => 1280,
                'payment_method' => PaymentMethod::CREDIT_CARD->value,
            ]);

            $this->makeListener()->handle(new OrderCreated($order->id));

            $paymentTransaction = PaymentTransaction::query()->where('order_id', $order->id)->firstOrFail();
            $this->assertSame(Provider::ECPAY->value, $paymentTransaction->provider);
            $this->assertMatchesRegularExpression('/^PAY20260930[0-9A-Z]{6}$/', $paymentTransaction->merchant_trade_no);
            $this->assertSame(1280, $paymentTransaction->amount);
            $this->assertSame('TWD', $paymentTransaction->currency);
            $this->assertSame(Status::PENDING->value, $paymentTransaction->status);
            $this->assertSame(PaymentTransactionPaymentMethod::CREDIT_CARD->value, $paymentTransaction->payment_method);
            $this->assertNull($paymentTransaction->request_payload);
            $this->assertNull($paymentTransaction->checkout_payload);
            $this->assertNull($paymentTransaction->response_payload);
            Event::assertDispatched(PaymentInitiated::class, fn (PaymentInitiated $event): bool => $event->paymentTransactionId === $paymentTransaction->id);
        } finally {
            Carbon::setTestNow();
        }
    }

    /**
     * OrderCreated: 每筆付款交易應產生唯一的商店交易編號。
     */
    public function test_handle_creates_unique_merchant_trade_no_for_each_payment_transaction(): void
    {
        Event::fake([PaymentInitiated::class]);
        Carbon::setTestNow('2026-09-30 12:34:56');

        try {
            $firstOrder = Order::factory()->create();
            $secondOrder = Order::factory()->create();

            $this->makeListener()->handle(new OrderCreated($firstOrder->id));
            $this->makeListener()->handle(new OrderCreated($secondOrder->id));

            $merchantTradeNos = PaymentTransaction::query()
                ->whereIn('order_id', [$firstOrder->id, $secondOrder->id])
                ->pluck('merchant_trade_no')
                ->all();

            $this->assertCount(2, $merchantTradeNos);
            $this->assertCount(2, array_unique($merchantTradeNos));
            foreach ($merchantTradeNos as $merchantTradeNo) {
                $this->assertMatchesRegularExpression('/^PAY20260930[0-9A-Z]{6}$/', $merchantTradeNo);
            }
        } finally {
            Carbon::setTestNow();
        }
    }

    /**
     * OrderCreated: 已存在金流交易時不應重複建立或重新 dispatch PaymentInitiated。
     */
    public function test_handle_does_not_create_duplicate_payment_transaction_when_one_already_exists(): void
    {
        Event::fake([PaymentInitiated::class]);

        $order = Order::factory()->create();
        PaymentTransaction::factory()->for($order)->create();

        $this->makeListener()->handle(new OrderCreated($order->id));

        $this->assertSame(1, PaymentTransaction::query()->where('order_id', $order->id)->count());
        Event::assertNotDispatched(PaymentInitiated::class);
    }

    /**
     * OrderCreated: 應使用系統設定的預設金流 provider。
     */
    public function test_handle_resolves_provider_from_default_payment_provider_config(): void
    {
        Event::fake([PaymentInitiated::class]);
        config(['services.payment.default_provider' => Provider::ECPAY->value]);

        $cases = [
            PaymentMethod::CREDIT_CARD,
            PaymentMethod::ATM,
            PaymentMethod::CVS,
            PaymentMethod::BARCODE,
        ];

        foreach ($cases as $paymentMethod) {
            $order = Order::factory()->create([
                'payment_method' => $paymentMethod->value,
            ]);

            $this->makeListener()->handle(new OrderCreated($order->id));

            $this->assertDatabaseHas('payment_transactions', [
                'order_id' => $order->id,
                'provider' => Provider::ECPAY->value,
                'payment_method' => $paymentMethod->value,
            ]);
        }
    }

    /**
     * OrderCreated: 找不到訂單時應拋出例外，讓 queue job 可重試或進 failed jobs。
     */
    public function test_handle_throws_model_not_found_exception_when_order_is_missing(): void
    {
        Event::fake([PaymentInitiated::class]);

        $this->expectException(ModelNotFoundException::class);

        $this->makeListener()->handle(new OrderCreated(999999));
    }

    private function makeListener(): CreatePaymentTransaction
    {
        return new CreatePaymentTransaction(
            new OrderRepository,
            new PaymentTransactionRepository,
            app('events'),
            app('config'),
        );
    }
}
