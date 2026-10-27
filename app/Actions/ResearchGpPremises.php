<?php

namespace App\Actions;

use App\Data\GpPremisesResult;
use App\Enums\GpDevelopmentType;
use App\Enums\GpLeadStatus;
use App\Models\AiModel;
use App\Models\GpPremisesLead;
use App\Models\GpPremisesSetting;
use App\Services\GpPremisesNormalizer;
use App\Services\GpPremisesResearcher;
use RuntimeException;
use Throwable;

class ResearchGpPremises
{
    public function __construct(
        private GpPremisesResearcher $researcher,
        private GpPremisesNormalizer $normalizer,
    ) {}

    /**
     * Search for GP premises projects and merge them into the pipeline.
     */
    public function discover(GpPremisesSetting $settings): GpPremisesResult
    {
        set_time_limit(0);

        $aiModel = $this->aiModel();

        if (! $aiModel instanceof AiModel) {
            return new GpPremisesResult(0, 0, 0, __('Connect ChatGPT before researching GP premises.'));
        }

        try {
            $existing = GpPremisesLead::query()
                ->latest('id')
                ->limit(30)
                ->get()
                ->map(fn (GpPremisesLead $lead): array => [
                    'surgery_name' => $lead->surgery_name,
                    'town' => $lead->town,
                    'development_type' => $lead->development_type->value,
                    'project_stage' => $lead->project_stage->value,
                    'source_url' => $lead->source_url,
                ])
                ->all();
            $research = $this->researcher->discover($aiModel, $settings, $existing);
        } catch (RuntimeException $exception) {
            return new GpPremisesResult(0, 0, 0, $this->safeReason($exception));
        } catch (Throwable) {
            return new GpPremisesResult(0, 0, 0, __('ChatGPT could not research GP premises.'));
        }

        $counts = $this->storeLeads($research, $settings);
        $summary = $this->normalizer->summary($research);
        $settings->last_summary = trim($summary['summary']) !== '' ? trim($summary['summary']) : null;
        $settings->last_limitations = $summary['limitations'] !== '' ? $summary['limitations'] : null;
        $settings->last_ran_at = now();
        $settings->save();

        return new GpPremisesResult($counts['added'], $counts['updated'], 0);
    }

    /**
     * Check one saved project for a newer public status.
     */
    public function refresh(GpPremisesLead $lead): GpPremisesResult
    {
        set_time_limit(0);

        $aiModel = $this->aiModel();

        if (! $aiModel instanceof AiModel) {
            return new GpPremisesResult(0, 0, 0, __('Connect ChatGPT before researching GP premises.'));
        }

        try {
            $research = $this->researcher->refresh($aiModel, $lead);
        } catch (RuntimeException $exception) {
            return new GpPremisesResult(0, 0, 0, $this->safeReason($exception));
        } catch (Throwable) {
            return new GpPremisesResult(0, 0, 0, __('ChatGPT could not research GP premises.'));
        }

        $incoming = is_array($research['lead'] ?? null) ? $research['lead'] : $research;
        $normalized = $this->normalizer->lead($incoming, GpPremisesSetting::current(), GpDevelopmentType::cases(), false);

        if ($normalized === null) {
            $lead->last_verified_at = now();
            $lead->save();

            return new GpPremisesResult(0, 0, 1);
        }

        $this->fill($lead, $normalized);
        $lead->save();

        return new GpPremisesResult(0, 1, 1);
    }

    /**
     * Re-check the projects that have gone longest without a status check, then search for new ones.
     */
    public function checkPipeline(GpPremisesSetting $settings): GpPremisesResult
    {
        $added = 0;
        $updated = 0;
        $checked = 0;

        $leads = GpPremisesLead::query()
            ->whereIn('status', [GpLeadStatus::Active, GpLeadStatus::Watchlist])
            ->orderByRaw('last_verified_at is null desc')
            ->orderBy('last_verified_at')
            ->limit(5)
            ->get();

        foreach ($leads as $lead) {
            $result = $this->refresh($lead);
            $checked++;
            $updated += $result->updated;

            if ($result->skippedReason !== null) {
                return new GpPremisesResult($added, $updated, $checked, $result->skippedReason);
            }
        }

        $discovery = $this->discover($settings);

        return new GpPremisesResult(
            $added + $discovery->added,
            $updated + $discovery->updated,
            $checked,
            $discovery->skippedReason,
        );
    }

    private function aiModel(): ?AiModel
    {
        $aiModel = AiModel::current();

        return $aiModel !== null && filled($aiModel->api_key) ? $aiModel : null;
    }

    /**
     * @param  array<string, mixed>  $research
     * @return array{added: int, updated: int}
     */
    private function storeLeads(array $research, GpPremisesSetting $settings): array
    {
        $added = 0;
        $updated = 0;
        $stored = 0;
        $leads = is_array($research['leads'] ?? null) ? $research['leads'] : [];

        foreach ($leads as $lead) {
            if (! is_array($lead) || $stored >= 30) {
                continue;
            }

            $normalized = $this->normalizer->lead($lead, $settings, $settings->selectedTypes());

            if ($normalized === null) {
                continue;
            }

            $record = GpPremisesLead::query()->firstOrNew([
                'fingerprint' => $normalized['fingerprint'],
            ]);
            $exists = $record->exists;
            $this->fill($record, $normalized);
            $record->save();
            $stored++;
            $exists ? $updated++ : $added++;
        }

        return ['added' => $added, 'updated' => $updated];
    }

    /**
     * @param  array<string, mixed>  $normalized
     */
    private function fill(GpPremisesLead $lead, array $normalized): void
    {
        $sources = is_array($lead->sources) ? $lead->sources : [];
        $url = is_string($normalized['source_url'] ?? null) ? $normalized['source_url'] : null;
        $known = collect($sources)->contains(fn (array $source): bool => ($source['url'] ?? null) === $url);

        if ($url !== null && ! $known) {
            $sources[] = [
                'title' => is_string($normalized['source_title'] ?? null) ? $normalized['source_title'] : $url,
                'url' => $url,
                'published_on' => is_string($normalized['source_published_on'] ?? null) ? $normalized['source_published_on'] : '',
            ];
        }

        if ($lead->exists) {
            unset($normalized['fingerprint'], $normalized['surgery_name']);
        }

        $lead->fill($normalized);
        $lead->sources = array_slice($sources, -12);
        $lead->last_verified_at = now();
    }

    private function safeReason(RuntimeException $exception): string
    {
        return in_array($exception->getMessage(), [
            'Could not reach ChatGPT.',
            'ChatGPT could not research GP premises.',
        ], true) ? $exception->getMessage() : __('ChatGPT could not research GP premises.');
    }
}
