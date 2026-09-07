# Spec 2 — `AMQPEvent`: accept any dequeuer and carry message collections

| | |
|---|---|
| **Target** | `php-amqplib/RabbitMqBundle`, base `master` @ `56305f1` (2.19.0) |
| **Branch** | `refactor/amqpevent-dequeuer-support` |
| **Type** | Refactor, additive |
| **BC break** | None at runtime. One documented return-type widening that static analysis in listeners can notice. |
| **Depends on** | — |
| **Blocks** | [Spec 3 — BatchConsumer events](03-batch-consumer-events.md) |
| **Fork origin** | `edb325a` / `b9318a6` (Call #4925), event classes only |
| **Consumer relevance** | EnVi: nice-to-have. Without it, `CoreBundle/{main,doctrine}/Tests/EventListener/RabbitMQEventSubscriberTest.php` (lines 41, 51) must mock `Consumer` instead of `DequeuerInterface`. No production code affected. |

---

## 1. Problem

The consumer event classes are hard-typed to `OldSound\RabbitMqBundle\RabbitMq\Consumer`:

```php
public function setConsumer(Consumer $consumer)
public function __construct(Consumer $consumer, AMQPMessage $AMQPMessage)
```

`BatchConsumer` does not extend `Consumer` — it is `BaseAmqp implements DequeuerInterface`. It
therefore cannot construct or dispatch any of the four consumer events, which is why
`BatchConsumer` is the only consumer in the bundle that emits nothing at all
([Spec 3](03-batch-consumer-events.md) fixes that, and needs this PR first).

Second, the events carry exactly one `AMQPMessage`. A batch consumer processes *n* messages per
callback invocation, so "the message this event is about" is a collection there, not a scalar.

## 2. Scope

**In scope**

* Widen the consumer type on the event classes from `Consumer` to `DequeuerInterface`.
* Add a message **collection** accessor alongside the existing single-message one, keeping both in
  sync so no existing listener changes behaviour.
* A named constructor on the two processing events for the batch case.

**Out of scope**

* Dispatching anything new — no consumer starts emitting an event it did not emit before. That is
  entirely Spec 3.
* The producer events (`BeforeProducerPublishMessageEvent` / `AfterProducerPublishMessageEvent`).
  They live on the same base class and are left untouched.

## 3. Design

### 3.1 Widening the consumer type is safe

```
BaseConsumer  extends BaseAmqp implements DequeuerInterface
  Consumer    extends BaseConsumer          ← everything dispatched today
    MultipleConsumer, DynamicConsumer, AnonConsumer
  RpcServer   extends BaseConsumer
BatchConsumer extends BaseAmqp implements DequeuerInterface   ← the one that cannot participate
```

Every class that can be passed today already implements `DequeuerInterface`, so widening the
parameter type accepts a strict superset of what it accepts now. Contravariance: no caller breaks.

### 3.2 Additive, not a rename

The fork renamed `getAMQPMessage()`/`setAMQPMessage()` to `getAMQPMessages()`/`setAMQPMessages()`.
That is a hard BC break on the most-used part of the event API, and it is also no longer viable
upstream: `AMQPEvent` is shared with the producer events added in #728/#729, where a single message
is the correct shape.

Both accessors therefore exist and are kept consistent:

| Call | `getAMQPMessage()` | `getAMQPMessages()` |
|---|---|---|
| `setAMQPMessage($m)` | `$m` | `[$m]` |
| `setAMQPMessages([$a, $b])` | `$a` (the first) | `[$a, $b]` |
| `setAMQPMessages([])` | `null` | `[]` |

So a listener written against today's API keeps working when it receives a batch event — it sees
the first message rather than an error — and a listener written against the collection API works
for single-message events too.

### 3.3 Named constructor instead of a union parameter

`BeforeProcessingMessageEvent` / `AfterProcessingMessageEvent` keep their existing constructor
signature untouched and gain a static factory:

```php
BeforeProcessingMessageEvent::forBatch($batchConsumer, $messages)
```

This avoids an `AMQPMessage|array` union parameter, keeps the constructor's meaning unambiguous,
and makes the batch path explicit at every call site.

### 3.4 The one visible change

`AMQPEvent::getConsumer()` is annotated `@return DequeuerInterface` instead of `@return Consumer`.
There is no runtime change — for every event dispatched today the object *is* still a `Consumer` —
but a listener that calls a `Consumer`-only method on the result will now need a narrowing check to
satisfy PHPStan/Psalm. Worth a CHANGELOG line.

If the maintainers would rather not touch the annotation at all, the alternative is
`@return Consumer|DequeuerInterface`. It is less honest but fully silent for existing users.

---

## 4. Code changes

### 4.1 `Event/AMQPEvent.php`

```diff
 namespace OldSound\RabbitMqBundle\Event;
 
 use OldSound\RabbitMqBundle\RabbitMq\Consumer;
+use OldSound\RabbitMqBundle\RabbitMq\DequeuerInterface;
 use OldSound\RabbitMqBundle\RabbitMq\Producer;
 use PhpAmqpLib\Message\AMQPMessage;
 
 class AMQPEvent extends AbstractAMQPEvent
 {
     public const ON_CONSUME                = 'on_consume';
     public const ON_IDLE                   = 'on_idle';
     public const BEFORE_PROCESSING_MESSAGE = 'before_processing';
     public const AFTER_PROCESSING_MESSAGE  = 'after_processing';
     public const BEFORE_PUBLISH_MESSAGE = 'before_publishing';
     public const AFTER_PUBLISH_MESSAGE  = 'after_publishing';
 
     /**
-     * @var AMQPMessage
+     * @var AMQPMessage|null The first message this event is about. Kept in sync with $AMQPMessages.
      */
     protected $AMQPMessage;
 
+    /**
+     * @var AMQPMessage[] Every message this event is about. Holds a single element for the
+     *                    single-message consumers, and the whole batch for BatchConsumer.
+     */
+    protected $AMQPMessages = [];
+
     /**
-     * @var Consumer
+     * @var DequeuerInterface
      */
     protected $consumer;
 
     /**
      * @var Producer
      */
     protected $producer;
 
     /**
-     * @return AMQPMessage
+     * @return AMQPMessage|null
      */
     public function getAMQPMessage()
     {
         return $this->AMQPMessage;
     }
 
     /**
      * @param AMQPMessage $AMQPMessage
      *
      * @return AMQPEvent
      */
     public function setAMQPMessage(AMQPMessage $AMQPMessage)
     {
         $this->AMQPMessage = $AMQPMessage;
+        $this->AMQPMessages = [$AMQPMessage];
 
         return $this;
     }
 
+    /**
+     * @return AMQPMessage[]
+     */
+    public function getAMQPMessages()
+    {
+        return $this->AMQPMessages;
+    }
+
+    /**
+     * Sets every message this event is about. getAMQPMessage() keeps returning the first one, so
+     * listeners written against the single-message API keep working.
+     *
+     * @param AMQPMessage[] $AMQPMessages
+     *
+     * @return AMQPEvent
+     */
+    public function setAMQPMessages(array $AMQPMessages)
+    {
+        $this->AMQPMessages = $AMQPMessages;
+        $this->AMQPMessage = $AMQPMessages === [] ? null : reset($AMQPMessages);
+
+        return $this;
+    }
+
     /**
-     * @return Consumer
+     * @return DequeuerInterface
      */
     public function getConsumer()
     {
         return $this->consumer;
     }
 
     /**
-     * @param Consumer $consumer
+     * @param DequeuerInterface $consumer
      *
      * @return AMQPEvent
      */
-    public function setConsumer(Consumer $consumer)
+    public function setConsumer(DequeuerInterface $consumer)
     {
         $this->consumer = $consumer;
 
         return $this;
     }
```

The `use ... \Consumer;` import stays — it is still referenced by the class docblocks; drop it only
if nothing else refers to it after the edit.

### 4.2 `Event/OnConsumeEvent.php`

```diff
-use OldSound\RabbitMqBundle\RabbitMq\Consumer;
+use OldSound\RabbitMqBundle\RabbitMq\DequeuerInterface;
 
 class OnConsumeEvent extends AMQPEvent
 {
     public const NAME = AMQPEvent::ON_CONSUME;
 
     /**
      * OnConsumeEvent constructor.
      *
-     * @param Consumer $consumer
+     * @param DequeuerInterface $consumer
      */
-    public function __construct(Consumer $consumer)
+    public function __construct(DequeuerInterface $consumer)
     {
         $this->setConsumer($consumer);
     }
 }
```

### 4.3 `Event/OnIdleEvent.php`

Identical widening of the import, the docblock and the constructor parameter. `$forceStop` and its
accessors are untouched.

### 4.4 `Event/BeforeProcessingMessageEvent.php`

```diff
-use OldSound\RabbitMqBundle\RabbitMq\Consumer;
+use OldSound\RabbitMqBundle\RabbitMq\DequeuerInterface;
 use PhpAmqpLib\Message\AMQPMessage;
 
 class BeforeProcessingMessageEvent extends AMQPEvent
 {
     public const NAME = AMQPEvent::BEFORE_PROCESSING_MESSAGE;
 
     /**
      * BeforeProcessingMessageEvent constructor.
      *
+     * @param DequeuerInterface $consumer
      * @param AMQPMessage $AMQPMessage
      */
-    public function __construct(Consumer $consumer, AMQPMessage $AMQPMessage)
+    public function __construct(DequeuerInterface $consumer, AMQPMessage $AMQPMessage)
     {
         $this->setConsumer($consumer);
         $this->setAMQPMessage($AMQPMessage);
     }
+
+    /**
+     * Named constructor for consumers that process more than one message per callback, such as
+     * BatchConsumer. getAMQPMessage() returns the first message of the batch.
+     *
+     * @param AMQPMessage[] $AMQPMessages
+     *
+     * @return static
+     */
+    public static function forBatch(DequeuerInterface $consumer, array $AMQPMessages)
+    {
+        $event = new static($consumer, reset($AMQPMessages) ?: new AMQPMessage(''));
+        $event->setAMQPMessages($AMQPMessages);
+
+        return $event;
+    }
 }
```

> **Implementation note.** The placeholder `new AMQPMessage('')` in `forBatch()` is ugly. Prefer
> giving the class a private constructor-less path instead — e.g. instantiate through
> `(new \ReflectionClass(static::class))->newInstanceWithoutConstructor()`, or restructure the
> constructor to accept `?AMQPMessage`. Decide during implementation; the empty-batch case cannot
> occur from `BatchConsumer` (it only dispatches on a non-empty batch), so the simplest correct
> option is:
>
> ```php
> public static function forBatch(DequeuerInterface $consumer, array $AMQPMessages)
> {
>     if ($AMQPMessages === []) {
>         throw new \InvalidArgumentException('A batch event needs at least one message.');
>     }
>
>     $event = new static($consumer, reset($AMQPMessages));
>     $event->setAMQPMessages($AMQPMessages);
>
>     return $event;
> }
> ```

### 4.5 `Event/AfterProcessingMessageEvent.php`

Identical to 4.4.

---

## 5. Tests

### 5.1 `Tests/Event/BeforeProcessingMessageEventTest.php` and `AfterProcessingMessageEventTest.php`

Keep the existing test, add three. Shown for the "after" variant; the "before" variant is identical
with the class swapped.

```php
<?php

use OldSound\RabbitMqBundle\Event\AfterProcessingMessageEvent;
use OldSound\RabbitMqBundle\RabbitMq\BatchConsumer;
use OldSound\RabbitMqBundle\RabbitMq\Consumer;
use PhpAmqpLib\Message\AMQPMessage;

beforeEach(function () {
    $this->makeConsumer = function (string $class) {
        return new $class(
            $this->getMockBuilder('\PhpAmqpLib\Connection\AMQPStreamConnection')
                ->disableOriginalConstructor()
                ->getMock(),
            $this->getMockBuilder('\PhpAmqpLib\Channel\AMQPChannel')
                ->disableOriginalConstructor()
                ->getMock()
        );
    };
});

// unchanged upstream test
test('event stores the correct message and consumer', function () {
    $consumer = ($this->makeConsumer)(Consumer::class);
    $message  = new AMQPMessage('body');
    $event    = new AfterProcessingMessageEvent($consumer, $message);

    expect($event->getAMQPMessage())->toBe($message);
    expect($event->getConsumer())->toBe($consumer);
});

test('a single message event also exposes a one element collection', function () {
    $consumer = ($this->makeConsumer)(Consumer::class);
    $message  = new AMQPMessage('body');
    $event    = new AfterProcessingMessageEvent($consumer, $message);

    expect($event->getAMQPMessages())->toBe([$message]);
});

test('a batch event accepts a batch consumer and carries every message', function () {
    $consumer = ($this->makeConsumer)(BatchConsumer::class);
    $messages = [new AMQPMessage('one'), new AMQPMessage('two')];

    $event = AfterProcessingMessageEvent::forBatch($consumer, $messages);

    expect($event->getConsumer())->toBe($consumer);
    expect($event->getAMQPMessages())->toBe($messages);
});

test('a batch event stays readable through the single message accessor', function () {
    $consumer = ($this->makeConsumer)(BatchConsumer::class);
    $messages = [new AMQPMessage('one'), new AMQPMessage('two')];

    $event = AfterProcessingMessageEvent::forBatch($consumer, $messages);

    expect($event->getAMQPMessage())->toBe($messages[0]);
});
```

The last test is the BC guard: it is what proves an existing listener does not break when it is
handed a batch event.

### 5.2 `Tests/Event/OnIdleEventTest.php`

Add one case next to the existing three:

```php
test('should accept any dequeuer, not only a Consumer', function () {
    $batchConsumer = new BatchConsumer(
        $this->getMockBuilder('\PhpAmqpLib\Connection\AMQPStreamConnection')
            ->disableOriginalConstructor()
            ->getMock(),
        $this->getMockBuilder('\PhpAmqpLib\Channel\AMQPChannel')
            ->disableOriginalConstructor()
            ->getMock()
    );

    $event = new OnIdleEvent($batchConsumer);

    expect($event->getConsumer())->toBe($batchConsumer);
    expect($event->isForceStop())->toBeTrue();
});
```

### 5.3 `Tests/Event/OnConsumeEventTest.php` — new file

There is no `OnConsumeEventTest` upstream. Add the mirror of 5.2 for `OnConsumeEvent`: one case
with a `Consumer`, one with a `BatchConsumer`.

---

## 6. Documentation

### 6.1 `README.md` — `#### Consumer Events ####`

The section quotes the four event classes verbatim. Update the quoted signatures to
`DequeuerInterface` and add one paragraph after the `AFTER PROCESSING MESSAGE` block:

    Consumers that process more than one message per callback — see
    [Batch Consumers](#batch-consumers) — dispatch the same events with the whole batch attached.
    Use `$event->getAMQPMessages()` to get every message; `$event->getAMQPMessage()` keeps
    returning the first one, so listeners written for the single-message consumers keep working.

### 6.2 `CHANGELOG`

```diff
+- 2026-xx-xx
+    * Consumer events accept any `DequeuerInterface`, not only `Consumer`, so that consumers
+      outside the `Consumer` hierarchy can dispatch them.
+    * `AMQPEvent` gained `getAMQPMessages()` / `setAMQPMessages()` for consumers that handle more
+      than one message per callback. `getAMQPMessage()` is unchanged and returns the first message.
+    * `AMQPEvent::getConsumer()` is now annotated `DequeuerInterface`. No runtime change; static
+      analysis in listeners that call `Consumer`-specific methods may need a narrowing check.
```

---

## 7. PR description (ready to paste)

> **Title:** Allow consumer events to carry any dequeuer and a batch of messages
>
> ### What
>
> * `AMQPEvent::setConsumer()` and the four consumer event constructors take `DequeuerInterface`
>   instead of `Consumer`.
> * `AMQPEvent` gains `getAMQPMessages()` / `setAMQPMessages()` next to the existing single-message
>   accessors, kept in sync in both directions.
> * `BeforeProcessingMessageEvent` / `AfterProcessingMessageEvent` gain a `forBatch()` named
>   constructor.
>
> Nothing new is dispatched in this PR. It is the enabling change for
> `BatchConsumer` event support, which follows separately.
>
> ### Why
>
> `BatchConsumer` is `BaseAmqp implements DequeuerInterface`, not a `Consumer`, so it cannot
> construct any of the consumer events — which is why it is the only consumer in the bundle that
> emits nothing. And a batch consumer's "current message" is a collection, not a scalar.
>
> ### Backwards compatibility
>
> No runtime break. Every class passed to these events today already implements
> `DequeuerInterface`, so the parameter type is a strict widening. `getAMQPMessage()` keeps its
> behaviour, and returns the first message of a batch when handed a batch event, so existing
> listeners keep working even for the new dispatch sites.
>
> The single visible change is the `@return` annotation on `getConsumer()`, now
> `DequeuerInterface`. Listeners that call `Consumer`-specific methods on it may need a narrowing
> check to keep static analysis quiet. Happy to annotate `Consumer|DequeuerInterface` instead if
> you prefer a fully silent change.

---

## 8. Acceptance criteria

- [ ] `new OnConsumeEvent($batchConsumer)` and `new OnIdleEvent($batchConsumer)` type-check.
- [ ] `getAMQPMessage()` returns the first message for a batch event, `null` for an empty one.
- [ ] `getAMQPMessages()` returns `[$m]` for a single-message event.
- [ ] Existing `Tests/Event/*` pass unmodified except for the added cases.
- [ ] `vendor/bin/phpstan analyse` clean — in particular no new error in `Consumer::consume()`,
      which passes `$this` into all four events.
- [ ] `php-cs-fixer` clean.

## 9. Risks

1. **Static analysis in downstream listeners.** See §3.4. The only real cost of this PR.
2. **`forBatch()` on an empty array.** Guarded by an exception; `BatchConsumer` never dispatches an
   empty batch (Spec 3 dispatches from inside `batchConsume()`, which runs on a non-empty batch).
3. **Serialised events.** `AMQPEvent` is not serialisable in any supported flow, so the extra
   property is inert. Worth a sanity check if anyone stores events.
