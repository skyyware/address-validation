<?php

declare(strict_types=1);

namespace Skyyware\SkyyAddressValidation\Validation\Constraint;

use Symfony\Component\Validator\Constraint;

final class PhoneCharacters extends Constraint
{
    public const ERROR_CODE = 'SKYY_PHONE_CHARACTERS';

    protected const ERROR_NAMES = [
        self::ERROR_CODE => self::ERROR_CODE,
    ];

    public string $message = 'VIOLATION::SKYY_PHONE_CHARACTERS';
}
