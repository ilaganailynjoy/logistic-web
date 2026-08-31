<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DeliveryController;
use App\Http\Controllers\RiderController;
use App\Http\Controllers\TrackingController;
use App\Http\Controllers\SettingController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\MessageController;
use App\Http\Controllers\RiderMessageController;
use App\Http\Controllers\StaffController;
use App\Http\Controllers\LogisticsCenterController;
use App\Http\Controllers\ServiceAreaController;
use App\Http\Controllers\TransactionController;
use App\Http\Controllers\ReportController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect()->route('login');
});

Route::get('/dashboard', [DashboardController::class, 'index'])
    ->middleware(['auth', 'verified', 'staff'])
    ->name('dashboard');

Route::middleware(['auth', 'verified', 'staff'])->group(function () {

    // ── Deliveries ──────────────────────────────────────────
    Route::resource('deliveries', DeliveryController::class)->except(['destroy']);
    Route::post('deliveries/{delivery}/assign-rider', [DeliveryController::class, 'assignRider'])->name('deliveries.assign-rider');
    Route::patch('deliveries/{delivery}/update-status', [DeliveryController::class, 'updateStatus'])->name('deliveries.update-status');
    Route::post('deliveries/{delivery}/receive', [DeliveryController::class, 'receive'])->name('deliveries.receive');
    Route::patch('deliveries/{delivery}/scan', [DeliveryController::class, 'scan'])->name('deliveries.scan');
    Route::post('deliveries/{delivery}/sort', [DeliveryController::class, 'sort'])->name('deliveries.sort');

    // ── Archived Deliveries (Admin Only) ────────────────────
    Route::get('deliveries-archived', [DeliveryController::class, 'archived'])->name('deliveries.archived');
    Route::post('deliveries/{delivery}/archive', [DeliveryController::class, 'archive'])->name('deliveries.archive');
    Route::post('deliveries/{delivery}/restore', [DeliveryController::class, 'restore'])->name('deliveries.restore');
    Route::delete('deliveries/{delivery}', [DeliveryController::class, 'destroy'])->name('deliveries.destroy');

    // ── Riders (Read-Only Directory) ────────────────────────
    Route::get('riders', [RiderController::class, 'index'])->name('riders.index');
    Route::get('riders/{rider}', [RiderController::class, 'show'])->name('riders.show');

    // ── Staff Management (Admin Only) ───────────────────────
    Route::middleware('admin')->group(function () {
        Route::resource('staff', StaffController::class)->except(['destroy']);
        Route::post('staff/{staff}/activate', [StaffController::class, 'activate'])->name('staff.activate');
        Route::delete('staff/{staff}', [StaffController::class, 'destroy'])->name('staff.destroy');
    });

    // ── Logistics Centers (Admin Only) ──────────────────────
    Route::middleware('admin')->group(function () {
        Route::resource('centers', LogisticsCenterController::class);
        Route::post('centers/{center}/toggle', [LogisticsCenterController::class, 'toggle'])->name('centers.toggle');
    });

    // ── Service Areas (Admin Only) ──────────────────────────
    Route::middleware('admin')->group(function () {
        Route::resource('service-areas', ServiceAreaController::class);
        Route::post('service-areas/{serviceArea}/toggle', [ServiceAreaController::class, 'toggle'])->name('service-areas.toggle');
    });

    // ── Tracking ────────────────────────────────────────────
    Route::get('tracking', [TrackingController::class, 'index'])->name('tracking.index');
    Route::get('tracking/search', [TrackingController::class, 'search'])->name('tracking.search');

    // ── Transactions ────────────────────────────────────────
    Route::get('transactions', [TransactionController::class, 'index'])->name('transactions.index');

    // ── Reports ─────────────────────────────────────────────
    Route::get('reports', [ReportController::class, 'index'])->name('reports.index');

    // ── Settings ────────────────────────────────────────────
    Route::get('settings', [SettingController::class, 'index'])->name('settings.index');
    Route::put('settings/profile', [SettingController::class, 'updateProfile'])->name('settings.update-profile');
    Route::put('settings/password', [SettingController::class, 'updatePassword'])->name('settings.update-password');
    Route::post('settings/photo', [SettingController::class, 'updatePhoto'])->name('settings.update-photo');
    Route::put('settings/notifications', [SettingController::class, 'updateNotifications'])->name('settings.update-notifications');
    Route::put('settings/delivery', [SettingController::class, 'updateDelivery'])->name('settings.update-delivery');

    // ── Notifications ───────────────────────────────────────
    Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::patch('notifications/{notification}/read', [NotificationController::class, 'markRead'])->name('notifications.mark-read');
    Route::patch('notifications/read-all', [NotificationController::class, 'markAllRead'])->name('notifications.mark-all-read');

    // ── Messages ────────────────────────────────────────────
    Route::get('messages', [MessageController::class, 'index'])->name('messages.index');
    Route::get('messages/poll', [MessageController::class, 'poll'])->name('messages.poll');
    Route::get('messages/{conversation}', [MessageController::class, 'show'])->name('messages.show');
    Route::post('messages', [MessageController::class, 'store'])->name('messages.store');
    Route::patch('messages/{message}', [MessageController::class, 'update'])->name('messages.update');
    Route::delete('messages/{message}', [MessageController::class, 'destroy'])->name('messages.destroy');
    Route::patch('messages-read/{conversation}', [MessageController::class, 'markRead'])->name('messages.mark-read');
    Route::get('attachments/{attachment}/view', [MessageController::class, 'viewAttachment'])->name('messages.attachments.view');
    Route::get('attachments/{attachment}/download', [MessageController::class, 'downloadAttachment'])->name('messages.attachments.download');

    // ── Profile ─────────────────────────────────────────────
    Route::get('profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

// Rider messaging only (riders access via Logistics login, messaging only)
Route::middleware(['auth', 'rider.web'])->group(function () {
    Route::get('rider/messages', [RiderMessageController::class, 'index'])->name('rider.messages');
    Route::get('rider/messages/poll', [RiderMessageController::class, 'poll'])->name('rider.messages.poll');
    Route::post('rider/messages', [RiderMessageController::class, 'store'])->name('rider.messages.send');
    Route::patch('rider/messages/{message}', [RiderMessageController::class, 'update'])->name('rider.messages.update');
    Route::delete('rider/messages/{message}', [RiderMessageController::class, 'destroy'])->name('rider.messages.destroy');
    Route::get('rider/attachments/{attachment}/view', [RiderMessageController::class, 'viewAttachment'])->name('rider.messages.attachments.view');
    Route::get('rider/attachments/{attachment}/download', [RiderMessageController::class, 'downloadAttachment'])->name('rider.messages.attachments.download');
});

require __DIR__.'/auth.php';
