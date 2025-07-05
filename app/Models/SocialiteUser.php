<?php

namespace App\Models;

use DutchCodingCompany\FilamentSocialite\Models\Contracts\FilamentSocialiteUser as FilamentSocialiteUserContract;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Contracts\User as SocialiteUserContract;

class SocialiteUser extends Model implements FilamentSocialiteUserContract
{
    protected $fillable = [
        'user_id',
        'provider',
        'provider_id',
        'google_access_token',
        'google_refresh_token',
        'google_expires_at',
    ];

    public function getUser(): Authenticatable
    {
        return User::find($this->user_id);
    }

    public static function findForProvider(string $provider, SocialiteUserContract $oauthUser): ?self
    {
        return self::query()
            ->where('provider', $provider)
            ->where('provider_id', $oauthUser->getId())
            ->first();
    }

    public static function createForProvider(
        string $provider,
        SocialiteUserContract $oauthUser,
        Authenticatable $user
    ): self
    {
        return self::query()
            ->create([
                'user_id' => $user->id,
                'provider' => $provider,
                'provider_id' => $oauthUser->getId(),
            ]);
    }

    public static function updateGoogleToken(
        Model $socialiteUser,
        SocialiteUserContract $oauthUser
    ): bool
    {
        Log::info('OAuth User:', ['user' => json_encode($oauthUser)]);
        return $socialiteUser
            ->update([
                'google_access_token' => $oauthUser->token, // Ini token aksesnya
                'google_refresh_token' => $oauthUser->refreshToken, // Simpan refresh token jika ada
                'google_expires_at' => now()->addSeconds($oauthUser->expiresIn), // Waktu kadaluarsa token akses
            ]);
    }
}
