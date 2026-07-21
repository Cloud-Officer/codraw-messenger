<?php

namespace Draw\Component\Messenger\ManualTrigger;

use Draw\Component\Messenger\Expirable\Stamp\ExpirationStamp;
use Draw\Component\Messenger\ManualTrigger\Action\ClickMessageAction;
use Draw\Component\Messenger\ManualTrigger\Message\ManuallyTriggeredInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class ManuallyTriggeredMessageUrlGenerator
{
    public function __construct(
        private MessageBusInterface $messageBus,
        private UrlGeneratorInterface $urlGenerator,
        private string $routeName = 'draw_messenger.message_click',
    ) {
    }

    /**
     * @param string|null $type The type of message. Can be use to customise error message.
     *
     * @return string The absolute URL to activate the message
     */
    public function generateLink(
        ManuallyTriggeredInterface $message,
        \DateTimeInterface $expiration,
        ?string $type = null,
    ): string {
        $stamp = $this->messageBus
            ->dispatch(
                $message,
                [new ExpirationStamp($expiration)]
            )
            ->last(TransportMessageIdStamp::class)
        ;

        if (!$stamp instanceof TransportMessageIdStamp) {
            throw new \RuntimeException(\sprintf('Message of class [%s] was not dispatched to a transport that provides a [%s]. Make sure it is routed to a transport that supports message ids.', $message::class, TransportMessageIdStamp::class));
        }

        $parameters = [
            ClickMessageAction::MESSAGE_ID_PARAMETER_NAME => $stamp->getId(),
        ];

        if ($type) {
            $parameters['type'] = $type;
        }

        return $this->urlGenerator->generate($this->routeName, $parameters, UrlGeneratorInterface::ABSOLUTE_URL);
    }
}
