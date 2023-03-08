<?php

namespace OldSound\RabbitMqBundle\Event;

use OldSound\RabbitMqBundle\RabbitMq\DequeuerInterface;

/**
 * Class OnConsumeEvent
 *
 * @package OldSound\RabbitMqBundle\Command
 */
class OnConsumeEvent extends AMQPEvent
{
    const NAME = AMQPEvent::ON_CONSUME;

    /**
     * OnConsumeEvent constructor.
     *
     * @param DequeuerInterface $consumer
     */
    public function __construct(DequeuerInterface $consumer)
    {
        $this->setConsumer($consumer);
    }
}
