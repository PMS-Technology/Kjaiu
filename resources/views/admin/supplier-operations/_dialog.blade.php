<dialog class="modal modal-wide" id="supplier-operation-{{ $operation['id'] }}" @if($errors->any() && old('_form') === 'supplier-operation-'.$operation['id']) open @endif>
    <header class="modal-head">
        <div><p class="panel-kicker">SUPPLIER OPERATION #{{ $operation['id'] }}</p><h2>安全审阅与恢复</h2></div>
        <button type="button" data-dialog-close aria-label="关闭">×</button>
    </header>
    <div class="modal-body">
        @if($operation['status'] === 'ambiguous')
            <div class="notice notice-error" role="note" style="margin: 0 0 20px">
                <span class="notice-icon">!</span>
                <span>该操作结果不明确，严禁重放任何采购、结算或付款写操作。只可先在供应商侧核对账单和主机证据，再执行不构成付款确认的证据关联。</span>
            </div>
        @endif

        <section class="detail-summary-grid">
            <article><span>供应商</span><strong>{{ $operation['supplier_name'] }}</strong></article>
            <article><span>动作 / 状态</span><strong>{{ $operation['action_label'] }} / {{ $operation['status_label'] }}</strong></article>
            <article><span>步骤 / 尝试</span><strong>{{ $operation['step'] }} / {{ $operation['attempts'] }}</strong></article>
            <article><span>最近更新</span><strong>{{ $operation['updated_at'] ?? '—' }}</strong></article>
        </section>

        <div class="table-scroll" style="margin-bottom: 22px">
            <table class="data-table resource-table">
                <thead><tr><th>关联类型</th><th>本地 ID</th><th>上游 ID</th></tr></thead>
                <tbody>
                    <tr><td data-label="关联类型"><strong>服务 / 主机</strong></td><td data-label="本地 ID">{{ $operation['service_id'] ?? '—' }}</td><td data-label="上游 ID">{{ $operation['upstream_host_id'] }}</td></tr>
                    <tr><td data-label="关联类型"><strong>账单</strong></td><td data-label="本地 ID">{{ $operation['invoice_id'] ?? '—' }}</td><td data-label="上游 ID">{{ $operation['upstream_invoice_id'] }}</td></tr>
                    <tr><td data-label="关联类型"><strong>订单</strong></td><td data-label="本地 ID">{{ $operation['order_id'] ?? '—' }}</td><td data-label="上游 ID">{{ $operation['upstream_order_id'] }}</td></tr>
                </tbody>
            </table>
        </div>

        <section class="form-section">
            <div class="section-heading"><span>01</span><div><h3>错误摘要</h3><p>仅展示清洗后的错误代码与消息，不包含原始响应或请求载荷。</p></div></div>
            <div><strong>{{ $operation['error_code'] }}</strong><p class="muted" style="margin: 8px 0 0; font-size: 10px; line-height: 1.7">{{ $operation['error_message'] }}</p></div>
        </section>

        <section class="form-section">
            <div class="section-heading"><span>02</span><div><h3>时间线</h3><p>操作创建、开始、可再次检查和完成时间。</p></div></div>
            <div class="form-grid">
                <label class="field"><span>创建</span><input value="{{ $operation['created_at'] ?? '—' }}" readonly></label>
                <label class="field"><span>开始</span><input value="{{ $operation['started_at'] ?? '—' }}" readonly></label>
                <label class="field"><span>下次检查</span><input value="{{ $operation['available_at'] ?? '—' }}" readonly></label>
                <label class="field"><span>完成</span><input value="{{ $operation['finished_at'] ?? '—' }}" readonly></label>
            </div>
        </section>

        @if($operation['can_resume_credit'])
            <section class="form-section">
                <div class="section-heading"><span>03</span><div><h3>兼容自动扣余额</h3><p>仅对严格启用高风险兼容策略且仍符合条件的已保存账单调用一次 `/apply_credit`。旧版接口不能携带预期金额、币种、账单版本或幂等前置条件，因此无法原子限制实际扣款金额或币种；未知结果绝不重放。</p></div></div>
                <form method="POST" action="{{ route('admin.supplier-operations.resume-credit', $operation['id']) }}" class="form-grid">
                    @csrf
                    <input type="hidden" name="_form" value="supplier-operation-{{ $operation['id'] }}">
                    <label class="switch-field field-full"><input type="checkbox" name="confirmation" value="1" required><span></span><b>我理解无法原子限制扣款，并确认只调用一次旧版余额支付</b></label>
                    <button class="button button-primary field-full" type="submit">兼容自动扣余额</button>
                </form>
            </section>
        @endif

        @if($operation['can_attest_payment'])
            <section class="form-section">
                <div class="section-heading"><span>04</span><div><h3>已在上游人工付款并确认主机</h3><p>提交前必须在供应商后台逐项确认本操作对应的准确账单、商品、应付金额和币种完全一致，并已完成上游付款。这是具名管理员基于供应商侧凭证作出的人工作证，不是密码学付款验证。系统只读确认主机记录可读并取得有效状态，再等待安全轮询；本次不调用余额支付，也不直接激活本地服务。</p></div></div>
                <form method="POST" action="{{ route('admin.supplier-operations.attest-payment', $operation['id']) }}" class="form-grid">
                    @csrf
                    <input type="hidden" name="_form" value="supplier-operation-{{ $operation['id'] }}">
                    <label class="field field-full"><span>上游主机 ID <small>必须来自已人工付款的正确供应商主机</small></span><input name="upstream_host_id" value="" maxlength="128" autocomplete="off" required></label>
                    <label class="switch-field field-full"><input type="checkbox" name="confirmation" value="1" required><span></span><b>我已核对准确账单、商品、金额、币种及主机归属，确认已在上游付款，并理解这只是人工作证而非密码学验证</b></label>
                    <button class="button button-secondary field-full" type="submit">已在上游人工付款并确认主机</button>
                </form>
            </section>
        @endif

        @if($operation['can_recover_poll'])
            <section class="form-section">
                <div class="section-heading"><span>05</span><div><h3>恢复安全轮询</h3><p>重置确认计数并只读取已关联主机状态，不会执行任何采购写操作。</p></div></div>
                <form method="POST" action="{{ route('admin.supplier-operations.recover-poll', $operation['id']) }}" class="form-grid">
                    @csrf
                    <input type="hidden" name="_form" value="supplier-operation-{{ $operation['id'] }}">
                    <label class="switch-field field-full"><input type="checkbox" name="confirmation" value="1" required><span></span><b>我确认只对已关联主机执行状态读取</b></label>
                    <button class="button button-secondary field-full" type="submit">恢复并立即轮询</button>
                </form>
            </section>
        @endif

        @if($operation['can_reconcile_host'])
            <section class="form-section form-section-last">
                <div class="section-heading"><span>06</span><div><h3>按供应商证据关联主机</h3><p>先在事务外只读验证主机，再在状态复核事务中建立关联；这不是采购重试，也不构成付款确认。</p></div></div>
                <form method="POST" action="{{ route('admin.supplier-operations.reconcile-host', $operation['id']) }}" class="form-grid">
                    @csrf
                    <input type="hidden" name="_form" value="supplier-operation-{{ $operation['id'] }}">
                    <label class="field field-full"><span>上游主机 ID <small>来自供应商侧可核验记录</small></span><input name="upstream_host_id" value="" maxlength="128" autocomplete="off" required></label>
                    <label class="switch-field field-full"><input type="checkbox" name="confirmation" value="1" required><span></span><b>我已核对供应商证据，并确认不重放采购</b></label>
                    <button class="button button-primary field-full" type="submit">验证并关联主机</button>
                </form>
            </section>
        @endif

        @if(! $operation['can_resume_credit'] && ! $operation['can_attest_payment'] && ! $operation['can_recover_poll'] && ! $operation['can_reconcile_host'])
            <div class="empty-state compact"><span>SAFE</span><h3>当前没有可执行的安全恢复</h3><p>状态或关联记录不满足恢复条件；页面不会提供通用采购重试。</p></div>
        @endif
    </div>
    <footer class="modal-foot"><button class="button button-ghost" type="button" data-dialog-close>关闭</button></footer>
</dialog>
