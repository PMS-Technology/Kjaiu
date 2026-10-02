{{-- Passwords, confirmation, custom client fields, sales rep. Shared by both
     registration tabs so the posted names stay identical. --}}
<div>
    <label class="form-label" for="password-{{ $prefix }}">登录密码</label>
    <input type="password" id="password-{{ $prefix }}" name="password" class="form-input" data-encrypt required
           placeholder="至少 6 位字符" autocomplete="new-password">
    <p class="form-hint">密码长度不少于 6 位</p>
</div>

<div>
    <label class="form-label" for="checkPassword-{{ $prefix }}">确认密码</label>
    <input type="password" id="checkPassword-{{ $prefix }}" name="checkPassword" class="form-input" data-encrypt required
           placeholder="请再次输入密码" autocomplete="new-password">
</div>

@foreach ($Register['fields'] as $field)
    <div>
        <label class="form-label" for="field-{{ $prefix }}-{{ $field['id'] }}">
            {{ $field['fieldname'] }}
            @if ($field['required'])
                <span class="text-rose-500">*</span>
            @endif
        </label>

        @if ($field['fieldtype'] === 'dropdown')
            <select id="field-{{ $prefix }}-{{ $field['id'] }}" name="fields[{{ $field['id'] }}]" class="form-select"
                    @required($field['required'])>
                <option value="">请选择</option>
                @foreach ($field['dropdown_option'] as $option)
                    <option value="{{ $option }}" @selected(old("fields.{$field['id']}") === $option)>{{ $option }}</option>
                @endforeach
            </select>
        @elseif ($field['fieldtype'] === 'textarea')
            <textarea id="field-{{ $prefix }}-{{ $field['id'] }}" name="fields[{{ $field['id'] }}]"
                      class="form-textarea" @required($field['required'])>{{ old("fields.{$field['id']}") }}</textarea>
        @elseif ($field['fieldtype'] === 'tickbox')
            <label class="flex items-center gap-2 text-sm text-slate-600">
                <input type="checkbox" name="fields[{{ $field['id'] }}]" value="1" class="form-checkbox">
                {{ $field['description'] ?: $field['fieldname'] }}
            </label>
        @elseif ($field['fieldtype'] === 'password')
            <input type="password" id="field-{{ $prefix }}-{{ $field['id'] }}" name="fields[{{ $field['id'] }}]"
                   class="form-input" @required($field['required']) autocomplete="new-password">
        @else
            <input type="text" id="field-{{ $prefix }}-{{ $field['id'] }}" name="fields[{{ $field['id'] }}]"
                   class="form-input" value="{{ old("fields.{$field['id']}") }}" @required($field['required'])>
        @endif

        @if (! empty($field['description']) && $field['fieldtype'] !== 'tickbox')
            <p class="form-hint">{{ $field['description'] }}</p>
        @endif
    </div>
@endforeach

{{-- Fields the administrator made mandatory by name. --}}
@foreach ($Register['login_register_custom_require'] as $custom)
    <div>
        <label class="form-label" for="require-{{ $prefix }}-{{ $custom['name'] }}">
            {{ $custom['label'] }} <span class="text-rose-500">*</span>
        </label>
        <input type="text" id="require-{{ $prefix }}-{{ $custom['name'] }}" name="{{ $custom['name'] }}"
               class="form-input" required value="{{ old($custom['name']) }}">
    </div>
@endforeach
