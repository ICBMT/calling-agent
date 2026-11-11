<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasFactory, Notifiable;

    protected $attributes = ['is_platform_admin' => false];

    protected $fillable = ['name', 'email', 'password'];

    protected $hidden = ['password', 'remember_token', 'is_platform_admin'];

    protected function casts(): array
    {
        return ['is_platform_admin' => 'boolean', 'email_verified_at' => 'datetime', 'password' => 'hashed'];
    }

    public function organizations(): BelongsToMany
    {
        return $this->belongsToMany(Organization::class)->withPivot('role')->withTimestamps();
    }

    public function currentOrganization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'current_organization_id');
    }

    /** Recheck persisted operator rights, including in long-lived server-side callers. */
    public function isPlatformProviderOperator(): bool
    {
        return $this->exists && self::query()->whereKey($this->getKey())->where('is_platform_admin', true)->exists();
    }

    public function hasOrganizationRole(Organization $organization, array $roles): bool
    {
        return $this->organizations()->whereKey($organization->id)->wherePivotIn('role', $roles)->exists();
    }
}
