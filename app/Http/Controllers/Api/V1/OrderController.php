<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\OrderActionRequest;
use App\Http\Requests\Api\V1\PayOrderRequest;
use App\Http\Requests\Api\V1\ReturnOrderRequest;
use App\Http\Requests\Api\V1\StoreOrderRequest;
use App\Http\Resources\Api\V1\OrderResource;
use App\Models\Order;
use App\Services\OrderReturnService;
use App\Services\OrderService;
use App\Services\ReceiptService;
use App\Support\Access;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class OrderController extends Controller
{
    public function __construct(private readonly OrderService $orders, private readonly OrderReturnService $returns)
    {
    }

    public function index(OrderActionRequest $request): AnonymousResourceCollection
    {
        $orders = Order::with(['items', 'returns.items'])
            ->when(Access::branchScope($request->user()) !== null, fn ($q) => $q->where('branch_id', Access::branchScope($request->user())))
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
            $order = $this->orders->create($request->orderHeader(), $request->validated('items'), $request->user());
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

    /**
     * Retur order yang sudah dibayar (Owner/Manager). Idempoten per uuid retur: 201 = baru, 200 = sudah pernah.
     * mode: waste (bawaan, makanan dibuang) | restock (bahan kembali ke stok).
     */
    public function refund(ReturnOrderRequest $request, string $uuid): JsonResponse
    {
        $order = $this->find($request, $uuid);

        try {
            $return = $this->returns->create($order, $request->payload(), $request->user());
        } catch (AuthorizationException $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        } catch (\Throwable $e) {
            return $this->fail($e, $order);
        }

        return (new OrderResource($this->find($request, $uuid)))->response()->setStatusCode($return->wasRecentlyCreated ? 201 : 200);
    }

    /** Data struk untuk dicetak (kertas thermal). Hanya order yang sudah dibayar. */
    public function receipt(OrderActionRequest $request, string $uuid, ReceiptService $receipts): JsonResponse
    {
        try {
            return response()->json(['data' => $receipts->build($this->find($request, $uuid))]);
        } catch (\Throwable $e) {
            return $this->fail($e);
        }
    }

    /** Data struk retur (kertas thermal). */
    public function returnReceipt(OrderActionRequest $request, string $uuid, string $returnUuid, ReceiptService $receipts): JsonResponse
    {
        $return = $this->find($request, $uuid)->returns->firstWhere('uuid', $returnUuid);
        abort_if($return === null, 404);

        return response()->json(['data' => $receipts->buildReturn($return)]);
    }

    private function find(Request $request, string $uuid): Order
    {
        return Order::with(['items', 'returns.items'])->where('uuid', $uuid)
            ->when(Access::branchScope($request->user()) !== null, fn ($q) => $q->where('branch_id', Access::branchScope($request->user())))->firstOrFail();
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