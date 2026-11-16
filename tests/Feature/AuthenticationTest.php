<?php

namespace Tests\Feature;

use App\Mail\OtpMail;
use App\Models\LoginHistory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRbac();
    }

    public function test_login_page_renders_with_required_elements(): void
    {
        $this->get('/login')->assertOk()
            ->assertSee('Welcome Back')->assertSee('Reliable Equipment.', false)
            ->assertSee('Forgot Password?')->assertSee('Remember me');
    }

    public function test_valid_login_by_email_and_by_username(): void
    {
        $user = $this->makeUser('facility_manager', attributes: ['email' => 'jane@hospital.test', 'username' => 'jane']);

        $this->post('/login', ['login' => 'JANE@hospital.test', 'password' => 'password'])->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);
        $this->post('/logout');

        $this->post('/login', ['login' => 'jane', 'password' => 'password'])->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);
        $this->assertSame(2, LoginHistory::where('status', 'success')->count());
        $this->assertNotNull($user->fresh()->last_login_at);
    }

    public function test_invalid_credentials_are_rejected_without_revealing_which_part_was_wrong(): void
    {
        $this->makeUser(attributes: ['email' => 'jane@hospital.test']);

        $a = $this->from('/login')->post('/login', ['login' => 'jane@hospital.test', 'password' => 'wrong']);
        $b = $this->from('/login')->post('/login', ['login' => 'nobody@hospital.test', 'password' => 'wrong']);

        $a->assertRedirect('/login')->assertSessionHasErrors('credentials');
        $this->assertSame(session('errors')->first('credentials'), 'Invalid username/email or password.');
        $b->assertSessionHasErrors('credentials');
        $this->assertGuest();
        $this->assertSame(2, LoginHistory::where('status', 'failed')->count());
    }

    public function test_validation_messages_for_empty_fields(): void
    {
        $this->from('/login')->post('/login', [])->assertSessionHasErrors(['login', 'password']);
    }

    public function test_disabled_inactive_and_locked_accounts_cannot_sign_in(): void
    {
        $this->makeUser(attributes: ['email' => 'd@x.test', 'status' => 'disabled']);
        $this->makeUser(attributes: ['email' => 'i@x.test', 'status' => 'inactive']);
        $this->makeUser(attributes: ['email' => 'l@x.test', 'locked_until' => now()->addMinutes(10)]);

        $this->postJson('/login', ['login' => 'd@x.test', 'password' => 'password'])->assertStatus(403)->assertJsonPath('message', 'This account has been disabled. Contact your administrator.');
        $this->postJson('/login', ['login' => 'i@x.test', 'password' => 'password'])->assertStatus(403)->assertJsonFragment(['message' => 'This account is inactive. Contact your administrator.']);
        $this->postJson('/login', ['login' => 'l@x.test', 'password' => 'password'])->assertStatus(423);
        $this->assertGuest();
    }

    public function test_wrong_password_for_a_disabled_account_does_not_reveal_it_is_disabled(): void
    {
        $this->makeUser(attributes: ['email' => 'd@x.test', 'status' => 'disabled']);
        $this->postJson('/login', ['login' => 'd@x.test', 'password' => 'nope'])->assertStatus(401);
    }

    public function test_repeated_failures_throttle_and_lock_the_account(): void
    {
        $user = $this->makeUser(attributes: ['email' => 'jane@hospital.test']);

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/login', ['login' => 'jane@hospital.test', 'password' => 'bad'])->assertStatus(401);
        }
        $this->assertTrue($user->fresh()->isLocked());

        // Even the correct password is refused now.
        $this->postJson('/login', ['login' => 'jane@hospital.test', 'password' => 'password'])->assertStatus(429);
        $this->assertGuest();

        RateLimiter::clear('login:'.sha1('jane@hospital.test|127.0.0.1'));
        RateLimiter::clear('login-ip:127.0.0.1');
        $this->postJson('/login', ['login' => 'jane@hospital.test', 'password' => 'password'])->assertStatus(423);
    }

    public function test_remember_me_sets_a_recaller_cookie_only_when_requested(): void
    {
        $this->makeUser(attributes: ['email' => 'jane@hospital.test']);

        $without = $this->post('/login', ['login' => 'jane@hospital.test', 'password' => 'password']);
        $without->assertCookieMissing(\Auth::guard('web')->getRecallerName());
        $this->post('/logout');

        $with = $this->post('/login', ['login' => 'jane@hospital.test', 'password' => 'password', 'remember' => '1']);
        $with->assertCookie(\Auth::guard('web')->getRecallerName());
    }

    public function test_logout_ends_the_session(): void
    {
        $this->actingAs($this->makeUser('technician'))->post('/logout')->assertRedirect(route('login'));
        $this->assertGuest();
        $this->get('/dashboard')->assertRedirect(route('login'));
    }

    public function test_guests_are_redirected_and_api_returns_401_json(): void
    {
        $this->get('/dashboard')->assertRedirect(route('login'));
        $this->getJson('/api/dashboard')->assertStatus(401)->assertJsonPath('code', 'unauthenticated');
    }

    public function test_expired_session_is_sent_to_session_expired_screen(): void
    {
        $cookie = config('kbt.session_marker_cookie');

        $this->withCookie($cookie, '1')->get('/dashboard')->assertRedirect(route('session.expired'));
        $this->withCredentials()->withCookie($cookie, '1')->getJson('/api/dashboard')
            ->assertStatus(401)->assertJsonPath('code', 'session_expired')->assertJsonPath('redirect', route('session.expired'));

        $this->get('/session-expired')->assertStatus(401)->assertSee('Session Expired')->assertSee('Back to Login');
    }

    public function test_user_disabled_mid_session_is_signed_out(): void
    {
        $user = $this->makeUser('technician');
        $this->actingAs($user)->get('/dashboard')->assertOk();
        $user->forceFill(['status' => 'disabled'])->save();

        $this->get('/dashboard')->assertRedirect(route('login'));
        $this->assertGuest();
    }

    /* -------------------------------------------------------------------- OTP */

    private function loginNeedingOtp(): User
    {
        Mail::fake();
        config(['kbt.otp.required' => true]);
        $user = $this->makeUser('technician', attributes: ['email' => 'jane@hospital.test']);
        $this->post('/login', ['login' => 'jane@hospital.test', 'password' => 'password'])->assertRedirect(route('otp.show'));
        $this->assertGuest();

        return $user;
    }

    private function sentCode(): string
    {
        $code = null;
        Mail::assertSent(OtpMail::class, function (OtpMail $mail) use (&$code) { $code = $mail->code; return true; });

        return $code;
    }

    public function test_otp_login_flow_with_valid_code(): void
    {
        $user = $this->loginNeedingOtp();
        $this->get('/otp')->assertOk()->assertSee('OTP Verification')->assertSee('j**e@hospital.test', false);

        $code = $this->sentCode();
        $this->assertMatchesRegularExpression('/^\d{6}$/', $code);
        $this->assertDatabaseMissing('otp_verifications', ['code_hash' => $code]); // stored hashed only

        $this->post('/otp/verify', ['code' => $code])->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);
    }

    public function test_otp_cannot_be_reused_and_wrong_codes_are_rejected(): void
    {
        $this->loginNeedingOtp();
        $code = $this->sentCode();
        $wrong = $code === '000000' ? '111111' : '000000';

        $this->postJson('/otp/verify', ['code' => $wrong])->assertStatus(422)->assertJsonPath('data.status', 'invalid');
        $this->assertGuest();

        $this->postJson('/otp/verify', ['code' => $code])->assertOk();
        $this->post('/logout');

        // The same code is dead after use (new pending login needed anyway).
        $this->postJson('/otp/verify', ['code' => $code])->assertStatus(401);
    }

    public function test_otp_attempts_are_limited(): void
    {
        $this->loginNeedingOtp();
        $code = $this->sentCode();
        $wrong = $code === '000000' ? '111111' : '000000';

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/otp/verify', ['code' => $wrong])->assertStatus(422);
        }
        // Attempts exhausted: even the right code no longer works.
        $this->postJson('/otp/verify', ['code' => $code])->assertStatus(422)->assertJsonPath('data.status', 'missing');
        $this->assertGuest();
    }

    public function test_expired_otp_is_rejected(): void
    {
        $this->loginNeedingOtp();
        $code = $this->sentCode();
        $this->travel(4)->minutes();

        $this->postJson('/otp/verify', ['code' => $code])->assertStatus(422)->assertJsonPath('data.status', 'expired');
    }

    public function test_resend_has_cooldown_and_invalidates_previous_code(): void
    {
        $this->loginNeedingOtp();
        $first = $this->sentCode();

        $this->postJson('/otp/send')->assertStatus(429)->assertJsonStructure(['data' => ['retry_after']]);

        $this->travel(61)->seconds();
        $this->postJson('/otp/send')->assertOk();
        $codes = [];
        Mail::assertSent(OtpMail::class, function (OtpMail $m) use (&$codes) { $codes[] = $m->code; return true; });
        $second = end($codes);

        if ($first !== $second) {
            $this->postJson('/otp/verify', ['code' => $first])->assertStatus(422); // old code invalidated
        }
        $this->postJson('/otp/verify', ['code' => $second])->assertOk();
        $this->assertAuthenticated();
    }

    public function test_otp_step_requires_a_pending_login_and_validates_format(): void
    {
        $this->get('/otp')->assertRedirect(route('login'));
        $this->postJson('/otp/verify', ['code' => '123456'])->assertStatus(401);

        $this->loginNeedingOtp();
        $this->postJson('/otp/verify', ['code' => '12ab'])->assertStatus(422);
    }

    public function test_user_level_two_factor_triggers_otp_without_global_setting(): void
    {
        Mail::fake();
        $this->makeUser('technician', attributes: ['email' => 'jane@hospital.test', 'two_factor_enabled' => true]);
        $this->post('/login', ['login' => 'jane@hospital.test', 'password' => 'password'])->assertRedirect(route('otp.show'));
        Mail::assertSent(OtpMail::class);
    }
}
