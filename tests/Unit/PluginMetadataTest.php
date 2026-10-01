<?php

declare(strict_types=1);

namespace Skyyware\SkyyAddressValidation\Tests\Unit;

use DOMDocument;
use DOMElement;
use DOMXPath;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Plugin;
use Shopware\Core\System\SystemConfig\Util\ConfigReader;
use Skyyware\SkyyAddressValidation\Provider\AddressProviderInterface;
use Skyyware\SkyyAddressValidation\Provider\CachedAddressProvider;
use Skyyware\SkyyAddressValidation\SkyyAddressValidation;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\XmlFileLoader;

final class PluginMetadataTest extends TestCase
{
    private const ROOT = __DIR__ . '/../..';

    private const ICON_SHA256 = '0a0333c87f98bf5ff77b2c05c69639362089569d1883aa7135da6eec2ff8d7e9';

    /**
     * @var array<string, array{type: string, default: string, en: string, de: string}>
     */
    private const CONFIG_FIELDS = [
        'validateNames' => [
            'type' => 'bool',
            'default' => 'true',
            'en' => 'Reject digits in names',
            'de' => 'Ziffern in Namen ablehnen',
        ],
        'validatePhoneNumbers' => [
            'type' => 'bool',
            'default' => 'true',
            'en' => 'Validate telephone characters',
            'de' => 'Telefonzeichen prüfen',
        ],
        'validatePostalCodes' => [
            'type' => 'bool',
            'default' => 'true',
            'en' => 'Validate postal code characters',
            'de' => 'Postleitzahlenzeichen prüfen',
        ],
        'providerEnabled' => [
            'type' => 'bool',
            'default' => 'false',
            'en' => 'Enable remote address verification',
            'de' => 'Externe Adressprüfung aktivieren',
        ],
        'providerEndpoint' => [
            'type' => 'url',
            'default' => '',
            'en' => 'Provider endpoint (HTTPS)',
            'de' => 'Provider-Endpunkt (HTTPS)',
        ],
        'providerUserAgent' => [
            'type' => 'text',
            'default' => '',
            'en' => 'Provider user agent',
            'de' => 'Provider-User-Agent',
        ],
        'providerContactEmail' => [
            'type' => 'text',
            'default' => '',
            'en' => 'Merchant contact email',
            'de' => 'Kontakt-E-Mail des Händlers',
        ],
        'normalizeAddresses' => [
            'type' => 'bool',
            'default' => 'false',
            'en' => 'Apply provider normalization',
            'de' => 'Provider-Normalisierung anwenden',
        ],
    ];

    public function testComposerMetadataDefinesThePublicPluginContract(): void
    {
        $composer = $this->decodeJsonFile(self::ROOT . '/composer.json');

        self::assertSame('skyyware/address-validation', $composer['name'] ?? null);
        self::assertSame('shopware-platform-plugin', $composer['type'] ?? null);
        self::assertSame('MIT', $composer['license'] ?? null);
        self::assertSame('https://www.skyyware.com/', $composer['homepage'] ?? null);
        self::assertArrayNotHasKey('version', $composer);

        self::assertSame('>=8.2', $composer['require']['php'] ?? null);
        self::assertSame('^3.0', $composer['require']['psr/cache'] ?? null);
        self::assertSame('^3.0', $composer['require']['psr/log'] ?? null);
        self::assertSame('~6.7.0', $composer['require']['shopware/core'] ?? null);
        self::assertSame('~6.7.0', $composer['require']['shopware/storefront'] ?? null);
        self::assertSame('^7.2', $composer['require']['symfony/http-client'] ?? null);
        self::assertSame('^7.2', $composer['require']['symfony/lock'] ?? null);
        self::assertSame(
            'src/',
            $composer['autoload']['psr-4']['Skyyware\\SkyyAddressValidation\\'] ?? null,
        );

        $extra = $composer['extra'] ?? [];
        self::assertIsArray($extra);
        self::assertSame(
            SkyyAddressValidation::class,
            $extra['shopware-plugin-class'] ?? null,
        );
        self::assertSame(
            [
                'de-DE' => 'Skyy Adressvalidierung',
                'en-GB' => 'Skyy Address Validation',
            ],
            $extra['label'] ?? null,
        );
        self::assertSame(
            [
                'de-DE' => 'Serverseitige internationale Eingabe- und optionale Adressprüfung.',
                'en-GB' => 'Server-side international input checks and optional address verification.',
            ],
            $extra['description'] ?? null,
        );

        $manufacturerLinks = [
            'de-DE' => 'https://www.skyyware.com/de',
            'en-GB' => 'https://www.skyyware.com/',
        ];
        self::assertSame($manufacturerLinks, $extra['manufacturerLink'] ?? null);
        self::assertSame(
            [
                'de-DE' => 'https://www.skyyware.com/contact/',
                'en-GB' => 'https://www.skyyware.com/contact/',
            ],
            $extra['supportLink'] ?? null,
        );
    }

    public function testPluginClassIsAutoloadable(): void
    {
        self::assertFileExists(self::ROOT . '/src/SkyyAddressValidation.php');

        $composer = $this->decodeJsonFile(self::ROOT . '/composer.json');
        $pluginClass = $composer['extra']['shopware-plugin-class'] ?? null;
        self::assertIsString($pluginClass);
        self::assertTrue(class_exists($pluginClass));
        self::assertTrue(is_subclass_of($pluginClass, Plugin::class));
    }

    public function testReadmeContainsNoInternalProvenanceLanguage(): void
    {
        $readme = file_get_contents(self::ROOT . '/README.md');
        self::assertIsString($readme);
        self::assertDoesNotMatchRegularExpression('/\b(?:clean[- ]room|provenance)\b/i', $readme);
    }

    public function testRepositoryContainsTheMitLicense(): void
    {
        $licensePath = self::ROOT . '/LICENSE';
        self::assertFileExists($licensePath);

        $license = file_get_contents($licensePath);
        self::assertIsString($license);
        self::assertStringStartsWith('MIT License', $license);
        self::assertStringContainsString('Permission is hereby granted, free of charge', $license);
    }

    public function testPluginIconMatchesThePublicSkyywareAsset(): void
    {
        $iconPath = self::ROOT . '/src/Resources/config/plugin.png';
        self::assertFileExists($iconPath);
        self::assertSame(self::ICON_SHA256, hash_file('sha256', $iconPath));

        $image = getimagesize($iconPath);
        self::assertIsArray($image);
        self::assertSame(512, $image[0]);
        self::assertSame(512, $image[1]);
        self::assertSame('image/png', $image['mime']);
    }

    public function testShopwareConfigReaderValidatesAndParsesGlobalConfiguration(): void
    {
        $configPath = self::ROOT . '/src/Resources/config/config.xml';
        self::assertFileExists($configPath);

        $config = (new ConfigReader())->read($configPath);

        self::assertCount(2, $config);
    }

    public function testSymfonyContainerLoadsAndCompilesServiceConfiguration(): void
    {
        $configDirectory = self::ROOT . '/src/Resources/config';
        self::assertFileExists($configDirectory . '/services.xml');

        $container = new ContainerBuilder();
        foreach ([
            'Shopware\\Core\\System\\SystemConfig\\SystemConfigService',
            'cache.object',
            'country.repository',
            'http_client',
            'lock.factory',
            'logger',
        ] as $serviceId) {
            $container->register($serviceId)->setSynthetic(true)->setPublic(true);
        }
        $loader = new XmlFileLoader($container, new FileLocator($configDirectory));
        $loader->load('services.xml');
        self::assertTrue($container->hasAlias(AddressProviderInterface::class));
        self::assertSame(
            CachedAddressProvider::class,
            (string) $container->getAlias(AddressProviderInterface::class),
        );
        $container->compile();

        self::assertTrue($container->isCompiled());
    }

    public function testStorefrontSnippetsAreValidAndComplete(): void
    {
        $expectedKeys = [
            'VIOLATION::SKYY_NAME_DIGITS',
            'VIOLATION::SKYY_PHONE_CHARACTERS',
            'VIOLATION::SKYY_POSTAL_CHARACTERS',
            'VIOLATION::SKYY_ADDRESS_NOT_VERIFIED',
            'VIOLATION::SKYY_ADDRESS_NOT_VERIFIED_SUGGESTION',
        ];

        foreach ([
            self::ROOT . '/src/Resources/snippet/de_DE/storefront.de-DE.json',
            self::ROOT . '/src/Resources/snippet/en_GB/storefront.en-GB.json',
        ] as $snippetPath) {
            $snippets = $this->decodeJsonFile($snippetPath);
            self::assertSame(['error'], array_keys($snippets));
            self::assertIsArray($snippets['error']);
            self::assertSame($expectedKeys, array_keys($snippets['error']));
            foreach ($snippets['error'] as $message) {
                self::assertIsString($message);
                self::assertNotSame('', trim($message));
            }
        }
    }

    public function testGlobalConfigurationIsTranslatedAndPrivacySafeByDefault(): void
    {
        $document = $this->loadXmlFile(self::ROOT . '/src/Resources/config/config.xml');
        $xpath = new DOMXPath($document);

        $cards = $xpath->query('/config/card');
        self::assertNotFalse($cards);
        self::assertCount(2, $cards);

        foreach ($cards as $card) {
            self::assertInstanceOf(DOMElement::class, $card);
            self::assertNotSame('', $this->elementText($xpath, './title[not(@lang) or @lang="en-GB"]', $card));
            self::assertNotSame('', $this->elementText($xpath, './title[@lang="de-DE"]', $card));
        }

        $fields = $xpath->query('/config/card/input-field');
        self::assertNotFalse($fields);
        self::assertCount(\count(self::CONFIG_FIELDS), $fields);

        $actualNames = [];

        foreach ($fields as $field) {
            self::assertInstanceOf(DOMElement::class, $field);
            $name = $this->elementText($xpath, './name', $field);
            $actualNames[] = $name;

            self::assertArrayHasKey($name, self::CONFIG_FIELDS);
            $expected = self::CONFIG_FIELDS[$name];

            self::assertSame($expected['type'], $field->getAttribute('type'), $name);
            self::assertSame($expected['default'], $this->elementText($xpath, './defaultValue', $field), $name);
            self::assertSame(
                $expected['en'],
                $this->elementText($xpath, './label[not(@lang) or @lang="en-GB"]', $field),
                $name,
            );
            self::assertSame(
                $expected['de'],
                $this->elementText($xpath, './label[@lang="de-DE"]', $field),
                $name,
            );
        }

        self::assertSame(array_keys(self::CONFIG_FIELDS), $actualNames);
        self::assertSame('false', self::CONFIG_FIELDS['providerEnabled']['default']);
        self::assertSame('', self::CONFIG_FIELDS['providerEndpoint']['default']);
        self::assertSame('false', self::CONFIG_FIELDS['normalizeAddresses']['default']);
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJsonFile(string $path): array
    {
        self::assertFileExists($path);

        $contents = file_get_contents($path);
        self::assertIsString($contents);

        $decoded = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    private function loadXmlFile(string $path): DOMDocument
    {
        self::assertFileExists($path);

        $document = new DOMDocument();
        self::assertTrue($document->load($path));

        return $document;
    }

    private function elementText(DOMXPath $xpath, string $query, DOMElement $context): string
    {
        $nodes = $xpath->query($query, $context);
        self::assertNotFalse($nodes);
        self::assertCount(1, $nodes);

        $element = $nodes->item(0);
        self::assertInstanceOf(DOMElement::class, $element);

        return trim($element->textContent);
    }
}
