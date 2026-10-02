<?php

namespace App\Models;

/**
 * Ticket reply (`shd_ticket_reply`).
 */
class TicketReply extends ShdModel
{
    protected $table = 'ticket_reply';

    protected $casts = [
        'tid' => 'integer',
        'uid' => 'integer',
        'admin_id' => 'integer',
        'create_time' => 'integer',
        'star' => 'integer',
    ];

    public function ticket()
    {
        return $this->belongsTo(Ticket::class, 'tid');
    }

    public function isStaffReply(): bool
    {
        return (int) $this->admin_id > 0 || trim((string) $this->admin) !== '';
    }

    public function attachmentList(): array
    {
        if (trim((string) $this->attachment) === '') {
            return [];
        }

        $decoded = json_decode((string) $this->attachment, true);

        return is_array($decoded) ? $decoded : [];
    }
}
