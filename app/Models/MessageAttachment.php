<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MessageAttachment extends Model
{
    protected $table = 'logistics_message_attachments';

    protected $fillable = [
        'message_id',
        'original_filename',
        'stored_path',
        'mime_type',
        'file_size',
        'disk',
    ];

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    public function isImage(): bool
    {
        return in_array($this->mime_type, ['image/jpeg', 'image/png', 'image/webp', 'image/gif']);
    }

    public function isPdf(): bool
    {
        return $this->mime_type === 'application/pdf';
    }

    public function humanSize(): string
    {
        $bytes = $this->file_size;
        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 1) . ' MB';
        }
        if ($bytes >= 1024) {
            return round($bytes / 1024, 1) . ' KB';
        }
        return $bytes . ' B';
    }

    public function absolutePath(): string
    {
        if (str_starts_with($this->stored_path, 'message-attachments/')) {
            // Attachments may live on the 'local' (private) or 'public' disk
            // depending on which client uploaded them (web vs Rider App API).
            $disk = in_array($this->disk, ['local', 'public']) ? $this->disk : 'local';
            return \Illuminate\Support\Facades\Storage::disk($disk)->path($this->stored_path);
        }

        return public_path($this->stored_path);
    }

    public function fileExists(): bool
    {
        return is_file($this->absolutePath());
    }
}
