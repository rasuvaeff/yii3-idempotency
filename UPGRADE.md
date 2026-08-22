# Upgrade to 2.0

One breaking change in 2.0.0 requires action, plus two behaviour changes that
may surface in your application.

## Idempotency keys are namespaced by the caller (required)

An idempotency key used to be global: a client that knew or guessed another
client's key and reproduced its payload got the other client's cached response
replayed — without ever entering the handler's authorization checks — and could
occupy someone else's key for the whole TTL.

In 2.0 the middleware partitions keys per caller:

- `IdempotencyMiddleware::__construct()` takes a new **required**
  `IdempotencyScopeResolver $scopeResolver`. The container refuses to build the
  middleware until you decide.
- The shipped default wiring reads the authenticated principal from a request
  attribute (`callerAttribute`, default `user`). Requests with no principal fall
  into the shared `anonymous` namespace.
- To restore the 1.x one-keyspace-for-everyone behaviour (single tenant, single
  trusted client), set **both** `'callerAttribute' => false` and `'scope' => null`
  in `config/common/params.php`.
- Endpoint namespacing (`scope: 'auto'`, "METHOD /path") is applied on top of the
  caller; set `'scope' => null` for one namespace across endpoints.

**Action:** set `callerAttribute` to the request attribute that carries your
authenticated user id after authentication middleware runs.

```php
// config/common/params.php
'rasuvaeff/yii3-idempotency' => [
    'callerAttribute' => 'user', // or false + scope: null for the 1.x behaviour
],
```

Existing stored records become unreachable under the new scoped keys. The table
is a TTL cache, so they simply expire — no migration needed.

## Response header capture is filtered

`Set-Cookie`, `Date` and hop-by-hop headers are no longer captured nor replayed.
If you relied on replaying them (you almost certainly did not want to), provide a
custom capture path. Additional exclusions can be added via
`additionalExcludedResponseHeaders`; the built-in deny-list cannot be disabled.

## Malformed keys answer 400

`Idempotency-Key: foo bar` now produces a 400 response instead of an unhandled
exception. Clients sending malformed keys will start seeing 400s — which is the
contract working as intended.
