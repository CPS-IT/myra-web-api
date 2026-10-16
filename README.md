# cpsit/myra-web-api

PHP client for the Myra Cloud Web API, v1 and v2. PHP ^8.2.

```bash
composer require cpsit/myra-web-api:^2.0
```

## Connect

```php
use Myracloud\WebApi\Auth\BearerToken;
use Myracloud\WebApi\Auth\HmacSignature;
use Myracloud\WebApi\V1;
use Myracloud\WebApi\V2;

$hmac  = new HmacSignature('api-key', 'api-secret');
$token = new BearerToken('api-token');

$myra = new V2\Client($token);   // https://apiv2.myracloud.com
$myra = new V2\Client($hmac);
$myra = new V1\Client($hmac);    // https://api.myracloud.com/en/rapi
$myra = new V1\Client($token, language: 'de');
```

Both auth methods work with both versions. Domains are passed by name; v2 looks the id up itself.

```php
use Myracloud\WebApi\Contract\Client;

function purge(Client $myra): void     // V1\Client and V2\Client alike
{
    $myra->cacheClear()->clear('example.com', 'www.example.com', '/assets/*');
}
```

Own HTTP client (any PSR-18 + PSR-17), proxy or emulator:

```php
$http = new Symfony\Component\HttpClient\Psr18Client();    // PSR-18 and PSR-17 in one

$myra = new V2\Client($token, baseUri: 'http://127.0.0.1:8080', httpClient: $http, requestFactory: $http, streamFactory: $http);
```

Without an HTTP client, Guzzle is used (connect timeout 10 s, timeout 30 s).

## Errors

```php
use Myracloud\WebApi\Exception\AccessDeniedException;
use Myracloud\WebApi\Exception\DomainNotFoundException;
use Myracloud\WebApi\Exception\MyraException;
use Myracloud\WebApi\Exception\RequestRejectedException;
use Myracloud\WebApi\Exception\TransportException;

try {
    $myra->redirects()->update($redirect);
} catch (RequestRejectedException $e) {        // Myra said no: $e->violations()
    foreach ($e->violations() as $violation) {
        echo $violation->propertyPath(), ': ', $violation->message(), ' ', $violation->code(), "\n";
    }
} catch (AccessDeniedException $e) {           // credentials, permissions, unknown v1 domain
} catch (DomainNotFoundException $e) {         // v2: name not in the account
} catch (TransportException $e) {              // network, 5xx, unreadable answer
}

try {
    $myra->domains()->list();
} catch (MyraException $e) {                   // all of the above
}
```

## Cache

```php
$myra->cacheClear()->clear('example.com', 'www.example.com', '/');               // the start page only
$myra->cacheClear()->clear('example.com', 'www.example.com', '/*');              // the whole host
$myra->cacheClear()->clear('example.com', 'www.example.com', '/news/*');         // a folder
$myra->cacheClear()->clear('example.com', 'www.example.com', '/*.css', recursive: true);
$myra->cacheClear()->clearBulk('example.com', 'www.example.com', ['/a.html', '/b.html']);
```

`resource` is a pattern. `recursive` extends it to subdirectories; `/` with `recursive: true` does not clear everything.

```php
use Myracloud\WebApi\Model\CacheSetting;

$settings = $myra->cacheSettings();
$rule = $settings->create(new CacheSetting('www.example.com', '/api/', CacheSetting::TTL_NO_CACHE));
$settings->update($rule->withTtl(60)->withType(CacheSetting::TYPE_EXACT));
$settings->delete($rule);
```

## DNS

```php
use Myracloud\WebApi\Model\DnsRecord;

$dns = $myra->dnsRecords();

foreach ($dns->list('example.com') as $record) {
    printf("%-30s %-5s %s\n", $record->name(), $record->recordType(), $record->value());
}

$record = $dns->create('example.com', new DnsRecord('api.example.com', 'A', '192.0.2.10', ttl: 300, active: true));
$record = $dns->update('example.com', $record->withValue('192.0.2.11'));
$dns->delete('example.com', $record);
```

Load balancing: several A records of one name, each with `UpstreamOptions`.

```php
foreach ($dns->list('example.com') as $record) {
    if ($record->name() === 'www.example.com' && $record->value() === '192.0.2.20') {
        // take one upstream out of rotation
        $dns->update('example.com', $record->withUpstreamOptions($record->upstreamOptions()?->withDown(true)));
    }
}
```

Updates send the record as read, with the changes on top. `modified` is the lock: a stale object is rejected.

```php
use Myracloud\WebApi\Exception\RequestRejectedException;

function retry(callable $change, int $times = 3): mixed
{
    for ($try = 1; ; $try++) {
        try {
            return $change();
        } catch (RequestRejectedException $e) {
            if ($try === $times) {
                throw $e;
            }
        }
    }
}

// v2: get() by id
retry(fn() => $dns->update('example.com', $dns->get('example.com', 4711)->withTtl(60)));
```

Zone export:

```php
file_put_contents('example.com.zone', $myra->zones()->file('example.com'));

$zone = $myra->zones()->get('example.com');
echo $zone->serial(), ' ', count($zone->records()), "\n";

$myra->zones()->file('example.com', V2\Zones::TARGET_ORIGIN);    // v2: with the origin's addresses
```

## Redirects, IP filter, maintenance, error pages

Per vhost, by its full name; the zone apex is `example.com`.

```php
use Myracloud\WebApi\Model\IpFilter;
use Myracloud\WebApi\Model\Maintenance;
use Myracloud\WebApi\Model\Redirect;

$myra->redirects()->create(new Redirect('www.example.com', '/old', '/new', Redirect::TYPE_PERMANENT));
$myra->redirects()->create(new Redirect('www.example.com', '/blog/', 'https://blog.example.com/', matchingType: Redirect::MATCH_PREFIX));

$myra->ipFilters()->create(new IpFilter('www.example.com', IpFilter::TYPE_BLACKLIST, '198.51.100.7', comment: 'scanner'));
$myra->ipFilters()->create(new IpFilter('admin.example.com', IpFilter::TYPE_WHITELIST, '203.0.113.0/24'));

$myra->maintenances()->create(new Maintenance(
    'www.example.com',
    start: '2026-11-01T02:00:00+01:00',
    end: '2026-11-01T04:00:00+01:00',
    content: file_get_contents('maintenance.html'),
));

$myra->errorPages()->set('example.com', file_get_contents('5xx.html'), [
    'www.example.com' => [502, 503, 504],
    'example.com'     => [502, 503, 504],
]);
$myra->errorPages()->delete('example.com', ['www.example.com' => [503]]);
```

IP filter as CSV (v2):

```php
$csv = $myra->ipFilters()->export('www.example.com', IpFilter::TYPE_BLACKLIST);

$skipped = $myra->ipFilters()->import('www.example.com', IpFilter::TYPE_BLACKLIST, "198.51.100.8\n198.51.100.9\n");
foreach ($skipped as $violation) {      // lines Myra did not take
    echo $violation->message(), "\n";
}
```

## Subdomain settings

```php
$settings = $myra->subdomainSettings()->get('www.example.com');

$settings->value('cache_enabled');    // effective value: own, else inherited
$settings->own();                     // set on this vhost
$settings->inherited();               // from domain and defaults

// send all own settings, not only the change
$myra->subdomainSettings()->update('www.example.com', ['hsts' => true] + $settings->own());
```

## Myra's IP ranges

nginx allowlist / `real_ip`:

```php
$lines = array_map(
    fn($range) => 'set_real_ip_from ' . $range->network() . ';',
    $myra->ipRanges()->active(),
);
file_put_contents('/etc/nginx/conf.d/myra-ranges.conf', implode("\n", $lines) . "\n");
```

`active()` is the same in v1 and v2. `list()` returns what the version sends: v1 enabled ranges only, v2 all of them, including expired ones.

## Statistics

```php
use Myracloud\WebApi\Model\StatisticQuery;

$query = (new StatisticQuery(new DateTimeImmutable('-1 day'), new DateTimeImmutable(), ['ALL:example.com']))
    ->withAggregationInterval(StatisticQuery::INTERVAL_HOUR)
    ->withDataSource('requests', 'requests')
    ->withDataSource('cached', 'requests_cached')
    ->withDataSource('per_hour', 'requests', StatisticQuery::TYPE_HISTOGRAM)
    ->withDataSource('codes', 'response_codes');

$result = $myra->statistics()->query($query);    // v1 and v2

printf("cache hit ratio: %.1f %%\n", 100 * $result->stats('cached')->sum() / max(1, $result->stats('requests')->sum()));

foreach ($result->histogram('per_hour') as $ms => $requests) {
    printf("%s %s\n", date('H:i', intdiv($ms, 1000)), str_repeat('#', (int) ($requests / 1000)));
}

foreach ($result->statsByKey('codes') as $code => $values) {
    echo $code, ' ', $values->sum(), "\n";          // 200, 301, …, "Other"
}
```

v2 dashboard statistics:

```php
$stats = $myra->statistics();
$from = new DateTimeImmutable('-1 hour');
$to   = new DateTimeImmutable();

$stats->totalRequests($from, $to)->totals()['total_requests'];
$stats->httpStatusCodes($from, $to, ['example.com']);                // [200 => …, 404 => …]
foreach ($stats->topUrls($from, $to, ['example.com']) as $url) {
    echo $url->count(), ' ', $url->url(), "\n";
}
$stats->worldMap($from, $to, ['example.com'])[0]->countryCode();
$stats->histogram($from, $to, V2\Statistics::CHART_TOTAL_REQUESTS, V2\Statistics::INTERVAL_HOURLY, ['example.com'], sum: true);
```

Times in the answers are Unix milliseconds.

## SSL certificates

```php
use Myracloud\WebApi\Model\SslCertificateUpload;

foreach ($myra->sslCertificates()->list('example.com') as $certificate) {
    $days = (new DateTimeImmutable())->diff(new DateTimeImmutable($certificate->validTo() ?? 'now'))->days;
    printf("%-40s %3d days  %s\n", implode(',', $certificate->subdomains()), $days, $certificate->managed() ? 'managed' : 'uploaded');
}

$myra->sslCertificates()->upload('example.com', new SslCertificateUpload(
    certificate: file_get_contents('cert.pem'),
    key: file_get_contents('key.pem'),
    subdomains: ['www.example.com', 'example.com'],
    intermediates: [file_get_contents('chain.pem')],
));
```

## Paging

```php
$all  = $myra->domains()->list();       // every page
$page = $myra->domains()->page(2);      // one page
$page->items(); $page->total(); $page->hasNext();
```

## v2 only

```php
$myra->domains()->get(1000);
$myra->domainId('example.com');                                  // 1000
$myra->subdomains()->list();                                     // every vhost of the account
$myra->subdomains()->search('shop', exact: false);
$myra->domainVerifications()->status('example.com')->verified();
$myra->maintenances()->forDomain('example.com', presentAndFuture: true);
$myra->maintenanceTemplates()->list('example.com');
$myra->errorPages()->grouped('example.com');
$myra->waf()->conditions(); $myra->waf()->actions(); $myra->waf()->countries();
$myra->users()->me(); $myra->users()->list(); $myra->users()->permissions();
$myra->users()->activities($myra->users()->me()->id(), page: 1);
$myra->organization()->contacts();
```

```php
$token = $myra->users()->createToken('ci', expires: new DateTimeImmutable('+90 days'));
echo $token->value();                  // only now, never again
```

## v1 only

```php
$myra->domains()->pause($domain, until: new DateTimeImmutable('+1 hour'));
$myra->domains()->resume($domain);
```

## Coverage

116 of 218 operations are verified against the real API. 40 are built from the manual/spec and marked *unverified* in their docblocks. Not covered: tags, SSL providers/requests, WAF rules, waiting room, replication DNS, v1 rate limit, custom configurations. Details and reasons: [docs/api-surface.md](docs/api-surface.md).

## 1.x

The 1.x API (`WebApi`, `WebApiV2`, endpoints, signature middleware, CLI) is gone in 2.0. To keep it:

```bash
composer require cpsit/myra-web-api:^1.2
```

## Development

```bash
composer ci                                   # validate, lint, cs, phpstan, dependency analyser, tests
composer infection                            # mutation testing (needs a coverage driver)
php -S 127.0.0.1:8080 tests/Emulator/index.php    # local Myra API emulator, v1 + v2
```

Design and decisions: [docs/2.0-design.md](docs/2.0-design.md). Captured API answers: [docs/captures](docs/captures).
