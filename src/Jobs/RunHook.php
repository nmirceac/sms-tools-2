<?php

namespace SmsTools2\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** Runs an afterMessage* hook on your model, on the queue (as sms-tools 0.x did). */
class RunHook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    /**
     * @param  class-string<Model>  $modelClass
     * @param  list<mixed>  $arguments
     */
    public function __construct(
        public string $modelClass,
        public int|string $modelId,
        public string $hook,
        public array $arguments = [],
    ) {}

    public function handle(): void
    {
        $model = $this->modelClass::query()->find($this->modelId);

        if ($model !== null && method_exists($model, $this->hook)) {
            $model->{$this->hook}(...$this->arguments);
        }
    }
}
