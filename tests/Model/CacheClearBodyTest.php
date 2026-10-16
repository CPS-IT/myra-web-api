<?php

declare(strict_types=1);

namespace Myracloud\WebApi\Tests\Model;

use Myracloud\WebApi\Exception\InvalidArgumentException;
use Myracloud\WebApi\Model\CacheClearBody;
use Myracloud\WebApi\Model\DomainName;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CacheClearBody::class)]
#[CoversClass(DomainName::class)]
#[UsesClass(InvalidArgumentException::class)]
final class CacheClearBodyTest extends TestCase
{
    public function testBuildsASingleClear(): void
    {
        self::assertSame(
            ['fqdn' => 'www.example.com', 'resource' => '/*', 'recursive' => true],
            CacheClearBody::single(' WWW.Example.com. ', '/*', true),
        );
    }

    public function testBuildsABulkClear(): void
    {
        self::assertSame(
            ['fqdn' => 'www.example.com', 'bulk' => ['/*.css', '/*.js'], 'recursive' => false],
            CacheClearBody::bulk('www.example.com', ['/*.css', '/*.js'], false),
        );
    }

    #[TestWith([''])]
    #[TestWith(['*.css'])]
    #[TestWith(['folder/*'])]
    public function testAResourceIsAPathPattern(string $resource): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(sprintf('A resource is a path pattern starting with "/", "%s" given.', $resource));

        CacheClearBody::single('www.example.com', $resource, false);
    }

    public function testChecksEveryBulkResource(): void
    {
        $this->expectExceptionMessage('A resource is a path pattern starting with "/", "*.js" given.');

        CacheClearBody::bulk('www.example.com', ['/*.css', '*.js'], false);
    }

    /**
     * @return iterable<string, array{array<mixed>, string}>
     */
    public static function invalidBulks(): iterable
    {
        yield 'empty' => [[], 'A bulk cache clear needs a non-empty list of resources.'];
        yield 'not a list' => [['a' => '/*'], 'A bulk cache clear needs a non-empty list of resources.'];
        yield 'not a string' => [['/*', 3], 'Resources must be strings, int given.'];
    }

    /**
     * @param array<mixed> $resources
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidBulks')]
    public function testRejectsInvalidBulks(array $resources, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        CacheClearBody::bulk('www.example.com', $resources, false);
    }

    public function testNormalizesDomainNames(): void
    {
        self::assertSame('example.com', DomainName::normalize(" Example.COM.\n"));
        self::assertSame('example.com', DomainName::normalize('example.com..'));
    }

    #[TestWith([''])]
    #[TestWith([' . '])]
    public function testRejectsEmptyDomainNames(string $name): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The domain name must not be empty.');

        DomainName::normalize($name);
    }
}
