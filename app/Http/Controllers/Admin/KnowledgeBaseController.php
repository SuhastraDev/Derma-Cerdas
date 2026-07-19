<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Consultation;
use App\Models\DatasetClassMapping;
use App\Models\Disease;
use App\Models\DiseaseMedicineRecommendation;
use App\Models\DiseaseSymptomRule;
use App\Models\Medicine;
use App\Models\RedFlag;
use App\Models\Setting;
use App\Models\Symptom;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class KnowledgeBaseController extends Controller
{
    public function index(Request $request, string $resource): Response
    {
        $config = $this->resourceConfig($resource);
        $query = $config['model']::query();

        foreach ($config['with'] ?? [] as $relation) {
            $query->with($relation);
        }

        $items = $query
            ->latest('id')
            ->limit(150)
            ->get()
            ->map(fn (Model $item): array => $this->serializeItem($item, $config));

        return Inertia::render('Admin/ResourceIndex', [
            'resource' => $resource,
            'title' => $config['title'],
            'description' => $config['description'],
            'columns' => $config['columns'],
            'fields' => $config['fields'],
            'items' => $items,
            'options' => $this->options(),
            'readOnly' => $config['read_only'] ?? false,
        ]);
    }

    public function datasetMappingImages(DatasetClassMapping $datasetMapping): JsonResponse
    {
        return response()->json([
            'images' => $this->datasetSampleImages($datasetMapping),
            'image_count' => $this->datasetImageCount($datasetMapping),
        ]);
    }

    public function store(Request $request, string $resource): RedirectResponse
    {
        $config = $this->resourceConfig($resource);
        abort_if($config['read_only'] ?? false, 403);

        $this->validateDatasetImages($request, $resource);

        $data = $this->validated($request, $resource, $config);
        $datasetMappingId = $this->pullDiseaseDatasetMappingId($resource, $data);
        $item = $config['model']::create($data);
        $this->syncDiseaseDatasetMapping($resource, $item, $datasetMappingId);
        $this->storeDatasetImages($request, $resource, $item);
        $this->storeMedicineImage($request, $resource, $item);

        return back()->with('success', "{$config['title']} berhasil ditambahkan.");
    }

    public function update(Request $request, string $resource, int $id): RedirectResponse
    {
        $config = $this->resourceConfig($resource);
        abort_if($config['read_only'] ?? false, 403);

        $this->validateDatasetImages($request, $resource);

        $item = $config['model']::query()->findOrFail($id);
        $data = $this->validated($request, $resource, $config, $id);
        $datasetMappingId = $this->pullDiseaseDatasetMappingId($resource, $data);
        $item->update($data);
        $this->syncDiseaseDatasetMapping($resource, $item, $datasetMappingId);
        $this->storeDatasetImages($request, $resource, $item);
        $this->storeMedicineImage($request, $resource, $item);

        return back()->with('success', "{$config['title']} berhasil diperbarui.");
    }

    public function destroy(string $resource, int $id): RedirectResponse
    {
        $config = $this->resourceConfig($resource);
        abort_if($config['read_only'] ?? false, 403);

        $config['model']::query()->findOrFail($id)->delete();

        return back()->with('success', "{$config['title']} berhasil dihapus.");
    }

    private function validated(Request $request, string $resource, array $config, ?int $id = null): array
    {
        $rules = match ($resource) {
            'diseases' => [
                'code' => ['required', 'string', 'max:80', Rule::unique('diseases', 'code')->ignore($id)],
                'name' => ['required', 'string', 'max:255'],
                'name_indonesian' => ['nullable', 'string', 'max:255'],
                'description' => ['nullable', 'string'],
                'severity_scope' => ['required', 'string', 'max:80'],
                'default_action' => ['required', 'string', 'max:80'],
                'dataset_class_mapping_id' => ['nullable', 'exists:dataset_class_mappings,id'],
                'is_active' => ['boolean'],
            ],
            'symptoms' => [
                'code' => ['required', 'string', 'max:80', Rule::unique('symptoms', 'code')->ignore($id)],
                'name' => ['required', 'string', 'max:255'],
                'question' => ['required', 'string'],
                'input_type' => ['required', 'string', 'max:80'],
                'is_red_flag_candidate' => ['boolean'],
                'is_active' => ['boolean'],
            ],
            'rules' => [
                'disease_id' => ['required', 'exists:diseases,id'],
                'symptom_id' => ['required', 'exists:symptoms,id'],
                'mb' => ['required', 'numeric', 'min:0', 'max:1'],
                'md' => ['required', 'numeric', 'min:0', 'max:1'],
                'expert_cf' => ['required', 'numeric', 'min:-1', 'max:1'],
                'is_required' => ['boolean'],
                'note' => ['nullable', 'string'],
            ],
            'medicines' => [
                'dataset_class_mapping_id' => ['nullable', 'exists:dataset_class_mappings,id'],
                'name' => ['required', 'string', 'max:255'],
                'category' => ['required', 'string', 'max:120'],
                'dosage_form' => ['nullable', 'string', 'max:120'],
                'medicine_image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
                'usage_instruction' => ['nullable', 'string'],
                'warnings' => ['nullable', 'string'],
                'source_note' => ['nullable', 'string'],
                'is_limited_otc' => ['boolean'],
                'is_active' => ['boolean'],
            ],
            'recommendations' => [
                'disease_id' => ['required', 'exists:diseases,id'],
                'medicine_id' => ['required', 'exists:medicines,id'],
                'dataset_class_mapping_id' => ['nullable', 'exists:dataset_class_mappings,id'],
                'recommendation_note' => ['nullable', 'string'],
                'priority' => ['required', 'integer', 'min:1', 'max:99'],
                'is_active' => ['boolean'],
            ],
            'red-flags' => [
                'code' => ['required', 'string', 'max:80', Rule::unique('red_flags', 'code')->ignore($id)],
                'question' => ['required', 'string'],
                'action_message' => ['required', 'string'],
                'severity' => ['required', 'string', 'max:80'],
                'is_active' => ['boolean'],
            ],
            'dataset-mappings' => [
                'dataset_class_id' => ['required', 'integer', 'min:1', Rule::unique('dataset_class_mappings', 'dataset_class_id')->ignore($id)],
                'dataset_class_name' => ['required', 'string', 'max:255', Rule::unique('dataset_class_mappings', 'dataset_class_name')->ignore($id)],
                'nama_indonesia' => ['nullable', 'string', 'max:255'],
                'scope_category' => ['required', 'string', 'max:80'],
                'boleh_rekomendasi_obat' => ['boolean'],
                'default_action' => ['required', 'string', 'max:80'],
                'disease_id' => ['nullable', 'exists:diseases,id'],
                'risk_note' => ['nullable', 'string'],
            ],
            'settings' => [
                'key' => ['required', 'string', 'max:120', Rule::unique('settings', 'key')->ignore($id)],
                'value' => ['required'],
                'group' => ['required', 'string', 'max:80'],
                'description' => ['nullable', 'string'],
            ],
            default => abort(404),
        };

        $data = $request->validate($rules);

        foreach ($config['booleans'] ?? [] as $field) {
            $data[$field] = $request->boolean($field);
        }

        if ($resource === 'settings' && ! is_array($data['value'])) {
            $data['value'] = ['value' => $data['value']];
        }

        if ($resource === 'diseases') {
            $data['slug'] = $this->uniqueDiseaseSlug($data['name'], $id);
        }

        unset($data['medicine_image'], $data['dataset_images']);

        return $data;
    }

    private function uniqueDiseaseSlug(string $name, ?int $id = null): string
    {
        $baseSlug = Str::slug($name) ?: 'penyakit';
        $slug = $baseSlug;
        $counter = 2;

        while (
            Disease::query()
                ->where('slug', $slug)
                ->when($id, fn ($query) => $query->whereKeyNot($id))
                ->exists()
        ) {
            $slug = "{$baseSlug}-{$counter}";
            $counter++;
        }

        return $slug;
    }

    private function pullDiseaseDatasetMappingId(string $resource, array &$data): ?int
    {
        if ($resource !== 'diseases') {
            return null;
        }

        $mappingId = $data['dataset_class_mapping_id'] ?? null;
        unset($data['dataset_class_mapping_id']);

        return $mappingId ? (int) $mappingId : null;
    }

    private function syncDiseaseDatasetMapping(string $resource, Model $item, ?int $mappingId): void
    {
        if ($resource !== 'diseases' || ! $item instanceof Disease || ! $mappingId) {
            return;
        }

        DatasetClassMapping::query()
            ->whereKey($mappingId)
            ->update(['disease_id' => $item->id]);
    }

    private function validateDatasetImages(Request $request, string $resource): void
    {
        if ($resource !== 'dataset-mappings') {
            return;
        }

        $request->validate([
            'dataset_images' => ['nullable', 'array', 'max:20'],
            'dataset_images.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ]);
    }

    private function storeMedicineImage(Request $request, string $resource, Model $item): void
    {
        if ($resource !== 'medicines' || ! $item instanceof Medicine || ! $request->hasFile('medicine_image')) {
            return;
        }

        $path = $request->file('medicine_image')->store('medicine-images', 'public');
        $item->update(['image_path' => $path]);
    }

    private function storeDatasetImages(Request $request, string $resource, Model $item): void
    {
        if ($resource !== 'dataset-mappings' || ! $item instanceof DatasetClassMapping) {
            return;
        }

        $files = $request->file('dataset_images', []);

        if (! is_array($files) || $files === []) {
            return;
        }

        $directory = base_path('datasets/sd-198/images/'.$item->dataset_class_name);

        if (! is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        collect($files)
            ->filter(fn ($file): bool => $file instanceof UploadedFile)
            ->each(function (UploadedFile $file, int $index) use ($directory, $item): void {
                $extension = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: 'jpg');
                $baseName = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
                $safeBaseName = Str::slug($baseName) ?: 'dataset-image';
                $fileName = sprintf(
                    '%s-%s-%02d.%s',
                    Str::slug($item->dataset_class_name),
                    $safeBaseName,
                    $index + 1,
                    $extension
                );

                $file->move($directory, $this->uniqueDatasetFileName($directory, $fileName));
            });
    }

    private function uniqueDatasetFileName(string $directory, string $fileName): string
    {
        $extension = pathinfo($fileName, PATHINFO_EXTENSION);
        $baseName = pathinfo($fileName, PATHINFO_FILENAME);
        $candidate = $fileName;
        $counter = 2;

        while (file_exists($directory.DIRECTORY_SEPARATOR.$candidate)) {
            $candidate = "{$baseName}-{$counter}.{$extension}";
            $counter++;
        }

        return $candidate;
    }

    private function serializeItem(Model $item, array $config): array
    {
        $data = $item->toArray();

        if ($item instanceof DiseaseSymptomRule) {
            $data['disease_name'] = $item->disease?->name;
            $data['symptom_name'] = $item->symptom?->name;
        }

        if ($item instanceof DiseaseMedicineRecommendation) {
            $data['disease_name'] = $item->disease?->name;
            $data['medicine_name'] = $item->medicine?->name;
            $mapping = $item->datasetClassMapping ?: $item->disease?->datasetMappings?->first() ?: $item->medicine?->datasetClassMapping;
            $data['dataset_name'] = $mapping?->dataset_class_name;
            $data['detail'] = [
                'title' => $item->medicine?->name,
                'dataset' => $this->datasetSnapshot($mapping),
                'disease_name' => $item->disease?->name_indonesian ?: $item->disease?->name,
                'medicine_name' => $item->medicine?->name,
                'medicine_image_url' => $item->medicine?->image_path ? '/storage/'.$item->medicine->image_path : null,
                'recommendation_note' => $item->recommendation_note,
                'priority' => $item->priority,
                'is_active' => (bool) $item->is_active,
            ];
        }

        if ($item instanceof DatasetClassMapping) {
            $data['disease_name'] = $item->disease?->name;
            $data['detail'] = [
                'id' => $item->id,
                'dataset_class_id' => $item->dataset_class_id,
                'dataset_class_name' => $item->dataset_class_name,
                'nama_indonesia' => $item->nama_indonesia,
                'scope_category' => $item->scope_category,
                'boleh_rekomendasi_obat' => (bool) $item->boleh_rekomendasi_obat,
                'default_action' => $item->default_action,
                'disease_name' => $item->disease?->name_indonesian ?: $item->disease?->name,
                'risk_note' => $item->risk_note,
                'image_count' => $this->datasetImageCount($item),
            ];
        }

        if ($item instanceof Consultation) {
            $data['user_name'] = $item->user?->name;
            $data['final_score'] = $item->final_score;
            $finalResult = $item->finalResults->sortByDesc('fusion_score')->first();

            $data['uploaded_image_url'] = $item->image_path ? '/storage/'.$item->image_path : null;
            $data['result_url'] = route('consultation.result', $item->session_code);
            $data['export_url'] = route('consultation.export', $item->session_code);
            $finalDataset = $finalResult?->disease?->datasetMappings?->first();
            $data['dataset_name'] = $finalDataset?->dataset_class_name;
            $data['detail'] = [
                'complaint_text' => $item->complaint_text,
                'complaint_summary' => $item->complaint_features['summary'] ?? [],
                'final_result' => $finalResult ? [
                    'disease_name' => $finalResult->disease?->name_indonesian ?: $finalResult->disease?->name,
                    'dataset' => $this->datasetSnapshot($finalDataset),
                    'textual_cf' => (float) $finalResult->textual_cf,
                    'visual_score' => (float) $finalResult->visual_score,
                    'fusion_score' => (float) $finalResult->fusion_score,
                    'action' => $finalResult->action,
                    'explanation' => $finalResult->explanation,
                    'recommendations' => $finalResult->recommendations_snapshot ?? [],
                ] : null,
                'symptoms' => $item->symptoms
                    ->filter(fn ($symptom): bool => (bool) $symptom->selected)
                    ->map(fn ($symptom): array => [
                        'name' => $symptom->symptom?->name,
                        'question' => $symptom->symptom?->question,
                        'user_cf' => (float) $symptom->user_cf,
                    ])
                    ->values()
                    ->all(),
                'red_flags' => $item->redFlags
                    ->filter(fn ($redFlag): bool => (bool) $redFlag->detected)
                    ->map(fn ($redFlag): array => [
                        'question' => $redFlag->redFlag?->question,
                        'severity' => $redFlag->redFlag?->severity,
                    ])
                    ->values()
                    ->all(),
                'visual_results' => $item->visualResults
                    ->sortByDesc('visual_score')
                    ->map(fn ($visualResult): array => [
                        'disease_name' => $visualResult->disease?->name_indonesian ?: $visualResult->disease?->name,
                        'visual_score' => (float) $visualResult->visual_score,
                        'visual_reason' => $visualResult->visual_reason,
                        'provider' => $visualResult->provider,
                    ])
                    ->values()
                    ->all(),
            ];
        }

        if ($item instanceof Disease) {
            $mapping = $item->datasetMappings->first();
            $data['dataset_name'] = $mapping?->dataset_class_name;
            $data['dataset_class_mapping_id'] = $mapping?->id;
            $data['detail'] = [
                'title' => $item->name_indonesian ?: $item->name,
                'dataset' => $this->datasetSnapshot($mapping),
                'description' => $item->description,
                'default_action' => $item->default_action,
                'severity_scope' => $item->severity_scope,
                'is_active' => (bool) $item->is_active,
            ];
        }

        if ($item instanceof Medicine) {
            $mapping = $item->datasetClassMapping ?: $item->diseaseRecommendations->first()?->disease?->datasetMappings?->first();
            $data['dataset_name'] = $mapping?->dataset_class_name;
            $data['image_url'] = $item->image_path ? '/storage/'.$item->image_path : null;
            $data['detail'] = [
                'title' => $item->name,
                'dataset' => $this->datasetSnapshot($mapping),
                'image_url' => $data['image_url'],
                'category' => $item->category,
                'dosage_form' => $item->dosage_form,
                'usage_instruction' => $item->usage_instruction,
                'warnings' => $item->warnings,
                'source_note' => $item->source_note,
                'is_limited_otc' => (bool) $item->is_limited_otc,
                'is_active' => (bool) $item->is_active,
            ];
        }

        $fieldNames = collect($config['fields'])
            ->pluck('name')
            ->all();

        return array_intersect_key($data, array_flip([
            'id',
            'detail',
            'uploaded_image_url',
            'result_url',
            'export_url',
            ...$config['columns'],
            ...$fieldNames,
        ]));
    }

    private function options(): array
    {
        return [
            'diseases' => Disease::query()->orderBy('name')->get(['id', 'name']),
            'symptoms' => Symptom::query()->orderBy('name')->get(['id', 'name']),
            'medicines' => Medicine::query()->orderBy('name')->get(['id', 'name']),
            'datasetMappings' => DatasetClassMapping::query()
                ->orderBy('dataset_class_id')
                ->get(['id', 'dataset_class_name as name']),
            'scopeCategories' => ['swamedikasi', 'edukasi', 'rujuk', 'exclude'],
            'actions' => ['recommend_otc', 'educate_only', 'refer'],
            'severityScopes' => ['mild', 'moderate', 'danger', 'excluded'],
            'inputTypes' => ['scale', 'boolean', 'choice', 'duration'],
        ];
    }

    /**
     * @return array<int, array{class_name: string, file_name: string, url: string}>
     */
    private function datasetImages(DatasetClassMapping $mapping): array
    {
        $className = $mapping->dataset_class_name;
        $directory = base_path('datasets/sd-198/images/'.$className);

        if (! is_dir($directory)) {
            return [];
        }

        return collect(scandir($directory) ?: [])
            ->filter(fn (string $file): bool => $this->isReadableDatasetImage($directory.DIRECTORY_SEPARATOR.$file))
            ->sort(SORT_NATURAL | SORT_FLAG_CASE)
            ->map(fn (string $file): array => [
                'class_name' => $className,
                'file_name' => $file,
                'url' => route('dataset.example-image', [$className, $file]),
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array{class_name: string, file_name: string, url: string}>
     */
    private function datasetSampleImages(DatasetClassMapping $mapping): array
    {
        $className = $mapping->dataset_class_name;
        $directory = base_path('datasets/sd-198/images/'.$className);

        if (! is_dir($directory)) {
            return [];
        }

        $images = [];
        $files = collect(scandir($directory) ?: [])
            ->sort(SORT_NATURAL | SORT_FLAG_CASE);

        foreach ($files as $file) {
            if (! $this->isReadableDatasetImage($directory.DIRECTORY_SEPARATOR.$file)) {
                continue;
            }

            $images[] = [
                'class_name' => $className,
                'file_name' => $file,
                'url' => route('dataset.example-image', [$className, $file]),
            ];

            if (count($images) >= 6) {
                break;
            }
        }

        return $images;
    }

    private function datasetImageCount(DatasetClassMapping $mapping): int
    {
        $directory = base_path('datasets/sd-198/images/'.$mapping->dataset_class_name);

        if (! is_dir($directory)) {
            return 0;
        }

        return collect(scandir($directory) ?: [])
            ->filter(fn (string $file): bool => is_file($directory.DIRECTORY_SEPARATOR.$file) && preg_match('/\.(jpg|jpeg|png|webp)$/i', $file))
            ->count();
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

    private function datasetSnapshot(?DatasetClassMapping $mapping): ?array
    {
        if (! $mapping) {
            return null;
        }

        return [
            'id' => $mapping->id,
            'dataset_class_id' => $mapping->dataset_class_id,
            'dataset_class_name' => $mapping->dataset_class_name,
            'nama_indonesia' => $mapping->nama_indonesia,
            'scope_category' => $mapping->scope_category,
            'boleh_rekomendasi_obat' => (bool) $mapping->boleh_rekomendasi_obat,
            'default_action' => $mapping->default_action,
            'disease_name' => $mapping->disease?->name_indonesian ?: $mapping->disease?->name,
            'risk_note' => $mapping->risk_note,
            'sample_images' => $this->datasetSampleImages($mapping),
            'image_count' => $this->datasetImageCount($mapping),
        ];
    }

    private function resourceConfig(string $resource): array
    {
        $configs = [
            'diseases' => [
                'model' => Disease::class,
                'title' => 'Penyakit',
                'description' => 'Master penyakit lokal yang dipakai mesin keputusan.',
                'with' => ['datasetMappings.disease'],
                'columns' => ['code', 'name', 'name_indonesian', 'dataset_name', 'severity_scope', 'default_action', 'is_active'],
                'fields' => [
                    ['name' => 'code', 'label' => 'Kode penyakit', 'help' => 'Kode internal singkat, misalnya TINEA_CORPORIS.'],
                    ['name' => 'name', 'label' => 'Nama penyakit'],
                    ['name' => 'name_indonesian', 'label' => 'Nama Indonesia'],
                    ['name' => 'description', 'label' => 'Deskripsi', 'type' => 'textarea'],
                    ['name' => 'severity_scope', 'label' => 'Tingkat risiko', 'type' => 'select', 'options' => 'severityScopes'],
                    ['name' => 'default_action', 'label' => 'Arahan default', 'type' => 'select', 'options' => 'actions'],
                    ['name' => 'dataset_class_mapping_id', 'label' => 'Relevan ke dataset', 'type' => 'select', 'options' => 'datasetMappings', 'nullable' => true, 'help' => 'Pilih class SD-198 yang menjadi dasar penyakit ini.'],
                    ['name' => 'is_active', 'label' => 'Aktif', 'type' => 'checkbox'],
                ],
                'booleans' => ['is_active'],
            ],
            'symptoms' => [
                'model' => Symptom::class,
                'title' => 'Gejala',
                'description' => 'Pertanyaan anamnesis dan gejala yang dihitung CF.',
                'columns' => ['code', 'name', 'input_type', 'is_red_flag_candidate', 'is_active'],
                'fields' => [
                    ['name' => 'code', 'label' => 'Kode gejala', 'help' => 'Kode internal untuk rule, misalnya ITCHING.'],
                    ['name' => 'name', 'label' => 'Nama gejala'],
                    ['name' => 'question', 'label' => 'Pertanyaan ke user', 'type' => 'textarea'],
                    ['name' => 'input_type', 'label' => 'Jenis jawaban', 'type' => 'select', 'options' => 'inputTypes'],
                    ['name' => 'is_red_flag_candidate', 'label' => 'Kandidat Red Flag', 'type' => 'checkbox'],
                    ['name' => 'is_active', 'label' => 'Aktif', 'type' => 'checkbox'],
                ],
                'booleans' => ['is_red_flag_candidate', 'is_active'],
            ],
            'rules' => [
                'model' => DiseaseSymptomRule::class,
                'title' => 'Rule CF',
                'description' => 'Bobot MB/MD pakar untuk relasi penyakit dan gejala.',
                'with' => ['disease', 'symptom'],
                'columns' => ['disease_name', 'symptom_name', 'mb', 'md', 'expert_cf', 'is_required'],
                'fields' => [
                    ['name' => 'disease_id', 'label' => 'Penyakit', 'type' => 'select', 'options' => 'diseases'],
                    ['name' => 'symptom_id', 'label' => 'Gejala', 'type' => 'select', 'options' => 'symptoms'],
                    ['name' => 'mb', 'label' => 'Bobot mendukung (MB)', 'type' => 'number', 'help' => 'Seberapa kuat gejala mendukung penyakit, nilai 0 sampai 1.'],
                    ['name' => 'md', 'label' => 'Bobot melemahkan (MD)', 'type' => 'number', 'help' => 'Seberapa kuat gejala melemahkan penyakit, nilai 0 sampai 1.'],
                    ['name' => 'expert_cf', 'label' => 'Nilai akhir pakar (CF)', 'type' => 'number', 'help' => 'Biasanya MB dikurangi MD.'],
                    ['name' => 'is_required', 'label' => 'Gejala wajib', 'type' => 'checkbox'],
                    ['name' => 'note', 'label' => 'Catatan', 'type' => 'textarea'],
                ],
                'booleans' => ['is_required'],
            ],
            'medicines' => [
                'model' => Medicine::class,
                'title' => 'Obat',
                'description' => 'Master obat/edukasi swamedikasi yang sudah dibatasi untuk OBT.',
                'with' => ['datasetClassMapping.disease', 'diseaseRecommendations.disease.datasetMappings'],
                'columns' => ['image_url', 'name', 'dataset_name', 'category', 'dosage_form', 'is_limited_otc', 'is_active'],
                'fields' => [
                    ['name' => 'dataset_class_mapping_id', 'label' => 'Relevan ke dataset', 'type' => 'select', 'options' => 'datasetMappings', 'nullable' => true, 'help' => 'Pilih class SD-198 yang menjadi dasar obat/edukasi ini.'],
                    ['name' => 'name', 'label' => 'Nama obat/edukasi'],
                    ['name' => 'category', 'label' => 'Kategori'],
                    ['name' => 'dosage_form', 'label' => 'Bentuk sediaan'],
                    ['name' => 'medicine_image', 'label' => 'Gambar obat', 'type' => 'file', 'help' => 'Opsional. Upload gambar sediaan/ilustrasi obat agar admin mudah mengenali.'],
                    ['name' => 'usage_instruction', 'label' => 'Aturan Pakai', 'type' => 'textarea'],
                    ['name' => 'warnings', 'label' => 'Peringatan', 'type' => 'textarea'],
                    ['name' => 'source_note', 'label' => 'Sumber', 'type' => 'textarea'],
                    ['name' => 'is_limited_otc', 'label' => 'OBT/Bebas Terbatas', 'type' => 'checkbox'],
                    ['name' => 'is_active', 'label' => 'Aktif', 'type' => 'checkbox'],
                ],
                'booleans' => ['is_limited_otc', 'is_active'],
            ],
            'recommendations' => [
                'model' => DiseaseMedicineRecommendation::class,
                'title' => 'Rekomendasi Obat',
                'description' => 'Mapping penyakit ke obat atau edukasi yang boleh muncul jika aman.',
                'with' => ['disease.datasetMappings', 'medicine.datasetClassMapping', 'datasetClassMapping.disease'],
                'columns' => ['disease_name', 'medicine_name', 'dataset_name', 'priority', 'is_active'],
                'fields' => [
                    ['name' => 'disease_id', 'label' => 'Penyakit', 'type' => 'select', 'options' => 'diseases'],
                    ['name' => 'medicine_id', 'label' => 'Obat', 'type' => 'select', 'options' => 'medicines'],
                    ['name' => 'dataset_class_mapping_id', 'label' => 'Relevan ke dataset', 'type' => 'select', 'options' => 'datasetMappings', 'nullable' => true, 'help' => 'Pilih class dataset yang menjadi dasar rekomendasi ini. Jika kosong, sistem memakai dataset dari penyakit/obat.'],
                    ['name' => 'recommendation_note', 'label' => 'Catatan rekomendasi', 'type' => 'textarea'],
                    ['name' => 'priority', 'label' => 'Urutan tampil', 'type' => 'number'],
                    ['name' => 'is_active', 'label' => 'Aktif', 'type' => 'checkbox'],
                ],
                'booleans' => ['is_active'],
            ],
            'red-flags' => [
                'model' => RedFlag::class,
                'title' => 'Red Flags',
                'description' => 'Kondisi bahaya yang otomatis memblokir rekomendasi obat.',
                'columns' => ['code', 'question', 'severity', 'is_active'],
                'fields' => [
                    ['name' => 'code', 'label' => 'Kode red flag'],
                    ['name' => 'question', 'label' => 'Pertanyaan tanda bahaya', 'type' => 'textarea'],
                    ['name' => 'action_message', 'label' => 'Pesan arahan user', 'type' => 'textarea'],
                    ['name' => 'severity', 'label' => 'Tingkat arahan'],
                    ['name' => 'is_active', 'label' => 'Aktif', 'type' => 'checkbox'],
                ],
                'booleans' => ['is_active'],
            ],
            'dataset-mappings' => [
                'model' => DatasetClassMapping::class,
                'title' => 'Dataset Mapping',
                'description' => 'Mapping class SD-198 ke scope sistem dan penyakit lokal.',
                'with' => ['disease'],
                'columns' => ['dataset_class_id', 'dataset_class_name', 'nama_indonesia', 'scope_category', 'disease_name'],
                'fields' => [
                    ['name' => 'dataset_class_id', 'label' => 'Nomor class SD-198', 'type' => 'number', 'help' => 'Nomor class dari file label dataset.'],
                    ['name' => 'dataset_class_name', 'label' => 'Nama folder/class SD-198', 'help' => 'Harus sama persis dengan nama folder gambar di datasets/sd-198/images.'],
                    ['name' => 'nama_indonesia', 'label' => 'Nama Indonesia'],
                    ['name' => 'scope_category', 'label' => 'Kategori penggunaan', 'type' => 'select', 'options' => 'scopeCategories'],
                    ['name' => 'boleh_rekomendasi_obat', 'label' => 'Boleh tampilkan rekomendasi obat', 'type' => 'checkbox'],
                    ['name' => 'default_action', 'label' => 'Arahan default', 'type' => 'select', 'options' => 'actions'],
                    ['name' => 'disease_id', 'label' => 'Hubungkan ke penyakit lokal', 'type' => 'select', 'options' => 'diseases', 'nullable' => true],
                    ['name' => 'risk_note', 'label' => 'Catatan risiko', 'type' => 'textarea'],
                    ['name' => 'dataset_images', 'label' => 'Upload gambar class ini', 'type' => 'file', 'multiple' => true, 'help' => 'Opsional. Pilih beberapa gambar JPG/PNG/WEBP. File akan disimpan ke folder class SD-198 yang sesuai.'],
                ],
                'booleans' => ['boleh_rekomendasi_obat'],
            ],
            'settings' => [
                'model' => Setting::class,
                'title' => 'Pengaturan',
                'description' => 'Threshold, bobot fusion, dan konfigurasi sistem.',
                'columns' => ['key', 'value', 'group', 'description'],
                'fields' => [
                    ['name' => 'key', 'label' => 'Key'],
                    ['name' => 'value', 'label' => 'Value'],
                    ['name' => 'group', 'label' => 'Group'],
                    ['name' => 'description', 'label' => 'Deskripsi', 'type' => 'textarea'],
                ],
            ],
            'consultations' => [
                'model' => Consultation::class,
                'title' => 'Riwayat Konsultasi',
                'description' => 'Audit konsultasi pengguna dan hasil final.',
                'read_only' => true,
                'with' => [
                    'user',
                    'symptoms.symptom',
                    'redFlags.redFlag',
                    'visualResults.disease',
                    'finalResults.disease.datasetMappings.disease',
                ],
                'columns' => ['session_code', 'visitor_name', 'dataset_name', 'status', 'final_score', 'final_action', 'created_at'],
                'fields' => [],
            ],
        ];

        abort_unless(isset($configs[$resource]), 404);

        return $configs[$resource];
    }
}
