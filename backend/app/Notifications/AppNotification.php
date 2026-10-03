<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Services\Notifications\AppNotificationMessage;
use Illuminate\Notifications\Notification;

/**
 * Powiadomienie w dzwonku (kanał database) dla każdego zdarzenia ze wspólnej obsługi powiadomień
 * (NotificationDispatcher). notifications.data: {type, title, body, url, message, …pola z message->data}.
 * `message` = tytuł — tak czytają je starsze wersje dzwonka.
 */
class AppNotification extends Notification
{
    public function __construct(public readonly AppNotificationMessage $message) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            ...$this->message->data,
            'type' => $this->message->event,
            'title' => $this->message->title,
            'body' => $this->message->body,
            'url' => $this->message->url,
            'message' => $this->message->title,
        ];
    }
}
