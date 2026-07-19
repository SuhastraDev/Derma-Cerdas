<?php

namespace App\Http\Controllers;

use App\Models\RedFlag;
use App\Models\Symptom;
use App\Services\AdaptiveQuestionService;
use App\Services\AiVisualService;
use App\Services\CertaintyFactorService;
use App\Services\ComplaintExtractionService;
use App\Services\ConsultationWorkflowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class ConsultationController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('Consultation/Start', [
            'symptoms' => Symptom::query()
                ->where('is_active', true)
                ->orderBy('id')
                ->get(['id', 'code', 'name', 'question']),
            'redFlags' => RedFlag::query()
                ->where('is_active', true)
                ->orderBy('id')
                ->get(['id', 'code', 'question', 'severity']),
        ]);
    }

    public function store(Request $request, ConsultationWorkflowService $workflow): RedirectResponse
    {
        $validated = $request->validate([
            'visitor_name' => ['required', 'string', 'max:120'],
            'complaint_text' => ['required', 'string', 'min:12', 'max:1500'],
            'consent' => ['accepted'],
            'image' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'symptoms' => ['required', 'array'],
            'symptoms.*' => ['nullable', 'numeric', 'min:0', 'max:1'],
            'red_flags' => ['nullable', 'array'],
            'red_flags.*' => ['nullable', 'boolean'],
        ]);

        $consultation = $workflow->process(
            visitorName: $validated['visitor_name'],
            complaintText: $validated['complaint_text'],
            image: $validated['image'],
            symptomInputs: $validated['symptoms'],
            redFlagInputs: $validated['red_flags'] ?? [],
        );

        return redirect()->route('consultation.result', $consultation->session_code);
    }

    public function precheck(
        Request $request,
        ComplaintExtractionService $complaintExtractionService,
        CertaintyFactorService $certaintyFactorService,
        AiVisualService $aiVisualService,
        AdaptiveQuestionService $adaptiveQuestionService
    ): JsonResponse {
        $validated = $request->validate([
            'complaint_text' => ['required', 'string', 'min:12', 'max:1500'],
            'image' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ]);

        $complaintFeatures = $complaintExtractionService->extract($validated['complaint_text']);
        $symptomEvidence = collect($complaintFeatures['symptom_evidence'] ?? [])
            ->mapWithKeys(fn (array $evidence, string $code): array => [$code => (float) ($evidence['score'] ?? 0)])
            ->all();
        $textualRankings = $certaintyFactorService->rankDiseases($symptomEvidence);
        $imagePath = $validated['image']->store('prechecks', 'public');

        try {
            $visualAnalysis = $aiVisualService->analyze($imagePath, $textualRankings);
        } finally {
            Storage::disk('public')->delete($imagePath);
        }

        $selectedSymptoms = $adaptiveQuestionService->selectSymptoms(
            $complaintFeatures,
            $visualAnalysis['candidates'] ?? []
        );

        return response()->json([
            'selected_symptoms' => $selectedSymptoms->map(fn (Symptom $symptom): array => [
                'id' => $symptom->id,
                'code' => $symptom->code,
                'name' => $symptom->name,
                'question' => $symptom->question,
            ])->values(),
            'complaint_summary' => $complaintFeatures['summary'] ?? [],
            'visual' => [
                'status' => $visualAnalysis['validation_status'],
                'is_valid_skin_image' => $visualAnalysis['is_valid_skin_image'],
                'warnings' => $visualAnalysis['warnings'],
                'candidates' => collect($visualAnalysis['candidates'] ?? [])
                    ->take(3)
                    ->map(fn (array $candidate): array => [
                        'disease_name' => $candidate['disease']?->name_indonesian ?: $candidate['disease']?->name,
                        'visual_score' => $candidate['visual_score'],
                        'reason' => $candidate['visual_reason'],
                    ])
                    ->values(),
            ],
        ]);
    }

    public function result(string $sessionCode, ConsultationWorkflowService $workflow): Response
    {
        $consultation = $workflow->loadResult($sessionCode);
        $finalResult = $consultation->finalResults->sortByDesc('fusion_score')->first();
        $comparisonImages = $this->datasetComparisonImages($finalResult?->disease);

        return Inertia::render('Consultation/Result', [
            'consultation' => [
                'session_code' => $consultation->session_code,
                'visitor_name' => $consultation->visitor_name,
                'complaint_text' => $consultation->complaint_text,
                'complaint_features' => $consultation->complaint_features,
                'image_path' => $consultation->image_path,
                'uploaded_image_url' => $consultation->image_path ? '/storage/'.$consultation->image_path : null,
                'status' => $consultation->status,
                'final_score' => $consultation->final_score,
                'final_action' => $consultation->final_action,
                'visual_validation' => $consultation->metadata['visual_validation'] ?? null,
                'created_at' => $consultation->created_at?->toDateTimeString(),
            ],
            'finalResult' => $finalResult ? [
                'disease_name' => $finalResult->disease?->name,
                'disease_name_indonesian' => $finalResult->disease?->name_indonesian,
                'textual_cf' => (float) $finalResult->textual_cf,
                'visual_score' => (float) $finalResult->visual_score,
                'fusion_score' => (float) $finalResult->fusion_score,
                'action' => $finalResult->action,
                'explanation' => $finalResult->explanation,
                'recommendations' => $finalResult->recommendations_snapshot ?? [],
            ] : null,
            'redFlags' => $consultation->redFlags
                ->filter(fn ($item): bool => (bool) $item->detected)
                ->map(fn ($item): array => [
                    'code' => $item->redFlag?->code,
                    'question' => $item->redFlag?->question,
                    'action_message' => $item->redFlag?->action_message,
                    'severity' => $item->redFlag?->severity,
                ])
                ->values(),
            'symptoms' => $consultation->symptoms
                ->filter(fn ($item): bool => (bool) $item->selected)
                ->map(fn ($item): array => [
                    'name' => $item->symptom?->name,
                    'question' => $item->symptom?->question,
                    'user_cf' => (float) $item->user_cf,
                ])
                ->values(),
            'visualResults' => $consultation->visualResults
                ->sortByDesc('visual_score')
                ->map(fn ($item): array => [
                    'disease_name_indonesian' => $item->disease?->name_indonesian,
                    'visual_score' => (float) $item->visual_score,
                    'visual_reason' => $item->visual_reason,
                    'provider' => $item->provider,
                ])
                ->values(),
            'comparisonImages' => $comparisonImages,
        ]);
    }

    public function history(): Response
    {
        return Inertia::render('Consultation/HistoryCheck');
    }

    public function checkHistory(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'session_code' => ['required', 'string', 'max:80'],
        ]);

        $sessionCode = Str::upper(trim($validated['session_code']));

        if (! $sessionCode || ! $this->sessionCodeExists($sessionCode)) {
            return back()
                ->withErrors(['session_code' => 'Kode konsultasi tidak ditemukan. Pastikan kode disalin lengkap.'])
                ->withInput();
        }

        return redirect()->route('consultation.result', $sessionCode);
    }

    public function export(string $sessionCode, ConsultationWorkflowService $workflow): HttpResponse
    {
        $consultation = $workflow->loadResult($sessionCode);
        $finalResult = $consultation->finalResults->sortByDesc('fusion_score')->first();
        $comparisonImages = $this->datasetComparisonImages($finalResult?->disease);
        $uploadedImageUrl = $consultation->image_path ? '/storage/'.$consultation->image_path : null;

        $complaintSummary = $consultation->complaint_features['summary'] ?? [];

        return response()
            ->view('consultations.export', compact('consultation', 'finalResult', 'comparisonImages', 'uploadedImageUrl', 'complaintSummary'))
            ->header('Content-Type', 'text/html; charset=UTF-8');
    }

    public function datasetExampleImage(string $className, string $fileName): BinaryFileResponse
    {
        abort_unless(preg_match('/^[A-Za-z0-9_().-]+$/', $className), 404);
        abort_unless(preg_match('/^[A-Za-z0-9_.-]+$/', $fileName), 404);

        $basePath = base_path('datasets/sd-198/images');
        $path = $basePath.DIRECTORY_SEPARATOR.$className.DIRECTORY_SEPARATOR.$fileName;
        $realBase = realpath($basePath);
        $realPath = realpath($path);

        abort_unless($realBase && $realPath && str_starts_with($realPath, $realBase), 404);

        return response()->file($realPath);
    }

    private function sessionCodeExists(string $sessionCode): bool
    {
        return \App\Models\Consultation::query()
            ->where('session_code', $sessionCode)
            ->exists();
    }

    /**
     * @return array<int, array{class_name: string, file_name: string, url: string}>
     */
    private function datasetComparisonImages(?\App\Models\Disease $disease): array
    {
        if (! $disease) {
            return [];
        }

        $mapping = $disease->loadMissing('datasetMappings')->datasetMappings->first();

        if (! $mapping) {
            return [];
        }

        $className = $mapping->dataset_class_name;
        $directory = base_path('datasets/sd-198/images/'.$className);

        if (! is_dir($directory)) {
            return [];
        }

        $files = collect(scandir($directory) ?: [])
            ->filter(fn (string $file): bool => $this->isReadableDatasetImage($directory.DIRECTORY_SEPARATOR.$file))
            ->sort(SORT_NATURAL | SORT_FLAG_CASE)
            ->take(3)
            ->values();

        return $files
            ->map(fn (string $file): array => [
                'class_name' => $className,
                'file_name' => $file,
                'url' => route('dataset.example-image', [$className, $file]),
            ])
            ->all();
    }

    private function isReadableDatasetImage(string $path): bool
    {
        if (! is_file($path) || filesize($path) <= 0 || ! preg_match('/\.(jpg|jpeg|png|webp)$/i', $path)) {
            return false;
        }

        $imageInfo = @getimagesize($path);

        if ($imageInfo === false) {
            return false;
        }

        $image = match ($imageInfo['mime'] ?? '') {
            'image/jpeg' => @imagecreatefromjpeg($path),
            'image/png' => @imagecreatefrompng($path),
            'image/webp' => @imagecreatefromwebp($path),
            default => false,
        };

        if ($image === false) {
            return false;
        }

        imagedestroy($image);

        return true;
    }
}
