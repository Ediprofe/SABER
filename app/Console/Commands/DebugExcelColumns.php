<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use PhpOffice\PhpSpreadsheet\IOFactory;

class DebugExcelColumns extends Command
{
    protected $signature = 'debug:excel-columns {file}';

    protected $description = 'Debug: Muestra las columnas exactas de un archivo Excel';

    public function handle()
    {
        $filePath = $this->argument('file');

        if (! file_exists($filePath)) {
            $this->error("Archivo no encontrado: {$filePath}");

            return 1;
        }

        $this->info("Analizando archivo: {$filePath}");
        $this->info('');

        try {
            $reader = IOFactory::createReaderForFile($filePath);
            $reader->setReadDataOnly(true);
            $spreadsheet = $reader->load($filePath);
            $sheet = $spreadsheet->getActiveSheet();

            // Obtener encabezados de la primera fila
            $headers = [];
            $firstRow = $sheet->getRowIterator()->current();
            $cellIterator = $firstRow->getCellIterator();
            $cellIterator->setIterateOnlyExistingCells(false);

            $colIndex = 1;
            foreach ($cellIterator as $cell) {
                $value = $cell->getValue();
                $headers[$colIndex] = $value;
                $colIndex++;
            }

            $this->info('📋 COLUMNAS DETECTADAS:');
            $this->info('======================');
            foreach ($headers as $col => $header) {
                $this->info("Columna {$col}: '{$header}'");
            }

            $this->info('');
            $this->info('🔍 BUSCANDO COLUMNA PIAR:');
            $this->info('========================');

            $piarColumn = null;
            foreach ($headers as $col => $header) {
                $headerUpper = strtoupper(trim($header));
                if (strpos($headerUpper, 'PIAR') !== false) {
                    $piarColumn = $col;
                    $this->info("✅ ENCONTRADA: Columna {$col} = '{$header}'");
                }
            }

            if (! $piarColumn) {
                $this->warn("❌ No se encontró ninguna columna con 'PIAR' en el nombre");
            }

            // Mostrar primera fila de datos
            $this->info('');
            $this->info('📊 PRIMERA FILA DE DATOS:');
            $this->info('========================');

            $rowIterator = $sheet->getRowIterator();
            $rowIterator->next(); // Saltar encabezados
            $firstDataRow = $rowIterator->current();

            $colIndex = 1;
            foreach ($firstDataRow->getCellIterator() as $cell) {
                $header = $headers[$colIndex] ?? "Columna {$colIndex}";
                $value = $cell->getValue();
                $this->info("{$header}: '{$value}'");
                $colIndex++;
            }

            // Buscar específicamente SAMANTHA
            $this->info('');
            $this->info("🔍 BUSCANDO 'SAMANTHA':");
            $this->info('======================');

            foreach ($sheet->getRowIterator() as $row) {
                $cellIterator = $row->getCellIterator();
                $cellIterator->setIterateOnlyExistingCells(false);

                $values = [];
                $colIndex = 1;
                foreach ($cellIterator as $cell) {
                    $values[$colIndex] = $cell->getValue();
                    $colIndex++;
                }

                // Buscar en cualquier columna
                $rowText = implode(' ', $values);
                if (strpos(strtoupper($rowText), 'SAMANTHA') !== false) {
                    $this->info('Encontrada fila con SAMANTHA:');
                    foreach ($values as $col => $val) {
                        $header = $headers[$col] ?? "Col {$col}";
                        $this->info("  {$header}: '{$val}'");
                    }
                    break;
                }
            }

        } catch (\Exception $e) {
            $this->error('Error: '.$e->getMessage());

            return 1;
        }

        return 0;
    }
}
