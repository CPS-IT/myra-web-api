# Real API answers (captures)

Answers of the real Myra API, recorded with Postman against a production account, then
**anonymised** (this repository is public): domains → `example.com` / `example.org`, IPs →
`192.0.2.0/24` / `2001:db8::/32`, ids, organisation ids and CNAMEs → made-up values. The
mapping is deterministic, so relations survive (the domain id in `/domains` is the id in the
`/domain/{id}/…` paths, records keep their `vhostId`). Error messages, codes, field names,
types and the envelope are unchanged — they are what the code depends on.

Each file: `request` (method, path, body as sent), `response` (status, content type, body),
`finding`. `index.json` lists all cases. These files are the source for test fixtures; do not
edit them, copy into `tests/Fixtures/` instead.

**Never commit raw captures.** They contain the origin IPs behind Myra, which allow
bypassing the CDN and its WAF.

## 2026-10-16

Both auth methods work on both versions; these cases used HMAC (key + secret) for v1 and the
Bearer token for v2. Cache clears used non-existent paths (`/__probe__/…`, not recursive).

| Case | Version | Request | Status | Finding |
|---|---|---|---|---|
| 1 | v1 | `GET /en/rapi/dnsRecords/{domain}/1` | 200 | envelope + `list`, paging fields |
| 2 | v1 | `PUT /en/rapi/cacheClear/{domain}` | 200 | `targetObject[CacheClearVO]` |
| 3 | v1 | same, `bulk` | 200 | bulk works in v1; `resource` echoes the last entry |
| 4 | v1 | wrong secret | 403 | `text/plain` "API key wrong or not provided.", no envelope |
| 5 | v1 | unknown domain | 403 | **empty body** — same as an auth failure |
| 6.1 | v1 | malformed body | 403 | violation "Invalid data format." |
| 6.2 | v1 | `fqdn` not in the domain | 403 | violation `DOMAIN_DOES_NOT_EXIST` with translations |
| 7 | v2 | `GET /domains` | 200 | envelope + `data`, paging fields, `id` per domain |
| 8 | v2 | `GET /domain/{id}/dns-records` | 200 | same DnsRecordVO as v1 |
| 9 | v2 | `PUT /domain/{id}/cache-clear` | 200 | **`error: false` is sent** (the spec omits it) |
| 10 | v2 | same, `bulk` | 200 | bulk works in v2 |
| 11 | v2 | wrong token | 403 | **empty body** |
| 12 | v2 | unknown domain id | 403 | violation "The entity does not exist."; without any token once 401 `{"error": "Session has been revoked"}` (a string) |
| 13.1 | v2 | malformed body | 403 | "Invalid data format." (also without a domain id) |
| 13.2 | v2 | `fqdn` not in the domain | 403 | `DOMAIN_DOES_NOT_EXIST` |
| 14 | v2 | `recursive: "x"` | 403 | violation on `recursive`, type error |
| 15 | v2 | domain **name** instead of id | 403 | rejected — v2 needs the numeric id |
| 16 | v2 | `GET /domains?page=2` | 200 | paging via `?page=`: echoed as `page: 2`; past the last page `data` is empty; `count` = total hits |
| 17 | v1 | `GET /en/rapi/domains/1` | 200 | same `DomainVO` as v2, field by field and ids; no `paused`/`pausedUntil` (the manual shows them) |
| 18 | v1 | `GET /en/rapi/domains/2` | 200 | page in the path, echoed; past the last page `list` is empty; `count` = total |
| 19 | v1 | `GET /en/rapi/redirects/example.com/1` | 200 | redirects of the zone apex: per vhost (subDomainName = the apex only) |
| 20 | v1 | `GET /en/rapi/redirects/www.example.com/1` | 200 | v1 takes a subdomain where the manual says {domain}: only that vhost's redirects |
| 21 | v1 | `GET /en/rapi/redirects/example.org/1` | 200 | no redirects: empty list, count 0 |
| 22 | v2 | `GET /domain/1000/redirects/example.com` | 200 | same DnsRedirectVO as v1; v2 lists per vHostName |
| 23 | v2 | `GET /domain/1000/redirects/example.com/90392` | 200 | get by id: data is a one-element list, no paging fields |
| 24 | v2 | `GET /domain/1140/redirects/example.org` | 403 | vHostName that is no vhost: 403 'The entity does not exist.' |
| 25 | v1 | `PUT /en/rapi/redirects/map.example.org` | 200 | v1 create: PUT, answer in targetObject; enabled: false is honoured although the manual does not list it |
| 26 | v1 | `POST /en/rapi/redirects/map.example.org` | 200 | v1 update: POST with id + modified, answer with the new modified |
| 27 | v1 | `POST /en/rapi/redirects/map.example.org` | 200 | optimistic lock: a stale modified is rejected with HTTP 200 (!) and error: true |
| 28 | v1 | `GET /en/rapi/redirects/map.example.org/1` | 200 | list after the update |
| 29 | v1 | `DELETE /en/rapi/redirects/map.example.org` | 200 | v1 delete: body {id, modified}; JSON answer although Content-Type is text/html |
| 30 | v1 | `GET /en/rapi/redirects/map.example.org/1` | 200 | list after the delete: the probe is gone |
| 31 | v2 | `POST /domain/1140/redirects/map.example.org` | 201 | v2 create: POST, HTTP 201, answer in data |
| 32 | v2 | `GET /domain/1140/redirects/map.example.org/90003` | 200 | get by id after the create |
| 33 | v2 | `PUT /domain/1140/redirects/map.example.org/90003` | 200 | v2 update: PUT …/{id} with id + modified |
| 34 | v2 | `PUT /domain/1140/redirects/map.example.org/90003` | 400 | optimistic lock: a stale modified is rejected with HTTP 400 and error: true |
| 35 | v2 | `GET /domain/1140/redirects/map.example.org` | 200 | list after the update |
| 36 | v2 | `DELETE /domain/1140/redirects/map.example.org/90003` | 204 | v2 delete: HTTP 204, empty body, no Content-Type |
| 37 | v2 | `GET /domain/1140/redirects/map.example.org` | 200 | list after the delete: the probe is gone |
| 38 | v1 | `GET /en/rapi/ipfilter/example.com/1` | 200 | IP filter of the apex: IpFilterVO, per vhost like redirects |
| 39 | v1 | `GET /en/rapi/ipfilter/www.example.com/1` | 200 | a vhost without entries: empty list |
| 40 | v2 | `GET /domain/1000/ip-filters/example.com` | 200 | same IpFilterVO as v1 |
| 41 | v2 | `GET /domain/1000/ip-filters/example.com/90393` | 200 | get by id: one-element data list |
| 42 | v2 | `GET /domain/1000/ip-filters/example.com/export` | 200 | export: text/csv with the columns IP, Expires on, Comments |
| 43 | v2 | `GET /domain/1140/ip-filters/example.org` | 403 | a vHostName that is no vhost: 403 'The entity does not exist.' |
| 44 | v1 | `PUT /en/rapi/ipfilter/map.example.org` | 200 | v1 create (probe: disabled blacklist entry for 192.0.2.1) |
| 45 | v1 | `POST /en/rapi/ipfilter/map.example.org` | 200 | v1 update with id + modified |
| 46 | v1 | `POST /en/rapi/ipfilter/map.example.org` | 200 | stale modified: HTTP 200 + error: true |
| 47 | v2 | `POST /domain/1140/ip-filters/map.example.org` | 201 | v2 create: HTTP 201 |
| 48 | v2 | `PUT /domain/1140/ip-filters/map.example.org/90002` | 200 | v2 update: PUT …/{id} |
| 49 | v2 | `PUT /domain/1140/ip-filters/map.example.org/90002` | 400 | stale modified: HTTP 400 |
| 50 | v2 | `GET /domain/1140/ip-filters/map.example.org/export?type=BLACKLIST` | 200 | export filtered by ?type=BLACKLIST |
| 51 | v2 | `POST /domain/1140/ip-filters/map.example.org/import?type=BLACKLIST` | 201 | import with one invalid line: HTTP 201 and error: false (the spec says true), the line as violation |
| 52 | v2 | `POST /domain/1140/ip-filters/map.example.org/import?type=BLACKLIST` | 201 | import signed with HMAC over Content-Type text/csv: works |
| 53 | v1 | `DELETE /en/rapi/ipfilter/map.example.org` | 200 | v1 delete: body {id, modified} |
| 54 | v2 | `GET /domain/1140/ip-filters/map.example.org` | 200 | list after the imports: expireDate appears only when set |
| 55 | v2 | `DELETE /domain/1140/ip-filters/map.example.org/90002` | 204 | v2 delete: HTTP 204, empty |
| 56 | v1 | `GET /en/rapi/cacheSettings/example.com/1` | 200 | cache settings of the apex: CacheSettingVO without a subdomain field |
| 57 | v1 | `GET /en/rapi/cacheSettings/www.example.com/1` | 200 | a vhost without rules: empty list |
| 58 | v1 | `GET /en/rapi/cacheSettings/www.example.org/1` | 500 | v1, a subdomain that is no vhost: HTTP 500, empty HTML — no envelope |
| 59 | v2 | `GET /domain/1000/example.com/cache-settings` | 200 | same CacheSettingVO as v1; path /domain/{id}/{vHostName}/cache-settings (vhost before area) |
| 60 | v2 | `GET /domain/1140/example.org/cache-settings` | 403 | a vHostName that is no vhost: 403 'The entity does not exist.' |
| 61 | v1 | `PUT /en/rapi/cacheSettings/map.example.org` | 200 | v1 create (probe: disabled rule for the exact path /__probe__/); notFoundTtl defaults to 60 |
| 62 | v1 | `POST /en/rapi/cacheSettings/map.example.org` | 200 | v1 update with id + modified |
| 63 | v1 | `POST /en/rapi/cacheSettings/map.example.org` | 200 | stale modified: HTTP 200 + error: true |
| 64 | v1 | `DELETE /en/rapi/cacheSettings/map.example.org` | 200 | v1 delete: body {id, modified} |
| 65 | v2 | `POST /domain/1140/map.example.org/cache-settings` | 201 | v2 create: HTTP 201 |
| 66 | v2 | `PUT /domain/1140/map.example.org/cache-settings/90002` | 200 | v2 update: PUT …/cache-settings/{id} |
| 67 | v2 | `PUT /domain/1140/map.example.org/cache-settings/90002` | 400 | stale modified: HTTP 400 |
| 68 | v2 | `DELETE /domain/1140/map.example.org/cache-settings/90002` | 204 | v2 delete: HTTP 204, empty |
| 69 | v1 | `GET /en/rapi/subdomainSetting/example.com` | 200 | subdomain settings: targetObject = 5 inheritance layers, domain = the vhost's own, parent = inherited (the manual's structure is outdated) |
| 70 | v1 | `GET /en/rapi/subdomainSetting/www.example.com` | 200 | a vhost with own settings: domain holds them, the last layer equals domain |
| 71 | v1 | `GET /en/rapi/subdomainSetting/example.com?flat=` | 200 | ?flat: the merged settings as a plain map, no envelope; v1 sends numbers as strings |
| 72 | v1 | `GET /en/rapi/subdomainSetting/www.example.org` | 500 | v1, a subdomain that is no vhost: HTTP 500, empty HTML |
| 73 | v2 | `GET /domain/1000/example.com/settings` | 200 | v2 GET answers in targetObject like v1 (not data) |
| 74 | v2 | `GET /domain/1140/example.org/settings` | 403 | a vHostName that is no vhost: 403 'The entity does not exist.' |
| 75 | v1 | `GET /en/rapi/subdomainSetting/map.example.org` | 200 | a vhost without own settings: domain is [] (a JSON list) |
| 76 | v1 | `GET /en/rapi/subdomainSetting/map.example.org?flat` | 200 | ?flat of that vhost (v1: numbers as strings) |
| 77 | v1 | `POST /en/rapi/subdomainSetting/map.example.org` | 200 | update with the unchanged own settings ({}): accepted, answer echoes them in targetObject |
| 78 | v2 | `GET /domain/1140/map.example.org/settings` | 200 | v2: same structure as v1 |
| 79 | v2 | `GET /domain/1140/map.example.org/settings?flat` | 200 | ?flat in v2 too (not in the spec); v2 sends numbers as numbers |
| 80 | v2 | `POST /domain/1140/map.example.org/settings` | 200 | v2 update: answer in data (the GET answers in targetObject) |
| 81 | v1 | `GET /en/rapi/dnsRecords/example.com/1?loadbalancer=true&recordTypes=A,AAAA,CNAME` | 200 | load balancing in v1: the DNS list with ?loadbalancer=true&recordTypes=A,AAAA,CNAME — records with upstreamOptions |
| 82 | v2 | `GET /domain/1000/load-balancing` | 200 | load balancing in v2: /domain/{id}/load-balancing, the same records |
| 83 | v2 | `GET /domain/1000/dns-records/1021` | 200 | get by id: one-element data list |
| 84 | v2 | `GET /domain/1000/vhost/1007/dns-records` | 200 | the records of one vhost, by its v2 vhostId |
| 85 | v1 | `PUT /en/rapi/dnsRecords/example.org` | 200 | v1 create (probe: inactive TXT __probe__.<domain>); a TXT record answers a partial UpstreamOptionsVO (backup, down only) |
| 86 | v1 | `POST /en/rapi/dnsRecords/example.org` | 200 | v1 update: the complete record as read, changes on top |
| 87 | v1 | `POST /en/rapi/dnsRecords/example.org` | 200 | stale modified: HTTP 200 + error: true |
| 88 | v1 | `PUT /en/rapi/dnsRecords/example.org` | 200 | probe A record (192.0.2.1, inactive) with default upstreamOptions (own id + modified) |
| 89 | v1 | `POST /en/rapi/dnsRecords/example.org` | 200 | load balancing changed through the normal update (weight 1 → 2) |
| 90 | v1 | `DELETE /en/rapi/dnsRecords/example.org` | 200 | v1 delete: body {id, modified} |
| 91 | v2 | `POST /domain/1140/dns-records` | 201 | v2 create: HTTP 201 |
| 92 | v2 | `PUT /domain/1140/dns-records/90009` | 200 | v2 update: PUT …/dns-records/{id} |
| 93 | v2 | `PUT /domain/1140/dns-records/90009` | 400 | stale modified: HTTP 400 |
| 94 | v2 | `POST /domain/1140/dns-records` | 201 | probe A record in v2 |
| 95 | v2 | `PUT /domain/1140/dns-records/90011` | 200 | load balancing changed through the normal update in v2 too (weight 1 → 2) |
| 96 | v2 | `PUT /domain/1140/dns-records/90011/load-balancing` | 500 | PUT …/{id}/load-balancing: HTTP 500 'An internal error occurred' for the inactive probe record (also with the upstream object's own id) — unverified |
| 97 | v2 | `DELETE /domain/1140/dns-records/90009` | 204 | v2 delete: HTTP 204 |

**Every failure is HTTP 403**, so the body decides: envelope with `error: true` → rejected
request (violations); no envelope → access denied.

Cases 19–24 come from `tests/Smoke/capture.php`, cases 25–37 from `tests/Smoke/write.php` (a disabled probe
redirect of `/__probe__/redirect` on an unused domain, created, changed and deleted through this
package) — both anonymised in memory, see their docblocks.
Cases 17/18 and the HMAC-on-v2 confirmation come from `tests/Smoke/run.php` (all four
version × auth combinations through this package, 12/12 passed): v2 signed with HMAC
works, including a query string (`/domains?page=2`); Bearer works on v1.

Missing, needed before the other areas are implemented: one list call and one harmless write
per version for subdomain settings, cache settings, redirects, IP filter, maintenance, SSL
certificates, statistics, IP ranges, load balancing.
