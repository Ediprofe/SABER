<x-filament-panels::page>
    @php
        $sessions = $this->record->getConfiguredSessionNumbers();
        $pipeline = $this->getPipelineStatus();
        $reportsStatus = $this->getIndividualReportsStatus();
        $emailCoverage = $this->getEmailCoverage();
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
            <h3 class="text-lg font-semibold">Paso 2: Resultados y Exportaciones</h3>
            <p class="mt-1 text-sm text-gray-500">Descarga Excel, PDF y HTML cuando el pipeline esté completo.</p>

            <div class="mt-3 flex flex-wrap gap-3">
                <x-filament::button
                    tag="a"
                    color="primary"
                    icon="heroicon-o-table-cells"
                    href="{{ \App\Filament\Resources\ExamResource::getUrl('zipgrade-results', ['record' => $this->record]) }}"
                >
                    Ver tabla de resultados
                </x-filament::button>

                <x-filament::button color="success" icon="heroicon-o-document-chart-bar" wire:click="mountAction('download_excel')" :disabled="! $pipeline['ready']">
                    Descargar Excel
                </x-filament::button>

                <x-filament::button color="warning" icon="heroicon-o-document-text" wire:click="mountAction('download_pdf')" :disabled="! $pipeline['ready']">
                    Descargar PDF
                </x-filament::button>

                <x-filament::button color="info" icon="heroicon-o-code-bracket" wire:click="mountAction('download_html')" :disabled="! $pipeline['ready']">
                    Descargar HTML
                </x-filament::button>
            </div>

            @if (! $pipeline['ready'])
                <p class="mt-3 text-sm text-warning-600">Completa la carga de todas las sesiones para habilitar descargas.</p>
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
