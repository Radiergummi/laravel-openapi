<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license       MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Events;

/**
 * Why an action descriptor was excluded from a spec.
 *
 * Set by {@see InclusionEvaluator} at the early-return for each failure stage and carried
 * on {@see InclusionDecision::$reason}.
 */
enum SkipReason: string
{
    case GlobalFilter = 'global-filter';
    case SpecMembership = 'spec-membership';
    case Visibility = 'visibility';
}
