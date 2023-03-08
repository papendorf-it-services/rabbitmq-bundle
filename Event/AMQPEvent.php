<?php

namespace OldSound\RabbitMqBundle\Event;

use OldSound\RabbitMqBundle\RabbitMq\DequeuerInterface;
use PhpAmqpLib\Message\AMQPMessage;
use Symfony\Component\EventDispatcher\Event;

/**
 * Class AMQPEvent
 *
 * @package OldSound\RabbitMqBundle\Event
 * @codeCoverageIgnore
 */
class AMQPEvent extends AbstractAMQPEvent
{
    const ON_CONSUME                = 'on_consume';
    const ON_IDLE                   = 'on_idle';
    const BEFORE_PROCESSING_MESSAGE = 'before_processing';
    const AFTER_PROCESSING_MESSAGE  = 'after_processing';

    /**
     * @var AMQPMessage
     */
    protected $AMQPMessage;

    /**
     * @var DequeuerInterface
     */
    protected $consumer;

    /**
     * @return AMQPMessage
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

        return $this;
    }

    /**
     * @return DequeuerInterface
     */
    public function getConsumer()
    {
        return $this->consumer;
    }

    /**
     * @param DequeuerInterface $consumer
     *
     * @return AMQPEvent
     */
    public function setConsumer(DequeuerInterface $consumer)
    {
        $this->consumer = $consumer;

        return $this;
    }
}
