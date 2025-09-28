<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Kreait\Firebase\Factory;
use Kreait\Firebase\Auth as FirebaseAuth;
use Kreait\Firebase\Messaging as FirebaseMessaging;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(Factory::class, function () {
            return (new Factory)
                ->withServiceAccount(config('firebase.projects.app.credentials'));
        });

        // Register Firebase Auth
        $this->app->singleton(
            FirebaseAuth::class,
            fn($app) =>
            $app->make(Factory::class)->createAuth()
        );

        // Register Firebase Messaging
        $this->app->singleton(
            FirebaseMessaging::class,
            fn($app) =>
            $app->make(Factory::class)->createMessaging()
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
