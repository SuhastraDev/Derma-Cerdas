<?php

namespace App\Http\Controllers;

use App\Models\RedFlag;
use App\Models\Symptom;
use App\Services\ConsultationWorkflowService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
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
            'consent' => ['accepted'],
            'image' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'symptoms' => ['required', 'array'],
            'symptoms.*' => ['nullable', 'numeric', 'min:0', 'max:1'],
            'red_flags' => ['nullable', 'array'],
            'red_flags.*' => ['nullable', 'boolean'],
        ]);

        $consultation = $workflow->process(
            visitorName: $validated['visitor_name'],
            image: $validated['image'],
            symptomInputs: $validated['symptoms'],
            redFlagInputs: $validated['red_flags'] ?? [],
        );

        return redirect()->route('consultation.result', $consultation->session_code);
    }

    public function result(string $sessionCode, ConsultationWorkflowService $workflow): Response
    {
        $consultation = $workflow->loadResult($sessionCode);
        $finalResult = $consultation->finalResults->sortByDesc('fusion_score')->first();

        return Inertia::render('Consultation/Result', [
            'consultation' => [
                'session_code' => $consultation->session_code,
                'visitor_name' => $consultation->visitor_name,
                'image_path' => $consultation->image_path,
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

        return response()
            ->view('consultations.export', compact('consultation', 'finalResult'))
            ->header('Content-Type', 'text/html; charset=UTF-8');
    }

    private function sessionCodeExists(string $sessionCode): bool
    {
        return \App\Models\Consultation::query()
            ->where('session_code', $sessionCode)
            ->exists();
    }
}
