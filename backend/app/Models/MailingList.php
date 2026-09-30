<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/** Grupa odbiorców: własna użytkownika albo wspólna (is_shared, prowadzi administrator). */
class MailingList extends Model
{
    protected $fillable = [
        'user_id',
        'name',
        'is_shared',
    ];

    protected function casts(): array
    {
        return [
            'is_shared' => 'boolean',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsToMany<Contact, $this> */
    public function contacts(): BelongsToMany
    {
        return $this->belongsToMany(Contact::class, 'mailing_list_contact')
            ->using(MailingListContact::class)
            ->withPivot(['id', 'basis', 'basis_note', 'added_by'])
            ->withTimestamps();
    }
}
