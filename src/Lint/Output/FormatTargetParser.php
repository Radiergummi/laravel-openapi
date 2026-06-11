<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint\Output;

use InvalidArgumentException;
use Radiergummi\OpenApi\Lint\LinterOutputFormat;

use function array_column;
use function implode;
use function sprintf;
use function strpos;
use function substr;

/**
 * Parses repeatable `--format=<format>[:<target>]` tokens into resolved {@see FormatTarget}s.
 *
 * Each token names a format and, after the first colon, an optional target (`stdout` default,
 * `stderr`, or a file path — see {@see OutputTarget::fromToken()}). Two formats may not share a
 * destination: writing two streams to one stdout/stderr/file would interleave into garbage.
 *
 * @internal
 */
final readonly class FormatTargetParser
{
    /**
     * @param list<string> $tokens
     *
     * @return list<FormatTarget>
     *
     * @throws InvalidArgumentException on an unknown format or a duplicated destination
     */
    public function parse(array $tokens): array
    {
        $targets = [];
        $seen = [];

        foreach ($tokens as $token) {
            [$formatName, $targetToken] = $this->split($token);

            $format = LinterOutputFormat::tryFrom($formatName)
                ?? throw new InvalidArgumentException(sprintf(
                    'Invalid format: %s. Allowed values are: %s.',
                    $formatName,
                    implode(', ', array_column(LinterOutputFormat::cases(), 'value')),
                ));

            $target = OutputTarget::fromToken($targetToken);
            $identity = $target->identity();

            if (isset($seen[$identity])) {
                throw new InvalidArgumentException(sprintf(
                    'Cannot write two formats to %s; give each a distinct target, '
                    . 'e.g. --format=cobertura:coverage.xml.',
                    $target->label(),
                ));
            }

            $seen[$identity] = true;
            $targets[] = new FormatTarget($format, $target);
        }

        return $targets;
    }

    /**
     * Split a token on its first colon only, so Windows paths (`cobertura:C:\out\cov.xml`) keep
     * their drive-letter colon. Returns [format, target|null].
     *
     * @return array{string, null|string}
     */
    private function split(string $token): array
    {
        $position = strpos($token, ':');

        return $position === false
            ? [$token, null]
            : [substr($token, 0, $position), substr($token, $position + 1)];
    }
}
