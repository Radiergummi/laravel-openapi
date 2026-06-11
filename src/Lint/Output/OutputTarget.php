<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint\Output;

/**
 * Where a single formatter's output goes: stdout (the default), stderr, or a file path.
 *
 * `stdout` and `stderr` are reserved keywords in the `--format=<format>[:<target>]` grammar; any
 * other target is a filesystem path. {@see label()} is the canonical string form, used both to
 * reject two formats writing to the same destination and in error messages.
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
     * Build a target from the portion after the first colon in a `--format` token (null when the
     * token had no colon, i.e. the default stdout stream).
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
     * Canonical string form: the file path, or the stream name for a console channel. Doubles as
     * the destination identity for collision detection — a path can never equal `stdout`/`stderr`
     * (those tokens resolve to channels in {@see fromToken()}), so no prefix is needed to keep
     * file and console targets distinct.
     */
    public function label(): string
    {
        return $this->channel === OutputChannel::File
            ? (string) $this->path
            : $this->channel->value;
    }
}
