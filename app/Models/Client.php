<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Client extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'description',
        'active',
    ];

    protected $casts = [
        'active' => 'boolean',
    ];

    public function platformConnections(): HasMany
    {
        return $this->hasMany(PlatformConnection::class);
    }

    public function trebleTemplates(): HasMany
    {
        return $this->hasMany(TrebleTemplate::class);
    }

    public function messageRules(): HasMany
    {
        return $this->hasMany(MessageRule::class);
    }

    public function hubspotOwners(): HasMany
    {
        return $this->hasMany(HubspotOwner::class);
    }

    public function automationFlows(): HasMany
    {
        return $this->hasMany(AutomationFlow::class);
    }

    public function records(): HasMany
    {
        return $this->hasMany(Record::class);
    }
}
