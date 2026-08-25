@extends('layouts.admin')

@section('title', '客户管理')
@section('eyebrow', 'KJAIU / CLIENT LEDGER')
@section('description', '管理账户身份、联系资料、余额和服务归属。')

@section('actions')
    @if($editing)<a class="button button-primary" href="{{ route('admin.customers.index') }}">新增客户 <span>＋</span></a>@else<button class="button button-primary" type="button" data-dialog-open="customer-dialog">新增客户 <span>＋</span></button>@endif
@endsection

@section('content')
    <section class="summary-strip">
        <div><span>客户总数</span><strong>{{ number_format($customers->total()) }}</strong></div>
        <i></i>
        <div><span>当前页余额</span><strong>¥{{ number_format((float) $customers->getCollection()->sum('credit'), 2) }}</strong></div>
        <i></i>
        <div><span>当前页服务</span><strong>{{ number_format($customers->getCollection()->sum('services_count')) }}</strong></div>
    </section>

    <section class="panel resource-panel">
        <header class="resource-toolbar">
            <form class="search-form" method="GET">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m21 19.6-5.2-5.2a7 7 0 1 0-1.4 1.4l5.2 5.2 1.4-1.4ZM5 10a5 5 0 1 1 10 0 5 5 0 0 1-10 0Z"/></svg>
                <input name="q" value="{{ $keyword }}" placeholder="搜索姓名、邮箱、手机或客户 ID">
                <button type="submit">搜索</button>
            </form>
            <span class="result-count">{{ $customers->total() }} 条客户记录</span>
        </header>

        <div class="table-scroll">
            <table class="data-table resource-table">
                <thead><tr><th>客户</th><th>联系信息</th><th>余额</th><th>服务 / 账单</th><th>状态</th><th class="align-right">操作</th></tr></thead>
                <tbody>
                @forelse ($customers as $customer)
                    <tr>
                        <td data-label="客户">
                            <div class="identity-cell"><span class="avatar avatar-table">{{ mb_substr($customer->name, 0, 1) }}</span><span><strong>{{ $customer->name }}</strong><small>#{{ str_pad((string) $customer->id, 6, '0', STR_PAD_LEFT) }} {{ $customer->company_name ? '· '.$customer->company_name : '' }}</small></span></div>
                        </td>
                        <td data-label="联系信息"><strong>{{ $customer->email }}</strong><small class="cell-sub">{{ $customer->phone ?: '未绑定手机' }}</small></td>
                        <td data-label="余额" class="money-cell">¥{{ number_format((float) $customer->credit, 2) }}</td>
                        <td data-label="服务 / 账单"><span class="split-number"><strong>{{ $customer->services_count }}</strong> 服务</span><span class="split-number"><strong>{{ $customer->invoices_count }}</strong> 账单</span></td>
                        <td data-label="状态"><span class="status status-{{ strtolower($customer->status) }}">{{ $customer->status === 'Active' ? '正常' : '已停用' }}</span></td>
                        <td data-label="操作" class="align-right"><a class="row-action" href="{{ route('admin.customers.index', ['edit' => $customer->id, 'q' => $keyword]) }}">编辑 →</a></td>
                    </tr>
                @empty
                    <tr><td colspan="6"><div class="empty-state"><span>CL</span><h3>没有找到客户</h3><p>调整搜索条件，或创建第一个客户账户。</p></div></td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <div class="pagination-wrap">{{ $customers->links() }}</div>
    </section>

    <dialog class="modal" id="customer-dialog" @if($editing || ($errors->any() && old('_form') === 'customer')) open @endif>
        <form method="POST" action="{{ $editing ? route('admin.customers.update', $editing) : route('admin.customers.store') }}">
            @csrf
            @if($editing) @method('PUT') @endif
            <input type="hidden" name="_form" value="customer">
            <header class="modal-head">
                <div><p class="panel-kicker">{{ $editing ? 'EDIT CLIENT' : 'NEW CLIENT' }}</p><h2>{{ $editing ? '编辑客户资料' : '创建客户账户' }}</h2></div>
                <button type="button" data-dialog-close aria-label="关闭">×</button>
            </header>
            <div class="modal-body form-grid">
                <label class="field"><span>客户名称</span><input name="name" value="{{ old('name', $editing?->name) }}" required maxlength="255" placeholder="企业或个人名称"></label>
                <label class="field"><span>邮箱</span><input type="email" name="email" value="{{ old('email', $editing?->email) }}" required maxlength="191" placeholder="name@example.com"></label>
                <label class="field"><span>手机号 <small>选填</small></span><input name="phone" value="{{ old('phone', $editing?->phone) }}" maxlength="32" placeholder="13800000000"></label>
                <label class="field"><span>公司名称 <small>选填</small></span><input name="company_name" value="{{ old('company_name', $editing?->company_name) }}" maxlength="255" placeholder="公司主体"></label>
                @if($editing)
                    <label class="field"><span>账户状态</span><select name="status"><option value="Active" @selected(old('status', $editing->status) === 'Active')>正常</option><option value="Suspended" @selected(old('status', $editing->status) === 'Suspended')>停用</option></select></label>
                    <label class="field"><span>重置密码 <small>留空则不修改</small></span><input type="password" name="password" minlength="8" maxlength="72" autocomplete="new-password" placeholder="至少 8 个字符"></label>
                @else
                    <label class="field field-full"><span>初始密码</span><input type="password" name="password" required minlength="8" maxlength="72" autocomplete="new-password" placeholder="至少 8 个字符"></label>
                @endif
            </div>
            <footer class="modal-foot"><button class="button button-ghost" type="button" data-dialog-close>取消</button><button class="button button-primary" type="submit">{{ $editing ? '保存修改' : '创建客户' }}</button></footer>
        </form>
    </dialog>
@endsection
