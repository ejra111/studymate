<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StudyNotification extends Model
{
    protected $table = 'study_notifications';

    protected $fillable = [
        'id', 'sender_id', 'receiver_id', 'type', 'message', 'data', 'read_at',
    ];

    public $incrementing = false;
    protected $keyType = 'string';

    protected $casts = [
        'data' => 'array',
        'read_at' => 'datetime',
    ];

    public function sender()
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    public function receiver()
    {
        return $this->belongsTo(User::class, 'receiver_id');
    }

    protected $appends = ['senderId', 'receiverId', 'readAt'];

    public function getSenderIdAttribute()
    {
        return $this->attributes['sender_id'] ?? null;
    }

    public function getReceiverIdAttribute()
    {
        return $this->attributes['receiver_id'] ?? null;
    }

    public function getReadAtAttribute()
    {
        $val = $this->attributes['read_at'] ?? null;
        return $val;
    }
}
