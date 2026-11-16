<?php

namespace Tests\Feature;

use App\Mail\PasswordChangedMail;
use App\Mail\PasswordResetLinkMail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    private const STRONG = 'NewPassw0rd!';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRbac();
        Mail::fake();
    }

    private function requestToken(string $email = 'jane@hospital.test'): string
    {
        $this->post('/forgot-password', ['email' => $email]);
        $url = null;
        Mail::assertSent(PasswordResetLinkMail::class, function ($m) use (&$url) { $url = $m->url; return true; });

        return basename(parse_url($url, PHP_URL_PATH));
    }

    public function test_forgot_password_stores_only_a_hash_and_sends_the_link(): void
    {
        $this->makeUser(attributes: ['email' => 'jane@hospital.test']);
        $token = $this->requestToken();

        $this->assertSame(64, strlen($token));
        $row = DB::table('password_reset_tokens')->where('email', 'jane@hospital.test')->first();
        $this->assertSame(hash('sha256', $token), $row->token);
        $this->assertNotSame($token, $row->token);
    }

    public function test_response_is_identical_for_unknown_emails_and_no_mail_is_sent(): void
    {
        $this->makeUser(attributes: ['email' => 'jane@hospital.test']);

        $known = $this->postJson('/forgot-password', ['email' => 'jane@hospital.test']);
        Mail::fake(); // reset
        $unknown = $this->postJson('/forgot-password', ['email' => 'ghost@hospital.test']);

        $this->assertSame($known->json('message'), $unknown->json('message'));
        $unknown->assertOk();
        Mail::assertNothingSent();
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => 'ghost@hospital.test']);
    }

    public function test_forgot_password_validates_email_and_is_rate_limited(): void
    {
        $this->postJson('/forgot-password', ['email' => 'not-an-email'])->assertStatus(422);
        for ($i = 0; $i < 4; $i++) {
            $this->postJson('/forgot-password', ['email' => 'a@b.test'])->assertOk();
        }
        $this->postJson('/forgot-password', ['email' => 'a@b.test'])->assertOk(); // 5th (1 failed validation did not count)
        $this->postJson('/forgot-password', ['email' => 'a@b.test'])->assertStatus(429);
    }

    public function test_a_new_request_invalidates_the_previous_link(): void
    {
        $this->makeUser(attributes: ['email' => 'jane@hospital.test']);
        $first = $this->requestToken();
        Mail::fake();
        $second = $this->requestToken();

        $this->assertNotSame($first, $second);
        $this->get("/reset-password/$first")->assertSee('Link expired');
        $this->get("/reset-password/$second")->assertSee('Reset Password')->assertDontSee('Link expired');
    }

    public function test_password_can_be_reset_once_and_everything_is_cleaned_up(): void
    {
        $user = $this->makeUser(attributes: ['email' => 'jane@hospital.test']);
        DB::table('sessions')->insert(['id' => 'abc', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);
        $token = $this->requestToken();

        $this->post('/reset-password', ['token' => $token, 'password' => self::STRONG, 'password_confirmation' => self::STRONG])
            ->assertRedirect(route('login'));

        $this->assertDatabaseMissing('password_reset_tokens', ['email' => 'jane@hospital.test']);
        $this->assertDatabaseMissing('sessions', ['user_id' => $user->id]);
        Mail::assertSent(PasswordChangedMail::class);
        $this->assertDatabaseHas('audit_logs', ['user_id' => $user->id, 'action' => 'auth.password_reset']);
        $this->assertSame(1, $user->fresh()->notifications()->count());

        $this->post('/login', ['login' => 'jane@hospital.test', 'password' => self::STRONG])->assertRedirect(route('dashboard'));
        $this->post('/logout');

        // token cannot be reused
        $this->postJson('/reset-password', ['token' => $token, 'password' => 'Another1!pass', 'password_confirmation' => 'Another1!pass'])->assertStatus(422);
    }

    public function test_expired_token_is_rejected(): void
    {
        $this->makeUser(attributes: ['email' => 'jane@hospital.test']);
        $token = $this->requestToken();
        $this->travel(61)->minutes();

        $this->get("/reset-password/$token")->assertSee('Link expired');
        $this->postJson('/reset-password', ['token' => $token, 'password' => self::STRONG, 'password_confirmation' => self::STRONG])->assertStatus(422);
    }

    public function test_weak_or_mismatched_passwords_are_rejected(): void
    {
        $this->makeUser(attributes: ['email' => 'jane@hospital.test']);
        $token = $this->requestToken();

        foreach (['Sh0rt!A', 'alllowercase1!', 'ALLUPPERCASE1!', 'NoNumbers!!', 'NoSpecial123A'] as $weak) {
            $this->postJson('/reset-password', ['token' => $token, 'password' => $weak, 'password_confirmation' => $weak])
                ->assertStatus(422)->assertJsonValidationErrors('password');
        }
        $this->postJson('/reset-password', ['token' => $token, 'password' => self::STRONG, 'password_confirmation' => 'Different1!'])
            ->assertStatus(422)->assertJsonValidationErrors('password');

        $this->assertDatabaseHas('password_reset_tokens', ['email' => 'jane@hospital.test']); // still usable
    }
}
