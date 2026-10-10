<?php

namespace Tests\Feature;

use App\Enums\Order\PaymentMethod;
use App\Enums\Order\PaymentStatus;
use App\Enums\Order\Status as OrderStatus;
use App\Enums\PaymentTransaction\Status as PaymentTransactionStatus;
use App\Models\Member;
use App\Models\Order;
use App\Models\PaymentTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class OrderPaymentTransactionConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    private const string CONNECTION = 'payment_transaction_concurrency';

    /**
     * 建立付款交易: 第二個並行 HTTP request 必須等待 order lock，並在第一筆交易建立後回傳衝突。
     */
    public function test_concurrent_request_waits_for_order_lock_and_does_not_create_a_second_active_payment_transaction(): void
    {
        $connection = $this->concurrencyConnection();
        $member = Member::factory()->make();
        $member->setConnection(self::CONNECTION)->save();
        $order = Order::factory()->for($member)->make([
            'status' => OrderStatus::STOCKING->value,
            'payment_method' => PaymentMethod::CREDIT_CARD->value,
            'payment_status' => PaymentStatus::UNPAID->value,
        ]);
        $order->setConnection(self::CONNECTION)->save();
        $token = $member->createToken('payment-transaction-concurrency-test')->plainTextToken;
        $process = null;

        $connection->beginTransaction();

        try {
            Order::on(self::CONNECTION)->lockForUpdate()->findOrFail($order->id);

            $process = $this->startConcurrentRequest($order, $token);
            $this->waitForProcessOutput($process, 'READY');

            usleep(200000);
            $this->assertTrue($process->isRunning(), 'Concurrent request should wait for the order lock.');

            $paymentTransaction = PaymentTransaction::factory()->for($order)->make([
                'merchant_trade_no' => 'PAY20261010LOCK01',
                'status' => PaymentTransactionStatus::PENDING->value,
            ]);
            $paymentTransaction->setConnection(self::CONNECTION)->save();
            $connection->commit();

            $process->wait();
            $response = json_decode($this->processResponse($process), true, flags: JSON_THROW_ON_ERROR);

            $this->assertSame(409, $response['status']);
            $this->assertSame('已有進行中的付款交易。', $response['body']['message']);
            $this->assertSame(1, PaymentTransaction::on(self::CONNECTION)->where('order_id', $order->id)->count());
        } finally {
            if ($connection->transactionLevel() > 0) {
                $connection->rollBack();
            }

            if ($process instanceof Process && $process->isRunning()) {
                $process->stop();
            }

            $this->deleteConcurrencyTestData($member->id, $order->id);
        }
    }

    private function concurrencyConnection(): \Illuminate\Database\ConnectionInterface
    {
        config()->set('database.connections.'.self::CONNECTION, config('database.connections.'.config('database.default')));
        DB::purge(self::CONNECTION);

        return DB::connection(self::CONNECTION);
    }

    private function deleteConcurrencyTestData(int $memberId, int $orderId): void
    {
        $connection = DB::connection(self::CONNECTION);
        $connection->table('payment_transactions')->where('order_id', $orderId)->delete();
        $connection->table('orders')->where('id', $orderId)->delete();
        $connection->table('personal_access_tokens')->where('tokenable_type', Member::class)->where('tokenable_id', $memberId)->delete();
        $connection->table('members')->where('id', $memberId)->delete();
        $connection->disconnect();
    }

    private function startConcurrentRequest(Order $order, string $token): Process
    {
        $script = <<<'PHP'
require $argv[1].'/vendor/autoload.php';
$app = require $argv[1].'/bootstrap/app.php';
$kernel = $app->make(\Illuminate\Contracts\Http\Kernel::class);

echo "READY\n";
flush();

$request = \Illuminate\Http\Request::create($argv[2], 'POST', [], [], [], [
    'HTTP_ACCEPT' => 'application/json',
    'HTTP_AUTHORIZATION' => 'Bearer '.$argv[3],
]);
$response = $kernel->handle($request);

echo json_encode([
    'status' => $response->getStatusCode(),
    'body' => json_decode($response->getContent(), true),
], JSON_THROW_ON_ERROR);
PHP;

        $process = new Process([
            PHP_BINARY,
            '-r',
            $script,
            base_path(),
            "/api/orders/{$order->id}/payment-transactions",
            $token,
        ]);
        $process->start();

        return $process;
    }

    private function waitForProcessOutput(Process $process, string $expectedOutput): void
    {
        $output = '';

        for ($attempt = 0; $attempt < 200; $attempt++) {
            $output .= $process->getIncrementalOutput();

            if (str_contains($output, $expectedOutput)) {
                return;
            }

            usleep(10000);
        }

        $this->fail("Concurrent request did not produce {$expectedOutput}. Output: {$output}");
    }

    private function processResponse(Process $process): string
    {
        return trim(str_replace('READY', '', $process->getOutput()));
    }
}
