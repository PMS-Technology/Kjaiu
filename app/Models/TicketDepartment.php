<?php

namespace App\Models;

/**
 * Ticket department (`shd_ticket_department`).
 */
class TicketDepartment extends ShdModel
{
    protected $table = 'ticket_department';

    protected $hidden = ['password'];

    protected $casts = [
        'hidden' => 'integer',
        'only_reg_client' => 'integer',
        'only_client_open' => 'integer',
        'order' => 'integer',
    ];

    public function tickets()
    {
        return $this->hasMany(Ticket::class, 'dptid');
    }
}
