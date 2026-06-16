<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint\Output;

/**
 * Where a formatter writes: stdout (default), stderr, or a file path.
 * `stdout`/`stderr` are reserved in the `--format=<format>[:<target>]` grammar; anything else is
 * a filesystem path.
 *
 * @internal
 */
final readonly class OutputTarget
{
    public function __construct(
        public OutputChannel $channel,
        public ?string $path = null,
    ) {}

    /**
     * Parses the portion after the first colon in a `--format` token; null defaults to stdout.
     */
    public static function fromToken(?string $token): self
    {
        return match ($token) {
            null, '', 'stdout' => new self(OutputChannel::Stdout),
            'stderr' => new self(OutputChannel::Stderr),
            default => new self(OutputChannel::File, $token),
        };
    }

    /**
     * Canonical string form for this target: the file path, or the stream name. Used for collision
     * detection. Paths can never equal `stdout`/`stderr` (those resolve to channels in {@see fromToken()}).
     */
    public function label(): string
    {
        return $this->channel === OutputChannel::File
            ? (string) $this->path
            : $this->channel->value;
    }
}
