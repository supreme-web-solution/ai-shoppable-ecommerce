<?php

namespace App\Services\Leads;

use App\Models\Lead;
use App\Models\Order;

class LeadCaptureService
{
    public function captureFromCheckout(Order $order, string $email, ?string $fullName = null): Lead
    {
        $email = mb_strtolower(trim($email));

        return Lead::query()->updateOrCreate(
            [
                'team_id' => $order->team_id,
                'email' => $email,
            ],
            [
                'full_name' => $fullName ?: data_get($order->metadata, 'billing.name'),
                'source' => 'checkout',
                'source_id' => $order->id,
                'last_activity_at' => now(),
                'metadata' => [
                    'order_id' => $order->id,
                    'order_number' => $order->order_number,
                    'order_status' => $order->status,
                ],
            ],
        );
    }

    public function captureFromWebinar(
        int $teamId,
        string $email,
        string $fullName,
        int $registrationId,
        int $liveShowId,
        string $liveShowTitle,
    ): Lead {
        $email = mb_strtolower(trim($email));

        return Lead::query()->updateOrCreate(
            [
                'team_id' => $teamId,
                'email' => $email,
            ],
            [
                'full_name' => trim($fullName),
                'source' => 'webinar',
                'source_id' => $registrationId,
                'last_activity_at' => now(),
                'metadata' => [
                    'live_show_id' => $liveShowId,
                    'live_show_title' => $liveShowTitle,
                    'registration_id' => $registrationId,
                ],
            ],
        );
    }

    public function markOrderPaid(Order $order): void
    {
        if (! $order->customer_email) {
            return;
        }

        Lead::query()
            ->where('team_id', $order->team_id)
            ->where('email', mb_strtolower($order->customer_email))
            ->update([
                'source' => 'checkout',
                'source_id' => $order->id,
                'last_activity_at' => now(),
                'metadata' => [
                    'order_id' => $order->id,
                    'order_number' => $order->order_number,
                    'order_status' => 'paid',
                    'total_amount' => (string) $order->total_amount,
                    'currency' => $order->currency,
                ],
            ]);
    }
}
