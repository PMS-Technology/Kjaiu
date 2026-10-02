<?php

namespace App\Models;

/**
 * Client group / discount tier (`shd_client_groups`).
 */
class ClientGroup extends ShdModel
{
    protected $table = 'client_groups';

    protected $casts = [
        'discount_percent' => 'integer',
        'susptermexempt' => 'integer',
        'separateinvoices' => 'integer',
    ];

    public function clients()
    {
        return $this->hasMany(Client::class, 'groupid');
    }
}
