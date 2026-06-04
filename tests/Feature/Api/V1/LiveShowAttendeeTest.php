<?php

namespace Tests\Feature\Api\V1;

use App\Jobs\SendWebinarRegistrationEmailBatchJob;
use App\Mail\WebinarRegistrationMail;
use App\Models\LiveShow;
use App\Models\LiveShowRegistration;
use App\Models\Team;
use App\Models\User;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class LiveShowAttendeeTest extends TestCase
{
    use RefreshDatabase;

    public function test_notify_all_attendees_queues_email_batches_of_ten(): void
    {
        Queue::fake();

        [$team, $owner, $liveShow] = $this->createLiveShowWithOwner();
        $this->seedRegistrations($liveShow, 15);

        $response = $this->actingAs($owner)->postJson(
            "/api/v1/admin/live-shows/{$liveShow->id}/attendees/notify",
        );

        $response->assertOk()
            ->assertJsonPath('data.attendees', 15)
            ->assertJsonPath('data.email_batches_queued', 2);

        Queue::assertPushed(SendWebinarRegistrationEmailBatchJob::class, 2);

        Queue::assertPushed(SendWebinarRegistrationEmailBatchJob::class, function (SendWebinarRegistrationEmailBatchJob $job) use ($liveShow): bool {
            return $job->liveShowId === $liveShow->id && count($job->registrationIds) === 10;
        });

        Queue::assertPushed(SendWebinarRegistrationEmailBatchJob::class, function (SendWebinarRegistrationEmailBatchJob $job) use ($liveShow): bool {
            return $job->liveShowId === $liveShow->id && count($job->registrationIds) === 5;
        });
    }

    public function test_import_attendees_registers_rows_and_queues_emails(): void
    {
        Queue::fake();

        [$team, $owner, $liveShow] = $this->createLiveShowWithOwner();

        $csv = implode("\n", [
            'full_name,email',
            'Alice Example,alice@example.com',
            'Bob Example,bob@example.com',
        ]);

        $file = UploadedFile::fake()->createWithContent('attendees.csv', $csv);

        $response = $this->actingAs($owner)->postJson(
            "/api/v1/admin/live-shows/{$liveShow->id}/attendees/import",
            ['file' => $file],
        );

        $response->assertOk()
            ->assertJsonPath('data.imported', 2)
            ->assertJsonPath('data.updated', 0)
            ->assertJsonPath('data.attendees', 2)
            ->assertJsonPath('data.email_batches_queued', 1);

        $this->assertDatabaseHas('live_show_registrations', [
            'live_show_id' => $liveShow->id,
            'email' => 'alice@example.com',
            'full_name' => 'Alice Example',
        ]);

        Queue::assertPushed(SendWebinarRegistrationEmailBatchJob::class, 1);
    }

    public function test_import_accepts_email_only_column_with_header(): void
    {
        Queue::fake();

        [$team, $owner, $liveShow] = $this->createLiveShowWithOwner();

        $csv = implode("\n", [
            'email',
            'jane@example.com',
            'john.smith@example.com',
        ]);

        $file = UploadedFile::fake()->createWithContent('attendees.csv', $csv);

        $response = $this->actingAs($owner)->postJson(
            "/api/v1/admin/live-shows/{$liveShow->id}/attendees/import",
            ['file' => $file],
        );

        $response->assertOk()
            ->assertJsonPath('data.imported', 2);

        $this->assertDatabaseHas('live_show_registrations', [
            'live_show_id' => $liveShow->id,
            'email' => 'jane@example.com',
            'full_name' => 'Jane',
        ]);

        $this->assertDatabaseHas('live_show_registrations', [
            'live_show_id' => $liveShow->id,
            'email' => 'john.smith@example.com',
            'full_name' => 'John Smith',
        ]);
    }

    public function test_import_accepts_plain_email_list_without_header(): void
    {
        Queue::fake();

        [$team, $owner, $liveShow] = $this->createLiveShowWithOwner();

        $csv = "pat@example.com\nsue_doe@example.org";

        $file = UploadedFile::fake()->createWithContent('emails.csv', $csv);

        $response = $this->actingAs($owner)->postJson(
            "/api/v1/admin/live-shows/{$liveShow->id}/attendees/import",
            ['file' => $file],
        );

        $response->assertOk()
            ->assertJsonPath('data.imported', 2);

        $this->assertDatabaseHas('live_show_registrations', [
            'email' => 'pat@example.com',
            'full_name' => 'Pat',
        ]);
    }

    public function test_attendees_endpoint_paginates_registrations(): void
    {
        [$team, $owner, $liveShow] = $this->createLiveShowWithOwner();

        for ($i = 1; $i <= 55; $i++) {
            LiveShowRegistration::query()->create([
                'live_show_id' => $liveShow->id,
                'full_name' => "Attendee {$i}",
                'email' => "attendee{$i}@example.com",
                'registered_at' => now()->subMinutes($i),
            ]);
        }

        $pageOne = $this->actingAs($owner)->getJson(
            "/api/v1/admin/live-shows/{$liveShow->id}/attendees?per_page=50&page=1",
        );

        $pageOne->assertOk()
            ->assertJsonPath('per_page', 50)
            ->assertJsonPath('total', 55)
            ->assertJsonPath('last_page', 2)
            ->assertJsonCount(50, 'data');

        $pageTwo = $this->actingAs($owner)->getJson(
            "/api/v1/admin/live-shows/{$liveShow->id}/attendees?per_page=50&page=2",
        );

        $pageTwo->assertOk()
            ->assertJsonCount(5, 'data');
    }

    public function test_email_batch_job_sends_registration_mail(): void
    {
        Mail::fake();

        [$team, $owner, $liveShow] = $this->createLiveShowWithOwner();

        $registration = LiveShowRegistration::query()->create([
            'live_show_id' => $liveShow->id,
            'full_name' => 'Pat Example',
            'email' => 'pat@example.com',
            'registered_at' => now(),
        ]);

        (new SendWebinarRegistrationEmailBatchJob($liveShow->id, [$registration->id]))->handle();

        Mail::assertSent(WebinarRegistrationMail::class, function (WebinarRegistrationMail $mail) use ($registration): bool {
            return $mail->registration->is($registration)
                && $mail->hasTo('pat@example.com');
        });
    }

    /**
     * @return array{0: Team, 1: User, 2: LiveShow}
     */
    protected function createLiveShowWithOwner(): array
    {
        [$team, $owner] = $this->createTeamWithOwner();

        $video = Video::query()->create([
            'team_id' => $team->id,
            'title' => 'Webinar Video',
            'source' => 'uploaded',
            'status' => 'ready',
            'visibility' => 'public',
        ]);

        $liveShow = LiveShow::query()->create([
            'team_id' => $team->id,
            'video_id' => $video->id,
            'title' => 'Product Launch',
            'status' => 'scheduled',
            'starts_at' => now()->addDay(),
        ]);

        return [$team, $owner, $liveShow];
    }

    protected function seedRegistrations(LiveShow $liveShow, int $count): void
    {
        for ($i = 1; $i <= $count; $i++) {
            LiveShowRegistration::query()->create([
                'live_show_id' => $liveShow->id,
                'full_name' => "Attendee {$i}",
                'email' => "attendee{$i}@example.com",
                'registered_at' => now(),
            ]);
        }
    }

    /**
     * @return array{0: Team, 1: User}
     */
    protected function createTeamWithOwner(): array
    {
        $owner = User::factory()->create();
        $team = Team::query()->create([
            'name' => 'Test Team',
            'slug' => 'test-team-'.fake()->unique()->slug(),
            'owner_user_id' => $owner->id,
            'checkout_mode' => 'hybrid',
            'external_provider' => 'none',
        ]);
        $owner->update(['team_id' => $team->id]);
        $team->users()->attach($owner->id, ['role' => 'owner']);

        return [$team, $owner];
    }
}
