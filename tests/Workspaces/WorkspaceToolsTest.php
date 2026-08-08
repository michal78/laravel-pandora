<?php

declare(strict_types=1);

use Pandora\Tests\Support\MakesTools;
use Pandora\Tools\BuiltIn\BuiltInTools;
use Pandora\Tools\BuiltIn\ListFilesTool;
use Pandora\Tools\BuiltIn\ReadFileTool;
use Pandora\Tools\BuiltIn\WriteFileTool;
use Pandora\Tools\Enums\RiskLevel;
use Pandora\Tools\ToolInput;
use Pandora\Tools\ToolRegistry;
use Pandora\Workspaces\Workspace;

/**
 * Phase 7, criteria 23 and 25 — the tools that let an agent use a workspace.
 *
 * They were named "Phase 7 workspace tools" by the Phase 5 walkthrough and
 * were never carried into Phase 7's criteria when ADR-0013 rewrote the phase
 * around storage, so a workspace was something only an operator could fill.
 *
 * What is asserted here is that they are a way to CALL `WorkspaceFiles` and
 * not a second implementation of it. Containment, quota and MIME are already
 * proven on both adapters by the storage suites; the job of these tests is
 * that every one of those refusals arrives as an ordinary tool failure with
 * the run still going, and that nothing an agent says chooses the workspace.
 */
uses(MakesTools::class);

beforeEach(function (): void {
    $this->root = sys_get_temp_dir().'/pandora-wtools-'.bin2hex(random_bytes(6));

    mkdir($this->root.'/reports', 0777, true);
    file_put_contents($this->root.'/notes.txt', 'workspace notes');
    file_put_contents($this->root.'/reports/q1.txt', 'quarterly');

    $this->context = $this->toolContext();

    $this->attach = function (array $attributes = []): Workspace {
        /** @var Workspace $workspace */
        $workspace = Workspace::query()->create($attributes + [
            'name' => 'Scratch',
            'slug' => 'scratch',
            'disk' => 'local',
            'root_path' => $this->root,
        ]);

        $this->context->agent->forceFill(['workspace_id' => $workspace->getKey()])->save();

        return $workspace;
    };

    $this->call = fn (object $tool, array $arguments = []) => $tool->handle(
        new ToolInput($arguments),
        $this->context,
    );
});

afterEach(function (): void {
    foreach ([$this->root.'/reports', $this->root] as $dir) {
        foreach (glob($dir.'/*') ?: [] as $file) {
            is_file($file) ? unlink($file) : null;
        }

        @rmdir($dir);
    }
});

it('registers the workspace tools as built-ins', function (): void {
    app(ToolRegistry::class)->flush()->registerMany(BuiltInTools::all());

    expect(app(ToolRegistry::class)->names())->toContain('list_files', 'read_file', 'write_file');
});

it('lists, reads and writes inside the workspace', function (): void {
    ($this->attach)();

    expect(($this->call)(new ListFilesTool)->content)->toContain('notes.txt', 'reports')
        ->and(($this->call)(new ReadFileTool, ['path' => 'notes.txt'])->content)->toBe('workspace notes');

    ($this->call)(new WriteFileTool, ['path' => 'written.txt', 'content' => 'by the agent']);

    expect(file_get_contents($this->root.'/written.txt'))->toBe('by the agent');
});

it('descends into a folder', function (): void {
    ($this->attach)();

    expect(($this->call)(new ListFilesTool, ['path' => 'reports'])->content)->toContain('reports/q1.txt')
        ->and(($this->call)(new ReadFileTool, ['path' => 'reports/q1.txt'])->content)->toBe('quarterly');
});

/**
 * The property the whole shape rests on: an agent cannot name a workspace, so
 * a sentence in a document it is reading has nowhere to land.
 */
it('takes no workspace argument at all', function (object $tool): void {
    expect(array_keys($tool->rules()))->not->toContain('workspace', 'disk', 'root', 'root_path');
})->with([
    fn () => new ListFilesTool,
    fn () => new ReadFileTool,
    fn () => new WriteFileTool,
]);

it('reaches no files when the agent has no workspace', function (object $tool, array $arguments): void {
    // The default state of every agent, so it is said plainly rather than
    // raised as an error -- a model told "an error occurred" tries again with
    // a different path.
    $result = ($this->call)($tool, $arguments);

    expect($result->content)->toContain('No workspace is attached')
        ->and($result->ok)->toBeTrue();
})->with([
    [fn () => new ListFilesTool, []],
    [fn () => new ReadFileTool, ['path' => 'notes.txt']],
    [fn () => new WriteFileTool, ['path' => 'x.txt', 'content' => 'nope']],
]);

it('reaches no files through a disabled workspace', function (): void {
    ($this->attach)(['enabled' => false]);

    expect(($this->call)(new ReadFileTool, ['path' => 'notes.txt'])->content)
        ->toContain('No workspace is attached');
});

/**
 * Every refusal, arriving as a tool failure rather than an exception. The run
 * continues in each case, which is criterion 25.
 */
it('fails without throwing when the path leaves the root', function (string $path): void {
    ($this->attach)();

    $result = ($this->call)(new ReadFileTool, ['path' => $path]);

    expect($result->ok)->toBeFalse()
        // Never the resolved path: telling an agent what `../../etc/passwd`
        // resolved to confirms the file exists and confirms where the root is.
        ->and($result->content)->not->toContain('/etc/passwd');
})->with(['../escape.txt', '../../etc/passwd', '/etc/passwd', 'missing.txt']);

it('fails without throwing when a write leaves the root', function (): void {
    ($this->attach)();

    $result = ($this->call)(new WriteFileTool, ['path' => '../escaped.txt', 'content' => 'out']);

    expect($result->ok)->toBeFalse()
        ->and(file_exists(dirname($this->root).'/escaped.txt'))->toBeFalse();
});

it('fails without throwing when the write exceeds the quota', function (): void {
    ($this->attach)(['quota_bytes' => 10]);

    $result = ($this->call)(new WriteFileTool, ['path' => 'big.txt', 'content' => str_repeat('x', 50)]);

    expect($result->ok)->toBeFalse()
        ->and($result->content)->toContain('full')
        ->and(file_exists($this->root.'/big.txt'))->toBeFalse();
});

it('fails without throwing when the type is not allowed', function (): void {
    ($this->attach)(['allowed_mime_types' => ['text/csv']]);

    $result = ($this->call)(new WriteFileTool, ['path' => 'notes.txt', 'content' => 'plain text']);

    expect($result->ok)->toBeFalse()
        ->and($result->content)->toContain('not allowed');
});

it('fails without throwing when the root has gone', function (): void {
    $workspace = ($this->attach)();
    $workspace->update(['root_path' => $this->root.'/not-here']);

    foreach ([[new ListFilesTool, []], [new ReadFileTool, ['path' => 'notes.txt']]] as [$tool, $arguments]) {
        expect(($this->call)($tool, $arguments)->ok)->toBeFalse();
    }
});

/**
 * The bound on a read, which is the difference between a workspace holding a
 * large file and a large file arriving in a prompt.
 */
it('truncates a file larger than the read bound and says so', function (): void {
    config()->set('pandora.workspaces.max_read_bytes', 100);

    ($this->attach)();
    file_put_contents($this->root.'/big.txt', str_repeat('a', 5000));

    $result = ($this->call)(new ReadFileTool, ['path' => 'big.txt']);

    expect($result->content)->toContain('Truncated')
        ->and($result->data['truncated'])->toBeTrue()
        // The whole file is 5,000 bytes and 100 of them arrived.
        ->and(strlen($result->content))->toBeLessThan(300)
        ->and($result->data['bytes'])->toBe(5000);
});

it('does not claim truncation for a file exactly at the bound', function (): void {
    config()->set('pandora.workspaces.max_read_bytes', 100);

    ($this->attach)();
    file_put_contents($this->root.'/exact.txt', str_repeat('a', 100));

    $result = ($this->call)(new ReadFileTool, ['path' => 'exact.txt']);

    expect($result->data['truncated'])->toBeFalse()
        ->and($result->content)->toBe(str_repeat('a', 100));
});

it('bounds what one write may carry', function (): void {
    expect(WriteFileTool::maxBytes())->toBe(1048576)
        ->and((new WriteFileTool)->rules()['content'])->toContain('max:1048576');
});

/**
 * Risk, which decides which agents may call them at all.
 */
it('lets an observe-only agent look but not write', function (): void {
    expect((new ListFilesTool)->risk())->toBe(RiskLevel::Low)
        ->and((new ReadFileTool)->risk())->toBe(RiskLevel::Low)
        // A file an agent wrote is one a person will later read believing
        // somebody meant it.
        ->and((new WriteFileTool)->risk())->toBe(RiskLevel::Medium);
});

it('does not reach another tenant\'s workspace even when the agent row names it', function (): void {
    $foreign = inTenant('acme', function (): Workspace {
        /** @var Workspace $workspace */
        $workspace = Workspace::query()->create([
            'name' => 'Acme only',
            'slug' => 'acme-only',
            'disk' => 'local',
            'root_path' => $this->root,
        ]);

        return $workspace;
    });

    // A dangling pointer across a tenant boundary is the shape a stale row
    // takes; it must find nothing rather than find it.
    $this->context->agent->forceFill(['workspace_id' => $foreign->getKey()])->save();

    inTenant('globex', function (): void {
        expect(($this->call)(new ReadFileTool, ['path' => 'notes.txt'])->content)
            ->toContain('No workspace is attached');
    });
});
