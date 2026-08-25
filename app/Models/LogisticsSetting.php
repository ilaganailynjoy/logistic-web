<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LogisticsSetting extends Model
{
    use HasFactory;

    protected $table = 'logistics_settings';

    public const NOTIFICATION_KEYS = [
        'rider_applications',
        'delivery_requests',
        'failed_deliveries',
        'failed_pickups',
        'rider_status_updates',
        'delivery_completed',
        'new_messages',
    ];

    protected $fillable = [
        'user_id',
        'photo_path',
        'notifications',
        'email_notifications',
        'delivery',
    ];

    protected function casts(): array
    {
        return [
            'notifications' => 'array',
            'email_notifications' => 'boolean',
            'delivery' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function forUser(int $userId): self
    {
        return static::firstOrCreate(
            ['user_id' => $userId],
            [
                'notifications' => array_fill_keys(static::NOTIFICATION_KEYS, true),
                'email_notifications' => true,
                'delivery' => [
                    'require_proof' => true,
                    'max_attempts' => 2,
                    'allow_reassignment' => true,
                ],
            ]
        );
    }

    public function notificationEnabled(string $key): bool
    {
        return (bool) ($this->notifications[$key] ?? false);
    }

    public static function vehicleCapacities(): array
    {
        $fromTable = VehicleType::capacityMap();

        if (!empty($fromTable)) {
            return $fromTable;
        }

        return config('logistics.vehicle_capacities', []);
    }
}
