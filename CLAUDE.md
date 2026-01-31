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
- Competencias: Identificar y entender, Reflexionar y evaluar, Comprender cómo se articulan
- Tipos de texto: Continuo, Discontinuo, Mixto

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

| Feature | Estado | Fecha |
|---------|--------|-------|
| Feature 1: MVP Base | ✅ Completado | 2026-01-29 |
| Feature 2: Análisis Detallado | 🔄 En desarrollo | 2026-01-30 |
