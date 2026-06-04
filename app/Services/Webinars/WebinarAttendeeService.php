<?php

namespace App\Services\Webinars;

use App\Jobs\SendWebinarRegistrationEmailBatchJob;
use App\Models\LiveShow;
use App\Models\LiveShowRegistration;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class WebinarAttendeeService
{
    public const EMAIL_BATCH_SIZE = 10;

    public function __construct(
        protected WebinarAttendeeImportParser $importParser,
    ) {}

    public function notifyAll(LiveShow $liveShow): array
    {
        $registrationIds = LiveShowRegistration::query()
            ->where('live_show_id', $liveShow->id)
            ->orderBy('id')
            ->pluck('id')
            ->all();

        if ($registrationIds === []) {
            throw ValidationException::withMessages([
                'attendees' => 'There are no registered attendees to notify.',
            ]);
        }

        $queued = $this->queueRegistrationEmails($liveShow, $registrationIds);

        return [
            'attendees' => count($registrationIds),
            'email_batches_queued' => $queued,
        ];
    }

    /**
     * @return array{imported: int, skipped: int, attendees: int, email_batches_queued: int}
     */
    public function import(LiveShow $liveShow, UploadedFile $file): array
    {
        $rows = $this->importParser->parse($file);

        if ($rows === []) {
            throw ValidationException::withMessages([
                'file' => 'No valid attendee rows were found. Use columns: full_name, email.',
            ]);
        }

        $registrationIds = [];
        $imported = 0;
        $updated = 0;

        DB::transaction(function () use ($liveShow, $rows, &$registrationIds, &$imported, &$updated): void {
            foreach ($rows as $row) {
                $registration = LiveShowRegistration::query()
                    ->where('live_show_id', $liveShow->id)
                    ->where('email', $row['email'])
                    ->first();

                if ($registration === null) {
                    $registration = LiveShowRegistration::query()->create([
                        'live_show_id' => $liveShow->id,
                        'full_name' => $row['full_name'],
                        'email' => $row['email'],
                        'registered_at' => now(),
                        'join_count' => 0,
                    ]);
                    $imported++;
                } else {
                    $registration->update([
                        'full_name' => $row['full_name'],
                    ]);
                    $updated++;
                }

                $registrationIds[] = $registration->id;
            }
        });

        $uniqueIds = array_values(array_unique($registrationIds));
        $queued = $this->queueRegistrationEmails($liveShow, $uniqueIds);

        return [
            'imported' => $imported,
            'updated' => $updated,
            'attendees' => count($uniqueIds),
            'email_batches_queued' => $queued,
        ];
    }

    /**
     * @param  list<int>  $registrationIds
     */
    public function queueRegistrationEmails(LiveShow $liveShow, array $registrationIds): int
    {
        $ids = array_values(array_unique(array_filter($registrationIds)));

        if ($ids === []) {
            return 0;
        }

        $chunks = array_chunk($ids, self::EMAIL_BATCH_SIZE);
        $queued = 0;

        foreach ($chunks as $chunk) {
            SendWebinarRegistrationEmailBatchJob::dispatch($liveShow->id, $chunk);
            $queued++;
        }

        return $queued;
    }
}
