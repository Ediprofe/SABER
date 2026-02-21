<x-filament-panels::page>
    @php
        $sessions = $this->record->getConfiguredSessionNumbers();
        $pipeline = $this->getPipelineStatus();
        $reportsStatus = $this->getIndividualReportsStatus();
        $emailCoverage = $this->getEmailCoverage();
        $dimensionCoverage = $this->getDimensionCoverageSummary();
    @endphp

    <div class="space-y-6">
        <x-filament::section>
            <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
                <div class="rounded-lg border p-4">
                    <p class="text-sm text-gray-500">Sesiones configuradas</p>
                    <p class="mt-1 text-xl font-semibold">{{ count($sessions) }}</p>
                </div>
                <div class="rounded-lg border p-4">
                    <p class="text-sm text-gray-500">Estado de carga</p>
                    <p class="mt-1 text-xl font-semibold">{{ $pipeline['tags_done'] ? 'Completo' : 'Pendiente' }}</p>
                </div>
                <div class="rounded-lg border p-4">
                    <p class="text-sm text-gray-500">Pipeline general</p>
                    <p class="mt-1 text-xl font-semibold">{{ $pipeline['ready'] ? 'Listo para reportes' : 'Aún no listo' }}</p>
                </div>
            </div>
        </x-filament::section>

        <x-filament::section>
            <h3 class="text-lg font-semibold">Paso 1: Cargar Archivos por Sesión</h3>
            <p class="mt-1 text-sm text-gray-500">Cada sesión requiere dos archivos: Blueprint CSV y Respuestas CSV.</p>

            <div class="mt-3 flex flex-wrap gap-3">
                @foreach ($sessions as $sessionNumber)
                    <x-filament::button
                        tag="a"
                        color="{{ $sessionNumber === 1 ? 'success' : 'warning' }}"
                        icon="heroicon-o-arrow-up-tray"
                        href="{{ \App\Filament\Resources\ExamResource::getUrl('upload', ['record' => $this->record, 'sessionNumber' => $sessionNumber]) }}"
                    >
                        Cargar Sesión {{ $sessionNumber }}
                    </x-filament::button>
                @endforeach
            </div>

            <div class="mt-4 grid grid-cols-1 gap-3 md:grid-cols-2">
                @foreach ($sessions as $sessionNumber)
                    @php($status = $this->getSessionStatus($sessionNumber))
                    <div class="rounded-lg border p-3">
                        <p class="text-sm text-gray-500">Sesión {{ $sessionNumber }}</p>
                        <p class="text-base font-medium">Importada: {{ $status['has_completed_import'] ? 'Sí' : 'No' }}</p>
                        <p class="text-sm text-gray-500">Preguntas detectadas: {{ $status['total_questions'] }}</p>
                    </div>
                @endforeach
            </div>
        </x-filament::section>

        <x-filament::section>
            <h3 class="text-lg font-semibold">Paso 2: Resultados Consolidados</h3>
            <p class="mt-1 text-sm text-gray-500">Descarga los reportes consolidados (Excel, PDF y HTML) cuando el pipeline esté completo.</p>

            <div class="mt-3 flex flex-wrap gap-3">
                <x-filament::button
                    tag="a"
                    color="primary"
                    icon="heroicon-o-table-cells"
                    href="{{ \App\Filament\Resources\ExamResource::getUrl('zipgrade-results', ['record' => $this->record]) }}"
                >
                    Ver tabla de resultados
                </x-filament::button>

                @if ($pipeline['ready'])
                    <x-filament::button
                        tag="a"
                        color="success"
                        icon="heroicon-o-document-chart-bar"
                        href="{{ route('admin.exams.pipeline.export.excel', ['exam' => $this->record]) }}"
                    >
                        Descargar Excel
                    </x-filament::button>

                    <x-filament::button
                        tag="a"
                        color="warning"
                        icon="heroicon-o-document-text"
                        href="{{ route('admin.exams.pipeline.export.pdf', ['exam' => $this->record]) }}"
                    >
                        Descargar PDF consolidado
                    </x-filament::button>

                    <x-filament::button
                        tag="a"
                        color="info"
                        icon="heroicon-o-code-bracket"
                        href="{{ route('admin.exams.pipeline.export.html', ['exam' => $this->record]) }}"
                    >
                        Descargar HTML
                    </x-filament::button>
                @else
                    <x-filament::button color="success" icon="heroicon-o-document-chart-bar" disabled>
                        Descargar Excel
                    </x-filament::button>

                    <x-filament::button color="warning" icon="heroicon-o-document-text" disabled>
                        Descargar PDF consolidado
                    </x-filament::button>

                    <x-filament::button color="info" icon="heroicon-o-code-bracket" disabled>
                        Descargar HTML
                    </x-filament::button>
                @endif
            </div>

            @if (! $pipeline['ready'])
                <p class="mt-3 text-sm text-warning-600">Completa la carga de todas las sesiones para habilitar descargas.</p>
            @else
                <p class="mt-3 text-sm text-gray-500">
                    Este PDF es el reporte consolidado del examen. El ZIP de PDFs por estudiante se genera en la sección
                    <span class="font-medium">Reportes Individuales y Correo</span>.
                </p>
            @endif
        </x-filament::section>

        <x-filament::section>
            <h3 class="text-lg font-semibold">Control de Dimensiones por Área</h3>
            <p class="mt-1 text-sm text-gray-500">
                Verifica aquí si cada pregunta del área tiene dimensión clasificada. Si hay faltantes, vuelve a cargar la sesión y corrige la clasificación de tags.
            </p>

            @if ($dimensionCoverage === [])
                <p class="mt-3 text-sm text-gray-500">Aún no hay preguntas importadas para calcular cobertura.</p>
            @else
                <div class="mt-3 overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 text-sm dark:divide-gray-700">
                        <thead>
                            <tr>
                                <th class="px-3 py-2 text-left font-medium">Área</th>
                                <th class="px-3 py-2 text-left font-medium">Preguntas</th>
                                <th class="px-3 py-2 text-left font-medium">Dim 1</th>
                                <th class="px-3 py-2 text-left font-medium">Cobertura</th>
                                <th class="px-3 py-2 text-left font-medium">Dim 2</th>
                                <th class="px-3 py-2 text-left font-medium">Cobertura</th>
                                <th class="px-3 py-2 text-left font-medium">Dim 3</th>
                                <th class="px-3 py-2 text-left font-medium">Cobertura</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                            @foreach ($dimensionCoverage as $row)
                                <tr>
                                    <td class="px-3 py-2">{{ $row['area'] }}</td>
                                    <td class="px-3 py-2">{{ $row['total'] }}</td>
                                    <td class="px-3 py-2">{{ $row['dim1_name'] }}</td>
                                    <td class="px-3 py-2">
                                        {{ $row['dim1_with'] }}/{{ $row['total'] }}
                                        @if ($row['dim1_missing'] > 0)
                                            <span class="text-danger-600"> (faltan {{ $row['dim1_missing'] }})</span>
                                        @endif
                                    </td>
                                    <td class="px-3 py-2">{{ $row['dim2_name'] ?? '—' }}</td>
                                    <td class="px-3 py-2">
                                        @if ($row['dim2_with'] === null)
                                            —
                                        @else
                                            {{ $row['dim2_with'] }}/{{ $row['total'] }}
                                            @if (($row['dim2_missing'] ?? 0) > 0)
                                                <span class="text-danger-600"> (faltan {{ $row['dim2_missing'] }})</span>
                                            @endif
                                        @endif
                                    </td>
                                    <td class="px-3 py-2">{{ $row['dim3_name'] ?? '—' }}</td>
                                    <td class="px-3 py-2">
                                        @if ($row['dim3_with'] === null)
                                            —
                                        @else
                                            {{ $row['dim3_with'] }}/{{ $row['total'] }}
                                            @if (($row['dim3_missing'] ?? 0) > 0)
                                                <span class="text-danger-600"> (faltan {{ $row['dim3_missing'] }})</span>
                                            @endif
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-filament::section>

        <x-filament::section>
            <h3 class="text-lg font-semibold">Reportes Individuales y Correo</h3>
            <div class="mt-3 grid grid-cols-1 gap-3 md:grid-cols-3">
                <div class="rounded-lg border p-3">
                    <p class="text-sm text-gray-500">Estado del ZIP</p>
                    <p class="text-base font-medium">{{ $reportsStatus['label'] }}</p>
                    @if ($reportsStatus['status'] === 'processing' || $reportsStatus['status'] === 'pending')
                        <p class="text-sm text-gray-500">Progreso: {{ $reportsStatus['progress'] }}%</p>
                    @endif
                </div>
                <div class="rounded-lg border p-3">
                    <p class="text-sm text-gray-500">Con email</p>
                    <p class="text-base font-medium">{{ $emailCoverage['with_email'] }} estudiantes</p>
                </div>
                <div class="rounded-lg border p-3">
                    <p class="text-sm text-gray-500">Sin email</p>
                    <p class="text-base font-medium">{{ $emailCoverage['without_email'] }} estudiantes</p>
                </div>
            </div>

            <div class="mt-4 flex flex-wrap gap-3">
                <x-filament::button color="success" icon="heroicon-o-document-arrow-down" wire:click="mountAction('generate_individual_reports')" :disabled="! $pipeline['ready']">
                    Generar ZIP Individuales
                </x-filament::button>

                <x-filament::button color="primary" icon="heroicon-o-arrow-down-tray" wire:click="mountAction('download_individual_reports')" :disabled="! $reportsStatus['can_download']">
                    Descargar ZIP Individuales
                </x-filament::button>

                <x-filament::button color="secondary" icon="heroicon-o-envelope" wire:click="mountAction('send_reports_email')" :disabled="! $reportsStatus['can_download']">
                    Enviar reportes por email
                </x-filament::button>
            </div>
        </x-filament::section>

        <x-filament::section>
            <h3 class="text-lg font-semibold">Flujo recomendado</h3>
            <ol class="mt-3 list-decimal space-y-1 pl-5 text-sm text-gray-700">
                <li>Carga cada sesión con Blueprint + Respuestas.</li>
                <li>Revisa la vista previa y confirma importación por sesión.</li>
                <li>Consulta resultados y descarga Excel/HTML/PDF.</li>
                <li>Genera ZIP individual y envía correos cuando esté listo.</li>
            </ol>
        </x-filament::section>
    </div>

    <x-filament-actions::modals />
</x-filament-panels::page>
