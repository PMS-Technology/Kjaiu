@extends('web.layouts.client')

@section('content')
@php
    $ticket = $ViewTicket['ticket'];
    $closed = (int) $ticket['status']['id'] === 4;
@endphp

<div class="grid gap-4 lg:grid-cols-[1fr_18rem]">
    {{-- ============ Conversation ============ --}}
    <div class="card">
        <div class="card-header">
            <div class="min-w-0">
                <h2 class="card-title truncate">#{{ $ticket['tid'] }} {{ $ticket['title'] }}</h2>
                <p class="mt-0.5 text-xs text-slate-500">
                    {{ $ticket['department']['name'] }}
                    @if (! empty($ticket['host'])) · {{ $ticket['host'] }} @endif
                    · 创建于 {{ date('Y-m-d H:i', $ticket['create_time']) }}
                </p>
            </div>
            <span class="badge" style="background-color: {{ $ticket['status']['color'] }}1a; color: {{ $ticket['status']['color'] }}; box-shadow: inset 0 0 0 1px {{ $ticket['status']['color'] }}33;">
                {{ $ticket['status']['title'] }}
            </span>
        </div>

        <div class="divide-y divide-slate-100">
            @foreach ($ViewTicket['list'] as $reply)
                <div class="px-5 py-4 {{ $reply['user_type'] === 'admin' ? 'bg-brand-50/40' : '' }}">
                    <div class="mb-2 flex items-center justify-between">
                        <div class="flex items-center gap-2">
                            <span class="grid h-7 w-7 place-items-center rounded-full text-xs font-semibold text-white
                                         {{ $reply['user_type'] === 'admin' ? 'bg-brand-600' : 'bg-slate-400' }}">
                                {{ mb_substr($reply['realname'], 0, 1) }}
                            </span>
                            <span class="text-sm font-medium text-slate-800">{{ $reply['realname'] }}</span>
                            @if ($reply['user_type'] === 'admin')
                                <span class="badge-brand">客服</span>
                            @endif
                        </div>
                        <span class="text-xs text-slate-400">{{ date('Y-m-d H:i', $reply['format_time']) }}</span>
                    </div>

                    <div class="prose-content whitespace-pre-wrap">{{ $reply['content'] }}</div>

                    @if (! empty($reply['attachment']))
                        <div class="mt-3 flex flex-wrap gap-2">
                            @foreach ($reply['attachment'] as $attachment)
                                <a href="/ticket/download?path={{ urlencode($attachment['path']) }}"
                                   class="badge-slate hover:bg-slate-200">
                                    {{ $attachment['name'] }}
                                </a>
                            @endforeach
                        </div>
                    @endif

                    {{-- Staff replies can be rated once. --}}
                    @if ($reply['user_type'] === 'admin')
                        <div class="mt-3 flex items-center gap-2">
                            @if ($reply['star'] > 0)
                                <span class="text-xs text-amber-600">
                                    {{ str_repeat('★', (int) $reply['star']) }}{{ str_repeat('☆', 5 - (int) $reply['star']) }}
                                </span>
                            @else
                                <span class="text-xs text-slate-400">评价：</span>
                                <div class="star-rating flex gap-0.5" data-reply="{{ $reply['id'] }}">
                                    @for ($i = 1; $i <= 5; $i++)
                                        <button type="button" data-star="{{ $i }}"
                                                class="text-lg leading-none text-slate-300 hover:text-amber-400">★</button>
                                    @endfor
                                </div>
                            @endif
                        </div>
                    @endif
                </div>
            @endforeach
        </div>

        {{-- ============ Reply box ============ --}}
        @if ($closed)
            <div class="border-t border-slate-200 px-5 py-4">
                <div class="alert-error mb-0">该工单已关闭，如需继续沟通请提交新工单。</div>
            </div>
        @else
            <form method="post" action="/viewticket" enctype="multipart/form-data" class="space-y-3 border-t border-slate-200 px-5 py-4">
                @csrf
                <input type="hidden" name="tid" value="{{ $ticket['tid'] }}">
                <input type="hidden" name="c" value="{{ $ticket['c'] }}">

                <div>
                    <label class="form-label" for="replyContent">回复内容</label>
                    <textarea name="content" id="replyContent" class="form-textarea min-h-32" required
                              placeholder="请输入回复内容"></textarea>
                </div>

                <div class="filebox flex gap-2">
                    <input type="file" name="attachments[]" class="form-input py-1.5 text-sm" accept=".jpg,.jpeg,.gif,.png">
                </div>
                <button type="button" id="addReplyFile" class="btn-secondary btn-sm">添加附件</button>

                <div class="flex justify-end">
                    <button type="submit" class="btn-primary btn-sm">提交回复</button>
                </div>
            </form>
        @endif
    </div>

    {{-- ============ Sidebar ============ --}}
    <aside class="space-y-4">
        <div class="card">
            <div class="card-header"><h2 class="card-title">工单信息</h2></div>
            <dl class="space-y-2.5 px-5 py-4 text-sm">
                <div class="flex justify-between gap-3">
                    <dt class="text-slate-400">工单号</dt>
                    <dd class="font-mono text-slate-800">{{ $ticket['tid'] }}</dd>
                </div>
                <div class="flex justify-between gap-3">
                    <dt class="text-slate-400">部门</dt>
                    <dd class="text-slate-800">{{ $ticket['department']['name'] }}</dd>
                </div>
                <div class="flex justify-between gap-3">
                    <dt class="text-slate-400">优先级</dt>
                    <dd class="text-slate-800">{{ $ticket['priority'] }}</dd>
                </div>
                @if (! empty($ticket['host']))
                    <div class="flex justify-between gap-3">
                        <dt class="text-slate-400">相关产品</dt>
                        <dd class="truncate text-slate-800">
                            <a href="/servicedetail?id={{ $ticket['host_id'] }}" class="text-brand-600 hover:underline">
                                {{ $ticket['host'] }}
                            </a>
                        </dd>
                    </div>
                @endif
                <div class="flex justify-between gap-3">
                    <dt class="text-slate-400">更新时间</dt>
                    <dd class="text-slate-800">{{ date('Y-m-d H:i', $ticket['last_reply_time']) }}</dd>
                </div>
            </dl>

            <div class="flex gap-2 border-t border-slate-100 px-5 py-3">
                <a href="/supporttickets" class="btn-secondary btn-sm">返回列表</a>
                @unless ($closed)
                    <button type="button" id="closeTicket" class="btn-secondary btn-sm text-rose-600">关闭工单</button>
                @endunless
            </div>
        </div>

        @if ($ViewTicket['feedback_request'])
            <div class="card p-4 text-xs leading-5 text-slate-500">
                如果问题已经解决，欢迎为客服的回复评分，您的反馈会帮助我们改进服务。
            </div>
        @endif
    </aside>
</div>
@endsection

@push('scripts')
<script>
    (function () {
        // -------- Rating ---------------------------------------------------
        document.querySelectorAll('.star-rating').forEach((widget) => {
            widget.querySelectorAll('[data-star]').forEach((button) => {
                button.addEventListener('click', async () => {
                    try {
                        await window.Kj.post('/ticket/evaluate', {
                            rid: widget.dataset.reply,
                            star: button.dataset.star,
                            tid: @json($ticket['tid']),
                        });
                        window.Kj.toastSuccess('感谢您的评价');
                        window.location.reload();
                    } catch (error) {
                        window.Kj.toastError(error.message);
                    }
                });

                // Hover preview
                button.addEventListener('mouseenter', () => {
                    const stars = Number(button.dataset.star);
                    widget.querySelectorAll('[data-star]').forEach((sibling) => {
                        sibling.classList.toggle('text-amber-400', Number(sibling.dataset.star) <= stars);
                        sibling.classList.toggle('text-slate-300', Number(sibling.dataset.star) > stars);
                    });
                });
            });

            widget.addEventListener('mouseleave', () => {
                widget.querySelectorAll('[data-star]').forEach((sibling) => {
                    sibling.classList.remove('text-amber-400');
                    sibling.classList.add('text-slate-300');
                });
            });
        });

        // -------- Close ----------------------------------------------------
        document.getElementById('closeTicket')?.addEventListener('click', async () => {
            if (!window.confirm('确定要关闭该工单吗？')) {
                return;
            }

            try {
                const data = await window.Kj.post('/ticket/close', { tid: @json($ticket['tid']) });
                window.location.href = data.url || '/supporttickets';
            } catch (error) {
                window.Kj.toastError(error.message);
            }
        });

        // -------- Attachment rows -----------------------------------------
        document.getElementById('addReplyFile')?.addEventListener('click', () => {
            const node = document.createElement('div');
            node.className = 'filebox flex gap-2';
            node.innerHTML = '<input type="file" name="attachments[]" class="form-input py-1.5 text-sm" accept=".jpg,.jpeg,.gif,.png">'
                + '<button type="button" class="btn-ghost btn-sm text-rose-600" data-remove-file>移除</button>';
            node.querySelector('[data-remove-file]').addEventListener('click', () => node.remove());
            document.getElementById('addReplyFile').before(node);
        });
    })();
</script>
@endpush
