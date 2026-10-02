@extends('web.layouts.client')

@section('content')
@php $page = $SubmitTicket['ticketpage']; @endphp

<div class="mx-auto max-w-3xl">
    <nav class="mb-4 flex items-center gap-2 text-sm text-slate-500">
        <a href="/submitticket" class="hover:text-brand-600">提交工单</a>
        <span>/</span>
        <span class="text-slate-800">填写工单内容</span>
    </nav>

    <div class="card">
        <div class="card-header">
            <h2 class="card-title">工单内容</h2>
        </div>

        <form method="post" action="/submitticket" enctype="multipart/form-data" class="card-body space-y-5">
            @csrf

            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label class="form-label" for="dptid">工单部门</label>
                    <select name="dptid" id="dptid" class="form-select" required>
                        @foreach ($SubmitTicket['department'] as $department)
                            <option value="{{ $department['id'] }}" @selected($SubmitTicket['department_selected'] === $department['id'])>
                                {{ $department['name'] }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label class="form-label" for="priority">优先级</label>
                    <select name="priority" id="priority" class="form-select">
                        @foreach ($page['priority'] as $key => $label)
                            <option value="{{ $key }}" @selected($key === 'medium')>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
            </div>

            <div>
                <label class="form-label" for="hostid">相关产品</label>
                <select name="hostid" id="hostid" class="form-select">
                    @foreach ($page['host_list'] as $id => $label)
                        <option value="{{ $id }}" @selected((int) $preselected_pid === (int) $id)>{{ $label }}</option>
                    @endforeach
                </select>
                <p class="form-hint">关联产品有助于我们更快定位问题。</p>
            </div>

            <div>
                <label class="form-label" for="title">工单标题</label>
                <input type="text" name="title" id="title" class="form-input" required maxlength="255"
                       value="{{ old('title') }}" placeholder="请简要描述问题">
            </div>

            {{-- Custom fields configured for the selected department. --}}
            @foreach ($ticketCustom as $field)
                <div data-ticket-field="{{ $field['id'] }}">
                    <label class="form-label" for="ticketField{{ $field['id'] }}">
                        {{ $field['fieldname'] }}
                        @if ($field['required']) <span class="text-rose-500">*</span> @endif
                    </label>

                    @if ($field['fieldtype'] === 'dropdown')
                        <select id="ticketField{{ $field['id'] }}" name="customfield[{{ $field['id'] }}]"
                                class="form-select" @required($field['required'])>
                            <option value="">请选择</option>
                            @foreach ($field['dropdown_option'] as $option)
                                <option value="{{ $option['option_name'] }}">{{ $option['option_name'] }}</option>
                            @endforeach
                        </select>
                    @elseif ($field['fieldtype'] === 'textarea')
                        <textarea id="ticketField{{ $field['id'] }}" name="customfield[{{ $field['id'] }}]"
                                  class="form-textarea" @required($field['required'])></textarea>
                    @elseif ($field['fieldtype'] === 'tickbox')
                        <label class="flex items-center gap-2 text-sm text-slate-600">
                            <input type="checkbox" name="customfield[{{ $field['id'] }}]" value="1" class="form-checkbox">
                            {{ $field['description'] ?: $field['fieldname'] }}
                        </label>
                    @else
                        <input type="text" id="ticketField{{ $field['id'] }}" name="customfield[{{ $field['id'] }}]"
                               class="form-input" @required($field['required'])>
                    @endif

                    @if (! empty($field['description']) && $field['fieldtype'] !== 'tickbox')
                        <p class="form-hint">{{ $field['description'] }}</p>
                    @endif
                </div>
            @endforeach

            <div>
                <label class="form-label" for="content">问题描述</label>
                <textarea name="content" id="content" class="form-textarea min-h-40" required
                          placeholder="请详细描述您遇到的问题，包括操作步骤、错误提示等">{{ old('content') }}</textarea>
            </div>

            {{-- Attachments --}}
            <div>
                <label class="form-label">附件</label>
                <div id="fileBoxes" class="space-y-2">
                    <div class="filebox flex gap-2">
                        <input type="file" name="attachments[]" class="form-input py-1.5 text-sm"
                               accept=".jpg,.jpeg,.gif,.png">
                    </div>
                </div>
                <button type="button" id="addFileBtn" class="btn-secondary btn-sm mt-2">添加附件</button>
                <p class="form-hint">仅支持 .jpg / .jpeg / .gif / .png，单个文件不超过 5MB。</p>
            </div>

            <div class="flex justify-between gap-2 border-t border-slate-100 pt-4">
                <a href="/submitticket" class="btn-secondary btn-sm">上一步</a>
                <button type="submit" class="btn-primary btn-sm">提交工单</button>
            </div>
        </form>
    </div>
</div>
@endsection

@push('scripts')
<script>
    document.getElementById('addFileBtn')?.addEventListener('click', () => {
        const boxes = document.getElementById('fileBoxes');
        const node = document.createElement('div');

        node.className = 'filebox flex gap-2';
        node.innerHTML = '<input type="file" name="attachments[]" class="form-input py-1.5 text-sm" accept=".jpg,.jpeg,.gif,.png">'
            + '<button type="button" class="btn-ghost btn-sm text-rose-600" data-remove-file>移除</button>';

        boxes.appendChild(node);
    });

    document.getElementById('fileBoxes')?.addEventListener('click', (event) => {
        if (event.target.matches('[data-remove-file]')) {
            event.target.closest('.filebox')?.remove();
        }
    });

    // Reload the department-specific custom fields when the department changes.
    document.getElementById('dptid')?.addEventListener('change', async (event) => {
        try {
            const fields = await window.Kj.get('/ticket/get_custom', { dptid: event.target.value });
            const container = document.querySelectorAll('[data-ticket-field]');

            // Fields are rendered server-side; a mismatch between the selected
            // department and the rendered set means the page must be reloaded.
            if (Array.isArray(fields) && fields.length !== container.length) {
                window.location.href = `/submitticket?step=2&dptid=${event.target.value}`;
            }
        } catch (error) {
            window.Kj.toastError(error.message);
        }
    });
</script>
@endpush
