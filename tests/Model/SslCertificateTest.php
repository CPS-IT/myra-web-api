<?php

declare(strict_types=1);

namespace Myracloud\WebApi\Tests\Model;

use Myracloud\WebApi\Http\JsonObject;
use Myracloud\WebApi\Model\SslCertificate;
use Myracloud\WebApi\Model\SslCertificateUpload;
use Myracloud\WebApi\Model\SslConfiguration;
use Myracloud\WebApi\Tests\Support\Fixture;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SslCertificate::class)]
#[CoversClass(SslCertificateUpload::class)]
#[CoversClass(SslConfiguration::class)]
#[UsesClass(JsonObject::class)]
final class SslCertificateTest extends TestCase
{
    public function testReadsTheCapturedCertificate(): void
    {
        $json = self::first('v2/certificates/get', 'data');

        $certificate = SslCertificate::fromJson(new JsonObject($json));

        self::assertSame(
            [90001, '2026-10-15T23:06:14+0200', ['example.com', 'www.example.com'], 'redacted-1', '2026-10-15T20:07:06+0200', '2027-01-13T20:07:05+0100', false, false, 'redacted-5'],
            [$certificate->id(), $certificate->modified(), $certificate->subdomains(), $certificate->subject(), $certificate->validFrom(), $certificate->validTo(), $certificate->wildcard(), $certificate->managed(), $certificate->sslConfigurationName()],
        );
        self::assertSame($json['fingerprint'], $certificate->fingerprint());
        self::assertSame($json['serialNumber'], $certificate->serialNumber());
        self::assertSame(['example.com', 'host1.live.host2.example.com', 'www.example.com'], $certificate->subjectAlternatives());
        self::assertSame($json, $certificate->raw());
    }

    public function testReadsAMinimalCertificate(): void
    {
        $certificate = SslCertificate::fromJson(new JsonObject(['id' => 1, 'modified' => 'm', 'wildcard' => true, 'managed' => true]));

        self::assertSame([[], '', null, null, [], true, true, null], [$certificate->subdomains(), $certificate->subject(), $certificate->fingerprint(), $certificate->validTo(), $certificate->subjectAlternatives(), $certificate->wildcard(), $certificate->managed(), $certificate->sslConfigurationName()]);
    }

    public function testAnUpdateSendsTheManualsFieldsOnly(): void
    {
        $certificate = SslCertificate::fromJson(new JsonObject(self::first('v2/certificates/get', 'data')));

        self::assertSame(['id' => 90001, 'modified' => '2026-10-15T23:06:14+0200', 'subdomains' => ['example.com', 'www.example.com']], $certificate->toJson());
        self::assertSame(
            ['id' => 90001, 'modified' => '2026-10-15T23:06:14+0200', 'subdomains' => ['www.example.com'], 'intermediates' => [['cert' => 'PEM-1'], ['cert' => 'PEM-2']]],
            $certificate->withSubdomains(['www.example.com'])->withIntermediates(['PEM-1', 'PEM-2'])->toJson(),
        );
        self::assertSame(['example.com', 'www.example.com'], $certificate->subdomains(), 'immutable');
        self::assertSame(['id' => 90001, 'modified' => '2026-10-15T23:06:14+0200', 'subdomains' => [], 'intermediates' => []], $certificate->withIntermediates([])->withSubdomains([])->toJson());
    }

    public function testAnUploadAsInTheManual(): void
    {
        $upload = new SslCertificateUpload('CERT-PEM', 'KEY-PEM', ['www.example.com'], ['CHAIN-PEM']);

        self::assertSame(
            ['objectType' => 'SslCertVO', 'cert' => 'CERT-PEM', 'key' => 'KEY-PEM', 'subdomains' => ['www.example.com'], 'intermediates' => [['cert' => 'CHAIN-PEM']]],
            $upload->toJson(),
        );
    }

    public function testARefreshWithPassword(): void
    {
        $upload = new SslCertificateUpload('CERT-PEM', 'ENCRYPTED-KEY', ['www.example.com'], password: 'pass', replaces: 90001, force: true);

        self::assertSame(
            ['objectType' => 'SslCertVO', 'cert' => 'CERT-PEM', 'key' => 'ENCRYPTED-KEY', 'subdomains' => ['www.example.com'], 'intermediates' => [], 'password' => 'pass', 'certToRefresh' => 90001, 'certRefreshForced' => true],
            $upload->toJson(),
        );
        self::assertFalse((new SslCertificateUpload('C', 'K', [], replaces: 1))->toJson()['certRefreshForced']);
    }

    public function testTheKeyAndPasswordDoNotShowInDumps(): void
    {
        $upload = new SslCertificateUpload('CERT-PEM', 'TOP-SECRET-KEY', [], password: 'TOP-SECRET-PASSWORD');

        ob_start();
        var_dump($upload);
        $dumps = (string) ob_get_clean() . print_r($upload, true) . var_export($upload, true);

        self::assertStringNotContainsString('TOP-SECRET', $dumps);
        self::assertStringContainsString('CERT-PEM', $dumps);
    }

    public function testReadsTheConfigurations(): void
    {
        $body = Fixture::load('v2/certificates/configurations')['response']['body'];
        self::assertIsArray($body);
        self::assertIsArray($body['data']);
        self::assertIsArray($body['data'][0]);
        self::assertIsArray($body['data'][1]);

        $first = SslConfiguration::fromJson(new JsonObject($body['data'][0]));
        $second = SslConfiguration::fromJson(new JsonObject($body['data'][1]));

        self::assertSame(['redacted-5', null, null, null], [$first->name(), $first->protocols(), $first->ciphers(), $first->tls13Ciphers()]);
        self::assertSame(['redacted-10', 'redacted-13', 'redacted-11', 'redacted-12'], [$second->name(), $second->protocols(), $second->ciphers(), $second->tls13Ciphers()]);
        self::assertSame($body['data'][1], $second->raw());
    }

    /**
     * @return array<mixed>
     */
    private static function first(string $fixture, string $key): array
    {
        $body = Fixture::load($fixture)['response']['body'];
        self::assertIsArray($body);
        self::assertIsArray($body[$key]);
        self::assertIsArray($body[$key][0]);

        return $body[$key][0];
    }
}
