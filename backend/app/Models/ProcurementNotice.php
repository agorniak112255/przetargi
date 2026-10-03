<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Ogłoszenie z Biuletynu Zamówień Publicznych (o zamówieniu albo o wyniku). `notice_number` z wersją
 * („2026/BZP 00431178/01”), `bzp_number` bez wersji; `ocds_id` (tenderId w API) łączy ogłoszenie o zamówieniu
 * z ogłoszeniem o wyniku. `parsed` — części odczytane parserem w wersji `parser_version`.
 */
class ProcurementNotice extends Model
{
    public const TYPE_CONTRACT = 'ContractNotice';

    public const TYPE_RESULT = 'TenderResultNotice';

    public const SOURCE_BZP = 'bzp';

    protected $fillable = [
        'source',
        'notice_type',
        'notice_number',
        'bzp_number',
        'ocds_id',
        'preceding_bzp_number',
        'object_id',
        'published_at',
        'submitting_offers_at',
        'order_object',
        'cpv_codes',
        'organization_name',
        'organization_city',
        'organization_province',
        'organization_nip',
        'procedure_result',
        'contractors',
        'parsed',
        'parser_version',
        'html_body',
        'fetched_at',
    ];

    /** pełny HTML ogłoszenia (~60 kB) nie trafia do odpowiedzi API */
    protected $hidden = [
        'html_body',
    ];

    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
            'submitting_offers_at' => 'datetime',
            'fetched_at' => 'datetime',
            'cpv_codes' => 'array',
            'contractors' => 'array',
            'parsed' => 'array',
            'parser_version' => 'integer',
        ];
    }

    public function contractTenders(): HasMany
    {
        return $this->hasMany(Tender::class, 'contract_notice_id');
    }

    public function resultTenders(): HasMany
    {
        return $this->hasMany(Tender::class, 'result_notice_id');
    }

    /** Decyzja „pominięte” postępowania (zakładka Ogłoszenia) — wspólna dla zespołu i dla wszystkich wersji ogłoszenia. */
    public function skip(): HasOne
    {
        return $this->hasOne(ProcurementNoticeSkip::class, 'bzp_number', 'bzp_number');
    }
}
