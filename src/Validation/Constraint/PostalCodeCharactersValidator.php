<?php

declare(strict_types=1);

namespace Skyyware\SkyyAddressValidation\Validation\Constraint;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

final class PostalCodeCharactersValidator extends ConstraintValidator
{
    private const VALID_CHARACTERS_PATTERN = '/\A[\p{L}\p{M}\p{Nd}\p{Zs}-]+\z/u';

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof PostalCodeCharacters) {
            throw new UnexpectedTypeException($constraint, PostalCodeCharacters::class);
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
            ->setCode(PostalCodeCharacters::ERROR_CODE)
            ->addViolation();
    }
}
