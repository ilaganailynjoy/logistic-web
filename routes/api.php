<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\RiderApplicationController;
use App\Http\Controllers\Api\RiderController;
use App\Http\Controllers\Api\RiderDeliveryController;
use App\Http\Controllers\Api\RiderEarningsController;
use App\Http\Controllers\Api\RiderHistoryController;
use App\Http\Controllers\Api\RiderLocationController;
use App\Http\Controllers\Api\RiderConversationController;
use App\Http\Controllers\Api\RiderMessageController;
use App\Http\Controllers\Api\RiderNotificationController;
use App\Http\Controllers\Api\RiderPasswordController;
use App\Http\Controllers\Api\VehicleTypeController;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login'])
    ->middleware('throttle:10,1');

Route::get('/vehicle-types', [VehicleTypeController::class, 'index']);
Route::post('/rider/apply', [RiderApplicationController::class, 'store']);
Route::get('/rider/application-status', [RiderApplicationController::class, 'status']);

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/user', [AuthController::class, 'user']);

    Route::middleware('role:rider')->prefix('rider')->group(function () {
        Route::get('/profile', [RiderController::class, 'profile']);
        Route::patch('/profile', [RiderController::class, 'updateProfile']);
        Route::patch('/password', [RiderPasswordController::class, 'update']);
        Route::patch('/status', [RiderController::class, 'updateStatus']);
        Route::get('/dashboard', [RiderController::class, 'dashboard']);

        Route::get('/deliveries', [RiderDeliveryController::class, 'index']);
        Route::get('/deliveries/{delivery}', [RiderDeliveryController::class, 'show']);
        Route::post('/deliveries/{delivery}/accept', [RiderDeliveryController::class, 'accept']);
        Route::patch('/deliveries/{delivery}/status', [RiderDeliveryController::class, 'updateStatus']);
        Route::post('/deliveries/{delivery}/pickup', [RiderDeliveryController::class, 'pickup']);
        Route::post('/deliveries/{delivery}/complete', [RiderDeliveryController::class, 'complete']);
        Route::post('/deliveries/{delivery}/failed', [RiderDeliveryController::class, 'failed']);

        Route::post('/location', [RiderLocationController::class, 'store']);

        Route::get('/earnings', [RiderEarningsController::class, 'index']);
        Route::get('/history', [RiderHistoryController::class, 'index']);

        Route::get('/notifications', [RiderNotificationController::class, 'index']);
        Route::patch('/notifications/read-all', [RiderNotificationController::class, 'markAllRead']);
        Route::patch('/notifications/{notification}/read', [RiderNotificationController::class, 'markRead']);

        Route::get('/messages', [RiderMessageController::class, 'index']);
        Route::get('/messages/poll', [RiderMessageController::class, 'poll']);
        Route::post('/messages', [RiderMessageController::class, 'store']);

        Route::get('/conversations', [RiderConversationController::class, 'index']);
        Route::get('/conversations/{conversation}', [RiderConversationController::class, 'show']);
        Route::post('/conversations/{conversation}/messages', [RiderConversationController::class, 'store']);
    });
});