<?php

namespace Tests\Unit;

use App\Events\PaymentInitiated;
use App\Gateways\Payments\EcpayPaymentGateway;
use App\Gateways\Payments\PaymentGatewayManager;
use App\Listeners\BuildPaymentCheckoutPayload;
use App\Models\PaymentTransaction;
use App\Repositories\PaymentTransactionRepository;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

class BuildPaymentCheckoutPayloadTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    /**
     * PaymentInitiated: listener 應同步建立 payload，讓後續付款流程可立即取得 checkout payload。
     */
    public function test_listener_should_not_be_queued(): void
    {
        $listener = new BuildPaymentCheckoutPayload(
            new PaymentTransactionRepository,
            Mockery::mock(PaymentGatewayManager::class),
            Mockery::mock(LoggerInterface::class),
        );

        $this->assertNotInstanceOf(ShouldQueue::class, $listener);
    }

    /**
     * PaymentInitiated: 應建立付款 request payload 與 checkout payload，且不寫入 response payload。
     */
    public function test_handle_builds_payment_request_and_checkout_payload(): void
    {
        $paymentTransaction = PaymentTransaction::factory()->create([
            'request_payload' => null,
            'checkout_payload' => null,
            'response_payload' => null,
        ]);
        $paymentRequest = [
            'action' => 'https://payment-stage.ecpay.com.tw/Cashier/AioCheckOut/V5',
            'method' => 'POST',
            'params' => [
                'MerchantID' => '3002599',
                'MerchantTradeNo' => $paymentTransaction->merchant_trade_no,
                'CheckMacValue' => 'CHECK_MAC_VALUE',
            ],
        ];

        $ecpayPaymentGateway = Mockery::mock(EcpayPaymentGateway::class);
        $ecpayPaymentGateway->shouldReceive('buildPaymentRequest')
            ->once()
            ->with(Mockery::on(fn ($argument): bool => $argument instanceof PaymentTransaction && $argument->id === $paymentTransaction->id))
            ->andReturn($paymentRequest);
        $paymentGatewayManager = Mockery::mock(PaymentGatewayManager::class);
        $paymentGatewayManager->shouldReceive('driver')
            ->once()
            ->with($paymentTransaction->provider)
            ->andReturn($ecpayPaymentGateway);

        $logger = Mockery::mock(LoggerInterface::class);
        $logger->shouldReceive('info')
            ->once()
            ->with('Payment checkout payload is ready.', Mockery::on(fn (array $context): bool => $context['payment_transaction_id'] === $paymentTransaction->id));

        $listener = new BuildPaymentCheckoutPayload(
            new PaymentTransactionRepository,
            $paymentGatewayManager,
            $logger,
        );

        $listener->handle(new PaymentInitiated($paymentTransaction->id));

        $paymentTransaction->refresh();

        $this->assertEquals($paymentRequest['params'], $paymentTransaction->request_payload);
        $this->assertSame([
            'action' => $paymentRequest['action'],
            'method' => $paymentRequest['method'],
        ], $paymentTransaction->checkout_payload);
        $this->assertNull($paymentTransaction->response_payload);
    }

    /**
     * PaymentInitiated: 找不到付款交易時應拋出例外，讓 queue job 可被 retry / failed job 追蹤。
     */
    public function test_handle_throws_model_not_found_exception_when_payment_transaction_is_missing(): void
    {
        $paymentGatewayManager = Mockery::mock(PaymentGatewayManager::class);
        $paymentGatewayManager->shouldReceive('driver')->never();

        $logger = Mockery::mock(LoggerInterface::class);
        $logger->shouldReceive('info')->never();

        $listener = new BuildPaymentCheckoutPayload(
            new PaymentTransactionRepository,
            $paymentGatewayManager,
            $logger,
        );

        $this->expectException(ModelNotFoundException::class);

        $listener->handle(new PaymentInitiated(999999));
    }
}
