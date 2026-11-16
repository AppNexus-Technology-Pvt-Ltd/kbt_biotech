<?php

namespace Tests\Feature;

use App\Mail\PasswordChangedMail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRbac();
    }

    public function test_profile_page_and_api_show_database_values(): void
    {
        $f = $this->makeFacility('City General');
        $user = $this->makeUser('biomedical_engineer', [$f->id], [
            'name' => 'John Smith', 'employee_id' => 'EMP-1023', 'department' => 'Biomedical Engineering', 'phone' => '+91 98765 43210',
        ]);

        $this->actingAs($user)->get('/profile')->assertOk()
            ->assertSee('EMP-1023')->assertSee('Biomedical Engineering')->assertSee('City General')->assertSee('Biomedical Engineer');
        $this->getJson('/api/user/profile')->assertOk()->assertJsonPath('data.employee_id', 'EMP-1023')->assertJsonPath('data.facility', 'City General');
    }

    public function test_user_can_update_permitted_fields_and_change_is_audited(): void
    {
        $user = $this->makeUser('technician', attributes: ['name' => 'Old Name', 'email' => 'old@x.test']);

        $this->actingAs($user)->putJson('/api/user/profile', ['name' => 'New Name', 'email' => 'NEW@x.test', 'phone' => '+91 98765 43210'])
            ->assertOk()->assertJsonPath('data.name', 'New Name');

        $user->refresh();
        $this->assertSame('new@x.test', $user->email);
        $log = DB::table('audit_logs')->where('action', 'profile.updated')->first();
        $this->assertNotNull($log);
        $this->assertSame('Old Name', json_decode($log->old_values, true)['name']);
        $this->assertSame('New Name', json_decode($log->new_values, true)['name']);
    }

    public function test_protected_fields_cannot_be_modified_through_the_profile_api(): void
    {
        $user = $this->makeUser('technician', attributes: ['employee_id' => 'EMP-1', 'department' => 'Ops', 'status' => 'active']);

        $this->actingAs($user)->putJson('/api/user/profile', [
            'name' => 'Same', 'email' => $user->email, 'employee_id' => 'EMP-HACK', 'department' => 'Hacked', 'status' => 'disabled',
            'role' => 'super_admin', 'roles' => [1], 'password' => 'Hacked1!pass',
        ])->assertOk();

        $user->refresh();
        $this->assertSame('EMP-1', $user->employee_id);
        $this->assertSame('Ops', $user->department);
        $this->assertSame('active', $user->status);
        $this->assertTrue($user->hasRole('technician'));
        $this->assertFalse($user->hasRole('super_admin'));
        $this->assertTrue(\Hash::check('password', $user->password));
    }

    public function test_profile_validation(): void
    {
        $other = $this->makeUser(attributes: ['email' => 'taken@x.test']);
        $user = $this->makeUser();

        $this->actingAs($user)->putJson('/api/user/profile', ['name' => '', 'email' => 'bad', 'phone' => 'abc'])
            ->assertStatus(422)->assertJsonValidationErrors(['name', 'email', 'phone']);
        $this->putJson('/api/user/profile', ['name' => 'Ok', 'email' => 'taken@x.test'])->assertStatus(422)->assertJsonValidationErrors('email');
        $this->putJson('/api/user/profile', ['name' => 'Ok', 'email' => $user->email, 'preferences' => ['language' => 'xx']])->assertStatus(422);
    }

    public function test_preferences_are_saved(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user)->putJson('/api/user/profile', [
            'name' => $user->name, 'email' => $user->email,
            'preferences' => ['density' => 'compact', 'language' => 'hi', 'notify_email_work_orders' => false],
        ])->assertOk();

        $prefs = $user->fresh()->preferences;
        $this->assertSame('compact', $prefs['density']);
        $this->assertFalse($prefs['notify_email_work_orders']);
        $this->get('/profile')->assertSee('data-density="compact"', false);
    }

    public function test_photo_upload_replaces_old_photo_and_is_served_to_authenticated_users_only(): void
    {
        Storage::fake('local');
        $user = $this->makeUser();

        $file = UploadedFile::fake()->createWithContent('me.png', $this->pngBytes());
        $res = $this->actingAs($user)->postJson('/api/user/profile/photo', ['photo' => $file])->assertOk();
        $first = $user->fresh()->profile_photo_path;
        Storage::disk('local')->assertExists($first);
        $this->get($res->json('data.photo_url'))->assertOk();

        $this->postJson('/api/user/profile/photo', ['photo' => UploadedFile::fake()->createWithContent('me2.png', $this->pngBytes(100))])->assertOk();
        Storage::disk('local')->assertMissing($first);

        auth()->logout();
        $this->app['auth']->forgetGuards();
        $this->get(route('profile.photo', $user))->assertRedirect(route('login'));
    }

    public function test_photo_upload_rejects_non_images_and_oversize_files(): void
    {
        Storage::fake('local');
        $user = $this->makeUser();
        $this->actingAs($user)->postJson('/api/user/profile/photo', ['photo' => UploadedFile::fake()->createWithContent('x.png', '<?php echo 1;')])->assertStatus(422);
        $this->postJson('/api/user/profile/photo', ['photo' => UploadedFile::fake()->create('big.png', 5000, 'image/png')])->assertStatus(422);
        $this->postJson('/api/user/profile/photo', ['photo' => UploadedFile::fake()->createWithContent('tiny.png', $this->pngBytes(8))])->assertStatus(422);
    }

    public function test_change_password_validates_and_signs_out_other_devices(): void
    {
        Mail::fake();
        $user = $this->makeUser();
        DB::table('sessions')->insert(['id' => 'other-device', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);

        $this->actingAs($user)->putJson('/api/user/password', ['current_password' => 'wrong', 'password' => 'NewPassw0rd!', 'password_confirmation' => 'NewPassw0rd!'])
            ->assertStatus(422)->assertJsonValidationErrors('current_password');
        $this->putJson('/api/user/password', ['current_password' => 'password', 'password' => 'weak', 'password_confirmation' => 'weak'])
            ->assertStatus(422)->assertJsonValidationErrors('password');
        $this->putJson('/api/user/password', ['current_password' => 'password', 'password' => 'NewPassw0rd!', 'password_confirmation' => 'Mismatch1!'])
            ->assertStatus(422);

        $this->putJson('/api/user/password', ['current_password' => 'password', 'password' => 'NewPassw0rd!', 'password_confirmation' => 'NewPassw0rd!'])->assertOk();
        $this->assertTrue(\Hash::check('NewPassw0rd!', $user->fresh()->password));
        $this->assertDatabaseMissing('sessions', ['id' => 'other-device']);
        Mail::assertSent(PasswordChangedMail::class);
        $this->assertDatabaseHas('audit_logs', ['user_id' => $user->id, 'action' => 'auth.password_changed']);
    }

    public function test_sessions_can_be_listed_and_revoked_but_ids_are_not_raw(): void
    {
        $user = $this->makeUser();
        $stranger = $this->makeUser();
        DB::table('sessions')->insert([
            ['id' => 'raw-session-id-1', 'user_id' => $user->id, 'ip_address' => '10.0.0.5', 'user_agent' => 'Mozilla/5.0 (Windows NT 10.0) Chrome/120 Safari/537', 'payload' => '', 'last_activity' => time()],
            ['id' => 'stranger-session', 'user_id' => $stranger->id, 'ip_address' => null, 'user_agent' => null, 'payload' => '', 'last_activity' => time()],
        ]);

        $list = $this->actingAs($user)->getJson('/api/user/sessions')->assertOk()->json('data');
        $this->assertCount(1, $list);
        $this->assertSame('Chrome on Windows', $list[0]['device']);
        $this->assertStringNotContainsString('raw-session-id-1', json_encode($list));

        $this->deleteJson('/api/user/sessions/'.str_repeat('a', 32))->assertStatus(404);
        $this->deleteJson('/api/user/sessions/'.$list[0]['id'])->assertOk();
        $this->assertDatabaseMissing('sessions', ['id' => 'raw-session-id-1']);
        $this->assertDatabaseHas('sessions', ['id' => 'stranger-session']);
    }

    public function test_logout_other_devices_requires_password(): void
    {
        $user = $this->makeUser();
        DB::table('sessions')->insert(['id' => 'dev2', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);

        $this->actingAs($user)->deleteJson('/api/user/sessions', ['password' => 'bad'])->assertStatus(422);
        $this->assertDatabaseHas('sessions', ['id' => 'dev2']);
        $this->deleteJson('/api/user/sessions', ['password' => 'password'])->assertOk();
        $this->assertDatabaseMissing('sessions', ['id' => 'dev2']);
    }

    public function test_two_factor_can_be_toggled_with_password_confirmation(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user)->putJson('/api/user/two-factor', ['enabled' => true, 'password' => 'bad'])->assertStatus(422);
        $this->putJson('/api/user/two-factor', ['enabled' => true, 'password' => 'password'])->assertOk()->assertJsonPath('data.two_factor_enabled', true);
        $this->assertTrue($user->fresh()->two_factor_enabled);
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.two_factor_changed']);
    }

    public function test_notifications_endpoint_lists_and_marks_read(): void
    {
        $user = $this->makeUser();
        $user->notify(new \App\Notifications\SecurityNotice('Hello', 'World'));

        $this->actingAs($user)->getJson('/api/notifications')->assertOk()->assertJsonPath('data.unread', 1)->assertJsonPath('data.items.0.title', 'Hello');
        $this->postJson('/api/notifications/read')->assertOk();
        $this->getJson('/api/notifications')->assertJsonPath('data.unread', 0);
    }

    public function test_access_denied_screen_is_available_to_signed_in_users(): void
    {
        $this->actingAs($this->makeUser('technician'))->get('/access-denied')->assertOk()->assertSee('Access Denied')->assertSee('Go to Dashboard');
    }
}
