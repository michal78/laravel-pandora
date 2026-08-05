<?php

declare(strict_types=1);

namespace Pandora\Pandora\Console\Commands;

use Illuminate\Console\Command;
use Pandora\Pandora\Agents\AgentRunner;
use Pandora\Pandora\Exceptions\AgentNotFound;
use Pandora\Pandora\Runs\Enums\TriggerType;
use Pandora\Pandora\Runs\Run;

final class AgentRunCommand extends Command
{
    protected $signature = 'pandora:agent:run
                            {agent : The agent slug}
                            {prompt : The message to send}
                            {--queue : Queue the run instead of waiting for it}
                            {--trace : Print the run trace afterwards}';

    protected $description = 'Run an agent from the console.';

    public function handle(AgentRunner $agents): int
    {
        /** @var string $slug */
        $slug = $this->argument('agent');
        /** @var string $prompt */
        $prompt = $this->argument('prompt');

        try {
            $pending = $agents->agent($slug);
        } catch (AgentNotFound $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $pending->asSystem('console')->triggeredBy(TriggerType::Console);

        if ($this->option('queue')) {
            $run = $pending->dispatch($prompt);
            $this->components->info("Run {$run->getKey()} queued.");

            return self::SUCCESS;
        }

        $this->components->info("Running agent [{$slug}]...");

        $run = $pending->run($prompt);

        $this->newLine();
        $this->line($run->output ?? '<fg=gray>(no output)</>');
        $this->newLine();

        $this->components->twoColumnDetail('<fg=gray>Run</>', (string) $run->getKey());
        $this->components->twoColumnDetail('<fg=gray>State</>', $run->state->label());
        $this->components->twoColumnDetail('<fg=gray>Iterations</>', (string) $run->iterations);
        $this->components->twoColumnDetail(
            '<fg=gray>Tokens</>',
            "{$run->input_tokens} in / {$run->output_tokens} out",
        );

        if ($run->error_message !== null) {
            $this->components->error($run->error_message);
        }

        if ($this->option('trace')) {
            $this->printTrace($run);
        }

        return $run->error_message === null ? self::SUCCESS : self::FAILURE;
    }

    private function printTrace(Run $run): void
    {
        $this->newLine();
        $this->components->info('Trace');

        foreach ($run->steps()->get() as $step) {
            $this->components->twoColumnDetail(
                "<fg=gray>{$step->sequence}.</> {$step->type->label()}".
                    ($step->label !== null ? " <fg=gray>({$step->label})</>" : ''),
                $step->duration_ms !== null ? "{$step->duration_ms} ms" : $step->status->value,
            );
        }
    }
}
