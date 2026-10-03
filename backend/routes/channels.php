<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

// Kanał prywatny użytkownika: zdarzenia czatu (chat.*) i kolejki dodatku do Thunderbirda (queue.updated).
// Bez warunku uprawnienia `chat` — kolejka dotyczy też osób bez czatu, a zdarzenia czatu dostają tylko uczestnicy.
Broadcast::channel('user.{id}', static fn (User $user, $id): bool => (int) $user->id === (int) $id);
