<?php

declare(strict_types=1);

namespace Skyyware\SkyyAddressValidation\Tests\Unit\Validation;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Skyyware\SkyyAddressValidation\Validation\Constraint\NoDigitsInName;
use Skyyware\SkyyAddressValidation\Validation\Constraint\PhoneCharacters;
use Skyyware\SkyyAddressValidation\Validation\Constraint\PostalCodeCharacters;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\Validation;

final class LocalConstraintsTest extends TestCase
{
    #[DataProvider('validNameProvider')]
    public function testNameAllowsInternationalUnicodeText(string $value): void
    {
        $this->assertValid($value, new NoDigitsInName());
    }

    #[DataProvider('invalidNameProvider')]
    public function testNameRejectsUnicodeDigitsWithOneStableViolation(string $value): void
    {
        $this->assertSingleViolation($value, new NoDigitsInName(), 'SKYY_NAME_DIGITS');
    }

    #[DataProvider('validPhoneProvider')]
    public function testPhoneAllowsInternationalFormattingCharacters(string $value): void
    {
        $this->assertValid($value, new PhoneCharacters());
    }

    #[DataProvider('invalidPhoneProvider')]
    public function testPhoneRejectsLettersControlsAndUnsupportedPunctuation(string $value): void
    {
        $this->assertSingleViolation($value, new PhoneCharacters(), 'SKYY_PHONE_CHARACTERS');
    }

    #[DataProvider('validPostalCodeProvider')]
    public function testPostalCodeAllowsInternationalLettersDigitsSpacesAndHyphens(string $value): void
    {
        $this->assertValid($value, new PostalCodeCharacters());
    }

    #[DataProvider('invalidPostalCodeProvider')]
    public function testPostalCodeRejectsControlsAndUnsupportedPunctuation(string $value): void
    {
        $this->assertSingleViolation($value, new PostalCodeCharacters(), 'SKYY_POSTAL_CHARACTERS');
    }

    public function testAllConstraintsPassThroughNullAndEmptyStrings(): void
    {
        $constraints = [
            new NoDigitsInName(),
            new PhoneCharacters(),
            new PostalCodeCharacters(),
        ];

        foreach ($constraints as $constraint) {
            $this->assertValid(null, $constraint);
            $this->assertValid('', $constraint);
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function validNameProvider(): iterable
    {
        yield 'hyphenated Latin name' => ['Anne-Marie'];
        yield 'apostrophe' => ["O'Connor"];
        yield 'Chinese characters' => ['李 雷'];
        yield 'decomposed combining mark' => ["Jose\u{0301}"];
        yield 'Unicode punctuation' => ["D\u{2019}Arcy"];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidNameProvider(): iterable
    {
        yield 'ASCII digit' => ['Anne2'];
        yield 'Arabic-Indic digit' => ["Sofia\u{0661}"];
        yield 'fullwidth digit' => ["李\u{FF11}"];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function validPhoneProvider(): iterable
    {
        yield 'German international format' => ['+49 (0)711 / 123-45'];
        yield 'dot separators' => ['+1.212.555.0100'];
        yield 'Unicode space separators' => ["+33\u{202F}1\u{202F}23\u{202F}45\u{202F}67\u{202F}89"];
        yield 'Unicode decimal digits' => ["+\u{0664}\u{0669} (\u{0660}) \u{0667}\u{0661}\u{0661} / \u{0661}\u{0662}\u{0663}-\u{0664}\u{0665}"];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidPhoneProvider(): iterable
    {
        yield 'letters' => ['+49 711 CALL-ME'];
        yield 'newline' => ["123\n456"];
        yield 'tab' => ["123\t456"];
        yield 'unsupported punctuation' => ['123_456'];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function validPostalCodeProvider(): iterable
    {
        yield 'German numeric code' => ['70173'];
        yield 'British alphanumeric code' => ['SW1A 1AA'];
        yield 'Canadian alphanumeric code' => ['H3Z 2Y7'];
        yield 'Greek letters' => ['ΑΒ-123'];
        yield 'decomposed combining mark' => ["A\u{030A}1-2"];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidPostalCodeProvider(): iterable
    {
        yield 'newline' => ["SW1A\n1AA"];
        yield 'tab' => ["H3Z\t2Y7"];
        yield 'slash' => ['123/45'];
        yield 'period' => ['123.45'];
    }

    private function assertValid(mixed $value, Constraint $constraint): void
    {
        self::assertCount(0, Validation::createValidator()->validate($value, $constraint));
    }

    private function assertSingleViolation(mixed $value, Constraint $constraint, string $expectedCode): void
    {
        $violations = Validation::createValidator()->validate($value, $constraint);

        self::assertCount(1, $violations);
        self::assertSame($expectedCode, $violations->get(0)->getCode());
    }
}
