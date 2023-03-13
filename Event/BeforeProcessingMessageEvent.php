<?php

namespace OldSound\RabbitMqBundle\Event;

use OldSound\RabbitMqBundle\RabbitMq\DequeuerInterface;
use PhpAmqpLib\Message\AMQPMessage;

/**
 * Class BeforeProcessingMessageEvent
 *
 * @package OldSound\RabbitMqBundle\Command
 */
class BeforeProcessingMessageEvent extends AMQPEvent
{
    const NAME = AMQPEvent::BEFORE_PROCESSING_MESSAGE;

    /**
     * BeforeProcessingMessageEvent constructor.
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
