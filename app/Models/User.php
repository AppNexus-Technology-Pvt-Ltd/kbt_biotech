<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    public const STATUS_ACTIVE = 'active';
    public const STATUS_INACTIVE = 'inactive';
    public const STATUS_DISABLED = 'disabled';

    public const DEFAULT_PREFERENCES = [
        'notify_email_security' => true,
        'notify_email_work_orders' => true,
        'language' => 'en',
        'density' => 'comfortable',
    ];

    protected $attributes = [
        'status' => self::STATUS_ACTIVE,
        'failed_login_attempts' => 0,
        'two_factor_enabled' => false,
    ];

    /** Fields users may never mass-assign through the profile API (employee id, role, department...). */
    protected $fillable = [
        'name', 'username', 'email', 'phone', 'password',
    ];

    protected $hidden = [
        'password', 'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'locked_until' => 'datetime',
            'last_login_at' => 'datetime',
            'password_changed_at' => 'datetime',
            'preferences' => 'array',
            'two_factor_enabled' => 'boolean',
        ];
    }

    /** @var array<string,bool>|null */
    private ?array $permissionCache = null;

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class)->withPivot('assigned_at');
    }

    public function departmentRecord(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Department::class, 'department_id');
    }

    public function facilities(): BelongsToMany
    {
        return $this->belongsToMany(Facility::class)->withPivot('is_primary');
    }

    /** @return array<string,bool> permission slug => true */
    public function permissionSlugs(): array
    {
        if ($this->permissionCache === null) {
            $this->permissionCache = Permission::query()
                ->whereIn('id', function ($q) {
                    $q->select('permission_role.permission_id')
                        ->from('permission_role')
                        ->join('role_user', 'role_user.role_id', '=', 'permission_role.role_id')
                        ->where('role_user.user_id', $this->getKey());
                })
                ->pluck('slug')
                ->mapWithKeys(fn ($s) => [$s => true])
                ->all();
        }

        return $this->permissionCache;
    }

    public function hasPermission(string $slug): bool
    {
        return isset($this->permissionSlugs()[$slug]);
    }

    public function flushPermissionCache(): void
    {
        $this->permissionCache = null;
    }

    public function hasRole(string $slug): bool
    {
        return $this->roles()->where('slug', $slug)->exists();
    }

    public function canAccessAllFacilities(): bool
    {
        return $this->hasPermission('facilities.access_all');
    }

    /** Facilities this user may see. */
    public function accessibleFacilities()
    {
        $query = Facility::query()->where('is_active', true)->orderBy('name');

        return $this->canAccessAllFacilities()
            ? $query
            : $query->whereIn('id', $this->facilities()->pluck('facilities.id'));
    }

    public function canAccessFacility(int $facilityId): bool
    {
        return $this->canAccessAllFacilities()
            || $this->facilities()->where('facilities.id', $facilityId)->exists();
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function isLocked(): bool
    {
        return $this->locked_until !== null && $this->locked_until->isFuture();
    }

    public function preference(string $key): mixed
    {
        return ($this->preferences[$key] ?? null) ?? self::DEFAULT_PREFERENCES[$key] ?? null;
    }

    public function initials(): string
    {
        $parts = preg_split('/\s+/', trim($this->name)) ?: [];
        $letters = array_map(fn ($p) => mb_strtoupper(mb_substr($p, 0, 1)), array_slice($parts, 0, 2));

        return implode('', $letters) ?: '?';
    }

    public function photoUrl(): ?string
    {
        return $this->profile_photo_path
            ? route('profile.photo', ['user' => $this->id, 'v' => substr(md5($this->profile_photo_path), 0, 8)])
            : null;
    }

    public function primaryRoleName(): ?string
    {
        return $this->roles->first()?->name;
    }
}
