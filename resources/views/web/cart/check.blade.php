@extends('web.layouts.public')

@section('content')
<div class="mx-auto max-w-3xl px-4 py-10 lg:px-8">
    <h1 class="mb-6 text-xl font-semibold text-slate-900">订单结算</h1>

    <div class="card">
        <div class="card-header"><h2 class="card-title">订单确认</h2></div>

        <div class="overflow-x-auto">
            <table class="data-table">
                <thead>
                <tr><th>产品</th><th>周期</th><th>数量</th><th class="text-right">金额</th></tr>
                </thead>
                <tbody>
                @foreach ($ShopData['cart_products'] as $item)
                    <tr>
                        <td>
                            <div class="font-medium text-slate-800">{{ $item['productsname'] }}</div>
                            @foreach ($item['conf_child'] as $child)
                                <div class="text-xs text-slate-400">{{ $child['name'] }}：{{ $child['sub_name'] }}</div>
                            @endforeach
                        </td>
                        <td>{{ $item['cycle_desc'] }}</td>
                        <td>{{ $item['qty'] }}</td>
                        <td class="text-right">{{ $ShopData['currency']['prefix'] }}{{ $item['sale_price'] }}</td>
                    </tr>
                @endforeach
                </tbody>
                <tfoot>
                <tr>
                    <td colspan="3" class="text-right font-medium">应付金额</td>
                    <td class="text-right font-semibold text-brand-600">
                        {{ $ShopData['currency']['prefix'] }}{{ $ShopData['total_price'] }}
                    </td>
                </tr>
                </tfoot>
            </table>
        </div>

        <form method="post" action="/cart/settle" class="space-y-4 border-t border-slate-100 px-5 py-4">
            @csrf
            <input type="hidden" name="statuscart" value="checkout">
            <input type="hidden" name="terms" value="1">

            <div>
                <label class="form-label">支付方式</label>
                <div class="space-y-2">
                    <label class="flex cursor-pointer items-center gap-3 rounded-lg border border-slate-200 px-4 py-3 hover:border-brand-300">
                        <input type="radio" name="paymt" value="credit" class="form-radio" checked>
                        <span class="text-sm text-slate-800">余额支付</span>
                        <span class="ml-auto text-xs text-slate-500">
                            {{ $ShopData['currency']['prefix'] }}{{ $ShopData['paymt']['credit'] }}
                        </span>
                    </label>
                    @foreach ($ShopData['gateways'] as $gateway)
                        <label class="flex cursor-pointer items-center gap-3 rounded-lg border border-slate-200 px-4 py-3 hover:border-brand-300">
                            <input type="radio" name="payment" value="{{ $gateway['name'] }}" class="form-radio">
                            <span class="text-sm text-slate-800">{{ $gateway['title'] }}</span>
                        </label>
                    @endforeach
                </div>
            </div>

            <div>
                <label class="form-label" for="checkNotes">订单备注</label>
                <textarea name="notes" id="checkNotes" maxlength="200" class="form-textarea">{{ $ShopData['notes'] }}</textarea>
            </div>

            <div class="flex justify-between gap-2 border-t border-slate-100 pt-4">
                <a href="/cart?action=viewcart" class="btn-secondary btn-sm">返回购物车</a>
                <button type="submit" class="btn-primary btn-sm">提交订单</button>
            </div>
        </form>
    </div>
</div>
@endsection
