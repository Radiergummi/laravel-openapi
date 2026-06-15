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
 * Reads a model's Laravel factory `definition()` as a source of per-property example values.
 *
 * A factory `definition()` is a hand-curated, app-maintained realistic payload — exactly the kind
 * of data the generated document should show consumers. `Model::factory()` comes from the
 * `HasFactory` trait (the base `Model` has no `factory()` method), so a model exposes a factory
 * when `method_exists($modelClass, 'factory')`. The factory is instantiated and its `definition()`
 * invoked so runtime `fake()` calls resolve to concrete values (Tier 0-ish: reflection + runtime
 * invocation of app-owned metadata, not dataflow).
 *
 * Only scalar (and null) definition values become examples — a value that is itself a factory, a
 * relationship closure, or an array/object is skipped, as it cannot stand in as a simple property
 * example.
 *
 * Determinism — a factory's `definition()` draws from the container-bound `Faker\Generator`, whose
 * seed this reader controls. Before **every** `definition()` invocation it reseeds that generator
 * from `(faker_seed ^ crc32($modelClass))` so reads are order-independent (Faker delegates `seed()`
 * to the global `mt_srand()`, so any RNG consumer between reads would otherwise drift the state) and
 * distinct models draw distinct values. When `faker_seed` is null (the config opt-out), factory
 * examples are disabled — they would be non-deterministic and churn the byte-exact snapshot output.
 *
 * Degrades gracefully — factory discovery, constructor side effects, or a `definition()` that
 * touches the database can throw; on any `Throwable` the reader logs one warning and returns `[]`.
 *
 * @internal
 */
#[Scoped]
final class ModelFactoryExampleReader
{
    /**
     * Per-model memo of column → scalar example value.
     *
     * @var array<class-string<Model>, array<string, null|scalar>>
     */
    private array $cache = [];

    public function __construct(
        private readonly ?int $seed,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * The scalar example values from the model's factory `definition()`, keyed by column. Returns
     * an empty map when the model has no factory, the seed is null (determinism opt-out), or any
     * error occurs during resolution.
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
            // Reseed the bound generator immediately before invoking definition(), mixing the model
            // class into the seed so reads stay order-independent and distinct models differ.
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

        $examples = [];

        foreach ($definition as $column => $value) {
            // Only scalars stand in as a simple property example; closures, factory refs, and
            // nested arrays/objects are skipped.
            if (is_scalar($value) || $value === null) {
                $examples[$column] = $value;
            }
        }

        return $this->cache[$modelClass] = $examples;
    }
}
