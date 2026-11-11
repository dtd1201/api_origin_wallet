# AUTO-02A offline HTTP test harness

The old bootstrap subscribed to PHPUnit `Test\Prepared` and called
`Http::preventStrayRequests()` for every test. PHPUnit emits that event after
setup, but plain PHPUnit tests (including `NiumDynamicPayoutConfirmationTest`)
do not initialize Laravel. With no facade application or cached HTTP root,
Laravel's `Http::preventStrayRequests()` dereferences null. PHPUnit converts
exceptions from third-party subscribers into runner warnings, allowing tests
to pass without the guard. The event also cannot protect a later application
refresh inside a test.

The bootstrap now only enables test-only support. `Tests\TestCase` overrides
`refreshApplication()`: after Laravel creates and bootstraps each application,
it installs protection on that application's HTTP singleton before returning
to trait setup or child setup. Every subsequent call to `refreshApplication()`
repeats installation. Ordinary runs using `vendor/autoload.php` retain their
existing lifecycle unless the focused regression class temporarily opts in.

Installation requires a bootstrapped application, the matching facade
application, a registered HTTP factory, and the same factory behind the facade.
Missing or inconsistent dependencies throw from Laravel setup; they are never
silently ignored or converted into subscriber warnings. The installer mutates
the current factory without resetting explicit fakes or recordings. Plain
PHPUnit tests do not create an application and therefore do not invoke this
Laravel lifecycle hook.

`NiumTransferOfflineHttpGuardTest` covers bootstrap loading without a facade
application, protection before child setup, unfaked requests, explicit provider
fakes with unmatched requests still blocked, application refresh with old fakes
discarded, repeated installation preserving fakes, and explicit failure for
missing facade state, missing bindings, and invalid factory instances. Request
probes use reserved `.invalid` URLs and a terminal handler that throws if
reached, so a guard regression cannot cause real network traffic in these probes.

## Coverage boundaries

- Protection applies to requests using the guarded application HTTP factory,
  including Laravel's `Http` facade and container-injected instances of that
  singleton. `ProviderHttpClient` uses this facade.
- Raw Guzzle clients, raw cURL (including multi handles), PHP URL streams,
  sockets, Symfony or other HTTP clients, SDK-owned transports, subprocesses,
  and separate worker processes bypass this guard. This is not an OS network
  sandbox and does not claim to block all outbound traffic.
- Independently constructed Laravel HTTP factories and pending requests created
  before installation are not covered. Neither are plain PHPUnit tests that
  manually build their own clients or applications outside `Tests\TestCase`,
  nor application construction/kernel bootstrap before this hook returns.
- Replacing the HTTP binding/facade manually within the same application is not
  an application refresh; callers must explicitly reinstall protection on the
  new factory. Test cases overriding refresh without calling the parent bypass
  the shared hook. Explicitly disabling prevention, allowing stray URLs, or
  bypassing Laravel's middleware also bypasses protection.
- Catch-all `Http::fake()` responses count as matched fakes. They prevent I/O
  but do not prove endpoint-specific contracts. The focused fake regression
  matches one synthetic provider URL and checks an unmatched URL is rejected.

## Technical Lead / operator validation

No PHP, PHPUnit, Artisan, Composer, lint, build, migration, or server command
was executed during implementation. Runtime results and warning counts remain
unverified. Existing unrelated dirty and untracked files must be preserved.

After Technical Lead review, the operator may run this single command from the
repository root in the approved offline test environment:

```sh
./vendor/bin/phpunit --bootstrap tests/nium-transfer-offline-bootstrap.php --fail-on-warning --filter 'NiumTransferOfflineHttpGuardTest|NiumDynamicPayoutConfirmationTest|NiumDynamicQuoteFoundationTest|NiumPayoutFxSafetyTest|NiumTransferServiceTest'
```

`--fail-on-warning` makes warnings fail the run; it does not hide them. The
selected feature tests use their existing in-memory test database lifecycle;
operator execution may therefore invoke their existing test migrations.
Recommend PASS only after the operator reports all selected tests passing,
zero errors, and zero warnings. Until then, validation is pending and release
sign-off is FAIL. Stop for Technical Lead review; do not commit or push.
