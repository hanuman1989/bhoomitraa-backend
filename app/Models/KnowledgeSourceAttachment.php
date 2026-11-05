<?php

namespace App\Models;

use Database\Factories\KnowledgeSourceAttachmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KnowledgeSourceAttachment extends Model
{
    /** @use HasFactory<KnowledgeSourceAttachmentFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = ['file_name', 'file_path', 'file_type', 'file_size'];

    /** @var list<string> */
    protected $hidden = ['file_path'];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['file_size' => 'integer'];
    }

    public function knowledgeSource(): BelongsTo
    {
        return $this->belongsTo(KnowledgeSource::class);
    }
}
