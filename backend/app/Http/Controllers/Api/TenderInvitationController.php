<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\TenderInvitationMail;
use App\Models\Tender;
use App\Models\TenderInvitation;
use App\Models\User;
use App\Services\Notifications\AppNotificationMessage;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\TenderActivityLogger;
use App\Support\PolishTime;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class TenderInvitationController extends Controller
{
    public function __construct(
        private readonly TenderActivityLogger $activities,
        private readonly NotificationDispatcher $notifications,
    ) {}

    public function index(Tender $tender): JsonResponse
    {
        $rows = $tender->invitations()
            ->with([
                'user:id,name,email,role',
                'inviter:id,name,email,role',
            ])
            ->orderByDesc('id')
            ->get()
            ->map(fn (TenderInvitation $invitation): array => $this->serialize($invitation));

        return response()->json(['data' => $rows]);
    }

    public function store(Request $request, Tender $tender): JsonResponse
    {
        $data = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $invitee = User::query()->findOrFail((int) $data['user_id']);
        $inviter = $request->user();
        assert($inviter instanceof User);

        if ((int) $invitee->id === (int) $inviter->id) {
            throw ValidationException::withMessages([
                'user_id' => 'Nie możesz zaprosić samego siebie.',
            ]);
        }

        if ((int) $tender->owner_id === (int) $invitee->id) {
            throw ValidationException::withMessages([
                'user_id' => 'Ta osoba jest już opiekunem przetargu.',
            ]);
        }

        if ($tender->invitations()->where('user_id', $invitee->id)->exists()) {
            throw ValidationException::withMessages([
                'user_id' => 'Ta osoba jest już zaproszona do tego przetargu.',
            ]);
        }

        $invitation = TenderInvitation::query()->create([
            'tender_id' => $tender->id,
            'user_id' => $invitee->id,
            'invited_by' => $inviter->id,
            'note' => $data['note'] ?? null,
        ]);

        $tender->loadMissing('client:id,name');
        $tenderUrl = rtrim((string) config('app.frontend_url'), '/').'/tenders/'.$tender->id;

        // dzwonek i e-mail według preferencji zaproszonego; e-mail od razu (bez defer), bo odpowiedź mówi,
        // czy wyszedł (email_sent, email_status)
        $deadline = PolishTime::formatDeadline($tender);
        $sent = $this->notifications->send($invitee, new AppNotificationMessage(
            event: 'tender_invitation',
            subjectKey: 'invitation:'.$invitation->id,
            title: sprintf('%s zaprasza Cię do przetargu %s', $inviter->name, $tender->number),
            body: implode("\n", array_filter([
                $tender->title,
                $tender->client?->name !== null ? 'Klient: '.$tender->client->name : null,
                $deadline !== '' ? 'Termin składania: '.$deadline : null,
                $invitation->note !== null && $invitation->note !== '' ? 'Wiadomość: '.$invitation->note : null,
            ])),
            url: '/tenders/'.$tender->id,
            data: [
                'invitation_id' => $invitation->id,
                'tender_id' => $tender->id,
                'tender_number' => $tender->number,
                'tender_title' => $tender->title,
                'inviter_id' => $inviter->id,
                'inviter_name' => $inviter->name,
                'note' => $invitation->note,
            ],
            mailable: new TenderInvitationMail($invitation, $tender, $invitee, $inviter, $tenderUrl),
        ));

        // sent = wyszedł, failed = błąd poczty, opted_out = zaproszony nie chce e-maili o zaproszeniach (albo nie ma
        // adresu) — wtedy zapraszający nie powinien widzieć „awarii poczty”
        $mailResult = $sent[(int) $invitee->id]['mail'] ?? null;
        $emailStatus = match ($mailResult) {
            true => 'sent',
            false => 'failed',
            default => 'opted_out',
        };
        $emailSent = $mailResult === true;
        if ($emailSent) {
            $invitation->email_sent_at = now();
            $invitation->save();
        }

        $this->activities->log($tender, 'invitation_added', $inviter, null, [
            'user_id' => $invitee->id,
            'user_name' => $invitee->name,
            'user_email' => $invitee->email,
            'email_sent' => $emailSent,
        ]);

        $tender->forceFill(['last_activity_at' => now()])->save();

        return response()->json([
            'data' => $this->serialize($invitation->load([
                'user:id,name,email,role',
                'inviter:id,name,email,role',
            ])),
            'email_sent' => $emailSent,
            'email_status' => $emailStatus,
        ], 201);
    }

    public function destroy(Request $request, Tender $tender, TenderInvitation $invitation): JsonResponse
    {
        if ((int) $invitation->tender_id !== (int) $tender->id) {
            return response()->json(['message' => 'Zaproszenie nie należy do tego przetargu.'], 404);
        }

        $invitee = $invitation->user;
        $inviter = $request->user();
        assert($inviter instanceof User);

        $invitation->delete();

        $this->activities->log($tender, 'invitation_removed', $inviter, null, [
            'user_id' => $invitee?->id,
            'user_name' => $invitee?->name,
            'user_email' => $invitee?->email,
        ]);

        $tender->forceFill(['last_activity_at' => now()])->save();

        return response()->json(['message' => 'OK']);
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(TenderInvitation $invitation): array
    {
        return [
            'id' => $invitation->id,
            'tender_id' => $invitation->tender_id,
            'user_id' => $invitation->user_id,
            'invited_by' => $invitation->invited_by,
            'note' => $invitation->note,
            'email_sent_at' => $invitation->email_sent_at?->toIso8601String(),
            'created_at' => $invitation->created_at?->toIso8601String(),
            'user' => $invitation->user ? [
                'id' => $invitation->user->id,
                'name' => $invitation->user->name,
                'email' => $invitation->user->email,
                'role' => $invitation->user->role,
            ] : null,
            'inviter' => $invitation->inviter ? [
                'id' => $invitation->inviter->id,
                'name' => $invitation->inviter->name,
                'email' => $invitation->inviter->email,
                'role' => $invitation->inviter->role,
            ] : null,
        ];
    }
}
