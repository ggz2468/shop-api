<?php

namespace Tests\Unit;

use App\Enums\Order\PaymentMethod as OrderPaymentMethod;
use App\Enums\PaymentTransaction\Provider;
use App\Models\Order;
use App\Models\PaymentTransaction;
use App\Repositories\PaymentTransactionRepository;
use App\Services\PaymentTransactionService;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\QueryException;
use Mockery;
use PDOException;
use Tests\TestCase;

class PaymentTransactionServiceTest extends TestCase
{
    /**
     * 付款交易: 商店交易編號在新增時發生唯一鍵衝突，應重新產生編號後重試。
     */
    public function test_create_retries_when_merchant_trade_no_unique_constraint_is_violated(): void
    {
        $order = new Order([
            'id' => 1,
            'total_amount' => 1280,
            'payment_method' => OrderPaymentMethod::CREDIT_CARD->value,
        ]);
        $paymentTransaction = new PaymentTransaction(['id' => 2]);
        $createAttempts = 0;
        $paymentTransactionRepository = Mockery::mock(PaymentTransactionRepository::class);
        $paymentTransactionRepository->shouldReceive('doesNotExist')
            ->twice()
            ->andReturn(true);
        $paymentTransactionRepository->shouldReceive('create')
            ->twice()
            ->andReturnUsing(function () use (&$createAttempts, $paymentTransaction): PaymentTransaction {
                $createAttempts++;

                if ($createAttempts === 1) {
                    throw $this->uniqueConstraintException();
                }

                return $paymentTransaction;
            });
        $config = Mockery::mock(ConfigRepository::class);
        $config->shouldReceive('get')
            ->once()
            ->with('services.payment.default_provider')
            ->andReturn(Provider::ECPAY->value);

        $result = (new PaymentTransactionService($paymentTransactionRepository, $config))->create($order);

        $this->assertSame($paymentTransaction, $result);
    }

    /**
     * 付款交易: 非重複鍵的資料庫 constraint failure 不應被誤判為商店交易編號衝突。
     */
    public function test_create_rethrows_non_duplicate_integrity_constraint_violation(): void
    {
        $order = new Order([
            'id' => 1,
            'total_amount' => 1280,
            'payment_method' => OrderPaymentMethod::CREDIT_CARD->value,
        ]);
        $exception = $this->foreignKeyConstraintException();
        $paymentTransactionRepository = Mockery::mock(PaymentTransactionRepository::class);
        $paymentTransactionRepository->shouldReceive('doesNotExist')
            ->once()
            ->andReturn(true);
        $paymentTransactionRepository->shouldReceive('create')
            ->once()
            ->andThrow($exception);
        $config = Mockery::mock(ConfigRepository::class);
        $config->shouldReceive('get')
            ->once()
            ->with('services.payment.default_provider')
            ->andReturn(Provider::ECPAY->value);

        $this->expectExceptionObject($exception);

        (new PaymentTransactionService($paymentTransactionRepository, $config))->create($order);
    }

    private function uniqueConstraintException(): QueryException
    {
        $exception = new QueryException(
            'mysql',
            'insert into `payment_transactions` (...) values (...)',
            [],
            new PDOException('Duplicate entry'),
        );
        $exception->errorInfo = ['23000', 1062, 'Duplicate entry'];

        return $exception;
    }

    private function foreignKeyConstraintException(): QueryException
    {
        $exception = new QueryException(
            'mysql',
            'insert into `payment_transactions` (...) values (...)',
            [],
            new PDOException('Cannot add or update a child row'),
        );
        $exception->errorInfo = ['23000', 1452, 'Cannot add or update a child row'];

        return $exception;
    }
}
