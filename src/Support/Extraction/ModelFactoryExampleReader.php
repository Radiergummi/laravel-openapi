<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Support\Extraction;

use Faker\Generator as Faker;
use Illuminate\Container\Attributes\Scoped;
use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Psr\Log\LoggerInterface;
use Throwable;

use function array_key_exists;
use function crc32;
use function is_scalar;
use function method_exists;

/**
 * Reads a model's factory `definition()` as a source of per-property example values.
 *
 * Only scalar/null values are used; closures, relationship factories, and nested arrays
 * are skipped. The Faker instance is reseeded with `faker_seed ^ crc32($model)` so reads
 * are order-independent and distinct models draw distinct values. When `faker_seed` is null
 * examples are disabled to avoid non-deterministic snapshot churn.
 *
 * @internal
 */
#[Scoped]
final class ModelFactoryExampleReader
{
    /** @var array<class-string<Model>, array<string, null|scalar>> */
    private array $cache = [];

    public function __construct(
        private readonly ?int $seed,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Scalar example values from the model's factory `definition()`, keyed by column.
     * Returns an empty map when the model has no factory, the seed is null, or resolution fails.
     *
     * @param class-string<Model> $modelClass
     *
     * @return array<string, null|scalar>
     */
    public function examplesFor(string $modelClass): array
    {
        if (array_key_exists($modelClass, $this->cache)) {
            return $this->cache[$modelClass];
        }

        if ($this->seed === null || !method_exists($modelClass, 'factory')) {
            return $this->cache[$modelClass] = [];
        }

        try {
            Container::getInstance()->make(Faker::class)->seed($this->seed ^ crc32($modelClass));

            /** @var array<string, mixed> $definition */
            $definition = $modelClass::factory()->definition();
        } catch (Throwable $throwable) {
            $this->logger->warning('ModelFactoryExampleReader: factory definition() failed, no examples', [
                'model' => $modelClass,
                'exception' => $throwable->getMessage(),
            ]);

            return $this->cache[$modelClass] = [];
        }

        // Filter to scalar/null; closures, factory refs, and nested arrays are not usable.
        $examples = array_filter(
            $definition,
            static fn(mixed $value): bool => is_scalar($value) || $value === null,
        );

        return $this->cache[$modelClass] = $examples;
    }
}
