<?php

declare(strict_types=1);

namespace Myracloud\WebApi\Tests\Smoke;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Anonymizer::class)]
#[CoversClass(Vocabulary::class)]
final class AnonymizerTest extends TestCase
{
    private Anonymizer $anonymizer;

    protected function setUp(): void
    {
        $this->anonymizer = new Anonymizer(new Vocabulary(['objectType RedirectVO type permanent redirect source WHITELIST www com net api ax4z.com 2026 /domain/redirects /{language}/rapi/domains /en/rapi A AAAA NOT REGEX']));
        $this->anonymizer->zone('real-zone.de', 'example.com');
    }

    public function testKeepsOnlyDocumentedWordsAndDates(): void
    {
        $result = $this->anonymizer->value([
            'objectType' => 'RedirectVO',
            'type' => 'permanent',
            'created' => '2026-10-16T12:00:00+0200',
            'comment' => 'internal note of the customer',
            'enabled' => true,
            'ttl' => 300,
        ]);

        self::assertSame([
            'objectType' => 'RedirectVO',
            'type' => 'permanent',
            'created' => '2026-10-16T12:00:00+0200',
            'comment' => 'redacted-1',
            'enabled' => true,
            'ttl' => 300,
        ], $result);
    }

    public function testReplacesHostsIpsMailsUrlsPathsAndIds(): void
    {
        $result = $this->anonymizer->value([
            'id' => 4711,
            'domainId' => 4242,
            'name' => 'www.real-zone.de',
            'other' => 'secret-staging.real-zone.de.',
            'cname' => 'real-zone-de.ax4z.com.',
            'ip' => '100.64.0.77',
            'net' => '10.20.0.0/16',
            'ip6' => '2a00:1234::1',
            'mail' => 'jane.doe@customer.de',
            'url' => 'https://www.real-zone.de/secret/page',
            'source' => '/secret/page',
        ]);
        self::assertIsArray($result);

        self::assertSame(90001, $result['id']);
        self::assertSame(90002, $result['domainId']);
        self::assertSame('www.example.com', $result['name']);
        self::assertSame('host1.example.com.', $result['other']);
        self::assertSame('host2.ax4z.com.', $result['cname']);
        self::assertSame('192.0.2.101', $result['ip']);
        self::assertSame('192.0.2.102/16', $result['net']);
        self::assertSame('2001:db8::101', $result['ip6']);
        self::assertSame('user1@example.com', $result['mail']);
        self::assertSame('https://www.example.com/path-1', $result['url']);
        self::assertSame('/path-2', $result['source']);
    }

    public function testIsDeterministicAndReusesSeededValues(): void
    {
        $this->anonymizer->seed(['id' => 4242, 'name' => 'map.real-zone.de'], ['id' => 1000, 'name' => 'map.example.com']);

        self::assertSame(['domainId' => 1000, 'name' => 'map.example.com'], $this->anonymizer->value(['domainId' => 4242, 'name' => 'map.real-zone.de']));
        self::assertSame($this->anonymizer->value('free text'), $this->anonymizer->value('free text'));
        self::assertSame('/domain/1000/redirects/www.example.com?page=2', $this->anonymizer->path('/domain/4242/redirects/www.real-zone.de?page=2'));
        self::assertSame('/en/rapi/domains/1', $this->anonymizer->path('/en/rapi/domains/1'));
        $this->anonymizer->seed(['id' => 1], ['id' => 5]);
        self::assertSame('/en/rapi/domains/1?recordTypes=A,AAAA', $this->anonymizer->path('/en/rapi/domains/1?recordTypes=A,AAAA'));
    }

    public function testKeepsUpperCasePhrasesOfDocumentedWordsButNotProse(): void
    {
        self::assertSame(['NOT REGEX', 'redacted-1'], $this->anonymizer->value(['NOT REGEX', 'redirect source']));
    }

    public function testTellsWhereALeakSurvived(): void
    {
        $this->anonymizer->value('internal note');
        $result = ['data' => [[], ['comment' => 'see internal note']]];

        self::assertSame(['replaced value #2 (from "") still present at data[1].comment: "see «LEAK»"'], $this->anonymizer->leaks((string) json_encode($result), $result));
    }

    public function testKeepsGenericAndProbeValues(): void
    {
        self::assertSame(['/', 'x', '/__probe__/redirect', 'https://www.example.com/__probe__/target', '__probe__ updated', '192.0.2.1/32', '2001:db8::1'], $this->anonymizer->value(['/', 'x', '/__probe__/redirect', 'https://www.real-zone.de/__probe__/target', '__probe__ updated', '192.0.2.1/32', '2001:db8::1']));
    }

    public function testFreeTextKeepsItsStructureAndLosesIds(): void
    {
        $this->anonymizer->seed(['id' => 4242], ['id' => 1000]);
        $this->anonymizer->value(['source' => '/', 'id' => 777777]);

        self::assertSame(
            'Myra rejected PUT https://api.example.com/domain/1000/redirects/www.example.com/90001 (HTTP 400): edited.',
            $this->anonymizer->text('Myra rejected PUT https://api.example.com/domain/4242/redirects/www.real-zone.de/777777 (HTTP 400): edited.'),
        );
    }

    public function testAnonymisesKeysThatAreData(): void
    {
        self::assertSame(['www.example.com' => ['type' => 'permanent']], $this->anonymizer->value(['www.real-zone.de' => ['type' => 'permanent']]));
    }

    public function testAnonymisesFreeText(): void
    {
        $text = $this->anonymizer->text("IP,TYPE\n100.64.0.9/32,WHITELIST\nwww.real-zone.de. 300 IN A 10.1.2.3\nadmin@customer.de\n");

        self::assertSame("IP,TYPE\n192.0.2.101/32,WHITELIST\nwww.example.com. 300 IN A 192.0.2.102\nuser1@example.com\n", $text);
    }

    public function testReportsLeaksWithoutValues(): void
    {
        $this->anonymizer->value(['comment' => 'internal note of the customer']);

        $leaks = $this->anonymizer->leaks('{"a":"internal note of the customer","b":"x@customer.de","c":"10.0.0.1","d":"2a00:1234:5678::1","e":"-----BEGIN PRIVATE KEY-----","f":"' . str_repeat('QUJD', 12) . '"}');

        self::assertSame([
            'replaced value #2 (from "comment") still present',
            'e-mail address',
            'PEM block',
            'long base64',
            'IPv4 outside documentation ranges',
            'IPv6 outside 2001:db8::/32',
        ], $leaks);
        foreach ($leaks as $leak) {
            self::assertStringNotContainsString('internal note', $leak);
        }
    }

    public function testFindsNothingInACleanResult(): void
    {
        $result = (string) json_encode($this->anonymizer->value(['name' => 'www.real-zone.de', 'ip' => '100.64.0.77', 'ip6' => '2a00:1234::1', 'created' => '2026-10-16T12:00:00+02:00', 'mail' => 'a@b.de']));

        self::assertSame([], $this->anonymizer->leaks($result));
    }
}
