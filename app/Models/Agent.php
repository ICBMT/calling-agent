<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Agent extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = ['name', 'purpose', 'description', 'personality', 'language', 'instructions', 'greeting'];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function calls(): HasMany
    {
        return $this->hasMany(CallRecord::class);
    }
}
