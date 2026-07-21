# Code Review — codraw/messenger

## Fixes applied (2026-07-20)

- **composer.json:** PHP version constraint changed from unbounded `>=8.5` to `^8.5` (version-compatibility debt: prevents a future PHP 9 from installing against this package; no effect on any currently existing PHP version).
- **H2** — `composer.json`: added `fidry/cpu-core-counter: ^1.0` to `require`, so `draw:messenger:start-broker --concurrent=auto` no longer fatals on installs without the transitive dev dependency.
- **Dependency declarations** — `composer.json`: added `symfony/console: ^6.4.0` and `symfony/dependency-injection: ^6.4.0` to `require` (both are hard-imported by shipped code: console commands, `Autowire`/`Exclude` attributes); moved `psr/log: ^3` from `require-dev` to `require` (`Versioning/EventListener/StopOnNewVersionListener.php` type-hints `LoggerInterface`); added a `suggest` entry for `doctrine/orm` (the `Transport/Entity` traits are only usable when the consumer maps ORM entities). `doctrine/orm` stays in `require-dev`, matching the `codraw/console` precedent.
- **M1** — `Transport/DrawTransport.php` `cleanQueue()`: ids are now bound with `IN (:ids)` + `ArrayParameterType::STRING` instead of interpolated into double-quoted SQL literals, fixing the PostgreSQL/`ANSI_QUOTES` breakage and removing the injection-shaped string concatenation.
- **M5** — `Retry/Event/GetWaitingTimeEvent.php`: `getThrowable()` return type corrected to `?\Throwable`, matching the nullable property and the sibling `IsRetryableEvent` (previously a `TypeError` when no throwable was supplied).
- **M6** — `Retry/EventListener/SelfAwareMessageRetryableListener.php`: the `spl_object_hash()`-keyed waiting-time entry is now unset after consumption in `onGetWaitingTimeEvent()`, preventing unbounded growth and stale-hash collisions in long-running workers.
- **L2** — `Broker/Command/StartMessengerBrokerCommand.php` `calculateAutoConcurrent()`: removed the dead `$maxProcesses =` assignment inside the `if` condition (behavior unchanged).
- **L3** — `ManualTrigger/ManuallyTriggeredMessageUrlGenerator.php` `generateLink()`: the missing `TransportMessageIdStamp` case now throws a descriptive `\RuntimeException` instead of fataling on a null method call.
- **L5** — `Transport/DrawTransport.php` `insert()`: delay is applied as `%d milliseconds` instead of `%d seconds` on `$delay / 1000`, so sub-second `DelayStamp` values are no longer truncated to zero.

Validation pass (2026-07-20): `composer install` resolves with the updated constraints as-is; the full PHPUnit suite passes against MySQL (195 tests, 829 assertions — the 58 PHPUnit notices are pre-existing and occur without these changes too); PHPStan reports the same 16 pre-existing errors with and without the changes (none in modified files; the empty baseline was left untouched); markdownlint is clean. No test-expectation updates were needed — no existing test pinned the old buggy behavior.

Not fixed (deliberately, as they change behavior consumers may rely on or need design decisions): H1 (endpoint authorization/design), M2 (transactionality), M3 (timezone normalization affects stored data semantics), M4 (ORM nullability change alters consumer-generated schemas/migrations), M7 (config default design), L1 (process-global signal handling), L4 (would start rejecting envelopes currently returned), L6 (design/documentation of the inheritance trick).

## Overall Assessment

This package is a well-structured extension of the Symfony Messenger Doctrine transport, adding tagged/searchable messages, expiration/purging, a process-supervising broker, manually-triggered ("click link") messages, event-driven retry, and Doctrine lifecycle-to-bus hooks. Code quality is generally high: modern PHP 8 style, an empty PHPStan baseline, immutable envelope handling, and an unusually broad test suite that includes real-database integration tests for the transport. However, the review found two high-severity issues — a missing runtime Composer dependency that makes the advertised `--concurrent=auto` broker option fatal, and a security design gap in `ClickMessageAction` that allows *any* queued message (not just manually-triggerable ones) to be executed and acked through the public click endpoint — plus several medium issues around SQL portability, transactionality, timezone consistency, and ORM mapping drift.

## Findings

### High

#### H1. `ClickMessageAction` executes and acks arbitrary queued messages, not just manually-triggered ones

`ManualTrigger/Action/ClickMessageAction.php:35-67` and `:84-111`, with `Searchable/EnvelopeFinder.php:23-45`.

The action looks up an envelope by id across **all** listable transports (`EnvelopeFinder::findById()`), and the only filter applied is `MustNotBeStampedEnvelopeFilter::sentToFailureTransport()` plus the expiration filter (which is a no-op when the envelope has no `ExpirationStamp`). There is no check that the message implements `ManuallyTriggeredInterface`, carries a `ManualTriggerStamp`, or has an `ExpirationStamp` at all. This endpoint is designed to be reachable from unauthenticated email links (`ManuallyTriggeredMessageUrlGenerator` builds absolute URLs to it), so anyone who knows or guesses a message id of a *normal* async message can:

1. force its synchronous execution inside a web request (`handle()` dispatches with a `ReceivedStamp`), and
2. **ack it** (`ClickMessageAction.php:106-109`), removing it from the queue — i.e., deleting arbitrary pending work.

Compounding this, message ids are UUID v6 (`Transport/DrawTransport.php:141`), which are time-ordered and built from timestamp + clock-seq + node (often the host MAC via ramsey's default node provider), so they are far more predictable than random tokens. Recommendation: restrict the action to envelopes stamped with `ManualTriggerStamp` (or messages implementing `ManuallyTriggeredInterface`), require an `ExpirationStamp`, and consider random (v4/v7) ids or an HMAC-signed URL.

#### H2. **[FIXED]** Missing Composer dependency `fidry/cpu-core-counter`

`Counter/CpuCounter.php:5-19`, `composer.json:19-44`.

`CpuCounter` imports `Fidry\CpuCoreCounter\CpuCoreCounter`, but `fidry/cpu-core-counter` is not declared in `require` (nor `require-dev`), and no other `codraw/*` package in the monorepo requires it. In many installs it happens to be present transitively via dev tooling (e.g., php-cs-fixer), which masks the problem, but in a production install `draw:messenger:start-broker --concurrent=auto` (`Broker/Command/StartMessengerBrokerCommand.php:146`) will fatal with "Class not found". Add the dependency (or make the class-availability check graceful).

### Medium

#### M1. **[FIXED]** `cleanQueue()` builds SQL by string concatenation with double-quoted literals

`Transport/DrawTransport.php:112-118`.

```php
->andWhere('id IN ("'.implode('","', $ids).'")')
```

The ids are interpolated instead of bound as parameters, and double quotes are used as string delimiters. On PostgreSQL (which the test suite explicitly supports — `Tests/TestCase.php` maps `pdo_pgsql`) and on MySQL with `ANSI_QUOTES`, double quotes denote identifiers, so this query errors or misbehaves. The ids come from the package's own DB reads, so direct injection is unlikely today, but any future path that lets external data become an id (the table is also writable via the ORM entities) would turn this into SQL injection. Use `IN (:ids)` with `ArrayParameterType::STRING`.

#### M2. Uniqueness enforcement is not atomic

`Transport/DrawTransport.php:54-64` (`cleanQueue()` then `insert()`), `:133-188`.

`send()` deletes duplicates and inserts the new message without a transaction, and `insert()` writes the message row and its tag rows in separate statements. Consequences: (a) two concurrent `send()` calls with `SearchableTagStamp(..., enforceUniqueness: true)` can both pass `findEnvelopeIds()` before either inserts, producing duplicates the feature promises to prevent; (b) a failure between the message insert and tag inserts leaves an untagged message that later uniqueness cleanups cannot find. Wrap `cleanQueue()` + `insert()` in a transaction (and consider a unique key on the tag table for hard guarantees).

#### M3. Timezone inconsistency between `expires_at` and other datetime columns

`Transport/DrawTransport.php:142-171, 190-193, 310-319`; `Expirable/Stamp/ExpirationStamp.php:15`.

`ExpirationStamp` normalizes its datetime via `createFromFormat('U', ...)`, which is always UTC. `DrawTransport::formatDateTime()` renders wall-clock time in the datetime's own timezone, so `expires_at` is stored as UTC wall time while `created_at`/`available_at` are stored in the PHP default timezone. `purgeObsoleteMessages()` then compares `expires_at` against a threshold typically built from local time (`PurgeExpiredMessageCommand` does `new \DateTime('-1 month')`). On any server not running UTC, expiry and purge are off by the timezone offset (messages purged too early or too late). Normalize everything to UTC before formatting.

#### M4. ORM entity trait nullability contradicts the transport schema

`Transport/Entity/DrawMessageTrait.php:17-39` vs `Transport/DrawTransport.php:344-363`.

`DrawTransport::getSchema()` declares `message_class`, `available_at`, `delivered_at`, and `expires_at` as nullable (`setNotnull(false)`), and the runtime relies on this (`delivered_at` is NULL until delivery; `available_at` is intentionally NULL for manual-trigger messages, `DrawTransport.php:50`). The entity trait's ORM attributes omit `nullable: true`, so Doctrine defaults them to NOT NULL. Any project that maps these entities (the intended usage via `resolve_target_entities` in `DependencyInjection/MessengerIntegration.php:472-478`) and generates migrations from the ORM metadata will produce a schema that breaks the transport (every insert has NULL `delivered_at`). Add `nullable: true` to those columns.

#### M5. **[FIXED]** `GetWaitingTimeEvent::getThrowable()` return type contradicts its nullable property

`Retry/Event/GetWaitingTimeEvent.php:12, 21-24`.

The constructor accepts `?\Throwable $throwable = null` (and `EventDrivenRetryStrategy::getWaitingTime()` at `Retry/EventDrivenRetryStrategy.php:29-37` forwards a nullable throwable), but the getter is declared `: \Throwable`. Any listener calling `getThrowable()` when no throwable was supplied triggers a `TypeError`. The sibling `IsRetryableEvent::getThrowable()` correctly returns `?\Throwable`; this one should too.

#### M6. **[FIXED]** `SelfAwareMessageRetryableListener` keys state by `spl_object_hash()` without cleanup

`Retry/EventListener/SelfAwareMessageRetryableListener.php:42-54`.

The waiting time computed in `onIsRetryableEvent()` is stored under `spl_object_hash($envelope)` and read back in `onGetWaitingTimeEvent()`, but entries are never removed after use — only on `reset()`. In a long-running worker (or with container resetting disabled), the array grows, and because `spl_object_hash()` values are recycled after objects are garbage-collected, a later, unrelated envelope can collide with a stale hash and silently receive the wrong waiting time. Unset the entry after consumption, or use `WeakMap`.

#### M7. Reusable component defaults to `App\Entity\*` classes

`DependencyInjection/MessengerIntegration.php:5-6, 297-310, 467-494`.

`MessengerIntegration` imports `App\Entity\MessengerMessage` / `App\Entity\MessengerMessageTag` and uses them as config defaults. A framework component referencing the application namespace inverts the dependency direction; the `class_exists` guards make it work, but the config validation (`:299`, `:306`) deliberately lets the *nonexistent* default pass while rejecting any other nonexistent class, which is surprising (enabling `doctrine_message_bus_hook`/entity mapping then silently does nothing until the app creates entities with exactly these names). Prefer a null default plus an explicit error when the feature needing the entity is enabled.

### Low

#### L1. SIGTERM handler depends on async signals being enabled elsewhere

`Broker/EventListener/StopBrokerOnSigtermSignalListener.php:20-26`, `Broker/Broker.php:36-60`.

The listener registers a handler with `pcntl_signal()` but neither enables `pcntl_async_signals(true)` nor does the `Broker::start()` loop call `pcntl_signal_dispatch()`. It only works because Symfony Console's `SignalRegistry` happens to enable async signals; if `Broker` is driven outside a full console application, SIGTERM is silently ignored and the graceful-shutdown path never runs.

#### L2. **[FIXED]** Assignment-in-condition dead variable in `calculateAutoConcurrent()`

`Broker/Command/StartMessengerBrokerCommand.php:148`.

`if ($maxProcesses = null === $input->getOption('maximum-processes'))` assigns a boolean to `$maxProcesses` that is immediately discarded and reassigned at `:152`. Behavior is coincidentally correct, but this reads like a `===`/`=` bug and will trip up maintainers.

#### L3. **[FIXED]** `ManuallyTriggeredMessageUrlGenerator::generateLink()` is null-unsafe

`ManualTrigger/ManuallyTriggeredMessageUrlGenerator.php:32-38`.

`->last(TransportMessageIdStamp::class)->getId()` fatals with a null method call if the message was routed to a sync transport (or any transport not adding the stamp). An explicit check with a descriptive exception would make the misconfiguration diagnosable.

#### L4. `EnvelopeFinder::findByTags()` skips the expiration filter that `findById()` applies

`Searchable/EnvelopeFinder.php:52-66` vs `:23-45`.

Tag-based lookups return expired envelopes; id-based lookups do not. The asymmetry is undocumented and likely unintended.

#### L5. **[FIXED]** Sub-second delays are truncated to zero

`Transport/DrawTransport.php:144-146`.

`$now->modify(sprintf('%d seconds', $delay / 1000))` floors the millisecond `DelayStamp` value; a 500 ms delay becomes 0 s and 1999 ms becomes 1 s. Symfony's own Doctrine `Connection` keeps millisecond precision.

#### L6. `PhpEventDispatcherSerializerDecorator` both extends and wraps `PhpSerializer`

`SerializerEventDispatcher/PhpEventDispatcherSerializerDecorator.php:14-20`.

The class extends `PhpSerializer` (presumably so `instanceof` checks on the decorated service keep passing) while also holding the real serializer, and never calls the parent constructor. It works only because the parent currently has no required state; a future parent change could break it silently. A comment explaining the inheritance trick — or composition plus an interface — would be safer.

## Strengths

- **Excellent test breadth**: nearly every class has a dedicated unit test, and `Tests/Transport/DrawTransportTest.php` runs real integration tests against MySQL/PostgreSQL (send, find-by-tags, ack, purge, negative delay, error paths). DI integration is covered by a 487-line `MessengerIntegrationTest`.
- **Clean static analysis**: `phpstan-baseline.neon` is empty — no suppressed errors.
- **Good security hygiene in process handling**: all `Process` invocations use array arguments (`Broker/Broker.php:112-122`, `MessageHandler/RetryFailedMessageMessageHandler.php:24-34`), so there is no shell injection surface.
- **Thoughtful transport details**: `find()` and `findEnvelopeIds()` correctly account for Symfony's `9999-12-31` "delivered" sentinel used by the Doctrine transport deadlock patch; `setup()` carefully saves and restores the schema-assets filter; `NULL available_at` is used elegantly to keep manually-triggered messages invisible to consumers.
- **Extensible event-driven design**: serializer encode/decode events, envelope-created events, retry-decision events, and broker lifecycle events all provide clean extension points with sensible `stopPropagation()` semantics.
- **Graceful broker shutdown design**: SIGTERM → finish in-flight consumers → timed escalation to SIGKILL (`Broker/Broker.php:74-97`).

## Test Coverage

Coverage is qualitatively strong for a component library. Covered: the broker (loop logic, command, listeners, events), DrawTransport and its factory (integration-level), searchable stamps and `EnvelopeFinder`, expiration stamp + purge command, manual trigger action/URL flow, Doctrine message-bus hook (listener, envelope factory, stamps), retry strategy and self-aware retry listener, serializer decorators, auto-stamp listener, version-stop listener, entity traits, and the DI integration.

Gaps:

- `DoctrineEnvelopeEntityReference/EventListener/PropertyReferenceEncodingListener.php` — the most intricate listener in the package (reflection-based property nulling/restoration across ORM/ODM) has **no tests at all**.
- `Counter/CpuCounter.php` and `Searchable/TransportRepository.php` have no dedicated tests (the latter is exercised only incidentally).
- The `enforceUniqueness` delete path in `DrawTransport::cleanQueue()` is not covered by the transport integration tests (only the stamp getter is tested), which is exactly where the SQL-portability bug (M1) hides.
- No test covers the MySQL-specific delete branch of `DrawTransport::find()` against PostgreSQL semantics, nor timezone-sensitive behavior (M3).
