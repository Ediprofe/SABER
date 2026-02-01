# 📘 DOCUMENTO DE REQUERIMIENTOS — SISTEMA SABER (ANÁLISIS ICFES)

## 🧠 Rol del Agente

Actúa como **Arquitecto de Software Educativo Senior y Desarrollador Laravel Experto**, con experiencia en análisis estadístico académico tipo ICFES (SABER).

Debes ejecutar **exactamente** lo especificado.
No inventes reglas, no simplifiques, no anticipes fases futuras.

---

# 🏁 FEATURE 1: MVP BASE (COMPLETADO ✅)

> Esta sección documenta el MVP original que ya está implementado y funcionando.

## 🎯 Propósito del MVP

Construir un **Producto Mínimo Viable (MVP)** que permita:

- Analizar **UNA prueba única** (Simulacro o ICFES).
- Para **una población de estudiantes generada y persistida en el sistema**.
- Flujo docente:
  1. El sistema **exporta un Excel plantilla**.
  2. El docente **diligencia puntajes**.
  3. El sistema **importa / sobreescribe resultados**.
  4. El sistema **genera un informe HTML interactivo OFFLINE**.

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

### ❌ Prohibiciones técnicas

- NO SPA
- NO React/Vue
- NO dependencias CDN en el HTML final (embeber todo)
- NO Livewire fuera de Filament

---

## 🧩 Modelo de Datos BASE

### Diagrama de Relaciones (MVP)

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

### Tablas Existentes

1. **students** - Identidad permanente del estudiante
2. **academic_years** - Años académicos
3. **enrollments** - Matrículas anuales (is_piar vive aquí)
4. **exams** - Definición de exámenes
5. **exam_results** - Resultados por área (5 áreas + global_score)

### Fórmula de Puntaje Global

```php
global_score = round(((lectura + matematicas + sociales + naturales) * 3 + ingles) / 13 * 5)
```

---

# 🆕 FEATURE 2: ANÁLISIS POR COMPETENCIAS Y COMPONENTES

> **Estado:** PENDIENTE DE IMPLEMENTACIÓN
> **Prioridad:** Alta
> **Dependencia:** Feature 1 (MVP) debe estar completo

---

## 🎯 Objetivo de la Feature

Extender el sistema de análisis para incluir **desglose opcional por competencias, componentes, tipos de texto y partes**, según el área evaluada. Esta información es **adicional y opcional** a los puntajes por área ya existentes.

---

## 📋 Estructura por Área

Cada área tiene su propia estructura de análisis detallado:

| Área | Dimensión 1 | Dimensión 2 |
|------|-------------|-------------|
| **Ciencias Naturales** | Competencias | Componentes |
| **Matemáticas** | Competencias | Componentes |
| **Ciencias Sociales** | Competencias | Componentes |
| **Lectura Crítica** | Competencias | Tipos de Texto |
| **Inglés** | Partes | — |

### Ejemplos de Configuración (REFERENCIA, NO OBLIGATORIOS)

**Ciencias Naturales:**
- Competencias: Uso del conocimiento, Explicación de fenómenos, Indagación
- Componentes: Vivo, Químico, Físico, CTS

**Matemáticas:**
- Competencias: Interpretación y representación, Formulación y ejecución, Argumentación
- Componentes: Numérico-variacional, Geométrico-métrico, Aleatorio

**Sociales:**
- Competencias: Pensamiento social, Interpretación y análisis de perspectivas, Pensamiento reflexivo y sistémico
- Componentes: Historia, Geografía, Ético-político

**Lectura Crítica:**
- Competencias: Identificar y ubicar, Relacionar e interpretar, Evaluar y reflexionar
- Tipos de texto: Continuo, Discontinuo, Literario, Informativo, Filosófico

**Inglés:**
- Partes: Parte 1, Parte 2, Parte 3, Parte 4, Parte 5, Parte 6, Parte 7

> ⚠️ **IMPORTANTE:** Estos son solo ejemplos. El usuario DEBE poder configurar cuántos y cuáles elementos usar para cada área.

---

## 🧩 Modelo de Datos EXTENDIDO

### Diagrama de Nuevas Tablas

```
┌─────────────────────────┐
│  exam_area_configs      │  ◄── Configuración por examen/área
│─────────────────────────│
│ id                      │
│ exam_id (FK)            │
│ area (enum)             │  lectura|matematicas|sociales|naturales|ingles
│ dimension1_name         │  "Competencias" | "Partes"
│ dimension2_name         │  "Componentes" | "Tipos de Texto" | NULL
│ created_at              │
│ updated_at              │
└───────────┬─────────────┘
            │
            │ hasMany
            ▼
┌─────────────────────────┐
│  exam_area_items        │  ◄── Items configurados (competencias, componentes, etc.)
│─────────────────────────│
│ id                      │
│ exam_area_config_id(FK) │
│ dimension               │  1 o 2 (dimension1 o dimension2)
│ name                    │  "Uso del conocimiento", "Vivo", etc.
│ order                   │  Orden de aparición
│ created_at              │
│ updated_at              │
└───────────┬─────────────┘
            │
            │ hasMany
            ▼
┌─────────────────────────┐
│  exam_detail_results    │  ◄── Resultados detallados por estudiante
│─────────────────────────│
│ id                      │
│ exam_result_id (FK)     │  Vincula con exam_results existente
│ exam_area_item_id (FK)  │  Vincula con el item (competencia/componente)
│ score                   │  Puntaje 0-100 (nullable)
│ created_at              │
│ updated_at              │
│                         │
│ UNIQUE(exam_result_id,  │
│        exam_area_item_id)│
└─────────────────────────┘
```

### Migraciones Requeridas

#### 1. Tabla `exam_area_configs`

```php
Schema::create('exam_area_configs', function (Blueprint $table) {
    $table->id();
    $table->foreignId('exam_id')->constrained()->cascadeOnDelete();
    $table->enum('area', ['lectura', 'matematicas', 'sociales', 'naturales', 'ingles']);
    $table->string('dimension1_name', 50);  // "Competencias", "Partes"
    $table->string('dimension2_name', 50)->nullable();  // "Componentes", "Tipos de Texto", NULL
    $table->timestamps();

    $table->unique(['exam_id', 'area']);  // Solo una config por área por examen
});
```

#### 2. Tabla `exam_area_items`

```php
Schema::create('exam_area_items', function (Blueprint $table) {
    $table->id();
    $table->foreignId('exam_area_config_id')->constrained()->cascadeOnDelete();
    $table->unsignedTinyInteger('dimension');  // 1 o 2
    $table->string('name', 100);  // "Uso del conocimiento", "Vivo", etc.
    $table->unsignedTinyInteger('order')->default(0);
    $table->timestamps();

    $table->unique(['exam_area_config_id', 'dimension', 'name']);
});
```

#### 3. Tabla `exam_detail_results`

```php
Schema::create('exam_detail_results', function (Blueprint $table) {
    $table->id();
    $table->foreignId('exam_result_id')->constrained()->cascadeOnDelete();
    $table->foreignId('exam_area_item_id')->constrained()->cascadeOnDelete();
    $table->unsignedTinyInteger('score')->nullable();  // 0-100
    $table->timestamps();

    $table->unique(['exam_result_id', 'exam_area_item_id']);
});
```

---

## 📋 Panel Administrativo Filament

### Nuevos Recursos/Acciones Requeridos

| Recurso/Acción | Tipo | Descripción |
|----------------|------|-------------|
| **ExamAreaConfigResource** | Inline en ExamResource | Configurar áreas dentro del formulario de examen |
| `ConfigureAreasAction` | Action en ExamResource | Modal para configurar competencias/componentes |
| `ExportDetailTemplateAction` | Action en ExamResource | Exportar plantilla con columnas de detalle |
| `ImportDetailResultsAction` | Action en ExamResource | Importar resultados detallados |

### Flujo de Configuración de Áreas

1. Usuario crea o edita un **Examen**
2. Ve botón **"Configurar Análisis Detallado"** (opcional)
3. Modal muestra las 5 áreas con:
   - Toggle para activar/desactivar análisis detallado
   - Si activa:
     - Input para nombre de Dimensión 1 (default según área)
     - Lista editable de items de Dimensión 1 (agregar/eliminar)
     - Input para nombre de Dimensión 2 (si aplica al área)
     - Lista editable de items de Dimensión 2 (agregar/eliminar)
4. Guardar configuración

### Interfaz de Configuración (Wireframe Conceptual)

```
┌─────────────────────────────────────────────────────────────────────┐
│  Configurar Análisis Detallado - Simulacro Único 2025              │
├─────────────────────────────────────────────────────────────────────┤
│                                                                     │
│  ☑ Ciencias Naturales                                              │
│  ┌─────────────────────────────────────────────────────────────┐   │
│  │ Dimensión 1: [Competencias        ]                         │   │
│  │ Items:                                                       │   │
│  │   [Uso del conocimiento      ] [×]                          │   │
│  │   [Explicación de fenómenos  ] [×]                          │   │
│  │   [Indagación                ] [×]                          │   │
│  │   [+ Agregar competencia]                                    │   │
│  │                                                              │   │
│  │ Dimensión 2: [Componentes         ]                         │   │
│  │ Items:                                                       │   │
│  │   [Vivo    ] [×]  [Químico ] [×]  [Físico ] [×]  [CTS] [×] │   │
│  │   [+ Agregar componente]                                     │   │
│  └─────────────────────────────────────────────────────────────┘   │
│                                                                     │
│  ☑ Matemáticas                                                     │
│  ┌─────────────────────────────────────────────────────────────┐   │
│  │ Dimensión 1: [Competencias        ]                         │   │
│  │ Items:                                                       │   │
│  │   [Interpretación y representación] [×]                     │   │
│  │   [Formulación y ejecución        ] [×]                     │   │
│  │   [+ Agregar competencia]                                    │   │
│  │                                                              │   │
│  │ Dimensión 2: [Componentes         ]                         │   │
│  │ Items:                                                       │   │
│  │   [Numérico-variacional] [×]  [Geométrico-métrico] [×]     │   │
│  │   [+ Agregar componente]                                     │   │
│  └─────────────────────────────────────────────────────────────┘   │
│                                                                     │
│  ☐ Ciencias Sociales (no configurado)                              │
│                                                                     │
│  ☑ Lectura Crítica                                                 │
│  ┌─────────────────────────────────────────────────────────────┐   │
│  │ Dimensión 1: [Competencias        ]                         │   │
│  │ Dimensión 2: [Tipos de Texto      ]                         │   │
│  │ ...                                                          │   │
│  └─────────────────────────────────────────────────────────────┘   │
│                                                                     │
│  ☑ Inglés                                                          │
│  ┌─────────────────────────────────────────────────────────────┐   │
│  │ Dimensión 1: [Partes              ]                         │   │
│  │ Items:                                                       │   │
│  │   [Parte 1] [×]  [Parte 2] [×]  [Parte 3] [×]  ...         │   │
│  │ (Sin Dimensión 2 para Inglés)                                │   │
│  └─────────────────────────────────────────────────────────────┘   │
│                                                                     │
│                              [Cancelar]  [Guardar Configuración]   │
└─────────────────────────────────────────────────────────────────────┘
```

---

## 📥 Exportación / Importación Excel EXTENDIDA

### Plantilla de Resultados Detallados

**Estructura del archivo:** `plantilla_resultados_detallado_{exam}_{grado}.xlsx`

**Formato OBLIGATORIO: Una hoja por grupo**

```
Libro Excel:
├── Hoja "11-1" (estudiantes del grupo 11-1)
├── Hoja "11-2" (estudiantes del grupo 11-2)
└── Hoja "11-3" (estudiantes del grupo 11-3)
```

El nombre de cada hoja DEBE ser exactamente el nombre del grupo (ej: "11-1", "10-2").

**Columnas de cada hoja:**

| Col | Campo | Editable | Notas |
|-----|-------|----------|-------|
| A | `codigo` | ❌ | Código estudiante |
| B | `nombre` | ❌ | Nombre completo |
| C | `grupo` | ❌ | Grupo |
| D | `es_piar` | ❌ | "SI" o "NO" |
| E | `lectura` | ✅ | Puntaje 0-100 |
| F | `matematicas` | ✅ | Puntaje 0-100 |
| G | `sociales` | ✅ | Puntaje 0-100 |
| H | `naturales` | ✅ | Puntaje 0-100 |
| I | `ingles` | ✅ | Puntaje 0-100 |
| J+ | *Columnas dinámicas* | ✅ | Según configuración del área |

**Columnas dinámicas (ejemplo con Naturales configurado):**

| Col | Campo Generado | Área | Dimensión |
|-----|----------------|------|-----------|
| J | `nat_comp_uso_conocimiento` | Naturales | Competencia |
| K | `nat_comp_explicacion` | Naturales | Competencia |
| L | `nat_comp_indagacion` | Naturales | Competencia |
| M | `nat_comp_vivo` | Naturales | Componente |
| N | `nat_comp_quimico` | Naturales | Componente |
| O | `nat_comp_fisico` | Naturales | Componente |
| P | `nat_comp_cts` | Naturales | Componente |

**Convención de nombres de columnas:**

```
{area_prefix}_{dimension_prefix}_{item_slug}
```

| Área | Prefix |
|------|--------|
| Lectura | `lec` |
| Matemáticas | `mat` |
| Sociales | `soc` |
| Naturales | `nat` |
| Inglés | `ing` |

| Dimensión | Prefix |
|-----------|--------|
| Competencia | `comp` |
| Componente | `cmpn` |
| Tipo Texto | `txt` |
| Parte | `part` |

**Ejemplo completo de encabezados:**

```
codigo | nombre | grupo | es_piar | lectura | matematicas | sociales | naturales | ingles | nat_comp_uso_conocimiento | nat_comp_explicacion | nat_comp_indagacion | nat_cmpn_vivo | nat_cmpn_quimico | nat_cmpn_fisico | nat_cmpn_cts | mat_comp_interpretacion | mat_comp_formulacion | ing_part_1 | ing_part_2 | ing_part_3
```

### Validaciones de Importación

| Validación | Comportamiento |
|------------|----------------|
| Columna de detalle no existe en config | ⚠️ Ignorar columna (warning) |
| Puntaje de detalle fuera de 0-100 | ❌ Rechazar archivo |
| Columna esperada faltante | ⚠️ Importar sin ese dato |
| Hoja con nombre que no es grupo válido | ⚠️ Ignorar hoja (warning) |

---

## 📊 Métricas y Reporte HTML EXTENDIDO

### Nuevas Secciones del Reporte

El reporte HTML debe incluir **secciones adicionales** cuando el examen tenga análisis detallado configurado:

#### 🟩 Sección 6 — Análisis por Competencias y Componentes (POR ÁREA)

**Para cada área con configuración activa, mostrar:**

##### 6.1 Estadísticas por Dimensión 1 (ej: Competencias)

| Métrica | Item 1 | Item 2 | Item 3 | ... |
|---------|--------|--------|--------|-----|
| Promedio | ✅ | ✅ | ✅ | |
| Desv. Estándar | ✅ | ✅ | ✅ | |
| Mín | ✅ | ✅ | ✅ | |
| Máx | ✅ | ✅ | ✅ | |

##### 6.2 Estadísticas por Dimensión 2 (ej: Componentes)

(Misma estructura que 6.1)

##### 6.3 Comparativo PIAR vs No-PIAR

| Item | Promedio PIAR | Promedio No-PIAR | Diferencia |
|------|---------------|------------------|------------|
| Uso del conocimiento | 58.2 | 62.4 | -4.2 |
| Explicación | 55.1 | 60.8 | -5.7 |
| ... | | | |

##### 6.4 Desglose por Grupo

| Grupo | Item 1 | Item 2 | Item 3 | ... |
|-------|--------|--------|--------|-----|
| 11-1 | 62.3 | 58.4 | 65.1 | |
| 11-2 | 59.8 | 61.2 | 63.4 | |
| 11-3 | 64.1 | 57.9 | 62.8 | |

#### 🟩 Sección 7 — Gráficos de Análisis Detallado

| Gráfico | Tipo | Descripción |
|---------|------|-------------|
| Promedios por Competencia | Barras | Una barra por competencia del área |
| Promedios por Componente | Barras | Una barra por componente del área |
| Comparativo PIAR (detalle) | Barras agrupadas | PIAR vs No-PIAR por cada item |
| Promedios por Grupo (detalle) | Barras agrupadas | Grupos en X, items como series |

**Filtros aplicables a todas las métricas detalladas:**
- ☑ Incluir PIAR / ☐ Excluir PIAR
- Dropdown de grupo específico
- Toggle por dimensión (mostrar solo competencias / solo componentes)

### Estructura del Reporte HTML Completo

```
┌─────────────────────────────────────────────────────────────┐
│ INFORME DE ANÁLISIS - [Nombre Examen]                       │
├─────────────────────────────────────────────────────────────┤
│                                                             │
│ [Header con metadatos del examen]                           │
│                                                             │
│ ═══════════════════════════════════════════════════════════ │
│ SECCIÓN 1: KPIs PRINCIPALES (existente)                     │
│ ═══════════════════════════════════════════════════════════ │
│                                                             │
│ ═══════════════════════════════════════════════════════════ │
│ SECCIÓN 2: LISTADO DE ESTUDIANTES (existente)               │
│ ═══════════════════════════════════════════════════════════ │
│                                                             │
│ ═══════════════════════════════════════════════════════════ │
│ SECCIÓN 3: ESTADÍSTICAS POR ÁREA (existente)                │
│ ═══════════════════════════════════════════════════════════ │
│                                                             │
│ ═══════════════════════════════════════════════════════════ │
│ SECCIÓN 4: TOP PERFORMERS (existente)                       │
│ ═══════════════════════════════════════════════════════════ │
│                                                             │
│ ═══════════════════════════════════════════════════════════ │
│ SECCIÓN 5: GRÁFICOS GENERALES (existente)                   │
│ ═══════════════════════════════════════════════════════════ │
│                                                             │
│ ═══════════════════════════════════════════════════════════ │
│ SECCIÓN 6: ANÁLISIS DETALLADO POR ÁREA (NUEVA - OPCIONAL)   │
│ ═══════════════════════════════════════════════════════════ │
│                                                             │
│  ┌─ PESTAÑA: Ciencias Naturales ─────────────────────────┐  │
│  │  [Estadísticas Competencias] [Estadísticas Componentes]│  │
│  │  [Comparativo PIAR] [Desglose por Grupo]              │  │
│  │  [Gráficos]                                            │  │
│  └───────────────────────────────────────────────────────┘  │
│                                                             │
│  ┌─ PESTAÑA: Matemáticas ────────────────────────────────┐  │
│  │  ...                                                   │  │
│  └───────────────────────────────────────────────────────┘  │
│                                                             │
│  ┌─ PESTAÑA: Lectura Crítica ────────────────────────────┐  │
│  │  ...                                                   │  │
│  └───────────────────────────────────────────────────────┘  │
│                                                             │
│  (Solo se muestran pestañas de áreas configuradas)          │
│                                                             │
└─────────────────────────────────────────────────────────────┘
```

---

## ⚙️ Extensión del MetricsService

### Nuevos Métodos Requeridos

```php
class MetricsService
{
    // ... métodos existentes ...

    /**
     * Obtiene estadísticas detalladas por item de un área.
     */
    public function getDetailStatistics(
        Exam $exam,
        string $area,
        ?array $filters = null
    ): array;

    /**
     * Comparativo PIAR vs No-PIAR por items detallados.
     */
    public function getDetailPiarComparison(
        Exam $exam,
        string $area,
        ?array $filters = null
    ): array;

    /**
     * Desglose por grupo para items detallados.
     */
    public function getDetailGroupComparison(
        Exam $exam,
        string $area,
        ?array $filters = null
    ): array;

    /**
     * Verifica si un examen tiene configuración de análisis detallado.
     */
    public function hasDetailConfig(Exam $exam, ?string $area = null): bool;

    /**
     * Obtiene la configuración de análisis detallado de un examen.
     */
    public function getDetailConfig(Exam $exam): Collection;
}
```

### Nuevos DTOs

```php
// app/DTOs/DetailItemStatistics.php
class DetailItemStatistics {
    public string $area;
    public int $dimension;       // 1 o 2
    public string $dimensionName; // "Competencias", "Componentes", etc.
    public string $itemName;      // "Uso del conocimiento", etc.
    public float $average;
    public float $stdDev;
    public int $min;
    public int $max;
    public int $count;
}

// app/DTOs/DetailAreaStatistics.php
class DetailAreaStatistics {
    public string $area;
    public string $areaLabel;  // "Ciencias Naturales"
    public array $dimension1;  // DetailItemStatistics[]
    public ?array $dimension2; // DetailItemStatistics[] | null
}
```

---

## 📦 Entregables de Feature 2

| # | Entregable | Ubicación |
|---|------------|-----------|
| 1 | Migraciones nuevas (3) | `database/migrations/` |
| 2 | Modelos nuevos (3) | `app/Models/ExamAreaConfig.php`, `ExamAreaItem.php`, `ExamDetailResult.php` |
| 3 | Factories nuevos (3) | `database/factories/` |
| 4 | Seeder actualizado | `database/seeders/DatabaseSeeder.php` |
| 5 | MetricsService extendido | `app/Services/MetricsService.php` |
| 6 | DTOs nuevos (2) | `app/DTOs/` |
| 7 | Export actualizado | `app/Exports/ResultsTemplateExport.php` |
| 8 | Import nuevo | `app/Imports/DetailResultsImport.php` |
| 9 | Filament Actions nuevas | `ConfigureAreasAction`, `ExportDetailTemplateAction`, `ImportDetailResultsAction` |
| 10 | ReportGenerator extendido | `app/Services/ReportGenerator.php` |
| 11 | Vista Blade extendida | `resources/views/reports/exam.blade.php` |

---

## ✅ Criterios de Aceptación - Feature 2

### Definition of Done

- [ ] Puedo crear un examen SIN configurar análisis detallado (funciona igual que antes)
- [ ] Puedo configurar análisis detallado para una o más áreas
- [ ] Puedo definir competencias/componentes personalizados por área
- [ ] Al exportar plantilla, se incluyen columnas dinámicas según configuración
- [ ] El Excel exportado tiene una hoja por grupo
- [ ] Puedo importar resultados detallados desde Excel
- [ ] Si un área no tiene configuración, sus columnas de detalle se ignoran
- [ ] El reporte HTML muestra secciones de análisis detallado solo si hay datos
- [ ] Las métricas de detalle tienen filtros PIAR / No-PIAR
- [ ] Las métricas de detalle se desglosan por grupo
- [ ] Los gráficos de detalle son interactivos
- [ ] El HTML sigue funcionando 100% offline
- [ ] No se rompe ninguna funcionalidad del MVP existente

### Casos de Prueba Obligatorios

1. **Examen sin configuración detallada:** Sistema funciona exactamente igual que antes
2. **Examen con solo Naturales configurado:** Solo aparece sección de Naturales en reporte
3. **Examen con todas las áreas configuradas:** Todas las pestañas visibles
4. **Importación parcial:** Solo algunas columnas de detalle tienen datos
5. **Filtro PIAR en detalle:** Métricas se recalculan correctamente
6. **Múltiples grupos:** Desglose correcto por cada grupo

---

## 🔧 Notas de Implementación

1. **Retrocompatibilidad:** El sistema DEBE seguir funcionando para exámenes sin configuración detallada.

2. **Columnas dinámicas:** La generación de nombres de columnas debe ser determinística y reversible (para el import).

3. **Performance:** Las consultas de métricas detalladas deben usar eager loading apropiado.

4. **UI en español:** Todos los labels en español colombiano.

5. **Nombres de encabezados Excel en español:**
   - `code` → `codigo`
   - `first_name` → `nombre`
   - `last_name` → `apellido`
   - `group` → `grupo`
   - `is_piar` → `es_piar`

---

## 📝 Historial de Features

| Feature | Estado | Fecha | Rama |
|---------|--------|-------|------|
| Feature 1: MVP Base | ✅ Completado | 2026-01-29 | main |
| Feature 2: Análisis Detallado | ✅ Completado | 2026-01-30 | main |
| Feature 3: Importación Zipgrade | 🔄 En desarrollo | 2026-02-01 | feature/zipgrade-prototype |

---

# 🆕 FEATURE 3: IMPORTACIÓN ZIPGRADE (PROTOTIPO)

> **Estado:** EN DESARROLLO
> **Rama:** `feature/zipgrade-prototype`
> **Prioridad:** Alta
> **Tipo:** Prototipo para validación

---

## 🎯 Objetivo de la Feature

Crear un prototipo que permita importar datos directamente desde **Zipgrade** (plataforma de escaneo y calificación), eliminando el cálculo manual del docente y garantizando **ponderación correcta** de puntajes por número de preguntas.

---

## 📋 Problema que Resuelve

### Situación Actual (Problemática)

```
Zipgrade → Docente calcula manualmente → Excel plantilla → SABER
                      ↑
              ERROR DE PONDERACIÓN
```

**El error:** Si Sesión 1 tiene 2 preguntas de "Químico" y Sesión 2 tiene 10, promediar las sesiones da peso 50%-50% cuando debería ser proporcional (2/12 vs 10/12).

### Solución Propuesta

```
Zipgrade → Excel crudo → SABER (calcula todo) → Reporte
```

**Ventaja:** Ponderación correcta = Σ(puntos obtenidos) / Σ(puntos posibles) × 100

---

## 🔑 Cambios Clave

### 1. Identificador de Estudiante

| Antes | Después |
|-------|---------|
| `code` = STU-2026-00001 | `document_id` = 1234567890 |

El documento de identidad (solo números) es el identificador único del estudiante.

### 2. Fuente de Datos

| Antes | Después |
|-------|---------|
| Plantilla Excel manual | Excel exportado de Zipgrade |

### 3. Sesiones de Examen

| Antes | Después |
|-------|---------|
| Una importación por examen | 1 o 2 sesiones por examen |

---

## 🧩 Modelo de Datos

### Diagrama de Nuevas Tablas

```
┌─────────────────────┐
│   tag_hierarchy     │  ← Configuración de jerarquía de tags
│─────────────────────│
│ id                  │
│ tag_name            │  "Químico", "Ciencias", "Uso comprensivo"
│ tag_type            │  area | competencia | componente | tipo_texto | parte
│ parent_area         │  NULL si es área, nombre del área si es hijo
│ created_at          │
│ updated_at          │
└─────────────────────┘

┌─────────────────────┐
│   exam_sessions     │  ← Sesiones de un examen
│─────────────────────│
│ id                  │
│ exam_id (FK)        │
│ session_number      │  1 o 2
│ name                │  "Sesión 1", "Sesión 2"
│ zipgrade_quiz_name  │  Nombre del quiz en Zipgrade
│ total_questions     │  Calculado después de importar
│ created_at          │
│ updated_at          │
└─────────────────────┘

┌─────────────────────┐
│  zipgrade_imports   │  ← Registro de importaciones
│─────────────────────│
│ id                  │
│ session_id (FK)     │
│ filename            │
│ total_rows          │
│ status              │  pending | processing | completed | error
│ error_message       │  NULL o mensaje de error
│ created_at          │
│ updated_at          │
└─────────────────────┘

┌─────────────────────┐
│   exam_questions    │  ← Preguntas detectadas por sesión
│─────────────────────│
│ id                  │
│ session_id (FK)     │
│ question_number     │  1, 2, 3...
│ created_at          │
│ updated_at          │
└─────────────────────┘

┌─────────────────────┐
│  question_tags      │  ← Tags asignados a cada pregunta
│─────────────────────│
│ id                  │
│ question_id (FK)    │
│ tag_hierarchy_id(FK)│  Referencia a la jerarquía
│ inferred_area       │  Área inferida (si el tag es hijo)
└─────────────────────┘

┌─────────────────────┐
│  student_answers    │  ← Respuestas de cada estudiante
│─────────────────────│
│ id                  │
│ question_id (FK)    │
│ enrollment_id (FK)  │
│ is_correct          │  boolean (true/false)
│ created_at          │
│ updated_at          │
│                     │
│ UNIQUE(question_id, │
│        enrollment_id)│
└─────────────────────┘
```

### Migración: Modificar Students

```php
// Agregar documento y hacer code nullable (para migración gradual)
Schema::table('students', function (Blueprint $table) {
    $table->string('document_id', 20)->nullable()->unique()->after('code');
});

// El code se mantiene por retrocompatibilidad con Features 1 y 2
// En Feature 3, document_id es el identificador principal
```

### Migraciones Nuevas

#### 1. Tabla `tag_hierarchy`

```php
Schema::create('tag_hierarchy', function (Blueprint $table) {
    $table->id();
    $table->string('tag_name', 100)->unique();
    $table->enum('tag_type', ['area', 'competencia', 'componente', 'tipo_texto', 'parte']);
    $table->string('parent_area', 50)->nullable();
    $table->timestamps();

    $table->index('tag_type');
    $table->index('parent_area');
});
```

#### 2. Tabla `exam_sessions`

```php
Schema::create('exam_sessions', function (Blueprint $table) {
    $table->id();
    $table->foreignId('exam_id')->constrained()->cascadeOnDelete();
    $table->unsignedTinyInteger('session_number'); // 1 o 2
    $table->string('name', 50); // "Sesión 1"
    $table->string('zipgrade_quiz_name', 150)->nullable();
    $table->unsignedSmallInteger('total_questions')->default(0);
    $table->timestamps();

    $table->unique(['exam_id', 'session_number']);
});
```

#### 3. Tabla `zipgrade_imports`

```php
Schema::create('zipgrade_imports', function (Blueprint $table) {
    $table->id();
    $table->foreignId('exam_session_id')->constrained('exam_sessions')->cascadeOnDelete();
    $table->string('filename', 255);
    $table->unsignedInteger('total_rows')->default(0);
    $table->enum('status', ['pending', 'processing', 'completed', 'error'])->default('pending');
    $table->text('error_message')->nullable();
    $table->timestamps();
});
```

#### 4. Tabla `exam_questions`

```php
Schema::create('exam_questions', function (Blueprint $table) {
    $table->id();
    $table->foreignId('exam_session_id')->constrained('exam_sessions')->cascadeOnDelete();
    $table->unsignedSmallInteger('question_number');
    $table->timestamps();

    $table->unique(['exam_session_id', 'question_number']);
});
```

#### 5. Tabla `question_tags`

```php
Schema::create('question_tags', function (Blueprint $table) {
    $table->id();
    $table->foreignId('exam_question_id')->constrained('exam_questions')->cascadeOnDelete();
    $table->foreignId('tag_hierarchy_id')->constrained('tag_hierarchy')->cascadeOnDelete();
    $table->string('inferred_area', 50)->nullable();
    $table->timestamps();

    $table->unique(['exam_question_id', 'tag_hierarchy_id']);
});
```

#### 6. Tabla `student_answers`

```php
Schema::create('student_answers', function (Blueprint $table) {
    $table->id();
    $table->foreignId('exam_question_id')->constrained('exam_questions')->cascadeOnDelete();
    $table->foreignId('enrollment_id')->constrained()->cascadeOnDelete();
    $table->boolean('is_correct')->default(false);  // true si EarnedPoints > 0
    $table->timestamps();

    $table->unique(['exam_question_id', 'enrollment_id']);
    $table->index('enrollment_id');
});
```

**Lógica de importación:**
```php
$isCorrect = (float) str_replace(',', '.', $row['EarnedPoints']) > 0;
```

---

## 📥 Formato de Entrada: Excel Zipgrade (Tags)

### Estructura del Archivo

| Columna | Campo | Uso |
|---------|-------|-----|
| A | Tag | Nombre del tag (área, competencia, componente) |
| B | StudentFirstName | Nombre del estudiante |
| C | StudentLastName | Apellido del estudiante |
| D | StudentID | **Documento de identidad** (el docente ingresa el documento aquí) |
| E | StudentExt | No usado |
| F | QuizName | Nombre del quiz |
| G | TagType | Siempre "question" |
| H | QuestionNum | Número de pregunta |
| I | EarnedPoints | Puntos obtenidos (0 o 0.334) |
| J | PossiblePoints | Puntos posibles (0.334) |

**IMPORTANTE:** El campo `StudentID` de Zipgrade contendrá el documento de identidad del estudiante (solo números). Este es el campo que se usará para hacer match con `document_id` en la tabla `students`.

### Interpretación de Puntos (REGLA SIMPLIFICADA)

| EarnedPoints | Interpretación |
|--------------|----------------|
| `> 0` (ej: 0.334) | Pregunta **CORRECTA** (1 punto) |
| `= 0` | Pregunta **INCORRECTA** (0 puntos) |

**NO se usan los decimales de Zipgrade.** Solo se determina si la pregunta está correcta o incorrecta.

### Ejemplo de Datos

```
Tag                    | StudentFirstName | StudentLastName | StudentID  | StudentExt | QuizName        | TagType  | QuestionNum | EarnedPoints | PossiblePoints
Ciencias               | SALOMÉ           | ACEVEDO OCAMPO  | 1234567890 |            | La materia Q11  | question | 1           | 0,334        | 0,334
Uso comprensivo...     | SALOMÉ           | ACEVEDO OCAMPO  | 1234567890 |            | La materia Q11  | question | 1           | 0,334        | 0,334
Químico                | SALOMÉ           | ACEVEDO OCAMPO  | 1234567890 |            | La materia Q11  | question | 1           | 0,334        | 0,334
Ciencias               | SALOMÉ           | ACEVEDO OCAMPO  | 1234567890 |            | La materia Q11  | question | 2           | 0            | 0,334
...
```

**Nota:** Una pregunta genera múltiples filas (una por cada tag asignado).

---

## 🔄 Flujo de Importación

### Paso 1: Crear/Seleccionar Examen

```
┌─────────────────────────────────────────────────────────────────────┐
│  Exámenes → Crear Examen                                            │
├─────────────────────────────────────────────────────────────────────┤
│                                                                     │
│  Nombre: [Simulacro ICFES Marzo 2025        ]                      │
│  Tipo:   [SIMULACRO ▼]                                             │
│  Fecha:  [2025-03-15]                                              │
│                                                                     │
│  Número de Sesiones: [2 ▼]                                         │
│                                                                     │
│                                        [Cancelar]  [Crear Examen]   │
└─────────────────────────────────────────────────────────────────────┘
```

### Paso 2: Importar Sesiones

```
┌─────────────────────────────────────────────────────────────────────┐
│  Examen: Simulacro ICFES Marzo 2025                                │
├─────────────────────────────────────────────────────────────────────┤
│                                                                     │
│  Sesiones del Examen:                                               │
│                                                                     │
│  ┌─────────────────────────────────────────────────────────────┐   │
│  │ Sesión 1                                    [Importar Excel] │   │
│  │ Estado: ⚪ Sin importar                                      │   │
│  │ Preguntas: —                                                 │   │
│  └─────────────────────────────────────────────────────────────┘   │
│                                                                     │
│  ┌─────────────────────────────────────────────────────────────┐   │
│  │ Sesión 2                                    [Importar Excel] │   │
│  │ Estado: ⚪ Sin importar                                      │   │
│  │ Preguntas: —                                                 │   │
│  └─────────────────────────────────────────────────────────────┘   │
│                                                                     │
└─────────────────────────────────────────────────────────────────────┘
```

### Paso 3: Asistente de Importación (Tags Nuevos)

```
┌─────────────────────────────────────────────────────────────────────┐
│  Importar Excel Zipgrade - Sesión 1                                │
├─────────────────────────────────────────────────────────────────────┤
│                                                                     │
│  ✅ Archivo cargado: zipgrade_sesion1.xlsx (48,320 filas)          │
│                                                                     │
│  ⚠️ Se detectaron 5 tags nuevos que necesitan clasificación:       │
│                                                                     │
│  ┌──────────────────────────┬─────────────────┬──────────────────┐ │
│  │ Tag                      │ Tipo            │ Área padre       │ │
│  ├──────────────────────────┼─────────────────┼──────────────────┤ │
│  │ Ciencias                 │ [Área ▼]        │ —                │ │
│  │ Químico                  │ [Componente ▼]  │ [Ciencias ▼]     │ │
│  │ Uso comprensivo...       │ [Competencia ▼] │ [Ciencias ▼]     │ │
│  │ Matemáticas              │ [Área ▼]        │ —                │ │
│  │ Interpretación...        │ [Competencia ▼] │ [Matemáticas ▼]  │ │
│  └──────────────────────────┴─────────────────┴──────────────────┘ │
│                                                                     │
│  ☑ Guardar esta configuración para futuros imports                 │
│                                                                     │
│                              [Cancelar]  [Continuar]                │
└─────────────────────────────────────────────────────────────────────┘
```

### Paso 4: Match de Estudiantes

```
┌─────────────────────────────────────────────────────────────────────┐
│  Verificar Estudiantes                                              │
├─────────────────────────────────────────────────────────────────────┤
│                                                                     │
│  ✅ 95 estudiantes encontrados por documento                       │
│  ⚠️ 5 estudiantes no encontrados en el sistema:                    │
│                                                                     │
│  ┌─────────────────┬────────────────┬───────────────────────────┐  │
│  │ Documento       │ Nombre         │ Acción                    │  │
│  ├─────────────────┼────────────────┼───────────────────────────┤  │
│  │ 1098765432      │ JUAN PÉREZ     │ [Crear estudiante ▼]      │  │
│  │ 1087654321      │ MARÍA GÓMEZ    │ [Vincular existente ▼]    │  │
│  │ ...             │                │                           │  │
│  └─────────────────┴────────────────┴───────────────────────────┘  │
│                                                                     │
│                              [Cancelar]  [Importar]                 │
└─────────────────────────────────────────────────────────────────────┘
```

### Paso 5: Confirmación

```
┌─────────────────────────────────────────────────────────────────────┐
│  ✅ Importación Completada                                          │
├─────────────────────────────────────────────────────────────────────┤
│                                                                     │
│  Sesión 1 importada exitosamente:                                   │
│                                                                     │
│  • Estudiantes: 100                                                 │
│  • Preguntas: 120                                                   │
│  • Tags procesados: 15                                              │
│  • Respuestas registradas: 12,000                                   │
│                                                                     │
│  Puede importar la Sesión 2 cuando esté lista.                     │
│                                                                     │
│                                        [Ir a Sesión 2]  [Cerrar]   │
└─────────────────────────────────────────────────────────────────────┘
```

---

## 📊 Cálculo de Puntajes

### Regla de Correcto/Incorrecto

```
Si EarnedPoints > 0 → Pregunta CORRECTA (cuenta como 1)
Si EarnedPoints = 0 → Pregunta INCORRECTA (cuenta como 0)
```

### Fórmula por Tag (Competencia, Componente, Tipo de Texto, Parte)

```
Puntaje(tag) = (Preguntas correctas con ese tag / Total preguntas con ese tag) × 100
```

### Fórmula por Área

```
Puntaje(área) = (Preguntas correctas del área / Total preguntas del área) × 100
```

### Ejemplo: Componente "Químico"

**Sesión 1 (2 preguntas de Químico):**
- Q1: EarnedPoints = 0.334 → ✓ Correcta
- Q4: EarnedPoints = 0 → ✗ Incorrecta
- Subtotal: 1 correcta / 2 total

**Sesión 2 (10 preguntas de Químico):**
- Q2: ✓ Correcta
- Q5: ✓ Correcta
- Q8: ✗ Incorrecta
- ... (7 más: 5 correctas, 2 incorrectas)
- Subtotal: 7 correctas / 10 total

**Cálculo CORRECTO (combinando sesiones):**
```
Químico = (1 + 7) / (2 + 10) × 100 = 8/12 × 100 = 66.7%
```

### Fórmula del Puntaje Global (OBLIGATORIA)

El puntaje global se calcula con la misma fórmula del MVP, usando los puntajes por área (0-100):

```php
global_score = round(((lectura + matematicas + sociales + naturales) * 3 + ingles) / 13 * 5)
```

Donde:
- `lectura` = Puntaje del área Lectura (0-100)
- `matematicas` = Puntaje del área Matemáticas (0-100)
- `sociales` = Puntaje del área Sociales (0-100)
- `naturales` = Puntaje del área Ciencias/Naturales (0-100)
- `ingles` = Puntaje del área Inglés (0-100)

**Resultado:** Puntaje global de 0 a 500 (escala ICFES)

### Ejemplo Completo de un Estudiante

| Área | Correctas | Total | Puntaje |
|------|-----------|-------|---------|
| Lectura | 28 | 41 | 68.3 |
| Matemáticas | 25 | 50 | 50.0 |
| Sociales | 30 | 45 | 66.7 |
| Naturales | 35 | 58 | 60.3 |
| Inglés | 40 | 66 | 60.6 |

```
Global = round(((68.3 + 50.0 + 66.7 + 60.3) * 3 + 60.6) / 13 * 5)
Global = round((245.3 * 3 + 60.6) / 13 * 5)
Global = round((735.9 + 60.6) / 13 * 5)
Global = round(796.5 / 13 * 5)
Global = round(61.27 * 5)
Global = round(306.3)
Global = 306
```

---

## ⚙️ ZipgradeMetricsService

### Nuevos Métodos

```php
class ZipgradeMetricsService
{
    /**
     * Calcula puntaje por tag para un estudiante.
     */
    public function getStudentTagScore(
        Enrollment $enrollment,
        Exam $exam,
        string $tagName
    ): float;

    /**
     * Calcula puntaje por área para un estudiante (combinando sesiones).
     */
    public function getStudentAreaScore(
        Enrollment $enrollment,
        Exam $exam,
        string $area
    ): float;

    /**
     * Obtiene estadísticas por tag para todo el examen.
     */
    public function getTagStatistics(
        Exam $exam,
        string $tagName,
        ?array $filters = null
    ): TagStatistics;

    /**
     * Obtiene comparativo PIAR vs No-PIAR por tag.
     */
    public function getTagPiarComparison(
        Exam $exam,
        string $tagName,
        ?array $filters = null
    ): array;

    /**
     * Obtiene desglose por grupo para un tag.
     */
    public function getTagGroupComparison(
        Exam $exam,
        string $tagName,
        ?array $filters = null
    ): array;

    /**
     * Infiere el área de una pregunta basándose en sus tags.
     */
    public function inferAreaFromTags(array $tagNames): ?string;
}
```

---

## 📋 Panel Administrativo Filament

### Nuevos Recursos

| Recurso | Tipo | Descripción |
|---------|------|-------------|
| **TagHierarchyResource** | CRUD | Gestionar jerarquía de tags |
| **ExamSessionResource** | Inline | Gestionar sesiones dentro de ExamResource |

### Nuevas Acciones en ExamResource

| Acción | Descripción |
|--------|-------------|
| `ImportZipgradeAction` | Importar Excel de Zipgrade por sesión |
| `ViewImportStatusAction` | Ver estado de importaciones |
| `GenerateZipgradeReportAction` | Generar reporte con datos de Zipgrade |

---

## 📦 Entregables del Prototipo

| # | Entregable | Ubicación | Prioridad |
|---|------------|-----------|-----------|
| 1 | Migración: `document_id` en students | `database/migrations/` | ✅ Alta |
| 2 | Migración: `tag_hierarchy` | `database/migrations/` | ✅ Alta |
| 3 | Migración: `exam_sessions` | `database/migrations/` | ✅ Alta |
| 4 | Migración: `zipgrade_imports` | `database/migrations/` | ✅ Alta |
| 5 | Migración: `exam_questions` | `database/migrations/` | ✅ Alta |
| 6 | Migración: `question_tags` | `database/migrations/` | ✅ Alta |
| 7 | Migración: `student_answers` | `database/migrations/` | ✅ Alta |
| 8 | Modelo `TagHierarchy` | `app/Models/` | ✅ Alta |
| 9 | Modelo `ExamSession` | `app/Models/` | ✅ Alta |
| 10 | Modelo `ZipgradeImport` | `app/Models/` | ✅ Alta |
| 11 | Modelo `ExamQuestion` | `app/Models/` | ✅ Alta |
| 12 | Modelo `QuestionTag` | `app/Models/` | ✅ Alta |
| 13 | Modelo `StudentAnswer` | `app/Models/` | ✅ Alta |
| 14 | Import `ZipgradeTagsImport` | `app/Imports/` | ✅ Alta |
| 15 | Service `ZipgradeMetricsService` | `app/Services/` | ✅ Alta |
| 16 | Resource `TagHierarchyResource` | `app/Filament/Resources/` | ✅ Alta |
| 17 | Action `ImportZipgradeAction` | `app/Filament/Actions/` | ✅ Alta |
| 18 | Vista de resultados (tabla simple) | `resources/views/` | ✅ Alta |
| 19 | Seeder con datos de prueba | `database/seeders/` | 🟡 Media |
| 20 | Reporte HTML completo | `resources/views/reports/` | ❌ Fuera de prototipo |

---

## ✅ Criterios de Aceptación del Prototipo

### Definition of Done

- [ ] Puedo agregar `document_id` a estudiantes existentes
- [ ] Puedo configurar la jerarquía de tags (CRUD en Filament)
- [ ] Puedo crear un examen con 1 o 2 sesiones
- [ ] Puedo importar un Excel de Zipgrade (formato tags)
- [ ] El sistema detecta tags nuevos y pide clasificación
- [ ] El sistema infiere el área si falta pero hay tag hijo conocido
- [ ] El sistema hace match de estudiantes por documento
- [ ] El sistema calcula puntajes correctamente (ponderados por # preguntas)
- [ ] Puedo ver los resultados calculados en una tabla simple
- [ ] Las 2 sesiones se combinan correctamente en los cálculos

### Casos de Prueba Obligatorios

1. **Importar sesión única:** 100 estudiantes, 120 preguntas
2. **Importar dos sesiones:** Combinación correcta de puntajes
3. **Tag sin área explícita:** Sistema infiere desde tag hijo
4. **Tag completamente nuevo:** Sistema pide clasificación
5. **Estudiante sin match:** Sistema permite crear o vincular
6. **Cálculo ponderado:** Verificar que 2 preguntas + 10 preguntas = 12 preguntas (no 50%-50%)

---

## 🔧 Notas de Implementación

1. **Retrocompatibilidad:** Esta feature es INDEPENDIENTE de Features 1 y 2. Coexisten en ramas separadas.

2. **document_id:** Se agrega como campo adicional, `code` se mantiene para no romper Features 1 y 2.

3. **Performance:** Con ~70,000 filas por sesión, usar:
   - Importación en chunks (1,000 filas)
   - Transacciones por chunk
   - Índices en `enrollment_id`, `exam_question_id`

4. **Decimales Zipgrade:** Los puntos usan coma como separador decimal (0,334). El import debe manejar esto.

5. **UI en español:** Todos los labels en español colombiano.

---

## 📝 Notas para el Agente Implementador

1. **Rama:** Trabajar en `feature/zipgrade-prototype`
2. **BD:** Crear migraciones nuevas, NO modificar las existentes de Feature 1/2
3. **Modelos:** Crear modelos nuevos, NO modificar Student (solo agregar `document_id`)
4. **Servicios:** Crear `ZipgradeMetricsService` SEPARADO de `MetricsService`
5. **Actualizar CHANGELOG.md** mientras avanzas
