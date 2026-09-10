<?php

namespace Tests\Feature;

use App\Enums\Shipment\Provider;
use App\Enums\ShipmentStoreMapRequest\StoreType;
use App\Models\Member;
use App\Models\ShipmentStoreMapRequest;
use App\Services\ShipmentStoreMapRequestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

class ShipmentStoreMapRequestControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Mockery::close();

        parent::tearDown();
    }

    /**
     * 超商電子地圖選擇請求: 未驗證的訪客無法建立選擇請求。
     */
    public function test_guest_cannot_create_shipment_store_map_request(): void
    {
        $response = $this->postJson('/api/shipment-store-map-requests', [
            'store_type' => StoreType::UNIMART->value,
        ]);

        $response->assertStatus(401);
    }

    /**
     * 超商電子地圖選擇請求: 建立選擇請求時必須提供超商類型。
     */
    public function test_store_returns_422_when_store_type_is_missing(): void
    {
        $member = Member::factory()->create();
        Sanctum::actingAs($member);

        $shipmentStoreMapRequestService = Mockery::mock(ShipmentStoreMapRequestService::class);
        $shipmentStoreMapRequestService->shouldReceive('create')->never();

        $this->app->instance(ShipmentStoreMapRequestService::class, $shipmentStoreMapRequestService);

        $response = $this->postJson('/api/shipment-store-map-requests');

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['store_type']);
    }

    /**
     * 超商電子地圖選擇請求: 超商類型必須是系統支援的超商。
     */
    public function test_store_returns_422_when_store_type_is_invalid(): void
    {
        $member = Member::factory()->create();
        Sanctum::actingAs($member);

        $shipmentStoreMapRequestService = Mockery::mock(ShipmentStoreMapRequestService::class);
        $shipmentStoreMapRequestService->shouldReceive('create')->never();

        $this->app->instance(ShipmentStoreMapRequestService::class, $shipmentStoreMapRequestService);

        foreach (['', 'invalid', 123] as $storeType) {
            $response = $this->postJson('/api/shipment-store-map-requests', [
                'store_type' => $storeType,
            ]);

            $response->assertStatus(422)
                ->assertJsonValidationErrors(['store_type']);
        }
    }

    /**
     * 超商電子地圖選擇請求: 裝置類型必須是綠界電子地圖支援的值。
     */
    public function test_store_returns_422_when_device_is_invalid(): void
    {
        $member = Member::factory()->create();
        Sanctum::actingAs($member);

        $shipmentStoreMapRequestService = Mockery::mock(ShipmentStoreMapRequestService::class);
        $shipmentStoreMapRequestService->shouldReceive('create')->never();

        $this->app->instance(ShipmentStoreMapRequestService::class, $shipmentStoreMapRequestService);

        foreach ([-1, 2, 'mobile'] as $device) {
            $response = $this->postJson('/api/shipment-store-map-requests', [
                'store_type' => StoreType::UNIMART->value,
                'device' => $device,
            ]);

            $response->assertStatus(422)
                ->assertJsonValidationErrors(['device']);
        }
    }

    /**
     * 超商電子地圖選擇請求: 建立成功後回傳電子地圖 checkout payload 與 request payload。
     */
    public function test_store_returns_checkout_and_request_payload(): void
    {
        $member = Member::factory()->create();
        Sanctum::actingAs($member);

        $shipmentStoreMapRequestService = Mockery::mock(ShipmentStoreMapRequestService::class);
        $shipmentStoreMapRequestService->shouldReceive('create')
            ->once()
            ->with($member->id, StoreType::UNIMART->value, 1)
            ->andReturn([
                'status' => 201,
                'data' => [
                    'id' => 1,
                    'selection_token' => '01J3QS2AJMZV09DNXQ2EE4NM2E',
                    'provider' => Provider::ECPAY_LOGISTICS->value,
                    'store_type' => StoreType::UNIMART->value,
                    'ready' => true,
                    'expires_at' => '2026-09-10T13:04:56.000000Z',
                    'checkout_payload' => [
                        'action' => 'https://logistics-stage.ecpay.com.tw/Express/map',
                        'method' => 'POST',
                    ],
                    'request_payload' => [
                        'MerchantID' => '2000132',
                        'MerchantTradeNo' => 'SMR20260910A1B2C3',
                        'LogisticsType' => 'CVS',
                        'LogisticsSubType' => 'UNIMARTC2C',
                        'IsCollection' => 'N',
                        'ServerReplyURL' => 'http://localhost/api/shipment-store-map-callbacks/ecpay',
                        'ExtraData' => '01J3QS2AJMZV09DNXQ2EE4NM2E',
                        'Device' => 1,
                        'CheckMacValue' => 'CHECK_MAC_VALUE',
                    ],
                ],
            ]);

        $this->app->instance(ShipmentStoreMapRequestService::class, $shipmentStoreMapRequestService);

        $response = $this->postJson('/api/shipment-store-map-requests', [
            'store_type' => StoreType::UNIMART->value,
            'device' => 1,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.id', 1)
            ->assertJsonPath('data.selection_token', '01J3QS2AJMZV09DNXQ2EE4NM2E')
            ->assertJsonPath('data.provider', Provider::ECPAY_LOGISTICS->value)
            ->assertJsonPath('data.store_type', StoreType::UNIMART->value)
            ->assertJsonPath('data.ready', true)
            ->assertJsonPath('data.expires_at', '2026-09-10T13:04:56.000000Z')
            ->assertJsonPath('data.checkout_payload.action', 'https://logistics-stage.ecpay.com.tw/Express/map')
            ->assertJsonPath('data.checkout_payload.method', 'POST')
            ->assertJsonPath('data.request_payload.MerchantID', '2000132')
            ->assertJsonPath('data.request_payload.MerchantTradeNo', 'SMR20260910A1B2C3')
            ->assertJsonPath('data.request_payload.LogisticsType', 'CVS')
            ->assertJsonPath('data.request_payload.LogisticsSubType', 'UNIMARTC2C')
            ->assertJsonPath('data.request_payload.IsCollection', 'N')
            ->assertJsonPath('data.request_payload.ServerReplyURL', 'http://localhost/api/shipment-store-map-callbacks/ecpay')
            ->assertJsonPath('data.request_payload.ExtraData', '01J3QS2AJMZV09DNXQ2EE4NM2E')
            ->assertJsonPath('data.request_payload.Device', 1)
            ->assertJsonPath('data.request_payload.CheckMacValue', 'CHECK_MAC_VALUE');
    }

    /**
     * 超商電子地圖選擇請求: 應透過真實 service 建立資料並回傳可提交至綠界電子地圖的 payload。
     */
    public function test_store_creates_request_with_real_service(): void
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
        Sanctum::actingAs($member);

        $response = $this->postJson('/api/shipment-store-map-requests', [
            'store_type' => StoreType::HILIFE->value,
        ]);

        $shipmentStoreMapRequest = ShipmentStoreMapRequest::query()->firstOrFail();
        $response->assertStatus(201)
            ->assertJsonPath('data.id', $shipmentStoreMapRequest->id)
            ->assertJsonPath('data.selection_token', $shipmentStoreMapRequest->selection_token)
            ->assertJsonPath('data.provider', Provider::ECPAY_LOGISTICS->value)
            ->assertJsonPath('data.store_type', StoreType::HILIFE->value)
            ->assertJsonPath('data.ready', true)
            ->assertJsonPath('data.checkout_payload.action', 'https://logistics-stage.ecpay.com.tw/Express/map')
            ->assertJsonPath('data.checkout_payload.method', 'POST')
            ->assertJsonPath('data.request_payload.MerchantID', '2000132')
            ->assertJsonPath('data.request_payload.LogisticsType', 'CVS')
            ->assertJsonPath('data.request_payload.LogisticsSubType', 'HILIFEC2C')
            ->assertJsonPath('data.request_payload.IsCollection', 'N')
            ->assertJsonPath('data.request_payload.ServerReplyURL', 'http://localhost/api/shipment-store-map-callbacks/ecpay')
            ->assertJsonPath('data.request_payload.ExtraData', $shipmentStoreMapRequest->selection_token)
            ->assertJsonPath('data.request_payload.Device', 0)
            ->assertJsonPath('data.expires_at', '2026-09-10T05:04:56.000000Z');
        $this->assertMatchesRegularExpression('/^SMR20260910[0-9A-Z]{6}$/', $response->json('data.request_payload.MerchantTradeNo'));
        $this->assertMatchesRegularExpression('/^[A-F0-9]{32}$/', $response->json('data.request_payload.CheckMacValue'));
        $this->assertDatabaseHas('shipment_store_map_requests', [
            'id' => $shipmentStoreMapRequest->id,
            'member_id' => $member->id,
            'provider' => Provider::ECPAY_LOGISTICS->value,
            'store_type' => StoreType::HILIFE->value,
            'merchant_trade_no' => $response->json('data.request_payload.MerchantTradeNo'),
            'selection_token' => $shipmentStoreMapRequest->selection_token,
        ]);
    }

    /**
     * 超商電子地圖選擇請求: 未指定裝置類型時仍可建立選擇請求。
     */
    public function test_store_allows_missing_device(): void
    {
        $member = Member::factory()->create();
        Sanctum::actingAs($member);

        $shipmentStoreMapRequestService = Mockery::mock(ShipmentStoreMapRequestService::class);
        $shipmentStoreMapRequestService->shouldReceive('create')
            ->once()
            ->with($member->id, StoreType::FAMI->value, null)
            ->andReturn([
                'status' => 201,
                'data' => [
                    'selection_token' => '01J3QS2AJMZV09DNXQ2EE4NM2F',
                    'provider' => Provider::ECPAY_LOGISTICS->value,
                    'store_type' => StoreType::FAMI->value,
                    'ready' => true,
                    'checkout_payload' => ['action' => 'https://logistics-stage.ecpay.com.tw/Express/map', 'method' => 'POST'],
                    'request_payload' => ['MerchantTradeNo' => 'SMR20260910D4E5F6'],
                ],
            ]);

        $this->app->instance(ShipmentStoreMapRequestService::class, $shipmentStoreMapRequestService);

        $response = $this->postJson('/api/shipment-store-map-requests', [
            'store_type' => StoreType::FAMI->value,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.store_type', StoreType::FAMI->value)
            ->assertJsonPath('data.ready', true);
    }

    /**
     * 超商電子地圖選擇請求: 應依 service 結果回傳錯誤狀態與訊息。
     */
    public function test_store_returns_service_failure_response(): void
    {
        $member = Member::factory()->create();
        Sanctum::actingAs($member);

        $shipmentStoreMapRequestService = Mockery::mock(ShipmentStoreMapRequestService::class);
        $shipmentStoreMapRequestService->shouldReceive('create')
            ->once()
            ->with($member->id, StoreType::OKMART->value, null)
            ->andReturn([
                'status' => 409,
                'message' => '目前無法建立超商電子地圖選擇請求。',
            ]);

        $this->app->instance(ShipmentStoreMapRequestService::class, $shipmentStoreMapRequestService);

        $response = $this->postJson('/api/shipment-store-map-requests', [
            'store_type' => StoreType::OKMART->value,
        ]);

        $response->assertStatus(409)
            ->assertJsonPath('message', '目前無法建立超商電子地圖選擇請求。');
    }

    /**
     * 超商電子地圖選擇請求: 建立選擇請求時會套用 RateLimiter。
     */
    public function test_store_is_rate_limited_for_authenticated_member(): void
    {
        $member = Member::factory()->create();
        Sanctum::actingAs($member);

        $shipmentStoreMapRequestService = Mockery::mock(ShipmentStoreMapRequestService::class);
        $shipmentStoreMapRequestService->shouldReceive('create')
            ->times(10)
            ->with($member->id, StoreType::UNIMART->value, null)
            ->andReturn([
                'status' => 201,
                'data' => [
                    'selection_token' => '01J3QS2AJMZV09DNXQ2EE4NM2E',
                    'provider' => Provider::ECPAY_LOGISTICS->value,
                    'store_type' => StoreType::UNIMART->value,
                    'ready' => true,
                    'checkout_payload' => ['action' => 'https://logistics-stage.ecpay.com.tw/Express/map', 'method' => 'POST'],
                    'request_payload' => ['MerchantTradeNo' => 'SMR20260910A1B2C3'],
                ],
            ]);

        $this->app->instance(ShipmentStoreMapRequestService::class, $shipmentStoreMapRequestService);

        for ($attempt = 0; $attempt < 10; $attempt++) {
            $this->postJson('/api/shipment-store-map-requests', [
                'store_type' => StoreType::UNIMART->value,
            ])->assertStatus(201);
        }

        $this->postJson('/api/shipment-store-map-requests', [
            'store_type' => StoreType::UNIMART->value,
        ])->assertStatus(429);
    }
}
