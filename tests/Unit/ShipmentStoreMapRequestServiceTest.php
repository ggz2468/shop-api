<?php

namespace Tests\Unit;

use App\Enums\Shipment\Provider;
use App\Enums\ShipmentStoreMapRequest\StoreType;
use App\Models\Member;
use App\Models\ShipmentStoreMapRequest;
use App\Repositories\ShipmentStoreMapRequestRepository;
use App\Services\ShipmentStoreMapRequestService;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Mockery;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use Tests\TestCase;

class ShipmentStoreMapRequestServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Mockery::close();

        parent::tearDown();
    }

    /**
     * 超商電子地圖選擇請求: 商店交易編號應沿用訂單編號尾碼格式，僅替換 prefix。
     */
    public function test_make_merchant_trade_no_uses_order_number_suffix_style_with_smr_prefix(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-10 12:34:56'));

        $shipmentStoreMapRequestRepository = Mockery::mock(ShipmentStoreMapRequestRepository::class);
        $shipmentStoreMapRequestRepository->shouldReceive('exists')
            ->once()
            ->with(Mockery::on(fn (array $conditions): bool => count($conditions) === 2
                && $conditions[0] === 'merchant_trade_no'
                && is_string($conditions[1])
                && preg_match('/^SMR20260910[0-9A-Z]{6}$/', $conditions[1]) === 1
            ))
            ->andReturnFalse();

        $service = new ShipmentStoreMapRequestService(
            $shipmentStoreMapRequestRepository,
            Mockery::mock(ConfigRepository::class),
            Mockery::mock(LoggerInterface::class),
        );

        $method = new ReflectionMethod($service, 'makeMerchantTradeNo');
        $merchantTradeNo = $method->invoke($service);

        $this->assertMatchesRegularExpression('/^SMR20260910[0-9A-Z]{6}$/', $merchantTradeNo);
        $this->assertSame(17, strlen($merchantTradeNo));
    }

    /**
     * 超商電子地圖選擇請求: 應建立選擇請求並回傳前端導向綠界電子地圖所需 payload。
     */
    public function test_create_creates_store_map_request_and_returns_checkout_and_request_payload(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-10 12:34:56'));
        config()->set('services.shipment.default_provider', Provider::ECPAY_LOGISTICS->value);
        config()->set('services.ecpay_logistics.merchant_id', '2000132');
        config()->set('services.ecpay_logistics.hash_key', 'XBERn1YOvpM9nfZc');
        config()->set('services.ecpay_logistics.hash_iv', 'h1ONHk4P4yqbl5LK');
        config()->set('services.ecpay_logistics.store_map_action_url', 'https://logistics-stage.ecpay.com.tw/Express/map');
        config()->set('services.ecpay_logistics.store_map_server_reply_url', 'http://localhost/api/shipment-store-map-callbacks/ecpay');
        config()->set('services.ecpay_logistics.store_map_request_ttl_minutes', 30);
        $member = Member::factory()->create();

        $logger = Mockery::mock(LoggerInterface::class);
        $logger->shouldNotReceive('error');

        $service = new ShipmentStoreMapRequestService(
            new ShipmentStoreMapRequestRepository,
            $this->app->make(ConfigRepository::class),
            $logger,
        );

        $result = $service->create($member->id, StoreType::UNIMART->value, 1);

        $shipmentStoreMapRequest = ShipmentStoreMapRequest::query()->firstOrFail();
        $this->assertSame(201, $result['status']);
        $this->assertSame($shipmentStoreMapRequest->id, $result['data']['id']);
        $this->assertSame($shipmentStoreMapRequest->selection_token, $result['data']['selection_token']);
        $this->assertSame(Provider::ECPAY_LOGISTICS->value, $result['data']['provider']);
        $this->assertSame(StoreType::UNIMART, $result['data']['store_type']);
        $this->assertTrue($result['data']['ready']);
        $this->assertTrue(Carbon::parse('2026-09-10 13:04:56')->equalTo($result['data']['expires_at']));
        $this->assertTrue(Carbon::parse('2026-09-10 13:04:56')->equalTo($shipmentStoreMapRequest->expires_at));
        $this->assertSame('https://logistics-stage.ecpay.com.tw/Express/map', $result['data']['checkout_payload']['action']);
        $this->assertSame('POST', $result['data']['checkout_payload']['method']);
        $this->assertSame('2000132', $result['data']['request_payload']['MerchantID']);
        $this->assertMatchesRegularExpression('/^SMR20260910[0-9A-Z]{6}$/', $result['data']['request_payload']['MerchantTradeNo']);
        $this->assertSame('CVS', $result['data']['request_payload']['LogisticsType']);
        $this->assertSame('UNIMARTC2C', $result['data']['request_payload']['LogisticsSubType']);
        $this->assertSame('N', $result['data']['request_payload']['IsCollection']);
        $this->assertSame('http://localhost/api/shipment-store-map-callbacks/ecpay', $result['data']['request_payload']['ServerReplyURL']);
        $this->assertSame($shipmentStoreMapRequest->selection_token, $result['data']['request_payload']['ExtraData']);
        $this->assertSame(1, $result['data']['request_payload']['Device']);
        $this->assertMatchesRegularExpression('/^[A-F0-9]{32}$/', $result['data']['request_payload']['CheckMacValue']);

        $this->assertDatabaseHas('shipment_store_map_requests', [
            'id' => $shipmentStoreMapRequest->id,
            'member_id' => $member->id,
            'provider' => Provider::ECPAY_LOGISTICS->value,
            'store_type' => StoreType::UNIMART->value,
            'merchant_trade_no' => $result['data']['request_payload']['MerchantTradeNo'],
            'selection_token' => $shipmentStoreMapRequest->selection_token,
        ]);
    }

    /**
     * 超商電子地圖選擇請求: 過期時間應使用設定的 TTL，且至少保留一分鐘。
     */
    public function test_create_uses_configured_ttl_minutes_with_one_minute_minimum(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-10 12:34:56'));
        config()->set('services.shipment.default_provider', Provider::ECPAY_LOGISTICS->value);
        config()->set('services.ecpay_logistics.merchant_id', '2000132');
        config()->set('services.ecpay_logistics.hash_key', 'XBERn1YOvpM9nfZc');
        config()->set('services.ecpay_logistics.hash_iv', 'h1ONHk4P4yqbl5LK');
        config()->set('services.ecpay_logistics.store_map_action_url', 'https://logistics-stage.ecpay.com.tw/Express/map');
        config()->set('services.ecpay_logistics.store_map_server_reply_url', 'http://localhost/api/shipment-store-map-callbacks/ecpay');
        config()->set('services.ecpay_logistics.store_map_request_ttl_minutes', 0);
        $member = Member::factory()->create();

        $logger = Mockery::mock(LoggerInterface::class);
        $logger->shouldNotReceive('error');

        $service = new ShipmentStoreMapRequestService(
            new ShipmentStoreMapRequestRepository,
            $this->app->make(ConfigRepository::class),
            $logger,
        );

        $result = $service->create($member->id, StoreType::FAMI->value);

        $this->assertSame(201, $result['status']);
        $this->assertTrue(Carbon::parse('2026-09-10 12:35:56')->equalTo($result['data']['expires_at']));
        $this->assertTrue(Carbon::parse('2026-09-10 12:35:56')->equalTo(ShipmentStoreMapRequest::query()->firstOrFail()->expires_at));
    }

    /**
     * 超商電子地圖選擇請求: 未指定裝置類型時，應預設使用桌機版電子地圖。
     */
    public function test_create_defaults_device_to_zero(): void
    {
        $this->setValidEcpayLogisticsConfig();
        $member = Member::factory()->create();

        $logger = Mockery::mock(LoggerInterface::class);
        $logger->shouldNotReceive('error');

        $service = new ShipmentStoreMapRequestService(
            new ShipmentStoreMapRequestRepository,
            $this->app->make(ConfigRepository::class),
            $logger,
        );

        $result = $service->create($member->id, StoreType::FAMI->value);

        $this->assertSame(201, $result['status']);
        $this->assertSame(0, $result['data']['request_payload']['Device']);
    }

    /**
     * 超商電子地圖選擇請求: 應依超商類型對應綠界物流子類型。
     */
    public function test_resolve_logistics_sub_type_maps_all_supported_store_types(): void
    {
        $service = $this->makeService();
        $method = new ReflectionMethod($service, 'resolveLogisticsSubType');

        $cases = [
            StoreType::UNIMART->value => 'UNIMARTC2C',
            StoreType::FAMI->value => 'FAMIC2C',
            StoreType::HILIFE->value => 'HILIFEC2C',
        ];

        foreach ($cases as $storeType => $logisticsSubType) {
            $this->assertSame($logisticsSubType, $method->invoke($service, $storeType));
        }
    }

    /**
     * 超商電子地圖選擇請求: 缺少綠界物流必填設定時，應回傳明確錯誤且不建立資料。
     */
    public function test_create_returns_error_when_required_ecpay_logistics_config_is_missing(): void
    {
        $requiredConfigKeys = [
            'services.ecpay_logistics.merchant_id',
            'services.ecpay_logistics.hash_key',
            'services.ecpay_logistics.hash_iv',
            'services.ecpay_logistics.store_map_action_url',
            'services.ecpay_logistics.store_map_server_reply_url',
        ];

        foreach ($requiredConfigKeys as $configKey) {
            $this->setValidEcpayLogisticsConfig();
            config()->set($configKey, '');
            $member = Member::factory()->create();

            $logger = Mockery::mock(LoggerInterface::class);
            $logger->shouldReceive('error')
                ->once()
                ->with("{$configKey} is not configured.", Mockery::type('array'));

            $service = new ShipmentStoreMapRequestService(
                new ShipmentStoreMapRequestRepository,
                $this->app->make(ConfigRepository::class),
                $logger,
            );

            $result = $service->create($member->id, StoreType::UNIMART->value);

            $this->assertSame(500, $result['status']);
            $this->assertSame("{$configKey} is not configured.", $result['message']);
            $this->assertDatabaseMissing('shipment_store_map_requests', [
                'member_id' => $member->id,
            ]);
        }
    }

    /**
     * 超商電子地圖選擇請求: 綠界物流檢查碼應使用物流 HashKey 與 HashIV 產生。
     */
    public function test_make_check_mac_value_uses_ecpay_logistics_credentials(): void
    {
        config()->set('services.ecpay_logistics.hash_key', 'XBERn1YOvpM9nfZc');
        config()->set('services.ecpay_logistics.hash_iv', 'h1ONHk4P4yqbl5LK');

        $service = $this->makeService();
        $payload = [
            'MerchantID' => '2000132',
            'MerchantTradeNo' => 'SMR20260910A1B2C3',
            'LogisticsType' => 'CVS',
            'LogisticsSubType' => 'UNIMARTC2C',
            'IsCollection' => 'N',
            'ServerReplyURL' => 'http://localhost/api/shipment-store-map-callbacks/ecpay',
            'ExtraData' => '01J3QS2AJMZV09DNXQ2EE4NM2E',
            'Device' => 1,
        ];

        $method = new ReflectionMethod($service, 'makeCheckMacValue');
        $checkMacValue = $method->invoke($service, $payload);

        $this->assertSame($this->makeExpectedCheckMacValue($payload), $checkMacValue);
        $this->assertMatchesRegularExpression('/^[A-F0-9]{32}$/', $checkMacValue);
    }

    /**
     * 超商電子地圖選擇請求: 重新計算檢查碼時不應受既有 CheckMacValue 影響。
     */
    public function test_make_check_mac_value_ignores_existing_check_mac_value(): void
    {
        config()->set('services.ecpay_logistics.hash_key', 'XBERn1YOvpM9nfZc');
        config()->set('services.ecpay_logistics.hash_iv', 'h1ONHk4P4yqbl5LK');

        $service = $this->makeService();
        $payload = [
            'MerchantID' => '2000132',
            'MerchantTradeNo' => 'SMR20260910A1B2C3',
            'LogisticsType' => 'CVS',
        ];

        $method = new ReflectionMethod($service, 'makeCheckMacValue');
        $checkMacValue = $method->invoke($service, $payload);
        $recalculatedCheckMacValue = $method->invoke($service, array_merge($payload, [
            'CheckMacValue' => 'INVALID_CHECK_MAC_VALUE',
        ]));

        $this->assertSame($checkMacValue, $recalculatedCheckMacValue);
    }

    private function makeService(?ShipmentStoreMapRequestRepository $shipmentStoreMapRequestRepository = null): ShipmentStoreMapRequestService
    {
        return new ShipmentStoreMapRequestService(
            $shipmentStoreMapRequestRepository ?? Mockery::mock(ShipmentStoreMapRequestRepository::class),
            $this->app->make(ConfigRepository::class),
            Mockery::mock(LoggerInterface::class),
        );
    }

    private function setValidEcpayLogisticsConfig(): void
    {
        config()->set('services.shipment.default_provider', Provider::ECPAY_LOGISTICS->value);
        config()->set('services.ecpay_logistics.merchant_id', '2000132');
        config()->set('services.ecpay_logistics.hash_key', 'XBERn1YOvpM9nfZc');
        config()->set('services.ecpay_logistics.hash_iv', 'h1ONHk4P4yqbl5LK');
        config()->set('services.ecpay_logistics.store_map_action_url', 'https://logistics-stage.ecpay.com.tw/Express/map');
        config()->set('services.ecpay_logistics.store_map_server_reply_url', 'http://localhost/api/shipment-store-map-callbacks/ecpay');
        config()->set('services.ecpay_logistics.store_map_request_ttl_minutes', 30);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function makeExpectedCheckMacValue(array $payload): string
    {
        unset($payload['CheckMacValue']);

        uksort($payload, 'strcasecmp');

        $encoded = 'HashKey='.config('services.ecpay_logistics.hash_key')
            .'&'.urldecode(http_build_query($payload))
            .'&HashIV='.config('services.ecpay_logistics.hash_iv');

        $encoded = strtolower(urlencode($encoded));
        $encoded = str_replace(
            ['%2d', '%5f', '%2e', '%21', '%2a', '%28', '%29'],
            ['-', '_', '.', '!', '*', '(', ')'],
            $encoded,
        );

        return strtoupper(md5($encoded));
    }
}
