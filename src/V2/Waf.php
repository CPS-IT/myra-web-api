<?php

declare(strict_types=1);

namespace Myracloud\WebApi\V2;

use Closure;
use Myracloud\WebApi\Exception\MyraException;
use Myracloud\WebApi\Http\Transport;
use Myracloud\WebApi\Model\Country;
use Myracloud\WebApi\Model\Page;
use Myracloud\WebApi\Model\WafAction;
use Myracloud\WebApi\Model\WafCondition;

/**
 * The WAF catalogue (v2 only, the same for every account): what rules can match on and do —
 * `GET /waf/conditions[/matching-types]`, `/waf/actions[/values]`, `/waf/countries`.
 */
final readonly class Waf
{
    /**
     * @internal built by {@see Client}
     */
    public function __construct(private Transport $transport) {}

    /**
     * @return list<WafCondition>
     *
     * @throws MyraException
     */
    public function conditions(): array
    {
        return $this->collect('waf/conditions', WafCondition::fromJson(...));
    }

    /**
     * The matching types by condition category, e.g. `['default' => ['REGEX' => 'REGEX', …],
     * 'country' => ['EQUALS' => …]]` (type → label).
     *
     * @return array<int|string, mixed>
     *
     * @throws MyraException
     */
    public function matchingTypes(): array
    {
        return $this->transport->send('GET', 'waf/conditions/matching-types')->object('data')->toArray();
    }

    /**
     * @return list<WafAction>
     *
     * @throws MyraException
     */
    public function actions(): array
    {
        return $this->collect('waf/actions', WafAction::fromJson(...));
    }

    /**
     * The values actions take: `customKey` (action type → value → label) and `requiresValue`
     * (action type → value → whether a custom value is needed), as Myra sends them.
     *
     * @return array<int|string, mixed>
     *
     * @throws MyraException
     */
    public function actionValues(): array
    {
        return $this->transport->send('GET', 'waf/actions/values')->object('data')->toArray();
    }

    /**
     * All countries — in one answer, although its pageSize says 50 (captured).
     *
     * @return list<Country>
     *
     * @throws MyraException
     */
    public function countries(): array
    {
        return $this->collect('waf/countries', Country::fromJson(...));
    }

    /**
     * @template T
     *
     * @param Closure(\Myracloud\WebApi\Http\JsonObject): T $item
     *
     * @return list<T>
     */
    private function collect(string $path, Closure $item): array
    {
        return Page::collect(fn(int $page): Page => Page::fromJson($this->transport->send('GET', $path, Domains::query($page)), 'data', $item));
    }
}
