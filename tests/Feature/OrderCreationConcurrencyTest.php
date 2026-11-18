<?php

namespace Tests\Feature;

use App\Enums\Order\Status as OrderStatus;
use App\Enums\PaymentTransaction\Status as TransactionStatus;
use App\Models\Member;
use App\Models\ProductVariant;
use App\Stores\CartStore;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class OrderCreationConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    private string $originalConnection;

    private ConnectionInterface $connection;

    private string $cachePath;

    /** @var array<int, Member> */
    private array $members = [];

    /** @var array<int, ProductVariant> */
    private array $variants = [];

    /** @var array<int, array{process: Process, input: InputStream}> */
    private array $requests = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalConnection = config('database.default');
        $this->assertSame('mysql', DB::connection()->getDriverName());
        config()->set('database.connections.order_creation_concurrency', config('database.connections.'.$this->originalConnection));
        DB::purge('order_creation_concurrency');
        // Child processes cannot see fixtures inside RefreshDatabase's outer transaction.
        DB::setDefaultConnection('order_creation_concurrency');
        $this->connection = DB::connection();
        $this->cachePath = storage_path('framework/testing/order-creation-'.Str::uuid());
        config()->set('cache.default', 'file');
        config()->set('cache.stores.file.path', $this->cachePath);
        config()->set('cache.stores.file.lock_path', $this->cachePath);
        app('cache')->forgetDriver('file');
        $this->members[] = Member::factory()->create();
        $this->variants[] = ProductVariant::factory()->create(['stock_quantity' => 5]);
        $this->variants[] = ProductVariant::factory()->create(['stock_quantity' => 7]);
    }

    protected function tearDown(): void
    {
        try {
            foreach ($this->requests as $request) {
                if ($request['process']->isRunning()) {
                    $request['process']->stop();
                }
                $request['input']->close();
            }
            if (isset($this->connection)) {
                $orderIds = $this->connection->table('orders')->whereIn('member_id', array_map(fn (Member $member): int => $member->id, $this->members))->pluck('id');
                foreach (['shipments', 'payment_transactions', 'order_details'] as $table) {
                    $this->connection->table($table)->whereIn('order_id', $orderIds)->delete();
                }
                $this->connection->table('orders')->whereIn('id', $orderIds)->delete();
                foreach ($this->members as $member) {
                    $member->tokens()->delete();
                    $this->connection->table('members')->where('id', $member->id)->delete();
                }
                foreach ($this->variants as $variant) {
                    $this->connection->table('product_variants')->where('id', $variant->id)->delete();
                    $this->connection->table('products')->where('id', $variant->product_id)->delete();
                    $this->connection->table('product_specs')->where('id', $variant->product_spec_id)->delete();
                }
                $this->connection->disconnect();
            }
            if (isset($this->cachePath) && File::isDirectory($this->cachePath)) {
                File::deleteDirectory($this->cachePath);
            }
        } finally {
            if (isset($this->originalConnection)) {
                DB::setDefaultConnection($this->originalConnection);
            }
            parent::tearDown();
        }
    }

    /**
     * 並行結帳: 同會員相同 key 回傳同一訂單，不同 key 則拒絕再次消費已清空購物車，庫存及付款僅處理一次。
     */
    #[DataProvider('checkoutKeyProvider')]
    public function test_same_member_concurrent_checkouts_consume_the_shared_cart_once(bool $sameKey): void
    {
        $member = $this->members[0];
        foreach (array_reverse($this->variants) as $variant) {
            $this->assertNotFalse(app(CartStore::class)->storeItem($member->id, $variant->id, 2));
        }
        $first = $this->startCheckout($member, 'checkout-first', true);
        $this->waitForMarker($first, 'STOCK_WRITTEN');
        $second = $this->startCheckout($member, $sameKey ? 'checkout-first' : 'checkout-second');
        $this->waitForMarker($second, 'CHECKOUT_LOCK_BUSY');
        $this->assertStringNotContainsString('CHECKOUT_LOCKED', $second->getOutput());
        $this->assertStringNotContainsString('RESULT:', $second->getOutput());
        $this->requests[spl_object_id($first)]['input']->write("CONTINUE\n");

        $created = $this->response($first);
        $repeated = $this->response($second);

        $this->assertSame(201, $created['status']);
        $this->assertSame($sameKey ? 200 : 409, $repeated['status']);
        if ($sameKey) {
            $this->assertSame($created['body']['data']['id'], $repeated['body']['data']['id']);
        } else {
            $this->assertSame('購物車沒有可建立訂單的產品。', $repeated['body']['message']);
        }
        $this->assertSame(1, $created['order_created']);
        $this->assertSame(0, $repeated['order_created']);
        $this->assertSame(array_map(fn (ProductVariant $variant): int => $variant->id, $this->variants), $created['stock_writes']);
        $this->assertSame([], $repeated['stock_writes']);
        $this->assertSame(3, $this->variants[0]->refresh()->stock_quantity);
        $this->assertSame(5, $this->variants[1]->refresh()->stock_quantity);
        $this->assertSame([], app(CartStore::class)->getItems($member->id));
        $this->assertSingleCheckout($member, 2, 4);
    }

    public static function checkoutKeyProvider(): array
    {
        return [
            'same member and key' => [true],
            'same member with different keys' => [false],
        ];
    }

    /**
     * 並行搶庫存: 不同會員讀到最後一件商品時，atomic decrement 僅允許一方成功，敗方保留購物車且無訂單或付款。
     */
    public function test_different_members_cannot_both_purchase_the_last_item(): void
    {
        $this->members[] = Member::factory()->create();
        $variant = $this->variants[0];
        $variant->update(['stock_quantity' => 1]);
        foreach ($this->members as $member) {
            $this->assertNotFalse(app(CartStore::class)->storeItem($member->id, $variant->id, 1));
        }
        $loserCart = app(CartStore::class)->getItems($this->members[1]->id);
        $first = $this->startCheckout($this->members[0], 'last-stock-first', true);
        $this->waitForMarker($first, 'STOCK_WRITTEN');
        $second = $this->startCheckout($this->members[1], 'last-stock-second');
        $this->waitForMarker($second, 'BEFORE_STOCK_WRITE');
        $this->assertStringContainsString('CHECKOUT_LOCKED', $second->getOutput());
        $this->assertStringNotContainsString('STOCK_WRITTEN', $second->getOutput());
        $this->requests[spl_object_id($first)]['input']->write("CONTINUE\n");

        $winner = $this->response($first);
        $loser = $this->response($second);

        $this->assertSame(201, $winner['status']);
        $this->assertSame(409, $loser['status']);
        $this->assertSame('產品庫存不足。', $loser['body']['message']);
        $this->assertSame(1, $winner['order_created']);
        $this->assertSame(0, $loser['order_created']);
        $this->assertSame(0, $variant->refresh()->stock_quantity);
        $this->assertSame(7, $this->variants[1]->refresh()->stock_quantity);
        $this->assertSingleCheckout($this->members[0], 1, 1);
        $this->assertSame(0, $this->members[1]->orders()->count());
        $this->assertSame([], app(CartStore::class)->getItems($this->members[0]->id));
        $this->assertSame($loserCart, app(CartStore::class)->getItems($this->members[1]->id));
    }

    private function assertSingleCheckout(Member $member, int $details, int $quantity): void
    {
        $order = $member->orders()->sole();
        $this->assertSame(OrderStatus::STOCKING->value, $order->status);
        $this->assertSame($details, $order->orderDetails()->count());
        $this->assertSame($quantity, (int) $order->orderDetails()->sum('quantity'));
        $this->assertSame(TransactionStatus::PENDING->value, $order->paymentTransactions()->sole()->status);
        $this->assertSame(0, $order->shipments()->count());
    }

    private function startCheckout(Member $member, string $key, bool $pause = false): Process
    {
        $script = <<<'PHP'
require $argv[1].'/vendor/autoload.php';
$app = require $argv[1].'/bootstrap/app.php';
$kernel = $app->make(\Illuminate\Contracts\Http\Kernel::class);
$kernel->bootstrap();
config()->set(json_decode($argv[2], true, flags: JSON_THROW_ON_ERROR));
\Illuminate\Support\Facades\Notification::fake();
\Illuminate\Support\Facades\Http::preventStrayRequests();
// Observe real file-backed cache locks without replacing their acquisition or release behavior.
app('cache')->extend('observed-file', function ($app, $config) {
    $store = new class($app['files'], $config['path']) extends \Illuminate\Cache\FileStore {
        public function lock($name, $seconds = 0, $owner = null)
        {
            return new class($this, "file-store-lock:{$name}", $seconds, $owner) extends \Illuminate\Cache\FileLock {
                public function acquire()
                {
                    $acquired = parent::acquire();
                    echo $acquired ? "CHECKOUT_LOCKED\n" : "CHECKOUT_LOCK_BUSY\n";
                    flush();
                    return $acquired;
                }
            };
        }
    };
    return new \Illuminate\Cache\Repository($store);
});
$stockWrites = [];
$created = 0;
$paused = false;
\Illuminate\Support\Facades\Event::listen(\App\Events\OrderCreated::class, static function () use (&$created): void {
    $created++;
});
\Illuminate\Support\Facades\DB::connection()->statement('SET SESSION innodb_lock_wait_timeout = 10');
\Illuminate\Support\Facades\DB::connection()->beforeExecuting(static function ($sql): void {
    if (preg_match('/^update `product_variants` /i', $sql)) {
        echo "BEFORE_STOCK_WRITE\n";
        flush();
    }
});
\Illuminate\Support\Facades\DB::listen(static function ($query) use (&$stockWrites, &$paused, $argv): void {
    if (! preg_match('/^update `product_variants` /i', $query->sql)) {
        return;
    }
    if ($query->connection->transactionLevel() < 1) {
        throw new \RuntimeException('Stock writes must run inside a transaction.');
    }
    if (! preg_match('/`id`\s*=\s*\?/', $query->sql, $match, PREG_OFFSET_CAPTURE)) {
        throw new \RuntimeException('Cannot identify stock write target.');
    }
    $index = substr_count(substr($query->sql, 0, $match[0][1]), '?');
    $stockWrites[] = (int) $query->bindings[$index];
    echo "STOCK_WRITTEN\n";
    flush();
    if ($argv[5] === 'pause' && ! $paused) {
        $paused = true;
        $read = [STDIN];
        $write = $except = [];
        if (stream_select($read, $write, $except, 10) !== 1 || trim((string) fgets(STDIN)) !== 'CONTINUE') {
            throw new \RuntimeException('Checkout synchronization gate was not released.');
        }
    }
});
$request = \Illuminate\Http\Request::create('/api/orders', 'POST', [
    'payment_method' => \App\Enums\Order\PaymentMethod::CREDIT_CARD->value,
    'shipping_method' => \App\Enums\Order\ShippingMethod::HOME_DELIVERY->value,
    'recipient_name' => 'Checkout Member',
    'recipient_phone' => '0912345678',
    'recipient_zip_code' => '100',
    'recipient_address' => 'Test checkout address',
], [], [], [
    'HTTP_ACCEPT' => 'application/json',
    'HTTP_AUTHORIZATION' => 'Bearer '.$argv[3],
    'HTTP_IDEMPOTENCY_KEY' => $argv[4],
]);
$response = $kernel->handle($request);
$kernel->terminate($request, $response);
echo 'RESULT:'.json_encode([
    'status' => $response->getStatusCode(),
    'body' => json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR),
    'stock_writes' => $stockWrites,
    'order_created' => $created,
], JSON_THROW_ON_ERROR)."\n";
PHP;
        $config = [
            'app.debug' => false,
            'cache.default' => 'file',
            'cache.stores.file.driver' => 'observed-file',
            'cache.stores.file.path' => $this->cachePath,
            'queue.default' => 'sync',
            'session.driver' => 'array',
            'services.payment.default_provider' => \App\Enums\PaymentTransaction\Provider::ECPAY->value,
            'services.ecpay.merchant_id' => '3002599',
            'services.ecpay.hash_key' => 'spPjZn66i0OhqJsQ',
            'services.ecpay.hash_iv' => 'hT5OJckN45isQTTs',
            'services.ecpay.payment_action_url' => 'https://payment-stage.ecpay.com.tw/Cashier/AioCheckOut/V5',
            'services.ecpay.return_url' => 'http://localhost/api/payment-callbacks/ecpay',
        ];
        $input = new InputStream;
        $process = new Process([
            PHP_BINARY, '-r', $script, base_path(),
            json_encode($config, JSON_THROW_ON_ERROR),
            $member->createToken('checkout-concurrency')->plainTextToken,
            $key, $pause ? 'pause' : 'continue',
        ]);
        $process->setInput($input);
        $process->setTimeout(20);
        $process->start();
        $this->requests[spl_object_id($process)] = ['process' => $process, 'input' => $input];

        return $process;
    }

    private function waitForMarker(Process $process, string $marker): void
    {
        do {
            $process->checkTimeout();
            $running = $process->isRunning();
            $output = $process->getOutput();
            if (str_contains($output, $marker) || str_contains($output, 'RESULT:') || $process->getErrorOutput() !== '' || ! $running) {
                break;
            }
            usleep(1000);
        } while (true);

        $this->assertStringContainsString($marker, $output, 'Exit: '.var_export($process->getExitCode(), true).'; stdout: '.$process->getOutput().'; stderr: '.$process->getErrorOutput());
        $this->assertTrue($process->isRunning());
    }

    private function response(Process $process): array
    {
        $this->requests[spl_object_id($process)]['input']->close();
        $process->wait();
        $this->assertTrue($process->isSuccessful(), $process->getOutput().$process->getErrorOutput());
        $this->assertSame(1, preg_match('/^RESULT:(.+)$/m', $process->getOutput(), $matches), $process->getOutput());

        return json_decode($matches[1], true, flags: JSON_THROW_ON_ERROR);
    }
}
