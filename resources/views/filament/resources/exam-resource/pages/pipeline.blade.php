<x-filament-panels::page>
    @php
        $session1 = $this->getSessionStatus(1);
        $session2 = $this->getSessionStatus(2);
        $pipeline = $this->getPipelineStatus();
    @endphp

    <div class="space-y-6">
        <x-filament::section>
            <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
                <div class="rounded-lg border p-4">
                    <p class="text-sm text-gray-500">Estado de Tags</p>
                    <p class="mt-1 text-xl font-semibold">
                        {{ $pipeline['tags_done'] ? 'Completo' : 'Pendiente' }}
                    </p>
                </div>
                <div class="rounded-lg border p-4">
                    <p class="text-sm text-gray-500">Estado de Stats</p>
                    <p class="mt-1 text-xl font-semibold">
                        {{ $pipeline['stats_done'] ? 'Completo' : 'Pendiente' }}
                    </p>
                </div>
                <div class="rounded-lg border p-4">
                    <p class="text-sm text-gray-500">Pipeline general</p>
                    <p class="mt-1 text-xl font-semibold">
                        {{ $pipeline['ready'] ? 'Listo para reportes' : 'Aún no listo' }}
                    </p>
                </div>
            </div>
        </x-filament::section>

        <x-filament::section>
            <h3 class="text-lg font-semibold">Sesión 1</h3>
            <div class="mt-3 grid grid-cols-1 gap-3 md:grid-cols-3">
                <div class="rounded-lg border p-3">
                    <p class="text-sm text-gray-500">Tags importados</p>
                    <p class="text-base font-medium">{{ $session1['has_completed_import'] ? 'Sí' : 'No' }}</p>
                </div>
                <div class="rounded-lg border p-3">
                    <p class="text-sm text-gray-500">Stats importadas</p>
                    <p class="text-base font-medium">{{ $session1['has_stats'] ? 'Sí' : 'No' }}</p>
                </div>
                <div class="rounded-lg border p-3">
                    <p class="text-sm text-gray-500">Preguntas</p>
                    <p class="text-base font-medium">{{ $session1['total_questions'] }}</p>
                </div>
            </div>
        </x-filament::section>

        <x-filament::section>
            <h3 class="text-lg font-semibold">Sesión 2</h3>
            <div class="mt-3 grid grid-cols-1 gap-3 md:grid-cols-3">
                <div class="rounded-lg border p-3">
                    <p class="text-sm text-gray-500">Tags importados</p>
                    <p class="text-base font-medium">{{ $session2['has_completed_import'] ? 'Sí' : 'No' }}</p>
                </div>
                <div class="rounded-lg border p-3">
                    <p class="text-sm text-gray-500">Stats importadas</p>
                    <p class="text-base font-medium">{{ $session2['has_stats'] ? 'Sí' : 'No' }}</p>
                </div>
                <div class="rounded-lg border p-3">
                    <p class="text-sm text-gray-500">Preguntas</p>
                    <p class="text-base font-medium">{{ $session2['total_questions'] }}</p>
                </div>
            </div>
        </x-filament::section>

        <x-filament::section>
            <h3 class="text-lg font-semibold">Flujo recomendado</h3>
            <ol class="mt-3 list-decimal space-y-1 pl-5 text-sm text-gray-700">
                <li>Importa Tags de Sesión 1 y Sesión 2.</li>
                <li>Importa Stats de Sesión 1 y Sesión 2.</li>
                <li>Abre Resultados y genera Excel, HTML, PDF o correos.</li>
            </ol>
        </x-filament::section>
    </div>
</x-filament-panels::page>
