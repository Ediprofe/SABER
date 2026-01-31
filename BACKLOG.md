# 📋 BACKLOG — Sistema SABER

> Documento de priorización de features futuras.
> Última actualización: 2026-01-30

---

## 🏷️ Leyenda de Prioridad

| Etiqueta | Significado |
|----------|-------------|
| 🔴 **CRÍTICO** | Bloquea uso del sistema |
| 🟠 **ALTO** | Necesario para próximo release |
| 🟡 **MEDIO** | Mejora significativa |
| 🟢 **BAJO** | Nice to have |
| ⚪ **FUTURO** | Ideas para evaluar |

---

## 📊 Features Priorizadas

### 🟠 ALTO — Próximo a Implementar

| ID | Feature | Descripción | Dependencia |
|----|---------|-------------|-------------|
| **F2** | Análisis por Competencias y Componentes | Desglose opcional por competencias, componentes, tipos de texto y partes según área | F1 ✅ |

---

### 🟡 MEDIO — Planificado

| ID | Feature | Descripción | Dependencia |
|----|---------|-------------|-------------|
| **F3** | Análisis Longitudinal | Comparar resultados del mismo estudiante en múltiples simulacros | F1, F2 |
| **F4** | Exportación a PDF | Generar versión PDF del informe HTML | F1 |
| **F5** | Dashboard Resumen | Vista rápida en Filament con KPIs principales | F1 |
| **F6** | Comparación entre Grupos | Análisis comparativo detallado entre grupos del mismo grado | F1 |

---

### 🟢 BAJO — Mejoras Opcionales

| ID | Feature | Descripción | Dependencia |
|----|---------|-------------|-------------|
| **F7** | Autenticación Básica | Login para docentes con roles | F1 |
| **F8** | Multi-Institución | Soporte para múltiples colegios | F7 |
| **F9** | Notificaciones Email | Alertas cuando hay nuevos resultados | F7 |
| **F10** | API REST | Endpoints para integración externa | F7 |

---

### ⚪ FUTURO — Ideas por Evaluar

| ID | Feature | Descripción | Notas |
|----|---------|-------------|-------|
| **F11** | Machine Learning | Predicción de puntaje ICFES basado en simulacros | Requiere datos históricos |
| **F12** | Gamificación | Badges y rankings para estudiantes | Evaluar impacto pedagógico |
| **F13** | App Móvil | Consulta de resultados desde celular | PWA podría ser suficiente |
| **F14** | Integración ICFES | Importar resultados oficiales automáticamente | Depende de API del ICFES |

---

## 📈 Roadmap Visual

```
2026 Q1                    2026 Q2                    2026 Q3
────────────────────────────────────────────────────────────────
   │                          │                          │
   ▼                          ▼                          ▼
┌──────────┐              ┌──────────┐              ┌──────────┐
│   F1 ✅  │              │   F3     │              │   F7     │
│   MVP    │              │ Longitud │              │   Auth   │
└──────────┘              └──────────┘              └──────────┘
┌──────────┐              ┌──────────┐              ┌──────────┐
│   F2 🔄  │              │   F4     │              │   F8     │
│ Compet.  │              │   PDF    │              │  Multi   │
└──────────┘              └──────────┘              └──────────┘
                          ┌──────────┐
                          │   F5     │
                          │Dashboard │
                          └──────────┘
```

---

## 📝 Notas de Priorización

1. **F2 (Competencias)** es prioridad alta porque:
   - Agrega valor analítico significativo
   - No rompe funcionalidad existente
   - Solicitado explícitamente por el usuario

2. **F3 (Longitudinal)** depende de tener múltiples exámenes con resultados.

3. **F7 (Auth)** se pospone porque el MVP es para uso interno.

4. **F4 (PDF)** es útil pero el HTML actual es imprimible.

---

## 🔄 Historial de Cambios

| Fecha | Cambio |
|-------|--------|
| 2026-01-30 | Documento creado. F2 priorizado como siguiente feature. |
| 2026-01-29 | F1 (MVP) completado. |
