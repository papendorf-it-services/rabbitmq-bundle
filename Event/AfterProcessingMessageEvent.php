<?php

namespace OldSound\RabbitMqBundle\Event;

use OldSound\RabbitMqBundle\RabbitMq\DequeuerInterface;
use PhpAmqpLib\Message\AMQPMessage;

/**
 * Class AfterProcessingMessageEvent
 *
 * @package OldSound\RabbitMqBundle\Event
 */
class AfterProcessingMessageEvent extends AMQPEvent
{
    const NAME = AMQPEvent::AFTER_PROCESSING_MESSAGE;

    /**
     * AfterProcessingMessageEvent constructor.
     *
     * @param DequeuerInterface $consumer
     * @param AMQPMessage[] $AMQPMessages
     */
    public function __construct(DequeuerInterface $consumer, array $AMQPMessages)
    {
        $this->setConsumer($consumer);
        $this->setAMQPMessages($AMQPMessages);
    }
}
