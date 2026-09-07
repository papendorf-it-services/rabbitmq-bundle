# Spec 4 — `publish()`: return contract and routing-key fallback

| | |
|---|---|
| **Target** | `php-amqplib/RabbitMqBundle`, base `master` @ `56305f1` (2.19.0) |
| **Branch** | `feature/producer-publish-contract` |
| **Type** | Interface alignment (part A) + **behaviour change** (part B) |
| **BC break** | Part A: none. Part B: **yes**, see §4. |
| **Depends on** | [Spec 1 — Publisher confirms](01-publisher-confirms.md) for the `@return bool` to mean anything |
| **Fork origin** | `f0bfed7` (Issue #660), `3ff9883` (Fallback producer) |
| **Consumer relevance** | EnVi: parts **A and C needed**, part **B inert** — see §1.1. |

> **Read this first.** This spec is split into three independently mergeable parts because they
> differ sharply in both value and risk. Parts A and C are wanted by real calling code; part B is
> the only one with a compatibility cost, and — per the consumer audit in §1.1 — is not actually
> needed by the in-scope consumer. Land A and C; treat B as optional.

---

## 1. The three parts

| Part | Change | Risk | Wanted by a known caller | Depends on |
|---|---|---|---|---|
| **A** | `ProducerInterface::publish()` documents a `bool` return; `$routingKey` default `''` → `null` in the interface and in `Fallback` | none | **yes** | Spec 1 |
| **B** | `Producer::publish()` treats an empty-string routing key as "not given" and falls back to `default_routing_key` | **behaviour change** | **no** — see §1.1 | — |
| **C** | `ProducerInterface` and `Fallback` gain the `?array $headers = null` parameter `Producer` already has | BC for implementors | **yes** | — |

### 1.1 What the consuming code actually needs

Verified against `Bundles/Pits/rabbitmq/src/MessageProducer.php` and the EnVi RabbitMQ
configuration (`config/packages/old_sound_rabbit_mq.yaml` in `Development/1.10.1`,
`Development/GitLab/1.10.1`, `Merge` and `Releases/1.10.1` — 16/16/17/18 producers respectively;
the trees are not identical):

```php
$published = $producer->publish($serializedMessage, $envelope->getRoutingKey(), $additionalProperties, $headers);
...
if (!$published) {
    throw new MessageNotSentException("Message could not be published!");
}
```

* **Part A is load-bearing.** `$producer` is typed `ProducerInterface`, and the return value decides
  whether the caller throws. The interface documents no return value today.
* **Part C is load-bearing too.** That call passes **four** arguments to a `ProducerInterface`.
  It only works because PHP tolerates surplus arguments on userland methods; in sandbox mode the
  `Fallback` receives the headers and drops them on the floor, and static analysis against the
  interface never sees the parameter at all. This is a stronger case than "tidy-up", which is how
  part C was originally framed here.
* **Part B is inert for this caller.** Not one configured producer in any of the four trees sets
  `default_routing_key`, so `$this->defaultRoutingKey` is `''` throughout. With an empty default,
  `!empty($routingKey)` and `$routingKey !== null` select the same value in every case. The
  behaviour change buys nothing here and costs the compatibility risk described in §4.3.

  (This corrects the claim in `SYMFONY-74-MIGRATION.md` §7 item 1 that "producers rely on the
  empty→default fallback". They cannot — no default is configured.)

---

## 2. Part A — the interface contract

### 2.1 Problem

Three signatures for the same method disagree:

```php
// ProducerInterface
public function publish($msgBody, $routingKey = '', $additionalProperties = []);

// Producer
public function publish($msgBody, $routingKey = null, $additionalProperties = [], ?array $headers = null)

// Fallback
public function publish($msgBody, $routingKey = '', $additionalProperties = [])
{
    return false;
}
```

`Producer` defaulted `$routingKey` to `null` some time ago — that is what makes
`default_routing_key` work at all, since `''` is a legitimate routing key that must not be
overridden. The interface and the sandbox `Fallback` were never updated, so the interface documents
a default the only real implementation does not use.

`Fallback::publish()` also already returns `false` while the interface documents no return value at
all — so the "returns a bool" contract is de facto in the codebase, just undeclared. Spec 1 makes
`Producer` return one too.

### 2.2 Change

```diff
--- a/RabbitMq/ProducerInterface.php
+++ b/RabbitMq/ProducerInterface.php
 interface ProducerInterface
 {
     /**
      * Publish a message
      *
      * @param string $msgBody
-     * @param string $routingKey
+     * @param string|null $routingKey Null uses the producer's configured default_routing_key.
      * @param array $additionalProperties
+     *
+     * @return bool False when the message is known not to have been accepted — the broker nacked
+     *              it, or the producer is a sandbox fallback. True otherwise.
      */
-    public function publish($msgBody, $routingKey = '', $additionalProperties = []);
+    public function publish($msgBody, $routingKey = null, $additionalProperties = []);
 }
```

```diff
--- a/RabbitMq/Fallback.php
+++ b/RabbitMq/Fallback.php
 class Fallback implements ProducerInterface
 {
-    public function publish($msgBody, $routingKey = '', $additionalProperties = [])
+    public function publish($msgBody, $routingKey = null, $additionalProperties = [])
     {
         return false;
     }
 }
```

### 2.3 Why this is not a BC break

PHP does not check default values when matching an implementation against an interface, so every
third-party `ProducerInterface` implementation keeps working unchanged. The only observable
difference is for a caller that relies on `publish($body)` reaching an implementation as `''`
rather than `null` — and the bundle's own implementation already receives `null` there today.

---

## 3. Part C — the `$headers` parameter

`Producer::publish()` takes a fourth `?array $headers = null` argument; `ProducerInterface` and
`Fallback` declare three. PHP tolerates the extra argument at the call site, so nothing breaks
today, but a caller that types against `ProducerInterface` gets no static support for headers, and
in sandbox mode the argument silently vanishes into `func_get_args()`.

This is not hypothetical: `Pits\RabbitMQ\MessageProducer::sendMessage()` passes four arguments to a
`ProducerInterface`-typed producer on every publish (§1.1). Every message it sends carries headers
(`ID`, and `message-class` / `priority` / `delay-count` where applicable), so with a sandbox
`Fallback` in place all of that is discarded without a diagnostic.

```diff
-    public function publish($msgBody, $routingKey = null, $additionalProperties = []);
+    public function publish($msgBody, $routingKey = null, $additionalProperties = [], ?array $headers = null);
```

and the same in `Fallback`.

**This one is a genuine, if small, BC break for implementors**: an existing third-party
`ProducerInterface` implementation declaring three parameters no longer satisfies a four-parameter
interface, and PHP raises a fatal error at class-declaration time. Include it only if the
maintainers are willing to note it under "Warning - BC Breaking Changes" in the README; otherwise
drop part C, or defer it to the next major.

---

## 4. Part B — empty routing key falls back to the default

### 4.1 Change

```diff
--- a/RabbitMq/Producer.php
+++ b/RabbitMq/Producer.php
-        $real_routingKey = $routingKey !== null ? $routingKey : $this->defaultRoutingKey;
+        $real_routingKey = !empty($routingKey) ? $routingKey : $this->defaultRoutingKey;
```

### 4.2 Motivation

Application code that funnels every publish through one wrapper typically does something like

```php
$producer->publish($payload, $envelope->getRoutingKey());
```

where `getRoutingKey()` returns `''` for messages that carry no routing information. Today that
publishes with an empty routing key and the producer's configured `default_routing_key` is
silently ignored — the option only ever applies when the caller passes literally nothing. Callers
end up re-implementing the fallback:

```php
$producer->publish($payload, $envelope->getRoutingKey() ?: null);
```

Treating `''` and `null` alike makes `default_routing_key` behave the way its name implies.

### 4.3 Why this is a real break

An empty routing key is meaningful in AMQP. For a `direct` exchange, `''` is a distinct binding
key; for the default exchange, the routing key is the queue name and `''` addresses nothing. Any
user who has configured `default_routing_key` **and** deliberately publishes with `''` gets their
messages routed somewhere else after this change — silently, with no error.

`!empty()` also swallows `'0'`, which is a legal routing key. If part B is accepted, the condition
should be written to catch only the empty string:

```php
$real_routingKey = ($routingKey === null || $routingKey === '')
    ? $this->defaultRoutingKey
    : $routingKey;
```

which is what the fork *meant*, and is strictly better than `!empty()`.

### 4.4 No known caller needs it

Per §1.1, the in-scope consumer configures no `default_routing_key` at all, which makes this change
a no-op for it. Part B is therefore a speculative improvement, not a requirement — weigh it purely
on whether the maintainers think `default_routing_key` *should* apply to an empty key, not on
downstream need.

### 4.5 The safer alternative

Rather than changing the meaning of `''` for everyone, make it opt-in:

```yaml
producers:
    upload_picture:
        default_routing_key:          'fallback.key'
        default_routing_key_on_empty: true   # default false — preserves today's behaviour
```

More surface area, zero risk. Recommended if the maintainers hesitate on part B. If they hesitate
on the option too, drop part B upstream and keep the one-line change in the fork.

---

## 5. Tests

`Tests/RabbitMq/ProducerTest.php` is introduced by Spec 1; these cases are appended to it.

```php
test('a null routing key falls back to the configured default', function () {
    $this->producer->setDefaultRoutingKey('default.key');

    $this->channel->expects($this->once())
        ->method('basic_publish')
        ->with($this->anything(), 'test_exchange', 'default.key');

    $this->producer->publish('body');
});

test('an explicit routing key wins over the default', function () {
    $this->producer->setDefaultRoutingKey('default.key');

    $this->channel->expects($this->once())
        ->method('basic_publish')
        ->with($this->anything(), 'test_exchange', 'explicit.key');

    $this->producer->publish('body', 'explicit.key');
});

// Part B only
test('an empty routing key falls back to the configured default', function () {
    $this->producer->setDefaultRoutingKey('default.key');

    $this->channel->expects($this->once())
        ->method('basic_publish')
        ->with($this->anything(), 'test_exchange', 'default.key');

    $this->producer->publish('body', '');
});

// Part B only — the '0' guard
test('a "0" routing key is not treated as empty', function () {
    $this->producer->setDefaultRoutingKey('default.key');

    $this->channel->expects($this->once())
        ->method('basic_publish')
        ->with($this->anything(), 'test_exchange', '0');

    $this->producer->publish('body', '0');
});

// Parts A and C
test('the sandbox fallback producer reports failure', function () {
    $fallback = new \OldSound\RabbitMqBundle\RabbitMq\Fallback();

    expect($fallback->publish('body'))->toBeFalse();
});
```

The first two cases pin today's behaviour and must pass **before** the change as well — they are
the guard that part B does not alter the `null` and explicit-key paths.

---

## 6. Documentation

### 6.1 `README.md` — `### Producer ###`

Amend the paragraph about `publish()`'s optional routing key:

    Besides the message itself, `OldSound\RabbitMqBundle\RabbitMq\Producer#publish()` also accepts
    an optional routing key parameter and an optional array of additional properties. Passing
    `null` (or omitting it) uses the producer's `default_routing_key`. It returns `false` when the
    message is known not to have been accepted — see [Publisher Confirms](#publisher-confirms) —
    and `true` otherwise.

For part B, add:

    An empty-string routing key is treated the same as `null` and also falls back to
    `default_routing_key`.

### 6.2 `README.md` — `### Warning - BC Breaking Changes ###`

Required for part B, and for part C if included.

### 6.3 `CHANGELOG`

```diff
+- 2026-xx-xx
+    * `ProducerInterface::publish()` documents its `bool` return and defaults `$routingKey` to
+      `null`, matching `Producer`.
+    * BC: `Producer::publish()` now treats an empty-string routing key like a missing one and
+      falls back to `default_routing_key`. Pass a routing key explicitly if you rely on
+      publishing with an empty one.
```

---

## 7. PR description (ready to paste)

> **Title:** Align the `publish()` contract across `ProducerInterface`, `Producer` and `Fallback`
>
> ### What
>
> Three separate, independently revertable commits:
>
> 1. **Interface alignment (no BC impact).** `ProducerInterface::publish()` documents the `bool`
>    return that `Fallback` already provides and Spec 1's `Producer` now provides, and defaults
>    `$routingKey` to `null` to match `Producer`. Same for `Fallback`.
> 2. **Empty routing key falls back to the default (BC).** `publish($body, '')` now uses
>    `default_routing_key` instead of publishing with an empty key.
> 3. **`$headers` on the interface (BC for implementors).** Brings the interface and `Fallback` up
>    to `Producer`'s four-parameter signature.
>
> Commits 1 and 3 close a gap real calling code already runs into: code typed against
> `ProducerInterface` passes four arguments and branches on the return value, neither of which the
> interface declares. Commit 2 is the speculative one — happy to drop it.
>
> ### Why
>
> The three implementations of one method currently disagree on both the default and the return
> value. For 2: `default_routing_key` today only applies when the caller passes literally nothing,
> which means application code that always forwards a possibly-empty routing key has to
> re-implement the fallback with `?: null` at every call site.
>
> ### Backwards compatibility
>
> Commit 1 is inert — PHP does not check defaults when matching an implementation to an interface.
>
> Commit 2 changes behaviour for anyone who sets `default_routing_key` *and* deliberately publishes
> with an empty routing key. I have written it as an explicit `=== ''` check rather than
> `!empty()`, so `'0'` stays a valid routing key. If you would rather not change the meaning of
> `''` at all, I can put it behind a `default_routing_key_on_empty` producer option defaulting to
> `false`.
>
> Commit 3 is a fatal error for any third-party `ProducerInterface` implementation with a
> three-parameter `publish()`. Only worth taking with a README "BC Breaking Changes" entry, or
> deferred to the next major.

---

## 8. Acceptance criteria

- [ ] `publish($body)` with `default_routing_key` set publishes with the default — before and after.
- [ ] `publish($body, 'explicit')` publishes with `'explicit'` — before and after.
- [ ] Part B: `publish($body, '')` publishes with the default.
- [ ] Part B: `publish($body, '0')` publishes with `'0'`.
- [ ] `Fallback::publish()` returns `false` for every argument shape the interface allows.
- [ ] Part C: a three-parameter third-party implementation is called out in the README BC section.
- [ ] `vendor/bin/pest` green; `phpstan` clean; `php-cs-fixer` clean.

## 9. Fallback plan

If part B is rejected, drop it — §1.1 establishes that no known caller depends on it, so there is
nothing to carry fork-local. Should a future caller need it, the change does not require a fork at
all; a thin subclass wired through the existing `class:` producer option does the job:

```php
final class DefaultRoutingKeyProducer extends \OldSound\RabbitMqBundle\RabbitMq\Producer
{
    public function publish($msgBody, $routingKey = null, $additionalProperties = [], ?array $headers = null)
    {
        return parent::publish($msgBody, $routingKey === '' ? null : $routingKey, $additionalProperties, $headers);
    }
}
```

wired via the existing `class:` producer option — no fork required at all.
