<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Resultados Zipgrade - {{ $exam->name }}</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'DejaVu Sans', sans-serif;
            font-size: 10px;
            line-height: 1.4;
            color: #333;
        }

        .header {
            background: #1e40af;
            color: white;
            padding: 15px 20px;
            margin-bottom: 20px;
        }

        .header h1 {
            font-size: 18px;
            margin-bottom: 5px;
        }

        .header p {
            font-size: 11px;
            opacity: 0.9;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin: 0 20px 20px 20px;
            width: calc(100% - 40px);
        }

        th, td {
            padding: 8px 10px;
            text-align: left;
            border-bottom: 1px solid #ddd;
        }

        th {
            background: #f8fafc;
            font-weight: bold;
            font-size: 9px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #64748b;
        }

        td {
            font-size: 10px;
        }

        tr:nth-child(even) {
            background: #f8fafc;
        }

        tr:hover {
            background: #f1f5f9;
        }

        .document {
            font-family: 'DejaVu Sans', monospace;
            font-weight: bold;
            color: #1e40af;
        }

        .score {
            text-align: right;
            font-weight: 500;
        }

        .global {
            font-weight: bold;
            color: #1e40af;
        }

        .footer {
            position: fixed;
            bottom: 10px;
            left: 20px;
            right: 20px;
            font-size: 8px;
            color: #94a3b8;
            border-top: 1px solid #e2e8f0;
            padding-top: 5px;
            display: flex;
            justify-content: space-between;
        }

        .page-break {
            page-break-after: always;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>RESULTADOS ZIPGRADE</h1>
        <p>Examen: {{ $exam->name }} | Fecha: {{ $exam->date->format('d/m/Y') }} | Generado: {{ $generatedAt }}</p>
    </div>

    <table>
        <thead>
            <tr>
                <th>Documento</th>
                <th style="text-align: right;">Lectura</th>
                <th style="text-align: right;">Matemáticas</th>
                <th style="text-align: right;">Sociales</th>
                <th style="text-align: right;">Naturales</th>
                <th style="text-align: right;">Inglés</th>
                <th style="text-align: right;">Global</th>
            </tr>
        </thead>
        <tbody>
            @foreach($results as $result)
            <tr>
                <td class="document">{{ $result['document_id'] }}</td>
                <td class="score">{{ number_format($result['lectura'], 2, ',', '.') }}</td>
                <td class="score">{{ number_format($result['matematicas'], 2, ',', '.') }}</td>
                <td class="score">{{ number_format($result['sociales'], 2, ',', '.') }}</td>
                <td class="score">{{ number_format($result['naturales'], 2, ',', '.') }}</td>
                <td class="score">{{ number_format($result['ingles'], 2, ',', '.') }}</td>
                <td class="score global">{{ $result['global'] }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>

    <div class="footer">
        <span>Sistema SABER - Análisis ICFES</span>
    </div>

    <script type="text/php">
        if (isset($pdf)) {
            $x = 520;
            $y = 565;
            $text = "Página {PAGE_NUM} de {PAGE_COUNT}";
            $font = $fontMetrics->get_font("DejaVu Sans", "normal");
            $size = 8;
            $color = array(0.58, 0.64, 0.71);
            $word_space = 0.0;
            $char_space = 0.0;
            $angle = 0.0;
            $pdf->page_text($x, $y, $text, $font, $size, $color, $word_space, $char_space, $angle);
        }
    </script>
</body>
</html>
