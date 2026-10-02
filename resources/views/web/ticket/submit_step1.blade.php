@extends('web.layouts.client')

@section('content')
<div class="mx-auto max-w-4xl">
    <div class="mb-5">
        <h1 class="text-lg font-semibold text-slate-900">提交工单</h1>
        <p class="mt-1 text-sm text-slate-500">第一步：选择工单部门</p>
    </div>

    @if (empty($SubmitTicket['department']))
        <div class="card p-12 text-center text-sm text-slate-500">
            当前没有可用的工单部门，请联系管理员。
        </div>
    @else
        <div class="grid gap-4 sm:grid-cols-2">
            @foreach ($SubmitTicket['department'] as $department)
                <a href="/submitticket?step=2&dptid={{ $department['id'] }}"
                   class="card p-5 transition-shadow hover:border-brand-300 hover:shadow-md">
                    <div class="flex items-center justify-between">
                        <h2 class="font-semibold text-slate-900">{{ $department['name'] }}</h2>
                        <svg class="h-4 w-4 text-slate-400" viewBox="0 0 20 20" fill="currentColor">
                            <path fill-rule="evenodd" d="M7.2 14.8a1 1 0 010-1.4L11.2 9.4 7.2 5.4a1 1 0 111.4-1.4l4.7 4.7a1 1 0 010 1.4l-4.7 4.7a1 1 0 01-1.4 0z" clip-rule="evenodd"/>
                        </svg>
                    </div>
                    @if (! empty($department['description']))
                        <p class="mt-2 text-sm leading-6 text-slate-500">{{ $department['description'] }}</p>
                    @endif
                </a>
            @endforeach
        </div>
    @endif
</div>
@endsection
