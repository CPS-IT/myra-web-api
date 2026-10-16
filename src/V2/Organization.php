<?php

declare(strict_types=1);

namespace Myracloud\WebApi\V2;

use Myracloud\WebApi\Exception\MyraException;
use Myracloud\WebApi\Http\Transport;
use Myracloud\WebApi\Model\ContactType;
use Myracloud\WebApi\Model\OrganizationContact;
use Myracloud\WebApi\Model\Page;

/**
 * The organisation's contacts and notes (v2 only): `/organization/…`.
 */
final readonly class Organization
{
    /**
     * @internal built by {@see Client}
     */
    public function __construct(private Transport $transport) {}

    /**
     * @return list<OrganizationContact>
     *
     * @throws MyraException
     */
    public function contacts(): array
    {
        return Page::collect(fn(int $page): Page => Page::fromJson($this->transport->send('GET', 'organization/contacts', Domains::query($page)), 'data', OrganizationContact::fromJson(...)));
    }

    /**
     * @return list<ContactType>
     *
     * @throws MyraException
     */
    public function contactTypes(): array
    {
        return Page::collect(fn(int $page): Page => Page::fromJson($this->transport->send('GET', 'organization/contact-types', Domains::query($page)), 'data', ContactType::fromJson(...)));
    }

    /**
     * The organisation's notes as Myra sends them (`OrganizationNoteVO`; captured without any
     * field set, so not modelled).
     *
     * @return array<mixed>
     *
     * @throws MyraException
     */
    public function notes(): array
    {
        return $this->transport->send('GET', 'organization/notes')->first('data')->toArray();
    }
}
