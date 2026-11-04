<?php

namespace SmsTools2\Commands;

use Illuminate\Console\Command;
use SmsTools2\Client\SmsApiException;
use SmsTools2\Client\SmsClient;

class SetupCommand extends Command
{
    protected $signature = 'smstools2:setup
        {--url= : Webhook URL to register (defaults to this app\'s webhook route)}
        {--write-env : Write SMS_TOOLS_WEBHOOK_SECRET into .env}
        {--skip-test : Do not send a test event}';

    protected $description = 'Check the API token, register the webhook URL and test it';

    public function handle(SmsClient $client): int
    {
        try {
            $current = $client->webhook();
            $this->components->info('API token OK ('.config('sms-tools.api.url').')');

            $url = (string) ($this->option('url') ?: route((string) config('sms-tools.webhook.route.name')));
            if ($current['url'] !== $url) {
                $current = $client->updateWebhook($url);
                $this->components->info("Webhook URL set to {$url}");
            } else {
                $this->components->info("Webhook URL already {$url}");
            }

            $secret = $current['secret'];
            if (config('sms-tools.webhook.secret') !== $secret) {
                if ($this->option('write-env') && $this->writeEnv('SMS_TOOLS_WEBHOOK_SECRET', $secret)) {
                    config(['sms-tools.webhook.secret' => $secret]);
                    $this->components->info('SMS_TOOLS_WEBHOOK_SECRET written to .env');
                } else {
                    $this->components->warn('Add the signing secret to your .env (or re-run with --write-env):');
                    $this->line("  SMS_TOOLS_WEBHOOK_SECRET={$secret}");
                }
            } else {
                $this->components->info('Webhook signing secret matches .env');
            }

            if (! $this->option('skip-test')) {
                $test = $client->testWebhook();
                if ($test['ok']) {
                    $this->components->info("Test event delivered (HTTP {$test['status']})");
                } else {
                    $this->components->error('Test event failed: '.($test['error'] ?? 'unknown error').' — is this URL reachable from the platform?');

                    return self::FAILURE;
                }
            }
        } catch (SmsApiException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function writeEnv(string $key, string $value): bool
    {
        $path = app()->environmentFilePath();
        if (! is_file($path) || ! is_writable($path)) {
            return false;
        }

        $contents = (string) file_get_contents($path);
        $line = "{$key}={$value}";
        $contents = preg_match("/^{$key}=.*/m", $contents)
            ? (string) preg_replace("/^{$key}=.*/m", $line, $contents)
            : rtrim($contents).PHP_EOL.$line.PHP_EOL;

        return file_put_contents($path, $contents) !== false;
    }
}
