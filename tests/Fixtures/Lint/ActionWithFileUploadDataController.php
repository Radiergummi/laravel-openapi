<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\Lint;

/**
 * Fixture controller whose method accepts an Action (not a Data class directly). The Action's
 * constructor carries {@see FileUploadFixtureData} — used to verify Action-indirection
 * detection in MultipartFileWithoutMultipart.
 */
final class ActionWithFileUploadDataController
{
    public function upload(ActionWithFileUploadData $action): void {}
}
