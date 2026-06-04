<?php

namespace App\Jobs;

use App\Mail\WebinarRegistrationMail;
use App\Models\LiveShow;
use App\Models\LiveShowRegistration;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;

class SendWebinarRegistrationEmailBatchJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 120;

    public int $tries = 3;

    /**
     * @param  list<int>  $registrationIds
     */
    public function __construct(
        public int $liveShowId,
        public array $registrationIds,
    ) {
        $this->onQueue('mail');
    }

    public function handle(): void
    {
        $liveShow = LiveShow::query()->find($this->liveShowId);

        if ($liveShow === null) {
            return;
        }

        LiveShowRegistration::query()
            ->where('live_show_id', $liveShow->id)
            ->whereIn('id', $this->registrationIds)
            ->orderBy('id')
            ->each(function (LiveShowRegistration $registration) use ($liveShow): void {
                Mail::to($registration->email)->send(
                    new WebinarRegistrationMail($liveShow, $registration),
                );
            });
    }
}
