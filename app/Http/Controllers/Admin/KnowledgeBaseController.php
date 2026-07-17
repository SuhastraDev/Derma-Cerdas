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
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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

    public function store(Request $request, string $resource): RedirectResponse
    {
        $config = $this->resourceConfig($resource);
        abort_if($config['read_only'] ?? false, 403);

        $config['model']::create($this->validated($request, $resource, $config));

        return back()->with('success', "{$config['title']} berhasil ditambahkan.");
    }

    public function update(Request $request, string $resource, int $id): RedirectResponse
    {
        $config = $this->resourceConfig($resource);
        abort_if($config['read_only'] ?? false, 403);

        $item = $config['model']::query()->findOrFail($id);
        $item->update($this->validated($request, $resource, $config, $id));

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
                'slug' => ['required', 'string', 'max:255', Rule::unique('diseases', 'slug')->ignore($id)],
                'name_indonesian' => ['nullable', 'string', 'max:255'],
                'description' => ['nullable', 'string'],
                'severity_scope' => ['required', 'string', 'max:80'],
                'default_action' => ['required', 'string', 'max:80'],
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
                'name' => ['required', 'string', 'max:255'],
                'category' => ['required', 'string', 'max:120'],
                'dosage_form' => ['nullable', 'string', 'max:120'],
                'usage_instruction' => ['nullable', 'string'],
                'warnings' => ['nullable', 'string'],
                'source_note' => ['nullable', 'string'],
                'is_limited_otc' => ['boolean'],
                'is_active' => ['boolean'],
            ],
            'recommendations' => [
                'disease_id' => ['required', 'exists:diseases,id'],
                'medicine_id' => ['required', 'exists:medicines,id'],
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

        return $data;
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
        }

        if ($item instanceof DatasetClassMapping) {
            $data['disease_name'] = $item->disease?->name;
        }

        if ($item instanceof Consultation) {
            $data['user_name'] = $item->user?->name;
            $data['final_score'] = $item->final_score;
        }

        $fieldNames = collect($config['fields'])
            ->pluck('name')
            ->all();

        return array_intersect_key($data, array_flip(['id', ...$config['columns'], ...$fieldNames]));
    }

    private function options(): array
    {
        return [
            'diseases' => Disease::query()->orderBy('name')->get(['id', 'name']),
            'symptoms' => Symptom::query()->orderBy('name')->get(['id', 'name']),
            'medicines' => Medicine::query()->orderBy('name')->get(['id', 'name']),
            'scopeCategories' => ['swamedikasi', 'edukasi', 'rujuk', 'exclude'],
            'actions' => ['recommend_otc', 'educate_only', 'refer'],
            'severityScopes' => ['mild', 'moderate', 'danger', 'excluded'],
            'inputTypes' => ['scale', 'boolean', 'choice', 'duration'],
        ];
    }

    private function resourceConfig(string $resource): array
    {
        $configs = [
            'diseases' => [
                'model' => Disease::class,
                'title' => 'Penyakit',
                'description' => 'Master penyakit lokal yang dipakai mesin keputusan.',
                'columns' => ['code', 'name', 'name_indonesian', 'severity_scope', 'default_action', 'is_active'],
                'fields' => [
                    ['name' => 'code', 'label' => 'Kode'],
                    ['name' => 'name', 'label' => 'Nama'],
                    ['name' => 'slug', 'label' => 'Slug'],
                    ['name' => 'name_indonesian', 'label' => 'Nama Indonesia'],
                    ['name' => 'description', 'label' => 'Deskripsi', 'type' => 'textarea'],
                    ['name' => 'severity_scope', 'label' => 'Severity', 'type' => 'select', 'options' => 'severityScopes'],
                    ['name' => 'default_action', 'label' => 'Aksi Default', 'type' => 'select', 'options' => 'actions'],
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
                    ['name' => 'code', 'label' => 'Kode'],
                    ['name' => 'name', 'label' => 'Nama'],
                    ['name' => 'question', 'label' => 'Pertanyaan', 'type' => 'textarea'],
                    ['name' => 'input_type', 'label' => 'Input', 'type' => 'select', 'options' => 'inputTypes'],
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
                    ['name' => 'mb', 'label' => 'MB', 'type' => 'number'],
                    ['name' => 'md', 'label' => 'MD', 'type' => 'number'],
                    ['name' => 'expert_cf', 'label' => 'Expert CF', 'type' => 'number'],
                    ['name' => 'is_required', 'label' => 'Wajib', 'type' => 'checkbox'],
                    ['name' => 'note', 'label' => 'Catatan', 'type' => 'textarea'],
                ],
                'booleans' => ['is_required'],
            ],
            'medicines' => [
                'model' => Medicine::class,
                'title' => 'Obat',
                'description' => 'Master obat/edukasi swamedikasi yang sudah dibatasi untuk OBT.',
                'columns' => ['name', 'category', 'dosage_form', 'is_limited_otc', 'is_active'],
                'fields' => [
                    ['name' => 'name', 'label' => 'Nama'],
                    ['name' => 'category', 'label' => 'Kategori'],
                    ['name' => 'dosage_form', 'label' => 'Sediaan'],
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
                'with' => ['disease', 'medicine'],
                'columns' => ['disease_name', 'medicine_name', 'priority', 'is_active'],
                'fields' => [
                    ['name' => 'disease_id', 'label' => 'Penyakit', 'type' => 'select', 'options' => 'diseases'],
                    ['name' => 'medicine_id', 'label' => 'Obat', 'type' => 'select', 'options' => 'medicines'],
                    ['name' => 'recommendation_note', 'label' => 'Catatan', 'type' => 'textarea'],
                    ['name' => 'priority', 'label' => 'Prioritas', 'type' => 'number'],
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
                    ['name' => 'code', 'label' => 'Kode'],
                    ['name' => 'question', 'label' => 'Pertanyaan', 'type' => 'textarea'],
                    ['name' => 'action_message', 'label' => 'Pesan Aksi', 'type' => 'textarea'],
                    ['name' => 'severity', 'label' => 'Severity'],
                    ['name' => 'is_active', 'label' => 'Aktif', 'type' => 'checkbox'],
                ],
                'booleans' => ['is_active'],
            ],
            'dataset-mappings' => [
                'model' => DatasetClassMapping::class,
                'title' => 'Dataset Mapping',
                'description' => 'Mapping class SD-198 ke scope sistem dan penyakit lokal.',
                'with' => ['disease'],
                'columns' => ['dataset_class_id', 'dataset_class_name', 'scope_category', 'default_action', 'disease_name'],
                'fields' => [
                    ['name' => 'dataset_class_id', 'label' => 'ID Class', 'type' => 'number'],
                    ['name' => 'dataset_class_name', 'label' => 'Class SD-198'],
                    ['name' => 'nama_indonesia', 'label' => 'Nama Indonesia'],
                    ['name' => 'scope_category', 'label' => 'Scope', 'type' => 'select', 'options' => 'scopeCategories'],
                    ['name' => 'boleh_rekomendasi_obat', 'label' => 'Boleh Rekomendasi', 'type' => 'checkbox'],
                    ['name' => 'default_action', 'label' => 'Aksi Default', 'type' => 'select', 'options' => 'actions'],
                    ['name' => 'disease_id', 'label' => 'Penyakit Lokal', 'type' => 'select', 'options' => 'diseases', 'nullable' => true],
                    ['name' => 'risk_note', 'label' => 'Catatan Risiko', 'type' => 'textarea'],
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
                'with' => ['user'],
                'columns' => ['session_code', 'user_name', 'status', 'final_score', 'final_action', 'created_at'],
                'fields' => [],
            ],
        ];

        abort_unless(isset($configs[$resource]), 404);

        return $configs[$resource];
    }
}
