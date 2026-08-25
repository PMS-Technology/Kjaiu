@extends('layouts.admin')

@section('title', '商品与定价')
@section('eyebrow', 'KJAIU / PRODUCT CATALOG')
@section('description', '维护销售目录、库存策略与多周期价格。')

@section('actions')
    <button class="button button-secondary" type="button" data-dialog-open="group-dialog">新建分组</button>
    @if($editing)<a class="button button-primary" href="{{ route('admin.products.index') }}">新增商品 <span>＋</span></a>@else<button class="button button-primary" type="button" data-dialog-open="product-dialog">新增商品 <span>＋</span></button>@endif
@endsection

@section('content')
    <section class="summary-strip">
        <div><span>商品总数</span><strong>{{ number_format($products->total()) }}</strong></div>
        <i></i>
        <div><span>一级分类</span><strong>{{ number_format($rootGroups->count()) }}</strong></div>
        <i></i>
        <div><span>销售分组</span><strong>{{ number_format($groups->count()) }}</strong></div>
    </section>

    <section class="panel resource-panel">
        <header class="resource-toolbar">
            <form class="search-form" method="GET">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m21 19.6-5.2-5.2a7 7 0 1 0-1.4 1.4l5.2 5.2 1.4-1.4ZM5 10a5 5 0 1 1 10 0 5 5 0 0 1-10 0Z"/></svg>
                <input name="q" value="{{ $keyword }}" placeholder="搜索商品名称">
                <button type="submit">搜索</button>
            </form>
            <span class="result-count">{{ $products->total() }} 个在册商品</span>
        </header>

        <div class="table-scroll">
            <table class="data-table resource-table">
                <thead><tr><th>商品</th><th>目录</th><th>基础定价</th><th>库存</th><th>交付</th><th>状态</th><th class="align-right">操作</th></tr></thead>
                <tbody>
                @forelse ($products as $product)
                    <tr>
                        <td data-label="商品">
                            <div class="product-cell"><span class="product-glyph">{{ strtoupper(mb_substr($product->type, 0, 2)) }}</span><span><strong>{{ $product->name }}</strong><small>{{ $product->type }} · #{{ $product->id }}</small></span></div>
                        </td>
                        <td data-label="目录"><strong>{{ $product->group?->parent?->name ?? '未分类' }}</strong><small class="cell-sub">{{ $product->group?->name }}</small></td>
                        <td data-label="基础定价"><span class="money-cell">¥{{ number_format((float) $product->price, 2) }}</span><small class="cell-sub">{{ $cycles[$product->billing_cycle] ?? $product->billing_cycle }} · {{ $product->prices->count() }} 个附加周期</small></td>
                        <td data-label="库存">
                            @if($product->stock_control)<strong>{{ number_format($product->quantity ?? 0) }}</strong><small class="cell-sub">库存控制</small>@else<span class="muted">不限量</span>@endif
                        </td>
                        <td data-label="交付"><span class="status {{ $product->auto_setup ? 'status-active' : 'status-pending' }}">{{ $product->auto_setup ? '自动开通' : '人工审核' }}</span></td>
                        <td data-label="状态"><span class="status {{ $product->is_active ? 'status-active' : 'status-suspended' }}">{{ $product->is_active ? '销售中' : '已下架' }}</span></td>
                        <td data-label="操作" class="align-right">
                            <div class="row-actions">
                                <a class="row-action" href="{{ route('admin.products.index', ['edit' => $product->id, 'q' => $keyword]) }}">编辑</a>
                                <form method="POST" action="{{ route('admin.products.toggle', $product) }}">@csrf @method('PATCH')<button class="row-action" type="submit">{{ $product->is_active ? '下架' : '上架' }}</button></form>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7"><div class="empty-state"><span>SKU</span><h3>销售目录还是空的</h3><p>先创建分组，再录入可销售的商品与周期价格。</p></div></td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <div class="pagination-wrap">{{ $products->links() }}</div>
    </section>

    <dialog class="modal modal-wide" id="product-dialog" @if($editing || ($errors->any() && old('_form') === 'product')) open @endif>
        @php
            $savedPrices = collect(old('prices', $editing?->prices?->map(fn($price) => ['billing_cycle' => $price->billing_cycle, 'price' => $price->price, 'setup_fee' => $price->setup_fee])->values()->all() ?? []));
            $priceRows = max(2, $savedPrices->count() + 1);
        @endphp
        <form method="POST" action="{{ $editing ? route('admin.products.update', $editing) : route('admin.products.store') }}">
            @csrf
            @if($editing) @method('PUT') @endif
            <input type="hidden" name="_form" value="product">
            <header class="modal-head">
                <div><p class="panel-kicker">{{ $editing ? 'EDIT PRODUCT' : 'NEW PRODUCT' }}</p><h2>{{ $editing ? '编辑商品与价格' : '创建销售商品' }}</h2></div>
                <button type="button" data-dialog-close aria-label="关闭">×</button>
            </header>
            <div class="modal-body">
                <div class="form-section">
                    <div class="section-heading"><span>01</span><div><h3>基本资料</h3><p>定义商品在销售目录中的身份。</p></div></div>
                    <div class="form-grid">
                        <label class="field"><span>销售分组</span><select name="product_group_id" required><option value="">选择分组</option>@foreach($groups as $group)<option value="{{ $group->id }}" @selected((string) old('product_group_id', $editing?->product_group_id) === (string) $group->id)>{{ $group->parent?->name }} / {{ $group->name }}</option>@endforeach</select></label>
                        <label class="field"><span>商品类型</span><input name="type" value="{{ old('type', $editing?->type ?? 'server') }}" maxlength="64" required placeholder="server / cloud / ssl"></label>
                        <label class="field field-full"><span>商品名称</span><input name="name" value="{{ old('name', $editing?->name) }}" maxlength="255" required placeholder="面向客户展示的商品名称"></label>
                        <label class="field field-full"><span>商品说明</span><textarea name="description" rows="4" maxlength="10000" placeholder="配置、权益与交付说明">{{ old('description', $editing?->description) }}</textarea></label>
                    </div>
                </div>

                <div class="form-section">
                    <div class="section-heading"><span>02</span><div><h3>基础定价</h3><p>作为默认销售与续费周期。</p></div></div>
                    <div class="form-grid form-grid-3">
                        <label class="field"><span>默认周期</span><select name="billing_cycle" required>@foreach($cycles as $value => $label)<option value="{{ $value }}" @selected(old('billing_cycle', $editing?->billing_cycle ?? 'monthly') === $value)>{{ $label }}</option>@endforeach</select></label>
                        <label class="field"><span>周期价格</span><div class="money-input"><b>¥</b><input type="number" name="price" value="{{ old('price', $editing?->price ?? '0.00') }}" min="0" step="0.01" required></div></label>
                        <label class="field"><span>初装费</span><div class="money-input"><b>¥</b><input type="number" name="setup_fee" value="{{ old('setup_fee', $editing?->setup_fee ?? '0.00') }}" min="0" step="0.01" required></div></label>
                    </div>
                </div>

                <div class="form-section">
                    <div class="section-heading"><span>03</span><div><h3>附加周期</h3><p>可选。添加季付、年付等独立价格。</p></div></div>
                    <div class="price-builder" data-price-builder data-next-index="{{ $priceRows }}">
                        <div class="price-row price-row-head"><span>付款周期</span><span>价格</span><span>初装费</span><span></span></div>
                        @for($index = 0; $index < $priceRows; $index++)
                            @php($price = $savedPrices->get($index, []))
                            <div class="price-row" data-price-row>
                                <select name="prices[{{ $index }}][billing_cycle]"><option value="">不设置</option>@foreach($cycles as $value => $label)<option value="{{ $value }}" @selected(($price['billing_cycle'] ?? '') === $value)>{{ $label }}</option>@endforeach</select>
                                <input type="number" name="prices[{{ $index }}][price]" value="{{ $price['price'] ?? '' }}" min="0" step="0.01" placeholder="0.00">
                                <input type="number" name="prices[{{ $index }}][setup_fee]" value="{{ $price['setup_fee'] ?? '' }}" min="0" step="0.01" placeholder="0.00">
                                <button type="button" data-remove-price aria-label="删除该周期">×</button>
                            </div>
                        @endfor
                        <template data-price-template>
                            <div class="price-row" data-price-row>
                                <select name="prices[__INDEX__][billing_cycle]"><option value="">不设置</option>@foreach($cycles as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select>
                                <input type="number" name="prices[__INDEX__][price]" min="0" step="0.01" placeholder="0.00">
                                <input type="number" name="prices[__INDEX__][setup_fee]" min="0" step="0.01" placeholder="0.00">
                                <button type="button" data-remove-price aria-label="删除该周期">×</button>
                            </div>
                        </template>
                        <button class="inline-add" type="button" data-add-price>＋ 添加付款周期</button>
                    </div>
                </div>

                <div class="form-section form-section-last">
                    <div class="section-heading"><span>04</span><div><h3>交付策略</h3><p>配置库存和付款后的开通方式。</p></div></div>
                    <div class="form-grid">
                        <div class="toggle-group">
                            <input type="hidden" name="stock_control" value="0">
                            <label class="switch-field"><input type="checkbox" name="stock_control" value="1" @checked(old('stock_control', $editing?->stock_control)) data-stock-toggle><span></span><b>启用库存控制</b></label>
                            <label class="field compact-field"><span>可售库存</span><input type="number" name="quantity" value="{{ old('quantity', $editing?->quantity) }}" min="0" max="4294967295" placeholder="仅库存控制时必填" data-stock-input></label>
                        </div>
                        <div class="toggle-group toggle-stack">
                            <input type="hidden" name="auto_setup" value="0">
                            <label class="switch-field"><input type="checkbox" name="auto_setup" value="1" @checked(old('auto_setup', $editing?->auto_setup))><span></span><b>付款后自动标记为已开通</b></label>
                            <input type="hidden" name="is_active" value="0">
                            <label class="switch-field"><input type="checkbox" name="is_active" value="1" @checked(old('is_active', $editing?->is_active ?? true))><span></span><b>立即上架销售</b></label>
                        </div>
                    </div>
                </div>
            </div>
            <footer class="modal-foot"><button class="button button-ghost" type="button" data-dialog-close>取消</button><button class="button button-primary" type="submit">{{ $editing ? '保存商品' : '创建商品' }}</button></footer>
        </form>
    </dialog>

    <dialog class="modal" id="group-dialog" @if($errors->any() && old('_form') === 'group') open @endif>
        <form method="POST" action="{{ route('admin.product-groups.store') }}">
            @csrf
            <input type="hidden" name="_form" value="group">
            <header class="modal-head"><div><p class="panel-kicker">CATALOG GROUP</p><h2>创建商品分组</h2></div><button type="button" data-dialog-close aria-label="关闭">×</button></header>
            <div class="modal-body form-grid">
                <label class="field field-full"><span>分组名称</span><input name="name" value="{{ old('name') }}" required maxlength="255" placeholder="例如：弹性云主机"></label>
                <label class="field field-full"><span>上级分类 <small>留空时创建一级分类</small></span><select name="parent_id"><option value="">作为一级分类</option>@foreach($rootGroups as $root)<option value="{{ $root->id }}" @selected((string) old('parent_id') === (string) $root->id)>{{ $root->name }}</option>@endforeach</select></label>
                <label class="field"><span>标题 <small>选填</small></span><input name="headline" value="{{ old('headline') }}" maxlength="255"></label>
                <label class="field"><span>副标题 <small>选填</small></span><input name="tagline" value="{{ old('tagline') }}" maxlength="255"></label>
            </div>
            <footer class="modal-foot"><button class="button button-ghost" type="button" data-dialog-close>取消</button><button class="button button-primary" type="submit">创建分组</button></footer>
        </form>
    </dialog>
@endsection
