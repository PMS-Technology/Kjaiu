@extends('layouts.admin')

@section('title', '导入上游商品')
@section('eyebrow', 'KJAIU / SUPPLIER CATALOG')
@section('description', '从 '.$supplier->name.' 最近一次同步的目录中选择商品，导入为本地下架商品并自动建立周期映射。')

@section('actions')
    <a class="button button-secondary" href="{{ route('admin.suppliers.index') }}">返回供应商</a>
@endsection

@section('content')
    @if($autoSyncCatalog)
        <form method="POST" action="{{ route('admin.suppliers.catalog-sync', $supplier) }}" data-auto-submit hidden>@csrf</form>
    @endif
    <section class="summary-strip">
        <div><span>目录商品</span><strong>{{ number_format($catalogProducts->total()) }}</strong></div>
        <i></i>
        <div><span>本地币种</span><strong>{{ $localCurrency }}</strong></div>
        <i></i>
        <div><span>最近同步</span><strong>{{ $supplier->last_catalog_synced_at?->format('m-d H:i') ?? '尚未同步' }}</strong></div>
    </section>

    <section class="panel resource-panel">
        <header class="resource-toolbar">
            <form class="search-form" method="GET">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m21 19.6-5.2-5.2a7 7 0 1 0-1.4 1.4l5.2 5.2 1.4-1.4ZM5 10a5 5 0 1 1 10 0 5 5 0 0 1-10 0Z"/></svg>
                <input name="q" value="{{ $keyword }}" placeholder="搜索上游商品名称、商品 ID 或分组 ID">
                <button type="submit">搜索</button>
            </form>
            <span class="result-count">当前页最多选择 30 个商品</span>
        </header>

        <form method="POST" action="{{ route('admin.suppliers.catalog-import', $supplier) }}" data-confirm="确认导入选中的上游商品吗？商品将保持下架，检查售价后才能对用户销售。">
            @csrf
            <input type="hidden" name="_form" value="supplier-catalog-import-{{ $supplier->id }}">
            <div class="catalog-import-toolbar">
                <label class="field"><span>导入到本地分组</span><select name="product_group_id" required><option value="">请选择子分组</option>@foreach($groups as $group)<option value="{{ $group->id }}" @selected((string) old('product_group_id') === (string) $group->id)>{{ $group->parent?->name }} / {{ $group->name }}</option>@endforeach</select></label>
                <button class="button button-primary" type="submit">导入所选商品</button>
            </div>

            <div class="table-scroll">
                <table class="data-table resource-table">
                    <thead><tr><th><input type="checkbox" data-select-all="catalog-product"></th><th>上游商品</th><th>类型 / 分组</th><th>周期与价格</th><th>状态</th></tr></thead>
                    <tbody>
                    @forelse($catalogProducts as $catalogProduct)
                        @php
                            $imported = $catalogProduct->catalogImport;
                            $currencyMatches = strtoupper((string) $catalogProduct->currency) === $localCurrency;
                            $selectable = $catalogProduct->is_active && !$imported && $currencyMatches && !empty(data_get($catalogProduct->metadata, 'prices'));
                        @endphp
                        <tr>
                            <td data-label="选择"><input type="checkbox" name="catalog_products[]" value="{{ $catalogProduct->id }}" data-select-item="catalog-product" @checked(in_array((string) $catalogProduct->id, old('catalog_products', []), true)) @disabled(!$selectable) aria-label="选择 {{ $catalogProduct->name }}"></td>
                            <td data-label="上游商品"><strong>{{ $catalogProduct->name }}</strong><small class="cell-sub">#{{ $catalogProduct->upstream_product_id }}</small></td>
                            <td data-label="类型 / 分组"><strong>{{ $catalogProduct->type ?: 'server' }}</strong><small class="cell-sub">{{ $catalogProduct->upstream_group_id ?: '未提供上游分组' }}</small></td>
                            <td data-label="周期与价格">
                                @forelse((array) data_get($catalogProduct->metadata, 'prices', []) as $cycle => $price)
                                    <span class="split-number"><strong>{{ $cycles[$cycle] ?? $cycle }}</strong> {{ $catalogProduct->currency }} {{ number_format((float) ($price['price'] ?? 0), 2) }}</span>
                                @empty
                                    <span class="cell-sub">无完整价格</span>
                                @endforelse
                            </td>
                            <td data-label="状态">
                                @if($imported)
                                    <span class="status status-active">已导入 #{{ $imported->product_id }}</span>
                                @elseif(!$catalogProduct->is_active)
                                    <span class="status status-suspended">上游已停用</span>
                                @elseif(!$currencyMatches)
                                    <span class="status status-suspended">币种不匹配</span>
                                @elseif(!$selectable)
                                    <span class="status status-suspended">价格不可用</span>
                                @else
                                    <span class="status">可导入</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5"><div class="empty-state"><span>UP</span><h3>没有可显示的上游商品</h3><p>请检查供应商连接，或调整搜索条件后重新进入。</p></div></td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </form>
        <div class="pagination-wrap">{{ $catalogProducts->links() }}</div>
    </section>
@endsection
