<?php

namespace Tests\Unit;

use App\Enums\Shipment\Provider;
use App\Enums\Shipment\ShippingMethod;
use App\Enums\Shipment\Status;
use App\Enums\Shipment\StoreType;
use App\Gateways\Shipments\EcpayLogisticsGateway;
use App\Models\Order;
use App\Models\Shipment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use ReflectionMethod;
use RuntimeException;
use Tests\TestCase;

class EcpayLogisticsGatewayTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * 綠界物流 Gateway: 超商取貨應建立綠界 C2C 物流訂單請求資訊。
     */
    public function test_build_shipment_request_returns_ecpay_cvs_create_logistics_payload(): void
    {
        $this->setValidEcpayLogisticsConfig();
        Carbon::setTestNow(Carbon::parse('2026-09-13 10:15:30'));
        $order = Order::factory()->create([
            'number' => 'ORD202609130001',
            'total_amount' => 1280,
        ]);
        $shipment = Shipment::factory()->for($order)->create([
            'provider' => Provider::ECPAY_LOGISTICS->value,
            'tracking_number' => null,
            'status' => Status::PENDING->value,
            'shipping_method' => ShippingMethod::CONVENIENCE_STORE->value,
            'recipient_name' => '王小明',
            'recipient_phone' => '0912345678',
            'recipient_address' => null,
            'store_code' => '991182',
            'store_type' => StoreType::UNIMART->value,
            'store_name' => '信義門市',
            'store_address' => '台北市信義區測試路 1 號',
            'created_at' => '2026-09-13 10:15:30',
        ]);

        $shipmentRequest = app(EcpayLogisticsGateway::class)->buildShipmentRequest($shipment);

        $this->assertSame('https://logistics-stage.ecpay.com.tw/Express/Create', $shipmentRequest['action']);
        $this->assertSame('POST', $shipmentRequest['method']);
        $this->assertSame('2000132', $shipmentRequest['params']['MerchantID']);
        $this->assertSame('ORD202609130001', $shipmentRequest['params']['MerchantTradeNo']);
        $this->assertSame('2026/09/13 10:15:30', $shipmentRequest['params']['MerchantTradeDate']);
        $this->assertSame('CVS', $shipmentRequest['params']['LogisticsType']);
        $this->assertSame('UNIMARTC2C', $shipmentRequest['params']['LogisticsSubType']);
        $this->assertSame(1280, $shipmentRequest['params']['GoodsAmount']);
        $this->assertSame(0, $shipmentRequest['params']['CollectionAmount']);
        $this->assertSame('N', $shipmentRequest['params']['IsCollection']);
        $this->assertSame('ORD202609130001', $shipmentRequest['params']['GoodsName']);
        $this->assertSame('Shop API', $shipmentRequest['params']['SenderName']);
        $this->assertSame('0911222333', $shipmentRequest['params']['SenderCellPhone']);
        $this->assertSame('王小明', $shipmentRequest['params']['ReceiverName']);
        $this->assertSame('0912345678', $shipmentRequest['params']['ReceiverCellPhone']);
        $this->assertSame('991182', $shipmentRequest['params']['ReceiverStoreID']);
        $this->assertArrayNotHasKey('ReceiverAddress', $shipmentRequest['params']);
        $this->assertSame('http://localhost/api/shipment-callbacks/ecpay', $shipmentRequest['params']['ServerReplyURL']);
        $this->assertMatchesRegularExpression('/^[A-F0-9]{32}$/', $shipmentRequest['params']['CheckMacValue']);
        $this->assertArrayNotHasKey('HashKey', $shipmentRequest['params']);
        $this->assertArrayNotHasKey('HashIV', $shipmentRequest['params']);
    }

    /**
     * 綠界物流 Gateway: 宅配應建立 Home 物流訂單請求資訊並帶收件地址。
     */
    public function test_build_shipment_request_returns_ecpay_home_delivery_create_logistics_payload(): void
    {
        $this->setValidEcpayLogisticsConfig();
        config()->set('services.ecpay_logistics.home_logistics_sub_type', 'TCAT');
        $order = Order::factory()->create([
            'number' => 'ORD202609130002',
            'total_amount' => 880,
        ]);
        $shipment = Shipment::factory()->for($order)->create([
            'provider' => Provider::ECPAY_LOGISTICS->value,
            'shipping_method' => ShippingMethod::HOME_DELIVERY->value,
            'recipient_name' => '陳美玲',
            'recipient_phone' => '0987654321',
            'recipient_address' => '台中市西屯區測試路 9 號',
            'store_code' => null,
            'store_type' => null,
            'store_name' => null,
            'store_address' => null,
        ]);

        $shipmentRequest = app(EcpayLogisticsGateway::class)->buildShipmentRequest($shipment);

        $this->assertSame('Home', $shipmentRequest['params']['LogisticsType']);
        $this->assertSame('TCAT', $shipmentRequest['params']['LogisticsSubType']);
        $this->assertSame('陳美玲', $shipmentRequest['params']['ReceiverName']);
        $this->assertSame('0987654321', $shipmentRequest['params']['ReceiverCellPhone']);
        $this->assertSame('台中市西屯區測試路 9 號', $shipmentRequest['params']['ReceiverAddress']);
        $this->assertArrayNotHasKey('ReceiverStoreID', $shipmentRequest['params']);
        $this->assertMatchesRegularExpression('/^[A-F0-9]{32}$/', $shipmentRequest['params']['CheckMacValue']);
    }

    /**
     * 綠界物流 Gateway: 應依超商類型對應綠界物流子類型。
     */
    public function test_build_shipment_request_maps_supported_cvs_store_types_to_logistics_sub_type(): void
    {
        $this->setValidEcpayLogisticsConfig();

        $cases = [
            StoreType::UNIMART->value => 'UNIMARTC2C',
            StoreType::FAMI->value => 'FAMIC2C',
            StoreType::HILIFE->value => 'HILIFEC2C',
        ];

        foreach ($cases as $storeType => $logisticsSubType) {
            $shipment = Shipment::factory()->create([
                'shipping_method' => ShippingMethod::CONVENIENCE_STORE->value,
                'store_code' => '991182',
                'store_type' => $storeType,
            ]);

            $shipmentRequest = app(EcpayLogisticsGateway::class)->buildShipmentRequest($shipment);

            $this->assertSame($logisticsSubType, $shipmentRequest['params']['LogisticsSubType']);
        }
    }

    /**
     * 綠界物流 Gateway: 宅配缺少收件地址時應拋出明確例外。
     */
    public function test_build_shipment_request_throws_runtime_exception_when_home_delivery_recipient_address_is_missing(): void
    {
        $this->setValidEcpayLogisticsConfig();
        $shipment = Shipment::factory()->create([
            'shipping_method' => ShippingMethod::HOME_DELIVERY->value,
            'recipient_address' => null,
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Shipment recipient address is required for home delivery.');

        app(EcpayLogisticsGateway::class)->buildShipmentRequest($shipment);
    }

    /**
     * 綠界物流 Gateway: 超商取貨缺少門市資訊時應拋出明確例外。
     */
    public function test_build_shipment_request_throws_runtime_exception_when_cvs_store_data_is_missing(): void
    {
        $this->setValidEcpayLogisticsConfig();

        $cases = [
            [
                ['store_code' => null, 'store_type' => StoreType::UNIMART->value],
                'Shipment store code is required for convenience store delivery.',
            ],
            [
                ['store_code' => '991182', 'store_type' => null],
                'Shipment store type is required for convenience store delivery.',
            ],
        ];

        foreach ($cases as [$attributes, $message]) {
            $shipment = Shipment::factory()->create(array_merge([
                'shipping_method' => ShippingMethod::CONVENIENCE_STORE->value,
            ], $attributes));
            $exception = null;

            try {
                app(EcpayLogisticsGateway::class)->buildShipmentRequest($shipment);
            } catch (RuntimeException $exception) {
            }

            $this->assertInstanceOf(RuntimeException::class, $exception);
            $this->assertSame($message, $exception->getMessage());
        }
    }

    /**
     * 綠界物流 Gateway: 缺少收件人姓名或手機時應拋出明確例外。
     */
    public function test_build_shipment_request_throws_runtime_exception_when_receiver_contact_data_is_missing(): void
    {
        $this->setValidEcpayLogisticsConfig();

        $cases = [
            [
                ['recipient_name' => ''],
                'Shipment recipient name is not configured.',
            ],
            [
                ['recipient_phone' => ''],
                'Shipment recipient phone is not configured.',
            ],
        ];

        foreach ($cases as [$attributes, $message]) {
            $shipment = Shipment::factory()->create(array_merge([
                'shipping_method' => ShippingMethod::CONVENIENCE_STORE->value,
                'store_code' => '991182',
                'store_type' => StoreType::UNIMART->value,
            ], $attributes));
            $exception = null;

            try {
                app(EcpayLogisticsGateway::class)->buildShipmentRequest($shipment);
            } catch (RuntimeException $exception) {
            }

            $this->assertInstanceOf(RuntimeException::class, $exception);
            $this->assertSame($message, $exception->getMessage());
        }
    }

    /**
     * 綠界物流 Gateway: 宅配物流子類型設定不可為空。
     */
    public function test_build_shipment_request_throws_runtime_exception_when_home_logistics_sub_type_is_missing(): void
    {
        $this->setValidEcpayLogisticsConfig();
        config()->set('services.ecpay_logistics.home_logistics_sub_type', '');
        $shipment = Shipment::factory()->create([
            'shipping_method' => ShippingMethod::HOME_DELIVERY->value,
            'recipient_address' => '台中市西屯區測試路 9 號',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('services.ecpay_logistics.home_logistics_sub_type is not configured.');

        app(EcpayLogisticsGateway::class)->buildShipmentRequest($shipment);
    }

    /**
     * 綠界物流 Gateway: 缺少綠界物流必填設定時應拋出明確例外。
     */
    public function test_build_shipment_request_throws_runtime_exception_when_required_config_is_missing(): void
    {
        $requiredConfigKeys = [
            'services.ecpay_logistics.merchant_id',
            'services.ecpay_logistics.hash_key',
            'services.ecpay_logistics.hash_iv',
            'services.ecpay_logistics.create_action_url',
            'services.ecpay_logistics.create_server_reply_url',
            'services.ecpay_logistics.sender_name',
            'services.ecpay_logistics.sender_cell_phone',
        ];

        foreach ($requiredConfigKeys as $configKey) {
            $this->setValidEcpayLogisticsConfig();
            config()->set($configKey, '');
            $shipment = Shipment::factory()->create([
                'shipping_method' => ShippingMethod::CONVENIENCE_STORE->value,
                'store_code' => '991182',
                'store_type' => StoreType::UNIMART->value,
            ]);
            $exception = null;

            try {
                app(EcpayLogisticsGateway::class)->buildShipmentRequest($shipment);
            } catch (RuntimeException $exception) {
            }

            $this->assertInstanceOf(RuntimeException::class, $exception);
            $this->assertSame("{$configKey} is not configured.", $exception->getMessage());
        }
    }

    /**
     * 綠界物流 Gateway: 物流檢查碼應使用綠界物流 MD5 編碼規則產生固定結果。
     */
    public function test_make_check_mac_value_uses_ecpay_logistics_md5_encoding_rules(): void
    {
        $this->setValidEcpayLogisticsConfig();
        $payload = [
            'MerchantID' => '2000132',
            'MerchantTradeNo' => 'ORD202609130001',
            'MerchantTradeDate' => '2026/09/13 10:15:30',
            'LogisticsType' => 'CVS',
            'LogisticsSubType' => 'UNIMARTC2C',
            'GoodsAmount' => 1280,
            'CollectionAmount' => 0,
            'IsCollection' => 'N',
            'GoodsName' => 'ORD202609130001',
            'SenderName' => 'Shop API',
            'SenderCellPhone' => '0911222333',
            'ReceiverName' => '王小明',
            'ReceiverCellPhone' => '0912345678',
            'ReceiverStoreID' => '991182',
            'ServerReplyURL' => 'http://localhost/api/shipment-callbacks/ecpay',
        ];

        $method = new ReflectionMethod(EcpayLogisticsGateway::class, 'makeCheckMacValue');
        $checkMacValue = $method->invoke(app(EcpayLogisticsGateway::class), $payload);

        $this->assertSame($this->makeExpectedCheckMacValue($payload), $checkMacValue);
        $this->assertMatchesRegularExpression('/^[A-F0-9]{32}$/', $checkMacValue);
    }

    /**
     * 綠界物流 Gateway: 重新計算檢查碼時不應受既有 CheckMacValue 影響。
     */
    public function test_make_check_mac_value_ignores_existing_check_mac_value(): void
    {
        $this->setValidEcpayLogisticsConfig();
        $payload = [
            'MerchantID' => '2000132',
            'MerchantTradeNo' => 'ORD202609130001',
            'LogisticsType' => 'CVS',
        ];

        $method = new ReflectionMethod(EcpayLogisticsGateway::class, 'makeCheckMacValue');
        $gateway = app(EcpayLogisticsGateway::class);
        $checkMacValue = $method->invoke($gateway, $payload);
        $recalculatedCheckMacValue = $method->invoke($gateway, array_merge($payload, [
            'CheckMacValue' => 'INVALID_CHECK_MAC_VALUE',
        ]));

        $this->assertSame($checkMacValue, $recalculatedCheckMacValue);
    }

    private function setValidEcpayLogisticsConfig(): void
    {
        config()->set('services.ecpay_logistics.merchant_id', '2000132');
        config()->set('services.ecpay_logistics.hash_key', 'XBERn1YOvpM9nfZc');
        config()->set('services.ecpay_logistics.hash_iv', 'h1ONHk4P4yqbl5LK');
        config()->set('services.ecpay_logistics.create_action_url', 'https://logistics-stage.ecpay.com.tw/Express/Create');
        config()->set('services.ecpay_logistics.create_server_reply_url', 'http://localhost/api/shipment-callbacks/ecpay');
        config()->set('services.ecpay_logistics.sender_name', 'Shop API');
        config()->set('services.ecpay_logistics.sender_cell_phone', '0911222333');
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
