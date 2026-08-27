@extends('layouts.admin')

@section('title', '上游供应商')
@section('eyebrow', 'KJAIU / SUPPLIER CONTROL')
@section('description', '安全配置 IDCsmart Finance 账户，自动刷新目录并按本地付款周期建立商品映射。')

@section('actions')
    <button class="button button-primary" type="button" data-dialog-open="supplier-create">新增账户 <span>＋</span></button>
@endsection

@section('content')
    @if($autoTestConnections)
        <form method="POST" action="{{ route('admin.suppliers.test-active') }}" data-auto-submit hidden>@csrf</form>
    @endif
    <section class="summary-strip">
        <div><span>供应商账户</span><strong>{{ $accounts->count() }}</strong></div>
        <i></i>
        <div><span>启用中</span><strong>{{ $accounts->where('is_active', true)->count() }}</strong></div>
        <i></i>
        <div><span>有效上游商品</span><strong>{{ number_format($accounts->sum('active_catalog_product_count')) }}</strong></div>
        <i></i>
        <div><span>有效周期映射</span><strong>{{ number_format($accounts->sum('active_mapping_count')) }}</strong></div>
    </section>

    <section class="panel resource-panel">
        <header class="panel-head">
            <div><p class="panel-kicker">SUPPLIER ACCOUNTS</p><h2>账户与连接状态</h2></div>
            <span class="panel-meta">仅支持 IDCSMART FINANCE · 强制 TLS 校验</span>
        </header>
        <div class="service-grid">
            @forelse($accounts as $account)
                @php
                    $state = $accountStates->get($account->id);
                @endphp
                <article class="service-card">
                    <header>
                        <div class="service-symbol">IDC</div>
                        <div class="service-title">
                            <span>{{ $state['code'] }}</span>
                            <h2>{{ $state['name'] }}</h2>
                            <p>{{ $state['base_url'] }}</p>
                        </div>
                        <span class="status {{ $account->is_active ? 'status-active' : 'status-suspended' }}">{{ $account->is_active ? '已启用' : '已停用' }}</span>
                    </header>
                    <div class="service-facts">
                        <div><span>登录标识</span><strong>{{ $state['identifier'] }}</strong><small class="cell-sub">{{ $state['password_configured'] ? '密码已加密保存' : '密码未配置' }}</small></div>
                        <div><span>上游目录</span><strong>{{ $account->active_catalog_product_count }} / {{ $account->catalog_product_count }}</strong></div>
                        <div><span>周期映射</span><strong>{{ $account->active_mapping_count }}</strong></div>
                        <div><span>最近同步</span><strong>{{ $account->last_catalog_synced_at?->format('m-d H:i') ?? '尚未同步' }}</strong></div>
                    </div>
                    <footer>
                        <div><span class="auto-renew-dot {{ $account->last_connected_at && ! $account->last_error ? 'is-on' : '' }}"></span>{{ $account->last_error ? '最近上游操作失败' : ($account->last_connected_at ? '上游连接正常' : '等待连接测试') }}</div>
                        <button class="row-action" type="button" data-dialog-open="supplier-{{ $account->id }}">配置账户</button>
                    </footer>
                    <div class="row-actions" style="margin-top: 14px; justify-content: flex-start; flex-wrap: wrap">
                        <form method="POST" action="{{ route('admin.suppliers.catalog-sync', $account) }}">
                            @csrf
                            <input type="hidden" name="_form" value="supplier-sync-{{ $account->id }}">
                            <button class="button button-secondary" type="submit">选择导入商品</button>
                        </form>
                        <button class="button button-primary" type="button" data-dialog-open="supplier-mappings-{{ $account->id }}">配置周期映射</button>
                    </div>
                </article>

                <dialog class="modal modal-wide" id="supplier-{{ $account->id }}" @if($errors->any() && old('_form') === 'supplier-'.$account->id) open @endif>
                    <form method="POST" action="{{ route('admin.suppliers.update', $account) }}">
                        @csrf @method('PUT')
                        <input type="hidden" name="_form" value="supplier-{{ $account->id }}">
                        <input type="hidden" name="driver" value="idcsmart_finance">
                        <header class="modal-head">
                            <div><p class="panel-kicker">SUPPLIER #{{ $account->id }}</p><h2>编辑上游账户</h2></div>
                            <button type="button" data-dialog-close aria-label="关闭">×</button>
                        </header>
                        <div class="modal-body">
                            <div class="form-grid">
                                <label class="field"><span>账户名称</span><input name="name" value="{{ old('_form') === 'supplier-'.$account->id ? old('name') : $state['name'] }}" maxlength="191" required></label>
                                <label class="field"><span>内部代码</span><input name="code" value="{{ old('_form') === 'supplier-'.$account->id ? old('code') : $state['code'] }}" maxlength="64" required></label>
                                <label class="field field-full"><span>Finance 基础地址 <small>仅 HTTPS，不含凭据或查询参数</small></span><input type="url" name="base_url" value="{{ old('_form') === 'supplier-'.$account->id ? old('base_url') : $state['base_url'] }}" maxlength="2048" required placeholder="https://finance.example.com"></label>
                                <label class="field"><span>登录标识 <small>留空保留 {{ $state['identifier'] }}</small></span><input name="username" value="" maxlength="191" autocomplete="off"></label>
                                <label class="field"><span>上游密码 <small>留空保留已保存密码</small></span><input type="password" name="password" value="" maxlength="4096" autocomplete="new-password"></label>
                                <div class="toggle-stack field-full">
                                    <input type="hidden" name="is_active" value="0">
                                    <label class="switch-field"><input type="checkbox" name="is_active" value="1" @checked(old('_form') === 'supplier-'.$account->id ? old('is_active') : $account->is_active)><span></span><b>启用该供应商账户</b></label>
                                    <input type="hidden" name="allow_legacy_unbounded_credit_payment" value="0">
                                    <label class="switch-field"><input type="checkbox" name="allow_legacy_unbounded_credit_payment" value="1" @checked(old('_form') === 'supplier-'.$account->id ? old('allow_legacy_unbounded_credit_payment') : $state['allow_legacy_unbounded_credit_payment'])><span></span><b>高风险兼容：允许旧版接口自动扣上游余额</b></label>
                                    <small class="field-hint">危险：旧版 `/apply_credit` 不支持预期金额、币种、账单版本或幂等前置条件，冻结报价无法原子限制实际扣款。存在未终结操作或待结算路由时禁止更改。</small>
                                </div>
                            </div>
                        </div>
                        <footer class="modal-foot"><button class="button button-ghost" type="button" data-dialog-close>取消</button><button class="button button-primary" type="submit">保存配置</button></footer>
                    </form>
                </dialog>

                @php
                    $options = $catalogOptions->get($account->id, collect());
                    $savedTargets = $mappingTargets->get($account->id, collect());
                    $mappingPage = $mappingPages->get($account->id);
                    $states = $mappingStates->get($account->id, collect());
                    $mappingForm = old('_form') === 'supplier-mappings-'.$account->id;
                @endphp
                <dialog class="modal modal-wide" id="supplier-mappings-{{ $account->id }}" @if(($errors->any() && $mappingForm) || (string) $mappingAccount === (string) $account->id) open @endif>
                    <form method="POST" action="{{ route('admin.suppliers.mappings', $account) }}" data-dirty-guard data-dirty-message="本页映射有未保存的修改，确定离开吗？">
                        @csrf @method('PUT')
                        <input type="hidden" name="_form" value="supplier-mappings-{{ $account->id }}">
                        <input type="hidden" name="mapping_page" value="{{ $mappingPage->currentPage() }}">
                        <input type="hidden" name="mapping_page_token" value="{{ $mappingPageTokens->get($account->id) }}">
                        <header class="modal-head">
                            <div><p class="panel-kicker">CYCLE MAPPINGS · {{ $state['code'] }}</p><h2>本地与上游商品周期</h2></div>
                            <button type="button" data-dialog-close aria-label="关闭">×</button>
                        </header>
                        <div class="modal-body">
                            <div class="section-heading"><span>{{ $account->active_catalog_product_count }}</span><div><h3>逐周期选择上游商品</h3><p>每页最多 50 行。输入框共享本页上游目标清单；空白仅解除当前行映射，未提交页和未提交行保持不变。历史路由快照不会被改写。</p></div></div>
                            <datalist id="supplier-targets-{{ $account->id }}">
                                @foreach($options as $option)
                                    <option value="{{ $option['value'] }}">{{ $option['name'] }} · {{ $option['cycle'] }} · {{ $option['upstream_product_id'] }}</option>
                                @endforeach
                            </datalist>
                            <div class="table-scroll">
                                <table class="data-table resource-table">
                                    <thead><tr><th>本地商品</th><th>本地周期</th><th>本地价格</th><th>上游商品与周期</th></tr></thead>
                                    <tbody>
                                    @forelse($mappingPage as $index => $row)
                                        @php
                                            $pair = $row['product']->id.'|'.$row['cycle'];
                                            $mappingState = $states->get($pair);
                                            $globalState = $globalMappingStates->get($pair);
                                            $selectedTarget = $mappingForm
                                                ? old('mappings.'.$index.'.target', $savedTargets->get($pair, ''))
                                                : $savedTargets->get($pair, '');
                                            $selectedTargetIsAvailable = $selectedTarget === ''
                                                || $options->contains('value', $selectedTarget);
                                        @endphp
                                        <tr>
                                            <td data-label="本地商品">
                                                <strong>{{ $row['product']->name }}</strong>
                                                <small class="cell-sub">#{{ $row['product']->id }} · {{ $row['product']->type }}</small>
                                                <input type="hidden" name="mappings[{{ $index }}][product_id]" value="{{ $row['product']->id }}">
                                            </td>
                                            <td data-label="本地周期">
                                                <strong>{{ $cycles[$row['cycle']] ?? $row['cycle'] }}</strong>
                                                <small class="cell-sub">{{ $row['is_default'] ? '默认周期' : '附加周期' }}</small>
                                                <input type="hidden" name="mappings[{{ $index }}][local_billing_cycle]" value="{{ $row['cycle'] }}">
                                            </td>
                                            <td data-label="本地价格"><span class="money-cell">¥{{ number_format((float) $row['price'], 2) }}</span><small class="cell-sub">初装费 ¥{{ number_format((float) $row['setup_fee'], 2) }}</small></td>
                                             <td data-label="上游商品与周期">
                                                  <label class="field">
                                                      <span>上游目标 <small>格式：商品 ID|周期；留空为不映射</small></span>
                                                      <input
                                                          name="mappings[{{ $index }}][target]"
                                                          value="{{ $selectedTarget }}"
                                                          list="supplier-targets-{{ $account->id }}"
                                                          maxlength="180"
                                                          autocomplete="off"
                                                          aria-label="选择 {{ $row['product']->name }} {{ $row['cycle'] }} 的上游映射"
                                                          aria-describedby="mapping-state-{{ $account->id }}-{{ $index }}"
                                                          placeholder="不映射"
                                                      >
                                                      <span id="mapping-state-{{ $account->id }}-{{ $index }}">
                                                      @if(!$selectedTargetIsAvailable)
                                                          <small class="field-hint">当前目标不可用，请重新选择或清空解除</small>
                                                      @endif
                                                      @if($mappingState)
                                                          <small class="field-hint">{{ $mappingState['status'] }}@if($mappingState['historical_count']) · 保留 {{ $mappingState['historical_count'] }} 条历史路由@endif</small>
                                                     @elseif($globalState && $globalState['is_active'])
                                                         <small class="field-hint">当前由 {{ $globalState['supplier_name'] }} 路由；选择目标后将安全迁移</small>
                                                      @elseif($globalState && $globalState['historical_count'])
                                                          <small class="field-hint">已有 {{ $globalState['historical_count'] }} 条历史路由快照</small>
                                                      @endif
                                                      </span>
                                                  </label>
                                              </td>
                                        </tr>
                                    @empty
                                        <tr><td colspan="4"><div class="empty-state compact"><span>SKU</span><h3>没有可配置的本地商品周期</h3><p>先上架本地商品并配置有效付款周期。</p></div></td></tr>
                                    @endforelse
                                    </tbody>
                                </table>
                             </div>
                            @if($mappingPage->hasPages())
                                <div class="pagination-wrap">{{ $mappingPage->links() }}</div>
                            @endif
                        </div>
                        <footer class="modal-foot"><button class="button button-ghost" type="button" data-dialog-close>取消</button><button class="button button-primary" type="submit" @disabled($mappingPage->isEmpty())>保存本页映射</button></footer>
                    </form>
                </dialog>
            @empty
                <div class="empty-state empty-state-wide"><span>IDC</span><h3>还没有上游账户</h3><p>创建账户后使用已保存凭据测试登录和商品读取。</p></div>
            @endforelse
        </div>
    </section>

    <dialog class="modal modal-wide" id="supplier-create" @if($errors->any() && old('_form') === 'supplier-create') open @endif>
        <form method="POST" action="{{ route('admin.suppliers.store') }}">
            @csrf
            <input type="hidden" name="_form" value="supplier-create">
            <input type="hidden" name="driver" value="idcsmart_finance">
            <header class="modal-head">
                <div><p class="panel-kicker">NEW SUPPLIER</p><h2>创建上游账户</h2></div>
                <button type="button" data-dialog-close aria-label="关闭">×</button>
            </header>
            <div class="modal-body">
                <div class="form-grid">
                    <label class="field"><span>账户名称</span><input name="name" value="{{ old('_form') === 'supplier-create' ? old('name') : '' }}" maxlength="191" required placeholder="主供应商"></label>
                    <label class="field"><span>内部代码</span><input name="code" value="{{ old('_form') === 'supplier-create' ? old('code') : '' }}" maxlength="64" required placeholder="primary-finance"></label>
                    <label class="field field-full"><span>Finance 基础地址 <small>强制 HTTPS 和证书校验</small></span><input type="url" name="base_url" value="{{ old('_form') === 'supplier-create' ? old('base_url') : '' }}" maxlength="2048" required placeholder="https://finance.example.com"></label>
                    <label class="field"><span>登录标识</span><input name="username" value="" maxlength="191" required autocomplete="off"></label>
                    <label class="field"><span>上游密码</span><input type="password" name="password" value="" maxlength="4096" required autocomplete="new-password"></label>
                    <div class="toggle-stack field-full">
                        <input type="hidden" name="is_active" value="0">
                        <label class="switch-field"><input type="checkbox" name="is_active" value="1" @checked(old('_form') === 'supplier-create' ? old('is_active') : true)><span></span><b>创建后立即启用</b></label>
                        <input type="hidden" name="allow_legacy_unbounded_credit_payment" value="0">
                        <label class="switch-field"><input type="checkbox" name="allow_legacy_unbounded_credit_payment" value="1" @checked(old('_form') === 'supplier-create' && old('allow_legacy_unbounded_credit_payment'))><span></span><b>高风险兼容：允许旧版接口自动扣上游余额</b></label>
                        <small class="field-hint">默认关闭。旧版接口无法携带金额、币种、账单版本或幂等约束，启用后存在无法原子限制供应商扣款的风险。</small>
                    </div>
                </div>
            </div>
            <footer class="modal-foot"><button class="button button-ghost" type="button" data-dialog-close>取消</button><button class="button button-primary" type="submit">创建账户</button></footer>
        </form>
    </dialog>
@endsection
