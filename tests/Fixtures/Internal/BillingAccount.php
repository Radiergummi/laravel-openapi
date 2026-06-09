<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\Internal;

/**
 * A plain, multi-word class under an "internal" namespace, used to prove that an unmapped object
 * type surfaces as a humanized resource name without leaking its namespace into the spec.
 */
final class BillingAccount {}
