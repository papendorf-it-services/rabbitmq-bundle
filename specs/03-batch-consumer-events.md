# Spec 3 — `BatchConsumer`: dispatch the consumer events and honour `OnIdleEvent`

| | |
|---|---|
| **Target** | `php-amqplib/RabbitMqBundle`, base `master` @ `56305f1` (2.19.0) |
| **Branch** | `feature/batch-consumer-events` |
| **Type** | Feature |
| **BC break** | None. Default behaviour on idle is unchanged; new events only fire where nothing fired before. |
| **Depends on** | [Spec 2 — AMQPEvent dequeuer support](02-amqpevent-dequeuer-support.md) — **must be merged first** |
| **Fork origin** | `edb325a` / `b9318a6` (Call #4925), `BatchConsumer` part |
| **Consumer relevance** | EnVi: none — it configures no batch consumers and gets its idle-timeout behaviour from upstream's `Consumer`. Redbus: required (5 batch consumers, 10+ `BatchConsumerInterface` implementations), but out of scope for the current migration. |

---

## 1. Problem

`BatchConsumer` is the only consumer in the bundle that dispatches nothing. `Consumer` emits all
four events — `OnConsumeEvent`, `BeforeProcessingMessageEvent`, `AfterProcessingMessageEvent`,
`OnIdleEvent` — and the README documents them under "Consumer Events" without saying that batch
consumers are excluded. Everything built on those hooks (graceful shutdown on deploy, per-message
tracing, keeping a consumer alive across an idle period) is simply unavailable for batch consumers.

The idle path is the concrete consequence. Both consumers hit `AMQPTimeoutException` after
`idle_timeout` seconds without a message. `Consumer` dispatches `OnIdleEvent` first and only exits
when a listener leaves `isForceStop()` at `true`:

```php
} elseif ($this->getIdleTimeout()
    && ($this->getLastActivityDateTime()->getTimestamp() + $this->getIdleTimeout() <= $now)
) {
    $idleEvent = new OnIdleEvent($this);
    $this->dispatchEvent(OnIdleEvent::NAME, $idleEvent);

    if ($idleEvent->isForceStop()) { /* exit code or rethrow */ }
}
```

`BatchConsumer` goes straight to the exit:

```php
} elseif (null !== $this->getIdleTimeoutExitCode()) {
    return $this->getIdleTimeoutExitCode();
} else {
    throw $e;
}
```

so `$event->setForceStop(false)` — documented in the README as the way to prevent the process
exiting on idle timeout — has no effect on a batch consumer. The only workaround today is
`keep_alive: true`, which is all-or-nothing and, per the README, makes `idle_timeout_exit_code`
be ignored entirely.

## 2. Scope

**In scope**

* Dispatch `OnConsumeEvent` once per consume-loop iteration.
* Dispatch `BeforeProcessingMessageEvent` / `AfterProcessingMessageEvent` around the batch callback,
  carrying the whole batch.
* Track last activity and route the idle timeout through `OnIdleEvent`, mirroring `Consumer`.

**Out of scope**

* Graceful-max-execution handling in the idle branch. `BatchConsumer` handles that in
  `checkGracefulMaxExecutionDateTime()` before the wait; leaving it there keeps the diff honest.
* Any change to `handleProcessFlag()`, `StopConsumerException` handling, or the logging.
* New configuration. `idle_timeout` / `idle_timeout_exit_code` / `keep_alive` already exist for
  batch consumers.

## 3. Design

### 3.1 Mirror `Consumer`, do not invent

Every line added here has a one-to-one counterpart in `Consumer::consume()`. That is the point of
the PR: two consumers in the same bundle should not disagree about what an idle timeout means. The
resulting `BatchConsumer::consume()` differs from `Consumer::consume()` only where the batch
semantics genuinely differ (`isCompleteBatch()`, `isEmptyBatch()`, `keepAlive`, the graceful check
sitting before the wait).

### 3.2 Existing behaviour is provably preserved

The wait timeout is chosen as:

```php
$timeout = $this->isEmptyBatch() ? $this->getIdleTimeout() : $this->getTimeoutWait();
```

To reach the new `elseif` the batch must be empty, so the wait ran with `getIdleTimeout()`. A
`wait()` with a timeout of `0` blocks indefinitely and can never raise `AMQPTimeoutException` —
therefore `getIdleTimeout()` is necessarily non-zero whenever the new guard is evaluated, and
`lastActivity + idleTimeout <= now` is necessarily true, because the wait just consumed exactly that
much time without a message. The guard is redundant in the current call graph; it is included
anyway so the two consumers read identically and so the branch stays correct if the timeout
selection ever changes.

`OnIdleEvent` constructs with `forceStop = true`. With no listener registered the behaviour is
byte-for-byte what it is today: return `idleTimeoutExitCode` if set, otherwise rethrow.

### 3.3 Batch events carry the whole batch

`BeforeProcessingMessageEvent::forBatch($this, $this->messages)` (Spec 2) is dispatched inside
`batchConsume()`, before `call_user_func($this->callback, $this->messages)`, and its "after"
counterpart after `handleProcessMessages()` — the same placement `Consumer::processMessage()` uses.
The after event is not dispatched when the callback throws, matching `Consumer` and matching what
the README already promises ("If the process message will throw an Exception the event will not
raise").

`$this->messages` is keyed by delivery tag. It is passed through unchanged, so a listener sees the
same array the callback sees.

### 3.4 `OnConsumeEvent` placement

At the top of the `while` body, before `isCompleteBatch()`, matching `Consumer` where it is the
first statement in the loop. It fires once per iteration, not once per message — the same as for
`Consumer`, where an iteration is also not a message.

### 3.5 Deviations from the fork implementation

| Fork | Here |
|---|---|
| `getLastActivityDateTime()` public, `setLastActivityDateTime(?\DateTime)` nullable | Matches `Consumer` exactly: `public setLastActivityDateTime(\DateTime)`, `protected getLastActivityDateTime(): ?\DateTime` |
| Fork's `BeforeProcessingMessageEvent($this, $this->messages)` relies on its renamed array constructor | `::forBatch()` named constructor from Spec 2, no BC break |
| Fork also drops `MSG_ACK_SENT` and `handleProcessMessages($e->getHandleCode())`, and renames a log line | Not carried over — unrelated regressions, see the migration analysis |

---

## 4. Code changes

All in `RabbitMq/BatchConsumer.php`.

### 4.1 Imports and the new property

```diff
 namespace OldSound\RabbitMqBundle\RabbitMq;
 
+use OldSound\RabbitMqBundle\Event\AfterProcessingMessageEvent;
+use OldSound\RabbitMqBundle\Event\BeforeProcessingMessageEvent;
+use OldSound\RabbitMqBundle\Event\OnConsumeEvent;
+use OldSound\RabbitMqBundle\Event\OnIdleEvent;
 use PhpAmqpLib\Channel\AMQPChannel;
 use PhpAmqpLib\Exception\AMQPRuntimeException;
 use PhpAmqpLib\Exception\AMQPTimeoutException;
 use PhpAmqpLib\Message\AMQPMessage;
@@
     /** @var int */
     private $batchAmountTarget;
 
+    /**
+     * @var \DateTime|null
+     */
+    protected $lastActivityDateTime;
+
```

### 4.2 `consume()`

```diff
     public function consume(int $batchAmountTarget = 0)
     {
         $this->batchAmountTarget = $batchAmountTarget;
 
         $this->setupConsumer();
 
+        $this->setLastActivityDateTime(new \DateTime());
         while ($this->getChannel()->is_consuming()) {
+            $this->dispatchEvent(OnConsumeEvent::NAME, new OnConsumeEvent($this));
+
             if ($this->isCompleteBatch()) {
                 $this->batchConsume();
             }
 
             $this->checkGracefulMaxExecutionDateTime();
             $this->maybeStopConsumer();
 
             $timeout = $this->isEmptyBatch() ? $this->getIdleTimeout() : $this->getTimeoutWait();
 
             try {
                 $this->getChannel()->wait(null, false, $timeout);
+                $this->setLastActivityDateTime(new \DateTime());
             } catch (AMQPTimeoutException $e) {
+                $now = time();
+
                 if (!$this->isEmptyBatch()) {
                     $this->batchConsume();
                     $this->maybeStopConsumer();
                 } elseif ($this->keepAlive === true) {
                     continue;
-                } elseif (null !== $this->getIdleTimeoutExitCode()) {
-                    return $this->getIdleTimeoutExitCode();
-                } else {
-                    throw $e;
+                } elseif ($this->getIdleTimeout()
+                    && ($this->getLastActivityDateTime()->getTimestamp() + $this->getIdleTimeout() <= $now)
+                ) {
+                    $idleEvent = new OnIdleEvent($this);
+                    $this->dispatchEvent(OnIdleEvent::NAME, $idleEvent);
+
+                    if ($idleEvent->isForceStop()) {
+                        if (null !== $this->getIdleTimeoutExitCode()) {
+                            return $this->getIdleTimeoutExitCode();
+                        }
+
+                        throw $e;
+                    }
                 }
             }
         }
 
         return 0;
     }
```

### 4.3 `batchConsume()`

```diff
     private function batchConsume()
     {
+        $this->dispatchEvent(
+            BeforeProcessingMessageEvent::NAME,
+            BeforeProcessingMessageEvent::forBatch($this, $this->messages)
+        );
+
         try {
             $processFlags = call_user_func($this->callback, $this->messages);
             $this->handleProcessMessages($processFlags);
+            $this->dispatchEvent(
+                AfterProcessingMessageEvent::NAME,
+                AfterProcessingMessageEvent::forBatch($this, $this->messages)
+            );
             $this->logger->debug('Queue message processed', [
```

The rest of `batchConsume()` — the three catch blocks, `handleProcessMessages($e->getHandleCode())`,
`resetBatch()`, `stopConsuming()` — is untouched.

### 4.4 Accessors

Appended to the class, copied verbatim from `Consumer` so the two stay in sync:

```php
    public function setLastActivityDateTime(\DateTime $dateTime)
    {
        $this->lastActivityDateTime = $dateTime;
    }

    protected function getLastActivityDateTime(): ?\DateTime
    {
        return $this->lastActivityDateTime;
    }
```

---

## 5. Tests

### 5.1 `Tests/RabbitMq/BatchConsumerTest.php` — new file

There is **no** `BatchConsumerTest` upstream. This PR introduces one; the cases below are the
minimum that covers the change.

```php
<?php

use OldSound\RabbitMqBundle\Event\AfterProcessingMessageEvent;
use OldSound\RabbitMqBundle\Event\BeforeProcessingMessageEvent;
use OldSound\RabbitMqBundle\Event\OnConsumeEvent;
use OldSound\RabbitMqBundle\Event\OnIdleEvent;
use OldSound\RabbitMqBundle\RabbitMq\BatchConsumer;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Message\AMQPMessage;
use Symfony\Component\EventDispatcher\EventDispatcher;

beforeEach(function () {
    $this->channel = $this->getMockBuilder('\PhpAmqpLib\Channel\AMQPChannel')
        ->disableOriginalConstructor()
        ->getMock();
    $this->channel->method('getChannelId')->willReturn(1);

    $this->connection = $this->getMockBuilder('\PhpAmqpLib\Connection\AMQPStreamConnection')
        ->disableOriginalConstructor()
        ->getMock();
    $this->connection->method('connectOnConstruct')->willReturn(false);
    $this->connection->method('channel')->willReturn($this->channel);

    $this->dispatcher = new EventDispatcher();

    $this->makeConsumer = function (): BatchConsumer {
        $consumer = new BatchConsumer($this->connection, $this->channel);
        $consumer->disableAutoSetupFabric();
        $consumer->setEventDispatcher($this->dispatcher);
        $consumer->setQueueOptions(['name' => 'batch_queue']);
        $consumer->setExchangeOptions(['name' => 'batch_exchange', 'type' => 'fanout']);

        return $consumer;
    };
});

test('an idle timeout dispatches OnIdleEvent', function () {
    $consumer = ($this->makeConsumer)();
    $consumer->setIdleTimeout(1);
    $consumer->setIdleTimeoutExitCode(0);

    $this->channel->method('is_consuming')->willReturn(true);
    $this->channel->method('wait')->willThrowException(new AMQPTimeoutException());

    $seen = null;
    $this->dispatcher->addListener(OnIdleEvent::NAME, function (OnIdleEvent $event) use (&$seen) {
        $seen = $event;
    });

    // last activity is set before the loop, so back-date it past the idle timeout
    $consumer->setLastActivityDateTime(new \DateTime('-10 seconds'));

    expect($consumer->consume(1))->toBe(0);
    expect($seen)->toBeInstanceOf(OnIdleEvent::class);
    expect($seen->getConsumer())->toBe($consumer);
});

test('a listener can keep the consumer alive past the idle timeout', function () {
    $consumer = ($this->makeConsumer)();
    $consumer->setIdleTimeout(1);
    $consumer->setIdleTimeoutExitCode(0);

    $calls = 0;
    $this->channel->method('is_consuming')->willReturnCallback(function () use (&$calls) {
        return ++$calls <= 3;
    });
    $this->channel->method('wait')->willThrowException(new AMQPTimeoutException());

    $idles = 0;
    $this->dispatcher->addListener(OnIdleEvent::NAME, function (OnIdleEvent $event) use (&$idles) {
        $idles++;
        $event->setForceStop(false);
    });

    $consumer->setLastActivityDateTime(new \DateTime('-10 seconds'));

    // the loop keeps running instead of returning the exit code
    expect($consumer->consume(1))->toBe(0);
    expect($idles)->toBeGreaterThan(1);
});

test('without a listener the idle timeout still returns the exit code', function () {
    $consumer = ($this->makeConsumer)();
    $consumer->setIdleTimeout(1);
    $consumer->setIdleTimeoutExitCode(-2);

    $this->channel->method('is_consuming')->willReturn(true);
    $this->channel->method('wait')->willThrowException(new AMQPTimeoutException());

    $consumer->setLastActivityDateTime(new \DateTime('-10 seconds'));

    expect($consumer->consume(1))->toBe(-2);
});

test('without a listener and without an exit code the timeout is rethrown', function () {
    $consumer = ($this->makeConsumer)();
    $consumer->setIdleTimeout(1);

    $this->channel->method('is_consuming')->willReturn(true);
    $this->channel->method('wait')->willThrowException(new AMQPTimeoutException());

    $consumer->setLastActivityDateTime(new \DateTime('-10 seconds'));

    expect(fn () => $consumer->consume(1))->toThrow(AMQPTimeoutException::class);
});

test('OnConsumeEvent fires once per loop iteration', function () {
    $consumer = ($this->makeConsumer)();
    $consumer->setIdleTimeout(1);
    $consumer->setIdleTimeoutExitCode(0);

    $iterations = 0;
    $this->channel->method('is_consuming')->willReturnCallback(function () use (&$iterations) {
        return ++$iterations <= 3;
    });
    $this->channel->method('wait')->willThrowException(new AMQPTimeoutException());

    $onConsume = 0;
    $this->dispatcher->addListener(OnConsumeEvent::NAME, function () use (&$onConsume) {
        $onConsume++;
    });
    $this->dispatcher->addListener(OnIdleEvent::NAME, function (OnIdleEvent $e) {
        $e->setForceStop(false);
    });

    $consumer->consume(1);

    expect($onConsume)->toBe(3);
});

test('the batch processing events carry the whole batch', function () {
    $consumer = ($this->makeConsumer)();
    $consumer->setQosOptions(0, 2, false);

    $messages = [];
    foreach (['one', 'two'] as $i => $body) {
        $message = new AMQPMessage($body);
        $message->setChannel($this->channel);
        $message->setDeliveryTag($i + 1);
        $messages[$i + 1] = $message;
    }

    $consumer->setCallback(fn (array $received) => true);

    $before = $after = null;
    $this->dispatcher->addListener(
        BeforeProcessingMessageEvent::NAME,
        function (BeforeProcessingMessageEvent $e) use (&$before) { $before = $e; }
    );
    $this->dispatcher->addListener(
        AfterProcessingMessageEvent::NAME,
        function (AfterProcessingMessageEvent $e) use (&$after) { $after = $e; }
    );

    foreach ($messages as $message) {
        $consumer->processMessage($message);
    }
    $consumer->stopConsuming();

    expect($before)->not->toBeNull();
    expect($before->getAMQPMessages())->toBe($messages);
    expect($before->getConsumer())->toBe($consumer);
    expect($after->getAMQPMessages())->toBe($messages);
});

test('no after event is dispatched when the batch callback throws', function () {
    $consumer = ($this->makeConsumer)();
    $consumer->setQosOptions(0, 1, false);
    $consumer->setCallback(function () { throw new \RuntimeException('boom'); });

    $message = new AMQPMessage('one');
    $message->setChannel($this->channel);
    $message->setDeliveryTag(1);

    $after = 0;
    $this->dispatcher->addListener(AfterProcessingMessageEvent::NAME, function () use (&$after) {
        $after++;
    });

    $consumer->processMessage($message);

    expect(fn () => $consumer->stopConsuming())->toThrow(\RuntimeException::class);
    expect($after)->toBe(0);
});
```

> Two of these lean on `processMessage()` + `stopConsuming()` to drive `batchConsume()` without
> entering the wait loop — `stopConsuming()` flushes a non-empty batch. Verify the delivery-tag
> plumbing (`getMessageChannel()` looks the channel up off the message) when implementing;
> `$message->setChannel()` / `setDeliveryTag()` are the two setters that matter.

### 5.2 Regression guard

`without a listener the idle timeout still returns the exit code` and `without a listener and
without an exit code the timeout is rethrown` are the two tests that pin the "no behaviour change
for existing users" claim. They must be written against the **pre-change** code first and pass
there too.

---

## 6. Documentation

### 6.1 `README.md` — `#### Consumer Events ####`

Add after the `IDLE MESSAGE` block:

    These four events are dispatched by both the single-message consumers and by
    [batch consumers](#batch-consumers). For a batch consumer, `BeforeProcessingMessageEvent` and
    `AfterProcessingMessageEvent` fire once per batch, with the whole batch reachable through
    `$event->getAMQPMessages()`, and `OnConsumeEvent` fires once per consume-loop iteration.

### 6.2 `README.md` — `### Batch Consumers ###`

Add after the `keep_alive` note:

    Batch consumers honour `OnIdleEvent` the same way single-message consumers do: on an idle
    timeout the event is dispatched first, and the process only exits when no listener has called
    `$event->setForceStop(false)`. `keep_alive: true` is the blunter alternative — it skips the
    idle handling altogether and, as noted above, makes `idle_timeout_exit_code` be ignored.

### 6.3 `CHANGELOG`

```diff
+- 2026-xx-xx
+    * `BatchConsumer` now dispatches `OnConsumeEvent`, `BeforeProcessingMessageEvent`,
+      `AfterProcessingMessageEvent` and `OnIdleEvent`, matching `Consumer`. The two processing
+      events carry the whole batch, reachable via `AMQPEvent::getAMQPMessages()`.
+    * `BatchConsumer` honours `OnIdleEvent::setForceStop(false)` on an idle timeout. With no
+      listener registered the behaviour is unchanged.
```

---

## 7. PR description (ready to paste)

> **Title:** Dispatch the consumer events from `BatchConsumer`
>
> Depends on #<spec-2-pr> — please merge that one first.
>
> ### What
>
> `BatchConsumer` now dispatches the same four events `Consumer` does:
>
> | Event | When |
> |---|---|
> | `OnConsumeEvent` | once per consume-loop iteration |
> | `BeforeProcessingMessageEvent` | before the batch callback, carrying the whole batch |
> | `AfterProcessingMessageEvent` | after the batch has been acked/rejected, not on exception |
> | `OnIdleEvent` | on idle timeout, and `setForceStop(false)` is honoured |
>
> ### Why
>
> `BatchConsumer` is the only consumer in the bundle that emits nothing, and the README's
> "Consumer Events" section does not say so. The idle case is the sharp edge: the README documents
> `$event->setForceStop(false)` as the way to stop a consumer exiting on idle timeout, and that has
> never worked for batch consumers — the only alternative is `keep_alive: true`, which is
> all-or-nothing and disables `idle_timeout_exit_code`.
>
> ### Implementation
>
> Every added line has a one-to-one counterpart in `Consumer::consume()`. The two consumers now
> read identically apart from the genuinely batch-specific parts.
>
> ### Backwards compatibility
>
> None affected. `OnIdleEvent` is constructed with `forceStop = true`, so with no listener
> registered the idle path returns `idleTimeoutExitCode` or rethrows exactly as before — covered by
> two dedicated regression tests. Nothing else in `batchConsume()` changes.
>
> ### Tests
>
> New `Tests/RabbitMq/BatchConsumerTest.php` — there was none.

---

## 8. Acceptance criteria

- [ ] With no listeners, `consume()` returns the same value and throws the same exception as before
      the change, for every combination of `idle_timeout`, `idle_timeout_exit_code`, `keep_alive`.
- [ ] `OnIdleEvent` listener calling `setForceStop(false)` keeps the loop running.
- [ ] `OnConsumeEvent` count equals the loop iteration count.
- [ ] Before/After events carry `$this->messages` unchanged, keyed by delivery tag.
- [ ] The after event does not fire when the callback throws.
- [ ] `keep_alive: true` still short-circuits before the idle handling.
- [ ] `vendor/bin/pest` green on the full matrix.
- [ ] `vendor/bin/phpstan analyse` clean.
- [ ] `php-cs-fixer` clean.

## 9. Risks

1. **Dispatch cost in a hot loop.** `OnConsumeEvent` is constructed once per iteration even with no
   listeners. `Consumer` already pays exactly this, and `BaseAmqp::dispatchEvent()` returns early
   when no dispatcher is set, so a consumer without an injected dispatcher pays one object
   allocation per iteration. Acceptable; flag if the maintainers disagree.
2. **`keep_alive` ordering.** The `keepAlive` branch stays ahead of the idle branch, so a
   keep-alive consumer never dispatches `OnIdleEvent`. That matches the documented "the consumer
   process continues" semantics, but it is a deliberate asymmetry worth confirming.
