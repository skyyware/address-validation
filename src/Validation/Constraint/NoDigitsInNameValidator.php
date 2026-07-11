<?php

declare(strict_types=1);

namespace Skyyware\SkyyAddressValidation\Validation\Constraint;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

final class NoDigitsInNameValidator extends ConstraintValidator
{
    private const DIGIT_PATTERN = '/\p{Nd}/u';

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof NoDigitsInName) {
            throw new UnexpectedTypeException($constraint, NoDigitsInName::class);
        }

        if ($value === null || $value === '') {
            return;
        }

        if (!\is_string($value)) {
            throw new UnexpectedValueException($value, 'string');
        }

        if (preg_match(self::DIGIT_PATTERN, $value) === 0) {
            return;
        }

        $this->context->buildViolation($constraint->message)
            ->setCode(NoDigitsInName::ERROR_CODE)
            ->addViolation();
    }
}
