# 📝 CHANGELOG — Sistema SABER

> Registro incremental de cambios por feature.
> El agente implementador debe actualizar este documento en tiempo real.

---

## [Feature 2] Análisis por Competencias y Componentes — 2026-01-30

### Estado: 🔄 EN PROGRESO

---

### Tareas Completadas

- [ ] Migración `exam_area_configs` creada
- [ ] Migración `exam_area_items` creada
- [ ] Migración `exam_detail_results` creada
- [ ] Modelo `ExamAreaConfig` creado
- [ ] Modelo `ExamAreaItem` creado
- [ ] Modelo `ExamDetailResult` creado
- [ ] Relaciones en modelo `Exam` actualizadas
- [ ] Relaciones en modelo `ExamResult` actualizadas
- [ ] Factory `ExamAreaConfigFactory` creado
- [ ] Factory `ExamAreaItemFactory` creado
- [ ] Factory `ExamDetailResultFactory` creado
- [ ] `ConfigureAreasAction` implementada en Filament
- [ ] `ResultsTemplateExport` actualizado con columnas dinámicas
- [ ] Exportación genera hojas por grupo
- [ ] `DetailResultsImport` creado
- [ ] Importación maneja hojas por grupo
- [ ] Validaciones de importación implementadas
- [ ] `MetricsService::getDetailStatistics()` implementado
- [ ] `MetricsService::getDetailPiarComparison()` implementado
- [ ] `MetricsService::getDetailGroupComparison()` implementado
- [ ] `MetricsService::hasDetailConfig()` implementado
- [ ] `MetricsService::getDetailConfig()` implementado
- [ ] DTO `DetailItemStatistics` creado
- [ ] DTO `DetailAreaStatistics` creado
- [ ] `ReportGenerator` extendido para secciones de detalle
- [ ] Vista Blade actualizada con pestañas por área
- [ ] Gráficos de análisis detallado implementados
- [ ] Filtros PIAR/No-PIAR en secciones de detalle
- [ ] Desglose por grupo en secciones de detalle
- [ ] Seeder actualizado con datos de prueba de detalle
- [ ] Encabezados Excel en español (codigo, nombre, etc.)
- [ ] Tests de regresión (MVP sigue funcionando)

---

### Tareas Pendientes / Bloqueadas

*(Agregar aquí cualquier tarea que no se pueda completar y por qué)*

---

### Decisiones Tomadas

| Decisión | Justificación |
|----------|---------------|
| *Ejemplo: Usar tabs en lugar de acordeón para áreas* | *Mejor UX para navegación entre áreas* |

---

### Problemas Encontrados y Soluciones

| Problema | Solución |
|----------|----------|
| *Ejemplo: Nombres de columna muy largos* | *Se usa prefijo abreviado (nat_, mat_, etc.)* |

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
