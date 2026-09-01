<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class HubspotOwner extends Model
{
    use HasFactory;

    protected $fillable = [
        'client_id',
        'name',
        'external_owner_id',
        'email',
        'active',
    ];

    protected $casts = [
        'active' => 'boolean',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function automationFlows(): BelongsToMany
    {
        return $this->belongsToMany(
            AutomationFlow::class,
            'hubspot_owner_owner_assignment_rule',
            'hubspot_owner_id',
            'owner_assignment_rule_id'
        );
    }
}
