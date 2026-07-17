<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Consultation;
use App\Models\ConsultationFinalResult;
use App\Models\DatasetClassMapping;
use App\Models\Disease;
use App\Models\DiseaseSymptomRule;
use App\Models\Medicine;
use App\Models\RedFlag;
use App\Models\Symptom;
use Carbon\CarbonPeriod;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(): Response
    {
        $from = now()->subDays(13)->startOfDay();
        $consultations = Consultation::query()
            ->with(['finalResults.disease'])
            ->where('created_at', '>=', $from)
            ->get();

        $trendCounts = $consultations
            ->groupBy(fn (Consultation $consultation): string => $consultation->created_at->toDateString())
            ->map->count();

        $trend = collect(CarbonPeriod::create($from, now()->startOfDay()))
            ->map(fn (Carbon $date): array => [
                'label' => $date->translatedFormat('d M'),
                'date' => $date->toDateString(),
                'count' => $trendCounts->get($date->toDateString(), 0),
            ])
            ->values();

        $actionDistribution = Consultation::query()
            ->selectRaw('COALESCE(final_action, status) as label, COUNT(*) as total')
            ->groupByRaw('COALESCE(final_action, status)')
            ->orderByDesc('total')
            ->get()
            ->map(fn (object $row): array => [
                'label' => $this->actionLabel((string) $row->label),
                'key' => (string) $row->label,
                'total' => (int) $row->total,
            ]);

        $topDiseases = ConsultationFinalResult::query()
            ->with('disease')
            ->whereNotNull('disease_id')
            ->get()
            ->groupBy('disease_id')
            ->map(fn ($results): array => [
                'label' => $results->first()->disease?->name_indonesian
                    ?: $results->first()->disease?->name
                    ?: 'Tidak diketahui',
                'total' => $results->count(),
            ])
            ->sortByDesc('total')
            ->take(5)
            ->values();

        $latestConsultations = Consultation::query()
            ->latest()
            ->limit(6)
            ->get(['session_code', 'visitor_name', 'status', 'final_score', 'final_action', 'created_at'])
            ->map(fn (Consultation $consultation): array => [
                'session_code' => $consultation->session_code,
                'visitor_name' => $consultation->visitor_name ?: 'Pengguna',
                'status' => $consultation->status,
                'final_score' => $consultation->final_score,
                'final_action' => $consultation->final_action,
                'created_at' => $consultation->created_at->translatedFormat('d M Y H:i'),
            ]);

        return Inertia::render('Dashboard', [
            'stats' => [
                'total_consultations' => Consultation::query()->count(),
                'today_consultations' => Consultation::query()->whereDate('created_at', today())->count(),
                'refer_consultations' => Consultation::query()->where('final_action', 'refer')->count(),
                'average_score' => round((float) Consultation::query()->whereNotNull('final_score')->avg('final_score'), 2),
                'active_diseases' => Disease::query()->where('is_active', true)->count(),
                'active_symptoms' => Symptom::query()->where('is_active', true)->count(),
                'rules' => DiseaseSymptomRule::query()->count(),
                'medicines' => Medicine::query()->where('is_active', true)->count(),
                'red_flags' => RedFlag::query()->where('is_active', true)->count(),
                'dataset_mappings' => DatasetClassMapping::query()->count(),
            ],
            'charts' => [
                'trend' => $trend,
                'actions' => $actionDistribution,
                'top_diseases' => $topDiseases,
            ],
            'latestConsultations' => $latestConsultations,
        ]);
    }

    private function actionLabel(string $action): string
    {
        return match ($action) {
            'recommend_otc' => 'Rekomendasi obat',
            'educate_only' => 'Edukasi',
            'refer' => 'Rujuk',
            'completed' => 'Selesai',
            'draft' => 'Draft',
            default => str($action)->replace('_', ' ')->headline()->toString(),
        };
    }
}
