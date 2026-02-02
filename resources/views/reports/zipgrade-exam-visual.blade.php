<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Informe {{ $exam->name }} - Sistema SABER</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { 
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; 
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: #1f2937; 
            line-height: 1.6;
            padding: 20px;
        }
        .container { 
            max-width: 1400px; 
            margin: 0 auto; 
            background: white;
            border-radius: 20px;
            padding: 40px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
        }
        
        /* Header con gradiente */
        .header { 
            background: linear-gradient(135deg, #1e3a8a 0%, #3b82f6 100%); 
            color: white; 
            padding: 40px; 
            border-radius: 16px; 
            margin-bottom: 32px; 
            box-shadow: 0 8px 16px rgba(0, 0, 0, 0.15);
        }
        .header h1 { font-size: 36px; font-weight: 800; margin-bottom: 8px; }
        .header-subtitle { font-size: 18px; opacity: 0.95; margin-bottom: 20px; }
        .header-meta { 
            display: flex; 
            gap: 32px; 
            flex-wrap: wrap; 
            font-size: 15px; 
            background: rgba(255,255,255,0.1);
            padding: 16px;
            border-radius: 8px;
        }
        .header-meta-item { display: flex; align-items: center; gap: 8px; }
        
        /* KPIs modernos */
        .kpi-section { margin-bottom: 40px; }
        .section-title {
            font-size: 24px;
            font-weight: 700;
            color: #1e293b;
            margin-bottom: 24px;
            padding-bottom: 12px;
            border-bottom: 3px solid #3b82f6;
        }
        .kpi-grid { 
            display: grid; 
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); 
            gap: 20px;
            margin-bottom: 32px;
        }
        .kpi-card { 
            background: linear-gradient(135deg, #f0f9ff 0%, #e0f2fe 100%); 
            border-left: 5px solid #3b82f6; 
            padding: 24px; 
            border-radius: 12px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.08);
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .kpi-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 8px 20px rgba(0,0,0,0.12);
        }
        .kpi-label { 
            font-size: 13px; 
            font-weight: 700; 
            color: #64748b; 
            text-transform: uppercase; 
            letter-spacing: 0.5px; 
            margin-bottom: 8px; 
        }
        .kpi-value { 
            font-size: 36px; 
            font-weight: 800; 
            color: #1e40af; 
        }
        .kpi-value-small { 
            font-size: 20px; 
            color: #475569; 
            font-weight: 600;
        }
        
        /* Sección de gráficos por área */
        .charts-section { margin-bottom: 40px; }
        .area-section {
            margin-bottom: 48px;
            page-break-inside: avoid;
        }
        .area-header {
            font-size: 28px;
            font-weight: 800;
            color: #0f172a;
            margin-bottom: 24px;
            padding: 16px;
            border-radius: 12px;
            text-align: center;
        }
        .area-header.lectura { background: linear-gradient(135deg, #dbeafe 0%, #bfdbfe 100%); color: #1e40af; }
        .area-header.matematicas { background: linear-gradient(135deg, #fee2e2 0%, #fecaca 100%); color: #991b1b; }
        .area-header.sociales { background: linear-gradient(135deg, #fed7aa 0%, #fdba74 100%); color: #9a3412; }
        .area-header.naturales { background: linear-gradient(135deg, #d1fae5 0%, #a7f3d0 100%); color: #065f46; }
        .area-header.ingles { background: linear-gradient(135deg, #e9d5ff 0%, #d8b4fe 100%); color: #6b21a8; }
        
        .charts-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 24px;
            margin-bottom: 32px;
        }
        @media (max-width: 1024px) {
            .charts-grid {
                grid-template-columns: 1fr;
            }
        }
        .chart-card {
            background: white;
            padding: 24px;
            border-radius: 12px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.08);
            border: 1px solid #e5e7eb;
        }
        .chart-title {
            font-size: 18px;
            font-weight: 700;
            color: #374151;
            margin-bottom: 20px;
            text-align: center;
            padding-bottom: 12px;
            border-bottom: 2px solid #e5e7eb;
        }
        .chart-wrapper {
            position: relative;
            height: 320px;
        }
        
        /* Página de distribuciones */
        .distribution-section {
            margin-top: 48px;
            page-break-before: always;
        }
        
        /* Print styles */
        @media print {
            body { background: white; padding: 0; }
            .container { box-shadow: none; }
            .area-section { page-break-inside: avoid; }
            .kpi-card:hover { transform: none; }
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Header -->
        <div class="header">
            <h1>📊 {{ $exam->name }}</h1>
            <div class="header-subtitle">Informe Visual de Resultados Zipgrade</div>
            <div class="header-meta">
                <div class="header-meta-item">
                    <span>📅</span>
                    <span><strong>Fecha:</strong> {{ $exam->date ? $exam->date->format('d/m/Y') : 'N/A' }}</span>
                </div>
                <div class="header-meta-item">
                    <span>🎓</span>
                    <span><strong>Grado:</strong> {{ $exam->grade ?? 'N/A' }}</span>
                </div>
                <div class="header-meta-item">
                    <span>📚</span>
                    <span><strong>Periodo:</strong> {{ $exam->period ?? 'N/A' }}</span>
                </div>
                <div class="header-meta-item">
                    <span>👥</span>
                    <span><strong>Estudiantes:</strong> {{ $statistics->totalStudents }}</span>
                </div>
            </div>
        </div>

        <!-- KPIs -->
        <div class="kpi-section">
            <h2 class="section-title">📈 Indicadores Clave</h2>
            <div class="kpi-grid">
                <div class="kpi-card">
                    <div class="kpi-label">Total Estudiantes</div>
                    <div class="kpi-value">{{ $statistics->totalStudents }}</div>
                </div>
                <div class="kpi-card">
                    <div class="kpi-label">Promedio Global</div>
                    <div class="kpi-value">{{ number_format($statistics->globalAverage, 1) }}</div>
                    <div class="kpi-value-small">/ 500 pts</div>
                </div>
                <div class="kpi-card">
                    <div class="kpi-label">Con PIAR</div>
                    <div class="kpi-value">{{ $piarComparison['piar_count'] ?? 0 }}</div>
                    <div class="kpi-value-small">{{ $statistics->totalStudents > 0 ? number_format(($piarComparison['piar_count'] ?? 0) / $statistics->totalStudents * 100, 1) : 0 }}%</div>
                </div>
                <div class="kpi-card">
                    <div class="kpi-label">Sin PIAR</div>
                    <div class="kpi-value">{{ $piarComparison['non_piar_count'] ?? 0 }}</div>
                    <div class="kpi-value-small">{{ $statistics->totalStudents > 0 ? number_format(($piarComparison['non_piar_count'] ?? 0) / $statistics->totalStudents * 100, 1) : 0 }}%</div>
                </div>
                <div class="kpi-card">
                    <div class="kpi-label">Desviación Estándar</div>
                    <div class="kpi-value">{{ number_format($statistics->globalStdDev, 1) }}</div>
                </div>
            </div>
        </div>

        <!-- Gráficos por Área -->
        <div class="charts-section">
            <h2 class="section-title">📊 Análisis por Área Académica</h2>
            
            @php
            $areas = [
                'lectura' => ['name' => 'Lectura Crítica', 'color' => '#3b82f6'],
                'matematicas' => ['name' => 'Matemáticas', 'color' => '#ef4444'],
                'sociales' => ['name' => 'Ciencias Sociales', 'color' => '#f59e0b'],
                'naturales' => ['name' => 'Ciencias Naturales', 'color' => '#10b981'],
                'ingles' => ['name' => 'Inglés', 'color' => '#8b5cf6'],
            ];
            @endphp

            @foreach($areas as $areaKey => $areaData)
            <div class="area-section">
                <div class="area-header {{ $areaKey }}">{{ $areaData['name'] }}</div>
                
                <div class="charts-grid">
                    <!-- Promedio -->
                    <div class="chart-card">
                        <div class="chart-title">Promedio - CON PIAR vs SIN PIAR</div>
                        <div class="chart-wrapper">
                            <canvas id="chart-avg-{{ $areaKey }}"></canvas>
                        </div>
                    </div>
                    
                    <!-- Desviación Estándar -->
                    <div class="chart-card">
                        <div class="chart-title">Desviación Estándar - CON PIAR vs SIN PIAR</div>
                        <div class="chart-wrapper">
                            <canvas id="chart-std-{{ $areaKey }}"></canvas>
                        </div>
                    </div>
                </div>
            </div>
            @endforeach
        </div>

        <!-- Distribuciones Globales -->
        <div class="distribution-section">
            <h2 class="section-title">📊 Distribución de Puntajes</h2>
            <div class="charts-grid">
                <div class="chart-card">
                    <div class="chart-title">Distribución Global de Puntajes</div>
                    <div class="chart-wrapper">
                        <canvas id="chart-distribution"></canvas>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Datos JSON ocultos -->
    <script type="application/json" id="report-data">
    {
        "piarComparison": {!! json_encode($piarComparison) !!},
        "areaStatistics": {!! json_encode($statistics->areaStatistics) !!},
        "distributions": {!! json_encode($distributions) !!}
    }
    </script>

    <!-- Chart.js CDN -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.x.x/dist/chart.umd.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels@2.x.x"></script>

    <!-- Charts Logic -->
    <script>
        const reportData = JSON.parse(document.getElementById('report-data').textContent);
        
        // Configuración de colores
        const colors = {
            lectura: '#3b82f6',
            matematicas: '#ef4444',
            sociales: '#f59e0b',
            naturales: '#10b981',
            ingles: '#8b5cf6',
            conPiar: '#9ca3af', // Gris para CON PIAR
        };

        // Mapeo de áreas
        const areaKeyMap = {
            'lectura': 'lectura',
            'matematicas': 'matematicas',
            'sociales': 'sociales',
            'naturales': 'naturales',
            'ingles': 'ingles'
        };

        // Función para calcular desviación estándar por área
        function getAreaStdDev(areaKey) {
            const areaStats = reportData.areaStatistics.find(a => 
                a.area.toLowerCase().includes(areaKey) || 
                areaKey === 'lectura' && a.area.includes('Lectura') ||
                areaKey === 'matematicas' && a.area.includes('Matemáticas') ||
                areaKey === 'sociales' && a.area.includes('Sociales') ||
                areaKey === 'naturales' && a.area.includes('Naturales') ||
                areaKey === 'ingles' && a.area.includes('Inglés')
            );
            return areaStats ? areaStats.stdDev : 0;
        }

        // Crear gráficos para cada área
        Object.keys(areaKeyMap).forEach(areaKey => {
            const conPiarAvg = reportData.piarComparison.piar?.[areaKeyMap[areaKey]]?.average || 0;
            const sinPiarAvg = reportData.piarComparison.non_piar?.[areaKeyMap[areaKey]]?.average || 0;
            const areaStdDev = getAreaStdDev(areaKey);
            
            // Calcular máximo para escala Y (con margen del 15%)
            const maxAvg = Math.max(conPiarAvg, sinPiarAvg) * 1.15;
            const maxStd = areaStdDev * 1.15;

            // Gráfico de Promedio
            const ctxAvg = document.getElementById(`chart-avg-${areaKey}`);
            if (ctxAvg) {
                new Chart(ctxAvg, {
                    type: 'bar',
                    data: {
                        labels: ['CON PIAR', 'SIN PIAR'],
                        datasets: [{
                            data: [conPiarAvg, sinPiarAvg],
                            backgroundColor: [colors.conPiar, colors[areaKey]],
                            borderColor: [colors.conPiar, colors[areaKey]],
                            borderWidth: 2,
                            borderRadius: 8,
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
                                formatter: (value) => value.toFixed(1),
                                font: { weight: 'bold', size: 14 },
                                color: '#374151'
                            }
                        },
                        scales: {
                            y: { 
                                beginAtZero: true, 
                                max: Math.min(maxAvg, 100),
                                ticks: {
                                    font: { size: 12 }
                                },
                                grid: {
                                    color: '#e5e7eb'
                                }
                            },
                            x: {
                                ticks: {
                                    font: { size: 13, weight: 'bold' }
                                },
                                grid: {
                                    display: false
                                }
                            }
                        }
                    }
                });
            }

            // Gráfico de Desviación Estándar
            const ctxStd = document.getElementById(`chart-std-${areaKey}`);
            if (ctxStd) {
                new Chart(ctxStd, {
                    type: 'bar',
                    data: {
                        labels: ['Desviación Estándar'],
                        datasets: [{
                            label: areaKey.charAt(0).toUpperCase() + areaKey.slice(1),
                            data: [areaStdDev],
                            backgroundColor: [colors[areaKey]],
                            borderColor: [colors[areaKey]],
                            borderWidth: 2,
                            borderRadius: 8,
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
                                formatter: (value) => value.toFixed(2),
                                font: { weight: 'bold', size: 14 },
                                color: '#374151'
                            }
                        },
                        scales: {
                            y: { 
                                beginAtZero: true, 
                                max: Math.ceil(maxStd),
                                ticks: {
                                    font: { size: 12 }
                                },
                                grid: {
                                    color: '#e5e7eb'
                                }
                            },
                            x: {
                                ticks: {
                                    font: { size: 13, weight: 'bold' }
                                },
                                grid: {
                                    display: false
                                }
                            }
                        }
                    }
                });
            }
        });

        // Gráfico de Distribución Global
        const distCtx = document.getElementById('chart-distribution');
        if (distCtx && reportData.distributions && reportData.distributions.global) {
            const distData = reportData.distributions.global;
            const labels = distData.map(d => d.range);
            const data = distData.map(d => d.count);
            
            new Chart(distCtx, {
                type: 'bar',
                data: {
                    labels: labels,
                    datasets: [{
                        label: 'Número de Estudiantes',
                        data: data,
                        backgroundColor: '#3b82f6',
                        borderColor: '#2563eb',
                        borderWidth: 2,
                        borderRadius: 6,
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { 
                            display: true,
                            position: 'top',
                            labels: {
                                font: { size: 14, weight: 'bold' }
                            }
                        },
                        datalabels: {
                            anchor: 'end',
                            align: 'top',
                            formatter: (value) => value,
                            font: { weight: 'bold', size: 12 },
                            color: '#374151'
                        }
                    },
                    scales: {
                        y: { 
                            beginAtZero: true,
                            title: {
                                display: true,
                                text: 'Cantidad de Estudiantes',
                                font: { size: 13, weight: 'bold' }
                            },
                            ticks: {
                                stepSize: 1,
                                font: { size: 12 }
                            }
                        },
                        x: {
                            title: {
                                display: true,
                                text: 'Rango de Puntaje',
                                font: { size: 13, weight: 'bold' }
                            },
                            ticks: {
                                font: { size: 11 }
                            }
                        }
                    }
                }
            });
        }
    </script>
</body>
</html>
