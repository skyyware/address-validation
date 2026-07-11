<?php

declare(strict_types=1);

namespace Skyyware\SkyyAddressValidation\Validation\Constraint;

use Symfony\Component\Validator\Constraint;

final class NoDigitsInName extends Constraint
{
    public const ERROR_CODE = 'SKYY_NAME_DIGITS';

    protected const ERROR_NAMES = [
        self::ERROR_CODE => self::ERROR_CODE,
    ];

    public string $message = 'VIOLATION::SKYY_NAME_DIGITS';
}
