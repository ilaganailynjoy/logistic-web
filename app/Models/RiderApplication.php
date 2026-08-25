<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RiderApplication extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'email',
        'phone',
        'address',
        'vehicle_type',
        'license_plate',
        'license_number',
        'vehicle_registration',
        'documents',
        'status',
        'notes',
        'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => 'string',
            'reviewed_at' => 'datetime',
            'documents' => 'array',
        ];
    }

    public function logs(): HasMany
    {
        return $this->hasMany(RiderApplicationLog::class)->latest();
    }

    public function changer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
