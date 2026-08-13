<?php

namespace App\Filament\Pages\Auth;

use App\Identity\IdentityException;
use App\Identity\IdentityService;
use Filament\Auth\Http\Responses\Contracts\LoginResponse;
use Illuminate\Validation\ValidationException;

class Login extends \Filament\Auth\Pages\Login
{
    public function authenticate(): ?LoginResponse
    {
        $result = parent::authenticate();

        $user = auth()->user();
        if ($user) {
            try {
                app(IdentityService::class)->assertLocalPasswordAllowed($user);
            } catch (IdentityException $exception) {
                auth()->logout();

                throw ValidationException::withMessages([
                    'data.email' => $exception->getMessage(),
                ]);
            }

            $user->updateLastActivity();
        }

        return $result;
    }
}
