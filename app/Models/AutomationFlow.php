<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AutomationFlow extends Model
{
    use HasFactory;

    protected $table = 'owner_assignment_rules';

    protected $fillable = [
        'client_id',
        'name',
        'priority',
        'trigger_property',
        'trigger_value',
        'conditions',
        'owner_assignment_enabled',
        'owner_property',
        'owner_selection_strategy',
        'existing_owner_behavior',
        'continue_to_treble',
        'active',
    ];

    protected $casts = [
        'conditions' => 'array',
        'owner_assignment_enabled' => 'boolean',
        'continue_to_treble' => 'boolean',
        'active' => 'boolean',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function owners(): BelongsToMany
    {
        return $this->belongsToMany(
            HubspotOwner::class,
            'hubspot_owner_owner_assignment_rule',
            'owner_assignment_rule_id',
            'hubspot_owner_id'
        );
    }

    public function messageRules(): HasMany
    {
        return $this->hasMany(MessageRule::class, 'automation_flow_id');
    }

    public function getOwnerIdsAttribute(): array
    {
        return $this->owners
            ->where('active', true)
            ->pluck('external_owner_id')
            ->map(fn (mixed $ownerId): string => (string) $ownerId)
            ->values()
            ->all();
    }
}
