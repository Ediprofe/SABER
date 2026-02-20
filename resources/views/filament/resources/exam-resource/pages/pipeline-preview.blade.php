@php
    $catalog = $this->preview['classification_catalog'] ?? [];
    $suggestions = $this->preview['tag_suggestions'] ?? [];

    if (! is_array($catalog) || $catalog === []) {
        $catalog = [
            'lectura' => ['label' => 'Lectura Critica', 'types' => [['key' => 'area', 'label' => 'Area'], ['key' => 'competencia', 'label' => 'Competencia'], ['key' => 'componente', 'label' => 'Componente'], ['key' => 'tipo_texto', 'label' => 'Tipo de Texto'], ['key' => 'nivel_lectura', 'label' => 'Nivel de Lectura']]],
            'matematicas' => ['label' => 'Matematicas', 'types' => [['key' => 'area', 'label' => 'Area'], ['key' => 'competencia', 'label' => 'Competencia'], ['key' => 'componente', 'label' => 'Componente']]],
            'sociales' => ['label' => 'Ciencias Sociales', 'types' => [['key' => 'area', 'label' => 'Area'], ['key' => 'competencia', 'label' => 'Competencia'], ['key' => 'componente', 'label' => 'Componente']]],
            'naturales' => ['label' => 'Ciencias Naturales', 'types' => [['key' => 'area', 'label' => 'Area'], ['key' => 'competencia', 'label' => 'Competencia'], ['key' => 'componente', 'label' => 'Componente']]],
            'ingles' => ['label' => 'Ingles', 'types' => [['key' => 'area', 'label' => 'Area'], ['key' => 'parte', 'label' => 'Parte'], ['key' => 'competencia', 'label' => 'Competencia']]],
            '__unclassified' => ['label' => 'Sin area (revisar)', 'types' => [['key' => 'componente', 'label' => 'Componente']]],
        ];
    }

    if (! is_array($suggestions) || $suggestions === []) {
        $suggestions = collect($this->preview['detected_tags'] ?? [])
            ->map(fn ($tag) => [
                'tag' => (string) $tag,
                'suggested_area' => '__unclassified',
                'suggested_type' => 'componente',
                'source' => 'fallback',
            ])
            ->values()
            ->all();
    }
@endphp

<x-filament-panels::page>
    <div class="space-y-6">
        <x-filament::section>
            <div class="grid grid-cols-1 gap-4 md:grid-cols-4">
                <div class="rounded-lg border p-4">
                    <p class="text-sm text-gray-500">Preguntas (Blueprint)</p>
                    <p class="mt-1 text-xl font-semibold">{{ $this->preview['question_count_blueprint'] ?? 0 }}</p>
                </div>
                <div class="rounded-lg border p-4">
                    <p class="text-sm text-gray-500">Preguntas (Respuestas)</p>
                    <p class="mt-1 text-xl font-semibold">{{ $this->preview['question_count_responses'] ?? 0 }}</p>
                </div>
                <div class="rounded-lg border p-4">
                    <p class="text-sm text-gray-500">Estudiantes vinculados</p>
                    <p class="mt-1 text-xl font-semibold text-success-600">{{ $this->preview['students_matched'] ?? 0 }}</p>
                </div>
                <div class="rounded-lg border p-4">
                    <p class="text-sm text-gray-500">Estudiantes sin vínculo</p>
                    <p class="mt-1 text-xl font-semibold text-danger-600">{{ $this->preview['students_unmatched'] ?? 0 }}</p>
                </div>
            </div>
        </x-filament::section>

        <x-filament::section>
            <h3 class="text-lg font-semibold">Conteo de Preguntas por Area</h3>
            @if (empty($this->preview['area_question_counts']))
                <p class="mt-2 text-sm text-gray-500">No se detectaron areas desde los tags del blueprint.</p>
            @else
                <div class="mt-3 overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 text-sm dark:divide-gray-700">
                        <thead>
                            <tr>
                                <th class="px-3 py-2 text-left font-medium">Area</th>
                                <th class="px-3 py-2 text-left font-medium">Preguntas</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                            @foreach (($this->preview['area_question_counts'] ?? []) as $area => $count)
                                <tr>
                                    <td class="px-3 py-2">{{ $area }}</td>
                                    <td class="px-3 py-2">{{ $count }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-filament::section>

        <x-filament::section>
            <h3 class="text-lg font-semibold">Clasifica Tags por Area y Dimension</h3>
            <p class="mt-1 text-sm text-gray-500">
                Arrastra cada tag a la columna correcta. El sistema usa esta clasificacion para crear/actualizar
                jerarquia de tags y calcular dimensiones por area.
            </p>

            <form
                method="POST"
                action="{{ $this->getImportUrl() }}"
                class="mt-4 space-y-5"
                x-data="tagClassifier(@js($suggestions), @js($catalog))"
            >
                @csrf
                <input type="hidden" name="classification_json" :value="serializedClassifications()">
                <input type="hidden" name="save_normalizations" :value="saveNormalizations ? '1' : '0'">

                <label class="inline-flex items-center gap-2 text-sm text-gray-700 dark:text-gray-200">
                    <input type="checkbox" class="rounded border-gray-300" x-model="saveNormalizations">
                    Guardar esta clasificacion para futuras importaciones
                </label>

                <div class="space-y-4">
                    <template x-for="areaKey in areaKeys" :key="areaKey">
                        <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
                            <div class="mb-3 flex items-center justify-between">
                                <h4 class="text-sm font-semibold" x-text="catalog[areaKey].label"></h4>
                                <span class="text-xs text-gray-500">
                                    <span x-text="countArea(areaKey)"></span> tags
                                </span>
                            </div>

                            <div class="grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-4">
                                <template x-for="typeItem in catalog[areaKey].types" :key="`${areaKey}:${typeItem.key}`">
                                    <div
                                        class="rounded-lg border border-dashed border-gray-300 bg-gray-50/70 p-3 dark:border-gray-600 dark:bg-gray-800/60"
                                        @dragover.prevent
                                        @drop.prevent="dropOnLane(areaKey, typeItem.key)"
                                    >
                                        <div class="mb-2 flex items-center justify-between">
                                            <span class="text-xs font-semibold text-gray-700 dark:text-gray-200" x-text="typeItem.label"></span>
                                            <span class="text-[11px] text-gray-500" x-text="laneTags(areaKey, typeItem.key).length"></span>
                                        </div>

                                        <div class="min-h-12 space-y-2">
                                            <template x-for="tag in laneTags(areaKey, typeItem.key)" :key="`${areaKey}:${typeItem.key}:${tag}`">
                                                <button
                                                    type="button"
                                                    draggable="true"
                                                    @dragstart="startDrag(tag)"
                                                    class="w-full cursor-move rounded-md border border-gray-300 bg-white px-2 py-1 text-left text-xs text-gray-800 shadow-sm transition hover:border-primary-400 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100"
                                                    :class="{ 'ring-2 ring-primary-500': draggingTag === tag }"
                                                    :title="`Arrastra ${tag}`"
                                                >
                                                    <span x-text="tag"></span>
                                                </button>
                                            </template>
                                        </div>
                                    </div>
                                </template>
                            </div>
                        </div>
                    </template>
                </div>

                <div class="flex flex-wrap gap-3">
                    <x-filament::button color="success" icon="heroicon-o-check-circle" type="submit">
                        Confirmar e Importar Sesion
                    </x-filament::button>

                    <x-filament::button tag="a" color="gray" icon="heroicon-o-arrow-left" href="{{ $this->getUploadUrl() }}">
                        Volver a Carga
                    </x-filament::button>

                    <x-filament::button tag="a" color="primary" icon="heroicon-o-queue-list" href="{{ $this->getPipelineUrl() }}">
                        Ir al Pipeline
                    </x-filament::button>
                </div>
            </form>
        </x-filament::section>

        <x-filament::section>
            <h3 class="text-lg font-semibold">Validaciones</h3>

            @if (! empty($this->preview['missing_questions_in_blueprint']))
                <p class="mt-2 text-sm text-warning-700">
                    Preguntas en respuestas sin blueprint: {{ implode(', ', $this->preview['missing_questions_in_blueprint']) }}
                </p>
            @endif

            @if (! empty($this->preview['missing_questions_in_responses']))
                <p class="mt-2 text-sm text-warning-700">
                    Preguntas en blueprint sin respuestas: {{ implode(', ', $this->preview['missing_questions_in_responses']) }}
                </p>
            @endif

            @if (empty($this->preview['missing_questions_in_blueprint']) && empty($this->preview['missing_questions_in_responses']))
                <p class="mt-2 text-sm text-success-700">La numeracion de preguntas coincide entre ambos archivos.</p>
            @endif
        </x-filament::section>
    </div>

    <script>
        function tagClassifier(suggestions, catalog) {
            const safeSuggestions = Array.isArray(suggestions) ? suggestions : [];
            const safeCatalog = typeof catalog === 'object' && catalog !== null ? catalog : {};
            const tags = safeSuggestions
                .map((item) => String(item.tag || '').trim())
                .filter((tag) => tag !== '');

            const assignments = {};
            for (const item of safeSuggestions) {
                const tag = String(item.tag || '').trim();
                if (!tag) {
                    continue;
                }

                const suggestedArea = String(item.suggested_area || '__unclassified');
                const area = safeCatalog[suggestedArea] ? suggestedArea : '__unclassified';

                const allowedTypes = (safeCatalog[area]?.types || []).map((typeItem) => typeItem.key);
                let type = String(item.suggested_type || '');
                if (!allowedTypes.includes(type)) {
                    type = String(safeCatalog[area]?.default_type || allowedTypes[0] || 'componente');
                }

                assignments[tag] = { area, type };
            }

            for (const tag of tags) {
                if (!assignments[tag]) {
                    assignments[tag] = { area: '__unclassified', type: 'componente' };
                }
            }

            return {
                saveNormalizations: true,
                catalog: safeCatalog,
                tags,
                assignments,
                draggingTag: null,
                areaKeys: Object.keys(safeCatalog),
                startDrag(tag) {
                    this.draggingTag = tag;
                },
                dropOnLane(area, type) {
                    if (!this.draggingTag) {
                        return;
                    }

                    this.assignments[this.draggingTag] = { area, type };
                    this.draggingTag = null;
                },
                laneTags(area, type) {
                    return this.tags.filter((tag) => {
                        const assignment = this.assignments[tag];
                        return assignment && assignment.area === area && assignment.type === type;
                    });
                },
                countArea(area) {
                    return this.tags.filter((tag) => this.assignments[tag]?.area === area).length;
                },
                serializedClassifications() {
                    return JSON.stringify(this.assignments);
                },
            };
        }
    </script>
</x-filament-panels::page>
