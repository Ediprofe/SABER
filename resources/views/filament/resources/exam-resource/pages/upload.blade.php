<x-filament-panels::page>
    <div class="fi-section rounded-xl border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-700 dark:bg-gray-900">
        @if (session('upload_error'))
            <div class="mb-4 rounded-lg border border-danger-300 bg-danger-50 px-4 py-3 text-sm text-danger-700 dark:border-danger-700 dark:bg-danger-950/40 dark:text-danger-200">
                {{ session('upload_error') }}
            </div>
        @endif

        @if ($errors->any())
            <div class="mb-4 rounded-lg border border-danger-300 bg-danger-50 px-4 py-3 text-sm text-danger-700 dark:border-danger-700 dark:bg-danger-950/40 dark:text-danger-200">
                {{ $errors->first() }}
            </div>
        @endif

        <form method="POST" action="{{ $this->getAnalyzeUrl() }}" enctype="multipart/form-data" class="space-y-6" x-data="{ submitting: false }" x-on:submit="submitting = true">
            @csrf

            <div class="space-y-2">
                <label for="blueprint_file" class="text-sm font-medium text-gray-900 dark:text-gray-100">
                    Blueprint CSV (preguntas, respuestas correctas y tags)
                </label>

                <input
                    id="blueprint_file"
                    name="blueprint_file"
                    type="file"
                    accept=".csv,text/csv,text/plain,application/csv,application/x-csv,application/vnd.ms-excel"
                    required
                    class="block w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 shadow-sm focus:border-primary-600 focus:outline-none focus:ring-2 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100"
                />
            </div>

            <div class="space-y-2">
                <label for="responses_file" class="text-sm font-medium text-gray-900 dark:text-gray-100">
                    Respuestas CSV (estudiantes y columnas Stu/PriKey/Points/Mark)
                </label>

                <input
                    id="responses_file"
                    name="responses_file"
                    type="file"
                    accept=".csv,text/csv,text/plain,application/csv,application/x-csv,application/vnd.ms-excel"
                    required
                    class="block w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 shadow-sm focus:border-primary-600 focus:outline-none focus:ring-2 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100"
                />

                <p class="text-xs text-gray-600 dark:text-gray-400">
                    Tamaño máximo recomendado por archivo: 20 MB.
                </p>
            </div>

            <div class="flex flex-wrap items-center gap-3">
                <button
                    type="submit"
                    x-bind:disabled="submitting"
                    class="inline-flex items-center rounded-lg bg-primary-600 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500"
                    x-bind:class="{ 'cursor-not-allowed opacity-70': submitting }"
                >
                    <svg x-show="submitting" class="-ml-1 mr-2 h-4 w-4 animate-spin text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-20" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-90" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
                    </svg>
                    <span x-show="!submitting">Analizar Archivos</span>
                    <span x-show="submitting">Analizando...</span>
                </button>

                <a
                    href="{{ $this->getPipelineUrl() }}"
                    class="inline-flex items-center rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-100 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-800"
                >
                    Volver al Pipeline
                </a>
            </div>

            <p x-show="submitting" class="text-xs text-gray-600 dark:text-gray-400">
                Se validará el consolidado y luego podrás confirmar la importación.
            </p>
        </form>
    </div>
</x-filament-panels::page>
