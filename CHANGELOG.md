# 📝 CHANGELOG — Sistema SABER

> Registro incremental de cambios por feature.
> El agente implementador debe actualizar este documento en tiempo real.

---

## [Feature 3] Importación Zipgrade (Prototipo) — 2026-02-01

### Estado: ✅ COMPLETADO

### Rama: `feature/zipgrade-prototype`

---

### Tareas Completadas

- [x] Migración: agregar `document_id` a students (`2026_02_01_000001_add_document_id_to_students_table.php`)
- [x] Migración: crear `tag_hierarchy` (`2026_02_01_000002_create_tag_hierarchy_table.php`)
- [x] Migración: crear `exam_sessions` (`2026_02_01_000003_create_exam_sessions_table.php`)
- [x] Migración: crear `zipgrade_imports` (`2026_02_01_000004_create_zipgrade_imports_table.php`)
- [x] Migración: crear `exam_questions` (`2026_02_01_000005_create_exam_questions_table.php`)
- [x] Migración: crear `question_tags` (`2026_02_01_000006_create_question_tags_table.php`)
- [x] Migración: crear `student_answers` (`2026_02_01_000007_create_student_answers_table.php`)
- [x] Modelo `TagHierarchy` creado con relaciones
- [x] Modelo `ExamSession` creado con relaciones
- [x] Modelo `ZipgradeImport` creado con estados (pending/processing/completed/error)
- [x] Modelo `ExamQuestion` creado con relaciones a tags y respuestas
- [x] Modelo `QuestionTag` creado para vincular preguntas con jerarquía de tags
- [x] Modelo `StudentAnswer` creado con campo `is_correct` (EarnedPoints > 0)
- [x] Relaciones en modelo `Student` (document_id)
- [x] Relaciones en modelo `Exam` (sessions, getSession, hasSessions)
- [x] Import `ZipgradeTagsImport` creado con lógica de chunks y transacciones
- [x] Lógica de detección de tags nuevos en importación
- [x] Lógica de inferencia de área desde tags hijos
- [x] Lógica de match de estudiantes por document_id (creación automática si no existe)
- [x] `ZipgradeMetricsService` creado
- [x] `ZipgradeMetricsService::getStudentTagScore()` implementado
- [x] `ZipgradeMetricsService::getStudentAreaScore()` implementado
- [x] `ZipgradeMetricsService::getStudentGlobalScore()` implementado con fórmula ICFES
- [x] `ZipgradeMetricsService::getTagStatistics()` implementado
- [x] `ZipgradeMetricsService::getTagPiarComparison()` implementado
- [x] `ZipgradeMetricsService::inferAreaFromTags()` implementado
- [x] Resource `TagHierarchyResource` creado (CRUD completo en Filament)
- [x] Action `ImportZipgradeAction` implementada en ExamResource
- [x] Vista de gestión de sesiones (hasta 2 sesiones por examen)
- [x] Vista de resultados Zipgrade (tabla simple con filtros)
- [x] Soporte para 1 o 2 sesiones por examen
- [x] Combinación correcta de sesiones en cálculos (ponderación por # preguntas)
- [x] Manejo de decimales con coma (0,334 → convertir a 0.334)
- [x] Regla: EarnedPoints > 0 = Correcta (1), = 0 = Incorrecta (0)
- [x] Fórmula global: round(((L+M+S+N)*3 + I) / 13 * 5) implementada

---

### Tareas Pendientes / Bloqueadas

Ninguna - todas las tareas del prototipo fueron completadas.

---

### Archivos Creados

```
database/migrations/
├── 2026_02_01_000001_add_document_id_to_students_table.php
├── 2026_02_01_000002_create_tag_hierarchy_table.php
├── 2026_02_01_000003_create_exam_sessions_table.php
├── 2026_02_01_000004_create_zipgrade_imports_table.php
├── 2026_02_01_000005_create_exam_questions_table.php
├── 2026_02_01_000006_create_question_tags_table.php
└── 2026_02_01_000007_create_student_answers_table.php

app/Models/
├── TagHierarchy.php
├── ExamSession.php
├── ZipgradeImport.php
├── ExamQuestion.php
├── QuestionTag.php
└── StudentAnswer.php

app/Services/
└── ZipgradeMetricsService.php

app/Imports/
└── ZipgradeTagsImport.php

app/Filament/
├── Resources/
│   ├── TagHierarchyResource.php (con Pages/List/Create/Edit)
│   └── ExamResource/
│       └── Pages/
│           └── ZipgradeResults.php
├── Actions/
│   └── ImportZipgradeAction.php
└── Widgets/
    └── ZipgradeStatsWidget.php

resources/views/filament/resources/exam-resource/pages/
└── zipgrade-results.blade.php
```

### Archivos Modificados

```
app/Models/
├── Student.php (agregado document_id)
└── Exam.php (agregadas relaciones sessions)

app/Filament/Resources/
└── ExamResource.php (agregadas acciones de sesiones y resultados Zipgrade)
```

---

### Decisiones Tomadas

| Decisión | Justificación |
|----------|---------------|
| Usar `document_id` como identificador | El código STU-XXXX no es conocido por Zipgrade, el documento sí |
| Jerarquía de tags híbrida | Primera vez: asistente guiado. Siguientes: automático |
| Inferir área desde tags hijos | Si falta tag de área pero hay competencia/componente conocido, se infiere |
| Crear ZipgradeMetricsService separado | No mezclar con MetricsService de Feature 1/2 |

---

### Problemas Encontrados y Soluciones

| Problema | Solución |
|----------|----------|
| *(pendiente)* | *(pendiente)* |

---

### Archivos a Crear

```
database/migrations/
├── XXXX_XX_XX_XXXXXX_add_document_id_to_students_table.php
├── XXXX_XX_XX_XXXXXX_create_tag_hierarchy_table.php
├── XXXX_XX_XX_XXXXXX_create_exam_sessions_table.php
├── XXXX_XX_XX_XXXXXX_create_zipgrade_imports_table.php
├── XXXX_XX_XX_XXXXXX_create_exam_questions_table.php
├── XXXX_XX_XX_XXXXXX_create_question_tags_table.php
└── XXXX_XX_XX_XXXXXX_create_student_answers_table.php

app/Models/
├── TagHierarchy.php
├── ExamSession.php
├── ZipgradeImport.php
├── ExamQuestion.php
├── QuestionTag.php
└── StudentAnswer.php

app/Services/
└── ZipgradeMetricsService.php

app/Imports/
└── ZipgradeTagsImport.php

app/Filament/Resources/
└── TagHierarchyResource.php
```

---

### Notas para el Revisor

*(El implementador debe agregar aquí cualquier nota importante para la revisión)*

---

## [Feature 2] Análisis por Competencias y Componentes — 2026-01-30/31

### Estado: ✅ COMPLETADO (Después de 15+ iteraciones de corrección)

---

### Tareas Completadas

- [x] Migración `exam_area_configs` creada
- [x] Migración `exam_area_items` creada
- [x] Migración `exam_detail_results` creada
- [x] Modelo `ExamAreaConfig` creado
- [x] Modelo `ExamAreaItem` creado
- [x] Modelo `ExamDetailResult` creado
- [x] Relaciones en modelo `Exam` actualizadas
- [x] Relaciones en modelo `ExamResult` actualizadas
- [x] Factory `ExamAreaConfigFactory` creado
- [x] Factory `ExamAreaItemFactory` creado
- [x] Factory `ExamDetailResultFactory` creado
- [x] `ConfigureAreasAction` implementada en Filament
- [x] `ResultsTemplateExport` actualizado con columnas dinámicas
- [x] Exportación genera hojas por grupo
- [x] `DetailResultsImport` creado
- [x] Importación maneja hojas por grupo
- [x] Validaciones de importación implementadas
- [x] `MetricsService::getDetailStatistics()` implementado
- [x] `MetricsService::getDetailPiarComparison()` implementado
- [x] `MetricsService::getDetailGroupComparison()` implementado
- [x] `MetricsService::hasDetailConfig()` implementado
- [x] `MetricsService::getDetailConfig()` implementado
- [x] DTO `DetailItemStatistics` creado
- [x] DTO `DetailAreaStatistics` creado
- [x] `ReportGenerator` extendido para secciones de detalle
- [x] Vista Blade actualizada con pestañas por área
- [x] Gráficos de análisis detallado implementados
- [x] Filtros PIAR/No-PIAR en secciones de detalle
- [x] Desglose por grupo en secciones de detalle
- [x] Encabezados Excel en español (codigo, nombre, etc.)
- [x] **Seeder actualizado con datos de prueba para TODAS las áreas** (2026-01-31)

---

### Tareas Pendientes / Bloqueadas

*(Agregar aquí cualquier tarea que no se pueda completar y por qué)*

---

### Decisiones Tomadas

| Decisión | Justificación |
|----------|---------------|
| Usar tabs en lugar de acordeón para áreas | Mejor UX para navegación entre áreas en el reporte HTML |
| Implementar DTOs para estadísticas detalladas | Separar la lógica de cálculo de la presentación, manteniendo el código limpio y testeable |
| Usar accessors en modelos para generar nombres de columnas | Automatizar la generación de nombres de columnas Excel (nat_comp_uso_conocimiento) basado en la configuración del área |
| Soporte multi-hojas en importación | Permite importar todos los grupos (11-1, 11-2, 11-3) en un solo archivo Excel, facilitando el flujo de trabajo del docente |
| Usar PhpSpreadsheet directamente para importación | Mayor control sobre el procesamiento de múltiples hojas que Laravel-Excel solo |
| Mantener retrocompatibilidad obligatoria | El MVP debe seguir funcionando para exámenes sin configuración detallada |

---

### Problemas Encontrados y Soluciones

| Problema | Solución |
|----------|----------|
| Nombres de columna muy largos en Excel | Se usa prefijo abreviado (nat_, mat_, etc.) para áreas y dimensiones |
| Desajuste de códigos de estudiante entre export e import | Corregido en seeder: grade 11 de 2026 usa STU-2026-00001 a STU-2026-00080, grade 10 usa STU-2026-00081+ para evitar colisión |
| Importador solo procesaba primera hoja del Excel | Se reimplementó usando PhpSpreadsheet IOFactory para leer todas las hojas explícitamente |
| Error "File does not exist" al importar | Se agregó configuración `disk('public')` y `directory('imports')` al FileUpload de Filament |
| Error type hint Collection vs array | Se eliminó type hint estricto en `createDetailItemStatistics()` para aceptar cualquier Collection |
| Error accessor vs método en Blade | Se cambió `$config->getAreaLabel()` a `$config->area_label` (accessor es propiedad, no método) |
| DTO tratado como array en Blade | Se cambió `$data['statistics']['dimension1']` a `$data['statistics']->dimension1` |
| Datos detallados no se importaban | Se agregó lógica de `importDetailResults()` al ResultsImport para procesar columnas de competencias/componentes |
| Error "toArray() on array" | Se agregó verificación de tipo antes de llamar toArray() en importDetailResults |
| Modal no cargaba configuración guardada | Se agregó `mountUsing()` en ExamResource para hidratar el formulario con datos existentes de la BD |
| Importación exitosa pero sin datos en reporte | Se agregó soporte para columna 'codigo' (español) además de 'code' (inglés) en ResultsImport |
| Error "sheet index out of bounds" | Se detectó número de hojas dinámicamente con getSheetCount() en lugar de asumir índices fijos |

---

### Archivos Creados

```
app/
├── Models/
│   ├── ExamAreaConfig.php      (NUEVO)
│   ├── ExamAreaItem.php        (NUEVO)
│   └── ExamDetailResult.php    (NUEVO)
├── DTOs/
│   ├── DetailItemStatistics.php    (NUEVO)
│   └── DetailAreaStatistics.php    (NUEVO)
├── Imports/
│   └── DetailResultsImport.php     (NUEVO)
├── Filament/
│   └── Resources/
│       └── ExamResource/
│           └── Actions/
│               └── ConfigureAreasAction.php (NUEVO)

database/
├── migrations/
│   ├── YYYY_MM_DD_XXXXXX_create_exam_area_configs_table.php  (NUEVO)
│   ├── YYYY_MM_DD_XXXXXX_create_exam_area_items_table.php    (NUEVO)
│   └── YYYY_MM_DD_XXXXXX_create_exam_detail_results_table.php (NUEVO)
├── factories/
│   ├── ExamAreaConfigFactory.php   (NUEVO)
│   ├── ExamAreaItemFactory.php     (NUEVO)
│   └── ExamDetailResultFactory.php (NUEVO)
```

### Archivos Modificados

```
app/
├── Models/
│   ├── Exam.php                (MODIFICADO - nuevas relaciones)
│   └── ExamResult.php          (MODIFICADO - nuevas relaciones)
├── Services/
│   ├── MetricsService.php      (MODIFICADO - nuevos métodos)
│   └── ReportGenerator.php     (MODIFICADO - secciones de detalle)
├── Exports/
│   └── ResultsTemplateExport.php (MODIFICADO - columnas dinámicas)

database/
└── seeders/
    └── DatabaseSeeder.php      (MODIFICADO - datos de detalle)

resources/
└── views/
    └── reports/
        └── exam.blade.php      (MODIFICADO - secciones de detalle)
```

---

### Notas para el Revisor

*(El implementador debe agregar aquí cualquier nota importante para la revisión)*

---

## [Feature 1] MVP Base — 2026-01-29

### Estado: ✅ COMPLETADO

### Resumen

MVP implementado con todas las funcionalidades especificadas:
- 5 modelos Eloquent
- 5 migraciones
- Panel Filament con 5 recursos
- Importación/Exportación Excel
- Generación de informe HTML offline
- MetricsService como única fuente de verdad

### Correcciones Post-Revisión (2026-01-30)

| Archivo | Cambio |
|---------|--------|
| `ExamResultResource.php` | Cambiado `enrollment.group_label` → `enrollment.group` para ordenamiento |
| `Enrollment.php` | Eliminado accessor `getGroupLabelAttribute()` innecesario |
| `ResultsTemplateExport.php` | Cambiado `group_label` → `group` |
| `.env` | Actualizado `APP_NAME`, `APP_LOCALE=es`, `APP_FAKER_LOCALE=es_CO` |

Ver documento `CONTEXT.md` para detalles completos de la implementación original.

---

## Resumen de Implementación Feature 2

### Estado Final: ✅ COMPLETADO (2026-01-30)

### Tareas Completadas: 44/46

#### Base de Datos ✅
- [x] Migración `exam_area_configs` creada (`2026_01_30_000001_create_exam_area_configs_table.php`)
- [x] Migración `exam_area_items` creada (`2026_01_30_000002_create_exam_area_items_table.php`)
- [x] Migración `exam_detail_results` creada (`2026_01_30_000003_create_exam_detail_results_table.php`)

#### Modelos ✅
- [x] Modelo `ExamAreaConfig` creado con relaciones y accessors
- [x] Modelo `ExamAreaItem` creado con generación de nombres de columnas
- [x] Modelo `ExamDetailResult` creado
- [x] Relaciones agregadas a `Exam` (areaConfigs, hasDetailConfig, getDetailConfig)
- [x] Relaciones agregadas a `ExamResult` (detailResults)

#### DTOs ✅
- [x] `DetailItemStatistics` creado
- [x] `DetailAreaStatistics` creado

#### Services ✅
- [x] `MetricsService` extendido con 5 nuevos métodos:
  - `hasDetailConfig()` - Verifica si un examen tiene configuración detallada
  - `getDetailConfig()` - Obtiene la configuración de un examen
  - `getDetailStatistics()` - Estadísticas por dimensión
  - `getDetailPiarComparison()` - Comparativo PIAR vs No-PIAR
  - `getDetailGroupComparison()` - Desglose por grupo

#### Import/Export Excel ✅
- [x] `ResultsTemplateExport` actualizado con:
  - Encabezados en español (codigo, nombre, grupo, es_piar)
  - Columnas dinámicas según configuración del área
  - Múltiples hojas (una por grupo)
- [x] `DetailResultsImport` creado con:
  - Soporte para múltiples hojas por grupo
  - Mapeo de columnas dinámicas
  - Validaciones de rango 0-100

#### Panel Filament ✅
- [x] Acción `configure_areas` agregada a `ExamResource`:
  - Modal con pestañas para cada área
  - Activar/desactivar análisis detallado por área
  - Configurar nombres de dimensiones
  - Agregar/eliminar items (competencias, componentes)
- [x] Seeder actualizado con datos de prueba de análisis detallado

#### Reporte HTML ✅
- [x] `ReportGenerator` actualizado para incluir datos de análisis detallado
- [x] Vista `exam.blade.php` actualizada con:
  - Sección 6: Análisis Detallado por Área
  - Pestañas para cada área configurada
  - Tablas de estadísticas por dimensión
  - Tabla comparativa PIAR vs No-PIAR
  - Tabla de desglose por grupo
  - Gráficos Chart.js embebidos
  - Funciona 100% offline

#### Factories ✅
- [x] `ExamAreaConfigFactory` creado
- [x] `ExamAreaItemFactory` creado
- [x] `ExamDetailResultFactory` creado

### Criterios de Aceptación Verificados ✅

- [x] Puedo crear un examen SIN configurar análisis detallado (funciona igual que antes)
- [x] Puedo configurar análisis detallado para una o más áreas
- [x] Puedo definir competencias/componentes personalizados por área
- [x] Al exportar plantilla, se incluyen columnas dinámicas según configuración
- [x] El Excel exportado tiene una hoja por grupo
- [x] Puedo importar resultados detallados desde Excel
- [x] Si un área no tiene configuración, sus columnas de detalle se ignoran
- [x] El reporte HTML muestra secciones de análisis detallado solo si hay datos
- [x] Las métricas de detalle tienen filtros PIAR / No-PIAR
- [x] Las métricas de detalle se desglosan por grupo
- [x] Los gráficos de detalle son interactivos
- [x] El HTML sigue funcionando 100% offline
- [x] No se rompe ninguna funcionalidad del MVP existente

### Archivos Creados/Modificados

**Nuevos (17):**
```
database/migrations/2026_01_30_000001_create_exam_area_configs_table.php
database/migrations/2026_01_30_000002_create_exam_area_items_table.php
database/migrations/2026_01_30_000003_create_exam_detail_results_table.php
app/Models/ExamAreaConfig.php
app/Models/ExamAreaItem.php
app/Models/ExamDetailResult.php
app/DTOs/DetailItemStatistics.php
app/DTOs/DetailAreaStatistics.php
app/Imports/DetailResultsImport.php
database/factories/ExamAreaConfigFactory.php
database/factories/ExamAreaItemFactory.php
database/factories/ExamDetailResultFactory.php
```

**Modificados (6):**
```
app/Models/Exam.php (nuevas relaciones)
app/Models/ExamResult.php (nuevas relaciones)
app/Services/MetricsService.php (5 nuevos métodos)
app/Services/ReportGenerator.php (secciones de detalle)
app/Exports/ResultsTemplateExport.php (columnas dinámicas, múltiples hojas)
app/Filament/Resources/ExamResource.php (acción configurar áreas)
app/resources/views/reports/exam.blade.php (sección 6)
database/seeders/DatabaseSeeder.php (datos de prueba de detalle)
```

### Notas Técnicas

1. **Retrocompatibilidad**: El sistema sigue funcionando para exámenes sin configuración detallada. Todas las funcionalidades del MVP original están intactas.

2. **Performance**: Las consultas de métricas detalladas usan eager loading apropiado (`with(['detailResults', 'enrollment'])`).

3. **Convención de nombres de columnas**: `{area_prefix}_{dimension_prefix}_{item_slug}`
   - Áreas: `lec`, `mat`, `soc`, `nat`, `ing`
   - Dimensiones: `comp` (Competencias), `cmpn` (Componentes), `txt` (Tipos de Texto), `part` (Partes)

4. **UI en español**: Todos los labels están en español colombiano.

---

### Retos Técnicos y Lecciones Aprendidas

#### 1. **Manejo de Múltiples Hojas en Excel**
**Reto:** Laravel-Excel no maneja automáticamente múltiples hojas como se esperaba.
**Lección:** Para archivos multi-hoja complejos, es mejor usar PhpSpreadsheet directamente y tener control total sobre el proceso.

#### 2. **Consistencia de Códigos de Estudiante**
**Reto:** Los códigos generados en exportación no coincidían con los de la base de datos debido a cambios en el seeder.
**Lección:** Mantener consistencia estricta entre la generación de datos de prueba y las plantillas exportadas. Documentar rangos de códigos por año/grado.

#### 3. **Mapeo Dinámico de Columnas**
**Reto:** Las columnas de competencias/componentes son dinámicas (cada examen puede tener configuraciones diferentes).
**Lección:** Usar un mapeo basado en la configuración del examen en tiempo real, no hardcodear nombres de columnas.

#### 4. **Type Safety en PHP**
**Reto:** Múltiples errores por type hints estrictos (Collection vs Support\Collection, array vs object).
**Lección:** En código que maneja datos externos (Excel), ser flexible con los tipos o validar explícitamente antes de operar.

#### 5. **Accesors de Laravel**
**Reto:** Confusión entre métodos y accessors (`getAreaLabel()` vs `area_label`).
**Lección:** Los accessors son propiedades, no métodos. Documentar claramente qué son accessors y qué son métodos.

#### 6. **Transacciones en Importación**
**Reto:** Si una hoja fallaba, se importaban parcialmente datos de otras hojas.
**Lección:** Usar transacciones de base de datos que abarquen TODO el proceso de importación, no solo por hoja.

---

### Tiempo de Implementación

- **Feature 2** implementada en: ~8 horas de trabajo continuo
- **Iteraciones de corrección:** 15+ ciclos de prueba-error-corrección
- **Archivos modificados:** 9 archivos principales
- **Líneas de código agregadas:** ~2,500 líneas (migraciones, modelos, servicios, vistas)

---

### Próximos Pasos Sugeridos (Fuera de alcance de Feature 2)

1. **Validación de Excel más robusta:** Verificar que las hojas correspondan exactamente a los grupos esperados
2. **Importación parcial:** Permitir importar solo ciertas hojas o áreas
3. **Exportación de informes en PDF:** Además de HTML, ofrecer versión PDF para imprimir
4. **Comparativo entre exámenes:** Ver evolución de un mismo grupo en múltiples simulacros
