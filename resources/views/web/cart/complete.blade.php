@extends('web.layouts.public')

@section('content')
<div class="mx-auto max-w-lg px-4 py-16 lg:px-8">
    <div class="card p-8 text-center">
        <span class="mx-auto grid h-14 w-14 place-items-center rounded-full bg-emerald-50 text-emerald-600">
            <svg class="h-7 w-7" viewBox="0 0 20 20" fill="currentColor">
                <path fill-rule="evenodd" d="M16.7 5.3a1 1 0 010 1.4l-8 8a1 1 0 01-1.4 0l-4-4a1 1 0 111.4-1.4L8 12.6l7.3-7.3a1 1 0 011.4 0z" clip-rule="evenodd"/>
            </svg>
        </span>

        <h1 class="mt-4 text-xl font-semibold text-slate-900">下单成功</h1>
        <p class="mt-2 text-sm text-slate-500">
            @if (! empty($ordernum))
                订单号 <span class="font-mono text-slate-700">{{ $ordernum }}</span>，
            @endif
            请尽快完成支付以便开通服务。
        </p>

        <div class="mt-6 flex justify-center gap-3">
            @if ($invoiceid)
                <a href="/viewbilling?id={{ $invoiceid }}&wakeup=1" class="btn-primary">立即支付</a>
            @endif
            <a href="/service" class="btn-secondary">我的服务</a>
        </div>
    </div>
</div>
@endsection
