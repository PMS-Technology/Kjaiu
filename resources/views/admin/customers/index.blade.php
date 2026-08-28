@extends('layouts.admin')

@section('title', '用户管理')
@section('eyebrow', 'KJAIU / USER LEDGER')
@section('description', '统一管理普通用户与管理员的分组、身份和账户状态。')

@section('actions')
    @if($editing)<a class="button button-primary" href="{{ route('admin.customers.index') }}">新增用户 <span>＋</span></a>@else<button class="button button-primary" type="button" data-dialog-open="customer-dialog">新增用户 <span>＋</span></button>@endif
@endsection

@section('content')
    <section class="summary-strip">
        <div><span>用户总数</span><strong>{{ number_format($customers->total()) }}</strong></div>
        <i></i>
        <div><span>当前页余额</span><strong>¥{{ number_format((float) $customers->getCollection()->sum('credit'), 2) }}</strong></div>
        <i></i>
        <div><span>当前页服务</span><strong>{{ number_format($customers->getCollection()->sum('services_count')) }}</strong></div>
    </section>

    <section class="panel resource-panel">
        <header class="resource-toolbar">
            <form class="search-form" method="GET">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m21 19.6-5.2-5.2a7 7 0 1 0-1.4 1.4l5.2 5.2 1.4-1.4ZM5 10a5 5 0 1 1 10 0 5 5 0 0 1-10 0Z"/></svg>
                <input name="q" value="{{ $keyword }}" placeholder="搜索姓名、邮箱、手机或用户 ID">
                <button type="submit">搜索</button>
            </form>
            <span class="result-count">{{ $customers->total() }} 条用户记录</span>
        </header>

        <div class="table-scroll">
            <table class="data-table resource-table">
                <thead><tr><th>用户</th><th>分组</th><th>联系信息</th><th>余额</th><th>服务 / 账单</th><th>状态</th><th class="align-right">操作</th></tr></thead>
                <tbody>
                @forelse ($customers as $customer)
                    <tr>
                        <td data-label="用户">
                            <div class="identity-cell"><span class="avatar avatar-table">{{ mb_substr($customer->name, 0, 1) }}</span><span><strong>{{ $customer->name }}</strong><small>#{{ str_pad((string) $customer->id, 6, '0', STR_PAD_LEFT) }} {{ $customer->company_name ? '· '.$customer->company_name : '' }}</small></span></div>
                        </td>
                        <td data-label="分组"><span class="status">{{ $customer->isAdministrator() ? '管理员' : '普通用户' }}</span></td>
                        <td data-label="联系信息"><strong>{{ $customer->email }}</strong><small class="cell-sub">{{ $customer->phone ?: '未绑定手机' }}</small></td>
                        <td data-label="余额" class="money-cell">¥{{ number_format((float) $customer->credit, 2) }}</td>
                        <td data-label="服务 / 账单"><span class="split-number"><strong>{{ $customer->services_count }}</strong> 服务</span><span class="split-number"><strong>{{ $customer->invoices_count }}</strong> 账单</span></td>
                        <td data-label="状态"><span class="status status-{{ strtolower($customer->status) }}">{{ $customer->status === 'Active' ? '正常' : '已停用' }}</span></td>
                        <td data-label="操作" class="align-right"><div class="row-actions">@if($customer->role === 'client' && $customer->status === 'Active')<button class="row-action" type="button" data-dialog-open="balance-{{ $customer->id }}">修改余额</button>@endif<a class="row-action" href="{{ route('admin.customers.index', ['edit' => $customer->id, 'q' => $keyword]) }}">编辑 →</a></div></td>
                    </tr>
                @empty
                    <tr><td colspan="7"><div class="empty-state"><span>US</span><h3>没有找到用户</h3><p>调整搜索条件，或创建第一个用户账户。</p></div></td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <div class="pagination-wrap">{{ $customers->links() }}</div>
    </section>
    @foreach($customers->where('role', 'client')->where('status', 'Active') as $customer)<dialog class="modal" id="balance-{{ $customer->id }}"><form method="POST" action="{{ route('admin.customers.balance', $customer) }}" data-confirm="确认修改该用户余额？系统将自动生成资金流水和操作记录。">@csrf @method('PATCH')<header class="modal-head"><div><p class="panel-kicker">BALANCE #{{ $customer->id }}</p><h2>修改 {{ $customer->name }} 的余额</h2></div><button type="button" data-dialog-close>×</button></header><div class="modal-body form-grid"><label class="field"><span>当前余额</span><input value="{{ $customer->credit }}" disabled></label><label class="field"><span>修改后余额</span><input type="number" name="balance" value="{{ $customer->credit }}" min="0" step="0.01" required></label><label class="field field-full"><span>修改原因</span><textarea name="reason" required maxlength="1000" rows="3"></textarea></label></div><footer class="modal-foot"><button class="button button-ghost" type="button" data-dialog-close>取消</button><button class="button button-primary">确认修改</button></footer></form></dialog>@endforeach

    <dialog class="modal" id="customer-dialog" @if($editing || ($errors->any() && old('_form') === 'customer')) open @endif>
        <form method="POST" action="{{ $editing ? route('admin.customers.update', $editing) : route('admin.customers.store') }}">
            @csrf
            @if($editing) @method('PUT') @endif
            <input type="hidden" name="_form" value="customer">
            <header class="modal-head">
                <div><p class="panel-kicker">{{ $editing ? 'EDIT USER' : 'NEW USER' }}</p><h2>{{ $editing ? '编辑用户资料' : '创建用户账户' }}</h2></div>
                <button type="button" data-dialog-close aria-label="关闭">×</button>
            </header>
            <div class="modal-body form-grid">
                <label class="field"><span>用户名称</span><input name="name" value="{{ old('name', $editing?->name) }}" required maxlength="255" placeholder="企业或个人名称"></label>
                <label class="field"><span>邮箱</span><input type="email" name="email" value="{{ old('email', $editing?->email) }}" required maxlength="191" placeholder="name@example.com"></label>
                <label class="field"><span>手机号 <small>选填</small></span><input name="phone" value="{{ old('phone', $editing?->phone) }}" maxlength="32" placeholder="13800000000"></label>
                <label class="field"><span>公司名称 <small>选填</small></span><input name="company_name" value="{{ old('company_name', $editing?->company_name) }}" maxlength="255" placeholder="公司主体"></label>
                <label class="field"><span>用户分组</span><select name="role"><option value="client" @selected(old('role', $editing?->role ?? 'client') === 'client')>普通用户</option><option value="admin" @selected(old('role', $editing?->role) === 'admin')>管理员</option></select></label>
                @if($editing)
                    <label class="field"><span>账户状态</span><select name="status"><option value="Active" @selected(old('status', $editing->status) === 'Active')>正常</option><option value="Suspended" @selected(old('status', $editing->status) === 'Suspended')>停用</option></select></label>
                    <label class="field"><span>重置密码 <small>留空则不修改</small></span><input type="password" name="password" minlength="8" maxlength="72" autocomplete="new-password" placeholder="至少 8 个字符"></label>
                @else
                    <label class="field"><span>初始密码</span><input type="password" name="password" required minlength="8" maxlength="72" autocomplete="new-password" placeholder="至少 8 个字符"></label>
                @endif
            </div>
            <footer class="modal-foot"><button class="button button-ghost" type="button" data-dialog-close>取消</button><button class="button button-primary" type="submit">{{ $editing ? '保存修改' : '创建用户' }}</button></footer>
        </form>
    </dialog>
@endsection
