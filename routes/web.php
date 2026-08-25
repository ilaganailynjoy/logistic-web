<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DeliveryController;
use App\Http\Controllers\RiderController;
use App\Http\Controllers\RiderApplicationController;
use App\Http\Controllers\TrackingController;
use App\Http\Controllers\SettingController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\MessageController;
use App\Http\Controllers\VehicleTypeController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect()->route('login');
});

Route::get('/dashboard', [DashboardController::class, 'index'])
    ->middleware(['auth', 'verified', 'staff'])
    ->name('dashboard');

Route::middleware(['auth', 'verified', 'staff'])->group(function () {
    Route::resource('deliveries', DeliveryController::class);
    Route::post('deliveries/{delivery}/assign-rider', [DeliveryController::class, 'assignRider'])->name('deliveries.assign-rider');
    Route::patch('deliveries/{delivery}/update-status', [DeliveryController::class, 'updateStatus'])->name('deliveries.update-status');
    Route::post('deliveries/{delivery}/archive', [DeliveryController::class, 'archive'])->name('deliveries.archive');
    Route::post('deliveries/{delivery}/restore', [DeliveryController::class, 'restore'])->name('deliveries.restore');
    Route::get('deliveries-archived', [DeliveryController::class, 'archived'])->name('deliveries.archived');

    Route::resource('riders', RiderController::class);
    Route::post('riders/{rider}/verify-vehicle', [RiderController::class, 'verifyVehicle'])->name('riders.verify-vehicle');
    Route::post('riders/{rider}/reject-vehicle', [RiderController::class, 'rejectVehicle'])->name('riders.reject-vehicle');

    Route::get('vehicle-types', [VehicleTypeController::class, 'index'])->name('vehicle-types.index');
    Route::post('vehicle-types', [VehicleTypeController::class, 'store'])->name('vehicle-types.store');
    Route::put('vehicle-types/{vehicleType}', [VehicleTypeController::class, 'update'])->name('vehicle-types.update');
    Route::post('vehicle-types/{vehicleType}/toggle', [VehicleTypeController::class, 'toggle'])->name('vehicle-types.toggle');

    Route::resource('rider-applications', RiderApplicationController::class)->only(['index', 'show']);
    Route::post('rider-applications/{riderApplication}/approve', [RiderApplicationController::class, 'approve'])->name('rider-applications.approve');
    Route::post('rider-applications/{riderApplication}/reject', [RiderApplicationController::class, 'reject'])->name('rider-applications.reject');
    Route::post('rider-applications/{riderApplication}/revert', [RiderApplicationController::class, 'revertToPending'])->name('rider-applications.revert');

    Route::get('tracking', [TrackingController::class, 'index'])->name('tracking.index');
    Route::get('tracking/search', [TrackingController::class, 'search'])->name('tracking.search');

    Route::get('settings', [SettingController::class, 'index'])->name('settings.index');
    Route::put('settings/profile', [SettingController::class, 'updateProfile'])->name('settings.update-profile');
    Route::put('settings/password', [SettingController::class, 'updatePassword'])->name('settings.update-password');
    Route::post('settings/photo', [SettingController::class, 'updatePhoto'])->name('settings.update-photo');
    Route::put('settings/notifications', [SettingController::class, 'updateNotifications'])->name('settings.update-notifications');
    Route::put('settings/delivery', [SettingController::class, 'updateDelivery'])->name('settings.update-delivery');

    Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::patch('notifications/{notification}/read', [NotificationController::class, 'markRead'])->name('notifications.mark-read');
    Route::patch('notifications/read-all', [NotificationController::class, 'markAllRead'])->name('notifications.mark-all-read');

    Route::get('messages', [MessageController::class, 'index'])->name('messages.index');
    Route::get('messages/{conversation}', [MessageController::class, 'show'])->name('messages.show');
    Route::post('messages', [MessageController::class, 'store'])->name('messages.store');
    Route::patch('messages/{conversation}/read', [MessageController::class, 'markRead'])->name('messages.mark-read');

    Route::get('profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

Route::get('apply', [RiderApplicationController::class, 'create'])->name('rider-applications.create');
Route::post('apply', [RiderApplicationController::class, 'store'])->name('rider-applications.store');

require __DIR__.'/auth.php';
