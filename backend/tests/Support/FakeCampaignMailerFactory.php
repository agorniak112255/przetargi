<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\UserMailAccount;
use App\Services\Campaigns\UserMailerFactory;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mime\Email;
use Throwable;

/**
 * Skrzynka nadawcy w testach kampanii: Mail::build z transportem w pamięci (jak „array”), który umie też udawać
 * błędy serwera SMTP — całej skrzynki albo pojedynczego adresu.
 */
class FakeCampaignMailerFactory extends UserMailerFactory
{
    public CampaignTestTransport $transport;

    /** Błąd już przy budowie mailera (np. DecryptException hasła). */
    public ?Throwable $makeError = null;

    /** @var list<int> id kont, dla których zbudowano mailer */
    public array $made = [];

    public function __construct()
    {
        $this->transport = new CampaignTestTransport;
        Mail::extend('campaign-test', fn (): CampaignTestTransport => $this->transport);
    }

    public function make(UserMailAccount $account): Mailer
    {
        $this->made[] = (int) $account->id;
        if ($this->makeError !== null) {
            throw $this->makeError;
        }

        return Mail::build(['transport' => 'campaign-test']);
    }

    /** @return list<Email> */
    public function emails(): array
    {
        return array_map(static function (SentMessage $m): Email {
            $original = $m->getOriginalMessage();
            assert($original instanceof Email);

            return $original;
        }, $this->transport->sent);
    }

    /** @return list<string> */
    public function recipients(): array
    {
        return array_map(static fn (Email $e): string => $e->getTo()[0]->getAddress(), $this->emails());
    }
}
