<?php

declare(strict_types=1);

namespace Pandora\Tools;

use Illuminate\Contracts\Validation\Factory as ValidationFactory;
use Illuminate\Validation\ValidationException;
use Pandora\Exceptions\ToolInputInvalid;
use Pandora\Tools\Enums\RiskLevel;
use Pandora\Tools\Schema\RuleSchemaGenerator;

/**
 * A capability an agent may request.
 *
 * A tool call is a REQUEST from an untrusted party, never an instruction. By
 * the time `handle()` runs, the call has cleared five independent layers:
 *
 *   1. registry        — is this tool registered and enabled at all?
 *   2. agent           — is it allowed for this agent?
 *   3. tenant          — is it allowed for this tenant?
 *   4. ToolPolicy      — allow / deny / approve / confirm / modify arguments
 *   5. Tool::authorize — this class, checked against the ACTING USER
 *
 * Layer 5 is the one host developers write, and it is what makes an agent
 * unable to do something the person it acts for could not do themselves.
 * Write it as ordinary Laravel authorization and the safety follows.
 *
 * See docs/adr/0007-tools-are-classes-with-laravel-authorization.md.
 */
abstract class Tool
{
    /**
     * The name the model sees. Stable: changing it invalidates remembered
     * approvals and breaks conversations mid-flight.
     */
    abstract public function name(): string;

    /**
     * What the tool does, in the model's terms. This text is the single
     * biggest influence on whether the model uses the tool correctly.
     */
    abstract public function description(): string;

    /**
     * Do the work. Only ever reached by an authorized, validated call.
     */
    abstract public function handle(ToolInput $input, ToolContext $context): ToolResult;

    /**
     * Laravel validation rules for the arguments. The JSON schema advertised
     * to the model is generated from these, so they are the one source of
     * truth for the tool's interface.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }

    /**
     * Field => description, merged into the generated schema. Worth writing:
     * a described field is a field the model gets right.
     *
     * @return array<string, string>
     */
    public function descriptions(): array
    {
        return [];
    }

    /**
     * How much damage this tool can do if the model is persuaded to misuse it.
     *
     * `high` and `critical` require human approval by default. Understating
     * this is the most consequential mistake a tool author can make.
     */
    public function risk(): RiskLevel
    {
        return RiskLevel::Low;
    }

    /**
     * Authorize the call against the ACTOR -- never against the agent.
     *
     * The default denies everything except a low-risk tool acting for a real
     * user, because a default that allowed would make forgetting this method
     * a security hole rather than a compile-time-obvious omission.
     */
    public function authorize(ToolInput $input, ToolContext $context): bool
    {
        return $context->actor !== null
            && ! $context->actor->isSystem()
            && $this->risk() === RiskLevel::Low;
    }

    /**
     * Alternative names this tool answers to. Use when renaming a tool without
     * breaking conversations that are mid-flight.
     *
     * @return list<string>
     */
    public function aliases(): array
    {
        return [];
    }

    /**
     * Bumped when the arguments or the behaviour change in a way a remembered
     * approval should not survive.
     */
    public function version(): string
    {
        return '1.0';
    }

    /**
     * The UI grouping, and the unit an agent allowlist can name wholesale.
     */
    public function group(): string
    {
        return 'general';
    }

    /**
     * Non-null marks the tool deprecated; the string explains what to use
     * instead. A deprecated tool still resolves -- breaking a running system
     * is not a deprecation, it is a removal.
     */
    public function deprecated(): ?string
    {
        return null;
    }

    /**
     * A short human summary of a specific call, shown on the approval card and
     * in the trace. Override to say "Refund £42.00 to order 1234" instead of
     * repeating the argument list.
     */
    public function summarize(ToolInput $input): string
    {
        return $this->name();
    }

    /**
     * The JSON schema advertised to the model. Generated from `rules()`;
     * override only for a shape the rules genuinely cannot express.
     *
     * @return array<string, mixed>
     */
    public function schema(RuleSchemaGenerator $generator): array
    {
        return $generator->generate($this->name(), $this->rules(), $this->descriptions());
    }

    /**
     * Validate model-supplied arguments.
     *
     * Failure is not a run-ending error: the model is told what was wrong and
     * gets to correct itself.
     *
     * @param array<string, mixed> $arguments
     *
     * @throws ToolInputInvalid
     */
    final public function validate(array $arguments, ValidationFactory $validator): ToolInput
    {
        $rules = $this->rules();

        if ($rules === []) {
            return new ToolInput([]);
        }

        try {
            /** @var array<string, mixed> $validated */
            $validated = $validator->make($arguments, $rules)->validate();
        } catch (ValidationException $e) {
            /** @var array<string, list<string>> $errors */
            $errors = $e->errors();

            throw ToolInputInvalid::make($this->name(), $errors);
        }

        return new ToolInput($validated);
    }
}
