<?php

namespace Tests\Feature;

use App\Models\Member;
use App\Services\MemberService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Mockery;
use Tests\TestCase;

class AuthControllerTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 登入：資料格式錯誤時應回傳 422。
     */
    public function test_login_returns_422_when_validation_fails(): void
    {
        $response = $this->postJson('/api/auth/login', [
            'email' => 'invalid-email-format',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['email', 'password']);
    }

    /**
     * 登入：帳號密碼正確時應登入成功。
     */
    public function test_login_returns_success_when_credentials_are_valid(): void
    {
        Member::query()->create([
            'national_id_number' => 'A123456789',
            'email' => 'member@example.com',
            'phone' => '0911222333',
            'password' => Hash::make('Password123'),
        ]);

        $response = $this->withHeaders($this->statefulApiHeaders())
            ->postJson('/api/auth/login', [
                'email' => 'member@example.com',
                'password' => 'Password123',
            ]);

        $response->assertOk()
            ->assertJson([
                'message' => '登入成功',
            ]);

        $this->assertAuthenticated('web');
    }

    /**
     * 登入：成功時應重新產生 session id。
     */
    public function test_login_regenerates_session_id_when_credentials_are_valid(): void
    {
        Member::query()->create([
            'national_id_number' => 'A123456789',
            'email' => 'member@example.com',
            'phone' => '0911222333',
            'password' => Hash::make('Password123'),
        ]);

        $sessionCookieName = config('session.cookie');
        $oldSessionId = str_repeat('a', 40);

        $response = $this->withHeaders($this->statefulApiHeaders())
            ->withCookie($sessionCookieName, $oldSessionId)
            ->postJson('/api/auth/login', [
                'email' => 'member@example.com',
                'password' => 'Password123',
            ]);

        $response->assertOk();

        $newSessionId = $this->cookieValue($response, $sessionCookieName);

        $this->assertNotNull($newSessionId);
        $this->assertNotSame($oldSessionId, $newSessionId);
    }

    /**
     * 登入：帳號密碼錯誤時應回傳 401。
     */
    public function test_login_returns_401_when_credentials_are_invalid(): void
    {
        Member::query()->create([
            'national_id_number' => 'A123456789',
            'email' => 'member@example.com',
            'phone' => '0911222333',
            'password' => Hash::make('Password123'),
        ]);

        $response = $this->withHeaders($this->statefulApiHeaders())
            ->postJson('/api/auth/login', [
                'email' => 'member@example.com',
                'password' => 'WrongPassword123',
            ]);

        $response->assertStatus(401)
            ->assertJson([
                'message' => '登入失敗',
            ]);
    }

    /**
     * 登出：未登入使用者應回傳 401。
     */
    public function test_logout_returns_401_when_unauthenticated(): void
    {
        $response = $this->postJson('/api/auth/logout');

        $response->assertStatus(401);
    }

    /**
     * 登出：已登入使用者可成功登出。
     */
    public function test_logout_returns_success_when_authenticated(): void
    {
        $member = Member::query()->create([
            'national_id_number' => 'A123456789',
            'email' => 'member@example.com',
            'phone' => '0911222333',
            'password' => Hash::make('Password123'),
        ]);

        $this->actingAs($member, 'sanctum');

        $response = $this->withHeaders($this->statefulApiHeaders())
            ->postJson('/api/auth/logout');

        $response->assertOk()
            ->assertJson([
                'message' => '登出成功',
            ]);

        $this->assertGuest('web');
    }

    /**
     * 登出：成功後使用原 session cookie 取 user 應回傳 401。
     */
    public function test_logout_invalidates_current_session_for_following_requests(): void
    {
        Member::query()->create([
            'national_id_number' => 'A123456789',
            'email' => 'member@example.com',
            'phone' => '0911222333',
            'password' => Hash::make('Password123'),
        ]);

        $loginResponse = $this->withHeaders($this->statefulApiHeaders())
            ->postJson('/api/auth/login', [
                'email' => 'member@example.com',
                'password' => 'Password123',
            ]);

        $loginResponse->assertOk();

        $sessionCookieName = config('session.cookie');
        $sessionId = $this->cookieValue($loginResponse, $sessionCookieName);

        $this->assertNotNull($sessionId);

        $logoutResponse = $this->withHeaders($this->statefulApiHeaders())
            ->withCookie($sessionCookieName, $sessionId)
            ->postJson('/api/auth/logout');

        $logoutResponse->assertOk()
            ->assertJson([
                'message' => '登出成功',
            ]);

        $this->app['auth']->forgetGuards();

        $userResponse = $this->withHeaders($this->statefulApiHeaders())
            ->withCookie($sessionCookieName, $sessionId)
            ->getJson('/api/auth/user');

        $userResponse->assertStatus(401);
    }

    /**
     * 取得會員資料：未登入應回傳 401。
     */
    public function test_user_returns_401_when_unauthenticated(): void
    {
        $response = $this->getJson('/api/auth/user');

        $response->assertStatus(401);
    }

    /**
     * 取得會員資料：已登入應回傳目前會員資訊。
     */
    public function test_user_returns_authenticated_member_when_logged_in(): void
    {
        $member = Member::factory()->create([
            'first_name' => '小明',
            'last_name' => '王',
            'national_id_number' => 'A223456789',
            'email' => 'user20@example.com',
            'phone' => '0911222444',
        ]);
        $this->assertInstanceOf(Member::class, $member);

        $this->actingAs($member, 'sanctum');

        $response = $this->withHeaders($this->statefulApiHeaders())
            ->getJson('/api/auth/user');

        $response->assertOk()
            ->assertJsonPath('first_name', '小明')
            ->assertJsonPath('last_name', '王');
    }

    /**
     * 註冊：資料格式錯誤時應回傳 422。
     */
    public function test_register_returns_422_when_validation_fails(): void
    {
        $response = $this->postJson('/api/auth/register', [
            'national_id_number' => 'A12345',
            'email' => 'invalid-email',
            'phone' => '0987',
            'password' => 'weak',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors([
                'national_id_number',
                'email',
                'phone',
                'password',
            ]);
    }

    /**
     * 註冊：驗證失敗時不應呼叫 service。
     */
    public function test_register_does_not_call_service_when_validation_fails(): void
    {
        $memberService = Mockery::mock(MemberService::class);
        $memberService->shouldReceive('register')->never();

        $this->app->instance(MemberService::class, $memberService);

        $response = $this->postJson('/api/auth/register', [
            'national_id_number' => 'A12345',
            'email' => 'invalid-email',
            'phone' => '0987',
            'password' => 'weak',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors([
                'national_id_number',
                'email',
                'phone',
                'password',
            ]);
    }

    /**
     * 註冊：應回傳自訂驗證錯誤訊息與邊界規則結果。
     */
    public function test_register_returns_custom_messages_for_required_and_boundary_rules(): void
    {
        $response = $this->postJson('/api/auth/register', [
            'national_id_number' => '',
            'email' => str_repeat('a', 91).'@example.com',
            'phone' => '0911222333',
            'password' => str_repeat('A', 8),
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors([
                'national_id_number',
                'email',
                'password',
            ])
            ->assertJsonPath('errors.national_id_number.0', '身分證字號為必填。')
            ->assertJsonPath('errors.email.0', '電子郵件長度不能超過 100 字元。')
            ->assertJsonPath('errors.password.0', '密碼格式錯誤，需包含大寫字母、小寫字母與數字。');
    }

    /**
     * 註冊：密碼超過 255 碼時應回傳 max 錯誤訊息。
     */
    public function test_register_returns_password_max_message_when_password_exceeds_255_chars(): void
    {
        $response = $this->postJson('/api/auth/register', [
            'national_id_number' => 'A123456789',
            'email' => 'member@example.com',
            'phone' => '0911222333',
            'password' => str_repeat('Aa1', 86),
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['password'])
            ->assertJsonPath('errors.password.0', '密碼長度不能超過 255 碼。');
    }

    /**
     * 註冊：重複資料應回傳對應 unique 驗證錯誤。
     */
    public function test_register_returns_422_when_unique_fields_conflict(): void
    {
        Member::query()->create([
            'first_name' => '王',
            'last_name' => '小明',
            'national_id_number' => 'A123456789',
            'email' => 'duplicate@example.com',
            'phone' => '0912345678',
            'password' => Hash::make('Password123'),
        ]);

        $response = $this->postJson('/api/auth/register', [
            'national_id_number' => 'A123456789',
            'email' => 'duplicate@example.com',
            'phone' => '0912345678',
            'password' => 'Password123',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors([
                'national_id_number',
                'email',
                'phone',
            ]);
    }

    /**
     * 註冊：驗證成功時應呼叫 service 並回傳 service 結果。
     */
    public function test_register_returns_service_result_when_payload_is_valid(): void
    {
        $memberService = Mockery::mock(MemberService::class);
        $memberService->shouldReceive('register')
            ->once()
            ->with(Mockery::on(function (array $data): bool {
                return ($data['national_id_number'] ?? null) === 'A123456789'
                    && ($data['email'] ?? null) === 'new@example.com'
                    && ($data['phone'] ?? null) === '0911222333'
                    && ($data['password'] ?? null) === 'Password123';
            }))
            ->andReturn([
                'status' => 201,
                'message' => '會員註冊成功。',
            ]);

        $this->app->instance(MemberService::class, $memberService);

        $response = $this->postJson('/api/auth/register', [
            'national_id_number' => 'A123456789',
            'email' => 'new@example.com',
            'phone' => '0911222333',
            'password' => 'Password123',
        ]);

        $response->assertStatus(201)
            ->assertJson([
                'message' => '會員註冊成功。',
            ]);
    }

    /**
     * 註冊：service 回傳失敗狀態時應原樣回傳。
     */
    public function test_register_propagates_service_failure_status_and_message(): void
    {
        $memberService = Mockery::mock(MemberService::class);
        $memberService->shouldReceive('register')
            ->once()
            ->andReturn([
                'status' => 409,
                'message' => '電子郵件已被註冊。',
            ]);

        $this->app->instance(MemberService::class, $memberService);

        $response = $this->postJson('/api/auth/register', [
            'national_id_number' => 'A123456789',
            'email' => 'new@example.com',
            'phone' => '0911222333',
            'password' => 'Password123',
        ]);

        $response->assertStatus(409)
            ->assertJson([
                'message' => '電子郵件已被註冊。',
            ]);
    }

    /**
     * 提供 Sanctum stateful API 所需的來源標頭，讓 API route 能取得 session。
     *
     * @return array<string, string>
     */
    private function statefulApiHeaders(): array
    {
        return [
            'Origin' => 'http://shop.test',
            'Referer' => 'http://shop.test',
        ];
    }

    /**
     * 取得 response 中指定 cookie 的值。
     */
    private function cookieValue(TestResponse $response, string $cookieName): ?string
    {
        foreach ($response->headers->getCookies() as $cookie) {
            if ($cookie->getName() === $cookieName) {
                return $cookie->getValue();
            }
        }

        return null;
    }
}
