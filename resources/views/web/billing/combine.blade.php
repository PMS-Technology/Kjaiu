@extends('web.layouts.client')

@section('content')
<div class="card">
    <div class="card-header">
        <h2 class="card-title">合并支付</h2>
        <span class="text-xs text-slate-500">共 {{ count($Combine_billing) }} 个账单</span>
    </div>

    <form method="post" action="/combinebilling" id="combinePayForm">
        @csrf

        <div class="divide-y divide-slate-100">
            @foreach ($Combine_billing as $index => $bill)
                <div class="px-5 py-4">
                    <input type="hidden" name="ids[{{ $index }}]" value="{{ $bill['id'] }}">
                    <div class="mb-2 flex items-center justify-between">
                        <a href="/viewbilling?id={{ $bill['id'] }}" class="font-mono text-sm text-brand-600 hover:underline">
                            #{{ $bill['id'] }}
                        </a>
                        <span class="text-sm font-semibold text-slate-800">
                            {{ $Currency['prefix'] }}{{ $bill['total'] }}{{ $Currency['suffix'] }}
                        </span>
                    </div>
                    <ul class="space-y-1">
                        @foreach ($bill['items'] as $item)
                            <li class="flex justify-between text-xs text-slate-500">
                                <span>{{ $item['description'] }}</span>
                                <span>{{ $Currency['prefix'] }}{{ $item['amount'] }}</span>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endforeach
        </div>

        <div class="space-y-4 border-t border-slate-200 px-5 py-4">
            <div class="flex items-center justify-between text-base font-semibold">
                <span>应付总额</span>
                <span class="text-brand-600">{{ $Currency['prefix'] }}{{ $Total }}{{ $Currency['suffix'] }}</span>
            </div>

            <div>
                <label class="form-label">支付方式</label>
                <div class="space-y-2">
                    <label class="flex cursor-pointer items-center gap-3 rounded-lg border border-slate-200 px-4 py-3 hover:border-brand-300">
                        <input type="radio" name="paymt" value="credit" class="form-radio" checked>
                        <span class="text-sm text-slate-800">余额支付</span>
                        <span class="ml-auto text-xs text-slate-500">{{ $Currency['prefix'] }}{{ $credit }}</span>
                    </label>
                </div>
            </div>

            <div class="flex justify-between gap-2 border-t border-slate-100 pt-4">
                <a href="/billing" class="btn-secondary btn-sm">返回账单列表</a>
                <button type="submit" class="btn-primary btn-sm">确认合并</button>
            </div>
        </div>
    </form>
</div>
@endsection
