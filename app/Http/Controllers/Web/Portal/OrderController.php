<?php

namespace App\Http\Controllers\Web\Portal;

use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\View\View;

class OrderController extends Controller
{
    public function index(Request $request): View
    {
        $userId = $request->user()->id;
        $orders = Order::query()
            ->where('user_id', $userId)
            ->select(['id', 'user_id', 'status', 'subtotal', 'discount', 'total', 'currency', 'created_at'])
            ->withCount('items')
            ->with(['invoice' => fn ($query) => $query
                ->where('user_id', $userId)
                ->select(['id', 'user_id', 'order_id', 'number', 'status'])])
            ->latest('id')
            ->paginate(15);

        return view('portal.orders.index', compact('orders'));
    }

    public function show(Request $request, int $order): View
    {
        $userId = $request->user()->id;
        $order = Order::query()
            ->where('user_id', $userId)
            ->select(['id', 'user_id', 'status', 'subtotal', 'discount', 'total', 'currency', 'promo_code', 'created_at'])
            ->with([
                'items:id,order_id,product_name,billing_cycle,quantity,unit_price,setup_fee,amount',
                'invoice' => fn ($query) => $query
                    ->where('user_id', $userId)
                    ->select(['id', 'user_id', 'order_id', 'number', 'status', 'total', 'currency']),
            ])
            ->findOrFail($order);

        return view('portal.orders.show', compact('order'));
    }
}
