# API surface: v1 and v2

Every operation of both versions, by area, with its status. Source: the v1 manual
(`docs/v1`, chapters 5–19) and the v2 spec (`docs/v2/openapi.json`, 175 operations).
Generated once, maintained by hand. Status per operation:

- **capture**: `–` none, `R` read captured with data, `W` write captured (`tests/Smoke/write.php` probe), `R∅` read captured but empty in this
  account (no object shape yet — needs one entry created), `R✗` captured but withheld by the
  leak check (anonymisation open), `403`/`400` not accessible for this account, `W` write
  captured (Postman, by the user)
- **impl**: `–` not yet, `✓` implemented and tested, `✓u` implemented from manual/spec, write unverified,
  `agents only` for Myra's own staff (spec), not offered

Reads are captured with `tests/Smoke/capture.php` (GET only, anonymised in memory, leak-checked,
staged in `.cache/captures/`, promoted to `docs/captures/` per area). First run 2026-10-16:
112 requests, 100 written, 12 withheld (BIND: Myra's name server labels; one vhost value).

Rules (docs/2.0-design.md): an area is implemented only after its reads are captured; writes
without a harmless test are implemented from the spec, labelled *write unverified*.

## Domains

_done: list/page_

| Version | Operation | Kind | Capture | Impl |
|---|---|---|---|---|
| v1 | `GET /{lang}/rapi/domains/{page}` | read | R | ✓ |
| v1 | `PUT /{lang}/rapi/domains` | write | – | – |
| v1 | `POST /{lang}/rapi/domains` | write | – | – |
| v1 | `DELETE /{lang}/rapi/domains` | write | – | – |
| v2 | `GET /domains/{id}` | read | R | ✓ |
| v2 | `PUT /domains/{id}` | write | – | – |
| v2 | `DELETE /domains/{id}` | write | – | – |
| v2 | `GET /domains/{id}/bind/{ipTarget}` | read | R✗ | – |
| v2 | `GET /domains/{id}/bind-raw/{ipTarget}` | read | R✗ | – |
| v2 | `GET /domains` | read | R | ✓ |
| v2 | `POST /domains` | write | – | – |

## DNS records

_done; reads and writes captured (81–97) with inactive TXT and A probes; v2 PUT …/load-balancing answered HTTP 500 (unverified) — load balancing is changed through update() in both versions_

| Version | Operation | Kind | Capture | Impl |
|---|---|---|---|---|
| v1 | `GET /{lang}/rapi/dnsRecords/{domain}/{page}` | read | R | ✓ |
| v1 | `PUT /{lang}/rapi/dnsRecords/{domain}` | write | W | ✓ |
| v1 | `POST /{lang}/rapi/dnsRecords/{domain}` | write | W | ✓ |
| v1 | `DELETE /{lang}/rapi/dnsRecords/{domain}` | write | W | ✓ |
| v2 | `GET /domain/{domainId}/dns-records/{id}` | read | R | ✓ |
| v2 | `PUT /domain/{domainId}/dns-records/{id}` | write | W | ✓ |
| v2 | `DELETE /domain/{domainId}/dns-records/{id}` | write | W | ✓ |
| v2 | `GET /domain/{domainId}/load-balancing` | read | R | ✓ |
| v2 | `GET /domain/{domainId}/dns-records` | read | R | ✓ |
| v2 | `POST /domain/{domainId}/dns-records` | write | W | ✓ |
| v2 | `GET /domain/{domainId}/vhost/{vhostId}/dns-records` | read | R | ✓ |
| v2 | `PUT /domain/{domainId}/dns-records/{id}/load-balancing` | write | 500 | ✓u |

## Cache clear

_done_

| Version | Operation | Kind | Capture | Impl |
|---|---|---|---|---|
| v1 | `PUT /{lang}/rapi/cacheClear/{domain}` | write | W | ✓ |
| v2 | `PUT /domain/{domainId}/cache-clear` | write | W | ✓ |

## Redirects

_done: sets the pattern for per-vhost areas and writes (docs/2.0-design.md, "Area pattern"); writes captured with a probe (25–37)_

| Version | Operation | Kind | Capture | Impl |
|---|---|---|---|---|
| v1 | `GET /{lang}/rapi/redirects/{domain}/{page}` | read | R | ✓ |
| v1 | `PUT /{lang}/rapi/redirects/{domain}` | write | W | ✓ |
| v1 | `POST /{lang}/rapi/redirects/{domain}` | write | W | ✓ |
| v1 | `DELETE /{lang}/rapi/redirects/{domain}` | write | W | ✓ |
| v2 | `GET /domain/{domainId}/redirects/{name}/{id}` | read | R | ✓ |
| v2 | `PUT /domain/{domainId}/redirects/{name}/{id}` | write | W | ✓ |
| v2 | `DELETE /domain/{domainId}/redirects/{name}/{id}` | write | W | ✓ |
| v2 | `GET /domain/{domainId}/redirects/{vHostName}` | read | R | ✓ |
| v2 | `POST /domain/{domainId}/redirects/{name}` | write | W | ✓ |

## IP filter

_done; reads and writes captured (38–55), incl. v2 export (CSV) and import (CSV, also signed with HMAC)_

| Version | Operation | Kind | Capture | Impl |
|---|---|---|---|---|
| v1 | `GET /{lang}/rapi/ipfilter/{domain}/{page}` | read | R | ✓ |
| v1 | `PUT /{lang}/rapi/ipfilter/{domain}` | write | W | ✓ |
| v1 | `POST /{lang}/rapi/ipfilter/{domain}` | write | W | ✓ |
| v1 | `DELETE /{lang}/rapi/ipfilter/{domain}` | write | W | ✓ |
| v2 | `GET /domain/{domainId}/ip-filters/{name}/{id}` | read | R | ✓ |
| v2 | `PUT /domain/{domainId}/ip-filters/{name}/{id}` | write | W | ✓ |
| v2 | `DELETE /domain/{domainId}/ip-filters/{name}/{id}` | write | W | ✓ |
| v2 | `GET /domain/{domainId}/ip-filters/{vHostName}` | read | R | ✓ |
| v2 | `GET /domain/{domainId}/ip-filters/{vHostName}/export` | read | R | ✓ |
| v2 | `POST /domain/{domainId}/ip-filters/{name}` | write | W | ✓ |
| v2 | `POST /domain/{domainId}/ip-filters/{vHostName}/import` | write | W | ✓ |

## Cache settings

_done; reads and writes captured (56–68); v2 path has the vhost before the area; the object names no subdomain (the endpoints attach it)_

| Version | Operation | Kind | Capture | Impl |
|---|---|---|---|---|
| v1 | `GET /{lang}/rapi/cacheSettings/{domain}/{page}` | read | R | ✓ |
| v1 | `PUT /{lang}/rapi/cacheSettings/{domain}` | write | W | ✓ |
| v1 | `POST /{lang}/rapi/cacheSettings/{domain}` | write | W | ✓ |
| v1 | `DELETE /{lang}/rapi/cacheSettings/{domain}` | write | W | ✓ |
| v2 | `GET /domain/{domainId}/{vHostName}/cache-settings` | read | R | ✓ |
| v2 | `POST /domain/{domainId}/{vHostName}/cache-settings` | write | W | ✓ |
| v2 | `PUT /domain/{domainId}/{vHostName}/cache-settings/{id}` | write | W | ✓ |
| v2 | `DELETE /domain/{domainId}/{vHostName}/cache-settings/{id}` | write | W | ✓ |

## Subdomain settings

_done; reads captured, write probed with the unchanged (empty) own settings (69–80) — replace vs. merge on update is NOT verified_

| Version | Operation | Kind | Capture | Impl |
|---|---|---|---|---|
| v1 | `GET /{lang}/rapi/subdomainSetting/{domain}?flat` | read | R | ✓ |
| v1 | `POST /{lang}/rapi/subdomainSetting/{domain}` | write | W | ✓ |
| v2 | `GET /domain/{domainId}/{vHostName}/settings` | read | R | ✓ |
| v2 | `POST /domain/{domainId}/{vHostName}/settings` | write | W | ✓ |

## Load balancing

_done as part of DNS records: loadBalancing() lists, update() with upstreamOptions changes (captured in both versions)_

_v2 ops are in Dns Record (load-balancing)_

| Version | Operation | Kind | Capture | Impl |
|---|---|---|---|---|
| v1 | `GET /{lang}/rapi/dnsRecords/{domain}/{page}?loadbalancer=true` | read | R | ✓ |

## Maintenance

_done; probed with a maintenance for 2099 (never active) and v2 templates (98–113); Myra returns times in its own offset (same instant); v2 create answers 200_

| Version | Operation | Kind | Capture | Impl |
|---|---|---|---|---|
| v1 | `GET /{lang}/rapi/maintenance/{domain}/{page}` | read | R | ✓ |
| v1 | `PUT /{lang}/rapi/maintenance/{domain}` | write | W | ✓ |
| v1 | `POST /{lang}/rapi/maintenance/{domain}` | write | W | ✓ |
| v1 | `DELETE /{lang}/rapi/maintenance/{domain}` | write | W | ✓ |
| v2 | `GET /domain/{domainId}/{vHostName}/maintenances` | read | R | ✓ |
| v2 | `POST /domain/{domainId}/{vHostName}/maintenances` | write | W | ✓ |
| v2 | `PUT /domain/{domainId}/{vHostName}/maintenances/{id}` | write | W | ✓ |
| v2 | `DELETE /domain/{domainId}/{vHostName}/maintenances/{id}` | write | W | ✓ |
| v2 | `GET /domain/{domainId}/maintenances/{id}` | read | R | ✓ |
| v2 | `GET /domain/{domainId}/maintenances` | read | R | ✓ |
| v2 | `GET /domain/{domainId}/maintenance-templates` | read | R | ✓ |
| v2 | `POST /domain/{domainId}/maintenance-templates` | write | W | ✓ |
| v2 | `PUT /domain/{domainId}/maintenance-templates/{id}` | write | W | ✓ |
| v2 | `DELETE /domain/{domainId}/maintenance-templates/{id}` | write | W | ✓ |

## SSL certificates

_done (158–164): reads captured, v1 and v2 identical, per domain; writes from manual/spec (unverified); v1 has no delete (Myra removes unassigned certificates after a week)_

| Version | Operation | Kind | Capture | Impl |
|---|---|---|---|---|
| v1 | `GET /{lang}/rapi/certificates/{domain}/{page}` | read | R | ✓ |
| v1 | `PUT /{lang}/rapi/certificates/{domain}` | write | – | ✓u |
| v1 | `POST /{lang}/rapi/certificates/{domain}` | write | – | ✓u |
| v2 | `GET /ssl-configurations` | read | R | ✓ |
| v2 | `GET /domain/{domainId}/ssl/certificates/{id}` | read | R | ✓ |
| v2 | `GET /domain/{domainId}/ssl/certificates` | read | R | ✓ |
| v2 | `POST /domain/{domainId}/certificates` | write | – | ✓u |
| v2 | `PUT /domain/{domainId}/certificates/{id}` | write | – | ✓u |
| v2 | `DELETE /domain/{domainId}/certificates/{id}` | write | – | ✓u |

## Error pages

_done; probed with code 413 on a vhost that had no page for it (114–126); v2 lists are unpaged_

| Version | Operation | Kind | Capture | Impl |
|---|---|---|---|---|
| v1 | `GET /{lang}/rapi/errorpages/{domain}/{page}` | read | R | ✓ |
| v1 | `GET /{lang}/rapi/errorpage/{id}` | read | R | ✓ |
| v1 | `POST /{lang}/rapi/errorpages/{domain}` | write | W | ✓ |
| v1 | `DELETE /{lang}/rapi/errorpages/{domain}` | write | W | ✓ |
| v2 | `GET /domain/{domainId}/errorpages` | read | R | ✓ |
| v2 | `POST /domain/{domainId}/errorpages` | write | W | ✓ |
| v2 | `DELETE /domain/{domainId}/errorpages` | write | W | ✓ |
| v2 | `GET /domain/{domainId}/errorpages-grouped` | read | R | ✓ |
| v2 | `GET /domain/{domainId}/errorpages/{id}` | read | R | ✓ |

## Statistics

_done; POST reads, captured 2026-10-16 (132–146) with tests/Smoke/statistics.php — the query is the same in v1 and v2, the dashboard statistics are v2 only_

| Version | Operation | Kind | Capture | Impl |
|---|---|---|---|---|
| v1 | `POST /{lang}/rapi/statistic/query` | read | R | ✓ |
| v2 | `POST /statistic/query` | read | R | ✓ |
| v2 | `GET /statistics/total-domain-requests/{startTime}/{finishTime}` | read | R | ✓ |
| v2 | `POST /statistics/total-domain-requests/{startTime}/{finishTime}` | read | R | ✓ |
| v2 | `GET /statistics/total-domain-traffic/{startTime}/{finishTime}` | read | R | ✓ |
| v2 | `POST /statistics/http-status-codes/{startTime}/{finishTime}` | read | R | ✓ |
| v2 | `POST /statistics/top-urls/{startTime}/{finishTime}` | read | R | ✓ |
| v2 | `POST /statistics/cached-urls/{startTime}/{finishTime}` | read | R | ✓ |
| v2 | `POST /statistics/world-map/{startTime}/{finishTime}/{sortBy}/{direction}` | read | R | ✓ |
| v2 | `POST /statistics/histogram/{startTime}/{finishTime}/{chartName}/{interval}/{compressed}/{sum}` | read | R | ✓ |

## IP ranges

_done; v1 lists only the enabled ranges, v2 all — `active()` is the same in both_

| Version | Operation | Kind | Capture | Impl |
|---|---|---|---|---|
| v1 | `GET /{lang}/rapi/networks` | read | R | ✓ |
| v2 | `GET /ip-ranges` | read | R | ✓ |

## BIND zone file

_v2 ops are in Domain (bind, bind-raw); raw = text_

| Version | Operation | Kind | Capture | Impl |
|---|---|---|---|---|
| v1 | `GET /{lang}/rapi/bind/{domainName}` | read | R✗ | – |
| v1 | `GET /{lang}/rapi/bindRaw/{domainName}` | read | R✗ | – |

## Rate limit (v1 only)

| Version | Operation | Kind | Capture | Impl |
|---|---|---|---|---|
| v1 | `GET /{lang}/rapi/ratelimit/dns/{page}` | read | 403 | – |
| v1 | `PUT /{lang}/rapi/ratelimit` | write | – | – |
| v1 | `POST /{lang}/rapi/ratelimit` | write | – | – |
| v1 | `DELETE /{lang}/rapi/ratelimit` | write | – | – |

## Subdomains / vhosts (v2)

_done (147–154); the account list ignores paging, per domain it works; `search` (+ `filterType=exact`)_

| Version | Operation | Kind | Capture | Impl |
|---|---|---|---|---|
| v2 | `GET /domain/{domainId}/subdomains` | read | R | ✓ |
| v2 | `GET /subdomains` | read | R | ✓ |

## Domain verification (v2)

_done (156); verification/check = write, built from the spec (unverified); PUT and foreign-descendants are for Myra agents only (spec) — not offered_

| Version | Operation | Kind | Capture | Impl |
|---|---|---|---|---|
| v2 | `GET /domains/{id}/verification` | read | R | ✓ |
| v2 | `PUT /domains/{id}/verification` | write | – | agents only |
| v2 | `POST /domains/{id}/verification/check` | write | – | ✓u |
| v2 | `GET /domains/{id}/foreign-descendants` | read | 403 | agents only |

## Custom configurations (v2)

| Version | Operation | Kind | Capture | Impl |
|---|---|---|---|---|
| v2 | `GET /domain/{domainId}/custom-configurations` | read | R∅ | – |
| v2 | `GET /domain/{domainId}/custom-configurations/{id}` | read | – | – |

## Tags (v2)

| Version | Operation | Kind | Capture | Impl |
|---|---|---|---|---|
| v2 | `GET /tags/{id}` | read | – | – |
| v2 | `POST /tags/{id}` | write | – | – |
| v2 | `PUT /tags/{id}` | write | – | – |
| v2 | `DELETE /tags/{id}` | write | – | – |
| v2 | `GET /tags/{subDomainName}` | read | R∅ | – |
| v2 | `GET /tags` | read | R∅ | – |
| v2 | `POST /tags` | write | – | – |
| v2 | `GET /tags/{tagId}/information` | read | – | – |
| v2 | `POST /tags/{tagId}/information` | write | – | – |
| v2 | `PUT /tags/{tagId}/information/{id}` | write | – | – |
| v2 | `DELETE /tags/{tagId}/information/{id}` | write | – | – |
| v2 | `GET /tags/{subDomainName}/information` | read | R∅ | – |
| v2 | `GET /tag/{tagId}/settings` | read | – | – |
| v2 | `PUT /tag/{tagId}/settings` | write | – | – |
| v2 | `GET /tag/{tagId}/cache-settings` | read | – | – |
| v2 | `POST /tag/{tagId}/cache-settings` | write | – | – |
| v2 | `PUT /tag/{tagId}/cache-settings/{id}` | write | – | – |
| v2 | `DELETE /tag/{tagId}/cache-settings/{id}` | write | – | – |
| v2 | `POST /tag/{tagId}/waf-rules` | write | – | – |
| v2 | `GET /tag/{tagId}/waf-rules/{id}` | read | – | – |
| v2 | `PUT /tag/{tagId}/waf-rules/{id}` | write | – | – |
| v2 | `DELETE /tag/{tagId}/waf-rules/{id}` | write | – | – |
| v2 | `GET /tag/{id}/waf-rules` | read | – | – |

## WAF (v2)

| Version | Operation | Kind | Capture | Impl |
|---|---|---|---|---|
| v2 | `GET /waf/conditions` | read | R | ✓ |
| v2 | `GET /waf/conditions/matching-types` | read | R | ✓ |
| v2 | `GET /waf/actions` | read | R | ✓ |
| v2 | `GET /waf/actions/values` | read | R | ✓ |
| v2 | `GET /waf/countries` | read | R | ✓ |
| v2 | `POST /domain/{domainId}/{vHostName}/waf-rules` | write | 403 | – |
| v2 | `PUT /domain/{domainId}/{vHostName}/waf-rules/{id}` | write | – | – |
| v2 | `GET /domain/waf-rules/{id}` | read | – | – |
| v2 | `DELETE /domain/waf-rules/{id}` | write | – | – |
| v2 | `GET /domain/{id}/waf-rules` | read | R∅ | – |
| v2 | `GET /domain/{id}/waf-rules/list` | read | R∅ | – |

## Waiting room (v2)

| Version | Operation | Kind | Capture | Impl |
|---|---|---|---|---|
| v2 | `POST /waiting-room` | write | – | – |
| v2 | `GET /waiting-room/{id}` | read | – | – |
| v2 | `PUT /waiting-room/{id}` | write | – | – |
| v2 | `DELETE /waiting-room/{id}` | write | – | – |
| v2 | `GET /waiting-rooms/feature` | read | R | – |
| v2 | `GET /waiting-rooms` | read | 400 | – |

## SSL providers and requests (v2)

_secrets never returned_

| Version | Operation | Kind | Capture | Impl |
|---|---|---|---|---|
| v2 | `GET /ssl/providers/{id}` | read | – | – |
| v2 | `PUT /ssl/providers/{id}` | write | – | – |
| v2 | `DELETE /ssl/providers/{id}` | write | – | – |
| v2 | `GET /ssl/providers` | read | 403 | – |
| v2 | `POST /ssl/providers` | write | – | – |
| v2 | `GET /ssl/provider-names` | read | 403 | – |
| v2 | `GET /ssl/providers/{id}/certificates` | read | – | – |
| v2 | `GET /ssl/requests` | read | 403 | – |
| v2 | `POST /ssl/requests` | write | – | – |
| v2 | `GET /ssl/requests/{id}` | read | – | – |
| v2 | `PUT /ssl/requests/{id}` | write | – | – |
| v2 | `DELETE /ssl/requests/{id}` | write | – | – |
| v2 | `PUT /ssl/requests/{id}/ssl-configuration` | write | – | – |
| v2 | `POST /ssl/requests/check-domains` | write | – | – |

## Replication DNS (v2)

_TSIG secret never returned_

| Version | Operation | Kind | Capture | Impl |
|---|---|---|---|---|
| v2 | `GET /replication-dns/zones` | read | R∅ | – |
| v2 | `POST /replication-dns/zones` | write | – | – |
| v2 | `GET /replication-dns/zones/{id}` | read | – | – |
| v2 | `PUT /replication-dns/zones/{id}` | write | – | – |
| v2 | `DELETE /replication-dns/zones/{id}` | write | – | – |

## Users, groups, permissions (v2)

_reads done (170–185), anonymised in memory and reviewed by eye; all writes from the spec (unverified, never probed)_

_personal data: anonymise in memory_

| Version | Operation | Kind | Capture | Impl |
|---|---|---|---|---|
| v2 | `GET /users/{id}` | read | R | ✓ |
| v2 | `PUT /users/{id}` | write | – | ✓u |
| v2 | `DELETE /users/{id}` | write | – | ✓u |
| v2 | `GET /user/me` | read | R | ✓ |
| v2 | `POST /user/reauthenticate` | write | – | ✓u |
| v2 | `GET /users` | read | R | ✓ |
| v2 | `POST /users` | write | – | ✓u |
| v2 | `GET /user/{id}/groups` | read | R | ✓ |
| v2 | `POST /user/password/reset` | write | – | ✓u |
| v2 | `POST /user/password/new` | write | – | ✓u |
| v2 | `POST /user/{id}/password` | write | – | ✓u |
| v2 | `GET /user/{id}/api-keys` | read | R | ✓ |
| v2 | `POST /user/{id}/api-keys` | write | – | ✓u |
| v2 | `DELETE /user/{userId}/api-keys/{id}` | write | – | ✓u |
| v2 | `DELETE /user/{userId}/sessions/{id}` | write | – | ✓u |
| v2 | `GET /user/{id}/activities` | read | R | ✓ |
| v2 | `GET /user/groups` | read | R | ✓ |
| v2 | `POST /user/groups` | write | – | ✓u |
| v2 | `GET /user/groups/{id}` | read | R | ✓ |
| v2 | `PUT /user/groups/{id}` | write | – | ✓u |
| v2 | `DELETE /user/groups/{id}` | write | – | ✓u |
| v2 | `GET /user/group/{id}/users` | read | R | ✓ |
| v2 | `POST /user/group/{id}/users` | write | – | ✓u |
| v2 | `DELETE /user/group/{id}/users/{userId}` | write | – | ✓u |
| v2 | `GET /user/group/{id}/permissions` | read | R | ✓ |
| v2 | `POST /user/group/{id}/permissions` | write | – | ✓u |
| v2 | `GET /user/{id}/permissions` | read | R | ✓ |
| v2 | `POST /user/{id}/permissions` | write | – | ✓u |
| v2 | `GET /user/permissions` | read | R | ✓ |
| v2 | `POST /user/permissions/check` | write | – | ✓u |
| v2 | `DELETE /user/group/{groupId}/permissions/{id}` | write | – | ✓u |
| v2 | `DELETE /user/{userId}/permissions/{id}` | write | – | ✓u |
| v2 | `GET /user/tokens` | read | R | ✓ |
| v2 | `POST /user/tokens` | write | – | ✓u |
| v2 | `PUT /user/tokens/{id}` | write | – | ✓u |
| v2 | `DELETE /user/tokens/{id}` | write | – | ✓u |

## Organisation (v2)

_reads done (170–185), anonymised in memory and reviewed by eye; writes open (spec, unverified)_

_personal data: anonymise in memory_

| Version | Operation | Kind | Capture | Impl |
|---|---|---|---|---|
| v2 | `GET /organization/contacts` | read | R | ✓ |
| v2 | `POST /organization/contacts` | write | – | – |
| v2 | `GET /organization/contact-types` | read | R | ✓ |
| v2 | `PUT /organization/contacts/{id}` | write | – | – |
| v2 | `DELETE /organization/contacts/{id}` | write | – | – |
| v2 | `GET /organization/notes` | read | R | ✓ |
| v2 | `PUT /organization/notes` | write | – | – |
