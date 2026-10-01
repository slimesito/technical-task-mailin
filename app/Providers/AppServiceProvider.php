<?php

namespace App\Providers;

use App\Services\Blacklist\BlacklistChecker;
use App\Services\Dns\DnsResolver;
use App\Services\Dns\NetDnsResolver;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(DnsResolver::class, fn () => new NetDnsResolver(
            nameservers: array_values(config('domain_checks.dns.nameservers')),
            timeout: config('domain_checks.dns.timeout'),
            retries: config('domain_checks.dns.query_retries'),
            logger: Log::channel('dns'),
        ));

        $this->app->bind(BlacklistChecker::class, fn ($app) => new BlacklistChecker(
            $app->make(DnsResolver::class),
            config('domain_checks.blacklists'),
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
