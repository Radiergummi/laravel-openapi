<?php

declare(strict_types=1);

use function Radiergummi\OpenApi\class_resource_name;

uses()->group('openapi');

it('strips the namespace from a fully-qualified class name', function (): void {
    expect(class_resource_name('App\\Models\\User'))->toBe('User');
});

it('splits a StudlyCase basename into a human-readable resource name', function (): void {
    expect(class_resource_name('App\\Models\\GroupMembership'))->toBe('Group Membership');
});

it('never leaks the namespace into the resource name', function (): void {
    expect(class_resource_name('App\\Models\\Internal\\BillingAccount'))
        ->not->toContain('App')
        ->not->toContain('Internal')
        ->not->toContain('\\')
        ->toBe('Billing Account');
});

it('accepts a bare class name without a namespace', function (): void {
    expect(class_resource_name('Invoice'))->toBe('Invoice');
});
