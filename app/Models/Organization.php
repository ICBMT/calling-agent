<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Organization extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'slug', 'timezone', 'default_language'];

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withPivot('role')->withTimestamps();
    }

    public function agents(): HasMany
    {
        return $this->hasMany(Agent::class);
    }

    public function calls(): HasMany
    {
        return $this->hasMany(CallRecord::class);
    }

    public function providerConfigurations(): HasMany
    {
        return $this->hasMany(ProviderConfiguration::class);
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class);
    }
}
