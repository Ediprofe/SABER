<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Informe {{ $exam->name }} - Sistema SABER</title>
    <style>
        /* Tailwind-like Utility Classes */
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif; background-color: #f3f4f6; color: #1f2937; line-height: 1.5; }
        .container { max-width: 1400px; margin: 0 auto; padding: 20px; }
        
        /* Header */
        .header { background: linear-gradient(135deg, #1e40af 0%, #3b82f6 100%); color: white; padding: 30px; border-radius: 12px; margin-bottom: 24px; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1); }
        .header h1 { font-size: 28px; font-weight: 700; margin-bottom: 8px; }
        .header-subtitle { font-size: 16px; opacity: 0.9; }
        .header-meta { display: flex; gap: 24px; margin-top: 16px; flex-wrap: wrap; font-size: 14px; }
        .header-meta-item { display: flex; align-items: center; gap: 6px; }
        
        /* Cards */
        .card { background: white; border-radius: 12px; padding: 24px; margin-bottom: 24px; box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1); }
        .card-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
        .card-title { font-size: 20px; font-weight: 600; color: #1f2937; }
        
        /* KPI Grid */
        .kpi-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; }
        .kpi-card { background: linear-gradient(135deg, #f0f9ff 0%, #e0f2fe 100%); border-left: 4px solid #3b82f6; padding: 20px; border-radius: 8px; }
        .kpi-label { font-size: 12px; font-weight: 600; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 8px; }
        .kpi-value { font-size: 32px; font-weight: 700; color: #1e40af; }
        .kpi-value-small { font-size: 18px; color: #475569; }
        
        /* Controls */
        .controls { display: flex; gap: 12px; margin-bottom: 20px; flex-wrap: wrap; align-items: center; }
        .input-group { display: flex; flex-direction: column; gap: 4px; }
        .input-label { font-size: 12px; font-weight: 600; color: #64748b; }
        .input { padding: 10px 14px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 14px; width: 250px; transition: border-color 0.2s; }
        .input:focus { outline: none; border-color: #3b82f6; box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1); }
        .select { padding: 10px 14px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 14px; background: white; cursor: pointer; }
        .toggle { display: flex; align-items: center; gap: 8px; cursor: pointer; padding: 8px 12px; background: #f3f4f6; border-radius: 6px; transition: background 0.2s; }
        .toggle:hover { background: #e5e7eb; }
        .toggle-input { width: 18px; height: 18px; accent-color: #3b82f6; }
        
        /* Tables */
        .table-container { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; font-size: 14px; }
        th { background: #f8fafc; color: #475569; font-weight: 600; text-align: left; padding: 12px 16px; border-bottom: 2px solid #e2e8f0; white-space: nowrap; cursor: pointer; user-select: none; position: relative; }
        th:hover { background: #f1f5f9; }
        th.sortable::after { content: '↕'; margin-left: 8px; opacity: 0.4; font-size: 12px; }
        th.sort-asc::after { content: '↑'; opacity: 1; color: #3b82f6; }
        th.sort-desc::after { content: '↓'; opacity: 1; color: #3b82f6; }
        td { padding: 12px 16px; border-bottom: 1px solid #e2e8f0; }
        tr:hover { background: #f8fafc; }
        tr.piar { background: #fef3c7; }
        tr.piar:hover { background: #fde68a; }
        
        /* Badges */
        .badge { display: inline-flex; align-items: center; padding: 4px 10px; border-radius: 20px; font-size: 12px; font-weight: 600; }
        .badge-piar { background: #fbbf24; color: #92400e; }
        .badge-normal { background: #e0e7ff; color: #3730a3; }
        
        /* Score colors */
        .score-high { color: #059669; font-weight: 600; }
        .score-medium { color: #d97706; font-weight: 600; }
        .score-low { color: #dc2626; font-weight: 600; }
        .score-null { color: #9ca3af; font-style: italic; }
        
        /* Charts */
        .chart-grid { display: grid; grid-template-columns: 1fr; gap: 24px; }
        .chart-container { background: white; padding: 20px; border-radius: 8px; border: 1px solid #e2e8f0; }
        .chart-title { font-size: 16px; font-weight: 600; color: #374151; margin-bottom: 16px; text-align: center; }
        .chart-wrapper { position: relative; height: 300px; }
        
        /* Stats tables */
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 24px; }
        .stats-table { width: 100%; }
        .stats-table th { background: #eff6ff; color: #1e40af; }
        .stats-table .area-lectura { border-left: 3px solid #3b82f6; }
        .stats-table .area-matematicas { border-left: 3px solid #ef4444; }
        .stats-table .area-sociales { border-left: 3px solid #f59e0b; }
        .stats-table .area-naturales { border-left: 3px solid #10b981; }
        .stats-table .area-ingles { border-left: 3px solid #8b5cf6; }
        
        /* Top performers */
        .top-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 20px; }
        .top-card { background: #f8fafc; border-radius: 8px; padding: 16px; border: 1px solid #e2e8f0; }
        .top-title { font-size: 14px; font-weight: 600; color: #374151; margin-bottom: 12px; padding-bottom: 8px; border-bottom: 2px solid #e2e8f0; }
        .top-list { list-style: none; }
        .top-item { display: flex; justify-content: space-between; align-items: center; padding: 8px 0; border-bottom: 1px solid #f1f5f9; }
        .top-item:last-child { border-bottom: none; }
        .top-rank { width: 24px; height: 24px; border-radius: 50%; background: #e0e7ff; color: #3730a3; display: flex; align-items: center; justify-content: center; font-size: 12px; font-weight: 700; }
        .top-rank.gold { background: #fde047; color: #854d0e; }
        .top-rank.silver { background: #e5e7eb; color: #374151; }
        .top-rank.bronze { background: #fed7aa; color: #9a3412; }
        .top-name { flex: 1; margin-left: 12px; font-size: 14px; }
        .top-score { font-weight: 600; color: #1e40af; }
        
        /* Comparison */
        .comparison-table th { background: #f0fdf4; color: #166534; }
        .comparison-table .piar-row { background: #fef3c7; }
        .comparison-table .non-piar-row { background: #eff6ff; }
        
        /* Footer */
        .footer { text-align: center; padding: 20px; color: #6b7280; font-size: 12px; margin-top: 40px; }
        
        /* Print styles */
        @media print {
            body { background: white; }
            .card { box-shadow: none; border: 1px solid #e2e8f0; break-inside: avoid; }
            .header { background: #1e40af; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            .no-print { display: none !important; }
        }
        
        /* Responsive */
        @media (max-width: 768px) {
            .container { padding: 12px; }
            .header { padding: 20px; }
            .header h1 { font-size: 22px; }
            .controls { flex-direction: column; align-items: stretch; }
            .input { width: 100%; }
            .chart-grid { grid-template-columns: 1fr; }
            .kpi-grid { grid-template-columns: repeat(2, 1fr); }
        }
        
        /* Loading */
        .loading { display: inline-block; width: 20px; height: 20px; border: 3px solid #f3f3f3; border-top: 3px solid #3b82f6; border-radius: 50%; animation: spin 1s linear infinite; }
        @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
    </style>
</head>
<body>
    <div class="container">
        <!-- Header -->
        <div class="header">
            <h1>📊 Informe de Análisis - {{ $exam->name }}</h1>
            <div class="header-subtitle">Sistema SABER - Análisis ICFES</div>
            <div class="header-meta">
                <div class="header-meta-item">
                    <span>📅</span>
                    <span>Fecha del examen: {{ \Carbon\Carbon::parse($exam->date)->format('d/m/Y') }}</span>
                </div>
                <div class="header-meta-item">
                    <span>📝</span>
                    <span>Tipo: {{ $exam->type === 'SIMULACRO' ? 'Simulacro' : 'ICFES' }}</span>
                </div>
                @if(isset($filters['grade']))
                <div class="header-meta-item">
                    <span>🎓</span>
                    <span>Grado: {{ $filters['grade'] }}</span>
                </div>
                @endif
                @if(isset($filters['group']) && $filters['group'])
                <div class="header-meta-item">
                    <span>👥</span>
                    <span>Grupo: {{ $filters['group'] }}</span>
                </div>
                @endif
                <div class="header-meta-item">
                    <span>⏰</span>
                    <span>Generado: {{ $generatedAt }}</span>
                </div>
            </div>
        </div>

        <!-- KPIs Section -->
        <div class="card">
            <div class="card-header">
                <h2 class="card-title">📈 Indicadores Principales</h2>
            </div>
            <div class="kpi-grid">
                <div class="kpi-card">
                    <div class="kpi-label">Total Estudiantes</div>
                    <div class="kpi-value">{{ $statistics->totalStudents }}</div>
                </div>
                <div class="kpi-card">
                    <div class="kpi-label">Estudiantes PIAR</div>
                    <div class="kpi-value">{{ $statistics->piarCount }}</div>
                    <div class="kpi-value-small">{{ $statistics->totalStudents > 0 ? round(($statistics->piarCount / $statistics->totalStudents) * 100, 1) : 0 }}%</div>
                </div>
                <div class="kpi-card">
                    <div class="kpi-label">Sin PIAR</div>
                    <div class="kpi-value">{{ $statistics->nonPiarCount }}</div>
                    <div class="kpi-value-small">{{ $statistics->totalStudents > 0 ? round(($statistics->nonPiarCount / $statistics->totalStudents) * 100, 1) : 0 }}%</div>
                </div>
                <div class="kpi-card">
                    <div class="kpi-label">Promedio Global</div>
                    <div class="kpi-value">{{ number_format($statistics->globalAverage, 1) }}</div>
                </div>
                <div class="kpi-card">
                    <div class="kpi-label">Desviación Estándar Global</div>
                    <div class="kpi-value">{{ number_format($statistics->globalStdDev, 1) }}</div>
                </div>
            </div>
        </div>

        <!-- Student List Section -->
        <div class="card" x-data="studentTable()">
            <div class="card-header">
                <h2 class="card-title">👨‍🎓 Listado de Estudiantes</h2>
            </div>
            
            <div class="controls no-print">
                <div class="input-group">
                    <label class="input-label">Buscar estudiante</label>
                    <input type="text" x-model="search" placeholder="Nombre, código o grupo..." class="input">
                </div>
                
                <div class="input-group">
                    <label class="input-label">Filtrar por grupo</label>
                    <select x-model="groupFilter" class="select">
                        <option value="">Todos los grupos</option>
                        <template x-for="group in uniqueGroups" :key="group">
                            <option :value="group" x-text="group"></option>
                        </template>
                    </select>
                </div>
                
                <label class="toggle">
                    <input type="checkbox" x-model="showPiar" class="toggle-input">
                    <span>Mostrar PIAR</span>
                </label>
                
                <label class="toggle">
                    <input type="checkbox" x-model="showNonPiar" class="toggle-input" checked>
                    <span>Mostrar sin PIAR</span>
                </label>
            </div>
            
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th class="sortable" @click="sortBy('code')" :class="getSortClass('code')">Código</th>
                            <th class="sortable" @click="sortBy('first_name')" :class="getSortClass('first_name')">Nombre</th>
                            <th class="sortable" @click="sortBy('last_name')" :class="getSortClass('last_name')">Apellido</th>
                            <th class="sortable" @click="sortBy('group')" :class="getSortClass('group')">Grupo</th>
                            <th>PIAR</th>
                            <th class="sortable" @click="sortBy('lectura')" :class="getSortClass('lectura')">Lectura</th>
                            <th class="sortable" @click="sortBy('matematicas')" :class="getSortClass('matematicas')">Matemáticas</th>
                            <th class="sortable" @click="sortBy('sociales')" :class="getSortClass('sociales')">Sociales</th>
                            <th class="sortable" @click="sortBy('naturales')" :class="getSortClass('naturales')">Naturales</th>
                            <th class="sortable" @click="sortBy('ingles')" :class="getSortClass('ingles')">Inglés</th>
                            <th class="sortable" @click="sortBy('global_score')" :class="getSortClass('global_score')">Global</th>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="student in filteredStudents" :key="student.code">
                            <tr :class="{ 'piar': student.is_piar }">
                                <td x-text="student.code"></td>
                                <td x-text="student.first_name"></td>
                                <td x-text="student.last_name"></td>
                                <td x-text="student.group"></td>
                                <td>
                                    <span class="badge" :class="student.is_piar ? 'badge-piar' : 'badge-normal'" x-text="student.is_piar ? 'SI' : 'NO'"></span>
                                </td>
                                <td :class="getScoreClass(student.lectura)" x-text="student.lectura !== null ? student.lectura : 'N/A'"></td>
                                <td :class="getScoreClass(student.matematicas)" x-text="student.matematicas !== null ? student.matematicas : 'N/A'"></td>
                                <td :class="getScoreClass(student.sociales)" x-text="student.sociales !== null ? student.sociales : 'N/A'"></td>
                                <td :class="getScoreClass(student.naturales)" x-text="student.naturales !== null ? student.naturales : 'N/A'"></td>
                                <td :class="getScoreClass(student.ingles)" x-text="student.ingles !== null ? student.ingles : 'N/A'"></td>
                                <td :class="getScoreClass(student.global_score)" x-text="student.global_score !== null ? student.global_score : 'N/A'"></td>
                            </tr>
                        </template>
                    </tbody>
                </table>
                <div x-show="filteredStudents.length === 0" style="text-align: center; padding: 40px; color: #6b7280;">
                    No se encontraron estudiantes con los filtros aplicados.
                </div>
            </div>
        </div>

        <!-- Statistics by Area -->
        <div class="card">
            <div class="card-header">
                <h2 class="card-title">📊 Estadísticas por Área</h2>
            </div>
            <div class="table-container">
                <table class="stats-table">
                    <thead>
                        <tr>
                            <th>Área</th>
                            <th>Promedio</th>
                            <th>Desv. Estándar</th>
                            <th>Mínimo</th>
                            <th>Máximo</th>
                            <th>Evaluados</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($statistics->areaStatistics as $areaStat)
                        <tr class="area-{{ strtolower($areaStat->area) }}">
                            <td><strong>{{ $areaStat->area }}</strong></td>
                            <td>{{ number_format($areaStat->average, 2) }}</td>
                            <td>{{ number_format($areaStat->stdDev, 2) }}</td>
                            <td>{{ $areaStat->min }}</td>
                            <td>{{ $areaStat->max }}</td>
                            <td>{{ $areaStat->count }}</td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        <!-- PIAR Comparison -->
        @if(isset($piarComparison['piar']) || isset($piarComparison['non_piar']))
        <div class="card">
            <div class="card-header">
                <h2 class="card-title">⚖️ Comparativo PIAR vs No PIAR</h2>
            </div>
            <div class="table-container">
                <table class="stats-table comparison-table">
                    <thead>
                        <tr>
                            <th>Población</th>
                            <th>Cantidad</th>
                            <th>Promedios por Área</th>
                        </tr>
                    </thead>
                    <tbody>
                        @if(isset($piarComparison['non_piar']))
                        <tr class="non-piar-row">
                            <td><strong>Sin PIAR</strong></td>
                            <td>{{ $piarComparison['non_piar_count'] }}</td>
                            <td>
                                Lectura: {{ number_format($piarComparison['non_piar']['lectura']->average ?? 0, 2) }} | 
                                Mat: {{ number_format($piarComparison['non_piar']['matematicas']->average ?? 0, 2) }} | 
                                Soc: {{ number_format($piarComparison['non_piar']['sociales']->average ?? 0, 2) }} | 
                                Nat: {{ number_format($piarComparison['non_piar']['naturales']->average ?? 0, 2) }} | 
                                Ing: {{ number_format($piarComparison['non_piar']['ingles']->average ?? 0, 2) }}
                            </td>
                        </tr>
                        @endif
                        @if(isset($piarComparison['piar']))
                        <tr class="piar-row">
                            <td><strong>PIAR</strong></td>
                            <td>{{ $piarComparison['piar_count'] }}</td>
                            <td>
                                Lectura: {{ number_format($piarComparison['piar']['lectura']->average ?? 0, 2) }} | 
                                Mat: {{ number_format($piarComparison['piar']['matematicas']->average ?? 0, 2) }} | 
                                Soc: {{ number_format($piarComparison['piar']['sociales']->average ?? 0, 2) }} | 
                                Nat: {{ number_format($piarComparison['piar']['naturales']->average ?? 0, 2) }} | 
                                Ing: {{ number_format($piarComparison['piar']['ingles']->average ?? 0, 2) }}
                            </td>
                        </tr>
                        @endif
                    </tbody>
                </table>
            </div>
            <div style="margin-top: 24px;">
                <div class="chart-container">
                    <div class="chart-title">Promedios por Área - Comparativo PIAR vs No PIAR</div>
                    <div class="chart-wrapper">
                        <canvas id="chartPiarComparison"></canvas>
                    </div>
                </div>
            </div>
        </div>
        @endif

        <!-- Top Performers -->
        <div class="card">
            <div class="card-header">
                <h2 class="card-title">🏆 Top 5 por Área</h2>
            </div>
            <div class="top-grid">
                @foreach($topPerformers as $area => $performers)
                <div class="top-card">
                    <div class="top-title">{{ $area === 'global' ? 'Puntaje Global' : $area }}</div>
                    <ul class="top-list">
                        @foreach($performers as $index => $performer)
                        <li class="top-item">
                            <span class="top-rank {{ $index === 0 ? 'gold' : ($index === 1 ? 'silver' : ($index === 2 ? 'bronze' : '')) }}">{{ $index + 1 }}</span>
                            <span class="top-name">{{ $performer->enrollment->student->first_name ?? 'N/A' }} {{ $performer->enrollment->student->last_name ?? '' }}</span>
                            <span class="top-score">{{ $area === 'global' ? $performer->global_score : $performer->{strtolower(str_replace(' ', '_', $area))} }}</span>
                        </li>
                        @endforeach
                    </ul>
                </div>
                @endforeach
            </div>
        </div>

        <!-- Charts Section -->
        <div class="card">
            <div class="card-header">
                <h2 class="card-title">📈 Gráficos y Visualizaciones</h2>
            </div>
            <div class="chart-grid">
                <!-- Promedios por área -->
                <div class="chart-container">
                    <div class="chart-title">Promedios por Área</div>
                    <div class="chart-wrapper">
                        <canvas id="chartAverages"></canvas>
                    </div>
                </div>
                
                <!-- Desviación estándar -->
                <div class="chart-container">
                    <div class="chart-title">Desviación Estándar por Área</div>
                    <div class="chart-wrapper">
                        <canvas id="chartStdDev"></canvas>
                    </div>
                </div>
                
                <!-- Promedios por grupo -->
                <div class="chart-container">
                    <div class="chart-title">Promedios por Grupo</div>
                    <div class="chart-wrapper">
                        <canvas id="chartGroups"></canvas>
                    </div>
                </div>
                
                <!-- Comparativo PIAR -->
                @if(isset($piarComparison->piar) && isset($piarComparison->nonPiar))
                <div class="chart-container">
                    <div class="chart-title">Comparativo PIAR vs No PIAR</div>
                    <div class="chart-wrapper">
                        <canvas id="chartPiar"></canvas>
                    </div>
                </div>
                @endif
                
                <!-- Distribución global -->
                <div class="chart-container">
                    <div class="chart-title">Distribución de Puntajes Globales</div>
                    <div class="chart-wrapper" style="height: 400px;">
                        <canvas id="chartDistribution"></canvas>
                    </div>
                </div>
            </div>
        </div>

        <!-- Section 6: Detailed Analysis by Area -->
        @if(isset($detailAnalysis) && !empty($detailAnalysis))
        @php $detailKeys = array_keys($detailAnalysis); $firstArea = $detailKeys[0]; @endphp
        <div class="card" x-data="{ activeArea: '{{ $firstArea }}' }">
            <div class="card-header">
                <h2 class="card-title">Sección 6: Análisis Detallado por Área</h2>
            </div>
            
            <!-- Area Tabs -->
            <div style="margin-bottom: 20px;">
                <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                    @foreach($detailAnalysis as $area => $data)
                    <button 
                        @click="activeArea = '{{ $area }}'" 
                        :class="activeArea === '{{ $area }}' ? 'active-tab' : 'inactive-tab'"
                        class="tab-button"
                        style="padding: 10px 20px; border-radius: 6px; font-weight: 600; cursor: pointer; border: none; transition: all 0.2s;"
                        :style="activeArea === '{{ $area }}' ? 'background: linear-gradient(135deg, #1e40af 0%, #3b82f6 100%); color: white;' : 'background: #f3f4f6; color: #374151;'"
                    >
                        {{ $data['config']->area_label ?? ucfirst($area) }}
                    </button>
                    @endforeach
                </div>
            </div>
            
            <!-- Content for each area -->
            @foreach($detailAnalysis as $area => $data)
            <div x-show="activeArea === '{{ $area }}'" x-transition>
                
                @if(!($data['hasConfig'] ?? false))
                <!-- Area without configuration -->
                <div style="background: #fef3c7; border-left: 4px solid #f59e0b; padding: 20px; border-radius: 8px; margin-bottom: 24px;">
                    <h3 style="font-size: 18px; font-weight: 600; color: #92400e; margin-bottom: 8px;">
                        {{ $data['area_label'] ?? ucfirst($area) }}
                    </h3>
                    <p style="color: #92400e; font-size: 14px;">
                        No hay configuración detallada para esta área. Para habilitar el análisis detallado, configure las competencias y componentes desde el panel administrativo.
                    </p>
                </div>
                @else
                
                <!-- Statistics for Dimension 1 -->
                @if(!empty($data['statistics']->dimension1))
                <div style="margin-bottom: 24px;">
                    <h3 style="font-size: 16px; font-weight: 600; color: #1f2937; margin-bottom: 12px;">
                        {{ $data['config']->dimension1_name }}
                    </h3>
                    <div class="table-container">
                        <table class="stats-table">
                            <thead>
                                <tr>
                                    <th>{{ $data['config']->dimension1_name }}</th>
                                    <th>Promedio</th>
                                    <th>Desv. Estándar</th>
                                    <th>Mínimo</th>
                                    <th>Máximo</th>
                                    <th>Evaluados</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($data['statistics']->dimension1 as $item)
                                <tr class="area-{{ $area }}">
                                    <td><strong>{{ $item->itemName ?? $item['itemName'] ?? $item->name ?? $item['name'] }}</strong></td>
                                    <td>{{ number_format($item->average ?? $item['average'] ?? 0, 2) }}</td>
                                    <td>{{ number_format($item->stdDev ?? $item['stdDev'] ?? $item->std_dev ?? $item['std_dev'] ?? 0, 2) }}</td>
                                    <td>{{ $item->min ?? $item['min'] ?? 0 }}</td>
                                    <td>{{ $item->max ?? $item['max'] ?? 0 }}</td>
                                    <td>{{ $item->count ?? $item['count'] ?? 0 }}</td>
                                </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
                @endif
                
                <!-- Statistics for Dimension 2 -->
                @if(!empty($data['statistics']->dimension2))
                <div style="margin-bottom: 24px;">
                    <h3 style="font-size: 16px; font-weight: 600; color: #1f2937; margin-bottom: 12px;">
                        {{ $data['config']->dimension2_name }}
                    </h3>
                    <div class="table-container">
                        <table class="stats-table">
                            <thead>
                                <tr>
                                    <th>{{ $data['config']->dimension2_name }}</th>
                                    <th>Promedio</th>
                                    <th>Desv. Estándar</th>
                                    <th>Mínimo</th>
                                    <th>Máximo</th>
                                    <th>Evaluados</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($data['statistics']->dimension2 as $item)
                                <tr class="area-{{ $area }}">
                                    <td><strong>{{ $item->itemName ?? $item['itemName'] ?? $item->name ?? $item['name'] }}</strong></td>
                                    <td>{{ number_format($item->average ?? $item['average'] ?? 0, 2) }}</td>
                                    <td>{{ number_format($item->stdDev ?? $item['stdDev'] ?? $item->std_dev ?? $item['std_dev'] ?? 0, 2) }}</td>
                                    <td>{{ $item->min ?? $item['min'] ?? 0 }}</td>
                                    <td>{{ $item->max ?? $item['max'] ?? 0 }}</td>
                                    <td>{{ $item->count ?? $item['count'] ?? 0 }}</td>
                                </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
                @endif
                
                <!-- PIAR vs No-PIAR Comparison -->
                @if(isset($data['piarComparison']) && !empty($data['piarComparison']))
                <div style="margin-bottom: 24px;">
                    <h3 style="font-size: 16px; font-weight: 600; color: #1f2937; margin-bottom: 12px;">
                        Comparativo PIAR vs No-PIAR - {{ $data['config']->area_label ?? ucfirst($area) }}
                    </h3>
                    <div class="table-container">
                        <table class="stats-table comparison-table">
                            <thead>
                                <tr>
                                    <th>{{ $data['config']->dimension1_name }} / Elemento</th>
                                    <th>Promedio PIAR</th>
                                    <th>Promedio No-PIAR</th>
                                    <th>Diferencia</th>
                                </tr>
                            </thead>
                            <tbody>
                                @if(isset($data['piarComparison']['items']) && is_array($data['piarComparison']['items']))
                                    @foreach($data['piarComparison']['items'] as $item)
                                    <tr>
                                        <td><strong>{{ $item['name'] ?? $item->name ?? 'N/A' }}</strong></td>
                                        <td>{{ number_format($item['piar_average'] ?? $item->piar_average ?? 0, 2) }}</td>
                                        <td>{{ number_format($item['non_piar_average'] ?? $item->non_piar_average ?? 0, 2) }}</td>
                                        <td>
                                            @php
                                                $diff = ($item['non_piar_average'] ?? $item->non_piar_average ?? 0) - ($item['piar_average'] ?? $item->piar_average ?? 0);
                                                $color = $diff > 0 ? '#dc2626' : ($diff < 0 ? '#059669' : '#6b7280');
                                            @endphp
                                            <span style="color: {{ $color }}; font-weight: 600;">
                                                {{ $diff > 0 ? '+' : '' }}{{ number_format($diff, 2) }}
                                            </span>
                                        </td>
                                    </tr>
                                    @endforeach
                                @endif
                            </tbody>
                        </table>
                    </div>
                </div>
                @endif
                
                <!-- Group Comparison -->
                @if(isset($data['groupComparison']) && !empty($data['groupComparison']))
                <div style="margin-bottom: 24px;">
                    <h3 style="font-size: 16px; font-weight: 600; color: #1f2937; margin-bottom: 12px;">
                        Desglose por Grupo - {{ $data['config']->area_label ?? ucfirst($area) }}
                    </h3>
                    <div class="table-container">
                        <table class="stats-table">
                            <thead>
                                <tr>
                                    <th>Grupo</th>
@if(!empty($data['statistics']->dimension1))
@foreach($data['statistics']->dimension1 as $item)
                                        <th>{{ $item->itemName ?? $item['itemName'] ?? $item->name ?? $item['name'] }}</th>
                                        @endforeach
                                    @endif
@if(!empty($data['statistics']->dimension2))
@foreach($data['statistics']->dimension2 as $item)
                                        <th>{{ $item->itemName ?? $item['itemName'] ?? $item->name ?? $item['name'] }}</th>
                                        @endforeach
                                    @endif
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($data['groupComparison'] as $group => $groupData)
                                <tr>
                                    <td><strong>{{ $group }}</strong></td>
                                    @if(isset($groupData['items']) && is_array($groupData['items']))
                                        @foreach($groupData['items'] as $itemData)
                                        <td>{{ number_format($itemData['average'] ?? $itemData->average ?? 0, 2) }}</td>
                                        @endforeach
                                    @endif
                                </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
                @endif
                
                <!-- Detailed Charts -->
                <div style="margin-top: 24px;">
                    <h3 style="font-size: 16px; font-weight: 600; color: #1f2937; margin-bottom: 16px;">
                        Gráficos de Análisis Detallado
                    </h3>
                    <div class="chart-grid">
                        <!-- Chart for Dimension 1 -->
@if(!empty($data['statistics']->dimension1))
                        <div class="chart-container">
                            <div class="chart-title">Promedios - {{ $data['config']->dimension1_name }}</div>
                            <div class="chart-wrapper">
                                <canvas id="chart{{ ucfirst($area) }}Dim1"></canvas>
                            </div>
                        </div>
                        @endif
                        
                        <!-- Chart for Dimension 2 -->
                        @if(!empty($data['statistics']->dimension2))
                        <div class="chart-container">
                            <div class="chart-title">Promedios - {{ $data['config']->dimension2_name }}</div>
                            <div class="chart-wrapper">
                                <canvas id="chart{{ ucfirst($area) }}Dim2"></canvas>
                            </div>
                        </div>
                        @endif
                        
                        <!-- Chart for PIAR Comparison -->
                        @if(isset($data['piarComparison']) && !empty($data['piarComparison']['items']))
                        <div class="chart-container">
                            <div class="chart-title">Comparativo PIAR vs No-PIAR</div>
                            <div class="chart-wrapper">
                                <canvas id="chart{{ ucfirst($area) }}Piar"></canvas>
                            </div>
                        </div>
                        @endif
                    </div>
                </div>
                @endif
                
            </div>
            @endforeach
        </div>
        
        <script>
            // Embedded detailed analysis data for charts
            window.detailAnalysisData = {!! json_encode($detailAnalysis) !!};
        </script>
        @endif

        <!-- Footer -->
        <div class="footer">
            <p>Sistema SABER - Análisis ICFES | Generado el {{ $generatedAt }}</p>
            <p>Este informe funciona completamente offline</p>
        </div>
    </div>

    <!-- Embedded Data -->
    <script id="report-data" type="application/json">
    {
        "students": {!! json_encode($results->map(function($result) {
            return [
                'code' => $result->enrollment->student->code ?? 'N/A',
                'first_name' => $result->enrollment->student->first_name ?? 'N/A',
                'last_name' => $result->enrollment->student->last_name ?? '',
                'group' => $result->enrollment->group ?? 'N/A',
                'is_piar' => $result->enrollment->is_piar ?? false,
                'lectura' => $result->lectura,
                'matematicas' => $result->matematicas,
                'sociales' => $result->sociales,
                'naturales' => $result->naturales,
                'ingles' => $result->ingles,
                'global_score' => $result->global_score
            ];
        })) !!},
        "areaStatistics": {!! json_encode($statistics->areaStatistics) !!},
        "groupComparison": {!! json_encode($groupComparison) !!},
        "piarComparison": {!! json_encode($piarComparison) !!},
        "distributions": {!! json_encode($distributions) !!}
    }
    </script>

    <!-- Alpine.js CDN -->
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>

    <!-- Chart.js CDN -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.x.x/dist/chart.umd.min.js"></script>

    <!-- Chart.js Data Labels Plugin -->
    <script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels@2.x.x"></script>

    <!-- Application Logic -->
    <script>
        // Parse embedded data
        const reportData = JSON.parse(document.getElementById('report-data').textContent);
        
        // Alpine.js Student Table Component
        function studentTable() {
            return {
                students: reportData.students,
                search: '',
                groupFilter: '',
                showPiar: true,
                showNonPiar: true,
                sortColumn: 'code',
                sortDirection: 'asc',
                
                get uniqueGroups() {
                    return [...new Set(this.students.map(s => s.group))].sort();
                },
                
                get filteredStudents() {
                    let filtered = this.students.filter(student => {
                        // Search filter
                        const searchLower = this.search.toLowerCase();
                        const matchesSearch = !this.search || 
                            student.code.toLowerCase().includes(searchLower) ||
                            student.first_name.toLowerCase().includes(searchLower) ||
                            student.last_name.toLowerCase().includes(searchLower) ||
                            student.group.toLowerCase().includes(searchLower);
                        
                        // Group filter
                        const matchesGroup = !this.groupFilter || student.group === this.groupFilter;
                        
                        // PIAR filter
                        const matchesPiar = (this.showPiar && student.is_piar) || (this.showNonPiar && !student.is_piar);
                        
                        return matchesSearch && matchesGroup && matchesPiar;
                    });
                    
                    // Sort
                    filtered.sort((a, b) => {
                        let valA = a[this.sortColumn];
                        let valB = b[this.sortColumn];
                        
                        // Handle nulls
                        if (valA === null && valB === null) return 0;
                        if (valA === null) return 1;
                        if (valB === null) return -1;
                        
                        // String comparison
                        if (typeof valA === 'string') {
                            valA = valA.toLowerCase();
                            valB = valB.toLowerCase();
                        }
                        
                        if (valA < valB) return this.sortDirection === 'asc' ? -1 : 1;
                        if (valA > valB) return this.sortDirection === 'asc' ? 1 : -1;
                        return 0;
                    });
                    
                    return filtered;
                },
                
                sortBy(column) {
                    if (this.sortColumn === column) {
                        this.sortDirection = this.sortDirection === 'asc' ? 'desc' : 'asc';
                    } else {
                        this.sortColumn = column;
                        this.sortDirection = 'asc';
                    }
                },
                
                getSortClass(column) {
                    if (this.sortColumn !== column) return 'sortable';
                    return this.sortDirection === 'asc' ? 'sortable sort-asc' : 'sortable sort-desc';
                },
                
                getScoreClass(score) {
                    if (score === null) return 'score-null';
                    if (score >= 80) return 'score-high';
                    if (score >= 60) return 'score-medium';
                    return 'score-low';
                }
            };
        }
        
        // Chart.js Implementation
        document.addEventListener('DOMContentLoaded', function() {
            // Register DataLabels plugin
            Chart.register(ChartDataLabels);

            // Chart defaults
            Chart.defaults.font.family = "-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif";
            Chart.defaults.color = '#64748b';
            
            // DataLabels defaults
            Chart.defaults.plugins.datalabels = {
                anchor: 'end',
                align: 'top',
                formatter: Math.round,
                font: { weight: 'bold', size: 11 },
                color: '#374151'
            };
            
            const areaColors = {
                'Lectura': '#3b82f6',
                'Matemáticas': '#ef4444',
                'Sociales': '#f59e0b',
                'Naturales': '#10b981',
                'Inglés': '#8b5cf6',
                'Global': '#6366f1'
            };
            
            // 1. Promedios por área
            const avgCtx = document.getElementById('chartAverages');
            if (avgCtx && reportData.areaStatistics) {
                const areas = reportData.areaStatistics.map(s => s.area);
                const avgs = reportData.areaStatistics.map(s => s.average);
                
                new Chart(avgCtx, {
                    type: 'bar',
                    data: {
                        labels: areas,
                        datasets: [{
                            label: 'Promedio',
                            data: avgs,
                            backgroundColor: areas.map(a => areaColors[a] || '#3b82f6'),
                            borderRadius: 6
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: { 
                            legend: { display: false },
                            datalabels: {
                                anchor: 'end',
                                align: 'top',
                                formatter: (value) => Math.round(value),
                                font: { weight: 'bold', size: 11 },
                                color: '#374151'
                            }
                        },
                        scales: {
                            y: { beginAtZero: true, max: 100, title: { display: true, text: 'Puntaje' } }
                        }
                    }
                });
            }
            
            // 2. Desviación estándar
            const stdCtx = document.getElementById('chartStdDev');
            if (stdCtx && reportData.areaStatistics) {
                const areas = reportData.areaStatistics.map(s => s.area);
                const stds = reportData.areaStatistics.map(s => s.stdDev);
                
                new Chart(stdCtx, {
                    type: 'bar',
                    data: {
                        labels: areas,
                        datasets: [{
                            label: 'Desv. Estándar',
                            data: stds,
                            backgroundColor: areas.map(a => areaColors[a] || '#3b82f6'),
                            borderRadius: 6
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: { 
                            legend: { display: false },
                            datalabels: {
                                anchor: 'end',
                                align: 'top',
                                formatter: (value) => Math.round(value * 100) / 100,
                                font: { weight: 'bold', size: 11 },
                                color: '#374151'
                            }
                        },
                        scales: {
                            y: { beginAtZero: true, title: { display: true, text: 'Desviación' } }
                        }
                    }
                });
            }
            
            // 3. Promedios por grupo
            const groupCtx = document.getElementById('chartGroups');
            if (groupCtx && reportData.groupComparison) {
                const groups = Object.keys(reportData.groupComparison);
                const allAreas = ['Lectura', 'Matemáticas', 'Sociales', 'Naturales', 'Inglés'];
                const areaKeyMap = {
                    'Lectura': 'lectura',
                    'Matemáticas': 'matematicas',
                    'Sociales': 'sociales',
                    'Naturales': 'naturales',
                    'Inglés': 'ingles'
                };
                
                const datasets = allAreas.map(area => ({
                    label: area,
                    data: groups.map(g => reportData.groupComparison[g][areaKeyMap[area]] || 0),
                    backgroundColor: areaColors[area]
                }));
                
                new Chart(groupCtx, {
                    type: 'bar',
                    data: {
                        labels: groups,
                        datasets: datasets
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: { 
                            legend: { position: 'bottom' },
                            datalabels: {
                                anchor: 'end',
                                align: 'top',
                                formatter: (value) => Math.round(value),
                                font: { weight: 'bold', size: 10 },
                                color: '#374151'
                            }
                        },
                        scales: {
                            y: { beginAtZero: true, max: 100 },
                            x: { stacked: false }
                        }
                    }
                });
            }
            
            // 4. Comparativo PIAR
            const piarCtx = document.getElementById('chartPiar');
            if (piarCtx && reportData.piarComparison) {
                const allAreas = ['Lectura', 'Matemáticas', 'Sociales', 'Naturales', 'Inglés'];
                const areaKeyMap = {
                    'Lectura': 'lectura',
                    'Matemáticas': 'matematicas',
                    'Sociales': 'sociales',
                    'Naturales': 'naturales',
                    'Inglés': 'ingles'
                };
                const piarData = allAreas.map(area => reportData.piarComparison.piar?.[areaKeyMap[area]] || 0);
                const nonPiarData = allAreas.map(area => reportData.piarComparison['non_piar']?.[areaKeyMap[area]] || 0);
                
                new Chart(piarCtx, {
                    type: 'bar',
                    data: {
                        labels: allAreas,
                        datasets: [
                            { label: 'Sin PIAR', data: nonPiarData, backgroundColor: '#3b82f6' },
                            { label: 'PIAR', data: piarData, backgroundColor: '#fbbf24' }
                        ]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: { 
                            legend: { position: 'bottom' },
                            datalabels: {
                                anchor: 'end',
                                align: 'top',
                                formatter: (value) => Math.round(value),
                                font: { weight: 'bold', size: 11 },
                                color: '#374151'
                            }
                        },
                        scales: {
                            y: { beginAtZero: true, max: 100 }
                        }
                    }
                });
            }
            
            // 4b. Comparativo PIAR (in section)
            const piarComparisonCtx = document.getElementById('chartPiarComparison');
            if (piarComparisonCtx && reportData.piarComparison) {
                const allAreas = ['Lectura', 'Matemáticas', 'Sociales', 'Naturales', 'Inglés'];
                const areaKeyMap = {
                    'Lectura': 'lectura',
                    'Matemáticas': 'matematicas',
                    'Sociales': 'sociales',
                    'Naturales': 'naturales',
                    'Inglés': 'ingles'
                };
                const piarData = allAreas.map(area => reportData.piarComparison.piar?.[areaKeyMap[area]]?.average || 0);
                const nonPiarData = allAreas.map(area => reportData.piarComparison['non_piar']?.[areaKeyMap[area]]?.average || 0);
                
                new Chart(piarComparisonCtx, {
                    type: 'bar',
                    data: {
                        labels: allAreas,
                        datasets: [
                            { label: 'Sin PIAR', data: nonPiarData, backgroundColor: '#3b82f6' },
                            { label: 'PIAR', data: piarData, backgroundColor: '#fbbf24' }
                        ]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: { 
                            legend: { position: 'bottom' },
                            datalabels: {
                                anchor: 'end',
                                align: 'top',
                                formatter: (value) => Math.round(value * 10) / 10,
                                font: { weight: 'bold', size: 11 },
                                color: '#374151'
                            }
                        },
                        scales: {
                            y: { beginAtZero: true, max: 100, title: { display: true, text: 'Promedio' } }
                        }
                    }
                });
            }

            // 5. Distribución global
            const distCtx = document.getElementById('chartDistribution');
            if (distCtx && reportData.distributions && reportData.distributions.global) {
                const dist = reportData.distributions.global;
                
                new Chart(distCtx, {
                    type: 'bar',
                    data: {
                        labels: dist.labels,
                        datasets: [{
                            label: 'Frecuencia',
                            data: dist.data,
                            backgroundColor: '#3b82f6',
                            borderRadius: 4
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: { 
                            legend: { display: false },
                            title: { display: true, text: 'Distribución de Puntajes Globales' },
                            datalabels: {
                                anchor: 'end',
                                align: 'top',
                                formatter: (value) => value,
                                font: { weight: 'bold', size: 11 },
                                color: '#374151'
                            }
                        },
                        scales: {
                            y: { beginAtZero: true, title: { display: true, text: 'Cantidad de Estudiantes' } },
                            x: { title: { display: true, text: 'Rango de Puntaje' } }
                        }
                    }
                });
            }
            
            // 6. Detailed Analysis Charts (if detailAnalysisData exists)
            if (typeof window.detailAnalysisData !== 'undefined') {
                Object.keys(window.detailAnalysisData).forEach(area => {
                    const areaData = window.detailAnalysisData[area];
                    const areaColor = areaColors[area] || '#3b82f6';
                    
                    // Chart for Dimension 1
                    const dim1Ctx = document.getElementById('chart' + area.charAt(0).toUpperCase() + area.slice(1) + 'Dim1');
                    if (dim1Ctx && areaData.statistics && areaData.statistics.dimension1) {
                        const dim1Items = areaData.statistics.dimension1;
                        const dim1Labels = dim1Items.map(item => item.itemName || item.name || 'N/A');
                        const dim1Values = dim1Items.map(item => item.average || 0);
                        
                        new Chart(dim1Ctx, {
                            type: 'bar',
                            data: {
                                labels: dim1Labels,
                                datasets: [{
                                    label: 'Promedio',
                                    data: dim1Values,
                                    backgroundColor: areaColor,
                                    borderRadius: 6
                                }]
                            },
                            options: {
                                responsive: true,
                                maintainAspectRatio: false,
                                plugins: {
                                    legend: { display: false },
                                    datalabels: {
                                        anchor: 'end',
                                        align: 'top',
                                        formatter: (value) => Math.round(value * 10) / 10,
                                        font: { weight: 'bold', size: 11 },
                                        color: '#374151'
                                    }
                                },
                                scales: {
                                    y: { beginAtZero: true, max: 100, title: { display: true, text: 'Puntaje' } }
                                }
                            }
                        });
                    }
                    
                    // Chart for Dimension 2
                    const dim2Ctx = document.getElementById('chart' + area.charAt(0).toUpperCase() + area.slice(1) + 'Dim2');
                    if (dim2Ctx && areaData.statistics && areaData.statistics.dimension2) {
                        const dim2Items = areaData.statistics.dimension2;
                        const dim2Labels = dim2Items.map(item => item.itemName || item.name || 'N/A');
                        const dim2Values = dim2Items.map(item => item.average || 0);
                        
                        new Chart(dim2Ctx, {
                            type: 'bar',
                            data: {
                                labels: dim2Labels,
                                datasets: [{
                                    label: 'Promedio',
                                    data: dim2Values,
                                    backgroundColor: areaColor,
                                    borderRadius: 6
                                }]
                            },
                            options: {
                                responsive: true,
                                maintainAspectRatio: false,
                                plugins: {
                                    legend: { display: false },
                                    datalabels: {
                                        anchor: 'end',
                                        align: 'top',
                                        formatter: (value) => Math.round(value * 10) / 10,
                                        font: { weight: 'bold', size: 11 },
                                        color: '#374151'
                                    }
                                },
                                scales: {
                                    y: { beginAtZero: true, max: 100, title: { display: true, text: 'Puntaje' } }
                                }
                            }
                        });
                    }
                    
                    // Chart for PIAR Comparison
                    const piarDetailCtx = document.getElementById('chart' + area.charAt(0).toUpperCase() + area.slice(1) + 'Piar');
                    if (piarDetailCtx && areaData.piarComparison && areaData.piarComparison.items) {
                        const items = areaData.piarComparison.items;
                        const piarLabels = items.map(item => item.name || 'N/A');
                        const piarData = items.map(item => item.piar_average || item.piarAverage || 0);
                        const nonPiarData = items.map(item => item.non_piar_average || item.nonPiarAverage || 0);
                        
                        new Chart(piarDetailCtx, {
                            type: 'bar',
                            data: {
                                labels: piarLabels,
                                datasets: [
                                    { label: 'Sin PIAR', data: nonPiarData, backgroundColor: '#3b82f6' },
                                    { label: 'PIAR', data: piarData, backgroundColor: '#fbbf24' }
                                ]
                            },
                            options: {
                                responsive: true,
                                maintainAspectRatio: false,
                                plugins: {
                                    legend: { position: 'bottom' },
                                    datalabels: {
                                        anchor: 'end',
                                        align: 'top',
                                        formatter: (value) => Math.round(value * 10) / 10,
                                        font: { weight: 'bold', size: 10 },
                                        color: '#374151'
                                    }
                                },
                                scales: {
                                    y: { beginAtZero: true, max: 100 }
                                }
                            }
                        });
                    }
                });
            }
        });
    </script>
</body>
</html>
