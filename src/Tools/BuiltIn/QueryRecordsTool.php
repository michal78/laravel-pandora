<?php

declare(strict_types=1);

namespace Pandora\Pandora\Tools\BuiltIn;

use Illuminate\Database\Eloquent\Model;
use Pandora\Pandora\Tools\Enums\RiskLevel;
use Pandora\Pandora\Tools\Tool;
use Pandora\Pandora\Tools\ToolContext;
use Pandora\Pandora\Tools\ToolInput;
use Pandora\Pandora\Tools\ToolResult;

/**
 * Read application records the deployment has explicitly opened up.
 *
 * Not "run a query". The model names a configured RESOURCE, and the
 * deployment decided what that resource means: which model, which columns are
 * readable, which are filterable, and how many rows may come back. Nothing is
 * available until somebody wrote it into config, and there is no path from a
 * model's output to a raw SQL string.
 *
 * The configured `authorize` callback is what makes this safe per-record. It
 * runs against the ACTOR, so an agent reads exactly what the person it acts
 * for could read.
 */
final class QueryRecordsTool extends Tool
{
    public function name(): string
    {
        return 'query_records';
    }

    public function description(): string
    {
        return 'Look up application records from a named, pre-approved resource. '
            .'If a resource you want does not exist, say so rather than guessing at a name.';
    }

    public function group(): string
    {
        return 'data';
    }

    public function rules(): array
    {
        return [
            'resource' => 'required|string|max:64',
            'filters' => 'nullable|array',
            'limit' => 'nullable|integer|min:1|max:100',
        ];
    }

    public function descriptions(): array
    {
        return [
            'resource' => 'The configured resource name, for example "orders".',
            'filters' => 'Field/value pairs to match exactly. Only filterable fields are accepted.',
            'limit' => 'How many records to return. Defaults to 10.',
        ];
    }

    public function risk(): RiskLevel
    {
        return RiskLevel::Low;
    }

    public function summarize(ToolInput $input): string
    {
        return 'Look up '.$input->string('resource', 'records');
    }

    /**
     * Layer 5. The resource must exist, and the deployment's own callback --
     * written against the acting user -- must say yes.
     */
    public function authorize(ToolInput $input, ToolContext $context): bool
    {
        $resource = $this->resource((string) $input->string('resource', ''));

        if ($resource === null) {
            return false;
        }

        $user = $context->user();

        if ($user === null) {
            return false;
        }

        $gate = $resource['authorize'] ?? null;

        // No callback means no access. A resource whose author did not say who
        // may read it has not said "everyone".
        return is_callable($gate) && $gate($user, $input->toArray()) === true;
    }

    public function handle(ToolInput $input, ToolContext $context): ToolResult
    {
        $name = (string) $input->string('resource', '');
        $resource = $this->resource($name);

        if ($resource === null) {
            return ToolResult::failure("There is no resource named [{$name}].");
        }

        /** @var class-string<Model> $modelClass */
        $modelClass = $resource['model'];
        /** @var list<string> $readable */
        $readable = $resource['fields'] ?? [];
        /** @var list<string> $filterable */
        $filterable = $resource['filterable'] ?? [];
        /** @var int $maxLimit */
        $maxLimit = $resource['max_results'] ?? 25;

        $query = $modelClass::query();

        foreach ($input->array('filters') as $field => $value) {
            if (! is_string($field) || ! in_array($field, $filterable, true)) {
                return ToolResult::failure(
                    "[{$field}] cannot be filtered on. Filterable fields: "
                    .(implode(', ', $filterable) ?: 'none').'.',
                );
            }

            if (! is_scalar($value)) {
                return ToolResult::failure("The filter for [{$field}] must be a single value.");
            }

            $query->where($field, $value);
        }

        // A scope the deployment supplies, for the tenant or ownership
        // constraint that must apply whatever the model asked for.
        $scope = $resource['scope'] ?? null;

        if (is_callable($scope)) {
            $scope($query, $context->user(), $context);
        }

        $limit = min($input->integer('limit', 10) ?? 10, $maxLimit);

        $records = $query->limit($limit)->get()
            ->map(static fn (Model $record): array => collect($record->attributesToArray())
                ->only($readable)
                ->all())
            ->all();

        return ToolResult::success(
            count($records) === 0
                ? "No {$name} matched."
                : sprintf('%d %s found.', count($records), $name),
            ['resource' => $name, 'records' => $records],
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resource(string $name): ?array
    {
        /** @var array<string, array<string, mixed>> $resources */
        $resources = config('pandora.tools.resources', []);

        return $resources[$name] ?? null;
    }
}
