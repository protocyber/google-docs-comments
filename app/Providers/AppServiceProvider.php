<?php

namespace App\Providers;

use App\Models\SocialiteUser;
use DutchCodingCompany\FilamentSocialite\Events\Login;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Event::listen(
            function (Login $event)
            {
                SocialiteUser::updateGoogleToken($event->socialiteUser, $event->oauthUser);
                // logger(->token);
            }
        );
    }
}
