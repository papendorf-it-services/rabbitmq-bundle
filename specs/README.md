# Upstream PR specs

Four specs, one per enhancement that exists in `papendorf-it-services/rabbitmq-bundle`
but not in `php-amqplib/RabbitMqBundle`. Each spec is a self-contained PR:
motivation, design, full code changes, tests, documentation text and a ready-to-paste
PR description.

| # | Spec | Upstream PR | Depends on | BC | Needed by EnVi |
|---|------|-------------|------------|----|----------------|
| 1 | [Publisher confirms](01-publisher-confirms.md) | feature, additive | — | none | **yes, mandatory** |
| 2 | [AMQPEvent: dequeuer + message collections](02-amqpevent-dequeuer-support.md) | refactor, additive | — | none (additive by design) | nice-to-have |
| 3 | [BatchConsumer events + idle timeout](03-batch-consumer-events.md) | feature | #2 | none | no |
| 4 | [`publish()` return + routing-key contract](04-producer-publish-contract.md) | interface alignment + behaviour change | #1 (part A) | part A none, part B/C yes | **parts A + C yes**, part B no |

## Consumer relevance

Audited 2026-09-07 against the EnVi checkouts (`Development/1.10.1`, `Development/GitLab/1.10.1`,
`Merge`, `Releases/1.10.1` — 16/16/17/18 producers, the trees are not identical),
`Bundles/Pits/rabbitmq` and `Bundles/CoreBundle`. Verification is against the checkouts, not
against deployed configuration.

* **Spec 1 — mandatory.** Three of EnVi's producers (`assessment`, `delay`, `event_bus`; two in the
  `Merge` tree) set `confirm_select: true` / `confirm_timeout: 5`, and
  `Pits\RabbitMQ\MessageProducer::sendMessage()` consumes both the boolean return and the
  timeout-as-exception behaviour. See spec §3.6 for the one caller-side line this needs.
* **Spec 4 parts A and C — needed.** `MessageProducer` passes four arguments to a
  `ProducerInterface` and branches on the return value; the interface declares neither.
* **Spec 4 part B — not needed.** No EnVi producer configures `default_routing_key`, so the
  empty-key fallback is a no-op there.
* **Spec 2 — nice-to-have.** Without it, `CoreBundle/{main,doctrine}/Tests/EventListener/
  RabbitMQEventSubscriberTest.php` (lines 41 and 51 in both) must mock `Consumer` instead of
  `DequeuerInterface`. No production code is affected — `DequeuerInterface` and `getAMQPMessage()`
  appear nowhere else in the consuming bundles.
* **Spec 3 — not needed by EnVi**, which configures no batch consumers and gets its idle-timeout
  behaviour from upstream's `Consumer`. It is needed by **Redbus** (5 batch consumers, 10+
  `BatchConsumerInterface` implementations), which is out of scope for the current migration but
  will need a fork line that has it.

**Consequence:** if specs 1 and 4A/4C are accepted upstream, EnVi no longer needs a fork at all.
Specs 2 and 3 are then only worth carrying as groundwork for Redbus.

## Baseline

All diffs are against `php-amqplib/RabbitMqBundle` **`master` @ `56305f1`**
(release `2.19.0` + "Feature/cosmetics" #745), i.e. PHP `^8.2`, Symfony `^6.0 || ^7.0 || ^8.0`,
service config in `Resources/config/services.yaml`, test suite on **Pest**
(`uses(\PHPUnit\Framework\TestCase::class)->in('Tests')`, so tests are Pest closures with the
PHPUnit `TestCase` API available via `$this`).

## Suggested merge order

```
1  publisher confirms          (independent, highest value, lowest risk)
2  AMQPEvent widening          (independent, mechanical)
3  batch consumer events       (needs 2)
4  publish() contract          (needs maintainer buy-in; keep fork-local if rejected)
```

## Fork provenance

| Spec | Fork commits |
|------|--------------|
| 1 | `9a9f79a` Confirm select · `fc5749a` Constructor parameters corrected · `f86bf08` Default confirmation timeout · `44fed67` Added getter for confirm select · `c27bdff` No initialization without connection |
| 2 | `edb325a` / `b9318a6` Call #4925 (Event-Teil) |
| 3 | `edb325a` / `b9318a6` Call #4925 (BatchConsumer-Teil) |
| 4 | `f0bfed7` Issue #660 · `3ff9883` Fallback-Producer |

Explicitly **not** part of any spec (fork-local noise, see the migration analysis):
`array()`/`else if`/`const` style reverts, the removed `MSG_ACK_SENT` branch, the removed
`handleProcessMessages($e->getHandleCode())` call, the `Consumer requested stop` → `restart`
log rename, `RabbitMq/Fallback.php.bak`, and the committed `.idea/` files.
