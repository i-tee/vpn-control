<?php

namespace App\Observers;

use App\Models\User;
use App\Notifications\NewUserRegistered;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Log;

class UserObserver
{
    public function created(User $user): void
    {

        Log::debug('NOTIKI -- New user created', ['user_id' => $user->id, 'name' => $user->name]);

        if ($adminEmail = env('ADMIN_EMAIL')) {

            Log::debug('NOTIKI -- Notification started for admin email', ['admin_email' => $adminEmail]);

            Notification::route('mail', $adminEmail)
                ->notify(new NewUserRegistered($user));

            Log::debug('NOTIKI -- Admin notified of new user registration', ['admin_email' => $adminEmail]);
        }
    }
}