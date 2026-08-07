<?php

declare(strict_types=1);

namespace Pandora\Contracts;

use Pandora\Agents\AgentBlueprint;

/**
 * A class-based agent definition.
 *
 * Class definitions are version-controlled and authoritative: on boot they are
 * synchronised into the database, and the fields they set win over database
 * edits to the same fields. Fields a definition does not set remain operator-
 * editable in the control center.
 *
 * @example
 *  final class SupportAgent implements AgentDefinition
 *  {
 *      public function define(AgentBlueprint $agent): AgentBlueprint
 *      {
 *          return $agent
 *              ->name('Support')
 *              ->instructions('Help customers resolve support issues.')
 *              ->model('openai', 'gpt-4o-mini');
 *      }
 *  }
 */
interface AgentDefinition
{
    public function define(AgentBlueprint $agent): AgentBlueprint;
}
