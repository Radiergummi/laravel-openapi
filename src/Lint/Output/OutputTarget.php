<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint\Output;

/**
 * Where a single formatter's output goes: stdout (the default), stderr, or a file path.
 *
 * `stdout` and `stderr` are reserved keywords in the `--format=<format>[:<target>]` grammar; any
 * other target is a filesystem path. {@see identity()} gives a stable key used to reject two
 * formats writing to the same destination.
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
     * Stable destination identity for collision detection: the stream name for a console channel,
     * or `file:<path>` for a file (so two formats may not target the same path either).
     */
    public function identity(): string
    {
        return $this->channel === OutputChannel::File
            ? 'file:' . $this->path
            : $this->channel->value;
    }

    /**
     * Human label for error messages.
     */
    public function label(): string
    {
        return $this->channel === OutputChannel::File
            ? (string) $this->path
            : $this->channel->value;
    }
}
