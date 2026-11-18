<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\OrderActionRequest;
use App\Http\Requests\Api\V1\PayOrderRequest;
use App\Http\Requests\Api\V1\StoreOrderRequest;
use App\Http\Resources\Api\V1\OrderResource;
use App\Models\Order;
use App\Services\OrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class OrderController extends Controller
{
    public function __construct(private readonly OrderService $orders)
    {
    }

    public function index(OrderActionRequest $request): AnonymousResourceCollection
    {
        $orders = Order::with('items')
            ->where('branch_id', $request->user()->branch_id)
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($request->query('date'), fn ($q, $d) => $q->whereDate('created_at', $d))
            ->latest('id')->limit(100)->get();

        return OrderResource::collection($orders);
    }

    public function show(OrderActionRequest $request, string $uuid): OrderResource
    {
        return new OrderResource($this->find($request, $uuid));
    }

    /**
     * Buat order (idempoten per uuid). Bila `pay` diisi, order langsung dibayar.
     * Bila pembayaran gagal, order tetap tersimpan Open dan responsnya 422 berisi order itu,
     * sehingga klien cukup memanggil /pay lagi tanpa membuat order baru.
     */
    public function store(StoreOrderRequest $request): JsonResponse
    {
        try {
            $order = $this->orders->create($request->header(), $request->validated('items'), $request->user());
        } catch (\Throwable $e) {
            return $this->fail($e);
        }
        $created = $order->wasRecentlyCreated;

        if ($pay = $request->validated('pay')) {
            try {
                $order = $this->orders->pay($order, $pay['method'], $pay['amount_paid'] ?? null, $request->user()->id);
            } catch (\Throwable $e) {
                return $this->fail($e, $order);
            }
        }

        return (new OrderResource($order->load('items')))->response()->setStatusCode($created ? 201 : 200);
    }

    public function pay(PayOrderRequest $request, string $uuid): JsonResponse
    {
        $order = $this->find($request, $uuid);

        try {
            $order = $this->orders->pay($order, $request->validated('method'), $request->validated('amount_paid'), $request->user()->id);
        } catch (\Throwable $e) {
            return $this->fail($e, $order);
        }

        return (new OrderResource($order))->response();
    }

    public function void(OrderActionRequest $request, string $uuid): JsonResponse
    {
        $order = $this->find($request, $uuid);

        try {
            $order = $this->orders->void($order);
        } catch (\Throwable $e) {
            return $this->fail($e, $order);
        }

        return (new OrderResource($order->load('items')))->response();
    }

    private function find(OrderActionRequest $request, string $uuid): Order
    {
        return Order::with('items')->where('uuid', $uuid)
            ->where('branch_id', $request->user()->branch_id)->firstOrFail();
    }

    private function fail(\Throwable $e, ?Order $order = null): JsonResponse
    {
        if (! ($e instanceof \RuntimeException || $e instanceof \InvalidArgumentException)) {
            throw $e; // kesalahan sistem: biarkan ditangani handler (500)
        }

        return response()->json(array_filter([
            'message' => $e->getMessage(),
            'order' => $order ? new OrderResource($order->load('items')) : null,
        ]), 422);
    }
}
