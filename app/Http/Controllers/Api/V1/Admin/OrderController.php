<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\OrderResource;
use App\Models\Order;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    protected function resolveTeamId(Request $request): int
    {
        $teamId = $request->integer('team_id', $request->user()->team_id);
        abort_unless($request->user()->team_id === $teamId || $request->user()->teams()->whereKey($teamId)->exists(), 403);

        return $teamId;
    }

    public function index(Request $request)
    {
        $teamId = $this->resolveTeamId($request);
        $perPage = min(max((int) $request->input('per_page', 20), 1), 100);

        $orders = Order::query()
            ->where('team_id', $teamId)
            ->with('items')
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')->toString()))
            ->when($request->filled('search'), function ($query) use ($request) {
                $search = $request->string('search')->toString();

                $query->where(function ($inner) use ($search) {
                    $inner->where('order_number', 'like', "%{$search}%")
                        ->orWhere('customer_email', 'like', "%{$search}%");
                });
            })
            ->latest('ordered_at')
            ->latest('id')
            ->paginate($perPage);

        return OrderResource::collection($orders);
    }

    public function show(Request $request, Order $order)
    {
        $teamId = $this->resolveTeamId($request);
        abort_unless($order->team_id === $teamId, 404);

        $order->load('items');

        return new OrderResource($order);
    }
}
