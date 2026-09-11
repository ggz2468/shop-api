<?php

namespace App\Providers;

use App\Contracts\PaymentGateway;
use App\Gateways\Payments\PaymentGatewayManager;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(PaymentGateway::class, function (): PaymentGateway {
            return $this->app->make(PaymentGatewayManager::class)
                ->driver((int) config('services.payment.default_provider'));
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('auth-login', function (Request $request): array {
            $identityKey = (string) ($request->input('email') ?? $request->input('phone') ?? $request->ip());

            return [
                Limit::perMinute(5)->by('auth:login:minute:'.$identityKey),
                Limit::perHour(30)->by('auth:login:hour:'.$request->ip()),
            ];
        });

        RateLimiter::for('auth-register', function (Request $request): array {
            $identityKey = (string) ($request->input('email') ?? $request->input('phone') ?? $request->ip());

            return [
                Limit::perMinute(5)->by('auth:register:minute:'.$identityKey),
                Limit::perHour(20)->by('auth:register:hour:'.$request->ip()),
            ];
        });

        RateLimiter::for('auth-password-forgot', function (Request $request): array {
            $emailKey = (string) ($request->input('email') ?? $request->ip());

            return [
                Limit::perMinute(1)->by('auth:password:forgot:cooldown:'.$emailKey),
                Limit::perMinutes(15, 5)->by('auth:password:forgot:burst:'.$emailKey),
                Limit::perHour(30)->by('auth:password:forgot:ip:'.$request->ip()),
            ];
        });

        RateLimiter::for('auth-password-reset', function (Request $request): array {
            $emailKey = strtolower(trim((string) ($request->input('email') ?? '')));
            $tokenFingerprint = hash('sha256', (string) ($request->input('token') ?? ''));

            return [
                Limit::perMinute(5)->by('auth:password:reset:minute:ip:'.$request->ip()),
                Limit::perMinutes(15, 10)->by('auth:password:reset:email:'.($emailKey !== '' ? $emailKey : $request->ip())),
                Limit::perHour(20)->by('auth:password:reset:token:'.$tokenFingerprint),
            ];
        });

        RateLimiter::for('auth-session', function (Request $request): array {
            $memberKey = (string) ($request->user()?->id ?? $request->ip());

            return [
                Limit::perMinute(60)->by('auth:session:minute:'.$memberKey),
                Limit::perHour(500)->by('auth:session:hour:'.$request->ip()),
            ];
        });

        RateLimiter::for('verification-email-send', function (Request $request): array {
            $memberKey = (string) ($request->user()?->id ?? $request->ip());

            return [
                Limit::perMinute(1)->by('verification:email:send:cooldown:'.$memberKey),
                Limit::perMinutes(15, 5)->by('verification:email:send:burst:'.$memberKey),
                Limit::perDay(20)->by('verification:email:send:day:'.$memberKey),
                Limit::perHour(30)->by('verification:email:send:ip:'.$request->ip()),
            ];
        });

        RateLimiter::for('verification-phone-send', function (Request $request): array {
            $phoneKey = (string) ($request->user()?->phone ?? $request->input('phone') ?? $request->ip());

            return [
                Limit::perMinute(1)->by('verification:phone:send:cooldown:'.$phoneKey),
                Limit::perMinutes(15, 3)->by('verification:phone:send:burst:'.$phoneKey),
                Limit::perDay(10)->by('verification:phone:send:day:'.$phoneKey),
                Limit::perHour(20)->by('verification:phone:send:ip:'.$request->ip()),
            ];
        });

        RateLimiter::for('verification-email-verify', function (Request $request): array {
            $memberKey = (string) ($request->user()?->id ?? $request->ip());

            return [
                Limit::perMinute(10)->by('verification:email:verify:ip:'.$request->ip()),
                Limit::perMinutes(15, 30)->by('verification:email:verify:member:'.$memberKey),
            ];
        });

        RateLimiter::for('verification-phone-verify', function (Request $request): array {
            $phoneKey = (string) ($request->user()?->phone ?? $request->input('phone') ?? $request->ip());

            return [
                Limit::perMinutes(10, 5)->by('verification:phone:verify:phone:'.$phoneKey),
                Limit::perHour(30)->by('verification:phone:verify:ip:'.$request->ip()),
            ];
        });

        RateLimiter::for('products', function (Request $request): array {
            $actorKey = (string) ($request->user()?->id ?? $request->ip());
            $isReadRequest = in_array($request->method(), ['GET', 'HEAD'], true);

            if ($isReadRequest) {
                return [
                    Limit::perMinute(120)->by('products:read:minute:'.$actorKey),
                    Limit::perHour(1000)->by('products:read:hour:'.$request->ip()),
                ];
            }

            return [
                Limit::perMinute(30)->by('products:write:minute:'.$actorKey),
                Limit::perHour(200)->by('products:write:hour:'.$request->ip()),
            ];
        });

        RateLimiter::for('cart', function (Request $request): array {
            $memberKey = (string) ($request->user()?->id ?? $request->ip());

            return [
                Limit::perMinute(60)->by('cart:read:minute:'.$memberKey),
                Limit::perHour(500)->by('cart:read:hour:'.$request->ip()),
            ];
        });

        RateLimiter::for('orders', function (Request $request): array {
            $memberKey = (string) ($request->user()?->id ?? $request->ip());
            $isReadRequest = in_array($request->method(), ['GET', 'HEAD'], true);

            if ($isReadRequest) {
                return [
                    Limit::perMinute(60)->by('orders:read:minute:'.$memberKey),
                    Limit::perHour(500)->by('orders:read:hour:'.$request->ip()),
                ];
            }

            return [
                Limit::perMinute(10)->by('orders:write:minute:'.$memberKey),
                Limit::perHour(50)->by('orders:write:hour:'.$request->ip()),
            ];
        });

        RateLimiter::for('shipment-store-map-requests', function (Request $request): array {
            $memberKey = (string) ($request->user()?->id ?? $request->ip());
            $isReadRequest = in_array($request->method(), ['GET', 'HEAD'], true);

            if ($isReadRequest) {
                return [
                    Limit::perMinute(60)->by('shipment-store-map-requests:read:minute:'.$memberKey),
                    Limit::perHour(500)->by('shipment-store-map-requests:read:hour:'.$request->ip()),
                ];
            }

            return [
                Limit::perMinute(10)->by('shipment-store-map-requests:write:minute:'.$memberKey),
                Limit::perHour(50)->by('shipment-store-map-requests:write:hour:'.$request->ip()),
            ];
        });

        RateLimiter::for('payment-callbacks', function (Request $request): array {
            $merchantTradeNo = trim((string) $request->input('MerchantTradeNo'));
            $callbackKey = $merchantTradeNo !== '' ? $merchantTradeNo : $request->ip();

            return [
                Limit::perMinute(30)->by('payment-callbacks:callback:'.$callbackKey),
                Limit::perMinute(300)->by('payment-callbacks:ip:'.$request->ip()),
            ];
        });

        RateLimiter::for('shipment-store-map-callbacks', function (Request $request): array {
            $selectionToken = trim((string) $request->input('ExtraData'));
            $callbackKey = $selectionToken !== '' ? $selectionToken : $request->ip();

            return [
                Limit::perMinute(30)->by('shipment-store-map-callbacks:callback:'.$callbackKey),
                Limit::perMinute(300)->by('shipment-store-map-callbacks:ip:'.$request->ip()),
            ];
        });
    }
}
