<x-filament::page>
    <x-filament::section>
        <x-slot name="heading">
            Clasificación de Tags Nuevos
        </x-slot>

        <x-slot name="description">
            Se detectaron {{ count($newTags) }} tags nuevos en el archivo CSV que necesitan ser clasificados antes de continuar con la importación.
            <br><br>
            Por favor, seleccione el tipo y el área padre para cada tag. Esto permitirá que el sistema calcule correctamente las métricas por área, competencia y componente.
        </x-slot>

        <form wire:submit="proceedWithImport">
            {{ $this->form }}

            <div class="mt-6 flex gap-4">
                <x-filament::button
                    type="submit"
                    color="primary"
                >
                    Continuar Importación
                </x-filament::button>

                <x-filament::button
                    type="button"
                    color="gray"
                    wire:click="cancel"
                >
                    Cancelar
                </x-filament::button>
            </div>
        </form>
    </x-filament::section>

    <x-filament::section class="mt-6">
        <x-slot name="heading">
            Información sobre los Tags
        </x-slot>

        <div class="prose dark:prose-invert max-w-none">
            <h4>Tipos de Tags:</h4>
            <ul>
                <li><strong>Área:</strong> Las 5 áreas principales (Ciencias, Matemáticas, Sociales, Lectura, Inglés)</li>
                <li><strong>Competencia:</strong> Habilidades cognitivas que se evalúan (ej: interpretación, formulación, indagación)</li>
                <li><strong>Componente:</strong> Temas específicos del área (ej: químico, físico, numérico, geométrico)</li>
                <li><strong>Tipo de Texto:</strong> Para Lectura Crítica (ej: continuo, discontinuo, literario)</li>
                <li><strong>Nivel de Lectura:</strong> Específico para Lectura Crítica (Literal, Inferencial, Crítico)</li>
                <li><strong>Parte:</strong> Para Inglés (ej: Parte 1, Parte 2, etc.)</li>
            </ul>

            <h4>Área Padre:</h4>
            <p>Solo necesaria si el tipo NO es "Área". Indica a qué área principal pertenece este tag.</p>
            <ul>
                <li><strong>Ciencias (Naturales):</strong> Químico, Físico, Biológico, CTS, etc.</li>
                <li><strong>Matemáticas:</strong> Numérico-variacional, Geométrico-métrico, Aleatorio, etc.</li>
                <li><strong>Ciencias Sociales:</strong> Historia, Geografía, Ético-político, etc.</li>
                <li><strong>Lectura Crítica:</strong> Tipos de texto, competencias de lectura, <strong>Nivel de Lectura (Literal, Inferencial, Crítico)</strong></li>
                <li><strong>Inglés:</strong> Partes del examen</li>
            </ul>

            <div class="mt-4 p-4 bg-info-50 dark:bg-info-950 rounded-lg">
                <p class="text-sm text-info-600 dark:text-info-400">
                    <strong>Nivel de Lectura:</strong> Es una dimensión especial del área Lectura Crítica que clasifica las preguntas según el nivel de comprensión requerido: Literal (comprensión explícita), Inferencial (interpretación) o Crítico (evaluación y reflexión).
                </p>
            </div>

            <div class="mt-4 p-4 bg-warning-50 dark:bg-warning-950 rounded-lg">
                <p class="text-sm text-warning-600 dark:text-warning-400">
                    <strong>Nota importante:</strong> Si marca "Guardar esta configuración para futuras importaciones",
                    el sistema recordará estas clasificaciones para archivos futuros con los mismos tags.
                </p>
            </div>
        </div>
    </x-filament::section>
</x-filament::page>
