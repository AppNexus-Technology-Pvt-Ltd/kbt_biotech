<x-layouts.app title="My Profile">
    @php $p = $profile; $u = auth()->user(); @endphp
    <h1 class="page-title">User Profile</h1>
    <p class="page-sub">View and edit personal details, preferences and account security</p>

    <div data-profile data-api="{{ route('api.profile.show') }}" data-update="{{ route('api.profile.update') }}" data-photo="{{ route('api.profile.photo') }}"
         data-password="{{ route('api.password') }}" data-two-factor="{{ route('api.two-factor') }}" data-sessions="{{ route('api.sessions') }}"
         data-sessions-others="{{ route('api.sessions.destroy-others') }}" data-otp-enforced="{{ $p['otp_enforced'] ? '1' : '0' }}">

        <section class="card">
            <div class="profile-head">
                <div class="profile-head__who">
                    <div class="avatar-edit">
                        <x-avatar :user="$u" large />
                        <button type="button" data-photo-trigger aria-label="Change profile photo"><x-icon name="camera" /></button>
                        <input type="file" accept="image/png,image/jpeg,image/webp" hidden data-photo-input>
                    </div>
                    <div>
                        <div style="font-size:18px;font-weight:700" data-view="name">{{ $p['name'] }}</div>
                        <div style="color:var(--muted)">{{ $p['designation'] ?? '—' }}</div>
                        <div style="font-size:13px;color:var(--muted);margin-top:4px">
                            <span data-view="email">{{ $p['email'] }}</span>@if($p['phone']) &nbsp;·&nbsp; <span data-view="phone">{{ $p['phone'] }}</span>@endif
                        </div>
                    </div>
                </div>
                <button type="button" class="btn btn--outline btn--sm btn--auto" data-edit-toggle>Edit</button>
            </div>

            <div class="tabs" role="tablist" aria-label="Profile sections">
                <button class="tab" role="tab" id="tab-info" aria-controls="panel-info" aria-selected="true" data-tab="info">Personal Info</button>
                <button class="tab" role="tab" id="tab-prefs" aria-controls="panel-prefs" aria-selected="false" data-tab="prefs" tabindex="-1">Preferences</button>
                <button class="tab" role="tab" id="tab-security" aria-controls="panel-security" aria-selected="false" data-tab="security" tabindex="-1">Security</button>
            </div>

            {{-- Personal info --}}
            <div class="tab-panel" role="tabpanel" id="panel-info" aria-labelledby="tab-info" data-panel="info">
                <form data-profile-form novalidate>
                    <div class="form-grid">
                        <x-input label="Full name" name="name" icon="user" :value="$p['name']" required readonly data-editable />
                        <x-input label="Email" name="email" type="email" icon="mail" :value="$p['email']" required readonly data-editable />
                        <x-input label="Phone number" name="phone" type="tel" icon="phone" :value="$p['phone']" placeholder="+91 98765 43210" readonly data-editable />
                    </div>
                    <div data-edit-actions hidden style="display:none;gap:10px;margin-bottom:20px">
                        <x-button class="btn--auto" loading="Saving…">Save changes</x-button>
                        <button type="button" class="btn btn--outline btn--auto" data-edit-cancel>Cancel</button>
                    </div>
                </form>

                <div class="kv" aria-label="Account details (managed by administrators)">
                    <div>Employee ID</div><div>{{ $p['employee_id'] ?? '—' }}</div>
                    <div>Role</div><div>{{ $p['role'] ?? '—' }}</div>
                    <div>Department</div><div>{{ $p['department'] ?? '—' }}</div>
                    <div>Facility</div><div>{{ $p['facility'] ?? '—' }}</div>
                    <div>Status</div><div><span class="tag {{ $p['status'] === 'active' ? 'tag--ok' : 'tag--off' }}">{{ ucfirst($p['status']) }}</span></div>
                </div>
                <p class="field__hint" style="margin-top:12px">Employee ID, role, department and facility can only be changed by an administrator.</p>
            </div>

            {{-- Preferences --}}
            <div class="tab-panel" role="tabpanel" id="panel-prefs" aria-labelledby="tab-prefs" data-panel="prefs" hidden>
                <form data-prefs-form novalidate>
                    <h2 class="section-title">Notification preferences</h2>
                    <label class="toggle-row"><span>Security alerts by email<small>Password changes and new sign-ins</small></span>
                        <input type="checkbox" name="notify_email_security" @checked($p['preferences']['notify_email_security'])></label>
                    <label class="toggle-row"><span>Work order emails<small>Assignments and status changes</small></span>
                        <input type="checkbox" name="notify_email_work_orders" @checked($p['preferences']['notify_email_work_orders'])></label>

                    <h2 class="section-title section-gap">Language &amp; display</h2>
                    <div class="form-grid">
                        <div class="field"><label class="field__label" for="pref-language">Language</label>
                            <select class="select" id="pref-language" name="language">
                                <option value="en" @selected($p['preferences']['language'] === 'en')>English</option>
                                <option value="hi" @selected($p['preferences']['language'] === 'hi')>हिन्दी (Hindi)</option>
                            </select>
                            <div class="field__hint">Your choice is saved; translations are added module by module.</div></div>
                        <div class="field"><label class="field__label" for="pref-density">Table density</label>
                            <select class="select" id="pref-density" name="density">
                                <option value="comfortable" @selected($p['preferences']['density'] === 'comfortable')>Comfortable</option>
                                <option value="compact" @selected($p['preferences']['density'] === 'compact')>Compact</option>
                            </select></div>
                    </div>
                    <div class="field__error" data-form-error role="alert"></div>
                    <x-button class="btn--auto" loading="Saving…">Save preferences</x-button>
                </form>
            </div>

            {{-- Security --}}
            <div class="tab-panel" role="tabpanel" id="panel-security" aria-labelledby="tab-security" data-panel="security" hidden>
                <h2 class="section-title">Change password</h2>
                <form data-password-form novalidate style="max-width:460px">
                    <x-password-input label="Current password" name="current_password" autocomplete="current-password" required />
                    <x-password-input label="New password" name="password" autocomplete="new-password" required data-password-rules />
                    <ul class="checklist" data-checklist>
                        <li data-rule="length"><span class="dot"><x-icon name="check" /></span> At least 8 characters</li>
                        <li data-rule="upper"><span class="dot"><x-icon name="check" /></span> One uppercase letter</li>
                        <li data-rule="lower"><span class="dot"><x-icon name="check" /></span> One lowercase letter</li>
                        <li data-rule="number"><span class="dot"><x-icon name="check" /></span> One number</li>
                        <li data-rule="special"><span class="dot"><x-icon name="check" /></span> One special character</li>
                    </ul>
                    <x-password-input label="Confirm new password" name="password_confirmation" autocomplete="new-password" required />
                    <x-button class="btn--auto" loading="Updating…">Change password</x-button>
                </form>

                <div class="section-gap">
                    <h2 class="section-title">Sign-in &amp; verification</h2>
                    <div class="toggle-row"><span>Last login<small data-last-login>{{ $p['last_login_at'] ? '' : 'No previous login recorded' }}</small></span></div>
                    <div class="toggle-row">
                        <span>Two-factor authentication (email code)
                            <small>@if($p['otp_enforced']) Required for all users by your organisation. @else Ask for a code sent to your email at every sign-in. @endif</small></span>
                        <span style="display:flex;gap:10px;align-items:center">
                            <span class="tag {{ ($p['two_factor_enabled'] || $p['otp_enforced']) ? 'tag--ok' : 'tag--off' }}" data-2fa-tag>{{ ($p['two_factor_enabled'] || $p['otp_enforced']) ? 'Enabled' : 'Disabled' }}</span>
                            @unless($p['otp_enforced'])
                                <button type="button" class="btn btn--outline btn--sm btn--auto" data-2fa-toggle data-enabled="{{ $p['two_factor_enabled'] ? '1' : '0' }}">{{ $p['two_factor_enabled'] ? 'Disable' : 'Enable' }}</button>
                            @endunless
                        </span>
                    </div>
                </div>

                <div class="section-gap">
                    <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap">
                        <h2 class="section-title" style="margin:0">Active sessions</h2>
                        <button type="button" class="btn btn--outline btn--sm btn--auto" data-logout-others>Log out from other devices</button>
                    </div>
                    <div data-sessions-list style="margin-top:8px"><div class="search-empty">Loading…</div></div>
                </div>

                <div class="section-gap">
                    <h2 class="section-title">Recent account activity</h2>
                    <div data-activity-list></div>
                </div>
            </div>
        </section>
    </div>
</x-layouts.app>
