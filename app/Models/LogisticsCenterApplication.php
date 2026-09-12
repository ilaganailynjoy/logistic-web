<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LogisticsCenterApplication extends Model
{
    protected $fillable = [
        'business_name',
        'owner_name',
        'email',
        'phone',
        'house_number',
        'street',
        'barangay',
        'municipality',
        'province',
        'address',
        'status',
        'submitted_via',
        'notes',
        'reviewed_at',
        'approved_by',
        'provisioned_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => 'string',
            'reviewed_at' => 'datetime',
            'provisioned_at' => 'datetime',
        ];
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function supportingDocuments(): HasMany
    {
        return $this->hasMany(LogisticsCenterApplicationDocument::class)
            ->orderBy('document_type');
    }

    /**
     * Full address composed from the structured PSGC fields, or the raw
     * address when only that was supplied.
     */
    public function composedAddress(): string
    {
        if ($this->address) {
            return $this->address;
        }

        return trim(collect([
            $this->house_number,
            $this->street,
            $this->barangay,
            $this->municipality,
            $this->province,
        ])->implode(', '), ' ,');
    }
}