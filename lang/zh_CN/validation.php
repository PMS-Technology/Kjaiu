<?php

return [
    'required' => ':attribute不能为空。',
    'email' => ':attribute格式不正确。',
    'unique' => ':attribute已被使用。',
    'min' => [
        'string' => ':attribute至少需要 :min 个字符。',
        'numeric' => ':attribute不能小于 :min。',
    ],
    'max' => [
        'string' => ':attribute不能超过 :max 个字符。',
        'numeric' => ':attribute不能大于 :max。',
    ],
    'numeric' => ':attribute必须是数字。',
    'integer' => ':attribute必须是整数。',
    'date' => ':attribute必须是有效日期。',
    'after_or_equal' => ':attribute不能早于 :date。',
    'exists' => '所选:attribute不存在。',
    'in' => '所选:attribute无效。',
    'ip' => ':attribute必须是有效 IP 地址。',
    'attributes' => [
        'name' => '名称',
        'email' => '邮箱',
        'phone' => '手机号',
        'password' => '密码',
        'amount' => '金额',
        'due_at' => '到期时间',
        'description' => '描述',
        'quantity' => '库存',
    ],
];
