{{-- Sales rep select (only when 面板 sets `setsaler = 2`) and the TOS gate. --}}
@if (! empty($saleList))
    <div>
        <label class="form-label" for="sale_id">推荐业务员</label>
        <select name="sale_id" id="sale_id" class="form-select">
            <option value="0">无</option>
            @foreach ($saleList as $sale)
                <option value="{{ $sale['id'] }}" @selected((int) old('sale_id') === $sale['id'])>{{ $sale['user_nickname'] }}</option>
            @endforeach
        </select>
    </div>
@endif

<label class="flex items-start gap-2 text-sm text-slate-600">
    <input type="checkbox" id="agreePrivacy" class="form-checkbox mt-0.5" required>
    <span>
        我已阅读并同意
        @if (! empty($Setting['web_tos_url']))
            <a href="{{ $Setting['web_tos_url'] }}" target="_blank" rel="noopener" class="text-brand-600 hover:underline">《服务条款》</a>
        @endif
        @if (! empty($Setting['web_privacy_url']))
            <a href="{{ $Setting['web_privacy_url'] }}" target="_blank" rel="noopener" class="text-brand-600 hover:underline">《隐私政策》</a>
        @endif
    </span>
</label>
