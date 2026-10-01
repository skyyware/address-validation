<?php

declare(strict_types=1);

namespace Skyyware\SkyyAddressValidation\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ZipArchive;

final class ReleaseToolingTest extends TestCase
{
    private string $projectRoot;

    protected function setUp(): void
    {
        $this->projectRoot = \dirname(__DIR__, 2);
    }

    public function testComposerAllowsOnlyTheCurrentShopwareReleaseLine(): void
    {
        $metadata = json_decode(
            (string) file_get_contents($this->projectRoot . '/composer.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        foreach ($metadata['require'] as $package => $constraint) {
            if (!str_starts_with($package, 'shopware/')) {
                continue;
            }

            self::assertTrue(\Composer\Semver\Semver::satisfies('6.7.15.0', $constraint));
            self::assertFalse(\Composer\Semver\Semver::satisfies('6.6.10.27', $constraint));
            self::assertFalse(\Composer\Semver\Semver::satisfies('6.8.0.0', $constraint));
        }
    }

    public function testReleaseEntrypointsExistAndScriptsAreExecutable(): void
    {
        foreach ([
            'bin/check',
            'bin/integration',
            'bin/package',
            'phpunit.integration.xml.dist',
            'tests/Integration/bootstrap.php',
        ] as $path) {
            self::assertFileExists($this->projectRoot . '/' . $path);
        }

        foreach (['bin/check', 'bin/integration', 'bin/package'] as $path) {
            self::assertFileIsReadable($this->projectRoot . '/' . $path);
            self::assertTrue(is_executable($this->projectRoot . '/' . $path));
        }
    }

    public function testChecksAndPackagingEnforceADevelopmentFreeRuntimeArchive(): void
    {
        $check = $this->read('bin/check');
        foreach ([
            'composer validate --strict',
            'vendor/bin/phpunit',
            'vendor/bin/phpstan analyse',
            'vendor/bin/php-cs-fixer fix --dry-run',
            'git diff --check',
            'bin/package',
        ] as $requiredText) {
            self::assertStringContainsString($requiredText, $check);
        }

        $package = $this->read('bin/package');
        foreach ([
            'PACKAGE_NAME=SkyyAddressValidation',
            'VERSION=${VERSION:-0.2.0}',
            'ARCHIVE="$BUILD_DIR/$PACKAGE_NAME-$VERSION.zip"',
            '*/tests/*',
            '*/vendor/*',
            '*/.git*',
            '*/composer.lock',
            '$PACKAGE_NAME/src/SkyyAddressValidation.php',
        ] as $requiredText) {
            self::assertStringContainsString($requiredText, $package);
        }
    }

    public function testDocumentationExplainsProviderOperationsAndPrivacyResponsibilities(): void
    {
        $readme = $this->read('README.md');
        foreach ([
            'disabled by default',
            'self-hosted',
            'contracted',
            'personal data',
            '30 days',
            'five minutes',
            'one request per second',
            'attribution',
            'data protection',
            'public Nominatim',
        ] as $requiredText) {
            self::assertStringContainsStringIgnoringCase($requiredText, $readme);
        }

        $changelog = $this->read('CHANGELOG.md');
        self::assertStringContainsString('## [0.2.0]', $changelog);

        $security = $this->read('SECURITY.md');
        self::assertStringContainsString('0.2.x', $security);
    }

    public function testPackagedComposerMetadataCarriesTheReleaseVersion(): void
    {
        $output = [];
        $exitCode = 1;
        exec(escapeshellarg($this->projectRoot . '/bin/package') . ' 2>&1', $output, $exitCode);
        self::assertSame(0, $exitCode, implode("\n", $output));

        $archive = new ZipArchive();
        self::assertTrue(
            $archive->open($this->projectRoot . '/build/SkyyAddressValidation-0.2.0.zip') === true,
        );
        $composerJson = $archive->getFromName('SkyyAddressValidation/composer.json');
        $archive->close();
        self::assertIsString($composerJson);

        $metadata = json_decode($composerJson, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('0.2.0', $metadata['version'] ?? null);
    }

    public function testGeneratedReleaseAndScratchPathsAreIgnored(): void
    {
        $gitignore = $this->read('.gitignore');
        foreach (['/build/', '/reports/', '/scratch/', '/.superpowers/'] as $path) {
            self::assertStringContainsString($path, $gitignore);
        }
    }

    private function read(string $path): string
    {
        $absolutePath = $this->projectRoot . '/' . $path;
        self::assertFileExists($absolutePath);
        $contents = file_get_contents($absolutePath);
        self::assertIsString($contents);

        return $contents;
    }
}
