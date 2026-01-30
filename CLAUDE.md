# 📘 DOCUMENTO DE REQUERIMIENTOS — MVP SISTEMA SABER (ANÁLISIS ICFES)

## 🧠 Rol del Agente

Actúa como **Arquitecto de Software Educativo Senior y Desarrollador Laravel Experto**, con experiencia en análisis estadístico académico tipo ICFES (SABER).

Debes ejecutar **exactamente** lo especificado.
No inventes reglas, no simplifiques, no anticipes fases futuras.

---

## 🎯 Propósito del MVP (Scope CERRADO)

Construir un **Producto Mínimo Viable (MVP)** que permita:

- Analizar **UNA prueba única** (Simulacro o ICFES).
- Para **una población de estudiantes generada y persistida en el sistema**.
- Flujo docente:
  1. El sistema **exporta un Excel plantilla**.
  2. El docente **diligencia puntajes**.
  3. El sistema **importa / sobreescribe resultados**.
  4. El sistema **genera un informe HTML interactivo OFFLINE**.

🚫 **Fuera de alcance del MVP:**
- Longitudinal
- Multicorte
- PDF
- Comparaciones históricas
- Autenticación / Login

---

## 🏗️ Stack Técnico (OBLIGATORIO)

| Componente | Tecnología | Versión |
|------------|------------|---------|
| Framework | Laravel | 12 |
| Panel Admin | Filament | 3 |
| Base de Datos | SQLite | local |
| Excel Import/Export | Maatwebsite/Laravel-Excel | ^3.1 |
| Reporte | HTML autocontenido | Blade + Alpine.js + Chart.js embebido |
| Asistente IA | Laravel Boost | ^2.0 (dev) |
| Idioma UI | Español (Colombia) | — |

### Instalación de dependencias clave

```bash
# Filament 3
composer require filament/filament:"^3.0"
php artisan filament:install --panels

# Laravel Excel
composer require maatwebsite/excel

# Laravel Boost (desarrollo)
composer require laravel/boost --dev
php artisan boost:install
```

### ❌ Prohibiciones técnicas

- NO SPA
- NO React/Vue
- NO dependencias CDN en el HTML final
- NO Livewire fuera de Filament

---

## 🧩 Modelo de Datos (ESQUEMA EXACTO)

### Diagrama de Relaciones

```
┌─────────────┐       ┌──────────────────┐       ┌─────────────┐
│  students   │       │   enrollments    │       │    exams    │
│─────────────│       │──────────────────│       │─────────────│
│ id          │◄──┐   │ id               │   ┌──►│ id          │
│ code (UK)   │   │   │ student_id (FK)  │───┘   │ academic_   │
│ first_name  │   └───│ academic_year_id │       │   year_id   │
│ last_name   │       │ grade            │       │ name        │
│ created_at  │       │ group            │       │ type        │
│ updated_at  │       │ is_piar          │       │ date        │
└─────────────┘       │ status           │       └──────┬──────┘
                      └────────┬─────────┘              │
                               │                        │
                               │    ┌───────────────────┘
                               │    │
                               ▼    ▼
                      ┌──────────────────┐
                      │   exam_results   │
                      │──────────────────│
                      │ id               │
                      │ exam_id (FK)     │
                      │ enrollment_id(FK)│
                      │ lectura          │
                      │ matematicas      │
                      │ sociales         │
                      │ naturales        │
                      │ ingles           │
                      │ global_score     │
                      └──────────────────┘

┌──────────────────┐
│  academic_years  │
│──────────────────│
│ id               │
│ year             │
└──────────────────┘
```

### 1️⃣ Students (Identidad Permanente)

```php
Schema::create('students', function (Blueprint $table) {
    $table->id();
    $table->string('code', 15)->unique();  // STU-2026-00001
    $table->string('first_name', 100);
    $table->string('last_name', 100);
    $table->timestamps();
});
```

#### Código de Estudiante (LÓGICA OBLIGATORIA)

**Formato:** `STU-{AÑO_GRADUACIÓN}-{SECUENCIAL_5_DÍGITOS}`

**Cálculo del año de graduación:**
```
año_graduación = año_académico_matrícula + (11 - grado)
```

| Año Académico | Grado | Cálculo | Código Ejemplo |
|---------------|-------|---------|----------------|
| 2025 | 11 | 2025 + (11-11) = 2025 | STU-2025-00001 |
| 2025 | 10 | 2025 + (11-10) = 2026 | STU-2026-00001 |
| 2024 | 11 (egresado) | 2024 + (11-11) = 2024 | STU-2024-00001 |

**Regla de generación:**
- El código se genera **una sola vez** al crear el estudiante.
- Se basa en su **primera matrícula**.
- Es **inmutable** (no cambia si repite año).
- Secuencial por año de graduación (cada promoción tiene su contador).

### 2️⃣ Academic Years

```php
Schema::create('academic_years', function (Blueprint $table) {
    $table->id();
    $table->year('year')->unique();  // 2024, 2025, 2026
    $table->timestamps();
});
```

### 3️⃣ Enrollments (Matrícula Anual — FUENTE DE VERDAD)

```php
Schema::create('enrollments', function (Blueprint $table) {
    $table->id();
    $table->foreignId('student_id')->constrained()->cascadeOnDelete();
    $table->foreignId('academic_year_id')->constrained()->cascadeOnDelete();
    $table->unsignedTinyInteger('grade');  // 10 o 11
    $table->string('group', 10);           // "10-1", "11-2"
    $table->boolean('is_piar')->default(false);
    $table->enum('status', ['ACTIVE', 'GRADUATED'])->default('ACTIVE');
    $table->timestamps();

    $table->unique(['student_id', 'academic_year_id']);
});
```

**⚠️ IMPORTANTE:** `is_piar` vive en `enrollments`, NO en `students`.

### 4️⃣ Exams

```php
Schema::create('exams', function (Blueprint $table) {
    $table->id();
    $table->foreignId('academic_year_id')->constrained()->cascadeOnDelete();
    $table->string('name', 150);           // "Simulacro Único 2025"
    $table->enum('type', ['SIMULACRO', 'ICFES']);
    $table->date('date');
    $table->timestamps();
});
```

### 5️⃣ Exam Results

```php
Schema::create('exam_results', function (Blueprint $table) {
    $table->id();
    $table->foreignId('exam_id')->constrained()->cascadeOnDelete();
    $table->foreignId('enrollment_id')->constrained()->cascadeOnDelete();
    $table->unsignedTinyInteger('lectura')->nullable();      // 0-100
    $table->unsignedTinyInteger('matematicas')->nullable();  // 0-100
    $table->unsignedTinyInteger('sociales')->nullable();     // 0-100
    $table->unsignedTinyInteger('naturales')->nullable();    // 0-100
    $table->unsignedTinyInteger('ingles')->nullable();       // 0-100
    $table->unsignedSmallInteger('global_score')->nullable(); // 0-500
    $table->timestamps();

    $table->unique(['exam_id', 'enrollment_id']);
});
```

---

## 🧪 Reglas de Negocio (ESTRICTAS)

### 1️⃣ Rangos de Puntajes

| Tipo | Mínimo | Máximo |
|------|--------|--------|
| Puntaje por área | 0 | 100 |
| Puntaje global | 0 | 500 |

### 2️⃣ Cálculo de Puntaje Global (FÓRMULA OBLIGATORIA)

```php
global = round(((lectura + matematicas + sociales + naturales) * 3 + ingles) / 13 * 5)
```

**Caso especial — Inglés NULL:**

| Contexto | Comportamiento |
|----------|----------------|
| Para cálculo del global | `ingles = 0` |
| En base de datos | `ingles` permanece `NULL` |

### 3️⃣ Reglas PIAR para Métricas Grupales

**Al calcular promedios por área:**

| Condición | Comportamiento |
|-----------|----------------|
| `is_piar = true` AND `ingles IS NULL` | NO sumar 0, NO contar en denominador. Se ignora completamente en promedio de inglés. |
| Cualquier área con valor `NULL` | Ignorar (no suma, no cuenta en denominador) |

---

## 📋 Panel Administrativo Filament (CRUDs)

### Recursos Requeridos

| Entidad | Tipo | Acciones | Justificación |
|---------|------|----------|---------------|
| **AcademicYearResource** | CRUD completo | Crear, Editar, Eliminar, Listar | Gestionar años académicos |
| **StudentResource** | CRUD completo | Crear, Editar, Eliminar, Listar, Importar | Gestión de estudiantes |
| **EnrollmentResource** | CRUD completo | Crear, Editar, Eliminar, Listar, Importar | Matrículas anuales |
| **ExamResource** | CRUD completo | Crear, Editar, Eliminar, Listar | Definir exámenes |
| **ExamResultResource** | Solo lectura | Listar, Ver detalle | Consulta (se llena por importación) |

### Acciones Personalizadas en Filament

| Recurso | Acción | Descripción |
|---------|--------|-------------|
| StudentResource | `ImportStudentsAction` | Importar estudiantes desde Excel |
| EnrollmentResource | `ImportEnrollmentsAction` | Importar matrículas desde Excel |
| ExamResource | `ExportTemplateAction` | Exportar plantilla de resultados |
| ExamResource | `ImportResultsAction` | Importar resultados de examen |
| ExamResource | `GenerateReportAction` | Generar y descargar HTML |

---

## 📥 Importación / Exportación Excel (ESPECIFICACIÓN COMPLETA)

### Formato General

- **Tipo de archivo:** `.xlsx` (Excel 2007+)
- **Librería:** Maatwebsite/Laravel-Excel
- **Encoding:** UTF-8
- **Primera fila:** Encabezados (obligatorio)

### A) Excel de Estudiantes + Matrículas (Carga Inicial)

**Archivo:** `estudiantes_matriculas.xlsx`

| Columna | Campo | Tipo | Requerido | Notas |
|---------|-------|------|-----------|-------|
| A | `first_name` | string | ✅ | Nombre del estudiante |
| B | `last_name` | string | ✅ | Apellido del estudiante |
| C | `academic_year` | integer | ✅ | Ej: 2025 |
| D | `grade` | integer | ✅ | 10 u 11 |
| E | `group` | string | ✅ | Ej: "10-1", "11-2" |
| F | `is_piar` | boolean | ❌ | "SI" o vacío. Default: NO |
| G | `status` | string | ❌ | "ACTIVE" o "GRADUATED". Default: ACTIVE |

**Comportamiento de importación:**
1. Si el estudiante NO existe → Crear estudiante + generar código + crear matrícula.
2. Si el estudiante YA existe (match por `first_name` + `last_name`) → Solo crear/actualizar matrícula.
3. El código se genera automáticamente según la lógica de año de graduación.

### B) Excel de Solo Matrículas (Años Siguientes)

**Archivo:** `matriculas_{año}.xlsx`

| Columna | Campo | Tipo | Requerido | Notas |
|---------|-------|------|-----------|-------|
| A | `student_code` | string | ✅ | Código existente (STU-2026-00001) |
| B | `academic_year` | integer | ✅ | Ej: 2026 |
| C | `grade` | integer | ✅ | 10 u 11 |
| D | `group` | string | ✅ | Ej: "11-1" |
| E | `is_piar` | boolean | ❌ | "SI" o vacío |
| F | `status` | string | ❌ | "ACTIVE" o "GRADUATED" |

### C) Plantilla de Resultados (Exportación)

**Archivo generado:** `plantilla_resultados_{exam_name}_{grado}.xlsx`

| Columna | Campo | Editable | Notas |
|---------|-------|----------|-------|
| A | `code` | ❌ (readonly) | Código del estudiante |
| B | `first_name` | ❌ (readonly) | Para referencia |
| C | `last_name` | ❌ (readonly) | Para referencia |
| D | `group` | ❌ (readonly) | Para referencia |
| E | `is_piar` | ❌ (readonly) | "SI" o "NO" |
| F | `lectura` | ✅ | 0-100 o vacío |
| G | `matematicas` | ✅ | 0-100 o vacío |
| H | `sociales` | ✅ | 0-100 o vacío |
| I | `naturales` | ✅ | 0-100 o vacío |
| J | `ingles` | ✅ | 0-100 o vacío |

**Filtros disponibles al exportar:**
- Año académico (obligatorio)
- Grado (obligatorio): 10 u 11
- Grupo (opcional): específico o todos

### D) Importación de Resultados

**Reglas de validación:**

| Validación | Comportamiento si falla |
|------------|-------------------------|
| `code` no existe | ❌ Rechazar TODO el archivo |
| Puntaje fuera de rango (0-100) | ❌ Rechazar TODO el archivo |
| Fila con todos los puntajes vacíos | ⚠️ Ignorar fila (warning) |
| Estudiante sin matrícula en ese año | ❌ Rechazar TODO el archivo |

**Comportamiento de sobreescritura:**
- Si ya existen resultados para ese `exam_id` + `enrollment_id` → REEMPLAZAR.
- El `global_score` se recalcula automáticamente.

**Mensaje de error (formato):**
```
Error en la importación:
- Fila 5: El código "STU-2026-00099" no existe en el sistema.
- Fila 12: El puntaje de matemáticas (105) está fuera del rango permitido (0-100).
- Fila 18: El estudiante "STU-2025-00003" no tiene matrícula en el año 2025.

No se importó ningún registro. Corrija los errores e intente nuevamente.
```

---

## 📊 Análisis y Reporte HTML (ENTREGABLE PRINCIPAL)

### Especificación del Reporte

| Aspecto | Valor |
|---------|-------|
| Tipo de análisis | Prueba Única |
| Formato | Un solo archivo `.html` |
| Funcionamiento | 100% offline (sin internet) |
| Datos | Embebidos como JSON en `<script>` |
| Interactividad | Alpine.js embebido |
| Gráficos | Chart.js embebido |
| Descarga | Directa al navegador |
| Nombre archivo | `informe_{exam_name}_{grado}_{timestamp}.html` |

### Estructura del Reporte

#### 🟦 Sección 1 — KPIs Principales

| KPI | Descripción |
|-----|-------------|
| Total estudiantes | Cantidad total evaluados |
| Con PIAR | Cantidad con `is_piar = true` |
| Sin PIAR | Cantidad con `is_piar = false` |
| Promedio global | Media del `global_score` |
| Desviación estándar global | DE del `global_score` |

#### 🟦 Sección 2 — Listado de Estudiantes

**Columnas de la tabla:**

| Columna | Ordenable | Filtrable |
|---------|-----------|-----------|
| Code | ❌ | ✅ (buscador) |
| Nombre | ❌ | ✅ (buscador) |
| Apellido | ❌ | ✅ (buscador) |
| Grupo | ❌ | ✅ (dropdown) |
| PIAR | ❌ | ✅ (toggle) |
| Global | ✅ | ❌ |
| Lectura | ✅ | ❌ |
| Matemáticas | ✅ | ❌ |
| Sociales | ✅ | ❌ |
| Naturales | ✅ | ❌ |
| Inglés | ✅ | ❌ |

**Interactividad requerida:**
- Buscador por nombre/código (filtro en tiempo real)
- Filtro por grupo (dropdown)
- Toggle mostrar/ocultar PIAR
- Ordenamiento por cualquier columna numérica

#### 🟦 Sección 3 — Estadísticas por Área

| Métrica | Lectura | Matemáticas | Sociales | Naturales | Inglés |
|---------|---------|-------------|----------|-----------|--------|
| Promedio | ✅ | ✅ | ✅ | ✅ | ✅ |
| Desviación | ✅ | ✅ | ✅ | ✅ | ✅ |
| Mínimo | ✅ | ✅ | ✅ | ✅ | ✅ |
| Máximo | ✅ | ✅ | ✅ | ✅ | ✅ |

**Comparativo PIAR:**
- Tabla separada con las mismas métricas para:
  - Solo estudiantes PIAR
  - Solo estudiantes sin PIAR

#### 🟦 Sección 4 — Top Performers

| Ranking | Criterio |
|---------|----------|
| Top 5 Global | Mayor `global_score` |
| Top 5 Lectura | Mayor puntaje en lectura |
| Top 5 Matemáticas | Mayor puntaje en matemáticas |
| Top 5 Sociales | Mayor puntaje en sociales |
| Top 5 Naturales | Mayor puntaje en naturales |
| Top 5 Inglés | Mayor puntaje en inglés |

#### 🟦 Sección 5 — Gráficos

| Gráfico | Tipo | Descripción |
|---------|------|-------------|
| Promedios por área | Barras horizontales | 5 barras, una por área |
| Desviación por área | Barras horizontales | 5 barras, una por área |
| Promedios por grupo | Barras agrupadas | Grupos en X, áreas como series |
| Comparativo PIAR | Barras agrupadas | Toggle para mostrar/ocultar |
| Distribución global | Histograma | Rangos de puntaje en X, frecuencia en Y |

---

## ⚙️ Arquitectura de Cálculo (MetricsService)

### Principio: Única Fuente de Verdad

```
┌─────────────────────────────────────────────────────────────┐
│                     MetricsService                          │
│─────────────────────────────────────────────────────────────│
│  + calculateGlobalScore(results): int                       │
│  + getExamStatistics(exam, filters): ExamStatistics        │
│  + getAreaStatistics(exam, area, filters): AreaStatistics  │
│  + getTopPerformers(exam, area, limit): Collection         │
│  + getGroupComparison(exam): GroupComparison               │
│  + getPiarComparison(exam): PiarComparison                 │
│  + getDistribution(exam, area): Distribution               │
└─────────────────────────────────────────────────────────────┘
                            │
                            │ consume
                            ▼
        ┌───────────────────┴───────────────────┐
        │                                       │
        ▼                                       ▼
┌───────────────┐                     ┌─────────────────┐
│ Filament      │                     │ HTML Report     │
│ Dashboard     │                     │ Generator       │
└───────────────┘                     └─────────────────┘
```

**Regla:** TODA la lógica de cálculo está en `MetricsService`. Las vistas NUNCA calculan.

### DTOs de Retorno

```php
// app/DTOs/ExamStatistics.php
class ExamStatistics {
    public int $totalStudents;
    public int $piarCount;
    public int $nonPiarCount;
    public float $globalAverage;
    public float $globalStdDev;
    public array $areaStatistics; // AreaStatistics[]
}

// app/DTOs/AreaStatistics.php
class AreaStatistics {
    public string $area;
    public float $average;
    public float $stdDev;
    public int $min;
    public int $max;
    public int $count;
}
```

---

## 👥 Datos de Prueba (SEEDING OBLIGATORIO)

### Distribución Requerida

| Año | Grado | Grupos | Estudiantes | Status |
|-----|-------|--------|-------------|--------|
| 2025 | 11 | 11-1, 11-2, 11-3 | 80 (≈27 por grupo) | ACTIVE |
| 2025 | 10 | 10-1, 10-2, 10-3 | 80 (≈27 por grupo) | ACTIVE |
| 2024 | 11 | 11-1, 11-2 | 50 (≈25 por grupo) | GRADUATED |

### PIAR

- **10-15%** de todas las matrículas deben tener `is_piar = true`
- Distribución aleatoria entre grupos

### Examen de Prueba

```php
Exam::create([
    'academic_year_id' => /* 2025 */,
    'name' => 'Simulacro Único 2025',
    'type' => 'SIMULACRO',
    'date' => '2025-03-15',
]);
```

### Resultados de Prueba

- Generar resultados aleatorios para el examen de prueba.
- Distribución normal: media ≈ 60, desviación ≈ 15 por área.
- **5% de estudiantes PIAR** deben tener `ingles = NULL`.

---

## 📦 Entregables del Agente

| # | Entregable | Ubicación |
|---|------------|-----------|
| 1 | Migraciones | `database/migrations/` |
| 2 | Modelos Eloquent | `app/Models/` |
| 3 | Factories | `database/factories/` |
| 4 | Seeders | `database/seeders/` |
| 5 | MetricsService | `app/Services/MetricsService.php` |
| 6 | DTOs | `app/DTOs/` |
| 7 | Exports (Laravel-Excel) | `app/Exports/` |
| 8 | Imports (Laravel-Excel) | `app/Imports/` |
| 9 | Filament Resources | `app/Filament/Resources/` |
| 10 | Filament Actions | `app/Filament/Actions/` |
| 11 | Generador HTML | `app/Services/ReportGenerator.php` |
| 12 | Vista Blade del reporte | `resources/views/reports/exam.blade.php` |
| 13 | Assets embebidos | Alpine.js + Chart.js minificados |

---

## ✅ Criterio de Éxito del MVP

El sistema es correcto si permite afirmar:

> "Este es el informe completo del análisis de una prueba única (ICFES o simulacro), generado a partir de datos diligenciados por docentes, sobre una población académica persistente y confiable."

### Checklist de Validación

- [ ] Puedo crear años académicos desde el panel
- [ ] Puedo importar estudiantes y matrículas desde Excel
- [ ] Puedo crear estudiantes manualmente y el código se genera automáticamente
- [ ] Puedo crear matrículas manualmente
- [ ] Puedo crear un examen desde el panel
- [ ] Puedo exportar la plantilla de resultados filtrada por grado/grupo
- [ ] Puedo importar resultados y el global_score se calcula automáticamente
- [ ] Si reimporto resultados, se sobreescriben los anteriores
- [ ] Si hay errores en el Excel, se rechaza TODO con mensaje claro
- [ ] Puedo generar el reporte HTML y se descarga directamente
- [ ] El HTML funciona sin internet
- [ ] Los gráficos son interactivos
- [ ] Las métricas del reporte coinciden con las del panel (misma fuente)
- [ ] Los estudiantes PIAR sin inglés no afectan el promedio de inglés

---

## 🔧 Configuración del Proyecto

### .env (valores relevantes)

```env
APP_NAME="Sistema SABER"
APP_ENV=local
APP_LOCALE=es
APP_FALLBACK_LOCALE=es

DB_CONNECTION=sqlite
DB_DATABASE=/absolute/path/to/database.sqlite
```

### config/app.php

```php
'locale' => 'es',
'fallback_locale' => 'es',
'faker_locale' => 'es_CO',
'timezone' => 'America/Bogota',
```

---

## 📝 Notas Finales

1. **Laravel Boost** debe estar instalado para asistencia de IA durante el desarrollo.
2. **No crear autenticación** en esta fase.
3. **No anticipar funcionalidades futuras** (históricos, PDF, etc.).
4. **Seguir PSR-12** con Laravel Pint.
5. **Todos los textos de UI** en español colombiano.
