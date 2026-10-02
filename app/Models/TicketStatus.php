<?php

namespace App\Models;

/**
 * Ticket status (`shd_ticket_status`), administrator-editable.
 */
class TicketStatus extends ShdModel
{
    protected $table = 'ticket_status';

    protected $casts = [
        'order' => 'integer',
        'show_active' => 'integer',
        'show_await' => 'integer',
        'auto_close' => 'integer',
    ];
}
