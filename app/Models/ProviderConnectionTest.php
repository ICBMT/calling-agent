<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProviderConnectionTest extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['applied' => 'boolean', 'created_at' => 'datetime'];
    }

    public function configuration(): BelongsTo
    {
        return $this->belongsTo(ProviderConfiguration::class, 'provider_configuration_id');
    }
}
