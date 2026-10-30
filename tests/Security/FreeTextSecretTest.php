<?php

declare(strict_types=1);

use Pandora\Approvals\Approval;
use Pandora\Providers\Data\ToolCall;
use Pandora\Tests\Support\MakesTools;
use Pandora\Tools\ToolExecution;

/**
 * A secret in a free-text argument does not reach the copy people are shown.
 *
 * Found in the Phase 8 walkthrough: `request_approval` was called with a
 * passphrase in its `detail`, the memory guard refused to store the same
 * passphrase, and the approval record kept it in the clear -- because
 * `sanitized_arguments` was redacted by key name, and neither `summary` nor
 * `detail` is a sensitive key. The two components disagreed about what a
 * secret is. They now share a definition.
 */
uses(MakesTools::class);

beforeEach(function (): void {
    $this->agentAllows(['request_approval']);
});

function requestApprovalWith(array $arguments): ToolExecution
{
    test()->fakeProvider()
        ->willRequestTools([new ToolCall('call_1', 'request_approval', $arguments)])
        ->willRespondWith('Waiting.');

    $run = test()->runToolAgent('Remember this for me.');

    /** @var ToolExecution $execution */
    $execution = ToolExecution::query()->where('run_id', $run->getKey())->firstOrFail();

    return $execution;
}

it('withholds a free-text argument that is about a secret', function (): void {
    // The walkthrough's own arguments, verbatim in substance.
    $execution = requestApprovalWith([
        'summary' => 'I propose to remember a fact for the user.',
        'detail' => "The user shared their walkthrough passphrase, which is 'copper-lantern-84', and asked me to keep it.",
    ]);

    $approval = Approval::query()->where('tool_execution_id', $execution->getKey())->firstOrFail();

    expect(json_encode($execution->sanitized_arguments))->not->toContain('copper-lantern-84')
        ->and(json_encode($approval->sanitized_arguments))->not->toContain('copper-lantern-84')
        // The executable copy keeps the real value; only the display copy is cut.
        ->and($execution->arguments['detail'])->toContain('copper-lantern-84');
});

it('cuts a credential shape out of free text and keeps the rest', function (): void {
    $execution = requestApprovalWith([
        'summary' => 'Rotate the deploy key.',
        'detail' => 'The old one was sk-live-abcdefghijklmnopqrstuvwxyz and it leaked.',
    ]);

    expect($execution->sanitized_arguments['detail'])
        ->not->toContain('sk-live-abcdefghijklmnopqrstuvwxyz')
        ->toContain('and it leaked.');
});

it('leaves ordinary free text alone', function (): void {
    $execution = requestApprovalWith([
        'summary' => 'Refund order ORD-1234.',
        'detail' => 'The customer was charged twice for one order.',
    ]);

    expect($execution->sanitized_arguments)->toBe([
        'summary' => 'Refund order ORD-1234.',
        'detail' => 'The customer was charged twice for one order.',
    ]);
});
