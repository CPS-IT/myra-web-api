<?php

declare(strict_types=1);

namespace Myracloud\WebApi\V2;

use Myracloud\WebApi\Exception\InvalidArgumentException;
use Myracloud\WebApi\Exception\MyraException;
use Myracloud\WebApi\Http\Transport;
use Myracloud\WebApi\Model\ContactType;
use Myracloud\WebApi\Model\OrganizationContact;
use Myracloud\WebApi\Model\Page;
use Myracloud\WebApi\Model\Persisted;

/**
 * The organisation's contacts and notes (v2 only): `/organization/…`. Reads captured; the
 * writes are *unverified* (from the spec, never probed).
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

    /**
     * Adds a contact person who is no Myra App user.
     *
     * @param string|null $phone as written (see {@see OrganizationContact::withPhone()})
     *
     * @throws MyraException
     */
    public function addContact(string $name, string $email, ?string $phone = null, bool $sslReminders = false, bool $trafficAlerts = false): OrganizationContact
    {
        $body = ['name' => $name, 'email' => $email] + ($phone === null ? [] : ['phone' => $phone]) + ['receiveSSLReminders' => $sslReminders, 'receiveTrafficAlerts' => $trafficAlerts];

        return OrganizationContact::fromJson($this->transport->send('POST', 'organization/contacts', body: $body)->first('data'));
    }

    /**
     * Adds a Myra App user as contact person.
     *
     * @throws MyraException
     */
    public function addUserAsContact(int $userId, bool $sslReminders = false, bool $trafficAlerts = false): OrganizationContact
    {
        $body = ['userId' => $userId, 'receiveSSLReminders' => $sslReminders, 'receiveTrafficAlerts' => $trafficAlerts];

        return OrganizationContact::fromJson($this->transport->send('POST', 'organization/contacts', body: $body)->first('data'));
    }

    /**
     * @throws InvalidArgumentException if modified is missing
     * @throws MyraException
     */
    public function updateContact(OrganizationContact $contact): OrganizationContact
    {
        Persisted::versionOf($contact->id(), $contact->modified(), 'contact');

        return OrganizationContact::fromJson($this->transport->send('PUT', 'organization/contacts/' . $contact->id(), body: $contact->toJson())->first('data'));
    }

    /**
     * @throws MyraException
     */
    public function deleteContact(OrganizationContact $contact): void
    {
        $this->transport->send('DELETE', 'organization/contacts/' . $contact->id());
    }

    /**
     * Replaces the organisation's note (plain text; null clears it).
     *
     * @param string|null $modified the `modified` the note was read with — the spec asks for
     *                              it, the captured note had none (none set yet)
     *
     * @return array<mixed> the note as Myra answers it
     *
     * @throws MyraException
     */
    public function updateNotes(?string $notes, ?string $modified = null): array
    {
        $body = ['notes' => $notes] + ($modified === null ? [] : ['modified' => $modified]);

        return $this->transport->send('PUT', 'organization/notes', body: $body)->first('data')->toArray();
    }
}
