<?php

namespace App\Http\Controllers;

use App\Models\LogisticsSetting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\View\View;

class SettingController extends Controller
{
    public function index(Request $request): View
    {
        $user = Auth::user();
        $settings = LogisticsSetting::forUser($user->id);

        return view('settings.index', compact('user', 'settings'));
    }

    public function updateProfile(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255|unique:users,email,' . Auth::id(),
            'phone' => 'nullable|string|max:20',
        ]);

        Auth::user()->update($validated);

        return redirect()->route('settings.index')->with('success', 'Profile updated successfully.');
    }

    public function updatePhoto(Request $request): RedirectResponse
    {
        $request->validate([
            'photo' => 'required|image|mimes:jpg,jpeg,png|max:2048',
        ]);

        $settings = LogisticsSetting::forUser(Auth::id());
        $file = $request->file('photo');
        $filename = Str::uuid() . '.' . $file->getClientOriginalExtension();

        if ($settings->photo_path && file_exists(public_path($settings->photo_path))) {
            @unlink(public_path($settings->photo_path));
        }

        $file->move(public_path('uploads/avatars'), $filename);
        $settings->update(['photo_path' => 'uploads/avatars/' . $filename]);

        return redirect()->route('settings.index')->with('success', 'Profile photo updated successfully.');
    }

    public function updatePassword(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'current_password' => 'required',
            'password' => 'required|string|min:8|confirmed',
        ]);

        if (!Hash::check($validated['current_password'], Auth::user()->password)) {
            return back()->withErrors(['current_password' => 'The current password is incorrect.']);
        }

        Auth::user()->update(['password' => $validated['password']]);

        return redirect()->route('settings.index')->with('success', 'Password changed successfully.');
    }

    public function updateNotifications(Request $request): RedirectResponse
    {
        $notifications = [];
        foreach (LogisticsSetting::NOTIFICATION_KEYS as $key) {
            $notifications[$key] = $request->boolean($key);
        }

        LogisticsSetting::forUser(Auth::id())->update([
            'notifications' => $notifications,
            'email_notifications' => $request->boolean('email_notifications'),
        ]);

        return redirect()->route('settings.index')->with('success', 'Notification settings saved.');
    }

    public function updateDelivery(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'max_attempts' => 'required|integer|min:1|max:3',
        ]);

        $existing = LogisticsSetting::forUser(Auth::id())->delivery ?? [];

        LogisticsSetting::forUser(Auth::id())->update([
            'delivery' => [
                'require_proof' => $request->boolean('require_proof'),
                'max_attempts' => (int) $validated['max_attempts'],
                'allow_reassignment' => $request->boolean('allow_reassignment'),
                'vehicle_capacities' => $existing['vehicle_capacities'] ?? null,
            ],
        ]);

        return redirect()->route('settings.index')->with('success', 'Delivery preferences saved.');
    }
}
