<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reporte Individual</title>
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px;">

    <div style="background-color: #2563eb; color: white; padding: 20px; text-align: center; border-radius: 8px 8px 0 0;">
        <h1 style="margin: 0; font-size: 24px;">📊 Reporte Individual</h1>
        <p style="margin: 10px 0 0 0; opacity: 0.9;">{{ $examName }}</p>
    </div>

    <div style="background-color: #f8fafc; padding: 30px; border: 1px solid #e2e8f0;">
        <p>Hola <strong>{{ $studentName }}</strong>,</p>

        <p>Adjunto encontrarás tu reporte individual del examen <strong>{{ $examName }}</strong>.</p>

        <table style="width: 100%; margin: 15px 0;">
            <tr>
                <td style="padding: 8px 0; color: #64748b; font-size: 14px;">Estudiante:</td>
                <td style="padding: 8px 0; font-weight: 600;">{{ $fullName }}</td>
            </tr>
            <tr>
                <td style="padding: 8px 0; color: #64748b; font-size: 14px;">Grupo:</td>
                <td style="padding: 8px 0; font-weight: 600;">{{ $group }}</td>
            </tr>
            <tr>
                <td style="padding: 8px 0; color: #64748b; font-size: 14px;">Fecha del examen:</td>
                <td style="padding: 8px 0; font-weight: 600;">{{ $examDate }}</td>
            </tr>
        </table>

        @if($globalScore)
        <div style="text-align: center; margin: 25px 0;">
            <div style="background-color: #2563eb; color: white; padding: 15px 30px; border-radius: 8px; display: inline-block;">
                <div style="font-size: 14px; opacity: 0.9;">Puntaje Global</div>
                <div style="font-size: 36px; font-weight: bold;">{{ $globalScore }}</div>
                <div style="font-size: 14px; opacity: 0.9;">/ 500</div>
            </div>
        </div>
        @endif

        <p>El reporte PDF adjunto contiene información detallada sobre tu desempeño en cada área evaluada.</p>

        <p>
            Cordial saludo,<br>
            <strong>{{ config('mail.from.name') }}</strong>
        </p>
    </div>

    <div style="background-color: #1e293b; color: #94a3b8; padding: 20px; text-align: center; font-size: 12px; border-radius: 0 0 8px 8px;">
        <p style="margin: 0;">Este es un correo automático generado por {{ config('mail.from.name') }}.</p>
        <p style="margin: 5px 0 0 0;">Por favor no responda a este mensaje.</p>
    </div>

</body>
</html>
