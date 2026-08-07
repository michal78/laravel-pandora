<?php

declare(strict_types=1);

use Pandora\Agents\AgentRegistry;
use Pandora\Agents\AgentRunner;
use Pandora\Audit\AuditLog;
use Pandora\Core\Actor\ActorContext;
use Pandora\Facades\Pandora;
use Pandora\Runs\Enums\RunState;
use Pandora\Tests\Fixtures\EchoAgent;

it('demonstrates the Phase 1 slice end to end', function (): void {
    $this->fakeProvider()->willRespondWith('Order 1234 shipped on Tuesday and arrives Thursday.');

    $user = $this->actingAsUser();

    // 1. Register a class-defined agent.
    app(AgentRegistry::class)->define(EchoAgent::class);
    $agent = app(AgentRegistry::class)->get('echo');

    // 2. Start a conversation.
    $conversation = Pandora::startConversation(
        'echo',
        ActorContext::forUser($user),
    );

    // 3. Run.
    $run = app(AgentRunner::class)
        ->agent($agent)
        ->forUser($user)
        ->inConversation($conversation)
        ->stream()
        ->run('Where is order 1234?');

    echo "\n";
    echo "  AGENT       {$agent->name} ({$agent->slug}), source: ".($agent->isClassDefined() ? 'class' : 'db')."\n";
    echo "  CONVERSATION {$conversation->refresh()->title}\n";
    echo "  RUN         {$run->getKey()}\n";
    echo "  STATE       {$run->state->label()}\n";
    echo "  PROVIDER    {$run->provider_key} / {$run->model_key}\n";
    echo "  TOKENS      {$run->input_tokens} in / {$run->output_tokens} out\n";
    echo "  DURATION    {$run->durationMs()} ms\n";
    echo "  OUTPUT      {$run->output}\n";
    echo "  TRACE:\n";

    foreach ($run->steps()->get() as $step) {
        $label = $step->label !== null ? " ({$step->label})" : '';
        echo "    {$step->sequence}. {$step->type->label()}{$label} [{$step->status->value}]\n";
    }

    echo "  MESSAGES:\n";
    foreach ($conversation->messages()->get() as $m) {
        echo "    {$m->sequence}. {$m->role->value}: ".mb_substr((string) $m->content, 0, 60)."\n";
    }

    echo "  AUDIT:\n";
    foreach (AuditLog::query()->where('run_id', $run->getKey())->get() as $log) {
        echo "    {$log->action} [{$log->severity}]\n";
    }

    expect($run->state)->toBe(RunState::Completed);
});
