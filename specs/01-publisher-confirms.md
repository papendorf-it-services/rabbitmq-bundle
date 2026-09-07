# Spec 1 — Publisher confirms for `Producer`

| | |
|---|---|
| **Target** | `php-amqplib/RabbitMqBundle`, base `master` @ `56305f1` (2.19.0) |
| **Branch** | `feature/publisher-confirms` |
| **Type** | Feature, additive, opt-in |
| **BC break** | None. Default off; no method call is added to existing producer definitions. |
| **Depends on** | — |
| **Fork origin** | `9a9f79a`, `fc5749a`, `f86bf08`, `44fed67`, `c27bdff` |
| **Consumer relevance** | EnVi: **mandatory**. Three producers (`assessment`, `delay`, `event_bus`; two in the `Merge` tree) set `confirm_select: true` / `confirm_timeout: 5`; `Pits\RabbitMQ\MessageProducer` consumes both the boolean return and the timeout-as-exception behaviour. See §3.6. |

---

## 1. Problem

RabbitMQ publisher confirms (`confirm.select`) are the only way for a publisher to learn that the
broker has taken responsibility for a message. Without them `basic_publish()` is fire-and-forget:
it returns as soon as the frame is on the socket, and a broker that is out of disk, is refusing
publishes, or dies mid-publish is indistinguishable from success.

The bundle exposes no way to turn confirms on. Applications that need an at-least-once guarantee
have to reach past the bundle onto `AMQPChannel` directly — which means re-implementing the handler
wiring on every reconnect, and losing it silently whenever the bundle recreates the channel.

## 2. Scope

**In scope**

* Per-producer opt-in configuration (`confirm_select`, `confirm_timeout`).
* Putting the producer's channel into confirm mode, including after a reconnect or channel swap.
* Blocking until the confirmation arrives, and reporting the outcome from `publish()`.

**Out of scope**

* Asynchronous / batched confirms (publish many, confirm once). Can follow later on top of the
  same handlers.
* Per-message confirmation callbacks exposed to userland.
* `tx.select` transactions.
* `mandatory` / `immediate` flags and return listeners — orthogonal, separate PR.

## 3. Design

### 3.1 Configuration

Two new keys on the `producers` prototype:

```yaml
producers:
    upload_picture:
        connection:       default
        exchange_options: {name: 'upload-picture', type: direct}
        confirm_select:   true   # default false
        confirm_timeout:  10.0   # default 10.0, seconds; 0 waits indefinitely
```

`confirm_timeout` is only read when `confirm_select` is true.

### 3.2 Activation point — `getChannel()` override, not the constructor

Confirm mode is a property of the **channel**, not of the producer. The bundle replaces the channel
in three situations: `BaseAmqp::getChannel()` recreates it once it has been closed,
`BaseAmqp::setChannel()` swaps it, and `BaseAmqp::reconnect()` reconnects underneath it. If confirm
mode is enabled once at construction time, every one of those silently drops the producer back to
fire-and-forget while `publish()` keeps reporting success.

The producer therefore overrides `getChannel()` and enables confirm mode whenever it is handed a
channel object it has not seen before:

```php
public function getChannel()
{
    $channel = parent::getChannel();

    if ($this->confirmSelect && $channel !== $this->confirmSelectChannel) {
        $this->enableConfirmSelect($channel);
    }

    return $channel;
}
```

Identity comparison against the object — not against the channel id, which is reused after a
reconnect — makes this correct for all three cases with one branch, and removes the need for both a
`reconnect()` override and a lazy `isConnected()` guard.

### 3.3 Return value of `publish()`

`Producer::publish()` currently returns nothing. It gains a `bool`:

* `true` — no negative confirmation was received.
* `false` — the broker sent `basic.nack` for the message.

With `confirm_select: false` it always returns `true`. That is deliberate: callers written as
`if (!$producer->publish(...))` must not start failing merely because confirms are off, and
returning `null` would make exactly that happen.

`$acknowledged` is reset to `true` immediately before every `basic_publish()`, so one nacked message
cannot poison the result of the next publish.

### 3.4 Timeout behaviour

`AMQPChannel::wait_for_pending_acks()` throws `PhpAmqpLib\Exception\AMQPTimeoutException` when the
timeout elapses. The exception is **not** caught — a broker that stops confirming is an
infrastructure failure, not a per-message rejection, and collapsing it into `return false` would
hide it behind the same value a legitimate nack produces. Documented in the README.

`confirm_timeout: 0` maps to php-amqplib's "wait indefinitely".

### 3.5 Deviations from the fork implementation

| Fork | Here | Why |
|---|---|---|
| `confirmSelect` as 4th constructor argument, wired with two `$definition->addArgument(null)` placeholders | `setConfirmSelect()` method call | The fork's wiring is positional and couples the extension to `BaseAmqp`'s constructor arity; one of the placeholders also duplicates the logged-channel argument. |
| `setConfirmationTimeout()` added to **every** producer definition | Method calls added only when `confirm_select` is true | Keeps `getMethodCalls()` unchanged for all existing producers, so no existing extension test has to be touched. |
| `wait_for_pending_acks()` on every publish | Only when confirms are enabled | It is a no-op on a non-confirm channel, but the call is misleading and costs a dispatch on the hot path. |
| `initializeProducer()` in the constructor, guarded by `$conn->isConnected()`, plus a `reconnect()` override and an `$initialized` flag re-checked in `publish()` | `getChannel()` override with object identity | Covers reconnect **and** channel recreation **and** `setChannel()`; no lazy-connection special case needed. |
| `$acknowledged` never reset per publish | Reset before each `basic_publish()` | Otherwise a single nack makes every later `publish()` return `false` until an ack happens to arrive. |
| `confirm_timeout` default `null`, coerced to `10` in the extension when confirms are on | `floatNode`, default `10.0` | Config-level default instead of extension-level branching. |

### 3.6 `isConfirmSelect()` stays off `ProducerInterface` — and what that means for callers

`isConfirmSelect()` is added to `Producer`, **not** to `ProducerInterface`. Adding a method to a
published interface is a fatal error for every third-party implementation of it, so upstream will
not take it, and this PR does not ask them to.

That is a real constraint for callers, and the known consuming code trips over it today.
`Pits\RabbitMQ\MessageProducer::sendMessage()` (`Bundles/Pits/rabbitmq/src/MessageProducer.php`)
types its parameter as `ProducerInterface` and then calls the method:

```php
public function sendMessage(ProducerInterface $producer, RabbitMQ $envelope) {
    ...
    try {
        $published = $producer->publish($serializedMessage, $envelope->getRoutingKey(), $additionalProperties, $headers);
    } catch (AMQPTimeoutException $timeoutException) {
        if ($producer->isConfirmSelect()) {   // not on ProducerInterface
            $published = false;
        } else {
            throw $timeoutException;
        }
    }
    if (!$published) {
        throw new MessageNotSentException("Message could not be published!");
    }
}
```

This works only because a `Producer` always arrives at runtime. In sandbox mode the container hands
out a `Fallback`, and the call is a fatal error.

**Resolution — caller side, one line, no upstream dependency:**

```php
-        if ($producer->isConfirmSelect()) {
+        if ($producer instanceof Producer && $producer->isConfirmSelect()) {
```

`Producer` is already imported in that file. Note that this catch block is also the reason §3.4
lets the timeout propagate as an exception rather than folding it into `return false`: the caller
wants to distinguish the two cases itself, and can only do so if the exception reaches it.

If a future major version of the bundle reworks `ProducerInterface`, `isConfirmSelect(): bool` is a
reasonable addition to it — worth raising as an issue rather than as part of this PR.

---

## 4. Code changes

### 4.1 `DependencyInjection/Configuration.php`

```diff
@@ protected function addProducers(ArrayNodeDefinition $node)
                             ->scalarNode('service_alias')->defaultValue(null)->end()
                             ->scalarNode('default_routing_key')->defaultValue('')->end()
+                            ->booleanNode('confirm_select')->defaultFalse()
+                                ->info('Enable AMQP publisher confirms for this producer. See https://www.rabbitmq.com/docs/confirms')
+                            ->end()
+                            ->floatNode('confirm_timeout')->defaultValue(10.0)
+                                ->info('Seconds to wait for a publisher confirmation. 0 waits indefinitely. Only used when confirm_select is true.')
+                            ->end()
                             ->scalarNode('default_content_type')->defaultValue(Producer::DEFAULT_CONTENT_TYPE)->end()
                             ->integerNode('default_delivery_mode')->min(1)->max(2)->defaultValue(2)->end()
```

### 4.2 `DependencyInjection/OldSoundRabbitMqExtension.php`

```diff
@@ protected function loadProducers()
                 $definition->addMethodCall('setDefaultRoutingKey', [$producer['default_routing_key']]);
                 $definition->addMethodCall('setContentType', [$producer['default_content_type']]);
                 $definition->addMethodCall('setDeliveryMode', [$producer['default_delivery_mode']]);
+
+                if ($producer['confirm_select']) {
+                    $definition->addMethodCall('setConfirmSelect', [true]);
+                    $definition->addMethodCall('setConfirmationTimeout', [$producer['confirm_timeout']]);
+                }
```

### 4.3 `RabbitMq/Producer.php` — new members

```diff
 use OldSound\RabbitMqBundle\Event\AfterProducerPublishMessageEvent;
 use OldSound\RabbitMqBundle\Event\BeforeProducerPublishMessageEvent;
+use PhpAmqpLib\Channel\AMQPChannel;
 use PhpAmqpLib\Message\AMQPMessage;
 use PhpAmqpLib\Wire\AMQPTable;
 
 class Producer extends BaseAmqp implements ProducerInterface
 {
     public const DEFAULT_CONTENT_TYPE = 'text/plain';
     protected $contentType = Producer::DEFAULT_CONTENT_TYPE;
     protected $deliveryMode = 2;
     protected $defaultRoutingKey = '';
+
+    /**
+     * @var bool Whether the producer's channel is put into publisher-confirm mode.
+     */
+    protected $confirmSelect = false;
+
+    /**
+     * @var float Seconds to wait for a publisher confirmation. 0 waits indefinitely.
+     */
+    protected $confirmationTimeout = 10.0;
+
+    /**
+     * @var bool Outcome of the most recent publish() while confirms are enabled.
+     */
+    private $acknowledged = true;
+
+    /**
+     * @var AMQPChannel|null The channel confirm mode has already been enabled on.
+     */
+    private $confirmSelectChannel = null;
+
+    /**
+     * @return $this
+     */
+    public function setConfirmSelect(bool $confirmSelect)
+    {
+        $this->confirmSelect = $confirmSelect;
+
+        return $this;
+    }
+
+    public function isConfirmSelect(): bool
+    {
+        return $this->confirmSelect;
+    }
+
+    /**
+     * @param float $confirmationTimeout Seconds. 0 waits indefinitely.
+     *
+     * @return $this
+     */
+    public function setConfirmationTimeout(float $confirmationTimeout)
+    {
+        $this->confirmationTimeout = $confirmationTimeout;
+
+        return $this;
+    }
+
+    public function getConfirmationTimeout(): float
+    {
+        return $this->confirmationTimeout;
+    }
+
+    /**
+     * {@inheritdoc}
+     *
+     * Puts the channel into publisher-confirm mode the first time it is handed out, and again
+     * whenever the underlying channel object has been replaced — by a reconnect, by the parent
+     * recreating a closed channel, or by an explicit setChannel().
+     */
+    public function getChannel()
+    {
+        $channel = parent::getChannel();
+
+        if ($this->confirmSelect && $channel !== $this->confirmSelectChannel) {
+            $this->enableConfirmSelect($channel);
+        }
+
+        return $channel;
+    }
+
+    private function enableConfirmSelect(AMQPChannel $channel): void
+    {
+        $channel->confirm_select();
+
+        $channel->set_ack_handler(function (AMQPMessage $message) {
+            $this->acknowledged = true;
+        });
+
+        $channel->set_nack_handler(function (AMQPMessage $message) {
+            $this->acknowledged = false;
+        });
+
+        $this->confirmSelectChannel = $channel;
+    }
```

### 4.4 `RabbitMq/Producer.php` — `publish()`

```diff
     /**
      * Publishes the message and merges additional properties with basic properties
      *
      * @param string $msgBody
      * @param string $routingKey
      * @param array $additionalProperties
      * @param array $headers
+     *
+     * @return bool False only when the broker explicitly nacked the message. Always true when
+     *              publisher confirms are disabled for this producer.
+     *
+     * @throws \PhpAmqpLib\Exception\AMQPTimeoutException When confirms are enabled and no
+     *              confirmation arrived within confirm_timeout seconds.
      */
     public function publish($msgBody, $routingKey = null, $additionalProperties = [], ?array $headers = null)
     {
@@
         $this->dispatchEvent(
             BeforeProducerPublishMessageEvent::NAME,
             new BeforeProducerPublishMessageEvent($this, $msg, $real_routingKey)
         );
 
-        $this->getChannel()->basic_publish($msg, $this->exchangeOptions['name'], (string)$real_routingKey);
+        $channel = $this->getChannel();
+
+        $this->acknowledged = true;
+        $channel->basic_publish($msg, $this->exchangeOptions['name'], (string)$real_routingKey);
+
+        if ($this->confirmSelect) {
+            $channel->wait_for_pending_acks($this->confirmationTimeout);
+        }
+
         $this->logger->debug('AMQP message published', [
             'amqp' => [
                 'body' => $msgBody,
                 'routingkey' => $real_routingKey,
                 'properties' => $additionalProperties,
                 'headers' => $headers,
             ],
         ]);
 
         $this->dispatchEvent(
             AfterProducerPublishMessageEvent::NAME,
             new AfterProducerPublishMessageEvent($this, $msg, $real_routingKey)
         );
+
+        return $this->acknowledged;
     }
```

---

## 5. Tests

### 5.1 `Tests/RabbitMq/ProducerTest.php` — new file

There is no producer unit test upstream today; this PR introduces one. Pest closures, PHPUnit
mocking via `$this`, matching the style of `Tests/RabbitMq/BaseAmqpTest.php`.

```php
<?php

use OldSound\RabbitMqBundle\RabbitMq\Producer;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AbstractConnection;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Message\AMQPMessage;

beforeEach(function () {
    $this->makeChannel = function (): AMQPChannel {
        $channel = $this->getMockBuilder(AMQPChannel::class)
            ->disableOriginalConstructor()
            ->getMock();
        $channel->method('getChannelId')->willReturn(1);

        return $channel;
    };

    $this->channel = ($this->makeChannel)();

    $this->connection = $this->getMockBuilder(AbstractConnection::class)
        ->disableOriginalConstructor()
        ->getMock();
    $this->connection->method('connectOnConstruct')->willReturn(false);
    $this->connection->method('channel')->willReturn($this->channel);

    $this->producer = new Producer($this->connection, $this->channel);
    $this->producer->disableAutoSetupFabric();
    $this->producer->setExchangeOptions(['name' => 'test_exchange', 'type' => 'direct']);
});

test('publisher confirms are off by default and the channel is untouched', function () {
    $this->channel->expects($this->never())->method('confirm_select');
    $this->channel->expects($this->never())->method('wait_for_pending_acks');
    $this->channel->expects($this->once())->method('basic_publish');

    expect($this->producer->isConfirmSelect())->toBeFalse();
    expect($this->producer->publish('body'))->toBeTrue();
});

test('confirm mode is enabled once and awaited on every publish', function () {
    $this->producer->setConfirmSelect(true)->setConfirmationTimeout(2.0);

    $this->channel->expects($this->once())->method('confirm_select');
    $this->channel->expects($this->once())->method('set_ack_handler');
    $this->channel->expects($this->once())->method('set_nack_handler');
    $this->channel->expects($this->exactly(2))
        ->method('wait_for_pending_acks')
        ->with(2.0);

    $this->producer->publish('one');
    $this->producer->publish('two');
});

test('publish returns false when the broker nacks the message', function () {
    $handlers = [];
    $this->channel->method('set_ack_handler')
        ->willReturnCallback(function (callable $h) use (&$handlers) { $handlers['ack'] = $h; });
    $this->channel->method('set_nack_handler')
        ->willReturnCallback(function (callable $h) use (&$handlers) { $handlers['nack'] = $h; });
    $this->channel->method('wait_for_pending_acks')
        ->willReturnCallback(function () use (&$handlers) { $handlers['nack'](new AMQPMessage('')); });

    $this->producer->setConfirmSelect(true);

    expect($this->producer->publish('body'))->toBeFalse();
});

test('a nacked publish does not poison the following publish', function () {
    $handlers = [];
    $this->channel->method('set_ack_handler')
        ->willReturnCallback(function (callable $h) use (&$handlers) { $handlers['ack'] = $h; });
    $this->channel->method('set_nack_handler')
        ->willReturnCallback(function (callable $h) use (&$handlers) { $handlers['nack'] = $h; });

    $sequence = ['nack', 'ack'];
    $this->channel->method('wait_for_pending_acks')
        ->willReturnCallback(function () use (&$handlers, &$sequence) {
            $handlers[array_shift($sequence)](new AMQPMessage(''));
        });

    $this->producer->setConfirmSelect(true);

    expect($this->producer->publish('first'))->toBeFalse();
    expect($this->producer->publish('second'))->toBeTrue();
});

test('confirm mode is re-enabled after the channel is replaced', function () {
    $this->producer->setConfirmSelect(true);
    $this->channel->expects($this->once())->method('confirm_select');

    $this->producer->publish('one');

    $replacement = ($this->makeChannel)();
    $replacement->expects($this->once())->method('confirm_select');

    $this->producer->setChannel($replacement);
    $this->producer->publish('two');
});

test('a confirmation timeout propagates as AMQPTimeoutException', function () {
    $this->channel->method('wait_for_pending_acks')
        ->willThrowException(new AMQPTimeoutException('timeout'));

    $this->producer->setConfirmSelect(true);

    expect(fn () => $this->producer->publish('body'))->toThrow(AMQPTimeoutException::class);
});
```

### 5.2 `Tests/DependencyInjection/Fixtures/test.yml`

```diff
         default_producer:
             exchange_options:
                 name:       default_exchange
                 type:       direct
 
+        confirm_producer:
+            connection:       default
+            exchange_options:
+                name:         confirm_exchange
+                type:         direct
+            confirm_select:   true
+            confirm_timeout:  2
+
     consumers:
```

### 5.3 `Tests/DependencyInjection/OldSoundRabbitMqExtensionTest.php`

```php
test('producer with publisher confirms enabled', function () {
    $container  = buildContainer('test.yml');
    $definition = $container->getDefinition('old_sound_rabbit_mq.confirm_producer_producer');

    expect($definition->getMethodCalls())->toContain(['setConfirmSelect', [true]]);
    expect($definition->getMethodCalls())->toContain(['setConfirmationTimeout', [2.0]]);
});

test('producers without publisher confirms get no confirm method calls', function () {
    $container  = buildContainer('test.yml');
    $definition = $container->getDefinition('old_sound_rabbit_mq.default_producer_producer');

    $called = array_column($definition->getMethodCalls(), 0);

    expect($called)->not->toContain('setConfirmSelect');
    expect($called)->not->toContain('setConfirmationTimeout');
});
```

The second test is the regression guard for the "no method call on existing producers" promise — it
is what keeps `foo producer definition` and `default producer definition` green unchanged.

---

## 6. Documentation

### 6.1 `README.md` — main configuration sample (§ Usage)

```diff
     producers:
         upload_picture:
             connection:            default
             exchange_options:      {name: 'upload-picture', type: direct}
             service_alias:         my_app_service # no alias by default
             default_routing_key:   'optional.routing.key' # defaults to '' if not set
             default_content_type:  'content/type' # defaults to 'text/plain'
             default_delivery_mode: 2 # optional. 1 means non-persistent, 2 means persistent. Defaults to "2".
+            confirm_select:        false # optional. Enable AMQP publisher confirms. Defaults to "false".
+            confirm_timeout:       10.0 # optional. Seconds to wait for a confirmation. 0 waits indefinitely. Defaults to "10.0".
```

### 6.2 `README.md` — new subsection

Insert after the `### Producer ###` section, immediately before `#### Producer Events ####`.
(Code fences below are shown indented so this spec stays readable; unindent when applying.)

    #### Publisher Confirms ####

    By default `publish()` is fire-and-forget: it returns as soon as the message has been written
    to the socket, whether or not the broker accepted it. Enable
    [publisher confirms](https://www.rabbitmq.com/docs/confirms#publisher-confirms) to make the
    producer wait for the broker to take responsibility for the message:

    ```yaml
    producers:
        upload_picture:
            connection:       default
            exchange_options: {name: 'upload-picture', type: direct}
            confirm_select:   true
            confirm_timeout:  10.0
    ```

    With `confirm_select: true`, `publish()` blocks until the broker confirms the message and
    returns whether it was accepted:

    ```php
    if (!$this->uploadPictureProducer->publish(serialize($msg))) {
        // the broker sent basic.nack for this message — it was not accepted
    }
    ```

    `publish()` returns `false` only for an explicit `basic.nack`. If no confirmation arrives
    within `confirm_timeout` seconds a `PhpAmqpLib\Exception\AMQPTimeoutException` is thrown — a
    broker that stops confirming is an infrastructure failure, not a per-message rejection. Set
    `confirm_timeout: 0` to wait indefinitely.

    With `confirm_select: false` (the default) nothing changes and `publish()` always returns
    `true`.

    Confirms cost a round trip per message. For high-throughput producers where per-message
    certainty is not required, leave them off.

### 6.3 `CHANGELOG`

```diff
+- 2026-xx-xx
+    * Add producer options `confirm_select` and `confirm_timeout` to enable AMQP publisher
+      confirms, see https://www.rabbitmq.com/docs/confirms#publisher-confirms.
+      `Producer::publish()` now returns a bool: false when the broker nacked the message.
+
 - 2024-03-18
     * Add bundle configuration for `login_method` for RabbitMQ connections, [...]
```

---

## 7. PR description (ready to paste)

> **Title:** Add publisher confirms support to `Producer`
>
> ### What
>
> Adds two opt-in producer options, `confirm_select` and `confirm_timeout`, which put the
> producer's channel into AMQP publisher-confirm mode and make `publish()` wait for the broker's
> confirmation.
>
> ```yaml
> producers:
>     upload_picture:
>         connection:       default
>         exchange_options: {name: 'upload-picture', type: direct}
>         confirm_select:   true
>         confirm_timeout:  10.0
> ```
>
> ```php
> if (!$producer->publish($body)) {
>     // broker sent basic.nack
> }
> ```
>
> ### Why
>
> Today `publish()` cannot distinguish "written to the socket" from "accepted by the broker".
> Applications that need at-least-once semantics have to reach past the bundle onto `AMQPChannel`,
> and then lose confirm mode silently whenever the bundle recreates the channel.
>
> ### Design notes
>
> * Confirm mode is enabled in an overridden `getChannel()`, keyed on channel object identity, so
>   it survives reconnects, channel recreation after a close, and explicit `setChannel()` calls.
> * The extension adds `setConfirmSelect()` / `setConfirmationTimeout()` **only** when the feature
>   is switched on, so no existing producer service definition changes shape.
> * `publish()` now returns `bool`. It previously returned nothing, and it returns `true` whenever
>   confirms are disabled, so no caller can start seeing a falsy value it did not see before.
> * A confirmation timeout propagates as `AMQPTimeoutException` rather than being folded into
>   `return false`, so a broken broker cannot be mistaken for a rejected message.
>
> ### Backwards compatibility
>
> None affected. Default is off; with the feature off the only observable change is `publish()`
> returning `true` instead of `null`.
>
> ### Tests
>
> New `Tests/RabbitMq/ProducerTest.php` (there was none), plus two extension tests — one asserting
> the wiring when the option is set, one asserting that producers without it get no extra method
> calls.

---

## 8. Acceptance criteria

- [ ] `confirm_select: true` results in exactly one `confirm_select()` call per channel object.
- [ ] Ack and nack handlers are registered on the same channel that is published on.
- [ ] `publish()` returns `false` after a nack and `true` on the next successful publish.
- [ ] `publish()` returns `true` unconditionally when the feature is off.
- [ ] Confirm mode is re-established after `setChannel()` and after `reconnect()`.
- [ ] `vendor/bin/pest` green on the full matrix (PHP 8.2–8.4, Symfony 6.4/7.4/8.0).
- [ ] `vendor/bin/phpstan analyse` clean.
- [ ] `php-cs-fixer` clean.
- [ ] No `getMethodCalls()` assertion in the existing extension tests needed changing.

### Caller-side follow-up (not part of the upstream PR)

- [ ] `Bundles/Pits/rabbitmq/src/MessageProducer.php`: guard the `isConfirmSelect()` call with
      `$producer instanceof Producer` — see §3.6.

## 9. Open questions for the maintainers

1. **Timeout semantics.** Exception (proposed) or `return false`? The fork returns the ack flag and
   lets the exception escape by accident; this spec makes the exception deliberate.
2. **Default `confirm_timeout`.** `10.0` seconds is the fork's value. `0` (block forever) matches
   php-amqplib's own default but is a poor default inside a web request.
3. **php-amqplib 2.12 verification.** `composer.json` still allows `^2.12.2`; `confirm_select`,
   `set_ack_handler`, `set_nack_handler` and `wait_for_pending_acks` all exist there, but the CI
   matrix only installs the highest allowed version.
4. **`isConfirmSelect()` on `ProducerInterface`.** Deliberately left off — see §3.6. Worth a
   separate issue for the next major, since callers that type against the interface currently
   cannot ask a producer whether confirms are on without an `instanceof` check.
