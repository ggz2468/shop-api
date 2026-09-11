<?php

namespace Tests\Feature;

use App\Enums\Shipment\Provider;
use App\Enums\ShipmentStoreMapRequest\StoreType;
use App\Models\Member;
use App\Models\ShipmentStoreMapRequest;
use App\Services\EcpayShipmentStoreMapCallbackService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Mockery;
use Tests\TestCase;

class EcpayShipmentStoreMapCallbackControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Mockery::close();

        parent::tearDown();
    }

    /**
     * 綠界電子地圖回呼：有效選店結果應更新選擇請求並導回前端結帳頁。
     */
    public function test_callback_processes_valid_store_selection_and_redirects_to_checkout(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-11 12:00:00'));
        $this->setEcpayLogisticsConfig();
        $shipmentStoreMapRequest = $this->createShipmentStoreMapRequest([
            'merchant_trade_no' => 'SMR20260911REAL01',
            'selection_token' => 'STORE_MAP_SELECTION_TOKEN_001',
            'expires_at' => Carbon::parse('2026-09-11 12:30:00'),
        ]);
        $payload = $this->signedStoreMapCallbackPayload([
            'MerchantTradeNo' => ' SMR20260911REAL01 ',
            'CVSStoreID' => ' 991182 ',
            'CVSStoreName' => ' 測試門市 ',
            'CVSAddress' => ' 台北市中正區測試路1號 ',
            'ExtraData' => ' STORE_MAP_SELECTION_TOKEN_001 ',
        ]);

        $response = $this->post('/api/shipment-store-map-callbacks/ecpay', $payload);

        $response->assertRedirect('http://localhost/checkout');

        $shipmentStoreMapRequest->refresh();
        $this->assertSame('991182', $shipmentStoreMapRequest->selected_store_code);
        $this->assertSame('測試門市', $shipmentStoreMapRequest->selected_store_name);
        $this->assertSame('台北市中正區測試路1號', $shipmentStoreMapRequest->selected_store_address);
        $this->assertSame('SMR20260911REAL01', $shipmentStoreMapRequest->response_payload['MerchantTradeNo']);
        $this->assertSame('991182', $shipmentStoreMapRequest->response_payload['CVSStoreID']);
        $this->assertSame('STORE_MAP_SELECTION_TOKEN_001', $shipmentStoreMapRequest->response_payload['ExtraData']);
    }

    /**
     * 綠界電子地圖回呼：驗簽失敗時不應更新選店資料。
     */
    public function test_callback_rejects_invalid_check_mac_value_without_updating_store_selection(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-11 12:00:00'));
        $this->setEcpayLogisticsConfig();
        $shipmentStoreMapRequest = $this->createShipmentStoreMapRequest([
            'merchant_trade_no' => 'SMR20260911REAL02',
            'selection_token' => 'STORE_MAP_SELECTION_TOKEN_002',
            'expires_at' => Carbon::parse('2026-09-11 12:30:00'),
        ]);
        $payload = $this->signedStoreMapCallbackPayload([
            'MerchantTradeNo' => 'SMR20260911REAL02',
            'ExtraData' => 'STORE_MAP_SELECTION_TOKEN_002',
            'CheckMacValue' => str_repeat('A', 32),
        ]);

        $response = $this->post('/api/shipment-store-map-callbacks/ecpay', $payload);

        $response->assertStatus(400)
            ->assertHeader('Content-Type', 'text/plain; charset=UTF-8')
            ->assertSeeText('0|Invalid CheckMacValue');

        $shipmentStoreMapRequest->refresh();
        $this->assertNull($shipmentStoreMapRequest->selected_store_code);
        $this->assertNull($shipmentStoreMapRequest->response_payload);
    }

    /**
     * 綠界電子地圖回呼：缺少必要欄位時應拒絕 callback。
     */
    public function test_callback_rejects_missing_required_field(): void
    {
        $this->setEcpayLogisticsConfig();
        $payload = $this->signedStoreMapCallbackPayload([
            'CVSStoreID' => '',
        ]);

        $response = $this->post('/api/shipment-store-map-callbacks/ecpay', $payload);

        $response->assertStatus(400)
            ->assertSeeText('0|Missing required field: CVSStoreID');
    }

    /**
     * 綠界電子地圖回呼：MerchantID 不符合設定時應拒絕 callback。
     */
    public function test_callback_rejects_invalid_merchant_id(): void
    {
        $this->setEcpayLogisticsConfig();
        $payload = $this->signedStoreMapCallbackPayload([
            'MerchantID' => '9999999',
        ]);

        $response = $this->post('/api/shipment-store-map-callbacks/ecpay', $payload);

        $response->assertStatus(400)
            ->assertSeeText('0|Invalid MerchantID');
    }

    /**
     * 綠界電子地圖回呼：找不到對應選擇請求時應拒絕 callback。
     */
    public function test_callback_rejects_unknown_store_map_request(): void
    {
        $this->setEcpayLogisticsConfig();
        $payload = $this->signedStoreMapCallbackPayload([
            'MerchantTradeNo' => 'SMR20260911UNKNOWN',
            'ExtraData' => 'STORE_MAP_SELECTION_UNKNOWN',
        ]);

        $response = $this->post('/api/shipment-store-map-callbacks/ecpay', $payload);

        $response->assertStatus(404)
            ->assertSeeText('0|Shipment store map request not found');
    }

    /**
     * 綠界電子地圖回呼：選店識別碼不符合特店交易編號時應拒絕 callback。
     */
    public function test_callback_rejects_mismatched_selection_token_without_updating_store_selection(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-11 12:00:00'));
        $this->setEcpayLogisticsConfig();
        $shipmentStoreMapRequest = $this->createShipmentStoreMapRequest([
            'merchant_trade_no' => 'SMR20260911REAL05',
            'selection_token' => 'STORE_MAP_SELECTION_TOKEN_005',
            'expires_at' => Carbon::parse('2026-09-11 12:30:00'),
        ]);
        $payload = $this->signedStoreMapCallbackPayload([
            'MerchantTradeNo' => 'SMR20260911REAL05',
            'ExtraData' => 'STORE_MAP_SELECTION_TOKEN_OTHER',
        ]);

        $response = $this->post('/api/shipment-store-map-callbacks/ecpay', $payload);

        $response->assertStatus(404)
            ->assertSeeText('0|Shipment store map request not found');

        $shipmentStoreMapRequest->refresh();
        $this->assertNull($shipmentStoreMapRequest->selected_store_code);
        $this->assertNull($shipmentStoreMapRequest->response_payload);
    }

    /**
     * 綠界電子地圖回呼：選擇請求已過期時不應更新選店資料。
     */
    public function test_callback_rejects_expired_store_map_request(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-11 12:00:00'));
        $this->setEcpayLogisticsConfig();
        $shipmentStoreMapRequest = $this->createShipmentStoreMapRequest([
            'merchant_trade_no' => 'SMR20260911REAL03',
            'selection_token' => 'STORE_MAP_SELECTION_TOKEN_003',
            'expires_at' => Carbon::parse('2026-09-11 11:59:59'),
        ]);
        $payload = $this->signedStoreMapCallbackPayload([
            'MerchantTradeNo' => 'SMR20260911REAL03',
            'ExtraData' => 'STORE_MAP_SELECTION_TOKEN_003',
        ]);

        $response = $this->post('/api/shipment-store-map-callbacks/ecpay', $payload);

        $response->assertStatus(400)
            ->assertSeeText('0|Shipment store map request expired');

        $shipmentStoreMapRequest->refresh();
        $this->assertNull($shipmentStoreMapRequest->selected_store_code);
        $this->assertNull($shipmentStoreMapRequest->response_payload);
    }

    /**
     * 綠界電子地圖回呼：回傳物流子類型與原選擇超商不符時不應更新選店資料。
     */
    public function test_callback_rejects_logistics_sub_type_mismatch_without_updating_store_selection(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-11 12:00:00'));
        $this->setEcpayLogisticsConfig();
        $shipmentStoreMapRequest = $this->createShipmentStoreMapRequest([
            'merchant_trade_no' => 'SMR20260911REAL06',
            'selection_token' => 'STORE_MAP_SELECTION_TOKEN_006',
            'store_type' => StoreType::FAMI->value,
            'expires_at' => Carbon::parse('2026-09-11 12:30:00'),
        ]);
        $payload = $this->signedStoreMapCallbackPayload([
            'MerchantTradeNo' => 'SMR20260911REAL06',
            'ExtraData' => 'STORE_MAP_SELECTION_TOKEN_006',
            'LogisticsSubType' => 'UNIMARTC2C',
        ]);

        $response = $this->post('/api/shipment-store-map-callbacks/ecpay', $payload);

        $response->assertStatus(400)
            ->assertSeeText('0|LogisticsSubType mismatch');

        $shipmentStoreMapRequest->refresh();
        $this->assertNull($shipmentStoreMapRequest->selected_store_code);
        $this->assertNull($shipmentStoreMapRequest->response_payload);
    }

    /**
     * 綠界電子地圖回呼：未設定過期時間的選擇請求仍可完成選店。
     */
    public function test_callback_allows_store_map_request_without_expiration_time(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-11 12:00:00'));
        $this->setEcpayLogisticsConfig();
        $shipmentStoreMapRequest = $this->createShipmentStoreMapRequest([
            'merchant_trade_no' => 'SMR20260911REAL07',
            'selection_token' => 'STORE_MAP_SELECTION_TOKEN_007',
            'expires_at' => null,
        ]);
        $payload = $this->signedStoreMapCallbackPayload([
            'MerchantTradeNo' => 'SMR20260911REAL07',
            'ExtraData' => 'STORE_MAP_SELECTION_TOKEN_007',
        ]);

        $response = $this->post('/api/shipment-store-map-callbacks/ecpay', $payload);

        $response->assertRedirect('http://localhost/checkout');

        $shipmentStoreMapRequest->refresh();
        $this->assertSame('991182', $shipmentStoreMapRequest->selected_store_code);
        $this->assertSame('測試門市', $shipmentStoreMapRequest->selected_store_name);
        $this->assertSame('台北市中正區測試路1號', $shipmentStoreMapRequest->selected_store_address);
    }

    /**
     * 綠界電子地圖回呼：剛好在過期時間當下收到 callback 時仍可完成選店。
     */
    public function test_callback_allows_store_map_request_at_exact_expiration_time(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-11 12:00:00'));
        $this->setEcpayLogisticsConfig();
        $shipmentStoreMapRequest = $this->createShipmentStoreMapRequest([
            'merchant_trade_no' => 'SMR20260911REAL08',
            'selection_token' => 'STORE_MAP_SELECTION_TOKEN_008',
            'expires_at' => Carbon::parse('2026-09-11 12:00:00'),
        ]);
        $payload = $this->signedStoreMapCallbackPayload([
            'MerchantTradeNo' => 'SMR20260911REAL08',
            'ExtraData' => 'STORE_MAP_SELECTION_TOKEN_008',
        ]);

        $response = $this->post('/api/shipment-store-map-callbacks/ecpay', $payload);

        $response->assertRedirect('http://localhost/checkout');

        $shipmentStoreMapRequest->refresh();
        $this->assertSame('991182', $shipmentStoreMapRequest->selected_store_code);
        $this->assertSame('測試門市', $shipmentStoreMapRequest->selected_store_name);
        $this->assertSame('台北市中正區測試路1號', $shipmentStoreMapRequest->selected_store_address);
    }

    /**
     * 綠界電子地圖回呼：缺少前端導向網址設定時應回覆伺服器錯誤。
     */
    public function test_callback_returns_server_error_when_client_redirect_url_is_not_configured(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-11 12:00:00'));
        $this->setEcpayLogisticsConfig();
        config()->set('services.ecpay_logistics.store_map_client_redirect_url', null);
        $this->createShipmentStoreMapRequest([
            'merchant_trade_no' => 'SMR20260911REAL04',
            'selection_token' => 'STORE_MAP_SELECTION_TOKEN_004',
            'expires_at' => Carbon::parse('2026-09-11 12:30:00'),
        ]);
        $payload = $this->signedStoreMapCallbackPayload([
            'MerchantTradeNo' => 'SMR20260911REAL04',
            'ExtraData' => 'STORE_MAP_SELECTION_TOKEN_004',
        ]);

        $response = $this->post('/api/shipment-store-map-callbacks/ecpay', $payload);

        $response->assertStatus(500)
            ->assertSeeText('0|Internal Server Error');
    }

    /**
     * 綠界電子地圖回呼：缺少物流 HashKey 設定時應回覆伺服器錯誤且不更新選店資料。
     */
    public function test_callback_returns_server_error_when_hash_key_is_not_configured_without_updating_store_selection(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-11 12:00:00'));
        $this->setEcpayLogisticsConfig();
        config()->set('services.ecpay_logistics.hash_key', null);
        $shipmentStoreMapRequest = $this->createShipmentStoreMapRequest([
            'merchant_trade_no' => 'SMR20260911REAL09',
            'selection_token' => 'STORE_MAP_SELECTION_TOKEN_009',
            'expires_at' => Carbon::parse('2026-09-11 12:30:00'),
        ]);
        $payload = $this->validStoreMapCallbackPayload([
            'MerchantTradeNo' => 'SMR20260911REAL09',
            'ExtraData' => 'STORE_MAP_SELECTION_TOKEN_009',
            'CheckMacValue' => str_repeat('B', 32),
        ]);

        $response = $this->post('/api/shipment-store-map-callbacks/ecpay', $payload);

        $response->assertStatus(500)
            ->assertSeeText('0|Internal Server Error');

        $shipmentStoreMapRequest->refresh();
        $this->assertNull($shipmentStoreMapRequest->selected_store_code);
        $this->assertNull($shipmentStoreMapRequest->response_payload);
    }

    /**
     * 綠界電子地圖回呼：同一筆選店識別碼會套用 RateLimiter。
     */
    public function test_callback_is_rate_limited_by_extra_data(): void
    {
        $payload = $this->validStoreMapCallbackPayload([
            'ExtraData' => 'STORE_MAP_RATE_LIMIT_TOKEN_001',
        ]);

        $service = Mockery::mock(EcpayShipmentStoreMapCallbackService::class);
        $service->shouldReceive('handle')
            ->times(30)
            ->with($payload)
            ->andReturn([
                'status' => 302,
                'redirect_url' => 'http://localhost/checkout',
            ]);

        $this->app->instance(EcpayShipmentStoreMapCallbackService::class, $service);

        for ($attempt = 0; $attempt < 30; $attempt++) {
            $this->post('/api/shipment-store-map-callbacks/ecpay', $payload)->assertRedirect('http://localhost/checkout');
        }

        $this->post('/api/shipment-store-map-callbacks/ecpay', $payload)->assertStatus(429);
    }

    /**
     * 綠界電子地圖回呼：不同選店識別碼不應互相消耗選店層級的限制。
     */
    public function test_callback_rate_limit_uses_separate_bucket_for_each_extra_data(): void
    {
        $firstPayload = $this->validStoreMapCallbackPayload([
            'ExtraData' => 'STORE_MAP_RATE_LIMIT_TOKEN_002',
        ]);
        $secondPayload = $this->validStoreMapCallbackPayload([
            'ExtraData' => 'STORE_MAP_RATE_LIMIT_TOKEN_003',
        ]);

        $service = Mockery::mock(EcpayShipmentStoreMapCallbackService::class);
        $service->shouldReceive('handle')
            ->times(30)
            ->with($firstPayload)
            ->andReturn([
                'status' => 302,
                'redirect_url' => 'http://localhost/checkout',
            ]);
        $service->shouldReceive('handle')
            ->once()
            ->with($secondPayload)
            ->andReturn([
                'status' => 302,
                'redirect_url' => 'http://localhost/checkout',
            ]);

        $this->app->instance(EcpayShipmentStoreMapCallbackService::class, $service);

        for ($attempt = 0; $attempt < 30; $attempt++) {
            $this->post('/api/shipment-store-map-callbacks/ecpay', $firstPayload)->assertRedirect('http://localhost/checkout');
        }

        $this->post('/api/shipment-store-map-callbacks/ecpay', $secondPayload)->assertRedirect('http://localhost/checkout');
    }

    /**
     * 綠界電子地圖回呼：缺少選店識別碼時會退回以 IP 套用 RateLimiter。
     */
    public function test_callback_without_extra_data_is_rate_limited_by_ip(): void
    {
        $payload = $this->validStoreMapCallbackPayload([
            'ExtraData' => '',
        ]);

        $service = Mockery::mock(EcpayShipmentStoreMapCallbackService::class);
        $service->shouldReceive('handle')
            ->times(30)
            ->with($payload)
            ->andReturn([
                'status' => 400,
                'content' => '0|Missing required field: ExtraData',
            ]);

        $this->app->instance(EcpayShipmentStoreMapCallbackService::class, $service);

        for ($attempt = 0; $attempt < 30; $attempt++) {
            $this->post('/api/shipment-store-map-callbacks/ecpay', $payload)->assertStatus(400);
        }

        $this->post('/api/shipment-store-map-callbacks/ecpay', $payload)->assertStatus(429);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createShipmentStoreMapRequest(array $attributes = []): ShipmentStoreMapRequest
    {
        return ShipmentStoreMapRequest::query()->create(array_merge([
            'member_id' => Member::factory()->create()->id,
            'provider' => Provider::ECPAY_LOGISTICS->value,
            'store_type' => StoreType::UNIMART->value,
            'merchant_trade_no' => 'SMR20260911REAL00',
            'selection_token' => 'STORE_MAP_SELECTION_TOKEN_000',
            'request_payload' => null,
            'checkout_payload' => null,
            'response_payload' => null,
            'selected_store_code' => null,
            'selected_store_name' => null,
            'selected_store_address' => null,
            'expires_at' => now()->addMinutes(30),
        ], $attributes));
    }

    /**
     * @param  array<string, string>  $overrides
     * @return array<string, string>
     */
    private function validStoreMapCallbackPayload(array $overrides = []): array
    {
        return array_merge([
            'MerchantID' => '2000132',
            'MerchantTradeNo' => 'SMR202609110000',
            'LogisticsSubType' => 'UNIMARTC2C',
            'CVSStoreID' => '991182',
            'CVSStoreName' => '測試門市',
            'CVSAddress' => '台北市中正區測試路1號',
            'CVSTelephone' => '0212345678',
            'CVSOutSide' => '0',
            'ExtraData' => 'STORE_MAP_SELECTION_TOKEN_000',
            'CheckMacValue' => 'VALID_CHECK_MAC_VALUE',
        ], $overrides);
    }

    /**
     * @param  array<string, string>  $overrides
     * @return array<string, string>
     */
    private function signedStoreMapCallbackPayload(array $overrides = []): array
    {
        $payload = array_merge([
            'MerchantID' => '2000132',
            'MerchantTradeNo' => 'SMR20260911REAL00',
            'LogisticsSubType' => 'UNIMARTC2C',
            'CVSStoreID' => '991182',
            'CVSStoreName' => '測試門市',
            'CVSAddress' => '台北市中正區測試路1號',
            'CVSTelephone' => '0212345678',
            'CVSOutSide' => '0',
            'ExtraData' => 'STORE_MAP_SELECTION_TOKEN_000',
        ], $overrides);

        $payloadForSignature = array_map(
            fn (string $value): string => trim($value),
            $payload,
        );
        $payload['CheckMacValue'] = $this->makeLogisticsCheckMacValue($payloadForSignature);

        if (array_key_exists('CheckMacValue', $overrides)) {
            $payload['CheckMacValue'] = $overrides['CheckMacValue'];
        }

        return $payload;
    }

    /**
     * @param  array<string, string>  $payload
     */
    private function makeLogisticsCheckMacValue(array $payload): string
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

    private function setEcpayLogisticsConfig(): void
    {
        config()->set('services.ecpay_logistics.merchant_id', '2000132');
        config()->set('services.ecpay_logistics.hash_key', 'XBERn1YOvpM9nfZc');
        config()->set('services.ecpay_logistics.hash_iv', 'h1ONHk4P4yqbl5LK');
        config()->set('services.ecpay_logistics.store_map_client_redirect_url', 'http://localhost/checkout');
    }
}
