<x-filament-panels::page>
    <div class="space-y-6">
        {{-- Información del Examen --}}
        <x-filament::section>
            <div class="flex justify-between items-center">
                <div>
                    <h2 class="text-xl font-bold text-gray-900 dark:text-white">
                        {{ $this->record->name }}
                    </h2>
                    <p class="text-sm text-gray-500 mt-1">
                        {{ $this->record->type }} - {{ $this->record->date->format('d/m/Y') }}
                    </p>
                </div>
                <div class="text-right">
                    @php
                        $sessions = $this->record->sessions;
                        $totalQuestions = $sessions->sum('total_questions');
                        $importedSessions = $sessions->filter(fn($s) => $s->zipgradeImport?->isCompleted())->count();
                    @endphp
                    <p class="text-sm font-medium text-gray-900 dark:text-white">
                        {{ $importedSessions }} de 2 sesiones importadas
                    </p>
                    <p class="text-sm text-gray-500">
                        {{ $totalQuestions }} preguntas en total
                    </p>
                </div>
            </div>
        </x-filament::section>

        {{-- Tabla de Resultados --}}
        <x-filament::section>
            <h3 class="text-lg font-medium mb-4">Resultados por Estudiante</h3>
            {{ $this->table }}
        </x-filament::section>

        {{-- Resumen Estadístico --}}
        @php
            $service = app(\App\Services\ZipgradeMetricsService::class);
            $stats = $service->getExamStatistics($this->record);
        @endphp

        @if($stats['total_students'] > 0)
        <x-filament::section>
            <h3 class="text-lg font-medium mb-4">Resumen Estadístico</h3>
            <div class="grid grid-cols-1 md:grid-cols-3 lg:grid-cols-6 gap-4">
                {{-- Global --}}
                <div class="bg-primary-50 dark:bg-primary-900/20 p-4 rounded-lg">
                    <p class="text-xs text-gray-500 uppercase tracking-wider">Global</p>
                    <p class="text-2xl font-bold text-primary-600 dark:text-primary-400">
                        {{ number_format($stats['global_average'], 1) }}
                    </p>
                    <p class="text-xs text-gray-400">σ = {{ number_format($stats['global_std_dev'], 1) }}</p>
                </div>

                {{-- Áreas --}}
                @foreach(['lectura', 'matematicas', 'sociales', 'naturales', 'ingles'] as $area)
                    @if(isset($stats['areas'][$area]))
                    <div class="bg-gray-50 dark:bg-gray-800 p-4 rounded-lg">
                        <p class="text-xs text-gray-500 uppercase tracking-wider">
                            {{ ucfirst($area) }}
                        </p>
                        <p class="text-xl font-semibold text-gray-900 dark:text-white">
                            {{ number_format($stats['areas'][$area]['average'], 1) }}
                        </p>
                        <p class="text-xs text-gray-400">σ = {{ number_format($stats['areas'][$area]['std_dev'], 1) }}</p>
                    </div>
                    @endif
                @endforeach
            </div>

            {{-- Comparativo PIAR --}}
            @php
                $piarComparison = $service->getGlobalPiarComparison($this->record);
            @endphp
            @if(!empty($piarComparison['piar']['total_students']) && !empty($piarComparison['no_piar']['total_students']))
            <div class="mt-6 pt-6 border-t border-gray-200 dark:border-gray-700">
                <h4 class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-3">Comparativo PIAR</h4>
                <div class="grid grid-cols-2 gap-4">
                    <div class="bg-warning-50 dark:bg-warning-900/20 p-3 rounded-lg">
                        <p class="text-xs text-gray-500">PIAR ({{ $piarComparison['piar']['total_students'] }} estudiantes)</p>
                        <p class="text-lg font-semibold text-warning-600 dark:text-warning-400">
                            {{ number_format($piarComparison['piar']['global_average'], 1) }}
                        </p>
                    </div>
                    <div class="bg-success-50 dark:bg-success-900/20 p-3 rounded-lg">
                        <p class="text-xs text-gray-500">No PIAR ({{ $piarComparison['no_piar']['total_students'] }} estudiantes)</p>
                        <p class="text-lg font-semibold text-success-600 dark:text-success-400">
                            {{ number_format($piarComparison['no_piar']['global_average'], 1) }}
                        </p>
                    </div>
                </div>
            </div>
            @endif
        </x-filament::section>
        @endif
    </div>
</x-filament-panels::page>
