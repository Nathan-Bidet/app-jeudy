<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LdtEntry extends Model
{
    protected $fillable = [
        'date',
        'assignee_type',
        'assignee_id',
        'assignee_label_free',
        'assignee_label',
        'phones',
        'tasks_text',
        'comments_text',
        'vehicles_text',
        'indicators',
        'is_all_pointed',
        'sms_sent',
        'sms_sent_at',
        'sms_sent_by_user_id',
        'source_task_ids',
        'color_style',
        'updated_from_source_at',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'phones' => 'array',
            'indicators' => 'array',
            'is_all_pointed' => 'boolean',
            'sms_sent' => 'boolean',
            'sms_sent_at' => 'datetime',
            'source_task_ids' => 'array',
            'color_style' => 'array',
            'updated_from_source_at' => 'datetime',
            'assignee_id' => 'integer',
            'assignee_label_free' => 'string',
            'sms_sent_by_user_id' => 'integer',
        ];
    }

    public function smsSentBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sms_sent_by_user_id');
    }
}
