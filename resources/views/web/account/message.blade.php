@extends('web.layouts.client')

@section('content')
<div class="card">
    <div class="card-header">
        <h2 class="card-title">消息中心</h2>

        <div class="flex flex-wrap items-center gap-2">
            <button type="button" id="markAllRead" class="btn-secondary btn-sm">全部已读</button>
            <button type="button" id="deleteAll" class="btn-secondary btn-sm text-rose-600">全部删除</button>
        </div>
    </div>

    {{-- Tabs: 全部信息 plus one per message type. --}}
    <div class="flex gap-1 overflow-x-auto border-b border-slate-200 px-5">
        <a href="/message?type=0" class="tab-link {{ $type === 0 ? 'tab-link-active' : '' }}">
            全部信息
        </a>
        @foreach ($unread_nav as $nav)
            @continue($nav['id'] === 0)
            <a href="/message?type={{ $nav['id'] }}" class="tab-link {{ $type === $nav['id'] ? 'tab-link-active' : '' }}">
                {{ $nav['title'] }}
                @if ($nav['unread_num'] > 0)
                    <span class="ml-1 rounded-full bg-rose-500 px-1.5 text-[10px] font-semibold text-white">
                        {{ $nav['unread_num'] }}
                    </span>
                @endif
            </a>
        @endforeach
    </div>

    @if (empty($messages))
        <div class="px-5 py-14 text-center text-sm text-slate-500">暂无消息</div>
    @else
        <div class="overflow-x-auto">
            <table class="data-table">
                <thead>
                <tr>
                    <th class="w-10"></th>
                    <th>标题内容</th>
                    <th>类型</th>
                    <th>提交时间</th>
                    <th class="text-right">操作</th>
                </tr>
                </thead>
                <tbody>
                @foreach ($messages as $message)
                    <tr data-message="{{ $message['id'] }}">
                        <td>
                            <input type="checkbox" class="form-checkbox message-check" value="{{ $message['id'] }}">
                        </td>
                        <td>
                            <div class="flex items-center gap-2">
                                @if ($message['read_time'] === 0)
                                    <span class="inline-block h-2 w-2 rounded-full bg-rose-500" title="未读"></span>
                                @endif
                                <button type="button" class="text-left font-medium text-brand-600 hover:underline"
                                        data-open-message="{{ $message['id'] }}">
                                    {{ $message['title'] }}
                                </button>
                            </div>
                        </td>
                        <td>
                            <span class="badge-slate">{{ $message['type_text'] }}</span>
                            @if ($message['is_market'])
                                <span class="badge-amber mt-1">营销</span>
                            @endif
                        </td>
                        <td>{{ date('Y-m-d H:i', $message['create_time']) }}</td>
                        <td class="text-right">
                            <button type="button" class="btn-ghost btn-sm text-rose-600"
                                    data-delete-message="{{ $message['id'] }}">删除</button>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>

        @include('web.partials.pagination', ['total' => $Total, 'pages' => $Pages, 'page' => $Page])
    @endif
</div>

{{-- ============ Message detail modal ============ --}}
<div id="messageModal" data-modal class="fixed inset-0 z-50 hidden items-center justify-center bg-slate-900/50 p-4">
    <div class="w-full max-w-2xl rounded-xl bg-white shadow-xl">
        <div class="flex items-center justify-between border-b border-slate-200 px-5 py-4">
            <h3 id="messageTitle" class="text-base font-semibold text-slate-900"></h3>
            <button type="button" data-modal-close class="text-slate-400 hover:text-slate-600">&times;</button>
        </div>
        <div class="max-h-[60vh] overflow-y-auto p-5">
            <div class="mb-3 text-xs text-slate-400" id="messageMeta"></div>
            <div id="messageContent" class="prose-content whitespace-pre-wrap"></div>
            <div id="messageAttachments" class="mt-4 flex flex-wrap gap-2"></div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
    (function () {
        var messages = @json(collect($messages)->keyBy('id'));

        function post(url, payload) {
            const body = new FormData();
            Object.entries(payload).forEach(([key, value]) => {
                if (Array.isArray(value)) {
                    value.forEach((item) => body.append(`${key}[]`, item));
                } else if (value !== undefined && value !== null) {
                    body.append(key, value);
                }
            });
            return window.Kj.post(url, body);
        }

        document.querySelectorAll('[data-open-message]').forEach((button) => {
            button.addEventListener('click', async () => {
                const id = button.dataset.openMessage;
                const message = messages[id];

                if (!message) {
                    return;
                }

                document.getElementById('messageTitle').textContent = message.title;
                document.getElementById('messageMeta').textContent =
                    `${message.type_text} · ${new Date(message.create_time * 1000).toLocaleString('zh-CN')}`;
                document.getElementById('messageContent').textContent = message.content;

                const attachments = document.getElementById('messageAttachments');
                attachments.innerHTML = (message.attachment || [])
                    .map((file) => `<a href="${file.path}" target="_blank" class="badge-slate hover:bg-slate-200">${file.name}</a>`)
                    .join('');

                window.Kj.openModal('messageModal');

                // Opening a message marks it read.
                if (message.read_time === 0) {
                    try {
                        await post('/read_messgage', { ids: [Number(id)] });
                        button.closest('tr')?.querySelector('.bg-rose-500')?.remove();
                    } catch (error) {
                        // A failed read marker is not worth interrupting the user.
                    }
                }
            });
        });

        document.querySelectorAll('[data-delete-message]').forEach((button) => {
            button.addEventListener('click', async () => {
                if (!window.confirm('确定要删除该消息吗？')) {
                    return;
                }

                try {
                    await post('/delete_messgage', { ids: [Number(button.dataset.deleteMessage)] });
                    window.location.reload();
                } catch (error) {
                    window.Kj.toastError(error.message);
                }
            });
        });

        document.getElementById('markAllRead')?.addEventListener('click', async () => {
            const ids = [...document.querySelectorAll('.message-check:checked')].map((c) => Number(c.value));

            try {
                // No selection means "mark everything read".
                await post('/read_messgage', ids.length ? { ids: ids } : {});
                window.location.reload();
            } catch (error) {
                window.Kj.toastError(error.message);
            }
        });

        document.getElementById('deleteAll')?.addEventListener('click', async () => {
            const ids = [...document.querySelectorAll('.message-check:checked')].map((c) => Number(c.value));

            if (!window.confirm(ids.length ? '确定要删除选中的消息吗？' : '确定要删除全部消息吗？')) {
                return;
            }

            try {
                await post('/delete_messgage', ids.length ? { ids: ids } : {});
                window.location.reload();
            } catch (error) {
                window.Kj.toastError(error.message);
            }
        });
    })();
</script>
@endpush
