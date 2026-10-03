<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Models\User;
use App\Services\MailSettingsService;
use InvalidArgumentException;

/**
 * Preferencje powiadomień: zdarzenie × kanał (dzwonek / e-mail) i momenty przypomnienia o terminie składania.
 * Wartości domyślne są w config/notifications.php; w users.notification_preferences zapisujemy tylko to, czym
 * użytkownik różni się od domyślnych — zmiana domyślnych w konfiguracji obejmuje każdego, kto ich nie ruszał.
 *
 * Zapis w bazie: {"events": {"tender_mention": {"mail": false}}, "deadline_offsets": ["7d", "3h"]}.
 */
class NotificationPreferences
{
    public const CHANNELS = ['bell', 'mail'];

    public function __construct(private readonly MailSettingsService $mailSettings) {}

    /**
     * Zdarzenia z konfiguracji.
     *
     * @return array<string, array{label: string, description: string, default_bell: bool, default_mail: bool, permission?: string|list<string>}>
     */
    public function events(): array
    {
        $events = config('notifications.events');

        return is_array($events) ? $events : [];
    }

    /**
     * Czy to zdarzenie w ogóle dotyczy tej osoby (np. alert systemu tylko z uprawnieniami). `permission` w config
     * to jedno uprawnienie albo lista — wtedy potrzebne są wszystkie.
     */
    public function available(User $user, string $event): bool
    {
        $definition = $this->events()[$event] ?? null;
        if (! is_array($definition)) {
            return false;
        }
        foreach ($this->requiredPermissions($definition) as $permission) {
            if (! $user->can($permission)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Uprawnienia wymagane do zdarzenia (wszystkie naraz).
     *
     * @param  array<string, mixed>  $definition
     * @return list<string>
     */
    public function requiredPermissions(array $definition): array
    {
        $permission = $definition['permission'] ?? null;
        $list = is_array($permission) ? $permission : [$permission];

        return array_values(array_filter($list, static fn (mixed $p): bool => is_string($p) && $p !== ''));
    }

    public function wants(User $user, string $event, string $channel): bool
    {
        if (! in_array($channel, self::CHANNELS, true) || ! $this->available($user, $event)) {
            return false;
        }

        return $this->for($user)['events'][$event][$channel] ?? false;
    }

    /**
     * Wybrane momenty przypomnienia o terminie składania (klucze z deadline_offset_options, w ich kolejności).
     *
     * @return list<string>
     */
    public function offsets(User $user): array
    {
        return $this->for($user)['deadline_offsets'];
    }

    /**
     * Preferencje po złożeniu domyślnych z nadpisaniami — tylko zdarzenia dostępne dla tej osoby.
     *
     * @return array{events: array<string, array{bell: bool, mail: bool}>, deadline_offsets: list<string>}
     */
    public function for(User $user): array
    {
        $stored = $this->stored($user);
        $events = [];
        foreach ($this->events() as $key => $definition) {
            if (! $this->available($user, $key)) {
                continue;
            }
            $override = is_array($stored['events'][$key] ?? null) ? $stored['events'][$key] : [];
            $events[$key] = [
                'bell' => is_bool($override['bell'] ?? null) ? $override['bell'] : (bool) ($definition['default_bell'] ?? false),
                'mail' => is_bool($override['mail'] ?? null) ? $override['mail'] : (bool) ($definition['default_mail'] ?? false),
            ];
        }

        $offsets = is_array($stored['deadline_offsets'] ?? null)
            ? $this->normalizeOffsets($stored['deadline_offsets'])
            : $this->defaultOffsets();

        return ['events' => $events, 'deadline_offsets' => $offsets];
    }

    /**
     * Zapis z ekranu „Moje konto › Powiadomienia”. Zdarzenia, których nie podano (albo niedostępne dla tej
     * osoby), zostają bez zmian. Zapisujemy tylko różnice od wartości domyślnych.
     *
     * @param  array{events?: array<string, array{bell?: bool, mail?: bool}>, deadline_offsets?: list<string>}  $input
     */
    public function update(User $user, array $input): void
    {
        $stored = $this->stored($user);
        $definitions = $this->events();
        $current = $this->for($user);

        foreach ($input['events'] ?? [] as $key => $channels) {
            if (! isset($definitions[$key]) || ! $this->available($user, (string) $key) || ! is_array($channels)) {
                continue;
            }
            $override = [];
            foreach (self::CHANNELS as $channel) {
                $value = array_key_exists($channel, $channels) ? (bool) $channels[$channel] : $current['events'][$key][$channel];
                if ($value !== (bool) ($definitions[$key]['default_'.$channel] ?? false)) {
                    $override[$channel] = $value;
                }
            }
            if ($override === []) {
                unset($stored['events'][$key]);
            } else {
                $stored['events'][$key] = $override;
            }
        }

        if (array_key_exists('deadline_offsets', $input)) {
            if (! is_array($input['deadline_offsets'])) {
                throw new InvalidArgumentException('deadline_offsets musi być listą.');
            }
            $offsets = $this->normalizeOffsets($input['deadline_offsets']);
            if ($offsets === $this->defaultOffsets()) {
                unset($stored['deadline_offsets']);
            } else {
                $stored['deadline_offsets'] = $offsets;
            }
        }

        if (($stored['events'] ?? []) === []) {
            unset($stored['events']);
        }

        $user->forceFill(['notification_preferences' => $stored === [] ? null : $stored])->save();
    }

    /**
     * Odpowiedź GET/PUT /me/notification-preferences (typ NotificationPreferences we frontendzie).
     *
     * @return array<string, mixed>
     */
    public function payload(User $user): array
    {
        $resolved = $this->for($user);
        $events = [];
        foreach ($this->events() as $key => $definition) {
            if (! isset($resolved['events'][$key])) {
                continue;
            }
            $events[] = [
                'key' => $key,
                'label' => (string) ($definition['label'] ?? $key),
                'description' => (string) ($definition['description'] ?? ''),
                'bell' => $resolved['events'][$key]['bell'],
                'mail' => $resolved['events'][$key]['mail'],
                'default_bell' => (bool) ($definition['default_bell'] ?? false),
                'default_mail' => (bool) ($definition['default_mail'] ?? false),
            ];
        }

        $options = [];
        foreach ($this->offsetOptions() as $key => $option) {
            $options[] = [
                'key' => $key,
                'label' => (string) ($option['label'] ?? $key),
                'needs_time' => (bool) ($option['needs_time'] ?? false),
            ];
        }

        return [
            'events' => $events,
            'deadline_offsets' => $resolved['deadline_offsets'],
            'deadline_offset_options' => $options,
            'email' => (string) $user->email,
            'mail_configured' => $this->mailConfigured(),
        ];
    }

    /**
     * Czy poczta wychodząca aplikacji (Administracja › SMTP albo .env) w ogóle może wysłać e-mail.
     * Mailer „log” i „array” tylko zapisują wiadomość — dla ludzi to brak poczty.
     */
    public function mailConfigured(): bool
    {
        $cfg = $this->mailSettings->resolve();
        if (in_array($cfg['mailer'], ['log', 'array'], true) || $cfg['from_address'] === null) {
            return false;
        }

        return $cfg['mailer'] !== 'smtp' || $cfg['host'] !== null;
    }

    /**
     * @return array<string, array{label?: string, needs_time?: bool}>
     */
    public function offsetOptions(): array
    {
        $options = config('notifications.deadline_offset_options');

        return is_array($options) ? $options : [];
    }

    /** @return list<string> */
    private function defaultOffsets(): array
    {
        $defaults = config('notifications.deadline_offsets');

        return $this->normalizeOffsets(is_array($defaults) ? $defaults : []);
    }

    /**
     * Tylko znane momenty, bez powtórek, w kolejności z konfiguracji.
     *
     * @param  array<mixed>  $offsets
     * @return list<string>
     */
    private function normalizeOffsets(array $offsets): array
    {
        $wanted = array_filter($offsets, 'is_string');

        return array_values(array_filter(
            array_keys($this->offsetOptions()),
            static fn (string $key): bool => in_array($key, $wanted, true),
        ));
    }

    /**
     * @return array{events?: array<string, array<string, bool>>, deadline_offsets?: list<string>}
     */
    private function stored(User $user): array
    {
        $raw = $user->notification_preferences;
        if (! is_array($raw)) {
            return [];
        }
        $out = [];
        if (is_array($raw['events'] ?? null)) {
            foreach ($raw['events'] as $key => $channels) {
                if (is_string($key) && is_array($channels)) {
                    $out['events'][$key] = array_filter(
                        array_intersect_key($channels, array_flip(self::CHANNELS)),
                        'is_bool',
                    );
                }
            }
        }
        if (is_array($raw['deadline_offsets'] ?? null)) {
            $out['deadline_offsets'] = array_values(array_filter($raw['deadline_offsets'], 'is_string'));
        }

        return $out;
    }
}
