<?php

declare(strict_types=1);

namespace Tests\Support;

use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Throwable;

/** Transport w pamięci z błędami na życzenie. */
class CampaignTestTransport extends AbstractTransport
{
    /** @var list<SentMessage> */
    public array $sent = [];

    public ?Throwable $failAll = null;

    /** @var array<string, Throwable> adres → błąd */
    public array $failFor = [];

    protected function doSend(SentMessage $message): void
    {
        if ($this->failAll !== null) {
            throw $this->failAll;
        }
        foreach ($message->getEnvelope()->getRecipients() as $recipient) {
            if (isset($this->failFor[$recipient->getAddress()])) {
                throw $this->failFor[$recipient->getAddress()];
            }
        }
        $this->sent[] = $message;
    }

    public function __toString(): string
    {
        return 'campaign-test://';
    }
}
