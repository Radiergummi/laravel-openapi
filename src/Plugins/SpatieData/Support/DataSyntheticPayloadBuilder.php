<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\SpatieData\Support;

use Illuminate\Container\Attributes\Scoped;
use Spatie\LaravelData\Concerns\ValidateableData;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Support\DataConfig;

/**
 * Builds a minimal synthetic payload for a {@see Data} subclass so that
 * {@see ValidateableData::getValidationRules()} returns rules for all properties.
 *
 * Spatie's `DataValidationRulesResolver` skips properties whose key is absent from the payload.
 * Supplying every key forces the full rule set. A visited-set guards against self-referential cycles.
 */
#[Scoped]
final readonly class DataSyntheticPayloadBuilder
{
    public function __construct(
        private DataConfig $dataConfig,
    ) {}

    /**
     * @param class-string<Data> $dataClass
     *
     * @return array<string, mixed>
     */
    public function build(string $dataClass): array
    {
        $visited = [];

        return $this->buildRecursive($dataClass, $visited);
    }

    /**
     * @param class-string<Data>              $dataClass
     * @param array<class-string<Data>, true> $visited
     *
     * @return array<string, mixed>
     */
    private function buildRecursive(string $dataClass, array &$visited): array
    {
        if (isset($visited[$dataClass])) {
            return [];
        }

        $visited[$dataClass] = true;
        $dataClassMeta = $this->dataConfig->getDataClass($dataClass);
        $payload = [];

        foreach ($dataClassMeta->properties as $dataProperty) {
            // Use inputMappedName: validation runs on the incoming payload (what Arr::has checks).
            $name = $dataProperty->inputMappedName ?? $dataProperty->name;
            $kind = $dataProperty->type->kind;

            if ($kind->isDataObject()) {
                /** @var class-string<Data> $nestedClass */
                $nestedClass = $dataProperty->type->dataClass;
                $payload[$name] = $this->buildRecursive($nestedClass, $visited);

                continue;
            }

            if ($kind->isDataCollectable()) {
                /** @var class-string<Data> $itemClass */
                $itemClass = $dataProperty->type->dataClass;
                $payload[$name] = [$this->buildRecursive($itemClass, $visited)];

                continue;
            }

            // Single null item so Arr::has succeeds on dotted paths like `tags.0`.
            if ($kind->isNonDataIteratable()) {
                $payload[$name] = [null];

                continue;
            }

            $payload[$name] = null;
        }

        return $payload;
    }
}
