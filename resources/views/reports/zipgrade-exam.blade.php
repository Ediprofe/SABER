<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Informe {{ $exam->name }} - Sistema SABER (Zipgrade)</title>
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
        .group-charts-wrapper { display: flex; flex-direction: column; gap: 40px; }
        
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
            .group-charts-wrapper { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Header -->
        <div class="header">
            <h1>Informe de Análisis Zipgrade - {{ $exam->name }}</h1>
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
                <h2 class="card-title">Indicadores Principales</h2>
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
                <h2 class="card-title">Listado de Estudiantes</h2>
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
                            <th class="sortable" @click="sortBy('code')" :class="getSortClass('code')">Documento</th>
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
                                <td :class="getScoreClass(student.lectura)" x-text="student.lectura !== null ? student.lectura.toFixed(2) : 'N/A'"></td>
                                <td :class="getScoreClass(student.matematicas)" x-text="student.matematicas !== null ? student.matematicas.toFixed(2) : 'N/A'"></td>
                                <td :class="getScoreClass(student.sociales)" x-text="student.sociales !== null ? student.sociales.toFixed(2) : 'N/A'"></td>
                                <td :class="getScoreClass(student.naturales)" x-text="student.naturales !== null ? student.naturales.toFixed(2) : 'N/A'"></td>
                                <td :class="getScoreClass(student.ingles)" x-text="student.ingles !== null ? student.ingles.toFixed(2) : 'N/A'"></td>
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

        <!-- Top Performers -->
        <div class="card">
            <div class="card-header">
                <h2 class="card-title">Top 5 por Área</h2>
            </div>
            <div class="top-grid">
                @foreach($topPerformers as $area => $performers)
                <div class="top-card">
                    <div class="top-title">{{ $area === 'global' ? 'Puntaje Global' : ucfirst($area) }}</div>
                    <ul class="top-list">
                        @foreach($performers as $index => $performer)
                        <li class="top-item">
                            <span class="top-rank {{ $index === 0 ? 'gold' : ($index === 1 ? 'silver' : ($index === 2 ? 'bronze' : '')) }}">{{ $index + 1 }}</span>
                            <span class="top-name">{{ $performer->student->first_name ?? 'N/A' }} {{ $performer->student->last_name ?? '' }}</span>
                            <span class="top-score">{{ $area === 'global' ? $performer->global_score : number_format($performer->{$area}, 2) }}</span>
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
                <h2 class="card-title">Gráficos y Visualizaciones</h2>
            </div>
            <div class="chart-grid">
                <!-- Promedios por área (CON PIAR vs SIN PIAR) -->
                @if(isset($piarComparison['piar']) && isset($piarComparison['non_piar']))
                <div class="chart-container">
                    <div class="chart-title">Promedios por Área (CON PIAR vs SIN PIAR)</div>
                    <div class="chart-wrapper">
                        <canvas id="chartAverages"></canvas>
                    </div>
                </div>

                <!-- Desviación estándar (CON PIAR vs SIN PIAR) -->
                <div class="chart-container">
                    <div class="chart-title">Desviación Estándar por Área (CON PIAR vs SIN PIAR)</div>
                    <div class="chart-wrapper">
                        <canvas id="chartStdDev"></canvas>
                    </div>
                </div>
                @endif
                
                <!-- Promedios por grupo -->
                @if(!empty($groupComparison))
                <div class="chart-container">
                    <div class="chart-title">Promedios por Grupo (CON PIAR vs SIN PIAR)</div>
                    <div class="group-charts-wrapper" id="groupChartsContainer">
                        <!-- Canvas generados dinámicamente -->
                    </div>
                </div>
                @endif
                
                <!-- Distribución global -->
                @if(!empty($distributions['global']))
                <div class="chart-container">
                    <div class="chart-title">Distribución de Puntajes Globales</div>
                    <div class="chart-wrapper" style="height: 400px;">
                        <canvas id="chartDistribution"></canvas>
                    </div>
                </div>
                @endif
            </div>
        </div>

        <!-- Footer -->
        <div class="footer">
            <p>Sistema SABER - Análisis ICFES (Zipgrade) | Generado el {{ $generatedAt }}</p>
            <p>Este informe funciona completamente offline</p>
        </div>
    </div>

    <!-- Embedded Data -->
    <script id="report-data" type="application/json">
    {
        "students": {!! json_encode($results->map(function($result) {
            return [
                'code' => $result->student->document_id ?? $result->student->code ?? 'N/A',
                'first_name' => $result->student->first_name ?? 'N/A',
                'last_name' => $result->student->last_name ?? '',
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
            if (typeof ChartDataLabels !== 'undefined') {
                Chart.register(ChartDataLabels);
            }

            // Chart defaults
            Chart.defaults.font.family = "-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif";
            Chart.defaults.color = '#64748b';
            
            // DataLabels defaults
            Chart.defaults.plugins.datalabels = {
                anchor: 'end',
                align: 'top',
                formatter: Math.round,
                font: { weight: 'bold', size: 10 },
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

            // Mapeo
            const allAreas = ['Lectura', 'Matemáticas', 'Sociales', 'Naturales', 'Inglés'];
            const areaKeyMap = {
                'Lectura': 'lectura',
                'Matemáticas': 'matematicas',
                'Sociales': 'sociales',
                'Naturales': 'naturales',
                'Inglés': 'ingles'
            };
            
            // 1. Promedios por área (Comparativo PIAR)
            const avgCtx = document.getElementById('chartAverages');
            if (avgCtx && reportData.piarComparison && reportData.piarComparison.piar && reportData.piarComparison.non_piar) {
                
                const piarData = allAreas.map(area => reportData.piarComparison.piar[areaKeyMap[area]]?.average || 0);
                const nonPiarData = allAreas.map(area => reportData.piarComparison.non_piar[areaKeyMap[area]]?.average || 0);
                
                // CON PIAR = gris, SIN PIAR = color del área
                const piarColors = '#9ca3af'; // Gris constante para CON PIAR
                const nonPiarColors = allAreas.map(area => areaColors[area]); // Color área para SIN PIAR

                // Calcular máximo dinámico
                const maxValue = Math.max(...piarData, ...nonPiarData) * 1.15;
                
                new Chart(avgCtx, {
                    type: 'bar',
                    data: {
                        labels: allAreas,
                        datasets: [
                            { 
                                label: 'SIN PIAR', 
                                data: nonPiarData, 
                                backgroundColor: nonPiarColors,
                                borderRadius: 6
                            },
                            { 
                                label: 'CON PIAR', 
                                data: piarData, 
                                backgroundColor: piarColors,
                                borderRadius: 6
                            }
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
                                formatter: (value) => Math.round(value * 100) / 100,
                            },
                        },
                        scales: {
                            y: { beginAtZero: true, max: Math.min(maxValue, 100), title: { display: true, text: 'Puntaje Promedio' } }
                        }
                    }
                });
            }
            
            // 2. Desviación estándar (Comparativo PIAR)
            const stdCtx = document.getElementById('chartStdDev');
            if (stdCtx && reportData.piarComparison && reportData.piarComparison.piar && reportData.piarComparison.non_piar) {

                const piarStds = allAreas.map(area => reportData.piarComparison.piar[areaKeyMap[area]]?.stdDev || 0);
                const nonPiarStds = allAreas.map(area => reportData.piarComparison.non_piar[areaKeyMap[area]]?.stdDev || 0);
                
                // CON PIAR = gris, SIN PIAR = color del área
                const piarColors = '#9ca3af'; 
                const nonPiarColors = allAreas.map(area => areaColors[area]);

                // Calcular máximo dinámico
                const maxValue = Math.max(...piarStds, ...nonPiarStds) * 1.15;
                
                new Chart(stdCtx, {
                    type: 'bar',
                    data: {
                        labels: allAreas,
                        datasets: [
                            { 
                                label: 'SIN PIAR', 
                                data: nonPiarStds, 
                                backgroundColor: nonPiarColors,
                                borderRadius: 6
                            },
                            { 
                                label: 'CON PIAR', 
                                data: piarStds, 
                                backgroundColor: piarColors,
                                borderRadius: 6
                            }
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
                                formatter: (value) => (Math.round(value * 100) / 100).toFixed(2),
                            }
                        },
                        scales: {
                            y: { beginAtZero: true, max: Math.ceil(maxValue), title: { display: true, text: 'Desviación Estándar' } }
                        }
                    }
                });
            }
            
            // 3. Promedios por grupo
            const groupContainer = document.getElementById('groupChartsContainer');
            if (groupContainer && reportData.groupComparison) {
                const groups = Object.keys(reportData.groupComparison);
                
                groups.forEach(groupKey => {
                    const groupData = reportData.groupComparison[groupKey];
                    
                    if (!groupData) return;
                    
                    // Crear wrapper y canvas
                    const wrapper = document.createElement('div');
                    wrapper.style.display = 'block';
                    wrapper.style.marginBottom = '40px';
                    wrapper.style.background = '#fff';
                    wrapper.style.padding = '10px';
                    wrapper.style.borderRadius = '8px';
                    
                    const title = document.createElement('h3');
                    title.innerText = `Grupo ${groupKey}`;
                    title.style.textAlign = 'center';
                    title.style.marginBottom = '15px';
                    title.style.color = '#1f2937';
                    title.style.fontSize = '16px';
                    
                    const canvasContainer = document.createElement('div');
                    canvasContainer.style.height = '250px';
                    canvasContainer.style.position = 'relative';
                    
                    const canvas = document.createElement('canvas');
                    
                    canvasContainer.appendChild(canvas);
                    wrapper.appendChild(title);
                    wrapper.appendChild(canvasContainer);
                    groupContainer.appendChild(wrapper);
                    
                    // Preparar datos
                    const piarData = allAreas.map(area => groupData[areaKeyMap[area]]?.piar || 0);
                    const nonPiarData = allAreas.map(area => groupData[areaKeyMap[area]]?.non_piar || 0);
                    
                    // CON PIAR = gris, SIN PIAR = color del área
                    const piarColors = '#9ca3af'; 
                    const nonPiarColors = allAreas.map(area => areaColors[area]);
                    
                    const maxValue = Math.max(...piarData, ...nonPiarData) * 1.15;
                    
                    new Chart(canvas, {
                        type: 'bar',
                        data: {
                            labels: allAreas,
                            datasets: [
                                { 
                                    label: 'SIN PIAR', 
                                    data: nonPiarData, 
                                    backgroundColor: nonPiarColors,
                                    borderRadius: 6
                                },
                                { 
                                    label: 'CON PIAR', 
                                    data: piarData, 
                                    backgroundColor: piarColors,
                                    borderRadius: 6
                                }
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
                                    formatter: Math.round,
                                }
                            },
                            scales: {
                                y: { beginAtZero: true, max: Math.min(maxValue, 100) }
                            }
                        }
                    });
                });
            }
            
            // 4. Distribución (Se mantiene)
            const distCtx = document.getElementById('chartDistribution');
            if (distCtx && reportData.distributions && reportData.distributions.global) {
                const distData = reportData.distributions.global;
                const labels = distData.map(d => d.range);
                const data = distData.map(d => d.count);
                
                new Chart(distCtx, {
                    type: 'bar',
                    data: {
                        labels: labels,
                        datasets: [{
                            label: 'Cantidad de Estudiantes',
                            data: data,
                            backgroundColor: '#3b82f6',
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
                                formatter: (value) => value,
                            }
                        },
                        scales: {
                            y: { beginAtZero: true, title: { display: true, text: 'Cantidad' } },
                            x: { title: { display: true, text: 'Rango de Puntaje' } }
                        }
                    }
                });
            }
        });
    </script>
</body>
</html>
