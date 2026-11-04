<?php

namespace SmsTools2;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use SmsTools2\Client\SmsClient;
use SmsTools2\Commands\SendCommand;
use SmsTools2\Commands\SetupCommand;
use SmsTools2\Commands\SyncCommand;
use SmsTools2\Http\Controllers\WebhookController;
use SmsTools2\Http\Middleware\VerifyWebhookSignature;

class SmsToolsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/sms-tools.php', 'sms-tools');

        $this->app->singleton(SmsClient::class, fn () => new SmsClient(
            (string) config('sms-tools.api.url'),
            config('sms-tools.api.token'),
            (int) config('sms-tools.api.timeout', 15),
            (int) config('sms-tools.api.retries', 2),
        ));
    }

    public function boot(): void
    {
        if (config('sms-tools.webhook.route.enabled', true)) {
            Route::post((string) config('sms-tools.webhook.route.path'), WebhookController::class)
                ->middleware([...(array) config('sms-tools.webhook.route.middleware', []), VerifyWebhookSignature::class])
                ->name((string) config('sms-tools.webhook.route.name'));
        }

        if ($this->app->runningInConsole()) {
            $this->commands([SetupCommand::class, SendCommand::class, SyncCommand::class]);

            $this->publishes([__DIR__.'/../config/sms-tools.php' => config_path('sms-tools.php')], 'sms-tools-2-config');

            $this->publishes([
                __DIR__.'/../stubs/SmsMessage.stub' => app_path('SmsMessage.php'),
                __DIR__.'/../stubs/SmsInbound.stub' => app_path('SmsInbound.php'),
            ], 'sms-tools-2-models');

            $stamp = date('Y_m_d_His');
            $this->publishes([
                __DIR__.'/../database/migrations/create_sms_tools_tables.php.stub' => database_path("migrations/{$stamp}_create_sms_tools_tables.php"),
            ], 'sms-tools-2-migrations');

            $this->publishes([
                __DIR__.'/../database/migrations/upgrade_sms_tools_tables_v2.php.stub' => database_path("migrations/{$stamp}_upgrade_sms_tools_tables_v2.php"),
            ], 'sms-tools-2-upgrade');
        }
    }
}
