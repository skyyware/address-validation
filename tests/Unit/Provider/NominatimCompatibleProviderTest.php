<?php

declare(strict_types=1);

namespace Skyyware\SkyyAddressValidation\Tests\Unit\Provider;

use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Skyyware\SkyyAddressValidation\Config\AddressValidationConfig;
use Skyyware\SkyyAddressValidation\Provider\Address;
use Skyyware\SkyyAddressValidation\Provider\NominatimCompatibleProvider;
use Skyyware\SkyyAddressValidation\Provider\ProviderResult;
use Stringable;
use Symfony\Component\HttpClient\Exception\JsonException;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class NominatimCompatibleProviderTest extends TestCase
{
    private const ENDPOINT = 'https://geo.example.test/search';

    private const USER_AGENT = 'StagewareAddress/1.0';

    private const CONTACT = 'maps@example.test';

    public function testDisabledProviderShortCircuitsWithoutHttp(): void
    {
        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->expects(self::never())->method('request');

        $result = $this->provider($httpClient, $this->config(false))->verify($this->address());

        self::assertTrue($result->isUnavailable());
    }

    public function testSendsStructuredBoundedRequestAndVerifiesMatchingCandidate(): void
    {
        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->expects(self::once())
            ->method('request')
            ->with(
                'GET',
                self::ENDPOINT,
                self::callback(function (array $options): bool {
                    self::assertSame([
                        'street' => 'Königstraße 10',
                        'city' => 'Stuttgart',
                        'postalcode' => '70173',
                        'countrycodes' => 'de',
                        'format' => 'jsonv2',
                        'addressdetails' => 1,
                        'limit' => 5,
                    ], $options['query']);
                    self::assertSame([
                        'Accept' => 'application/json',
                        'User-Agent' => self::USER_AGENT,
                        'From' => self::CONTACT,
                    ], $options['headers']);
                    self::assertSame(2.0, $options['timeout']);
                    self::assertSame(2.0, $options['max_duration']);
                    self::assertSame(0, $options['max_redirects']);

                    return true;
                }),
            )
            ->willReturn($this->response([$this->candidate()]));

        $result = $this->provider($httpClient)->verify($this->address());

        self::assertTrue($result->isVerified());
        self::assertEquals($this->address(), $result->normalizedAddress());
    }

    public function testReturnsFirstValidCandidateAsSuggestionWhenNothingMatches(): void
    {
        $httpClient = $this->clientReturning([
            $this->candidate(road: 'Rotebühlstraße', houseNumber: '1'),
            $this->candidate(road: 'Theodor-Heuss-Straße', houseNumber: '2'),
        ]);

        $result = $this->provider($httpClient)->verify($this->address());

        self::assertTrue($result->isRejected());
        self::assertEquals(
            new Address('Rotebühlstraße 1', 'Stuttgart', '70173', 'DE'),
            $result->suggestion(),
        );
    }

    public function testEmptyValidResponseRejectsWithoutSuggestion(): void
    {
        $result = $this->provider($this->clientReturning([]))->verify($this->address());

        self::assertTrue($result->isRejected());
        self::assertNull($result->suggestion());
    }

    public function testOnlyFirstFiveCandidatesAreConsidered(): void
    {
        $candidates = array_fill(0, 5, $this->candidate(road: 'Wrong Road', houseNumber: '1'));
        $candidates[] = $this->candidate();

        $result = $this->provider($this->clientReturning($candidates))->verify($this->address());

        self::assertTrue($result->isRejected());
        self::assertSame('Wrong Road 1', $result->suggestion()?->street);
    }

    public function testMalformedPayloadReturnsUnavailable(): void
    {
        $httpClient = $this->createMock(HttpClientInterface::class);
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $response->method('toArray')->willThrowException(new JsonException('Invalid JSON.'));
        $httpClient->method('request')->willReturn($response);

        self::assertTrue($this->provider($httpClient)->verify($this->address())->isUnavailable());

        $httpClient = $this->clientReturning([['display_name' => 'missing address details']]);
        self::assertTrue($this->provider($httpClient)->verify($this->address())->isUnavailable());
    }

    public function testTransportAndHttpFailuresAreUnavailableAndLogsContainNoPii(): void
    {
        $logger = new RecordingLogger();
        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->method('request')->willThrowException(new TransportException('timed out'));

        $result = $this->provider($httpClient, logger: $logger)->verify($this->address());

        self::assertTrue($result->isUnavailable());
        $encodedLogs = json_encode($logger->records, JSON_THROW_ON_ERROR);
        foreach (['Königstraße', 'Stuttgart', '70173', self::ENDPOINT, self::CONTACT] as $secret) {
            self::assertStringNotContainsString($secret, $encodedLogs);
        }

        $logger = new RecordingLogger();
        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->method('request')->willReturn($this->response([], 503));

        self::assertTrue($this->provider($httpClient, logger: $logger)->verify($this->address())->isUnavailable());
        self::assertSame(['status_code' => 503], $logger->records[0]['context']);
    }

    public function testInvalidEnabledConfigurationDoesNotCallHttpOrExposeValues(): void
    {
        $logger = new RecordingLogger();
        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->expects(self::never())->method('request');
        $config = $this->config(true, endpoint: 'http://unsafe.example.test');

        self::assertTrue($this->provider($httpClient, $config, $logger)->verify($this->address())->isUnavailable());
        self::assertStringNotContainsString(
            'unsafe.example.test',
            json_encode($logger->records, JSON_THROW_ON_ERROR),
        );
    }

    public function testResultFactoriesHaveUnambiguousStates(): void
    {
        self::assertTrue(ProviderResult::verified(null)->isVerified());
        self::assertTrue(ProviderResult::rejected(null)->isRejected());
        self::assertTrue(ProviderResult::unavailable()->isUnavailable());
    }

    private function provider(
        HttpClientInterface $httpClient,
        ?AddressValidationConfig $config = null,
        ?RecordingLogger $logger = null,
    ): NominatimCompatibleProvider {
        return new NominatimCompatibleProvider(
            $httpClient,
            $config ?? $this->config(true),
            $logger ?? new RecordingLogger(),
            2.0,
        );
    }

    private function config(
        bool $enabled,
        string $endpoint = self::ENDPOINT,
        string $userAgent = self::USER_AGENT,
        string $contact = self::CONTACT,
    ): AddressValidationConfig {
        $values = [
            'providerEnabled' => $enabled,
            'providerEndpoint' => $endpoint,
            'providerUserAgent' => $userAgent,
            'providerContactEmail' => $contact,
        ];
        $systemConfig = $this->createMock(SystemConfigService::class);
        $systemConfig->method('getBool')
            ->willReturnCallback(
                static fn (string $key): bool => (bool) ($values[self::shortKey($key)] ?? false),
            );
        $systemConfig->method('getString')
            ->willReturnCallback(
                static fn (string $key): string => (string) ($values[self::shortKey($key)] ?? ''),
            );

        return new AddressValidationConfig($systemConfig);
    }

    /**
     * @param list<array<string, mixed>> $payload
     */
    private function clientReturning(array $payload): HttpClientInterface
    {
        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->method('request')->willReturn($this->response($payload));

        return $httpClient;
    }

    /**
     * @param list<array<string, mixed>> $payload
     */
    private function response(array $payload, int $statusCode = 200): ResponseInterface
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn($statusCode);
        $response->method('toArray')->with(false)->willReturn($payload);

        return $response;
    }

    /**
     * @return array{address: array{road: string, house_number: string, city: string, postcode: string, country_code: string}}
     */
    private function candidate(string $road = 'Königstraße', string $houseNumber = '10'): array
    {
        return [
            'address' => [
                'road' => $road,
                'house_number' => $houseNumber,
                'city' => 'Stuttgart',
                'postcode' => '70173',
                'country_code' => 'de',
            ],
        ];
    }

    private function address(): Address
    {
        return new Address('Königstraße 10', 'Stuttgart', '70173', 'DE');
    }

    private static function shortKey(string $key): string
    {
        return str_replace('SkyyAddressValidation.config.', '', $key);
    }
}

final class RecordingLogger extends AbstractLogger
{
    /**
     * @var list<array{level: mixed, message: string, context: array<string, mixed>}>
     */
    public array $records = [];

    /**
     * @param array<string, mixed> $context
     */
    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = [
            'level' => $level,
            'message' => (string) $message,
            'context' => $context,
        ];
    }
}
