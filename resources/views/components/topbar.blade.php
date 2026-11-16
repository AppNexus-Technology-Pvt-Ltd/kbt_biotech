@php
    $user = auth()->user();
    $facilities = $user->accessibleFacilities()->get(['id', 'name']);
    $selected = \App\Services\FacilityContext::selectedId($user);
    $roleName = $user->designation ?: $user->primaryRoleName();
@endphp
<header class="topbar">
    <button type="button" class="icon-btn topbar__menu" data-sidebar-open aria-label="Open menu"><x-icon name="menu" /></button>

    <form method="POST" action="{{ route('facility.switch') }}" class="facility-select" data-facility-form>
        @csrf
        <x-icon name="building" />
        <select name="facility_id" aria-label="Current facility" data-facility-select @disabled($facilities->isEmpty() && ! $user->canAccessAllFacilities())>
            @if($user->canAccessAllFacilities() || $facilities->count() > 1)
                <option value="">All facilities</option>
            @elseif($facilities->isEmpty())
                <option value="">No facility assigned</option>
            @endif
            @foreach($facilities as $f)
                <option value="{{ $f->id }}" @selected($selected === $f->id)>{{ $f->name }}</option>
            @endforeach
        </select>
        <x-icon name="chevron-down" />
    </form>

    <div class="search" data-search role="search">
        <label for="global-search" class="sr-only">Search assets and work orders</label>
        <div class="input-wrap">
            <x-icon name="search" />
            <input id="global-search" type="search" placeholder="Search assets, work orders, etc..." autocomplete="off"
                   aria-controls="search-results" aria-expanded="false" data-search-input>
        </div>
        <div class="search-results" id="search-results" hidden data-search-results></div>
    </div>

    <div class="topbar__spacer"></div>

    <div class="menu" data-menu>
        <button type="button" class="icon-btn" aria-haspopup="true" aria-expanded="false" aria-label="Notifications" data-menu-trigger data-notifications-trigger>
            <x-icon name="bell" /><span class="badge-dot" data-notification-count hidden>0</span>
        </button>
        <div class="menu__panel" hidden style="min-width:320px" data-menu-panel>
            <div class="menu__head" style="display:flex;justify-content:space-between;align-items:center">
                <strong>Notifications</strong>
                <button type="button" class="link" data-notifications-read style="font-size:12px">Mark all read</button>
            </div>
            <div data-notification-list><div class="search-empty">Loading…</div></div>
        </div>
    </div>

    <div class="menu" data-menu>
        <button type="button" class="profile-trigger" aria-haspopup="true" aria-expanded="false" data-menu-trigger>
            <x-avatar :user="$user" />
            <span class="profile-trigger__text"><strong>{{ $user->name }}</strong><small>{{ $roleName }}</small></span>
            <x-icon name="chevron-down" style="width:14px;height:14px;color:var(--muted)" />
        </button>
        <div class="menu__panel" hidden data-menu-panel>
            <div class="menu__head"><strong>{{ $user->name }}</strong><small>{{ $user->email }}</small></div>
            <a class="menu__item" href="{{ route('profile') }}"><x-icon name="user" /> My Profile</a>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="menu__item"><x-icon name="log-out" /> Sign out</button>
            </form>
        </div>
    </div>
</header>
