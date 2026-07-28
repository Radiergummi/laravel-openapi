<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\Lint;

use Radiergummi\OpenApi\Tests\Fixtures\ScalarOnlyData;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Fixture controller whose 2xx bodies carry no fields and no component reference: a plain string
 * payload, a binary download, and the two shapes OAS 3.1 nullability produces (a widened type
 * array, and a `oneOf` split whose `$ref` sits on an inner branch).
 *
 * All four are invisible to the node-level signals and only show up on the raw media-type schema.
 */
final class ScalarResponseController
{
    /**
     * Promoted so the nullable returns below stay genuinely nullable: a method body of plain
     * `return null;` lets static analysis narrow the declared type away.
     */
    public function __construct(
        private readonly ?string $message = 'pong',
        private readonly ?ScalarOnlyData $payload = null,
    ) {}

    /**
     * Return a plain string payload.
     */
    public function text(): string
    {
        return 'pong';
    }

    /**
     * Stream a binary download.
     */
    public function download(): StreamedResponse
    {
        return new StreamedResponse(static fn() => print 'payload');
    }

    /**
     * Return a nullable string payload, documented as a widened type array.
     */
    public function nullableText(): ?string
    {
        return $this->message;
    }

    /**
     * Return a nullable Data payload, documented as a `oneOf` split around the component ref.
     */
    public function nullableData(): ?ScalarOnlyData
    {
        return $this->payload;
    }
}
