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

**Every failure is HTTP 403**, so the body decides: envelope with `error: true` → rejected
request (violations); no envelope → access denied.

Cases 17/18 and the HMAC-on-v2 confirmation come from `tests/Smoke/run.php` (all four
version × auth combinations through this package, 12/12 passed): v2 signed with HMAC
works, including a query string (`/domains?page=2`); Bearer works on v1.

Missing, needed before the other areas are implemented: one list call and one harmless write
per version for subdomain settings, cache settings, redirects, IP filter, maintenance, SSL
certificates, statistics, IP ranges, load balancing.
