<?php

namespace App\Models;

use App\Enums\NhsObligation;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * @property int $id
 * @property string $fingerprint
 * @property string $title
 * @property string|null $issuer
 * @property string|null $published_on
 * @property string|null $updated_on
 * @property string|null $effective_on
 * @property NhsObligation $obligation
 * @property string|null $applies_to
 * @property string|null $source_title
 * @property string|null $source_url
 * @property array<int, array<string, string>>|null $sources
 * @property string|null $summary
 * @property string|null $commercial_implications
 * @property string $confidence
 * @property bool $requirement_confirmed
 * @property CarbonInterface|null $last_verified_at
 */
#[Fillable([
    'fingerprint',
    'title',
    'issuer',
    'published_on',
    'updated_on',
    'effective_on',
    'obligation',
    'applies_to',
    'source_title',
    'source_url',
    'sources',
    'summary',
    'commercial_implications',
    'confidence',
    'requirement_confirmed',
    'last_verified_at',
])]
class NhsDirective extends Model
{
    /**
     * Product opportunities supported by this directive.
     *
     * @return BelongsToMany<NhsProductOpportunity, $this>
     */
    public function opportunities(): BelongsToMany
    {
        return $this->belongsToMany(NhsProductOpportunity::class, 'nhs_directive_opportunity');
    }

    /**
     * Whether this source is an official directive, a news or blog item, or Greener NHS research.
     */
    public function researchGroup(): string
    {
        return self::researchGroupFor($this->title, $this->source_url, $this->source_title);
    }

    /**
     * Official directives sort first, then news and blogs, then Greener NHS.
     */
    public function researchGroupRank(): int
    {
        return match ($this->researchGroup()) {
            'news' => 1,
            'greener' => 2,
            default => 0,
        };
    }

    public static function researchGroupFor(?string $title, ?string $url, ?string $sourceTitle): string
    {
        $heading = strtolower(trim(implode(' ', array_filter([$title, $sourceTitle]))));
        $address = strtolower((string) $url);

        if (preg_match('/greener|net zero|net-zero|sustainab|decarbon|carbon neutral|\bcarbon\b|climate/', $heading.' '.$address) === 1) {
            return 'greener';
        }

        if (preg_match('#/(blog|news)(/|$)#', $address) === 1 || preg_match('/\b(blog|news)\b/', $heading) === 1) {
            return 'news';
        }

        return 'directive';
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'obligation' => NhsObligation::class,
            'sources' => 'array',
            'requirement_confirmed' => 'boolean',
            'last_verified_at' => 'datetime',
        ];
    }
}
