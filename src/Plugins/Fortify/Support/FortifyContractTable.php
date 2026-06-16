<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\Fortify\Support;

use Laravel\Fortify\Contracts\LoginResponse;
use Laravel\Fortify\Contracts\LogoutResponse;
use Laravel\Fortify\Contracts\PasswordConfirmedResponse;
use Laravel\Fortify\Contracts\PasswordResetResponse;
use Laravel\Fortify\Contracts\PasswordUpdateResponse;
use Laravel\Fortify\Contracts\ProfileInformationUpdatedResponse;
use Laravel\Fortify\Contracts\RegisterResponse;
use Laravel\Fortify\Contracts\SuccessfulPasswordResetLinkRequestResponse;
use Laravel\Fortify\Fortify;
use OpenApi\Annotations as OA;
use Radiergummi\OpenApi\Support\Generator\SchemaFromArrayDefinition;

/**
 * Hand-maintained contract table for Fortify's headless core-auth routes, keyed by route name.
 *
 * Field names are read from {@see Fortify::username()} and {@see Fortify::email()} so the body
 * reflects the app config. Password fields are typed `string/format:password` because
 * `Password::default()` constraints are not statically readable.
 *
 * @internal
 */
final class FortifyContractTable
{
    public static function for(string $routeName): ?FortifyContractEntry
    {
        $username = Fortify::username();
        $email = Fortify::email();

        return match ($routeName) {
            // POST /login: JSON returns {two_factor: false} at 200 (LoginResponse).
            'login.store' => new FortifyContractEntry(
                requestSchema: self::object([
                    $username => ['type' => 'string'],
                    'password' => ['type' => 'string', 'format' => 'password'],
                    'remember' => ['type' => 'boolean'],
                ], required: [$username, 'password']),
                requestSchemaName: 'LoginRequest',
                responseContract: LoginResponse::class,
                successStatus: 200,
                successSchema: self::object(['two_factor' => ['type' => 'boolean']]),
            ),
            // POST /logout: JSON returns '' at 204 (LogoutResponse).
            'logout' => new FortifyContractEntry(
                requestSchema: null,
                requestSchemaName: null,
                responseContract: LogoutResponse::class,
                successStatus: 204,
                successSchema: null,
            ),
            // POST /register: JSON returns '' at 201 (RegisterResponse).
            'register.store' => new FortifyContractEntry(
                requestSchema: self::object([
                    'name' => ['type' => 'string'],
                    $email => ['type' => 'string', 'format' => 'email'],
                    'password' => ['type' => 'string', 'format' => 'password'],
                    'password_confirmation' => ['type' => 'string', 'format' => 'password'],
                ], required: ['name', $email, 'password', 'password_confirmation']),
                requestSchemaName: 'RegisterRequest',
                responseContract: RegisterResponse::class,
                successStatus: 201,
                successSchema: null,
            ),
            // POST /forgot-password: JSON returns {message} at 200.
            'password.email' => new FortifyContractEntry(
                requestSchema: self::object([
                    $email => ['type' => 'string', 'format' => 'email'],
                ], required: [$email]),
                requestSchemaName: 'ForgotPasswordRequest',
                responseContract: SuccessfulPasswordResetLinkRequestResponse::class,
                successStatus: 200,
                successSchema: self::object(['message' => ['type' => 'string']]),
            ),
            // POST /reset-password: JSON returns {message} at 200.
            'password.update' => new FortifyContractEntry(
                requestSchema: self::object([
                    'token' => ['type' => 'string'],
                    $email => ['type' => 'string', 'format' => 'email'],
                    'password' => ['type' => 'string', 'format' => 'password'],
                    'password_confirmation' => ['type' => 'string', 'format' => 'password'],
                ], required: ['token', $email, 'password', 'password_confirmation']),
                requestSchemaName: 'ResetPasswordRequest',
                responseContract: PasswordResetResponse::class,
                successStatus: 200,
                successSchema: self::object(['message' => ['type' => 'string']]),
            ),
            // POST /user/confirm-password: JSON returns '' at 201 (PasswordConfirmedResponse).
            'password.confirm.store' => new FortifyContractEntry(
                requestSchema: self::object([
                    'password' => ['type' => 'string', 'format' => 'password'],
                ], required: ['password']),
                requestSchemaName: 'ConfirmPasswordRequest',
                responseContract: PasswordConfirmedResponse::class,
                successStatus: 201,
                successSchema: null,
            ),
            // GET /user/confirmed-password-status: controller returns {confirmed} directly (no contract).
            'password.confirmation' => new FortifyContractEntry(
                requestSchema: null,
                requestSchemaName: null,
                responseContract: null,
                successStatus: 200,
                successSchema: self::object(['confirmed' => ['type' => 'boolean']]),
            ),
            // PUT /user/password: JSON returns '' at 200 (PasswordUpdateResponse).
            'user-password.update' => new FortifyContractEntry(
                requestSchema: self::object([
                    'current_password' => ['type' => 'string', 'format' => 'password'],
                    'password' => ['type' => 'string', 'format' => 'password'],
                    'password_confirmation' => ['type' => 'string', 'format' => 'password'],
                ], required: ['current_password', 'password', 'password_confirmation']),
                requestSchemaName: 'UpdatePasswordRequest',
                responseContract: PasswordUpdateResponse::class,
                successStatus: 200,
                successSchema: null,
            ),
            // PUT /user/profile-information: JSON returns '' at 200 (ProfileInformationUpdatedResponse).
            'user-profile-information.update' => new FortifyContractEntry(
                requestSchema: self::object([
                    'name' => ['type' => 'string'],
                    $email => ['type' => 'string', 'format' => 'email'],
                ], required: ['name', $email]),
                requestSchemaName: 'UpdateProfileInformationRequest',
                responseContract: ProfileInformationUpdatedResponse::class,
                successStatus: 200,
                successSchema: null,
            ),
            default => null,
        };
    }

    /**
     * @param array<string, array<string, mixed>> $properties
     * @param list<string>                        $required
     */
    private static function object(array $properties, array $required = []): OA\Schema
    {
        $definition = ['type' => 'object', 'properties' => $properties];

        if ($required !== []) {
            $definition['required'] = $required;
        }

        return SchemaFromArrayDefinition::build($definition);
    }
}
