<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Przebieg zadania harmonogramu (zapisuje App\Services\System\ScheduledTaskRecorder). `task` = klucz zadania
 * z config/system_health.php; `output_tail` = końcówka komunikatu polecenia.
 */
class ScheduledTaskRun extends Model
{
    public const UPDATED_AT = null;

    public const STATUS_RUNNING = 'running';

    public const STATUS_OK = 'ok';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'task',
        'started_at',
        'finished_at',
        'duration_ms',
        'status',
        'exit_code',
        'output_tail',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'duration_ms' => 'integer',
            'exit_code' => 'integer',
        ];
    }
}
