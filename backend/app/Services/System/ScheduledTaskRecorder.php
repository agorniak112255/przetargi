<?php

declare(strict_types=1);

namespace App\Services\System;

use Illuminate\Console\Scheduling\Schedule;

/**
 * Zapis przebiegów zadań harmonogramu (scheduled_task_runs) dla ekranu „Stan systemu” i alertów: before /
 * onSuccess / onFailureWithOutput dla zadań z config/system_health.php. Wywoływany ostatnią linią withSchedule
 * w bootstrap/app.php.
 *
 * ZAŚLEPKA kroku 0 — pełną logikę dopisuje strumień D.
 */
class ScheduledTaskRecorder
{
    public function attach(Schedule $schedule): void
    {
        // celowo nic — strumień D
    }
}
