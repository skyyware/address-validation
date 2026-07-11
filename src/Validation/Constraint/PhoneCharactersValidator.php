<?php

declare(strict_types=1);

namespace Skyyware\SkyyAddressValidation\Validation\Constraint;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

final class PhoneCharactersValidator extends ConstraintValidator
{
    private const VALID_CHARACTERS_PATTERN = '/\A[\p{Nd}\p{Zs}+().\/-]+\z/u';

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof PhoneCharacters) {
            throw new UnexpectedTypeException($constraint, PhoneCharacters::class);
        }

        if ($value === null || $value === '') {
            return;
        }

        if (!\is_string($value)) {
            throw new UnexpectedValueException($value, 'string');
        }

        if (preg_match(self::VALID_CHARACTERS_PATTERN, $value) === 1) {
            return;
        }

        $this->context->buildViolation($constraint->message)
            ->setCode(PhoneCharacters::ERROR_CODE)
            ->addViolation();
    }
}
