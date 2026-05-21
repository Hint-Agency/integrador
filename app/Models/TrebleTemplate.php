<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TrebleTemplate extends Model
{
    use HasFactory;

    protected $table = 'treble_templates';

    protected $fillable = [
        'client_id',
        'name',
        'external_template_id',
        'payload_mapping',
        'request_template',
        'active',
    ];

    protected $casts = [
        'payload_mapping' => 'array',
        'request_template' => 'array',
        'active' => 'boolean',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function messageRules(): HasMany
    {
        return $this->hasMany(MessageRule::class);
    }
}
