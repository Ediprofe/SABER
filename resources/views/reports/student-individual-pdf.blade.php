<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>Informe Individual - {{ $student['full_name'] }}</title>
    <style>
        /* CONFIGURACIÓN BASE - MÁRGENES DE 2.5CM REALES */
        @page {
            margin: 2.5cm;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'DejaVu Sans', sans-serif;
            font-size: 10px;
            line-height: 1.5;
            color: #1f2937;
            background: #fff;
            width: 100%;
        }

        /* COLORES Y FUENTES */
        :root {
            --primary: #1e3a8a;
            --success: #059669;
            --danger: #dc2626;
            --border: #e5e7eb;
        }

        .text-primary { color: #1e3a8a; }
        .text-success { color: #059669; }
        .text-danger { color: #dc2626; }
        .font-bold { font-weight: bold; }

        /* HEADER PRINCIPAL */
        .header {
            text-align: center;
            border-bottom: 2px solid #1e3a8a;
            padding-bottom: 15px;
            margin-bottom: 30px;
            margin-top: 1.5cm; /* BAJAR EL TÍTULO */
        }

        .header h1 {
            font-size: 18px;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: #1e3a8a;
            margin-bottom: 5px;
        }

        .header .exam-info {
            font-size: 11px;
            color: #6b7280;
        }

        /* INFORMACIÓN DEL ESTUDIANTE CENTRADA */
        .info-card {
            padding: 10px 0 30px 0;
            margin-bottom: 40px;
            text-align: center;
            border-bottom: 1px solid #f3f4f6;
        }

        .student-name {
            font-size: 22px;
            color: #1e3a8a;
            font-weight: bold;
            margin-bottom: 15px;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .student-meta {
            font-size: 11px;
            color: #4b5563;
        }

        .meta-item {
            display: inline-block;
            margin: 0 20px;
        }

        .meta-label {
            font-weight: bold;
            color: #1f2937;
            text-transform: uppercase;
            margin-right: 5px;
        }

        .meta-value {
            color: #1e3a8a;
        }

        /* PUNTAJE GLOBAL */
        .global-score-container {
            margin-bottom: 50px;
            text-align: center;
        }

        .score-box {
            display: inline-block;
            background: #1e3a8a;
            color: white;
            padding: 35px 80px;
            border-radius: 20px;
            box-shadow: 0 10px 20px rgba(30, 58, 138, 0.15);
        }

        .score-box .label {
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 4px;
            opacity: 0.9;
            margin-bottom: 10px;
        }

        .score-box .value {
            font-size: 56px;
            font-weight: bold;
            line-height: 1;
        }

        .score-box .total {
            font-size: 18px;
            font-weight: normal;
            opacity: 0.8;
            vertical-align: middle;
            margin-left: 8px;
        }

        /* TÍTULOS DE SECCIÓN CENTRADOS */
        .section-header {
            background: #1e3a8a;
            color: white;
            padding: 10px 20px;
            font-size: 12px;
            font-weight: bold;
            border-radius: 4px;
            margin: 30px 0 20px 0;
            text-transform: uppercase;
            text-align: center; /* CENTRADO */
            letter-spacing: 1px;
        }

        /* TABLAS QUE RESPETAN MÁRGENES */
        .data-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 25px;
            table-layout: fixed;
        }

        .data-table th {
            background: #f8fafc;
            color: #1e3a8a;
            font-weight: bold;
            text-transform: uppercase;
            font-size: 9px;
            padding: 12px 10px;
            border: 1px solid #e2e8f0;
            text-align: center;
        }

        .data-table td {
            padding: 12px 10px;
            border: 1px solid #e2e8f0;
            text-align: center;
            font-size: 11px;
            word-wrap: break-word;
        }

        .data-table tr:nth-child(even) {
            background: #f9fafb;
        }

        .text-left { text-align: left !important; }

        /* ANALISIS DETALLADO */
        .area-card {
            margin-bottom: 40px;
        }

        .area-card-header {
            font-size: 16px;
            font-weight: bold;
            color: #1e3a8a;
            border-bottom: 2px solid #1e3a8a;
            padding: 10px 0;
            margin-bottom: 25px;
            text-transform: uppercase;
            text-align: center; /* CENTRADO */
        }

        .area-card-header .score-label {
            display: block;
            font-size: 12px;
            font-weight: normal;
            color: #64748b;
            margin-top: 5px;
        }

        .dim-grid {
            margin-bottom: 30px;
        }

        .dim-title {
            font-size: 11px;
            font-weight: bold;
            color: #1e3a8a;
            margin-bottom: 12px;
            text-transform: uppercase;
            text-align: center; /* TITULO DE DIMENSIÓN TAMBIÉN CENTRADO */
            border-bottom: 1px solid #e5e7eb;
            padding-bottom: 5px;
        }

        /* INCORRECTAS */
        .incorrect-area-title {
            color: #1e3a8a;
            font-size: 14px;
            font-weight: bold;
            margin-bottom: 20px;
            border-bottom: 2px solid #ef4444;
            padding-bottom: 10px;
            text-transform: uppercase;
            text-align: center; /* CENTRADO */
        }

        .summary-mini-box {
            background: #fff5f5;
            border-left: 5px solid #ef4444;
            padding: 15px;
            margin-bottom: 20px;
            font-size: 11px;
            text-align: center;
        }

        .incorrect-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 10px;
            table-layout: fixed;
        }

        .incorrect-table th {
            background: #fee2e2;
            color: #b91c1c;
            padding: 10px 5px;
            border: 1px solid #fecaca;
            font-weight: bold;
            text-transform: uppercase;
        }

        .incorrect-table td {
            padding: 10px 5px;
            border: 1px solid #fee2e2;
            text-align: center;
            word-wrap: break-word;
        }

        /* BALANCE GENERAL */
        .stats-grid {
            width: 100%;
            margin-bottom: 30px;
            border-collapse: collapse;
        }

        .stat-box {
            text-align: center;
            padding: 20px;
            border: 1px solid #e5e7eb;
            background: #fff;
        }

        .stat-value {
            font-size: 24px;
            font-weight: bold;
            display: block;
            margin-bottom: 5px;
        }

        .stat-label {
            font-size: 9px;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        /* UTILIDADES */
        .page-break { page-break-after: always; }
        
        .footer {
            position: fixed;
            bottom: -20px; /* Ajustado para el nuevo margen inferior */
            left: 0;
            right: 0;
            text-align: center;
            font-size: 9px;
            color: #9ca3af;
            border-top: 1px solid #f3f4f6;
            padding-top: 5px;
        }

    </style>
</head>
<body>
    <div class="footer">
        Este informe es de uso académico y confidencial — Generado por Sistema SABER el {{ $generatedAt }}
    </div>

    {{-- PÁGINA 1: RESUMEN EJECUTIVO --}}
    <div class="header">
        <h1>Informe Individual de Resultados</h1>
        <div class="exam-info">{{ $exam['name'] }} &bull; {{ $exam['date'] }}</div>
    </div>

    <div class="info-card">
        <div class="student-name">{{ mb_strtoupper($student['full_name'], 'UTF-8') }}</div>
        <div class="student-meta">
            <div style="margin-top: 5px;">
                <span style="font-size: 11px; color: #64748b; text-transform: uppercase; letter-spacing: 2px;">Grupo</span>
                <span style="font-size: 16px; color: #1e3a8a; font-weight: bold; margin-left: 5px;">{{ $student['group'] }}</span>
            </div>
        </div>
    </div>

    <div class="global-score-container">
        <div class="score-box">
            <div class="label">Puntaje Global</div>
            <div class="value">{{ $scores['global'] }}<span class="total">/ 500</span></div>
        </div>
    </div>

    <div class="section-header">Resumen de Desempeño por Áreas</div>

    <table class="data-table">
        <thead>
            <tr>
                <th class="text-left" style="width: 65%;">Área Evaluable</th>
                <th style="width: 35%;">Puntaje Obtenido</th>
            </tr>
        </thead>
        <tbody>
            @foreach($scores['areas'] as $key => $area)
            <tr>
                <td class="text-left font-bold" style="color: #1f2937;">{{ $area['label'] }}</td>
                <td class="font-bold" style="color: #1e3a8a; font-size: 14px;">{{ number_format($area['score'], 1) }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>

    {{-- ANÁLISIS DETALLADO: CADA ÁREA EN SU PÁGINA --}}
    @foreach($dimensions as $areaKey => $areaData)
        <div class="page-break"></div>
        
        <div class="area-card">
            <div class="area-card-header">
                {{ mb_strtoupper($areaData['label'], 'UTF-8') }}
                <span class="score-label">Puntaje Alcanzado: <strong>{{ number_format($areaData['area_score'], 1) }}</strong></span>
            </div>

            @foreach($areaData['dimensions'] as $dimType => $items)
                <div class="dim-grid">
                    @php
                        $dimLabel = match($dimType) {
                            'competencia' => 'Competencias',
                            'componente' => 'Componentes',
                            'parte' => 'Estructura de Partes',
                            'tipo_texto' => 'Tipos de Texto',
                            'nivel_lectura' => 'Niveles de Lectura',
                            default => ucfirst($dimType)
                        };
                    @endphp
                    <div class="dim-title">{{ $dimLabel }}</div>
                    <table class="data-table">
                        <thead>
                            <tr style="background: #f8fafc;">
                                <th class="text-left" style="width: 70%;">Detalle de la Dimensión</th>
                                <th style="width: 30%;">Acierto (%)</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($items as $itemName => $itemScore)
                            <tr>
                                <td class="text-left">{{ $itemName }}</td>
                                <td class="font-bold" style="color: #1e3a8a; font-size: 12px;">{{ number_format($itemScore, 0) }}%</td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endforeach
        </div>
    @endforeach

    {{-- PÁGINAS DE INCORRECTAS --}}
    @if($totalIncorrect > 0)
        @foreach($incorrectByArea as $areaKey => $areaData)
            <div class="page-break"></div>

            <div class="incorrect-area-title">
                Análisis de Errores: {{ mb_strtoupper($areaData['label'], 'UTF-8') }}
            </div>

            <div class="summary-mini-box">
                Se detectaron <strong>{{ $areaData['count'] }}</strong> respuestas incorrectas de <strong>{{ $incorrectSummary[$areaKey]['total'] ?? '—' }}</strong> preguntas evaluadas. 
                Tasa de error en esta sección: <strong>{{ $incorrectSummary[$areaKey]['error_rate'] ?? '—' }}%</strong>.
            </div>

            <table class="incorrect-table">
                <thead>
                    <tr>
                        <th style="width: 15%;">Sesión</th>
                        <th style="width: 10%;">#</th>
                        <th style="width: 15%;">Correcta</th>
                        @foreach($areaData['dimensions'] as $dim)
                            <th>{{ $dim['name'] }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach($areaData['questions'] as $answer)
                    <tr>
                        <td>{{ $answer['session'] }}</td>
                        <td class="font-bold">{{ $answer['question_number'] }}</td>
                        <td class="text-success font-bold" style="background: #f0fdf4;">
                            {{ $answer['correct_answer'] }}
                        </td>
                        @foreach($areaData['dimensions'] as $dim)
                            <td>{{ $answer[$dim['field']] ?: '—' }}</td>
                        @endforeach
                    </tr>
                    @endforeach
                </tbody>
            </table>
        @endforeach

        {{-- BALANCE GENERAL --}}
        <div class="page-break"></div>
        <div class="section-header">Balance General de la Prueba</div>

        <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 30px;">
            <table class="stats-grid">
                <tr>
                    <td class="stat-box" style="border-radius: 8px 0 0 8px;">
                        <span class="stat-value" style="color: #1e3a8a;">{{ $totalQuestions }}</span>
                        <span class="stat-label">Total Preguntas</span>
                    </td>
                    <td class="stat-box">
                        <span class="stat-value" style="color: #059669;">{{ $totalQuestions - $totalIncorrect }}</span>
                        <span class="stat-label">Total Aciertos</span>
                    </td>
                    <td class="stat-box">
                        <span class="stat-value" style="color: #dc2626;">{{ $totalIncorrect }}</span>
                        <span class="stat-label">Total Errores</span>
                    </td>
                    <td class="stat-box" style="border-radius: 0 8px 8px 0; border-right: 1px solid #e5e7eb;">
                        <span class="stat-value" style="color: #3b82f6;">{{ number_format((($totalQuestions - $totalIncorrect) / max($totalQuestions, 1)) * 100, 1) }}%</span>
                        <span class="stat-label">% Global Acierto</span>
                    </td>
                </tr>
            </table>

            <div class="dim-title" style="margin-top: 10px;">Desglose Local de Errores por Área</div>
            <table class="data-table">
                <thead>
                    <tr>
                        <th class="text-left">Área</th>
                        <th>Errores</th>
                        <th>Preguntas</th>
                        <th>Tasa de Error</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($incorrectSummary as $area => $data)
                    <tr>
                        <td class="text-left font-bold" style="color: #1f2937;">{{ $data['label'] }}</td>
                        <td class="text-danger font-bold">{{ $data['incorrect'] }}</td>
                        <td>{{ $data['total'] }}</td>
                        <td class="font-bold {{ $data['error_rate'] > 40 ? 'text-danger' : '' }}">
                            {{ $data['error_rate'] }}%
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @else
        <div class="page-break"></div>
        <div class="section-header">Resultados Finales</div>
        <div style="text-align: center; padding: 100px 50px; background: #f0fdf4; border-radius: 15px; border: 2px dashed #059669;">
            <h2 style="color: #059669; font-size: 28px;">¡Excelente Desempeño!</h2>
            <p style="color: #065f46; font-size: 13px; margin-top: 15px;">Felicidades, no se registraron respuestas incorrectas en este proceso evaluativo.</p>
        </div>
    @endif

</body>
</html>
