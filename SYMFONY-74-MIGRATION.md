# Symfony 7.4 migration analysis — `papendorf-it-services/rabbitmq-bundle`

Analysis date: 2026-09-04
Analysed revision: `47ab00c` ("Prepare for Symfony 7.x"), branch `master`
Compared against: upstream `php-amqplib/rabbitmq-bundle` **2.19.0** (`e2f5d3fd685cd53a5fba4601311106476b3f7233`, 2026-01-16)

Companion document: `D:\Development\PHP-Projects\Bundles\Pits\rabbitmq\FORK-MIGRATION-NOTES.md`
(written from the consuming bundle's perspective, against tag `V2.11.9`). This document verifies,
corrects and extends it against the *current* HEAD of this repo and the *current* upstream release.

**Revised 2026-09-07** against upstream `master` `56305f1` (2.19.0 + "Feature/cosmetics" #745,
2026-03-06) and against the actual consuming code. Corrections were folded into §6 (E-4, withdrawn),
§7 item 1, §11.2 (producer/consumer counts, and the trees are *not* identical) and §11.7 item 2 —
each marked inline. The per-enhancement implementation specs
derived from this analysis live in [`specs/`](specs/README.md); where they and this document
disagree on implementation detail, the specs are newer.

---

## 1. Verdict

**Re-fork upstream 2.19.0 and re-apply the three enhancements. Do not forward-port this tree.**
(E-1–E-3. E-4 was withdrawn on 2026-09-07 — see §6.)

The recommendation in `FORK-MIGRATION-NOTES.md` is correct. The evidence gathered here makes the case
stronger than that document states, for three reasons:

1. This tree does **not** currently work on Symfony 7 — the constraint widening in `47ab00c` is a
   false green light. There are two fatal-level blockers (§3).
2. Upstream has *already fixed both of them*, and its CI runs PHP 8.3 + Symfony 7.4 today (§4).
3. The largest and most-cited enhancement (E-3) turns out to be both unnecessary in its current form
   *and* incompatible with upstream 2.19.0's own new code (§6.3). Re-forking is the natural moment to
   correct that.

**Estimated effort: 2–4 days**, with the split shifted relative to the earlier estimate — less
porting, more test writing.

**Scope:** only **EnVi** consumes the migrated fork; Redbus stays on `2.11.*` and EnViSCPDaq is
obsolete. EnVi strictly requires only **E-1** — see §11 for the consumer audit, what that means for
E-2/E-3, and the two-fork-lines debt it creates.

---

## 2. Correction to the earlier baseline

`FORK-MIGRATION-NOTES.md` analysed tag `V2.11.9` (`2421f60`) and reported that every Symfony
constraint is capped at `^6.0`. That was accurate for the tag, but HEAD has moved:

| | |
|---|---|
| `git describe` | `V2.11.9-1-g47ab00c` |
| HEAD commit | `47ab00c` "Prepare for Symfony 7.x" — **not tagged** |
| What it changed | `composer.json` only: all `symfony/*` constraints widened to `^4.4\|^5.3\|^6.0\|^7.0` |
| Effective impact | None. `papendorf-it-services/rabbitmq: 2.11.*` still resolves to `V2.11.9`, i.e. the `^6.0` cap. |

`php` is still `^7.4|^8.0`. No source file was touched by that commit, so the runtime
incompatibilities below are all still present.

---

## 3. Hard Symfony 7 blockers in the current tree

Both are class-load-time fatal errors, not deprecations.

### 3.1 `ContainerAwareInterface` was removed in Symfony 7.0

`Command/BaseRabbitMqCommand.php:5,9`

```php
use Symfony\Component\DependencyInjection\ContainerAwareInterface;

abstract class BaseRabbitMqCommand extends Command implements ContainerAwareInterface
```

Verified: `src/Symfony/Component/DependencyInjection/ContainerAwareInterface.php` exists on branch
`6.4` and is **absent** on branch `7.4`. Deprecated in 6.4, removed in 7.0. Every command in this
bundle extends `BaseRabbitMqCommand`, so the whole `Command/` namespace fails to load.

Upstream's fix: drop the interface and add `DependencyInjection/Compiler/ServiceContainerPass.php`,
which walks `console.command`-tagged services and injects `service_container` via `addMethodCall`
where the class is a `BaseRabbitMqCommand`. Registered in `OldSoundRabbitMqBundle::build()`.

### 3.2 `Command::execute()` gained a `: int` return type in Symfony 7.0

Verified across branches:

| Symfony branch | Signature |
|---|---|
| 6.0 | `protected function execute(InputInterface $input, OutputInterface $output)` |
| 6.4 | `protected function execute(InputInterface $input, OutputInterface $output)` |
| **7.0** | `protected function execute(InputInterface $input, OutputInterface $output): int` |
| 7.4 | `protected function execute(InputInterface $input, OutputInterface $output): int` |

A child class that omits the return type where the parent declares one is a fatal
"Declaration ... must be compatible" error. Six classes in this tree are affected:

- `Command/BaseConsumerCommand.php:73`
- `Command/BatchConsumerCommand.php:59`
- `Command/DeleteCommand.php:36`
- `Command/PurgeConsumerCommand.php:36`
- `Command/RpcServerCommand.php:35`
- `Command/StdInProducerCommand.php:37`

(`Command/SetupFabricCommand.php:21` already has `: int` and is fine.)

### 3.3 What is *not* a problem

The fork strips `: void` from many methods relative to upstream. This looked like a broad risk; it
is not. Every interface involved is still return-type-free on Symfony 7.4:

| Interface / method | 7.4 signature |
|---|---|
| `ExtensionInterface::load()` | `public function load(array $configs, ContainerBuilder $container);` |
| `BundleInterface::build()` / `shutdown()` | `public function build(ContainerBuilder $container);` / `shutdown();` |
| `DataCollectorInterface::collect()` | `collect(Request $request, Response $response, ?\Throwable $exception = null);` |
| `DataCollectorInterface::getName()` | `public function getName();` |
| `CompilerPassInterface::process()` | `public function process(ContainerBuilder $container);` |
| `Command::configure()` / `initialize()` | no return type |

`DataCollector::$data` also still exists in 7.4 (`protected array|Data $data = []`), so
`MessageDataCollector`'s use of `$this->data` is valid — upstream's rename to a private `$messages`
is a cleanup, not a fix.

There are 7 implicit-nullable parameters in this tree (deprecated in PHP 8.4, not fatal):
`Command/BaseRabbitMqCommand.php:19`, `RabbitMq/BatchConsumer.php:93`,
`DataCollector/MessageDataCollector.php:24`, `RabbitMq/Consumer.php:236`,
`RabbitMq/BaseAmqp.php:62`, `RabbitMq/AMQPConnectionFactory.php:43`,
`RabbitMq/Producer.php:69`, `RabbitMq/Producer.php:85`. Upstream has already converted all of its
equivalents to `?Type`.

---

## 4. What a re-fork gives for free

Measured diff, HEAD vs upstream 2.19.0 (line endings normalised, `Tests/` excluded):

- 38 files differ; 4 files exist only here (CI, `.gitignore`, php-cs-fixer, scrutinizer config)
- ~606 changed lines total, of which ~71 are `README`/`composer.json`/`CHANGELOG`/`phpstan` →
  **~535 changed code lines, roughly 6% of the tree**
- Still no files added and none removed by the fork, exactly as the earlier analysis found

The bulk of that diff is style reversion (short array syntax → `array()`, `public const` → `const`,
`else if`, stripped `: void`, removed trailing commas) which disappears on re-fork at no cost. The
genuinely valuable content is E-1…E-3 (§6).

Beyond the two blockers in §3, these upstream improvements are **currently missing here** and would
arrive for free:

| Upstream feature | Files |
|---|---|
| `login_method` enum (`AMQPLAIN` / `PLAIN` / `EXTERNAL`) | `DependencyInjection/Configuration.php` |
| `channel_rpc_timeout` connection option | `Configuration.php`, `RabbitMq/AMQPConnectionFactory.php` |
| `options.no_ack` consumer support (`BaseAmqp::setConsumerOptions()`) wired through all 5 consumer types | `BaseAmqp`, `BaseConsumer`, `BatchConsumer`, `MultipleConsumer`, `Configuration`, `OldSoundRabbitMqExtension` |
| `ConsumerInterface::MSG_ACK_SENT` handling in `BatchConsumer::handleProcessFlag()` — this tree silently ACKs where upstream correctly does nothing | `RabbitMq/BatchConsumer.php` |
| `BeforeProducerPublishMessageEvent` / `AfterProducerPublishMessageEvent`, dispatched from `Producer::publish()` | `Event/`, `RabbitMq/Producer.php` |
| `AMQPEvent::getProducer()` / `setProducer()` | `Event/AMQPEvent.php` |
| `rabbitmq:setup-fabric --skip-anon-consumers` | `Command/SetupFabricCommand.php` |
| `SIGQUIT` signal handling | `Command/BaseConsumerCommand.php` |
| PHP 8.4-clean nullable parameter declarations throughout | many |
| `Tests/SetupFabricCommandTest.php`, `Tests/TestKernel.php` | `Tests/` |

### CI

| | This repo | Upstream 2.19.0 |
|---|---|---|
| Runner | `ubuntu-20.04` (retired) | `ubuntu-22.04` |
| PHP | 7.4, 8.0, 8.1 | 8.2, 8.3, 8.4 |
| Symfony | 4.4, 5.3, 5.4, 6.0 | 6.4, **7.4**, 8.0 |
| Actions | `checkout@v2`, `composer-install@v1` | current |

Upstream already has a green PHP 8.3 + Symfony 7.4 job. That is the single strongest argument for
re-forking rather than porting.

### Service configuration

Upstream replaced `Resources/config/rabbitmq.xml` with `Resources/config/services.yaml` and swapped
`XmlFileLoader` for `YamlFileLoader` in `OldSoundRabbitMqExtension::load()`. I diffed the two files
line by line: it is a **1:1 semantic translation** — same 19 parameters, same 12 services, same tags
and arguments. Take upstream's version; there is nothing to port.

---

## 5. What is *not* inherited

Two items from the earlier plan's §6 are fork-local work regardless of re-forking:

- **PHPUnit.** Upstream still requires `phpunit/phpunit: ^9.5` and ships a PHPUnit 9-schema
  `phpunit.xml.dist` (`backupStaticAttributes`, `convertErrorsToExceptions`, `<filter><whitelist>`).
  Migrating to PHPUnit 12 is ours to do in both this bundle and `Pits\rabbitmq`.
- **`psr/log`.** Upstream narrowed to `^2.0 || ^3.0`, dropping `^1.0`. Both this bundle and
  `Pits\rabbitmq` currently allow `^1.0` and must be narrowed to match.

---

## 6. The enhancements, re-assessed

E-1, E-2 and E-3 remain absent from upstream 2.19.0. Two need design changes. E-4 was withdrawn
on 2026-09-07 — it was never a fork change; see below.

> **Scope note (see §11).** Only **EnVi** is in scope for the migration; Redbus stays on `2.11.*` and
> EnViSCPDaq is obsolete. EnVi configures **no batch consumers**, so **E-1 is the only enhancement it
> strictly requires** — E-2 and E-3 are a costed choice rather than a requirement (§11.4). The
> recommendation is still to carry them, because §6.3's additive design makes E-3 cheap and it keeps
> Redbus's future migration path open.

### E-1 — Publisher confirms · **carry over, but rewire**

Files: `DependencyInjection/Configuration.php`, `DependencyInjection/OldSoundRabbitMqExtension.php`,
`RabbitMq/Producer.php`, `RabbitMq/ProducerInterface.php`.

This one is genuinely load-bearing. `Pits\RabbitMQ\MessageProducer::sendMessage()` consumes both the
boolean return value and the timeout-throws behaviour (`src/MessageProducer.php:68-79`).

Three changes to how it is re-applied:

**(a) Drop the constructor parameter; use a setter.** The current wiring is positional bookkeeping
against `Producer::__construct()`:

```php
// OldSoundRabbitMqExtension::loadProducers() — current fork
if ($this->collectorEnabled) {
    $this->injectLoggedChannel($definition, $key, $producer['connection']);
} else {
    $definition->addArgument(null);          // keep $ch slot occupied
}
...
if (isset($producer['confirm_select'])) {
    $definition->addArgument(null);          // $consumerTag
    $confirmSelect = boolval($producer['confirm_select']);
    $definition->addArgument($confirmSelect); // $confirmSelect
}
```

This works today only by accident: `confirm_select` has `defaultFalse()` in `Configuration`, so
`isset()` is *always* true after config processing, and the two extra arguments are always appended.
It also forces the `else { addArgument(null); }` branch, diverging from upstream's conditional
`injectLoggedChannel`.

Replace the whole thing with:

```php
$definition->addMethodCall('setConfirmSelect', [(bool) $producer['confirm_select']]);
$definition->addMethodCall('setConfirmationTimeout', [
    isset($producer['confirm_timeout']) ? (int) $producer['confirm_timeout'] : ($confirmSelect ? 10 : 0)
]);
```

`Producer::publish()` already re-initialises lazily (`if (!$this->initialized) { $this->initializeProducer(); }`),
so the constructor's call to `initializeProducer()` is redundant. Removing the constructor override
entirely means upstream's `injectLoggedChannel` path stays untouched and there is no positional
coupling left to break on the next upstream bump.

**(b) Preserve upstream's producer events.** The fork's `publish()` replaces the whole method body.
Upstream 2.19.0's version dispatches `BeforeProducerPublishMessageEvent` and
`AfterProducerPublishMessageEvent` around `basic_publish`, and logs `routingkey => $real_routingKey`.
Re-apply E-1 as an *insertion* into upstream's method — add the lazy re-init, the
`wait_for_pending_acks()` call after `basic_publish`, and the `return $this->acknowledged;` — keeping
both dispatches. Do not restore the fork's `'routingkeys' => $routingKey` log key (it logs the
pre-fallback value, which is misleading when the default routing key kicks in).

**(c) Add `isConfirmSelect()` to `ProducerInterface`.** This is a latent bug in the current
arrangement, not a new requirement. `MessageProducer::sendMessage()` does:

```php
// Pits\rabbitmq — src/MessageProducer.php:69-76
try {
    $published = $producer->publish($serializedMessage, ...);
} catch (AMQPTimeoutException $timeoutException) {
    if ($producer->isConfirmSelect()) {   // <-- $producer is typed ProducerInterface
```

`isConfirmSelect()` exists only on the concrete `Producer`, not on `ProducerInterface`. In sandbox
mode the container injects `RabbitMq\Fallback` for every producer, so this line is
`Error: Call to undefined method ...Fallback::isConfirmSelect()`. It has never fired because the
path requires an `AMQPTimeoutException` first. Fix on re-application: declare
`isConfirmSelect(): bool` on `ProducerInterface` and implement it on `Fallback` (returning `false`).

**Behavioural notes worth pinning in tests:**

- `wait_for_pending_acks(0)` is safe to call unconditionally. Verified against php-amqplib:
  `while (!empty($this->published_messages)) { $this->wait(...); }` — with confirms off,
  `published_messages` is empty and it returns immediately.
- On timeout it **throws** `AMQPTimeoutException`; it does not return false. This is exactly what
  `MessageProducer` catches, so the semantics are intentional and consumed. Document it.
- With confirms *on* and `confirmationTimeout = 0` it blocks indefinitely. That is why the extension
  defaults the timeout to 10s when `confirm_select` is true — keep that default.
- `$this->acknowledged` is never reset to `true` before a publish, and it is a single flag shared
  across messages. One NACK therefore sticks until the next ACK arrives. Decide deliberately whether
  to reset it at the top of `publish()`.

### E-2 — Batch-consumer event dispatching + working idle timeout · **carry over as-is**

File: `RabbitMq/BatchConsumer.php` (~114 changed lines, the largest single file).

Two independent pieces:

1. Dispatch `OnConsumeEvent`, `OnIdleEvent`, `BeforeProcessingMessageEvent`,
   `AfterProcessingMessageEvent`; track `protected ?\DateTime $lastActivityDateTime` with
   `getLastActivityDateTime()` / `setLastActivityDateTime()`, set on start and after each
   successful `wait()`.
2. Replace upstream's degenerate idle check. Upstream:
   ```php
   } elseif (null !== $this->getIdleTimeoutExitCode()) {
       return $this->getIdleTimeoutExitCode();
   ```
   Ours performs an actual elapsed-time check, dispatches `OnIdleEvent`, and honours `isForceStop()`:
   ```php
   } elseif ($this->getIdleTimeout()
       && ($this->getLastActivityDateTime()->getTimestamp() + $this->getIdleTimeout() <= $now)
   ) {
       $idleEvent = new OnIdleEvent($this);
       $this->dispatchEvent(OnIdleEvent::NAME, $idleEvent);
       if ($idleEvent->isForceStop()) {
           if (null !== $this->getIdleTimeoutExitCode()) {
   ```

This is the behaviour `Pits\RabbitMQ\EventListener\RabbitMQEventSubscriber::onIdle()` depends on —
it calls `$event->setForceStop(false)` to keep a systemd-watchdogged consumer alive across idle
periods. Without it, batch consumers exit on the first idle timeout.

Carry both pieces over verbatim, but re-apply them *onto* upstream's current method body so that
upstream's `MSG_ACK_SENT` branch in `handleProcessFlag()` survives.

### E-3 — `AMQPEvent` reshape · **carry over ADDITIVELY, not as a rename**

This is the item that changes most. As written, E-3 renames:

- `protected $AMQPMessage` → `protected $AMQPMessages`
- `getAMQPMessage()` / `setAMQPMessage(AMQPMessage $m)` → `getAMQPMessages()` / `setAMQPMessages(array $m)`
- and deletes upstream's `getProducer()` / `setProducer()`

**Problem: that rename is incompatible with upstream 2.19.0's own code.** Upstream's two new producer
events both call the singular setter:

```php
// Event/BeforeProducerPublishMessageEvent.php (and AfterProducerPublishMessageEvent.php)
public function __construct(Producer $producer, AMQPMessage $AMQPMessage, string $routingKey)
{
    $this->setProducer($producer);
    $this->setAMQPMessage($AMQPMessage);   // <-- deleted by E-3
    $this->routingKey = $routingKey;
}
```

Deleting `setAMQPMessage()` and `setProducer()` breaks both classes and the two dispatches in
`Producer::publish()`.

**And the rename buys nothing.** The only known consumer of these events is
`Pits\RabbitMQ\EventListener\RabbitMQEventSubscriber` (`src/EventListener/RabbitMQEventSubscriber.php:50-58`),
which subscribes to `BEFORE_PROCESSING_MESSAGE` and `ON_IDLE` and never reads the message at all —
`beforeProcessing()` only pings systemd; `onIdle()` pings, resets the logger, and calls
`setForceStop(false)`.

The only load-bearing part of E-3 is **widening the consumer type** so that `BatchConsumer` (which
`extends BaseAmqp implements DequeuerInterface` and is *not* a `Consumer`) can construct these
events at all. That is what makes E-2 possible.

**Re-apply as:**

| Change | Action |
|---|---|
| `AMQPEvent::$consumer` type, `getConsumer()`, `setConsumer()` | widen `Consumer` → `DequeuerInterface` |
| `OnConsumeEvent::__construct`, `OnIdleEvent::__construct` | widen `Consumer` → `DequeuerInterface` |
| `BeforeProcessingMessageEvent`, `AfterProcessingMessageEvent` | widen consumer to `DequeuerInterface`; accept `array $AMQPMessages` |
| `getAMQPMessage()` / `setAMQPMessage()` | **keep** (upstream's producer events need them) |
| `getAMQPMessages()` / `setAMQPMessages(array)` | **add** alongside |
| `getProducer()` / `setProducer()` | **keep** upstream's |
| `RabbitMq/Consumer.php` single-message callers | keep upstream's `new BeforeProcessingMessageEvent($this, $msg)` or pass `[$msg]` — decide once, consistently |
| `Compiler/InjectEventDispatcherPass.php` | no real change; skip (it is a `: void` removal plus an `@inheritDoc`) |

Net effect: E-3 shrinks from a breaking public-contract change (~59 lines, requiring an audit of
every application-level subscriber across all consuming projects) to roughly 20 additive lines with
no audit needed.

Suggested shape for the message accessors, keeping both contracts honest:

```php
/** @var AMQPMessage[] */
protected $AMQPMessages = [];

public function getAMQPMessage(): ?AMQPMessage
{
    return $this->AMQPMessages[array_key_first($this->AMQPMessages)] ?? null;
}

public function setAMQPMessage(AMQPMessage $AMQPMessage): self
{
    $this->AMQPMessages = [$AMQPMessage];

    return $this;
}

/** @return AMQPMessage[] */
public function getAMQPMessages(): array
{
    return $this->AMQPMessages;
}

/** @param AMQPMessage[] $AMQPMessages */
public function setAMQPMessages(array $AMQPMessages): self
{
    $this->AMQPMessages = $AMQPMessages;

    return $this;
}
```

### E-4 — `AMQPConnectionFactory` ordering · ~~**carry over**~~ **withdrawn — not a fork change**

> **Correction, 2026-09-07.** This entry was wrong. E-4 is not an enhancement, there is nothing to
> carry over, and the count of "four enhancements" used elsewhere in this document is really three.

`git diff 8ebaf0d..HEAD -- RabbitMq/AMQPConnectionFactory.php` is **empty**: the fork never touched
this file. What the earlier analysis read as a deliberate fork fix is simply the *older upstream*
ordering, frozen at the fork point.

Upstream changed it in `abe69ad` — "Fix for creating a stream context from a custom user parameters"
(#711) — and moved the `$parametersProvider` merge to run *before* the `ssl_context` processing:

```php
// upstream 2.19.0
if ($parametersProvider) {
    $this->parameters = array_merge($this->parameters, $parametersProvider->getConnectionParameters());
}

if (is_array($this->parameters['ssl_context'])) {
    $this->parameters['context'] = !empty($this->parameters['ssl_context'])
        ? stream_context_create(['ssl' => $this->parameters['ssl_context']])
        : null;
}
```

That is the intent of #711: a provider supplies `ssl_context`, and the factory builds `context` from
it. Restoring the fork's ordering would revert someone else's bugfix.

**What to do instead:** take upstream's version unchanged, and check one thing on the consuming side
— if any application supplies a ready-made `context` key from a
`ConnectionParametersProviderInterface`, it is now overwritten whenever `ssl_context` is also an
array. Such a provider must be changed to return `ssl_context` instead. Neither EnVi nor
`Pits\rabbitmq` defines a connection-parameters provider, so this is a check, not a task.

---

## 7. Fork-local behaviour changes: decide, don't inherit

| # | Change | Recommendation |
|---|---|---|
| 1 | `ProducerInterface::publish($routingKey = null)` + `Producer::publish()` using `!empty($routingKey)` instead of `$routingKey !== null`. Effect: an empty-string routing key falls back to `defaultRoutingKey` instead of publishing with an empty key. Mirrored in `RabbitMq/Fallback.php`. | **Corrected 2026-09-07: split it.** The `$routingKey = null` default and the documented `bool` return **are** needed — `MessageProducer::sendMessage()` is typed against `ProducerInterface`, branches on the return value, and passes a fourth `$headers` argument the interface does not declare. The `!empty()` fallback is **not** needed: no EnVi producer configures `default_routing_key`, so `defaultRoutingKey` is `''` and both conditions select the same value in every case. The earlier claim that "producers rely on the empty→default fallback" does not hold. Take the interface alignment, drop the behaviour change. See [`specs/04-producer-publish-contract.md`](specs/04-producer-publish-contract.md) §1.1. |
| 2 | `Producer::publish(..., array $headers = null)` — implicit nullable, deprecated in PHP 8.4. | Re-apply as `?array $headers = null`. This is the only implicit nullable you will be *adding*; upstream is otherwise clean. |
| 3 | `BatchConsumer::batchConsume()` renames `'Consumer requested stop'` → `'Consumer requested restart'` **and drops `$this->handleProcessMessages($e->getHandleCode());`**. | **Restore the dropped call** unless someone can point at the reason it was removed. Upstream still has it. Without it, a `StopConsumerException`'s handle code is discarded and the in-flight batch is neither acked nor rejected before `stopConsuming()`. The log rename is harmless — keep it if the wording matters operationally. |
| 4 | `RabbitMq/Fallback.php.bak` is committed. | Do not carry over. Confirmed still present at HEAD. |
| 5 | `Event/AMQPEvent.php` carries `use Symfony\Component\EventDispatcher\Event;` — a class removed in Symfony 5. | Harmless (unused import, never autoloaded), and it disappears on re-fork. |
| 6 | `phpstan.neon.dist` drops upstream's `HelperInterface::ask()` ignore line. | Take upstream's file wholesale. |

---

## 8. Re-fork procedure

1. Fork `php-amqplib/RabbitMqBundle` at tag **2.19.0** into `papendorf-it-services`
   (note the repo's actual casing — `php-amqplib/RabbitMqBundle`; the Packagist package name is
   lower-cased `php-amqplib/rabbitmq-bundle`).
2. Restore fork packaging in `composer.json`: `name`, `authors`, `license`, and the `replace` block
   for `oldsound/rabbitmq-bundle` + `emag-tech-labs/rabbitmq-bundle` (both `self.version`).
   **Fix the stale metadata while you are there:**
   - `extra.branch-alias.dev-master` currently says `1.10.x-dev`
   - `keywords` still say `symfony4` / `symfony5`
   - `CODEOWNERS` still lists `@mihaileu` from EmagTechLabs
3. **Adopt a version scheme that cannot collide with upstream's** (e.g. `2.19.0-pits.1`, or a
   distinct major). Upstream's 2.11 line stopped at **2.11.2**; this fork's `2.11.3`–`2.11.9` are
   fork-local releases with no upstream counterpart. That collision is what made this analysis
   necessary in the first place — do not repeat it.
4. Re-apply the enhancements in order: **E-1 → E-3 → E-2**. E-3 must land before E-2 (E-2
   constructs the widened events). E-1 is independent and is the only one EnVi strictly needs, so
   it makes the best first commit. Resolve the §7 decisions as you go. Per-enhancement
   implementation specs, including ready-to-paste PR text: [`specs/`](specs/README.md).
5. Add tests for the re-applied features. Neither this fork nor upstream has any coverage for
   publisher confirms or the batch idle-timeout path; both are testable and both are worth pinning.
   The event reshape *is* already covered (`Tests/Event/*Test.php` reference `getAMQPMessages`) —
   update those tests to assert both the singular and plural accessors.
6. Set constraints to `php: ^8.3`, `symfony/*: ^7.4`, `psr/log: ^3.0`, `phpunit/phpunit: ^12`,
   `phpstan/phpstan: ^2.1`, matching the rest of the bundle set. Migrate `phpunit.xml.dist` to the
   PHPUnit 10+ schema (`backupStaticAttributes`, `convertErrorsToExceptions`,
   `convertNoticesToExceptions`, `convertWarningsToExceptions` were all removed in PHPUnit 10;
   `<filter><whitelist>` → `<source><include>`).
7. Refresh CI: upstream's matrix is a good starting point, but narrow it to what we actually support
   (PHP 8.3/8.4 × Symfony 7.4) rather than carrying its 6.4 and 8.0 jobs.
8. Update `Pits\rabbitmq`'s `composer.json` to the new fork version and run its own Symfony 7.4
   migration (see `FORK-MIGRATION-NOTES.md` §6 — that bundle is 5 src files / 507 lines with
   decent test coverage, and is otherwise straightforward). Remember to fix
   `MessageProducer::sendMessage()`'s `isConfirmSelect()` call per §6 E-1(c) — or rely on the
   interface addition, which resolves it without touching the caller.

---

## 9. Test coverage baseline

Both trees ship 23–25 test files. Upstream has everything this fork has, plus
`Tests/SetupFabricCommandTest.php` and `Tests/TestKernel.php`.

Existing coverage relevant to the enhancements:

| Enhancement | Covered today? |
|---|---|
| E-1 publisher confirms | **No.** No `ProducerTest` in either tree. |
| E-2 batch consumer events / idle timeout | **No.** No `BatchConsumerTest` in either tree. |
| E-3 event reshape | Partially — `Tests/Event/{Before,After}ProcessingMessageEventTest.php`, `Tests/Event/OnIdleEventTest.php`, `Tests/RabbitMq/ConsumerTest.php` reference the plural API. |
| ~~E-4 connection factory ordering~~ | Withdrawn — not a fork change (§6). Take upstream's `Tests/RabbitMq/AMQPConnectionFactoryTest.php` as-is. |

Minimum new tests worth writing:

- `Producer::publish()` returns `true` on ACK, `false` after NACK, and propagates
  `AMQPTimeoutException` when `confirm_timeout` elapses
- `Producer` with `confirm_select: false` does not block (asserts `wait_for_pending_acks` is a no-op)
- `OldSoundRabbitMqExtensionTest`: `confirm_select` / `confirm_timeout` produce the expected
  `setConfirmSelect` / `setConfirmationTimeout` method calls, and the 10s default when
  `confirm_select` is true with no explicit timeout
- `BatchConsumer` dispatches `OnIdleEvent` after `idle_timeout` elapses, and continues consuming when
  a subscriber calls `setForceStop(false)`
- `AMQPConnectionFactory`: a `ConnectionParametersProviderInterface` can override the `context` key
  computed from `ssl_context`

---

## 10. Working artefacts

The upstream 2.19.0 tree and the full normalised diff used for this analysis are at:

```
D:\Development\PHP-Projects\Bundles\RabbitMQBundle\_upstream-compare\
    2.19.0\php-amqplib-RabbitMqBundle-e2f5d3f\   # extracted upstream tree
    full.diff                                    # diff -ru, line endings normalised, Tests\ excluded
```

Kept for the re-application work; safe to delete afterwards. Note that the upstream zipball omits
`Tests/`, `.github/` and `phpunit.xml.dist` (they are `export-ignore`d), so those were inspected via
the GitHub API rather than the extracted tree.

---

## 11. Consumer audit and migration scope

*Added 2026-09-04, from the consuming-project side. This section does not change §3 (the blockers),
§4 (what a re-fork gives for free), §5 or §8 — all of which stand. It refines §6's original "all
four must be carried over" by establishing **which consumers are actually in scope**, and it
converts E-2/E-3 from a requirement into a costed choice. §7 item 1 and §11.2 were corrected on
2026-09-07; E-4 was withdrawn the same day, so §6 now covers three enhancements, not four.*

### 11.1 Scope decision

The fork is consumed by three application families. **Only EnVi is in scope.**

| Consumer | Status | Needs from this fork |
|---|---|---|
| **EnVi** | **in scope** — migrating to Symfony 7.4 in parallel | **E-1 only** |
| **Redbus** | out of scope — stays on `2.11.*` | E-1 **and** E-2/E-3 |
| **EnViSCPDaq** | out of scope — **obsolete**, will not be migrated | (E-1 only, had it mattered) |

Chain to migrate: **`core-bundle` → this fork → `Pits\rabbitmq` → EnVi.**

A completed tree-wide scan (1,491 hits) found AMQP-event / batch-consumer references in exactly four
trees: `Redbus` (856), `EnVi` (352), `EnViSCPDaq` (155), `Bundles` (128). `PortalAPI`, `Requeue`,
`PV.Analyzer`, `SC.Portal`, `SCC4`, `Thing/*` and `sonnendreher` have none.

### 11.2 EnVi configures no batch consumers

This is the finding that matters for §6. EnVi's `config/packages/old_sound_rabbit_mq.yaml` has
exactly three sections in every tree:

```yaml
old_sound_rabbit_mq:
    connections:        # default
    producers:          # some with confirm_select: true, confirm_timeout: 5
    dynamic_consumers:  # each with idle_timeout: 20
```

> **Correction, 2026-09-07.** The earlier revision called the four configs "identical" and gave
> `13` producers / `11` dynamic consumers / `confirm_timeout: 2`. Re-counted from the YAML, they
> differ per tree and the timeout is `5`:
>
> | Tree | producers | dynamic_consumers | `confirm_select: true` |
> |---|---|---|---|
> | `Development/1.10.1` | 16 | 9 | 3 |
> | `Development/GitLab/1.10.1` | 16 | 9 | 3 |
> | `Merge` | 17 | 10 | 2 |
> | `Releases/1.10.1` | 18 | 10 | 3 |
>
> The producers carrying confirms in `Development/1.10.1` are `assessment`, `delay` and
> `event_bus`; `Merge` lacks one of them. This sharpens §11.7 item 1 — the trees are not
> interchangeable, so the migration target has to be named before the config is treated as known.

No `batch_consumers:` key and no `consumers:` key in any tree, and no `default_routing_key` on any
producer. Confirmed against the compiled container
(`EnVi/Development/1.10.1/var/cache/dev/App_KernelDevDebugContainer.xml`): **zero batch consumer
instances**. `old_sound_rabbit_mq.batch_consumer_command` and the `batch_consumer.class` parameter
do appear, but the bundle registers those unconditionally — not evidence of use. There is no
`BatchConsumerInterface` implementation anywhere in EnVi.

### 11.3 For EnVi, the idle-timeout behaviour already comes from upstream

§6 E-2 states that `RabbitMQEventSubscriber::onIdle()` depends on the fork's idle-timeout work, and
that "without it, batch consumers exit on the first idle timeout". That is correct **for batch
consumers**. It does not apply to EnVi, because EnVi has none.

EnVi runs `DynamicConsumer extends Consumer extends BaseConsumer`, and **upstream 2.19.0's `Consumer`
already contains the exact idle-timeout implementation that E-2 adds to `BatchConsumer`** —
`setLastActivityDateTime()`, the elapsed-wall-clock guard, the `OnIdleEvent` dispatch and the
`isForceStop()` veto:

```php
// upstream 2.19.0 RabbitMq/Consumer.php — unchanged by this fork
$this->getChannel()->wait(null, false, $waitTimeout);
$this->setLastActivityDateTime(new \DateTime());
} catch (AMQPTimeoutException $e) {
    $now = time();
    if ($this->gracefulMaxExecutionDateTime && ...) {
        return $this->gracefulMaxExecutionTimeoutExitCode;
    } elseif ($this->getIdleTimeout()
        && ($this->getLastActivityDateTime()->getTimestamp() + $this->getIdleTimeout() <= $now)
    ) {
        $idleEvent = new OnIdleEvent($this);
        $this->dispatchEvent(OnIdleEvent::NAME, $idleEvent);
        if ($idleEvent->isForceStop()) {
            if (null !== $this->getIdleTimeoutExitCode()) {
                return $this->getIdleTimeoutExitCode();
            } else {
                throw $e;
            }
        }
    }
}
```

So **E-2 was never a novel feature** — it is a port of upstream's `Consumer` idle logic into
`BatchConsumer`, which upstream never instrumented.

Traced on plain upstream 2.19.0 with EnVi's actual config (`idle_timeout: 20`, and neither
`keep_alive` nor `idle_timeout_exit_code` set anywhere):

1. Socket times out with ≥ 20 s elapsed since the last message → `OnIdleEvent` dispatched.
2. `RabbitMQEventSubscriber::onIdle()` pings the systemd watchdog, resets the logger, calls
   `$event->setForceStop(false)`.
3. `isForceStop()` is now false → no throw, loop continues.

Identical to today's behaviour. `setForceStop()` is upstream API, so the watchdog-keepalive pattern
survives untouched.

### 11.4 E-2/E-3 are now a costed choice, not a requirement

Two conclusions pull in opposite directions, and both are right:

- **This section:** EnVi does not need E-2, and therefore does not need E-3 either (E-3 exists to let
  `BatchConsumer` construct the events — §6.3).
- **§6.3:** re-applied *additively* rather than as a rename, E-3 costs roughly **20 lines with no
  subscriber audit** — far less than the breaking-rename version the earlier analysis assumed.

That second point materially changes the trade-off. **Recommendation: carry E-2 and E-3 anyway**,
using §6.3's additive design:

- E-3 additive is ~20 lines and introduces no public-contract break, so the recurring rebase cost is
  near zero.
- E-2 is self-contained in `BatchConsumer`, a class EnVi never instantiates — so it carries no risk
  to the in-scope consumer.
- It keeps **Redbus's** migration path open. Redbus needs E-2 for real: 5 batch consumers with
  `idle_timeout: 20` across `Extractor`, `PortalConnector` and `RedbusHandler`, plus 10+
  `BatchConsumerInterface` implementations. If E-2/E-3 are dropped now, they must be rebuilt when
  Redbus is eventually migrated — against whatever upstream looks like then.

If instead you want the smallest possible fork, dropping E-2/E-3 is **safe for EnVi** and reduces the
payload to ~90 lines across 4 files (E-1 only — E-4 no longer exists). In that case:

- `Bundles/CoreBundle/{main,doctrine}/Tests/EventListener/RabbitMQEventSubscriberTest.php:51` mocks
  `DequeuerInterface` and would need to mock `Consumer` instead — a one-line fix.
- The `Tests/Event/*Test.php` updates in §8 step 5 become unnecessary.

Either way, **E-1 is mandatory**: `confirm_select` / `confirm_timeout` are on EnVi's producers, and
`Pits\RabbitMQ\MessageProducer::sendMessage()` consumes both the boolean return and the
timeout-throws behaviour. §6 E-1(a)–(c) is the right way to re-apply it.

### 11.5 Two fork lines will coexist — a deliberate debt

Redbus staying on `2.11.*` while EnVi moves means:

- The **2.11 line becomes frozen legacy** — still on Symfony `^6.0` / PHP `^7.4|^8.0`, carrying
  E-1–E-3, receiving nothing from upstream, and still exposed to the §3 blockers should anyone try
  to move it.
- **Redbus cannot simply adopt the new fork later** unless E-2/E-3 are present in it — see §11.4.
  This is the strongest practical argument for carrying them now.
- Version ranges must not collide. Redbus pins `2.11.*`, so anything outside that range is safe;
  §8 step 3's advice applies with extra force.
- Security patching of the frozen line is nobody's job by default. Accept that consciously, or plan
  Redbus's migration rather than deferring it indefinitely.
- **Keep this document and the §10 diff artefacts** rather than deleting them once EnVi ships — they
  are the input to Redbus's eventual migration.

### 11.6 Corroborations and corrections

Independently confirmed from the consuming side, and consistent with this document:

- No files added or removed by the fork; `RabbitMq/Fallback.php.bak` is committed (§7 item 4).
- All enhancements absent from upstream 2.19.0 — but see §6: E-4 was never one of them, so the
  real count is three, not four.
- `BatchConsumer extends BaseAmqp implements DequeuerInterface` — not a `Consumer` — which is why the
  event widening is a prerequisite for E-2 (§6.3).
- Nothing outside this fork's own sources calls `getAMQPMessage()` / `getAMQPMessages()`. Every
  `RabbitMQEventSubscriber` variant (`Pits\rabbitmq`, both `CoreBundle` checkouts, Redbus) is
  message-agnostic. The `getAMQPMessage` hits in
  `EnVi/Development/*/tests/functional/Consumer/EnviCest.php` are a local Codeception fixture helper
  of the same name, not the event API.

Corrections to `FORK-MIGRATION-NOTES.md`, which this document supersedes on both points:

- Its diff figures (~280 changed lines, ~3% of the tree, 29 files) were measured against tag
  `V2.11.9` vs upstream **2.11.2**. §4's figures — ~606 changed lines, ~535 code lines, ~6%, 38 files,
  measured at HEAD vs upstream **2.19.0** — are the correct baseline for the re-fork work.
- It states the fork is not checked out locally. It is: this repo,
  `D:\Development\PHP-Projects\Bundles\RabbitMQBundle\rabbitmq-bundle`.

### 11.7 Open items

1. **Which EnVi tree is the migration target.** `Merge`, `Releases/1.10.1`,
   `Development/GitLab/1.10.1` and `Development/1.10.1*` all carry the fork; `Trunk` and everything
   ≤ `1.9.2` are still on upstream `php-amqplib/rabbitmq-bundle: ^2.6`. The producer sets differ
   slightly between trees (`Merge` shows 2 × `confirm_select`, the others 3 ×).
2. ~~**Verify against deployed configuration**~~ — **partially closed 2026-09-07.** Re-checked
   across all four trees' `config/packages/old_sound_rabbit_mq.yaml`: no `keep_alive`, no
   `idle_timeout_exit_code`, no `batch_consumers`, no `default_routing_key`. The §11.3 trace holds.
   **Still open:** this was verified against the *checkouts* only. The deployed configuration —
   including environment overrides and anything under `config/packages/{env}/` — has not been
   inspected.
3. **Whether to upstream E-1.** Publisher confirms is a general AMQP feature, small and additive, with
   opt-in config defaulting to off. If `php-amqplib/RabbitMqBundle` accepts it, EnVi could depend on
   upstream directly and this fork would no longer be needed for EnVi at all. Worth checking for an
   existing upstream issue or PR before committing to the re-fork. Note this only works if E-2/E-3 are
   *not* required — see §11.4.
