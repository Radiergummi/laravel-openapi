<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Radiergummi\OpenApi\Support\Extraction\PayloadParameterScanner;

/**
 * Minimal Eloquent model fixture.
 *
 * Fixture actions carry a non-DTO constructor parameter so the {@see PayloadParameterScanner} has
 * a parameter to *skip* on its way to the request DTO. Only the class needs to exist — these
 * tests never touch the database.
 */
class User extends Model {}
